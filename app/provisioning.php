<?php
declare(strict_types=1);

function provisioning_worker_id(): string {
    static $id = null;
    if ($id !== null) return $id;
    $id = substr((string)gethostname(), 0, 32) . '-' . substr(bin2hex(random_bytes(8)), 0, 12);
    return $id;
}

function provisioning_emit_event(string $event, array $context = [], ?int $queueId = null, string $level = 'info', string $message = ''): void {
    try {
        $q = db()->prepare('INSERT INTO provisioning_logs(queue_id,level,event_name,message,context_json) VALUES(?,?,?,?,?)');
        $q->execute([$queueId, $level, $event, $message !== '' ? $message : null, $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : null]);
    } catch (Throwable $e) {
    }
}

function provisioning_retry_delay(int $attempts): int {
    $base = max(5, (int)setting('provisioning_retry_base_seconds', '30'));
    $max = max($base, (int)setting('provisioning_retry_max_seconds', '900'));
    $exp = (int)pow(2, max(0, $attempts - 1));
    return min($max, $base * $exp);
}

function provisioning_queue_service(int $serviceId, array $meta = [], int $priority = 50, bool $force = false): int {
    if ($serviceId <= 0) throw new RuntimeException('Invalid service ID for provisioning queue.');
    $jobKey = 'service-provision-' . $serviceId;
    $pdo = db();
    $payload = json_encode(['service_id' => $serviceId, 'meta' => $meta], JSON_UNESCAPED_SLASHES);
    $maxAttempts = max(1, (int)setting('provisioning_max_attempts', '5'));

    $existing = $pdo->prepare('SELECT id,status FROM provisioning_queue WHERE job_key=? ORDER BY id DESC LIMIT 1');
    $existing->execute([$jobKey]);
    $row = $existing->fetch();

    // Normal path: keep the current waiting/running job for this service.
    if ($row && !$force && in_array((string)$row['status'], ['pending', 'running', 'retry_wait'], true)) {
        return (int)$row['id'];
    }

    // Re-use the existing unique job row to avoid uq_provisioning_job_key collisions.
    if ($row) {
        $id = (int)$row['id'];
        $pdo->prepare("UPDATE provisioning_queue
            SET status='pending',
                priority=?,
                max_attempts=?,
                attempts=0,
                payload=?,
                run_at=NOW(),
                started_at=NULL,
                finished_at=NULL,
                worker_id=NULL,
                last_error=NULL
            WHERE id=?")
            ->execute([$priority, $maxAttempts, $payload, $id]);
        provisioning_emit_event('queue.job_requeued', ['service_id' => $serviceId, 'priority' => $priority, 'forced' => $force ? 1 : 0], $id, $force ? 'warning' : 'info');
        return $id;
    }

    $ins = $pdo->prepare("INSERT INTO provisioning_queue(job_key,job_type,status,priority,max_attempts,attempts,payload,run_at) VALUES(?, 'provision_service', 'pending', ?, ?, 0, ?, NOW())");
    $ins->execute([$jobKey, $priority, $maxAttempts, $payload]);
    $id = (int)$pdo->lastInsertId();
    provisioning_emit_event('queue.job_created', ['service_id' => $serviceId, 'priority' => $priority], $id);
    return $id;
}

function provisioning_dispatch_order(int $orderId, array $meta = []): int {
    $serviceId = ensure_service_for_order($orderId);
    db()->prepare("UPDATE orders SET status='provisioning' WHERE id=? AND status IN ('paid','awaiting_payment')")->execute([$orderId]);
    zoho_crm_try_sync_service($serviceId);
    $queueId=provisioning_queue_service($serviceId, $meta, 70, false);
    provisioning_maybe_run_linode_now($serviceId,$queueId);
    return $queueId;
}

function provisioning_register_worker(string $workerId, string $status = 'idle', bool $failed = false): void {
    $host = substr((string)gethostname(), 0, 140);
    $sql = "INSERT INTO provisioning_workers(worker_id,hostname,status,processed_jobs,failed_jobs,last_heartbeat,started_at)
            VALUES(?,?,?,1,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE
            hostname=VALUES(hostname),
            status=VALUES(status),
            processed_jobs=processed_jobs+1,
            failed_jobs=failed_jobs+VALUES(failed_jobs),
            last_heartbeat=NOW()";
    db()->prepare($sql)->execute([$workerId, $host, $status, $failed ? 1 : 0]);
}

function provisioning_claim_job(string $workerId): ?array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->query("SELECT * FROM provisioning_queue WHERE status IN ('pending','retry_wait') AND run_at<=NOW() ORDER BY priority DESC,id ASC LIMIT 1 FOR UPDATE");
        $job = $q->fetch();
        if (!$job) {
            $pdo->commit();
            return null;
        }
        $pdo->prepare("UPDATE provisioning_queue SET status='running',worker_id=?,attempts=attempts+1,started_at=NOW(),updated_at=NOW() WHERE id=?")
            ->execute([$workerId, (int)$job['id']]);
        $pdo->commit();
        $s = $pdo->prepare('SELECT * FROM provisioning_queue WHERE id=?');
        $s->execute([(int)$job['id']]);
        return $s->fetch() ?: null;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function provisioning_job_payload(array $job): array {
    $payload = json_decode((string)($job['payload'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function provisioning_recover_stale_jobs(): int {
    $timeout = max(60, (int)setting('provisioning_worker_timeout_seconds', '240'));
    $q = db()->prepare("SELECT id FROM provisioning_queue WHERE status='running' AND started_at IS NOT NULL AND started_at<DATE_SUB(NOW(),INTERVAL ? SECOND)");
    $q->execute([$timeout]);
    $rows = $q->fetchAll();
    if (!$rows) return 0;

    $update = db()->prepare("UPDATE provisioning_queue
        SET status='retry_wait',worker_id=NULL,started_at=NULL,run_at=NOW(),last_error='Recovered after an interrupted provisioning worker.'
        WHERE id=? AND status='running'");
    $recovered = 0;
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) continue;
        $update->execute([$id]);
        if ($update->rowCount() > 0) {
            $recovered++;
            provisioning_emit_event('queue.stale_job_recovered', ['timeout_seconds' => $timeout], $id, 'warning');
        }
    }
    return $recovered;
}

function provisioning_verify_online(int $serverId, int $tries = 20, int $sleepMs = 1500): bool {
    $tries = max(5, (int)setting('provisioning_online_check_tries', (string)$tries));
    $sleepMs = max(300, (int)setting('provisioning_online_check_sleep_ms', (string)$sleepMs));
    for ($i = 0; $i < $tries; $i++) {
        try {
            $res = app_ptero('/servers/' . $serverId . '/resources');
            $state = strtolower((string)($res['attributes']['current_state'] ?? ''));
            if (in_array($state, ['running', 'starting'], true)) return true;
        } catch (Throwable $e) {
        }
        usleep(max(100, $sleepMs) * 1000);
    }
    return false;
}

function provisioning_lock_timeout_seconds(): int {
    return max(60, (int)setting('provisioning_allocation_lock_timeout_seconds', '900'));
}

function provisioning_cleanup_stale_allocation_locks(): void {
    $timeout = provisioning_lock_timeout_seconds();
    db()->prepare("UPDATE provisioning_allocation_locks
        SET status='released',released_at=NOW(),release_reason='stale_timeout'
        WHERE status='locked' AND locked_at < DATE_SUB(NOW(), INTERVAL ? SECOND)")
        ->execute([$timeout]);
}

function provisioning_try_lock_allocation(int $queueId, int $nodeId, int $allocationId): bool {
    try {
        $q = db()->prepare('INSERT INTO provisioning_allocation_locks(queue_id,node_id,allocation_id,status,locked_at) VALUES(?,?,?,?,NOW())');
        $q->execute([$queueId, $nodeId, $allocationId, 'locked']);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function provisioning_release_allocation_lock(int $queueId, ?int $allocationId = null, string $reason = 'released'): void {
    try {
        if ($allocationId !== null && $allocationId > 0) {
            db()->prepare("UPDATE provisioning_allocation_locks SET status='released',released_at=NOW(),release_reason=? WHERE queue_id=? AND allocation_id=? AND status='locked'")
                ->execute([$reason, $queueId, $allocationId]);
        } else {
            db()->prepare("UPDATE provisioning_allocation_locks SET status='released',released_at=NOW(),release_reason=? WHERE queue_id=? AND status='locked'")
                ->execute([$reason, $queueId]);
        }
    } catch (Throwable $e) {
    }
}

function provisioning_score_node(array $node): float {
    $cpu = max(0.0, min(100.0, (float)($node['cpu_usage_percent'] ?? 0)));
    $ram = max(0.0, min(100.0, (float)($node['ram_usage_percent'] ?? 0)));
    $disk = max(0.0, min(100.0, (float)($node['disk_usage_percent'] ?? 0)));
    $servers = max(0, (int)($node['servers_count'] ?? 0));

    $wCpu = max(0.0, (float)setting('provisioning_weight_cpu', '35'));
    $wRam = max(0.0, (float)setting('provisioning_weight_ram', '30'));
    $wDisk = max(0.0, (float)setting('provisioning_weight_disk', '20'));
    $wServers = max(0.0, (float)setting('provisioning_weight_servers', '15'));
    $sum = $wCpu + $wRam + $wDisk + $wServers;
    if ($sum <= 0.0) {
        $wCpu = 35.0;
        $wRam = 30.0;
        $wDisk = 20.0;
        $wServers = 15.0;
        $sum = 100.0;
    }

    $cpuFree = 100.0 - $cpu;
    $ramFree = 100.0 - $ram;
    $diskFree = 100.0 - $disk;
    $serverPenalty = min(100.0, $servers * 2.0);
    $serverFree = 100.0 - $serverPenalty;

    return ($cpuFree * ($wCpu / $sum))
        + ($ramFree * ($wRam / $sum))
        + ($diskFree * ($wDisk / $sum))
        + ($serverFree * ($wServers / $sum));
}

function provisioning_select_smart_node(array $service, int $queueId): array {
    $explicitNode = (int)($service['ptero_node_id'] ?? 0);
    if ($explicitNode > 0) {
        $q = db()->prepare('SELECT * FROM node_cache WHERE node_id=? LIMIT 1');
        $q->execute([$explicitNode]);
        $row = $q->fetch();
        if (!$row || (int)($row['free_allocations'] ?? 0) <= 0) {
            throw new RuntimeException('Selected Pterodactyl node has no free allocations.');
        }
        if ((int)($row['is_maintenance'] ?? 0) === 1) {
            throw new RuntimeException('Selected Pterodactyl node is in maintenance mode.');
        }
        return $row;
    }

    $cacheMaxAge = max(30, (int)setting('provisioning_node_cache_max_age_seconds', '300'));
    $staleCutoff = date('Y-m-d H:i:s', time() - $cacheMaxAge);
    $locationId = (int)($service['ptero_location_id'] ?? 0);

    $sql = "SELECT * FROM node_cache WHERE free_allocations > 0 AND is_maintenance=0 AND last_sync_at>=?";
    $params = [$staleCutoff];
    if ($locationId > 0) {
        $sql .= ' AND location_id=?';
        $params[] = $locationId;
    }
    $sql .= ' ORDER BY free_allocations DESC, updated_at DESC';

    $q = db()->prepare($sql);
    $q->execute($params);
    $candidates = $q->fetchAll();

    if (!$candidates && setting('provisioning_smart_node_enabled', '1') === '1') {
        provisioning_refresh_node_cache(50);
        $q->execute($params);
        $candidates = $q->fetchAll();
    }

    if (!$candidates) {
        if ($locationId > 0) {
            throw new RuntimeException('No healthy nodes with free allocations were found in the selected location.');
        }
        throw new RuntimeException('No healthy nodes with free allocations were found.');
    }

    $best = null;
    $bestScore = -INF;
    foreach ($candidates as $row) {
        $score = provisioning_score_node($row);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $row;
        }
    }

    if (!$best) {
        throw new RuntimeException('Could not pick a suitable provisioning node.');
    }

    provisioning_emit_event('provisioning.node_selected', [
        'service_id' => (int)$service['id'],
        'node_id' => (int)$best['node_id'],
        'score' => round($bestScore, 2),
        'cpu' => (float)$best['cpu_usage_percent'],
        'ram' => (float)$best['ram_usage_percent'],
        'disk' => (float)$best['disk_usage_percent'],
        'servers' => (int)$best['servers_count'],
    ], $queueId);

    return $best;
}

function provisioning_select_allocation_for_node(int $queueId, int $nodeId): int {
    provisioning_cleanup_stale_allocation_locks();

    $alloc = app_ptero('/nodes/' . $nodeId . '/allocations?per_page=200');
    $rows = $alloc['data'] ?? [];

    foreach ($rows as $row) {
        $a = $row['attributes'] ?? [];
        if (!empty($a['assigned'])) continue;
        $allocationId = (int)($a['id'] ?? 0);
        if ($allocationId <= 0) continue;
        if (provisioning_try_lock_allocation($queueId, $nodeId, $allocationId)) {
            provisioning_emit_event('provisioning.allocation_locked', ['node_id' => $nodeId, 'allocation_id' => $allocationId], $queueId);
            return $allocationId;
        }
    }

    throw new RuntimeException('No free allocation could be reserved on the selected node.');
}

function provisioning_select_runtime_for_service(array $service, int $queueId): array {
    $node = provisioning_select_smart_node($service, $queueId);
    $nodeId = (int)$node['node_id'];
    if ($nodeId <= 0) throw new RuntimeException('Smart node selection returned an invalid node.');

    $allocationId = provisioning_select_allocation_for_node($queueId, $nodeId);
    return ['node_id' => $nodeId, 'allocation_id' => $allocationId];
}

function provisioning_cleanup_orphan_ptero_user(array $runtime, int $serviceId, int $queueId): void {
    $created = !empty($runtime['created_ptero_user']);
    $pteroUserId = (int)($runtime['ptero_user_id'] ?? 0);
    if (!$created || $pteroUserId <= 0) return;

    try {
        $sq = db()->prepare('SELECT user_id FROM services WHERE id=? LIMIT 1');
        $sq->execute([$serviceId]);
        $ownerUserId = (int)$sq->fetchColumn();
        if ($ownerUserId <= 0) return;

        $serverCountQ = db()->prepare('SELECT COUNT(*) FROM services WHERE user_id=? AND ptero_server_id IS NOT NULL');
        $serverCountQ->execute([$ownerUserId]);
        $serverCount = (int)$serverCountQ->fetchColumn();
        if ($serverCount > 0) return;

        try {
            app_ptero('/users/' . $pteroUserId, 'DELETE');
            provisioning_emit_event('provisioning.rollback_user_deleted', ['ptero_user_id' => $pteroUserId], $queueId, 'warning');
            db()->prepare('UPDATE users SET ptero_user_id=NULL WHERE id=? AND ptero_user_id=?')->execute([$ownerUserId, $pteroUserId]);
        } catch (Throwable $e) {
            provisioning_emit_event('provisioning.rollback_user_delete_failed', ['ptero_user_id' => $pteroUserId, 'error' => $e->getMessage()], $queueId, 'warning');
        }
    } catch (Throwable $e) {
    }
}

function provisioning_remove_queue_item(int $queueId): void {
    try {
        db()->prepare('DELETE FROM provisioning_queue WHERE id=?')->execute([$queueId]);
    } catch (Throwable $e) {
    }
}

function provisioning_process_service_job(array $job, array &$runtime = []): void {
    $payload = provisioning_job_payload($job);
    $serviceId = (int)($payload['service_id'] ?? 0);
    if ($serviceId <= 0) throw new RuntimeException('Provisioning payload is missing service_id.');

    $serviceBefore = service_row($serviceId);
    $runtime['service_id'] = $serviceId;
    $runtime['queue_id'] = (int)$job['id'];

    if (linode_service_provider($serviceBefore) === 'linode') {
        provisioning_emit_event('provisioning.started', ['service_id'=>$serviceId,'provider'=>'linode'], (int)$job['id']);
        provision_service($serviceId, $runtime);
        $serviceAfter = service_row($serviceId);
        $instanceId = (int)($serviceAfter['linode_instance_id'] ?? 0);
        if ($instanceId <= 0) throw new RuntimeException('Provisioning did not produce a Linode instance ID.');
        provisioning_emit_event('provisioning.start_sent', ['service_id'=>$serviceId,'provider'=>'linode','instance_id'=>$instanceId], (int)$job['id']);
        if (!empty($serviceAfter['order_id'])) db()->prepare("UPDATE orders SET status='active' WHERE id=?")->execute([(int)$serviceAfter['order_id']]);
        $qu=db()->prepare('SELECT id,name,email,email_notifications FROM users WHERE id=?');$qu->execute([(int)$serviceAfter['user_id']]);$recipient=$qu->fetch();
        if($recipient)send_template('service_ready',$recipient,['service_name'=>$serviceAfter['name']]);
        zoho_crm_try_sync_service($serviceId);
        provisioning_emit_event('provisioning.completed', ['service_id'=>$serviceId,'provider'=>'linode','instance_id'=>$instanceId], (int)$job['id']);
        return;
    }

    if (empty($serviceBefore['ptero_server_id'])) {
        $runtime = array_merge($runtime, provisioning_select_runtime_for_service($serviceBefore, (int)$job['id']));
    }

    // Register and point an optional OXXA domain once the final allocation IP is known.
    oxxa_provision_domain($serviceId, $runtime);

    provisioning_emit_event('provisioning.started', ['service_id' => $serviceId, 'node_id' => (int)$runtime['node_id'], 'allocation_id' => (int)$runtime['allocation_id']], (int)$job['id']);
    provision_service($serviceId, $runtime);

    $serviceAfter = service_row($serviceId);
    $newServerId = (int)($serviceAfter['ptero_server_id'] ?? 0);
    if ($newServerId <= 0) throw new RuntimeException('Provisioning did not produce a Pterodactyl server ID.');

    try {
        app_ptero('/servers/' . $newServerId . '/startup', 'POST');
        provisioning_emit_event('provisioning.start_sent', ['service_id' => $serviceId, 'server_id' => $newServerId], (int)$job['id']);
    } catch (Throwable $e) {
        provisioning_emit_event('provisioning.start_failed', ['service_id' => $serviceId, 'error' => $e->getMessage()], (int)$job['id'], 'warning', 'Start request failed');
    }

    if (!provisioning_verify_online($newServerId)) {
        $strictOnline = setting('provisioning_strict_online_check', '0') === '1';
        if ($strictOnline) {
            throw new RuntimeException('Server did not become online during post-provision verification.');
        }
        provisioning_emit_event(
            'provisioning.online_check_timeout',
            ['service_id' => $serviceId, 'server_id' => $newServerId],
            (int)$job['id'],
            'warning',
            'Server startup verification timed out. Keeping created server and continuing.'
        );
        db()->prepare("UPDATE services SET status='active',last_error=NULL WHERE id=?")
            ->execute([$serviceId]);
    }

    provisioning_release_allocation_lock((int)$job['id'], (int)($runtime['allocation_id'] ?? 0), 'provisioned');

    if (!empty($serviceAfter['order_id'])) {
        db()->prepare("UPDATE orders SET status='active' WHERE id=?")->execute([(int)$serviceAfter['order_id']]);
    }

    $qu = db()->prepare('SELECT id,name,email,email_notifications FROM users WHERE id=?');
    $qu->execute([(int)$serviceAfter['user_id']]);
    $recipient = $qu->fetch();
    if ($recipient) {
        send_template('service_ready', $recipient, ['service_name' => $serviceAfter['name']]);
    }

    zoho_crm_try_sync_service($serviceId);

    provisioning_emit_event('provisioning.completed', ['service_id' => $serviceId, 'server_id' => $newServerId], (int)$job['id']);
}

function provisioning_rollback_service_job(array $job, Throwable $error, array $runtime = []): void {
    $payload = provisioning_job_payload($job);
    $serviceId = (int)($payload['service_id'] ?? 0);
    if ($serviceId <= 0) return;

    try {
        $service = service_row($serviceId);
        if (linode_service_provider($service) === 'linode') {
            $instanceId=(int)($service['linode_instance_id']??$runtime['linode_instance_id']??0);
            if($instanceId>0){try{linode_api('/linode/instances/'.$instanceId,'DELETE');provisioning_emit_event('provisioning.rollback_instance_deleted',['service_id'=>$serviceId,'instance_id'=>$instanceId],(int)$job['id'],'warning');}catch(Throwable $rollbackErr){provisioning_emit_event('provisioning.rollback_instance_delete_failed',['service_id'=>$serviceId,'instance_id'=>$instanceId,'error'=>$rollbackErr->getMessage()],(int)$job['id'],'error');}}
            db()->prepare("UPDATE services SET status='failed',linode_instance_id=NULL,linode_ipv4=NULL,linode_ipv6=NULL,linode_root_password=NULL,last_error=? WHERE id=?")->execute([$error->getMessage(),$serviceId]);
            if(!empty($service['order_id']))db()->prepare("UPDATE orders SET status='paid' WHERE id=? AND status='provisioning'")->execute([(int)$service['order_id']]);
            zoho_crm_try_sync_service($serviceId);
            return;
        }
        $serverId = (int)($service['ptero_server_id'] ?? 0);
        if ($serverId > 0) {
            try {
                app_ptero('/servers/' . $serverId, 'DELETE');
                provisioning_emit_event('provisioning.rollback_server_deleted', ['service_id' => $serviceId, 'server_id' => $serverId], (int)$job['id'], 'warning');
            } catch (Throwable $rollbackErr) {
                provisioning_emit_event('provisioning.rollback_server_delete_failed', ['service_id' => $serviceId, 'server_id' => $serverId, 'error' => $rollbackErr->getMessage()], (int)$job['id'], 'error');
            }
        }

        provisioning_release_allocation_lock((int)$job['id'], (int)($runtime['allocation_id'] ?? 0), 'rollback_failure');
        provisioning_cleanup_orphan_ptero_user($runtime, $serviceId, (int)$job['id']);

        db()->prepare("UPDATE services SET status='failed',ptero_server_id=NULL,ptero_identifier=NULL,last_error=? WHERE id=?")
            ->execute([$error->getMessage(), $serviceId]);
        if (!empty($service['order_id'])) {
            db()->prepare("UPDATE orders SET status='paid' WHERE id=? AND status='provisioning'")->execute([(int)$service['order_id']]);
        }
        zoho_crm_try_sync_service($serviceId);
    } catch (Throwable $e) {
    }
}

function provisioning_process_job(array $job, array &$runtime = []): void {
    $type = (string)($job['job_type'] ?? '');
    if ($type === 'provision_service') {
        provisioning_process_service_job($job, $runtime);
        return;
    }
    throw new RuntimeException('Unknown provisioning job type: ' . $type);
}

function provisioning_run_job_now(int $queueId): array {
    if(setting('provisioning_enabled','1')!=='1')return ['status'=>'disabled','error'=>'Provisioning worker is disabled.'];
    $pdo=db();$workerId=provisioning_worker_id();
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("SELECT * FROM provisioning_queue WHERE id=? FOR UPDATE");$q->execute([$queueId]);$job=$q->fetch();
        if(!$job){$pdo->commit();return ['status'=>'missing','error'=>'Provisioning job not found.'];}
        if(!in_array((string)$job['status'],['pending','retry_wait'],true)){$pdo->commit();return ['status'=>(string)$job['status'],'error'=>$job['last_error']??null];}
        $pdo->prepare("UPDATE provisioning_queue SET status='running',worker_id=?,attempts=attempts+1,started_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$workerId,$queueId]);
        $pdo->commit();
        $q=$pdo->prepare('SELECT * FROM provisioning_queue WHERE id=?');$q->execute([$queueId]);$job=$q->fetch();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if(!$job)return ['status'=>'missing','error'=>'Provisioning job disappeared after claim.'];
    provisioning_register_worker($workerId,'running',false);
    provisioning_emit_event('queue.job_claimed',['worker_id'=>$workerId,'immediate'=>1],$queueId);
    $runtime=[];
    try{
        provisioning_process_job($job,$runtime);
        $pdo->prepare("UPDATE provisioning_queue SET status='completed',finished_at=NOW(),last_error=NULL,worker_id=NULL WHERE id=?")->execute([$queueId]);
        provisioning_register_worker($workerId,'idle',false);
        return ['status'=>'completed','error'=>null];
    }catch(Throwable $e){
        provisioning_emit_event('queue.job_failed',['error'=>$e->getMessage(),'immediate'=>1],$queueId,'error',$e->getMessage());
        provisioning_rollback_service_job($job,$e,$runtime);
        $attempts=(int)$job['attempts'];$maxAttempts=(int)$job['max_attempts'];
        if($attempts<$maxAttempts){
            $delay=provisioning_retry_delay($attempts);
            $pdo->prepare("UPDATE provisioning_queue SET status='retry_wait',last_error=?,run_at=DATE_ADD(NOW(),INTERVAL ? SECOND),worker_id=NULL WHERE id=?")->execute([$e->getMessage(),$delay,$queueId]);
            provisioning_emit_event('queue.job_retry_scheduled',['delay_seconds'=>$delay,'attempt'=>$attempts],$queueId,'warning');
            $status='retry_wait';
        }else{
            $pdo->prepare("UPDATE provisioning_queue SET status='failed',last_error=?,finished_at=NOW(),worker_id=NULL WHERE id=?")->execute([$e->getMessage(),$queueId]);
            $status='failed';
        }
        provisioning_register_worker($workerId,'idle',true);
        return ['status'=>$status,'error'=>$e->getMessage()];
    }
}

function provisioning_maybe_run_linode_now(int $serviceId,int $queueId): array {
    $service=service_row($serviceId);
    if(linode_service_provider($service)!=='linode'||setting('linode_immediate_provisioning','1')!=='1')return ['status'=>'queued','error'=>null];
    return provisioning_run_job_now($queueId);
}

function provisioning_run_worker(int $limit = 5): array {
    $enabled = setting('provisioning_enabled', '1');
    if ($enabled !== '1') return ['processed' => 0, 'failed' => 0, 'queued' => 0, 'enabled' => false];

    $workerId = provisioning_worker_id();
    $processed = 0;
    $failed = 0;
    provisioning_recover_stale_jobs();

    for ($i = 0; $i < max(1, $limit); $i++) {
        provisioning_register_worker($workerId, 'idle', false);
        $job = provisioning_claim_job($workerId);
        if (!$job) break;

        provisioning_register_worker($workerId, 'running', false);
        provisioning_emit_event('queue.job_claimed', ['worker_id' => $workerId], (int)$job['id']);

        $runtime = [];
        try {
            provisioning_process_job($job, $runtime);
            db()->prepare("UPDATE provisioning_queue SET status='completed',finished_at=NOW(),last_error=NULL WHERE id=?")->execute([(int)$job['id']]);
            $processed++;
        } catch (Throwable $e) {
            $failed++;
            provisioning_emit_event('queue.job_failed', ['error' => $e->getMessage()], (int)$job['id'], 'error', $e->getMessage());
            provisioning_rollback_service_job($job, $e, $runtime);

            $attempts = (int)$job['attempts'];
            $maxAttempts = (int)$job['max_attempts'];
            if ($attempts < $maxAttempts) {
                $delay = provisioning_retry_delay($attempts);
                db()->prepare("UPDATE provisioning_queue SET status='retry_wait',last_error=?,run_at=DATE_ADD(NOW(),INTERVAL ? SECOND),worker_id=NULL WHERE id=?")
                    ->execute([$e->getMessage(), $delay, (int)$job['id']]);
                provisioning_emit_event('queue.job_retry_scheduled', ['delay_seconds' => $delay, 'attempt' => $attempts], (int)$job['id'], 'warning');
            } else {
                db()->prepare("UPDATE provisioning_queue SET status='failed',last_error=?,finished_at=NOW() WHERE id=?")
                    ->execute([$e->getMessage(), (int)$job['id']]);

                if (setting('provisioning_remove_failed_queue_item', '1') === '1') {
                    provisioning_emit_event('queue.job_removed_after_failure', ['queue_id' => (int)$job['id']], (int)$job['id'], 'warning');
                    provisioning_remove_queue_item((int)$job['id']);
                }
            }
            provisioning_register_worker($workerId, 'running', true);
        }
    }

    provisioning_register_worker($workerId, 'idle', false);
    $queued = (int)db()->query("SELECT COUNT(*) FROM provisioning_queue WHERE status IN ('pending','retry_wait','running')")->fetchColumn();
    return ['processed' => $processed, 'failed' => $failed, 'queued' => $queued, 'enabled' => true];
}

function provisioning_refresh_node_cache(int $maxNodes = 50): int {
    try {
        $nodes = app_ptero('/nodes?per_page=' . max(1, $maxNodes));
    } catch (Throwable $e) {
        provisioning_emit_event('node_cache.refresh_failed', ['error' => $e->getMessage()], null, 'warning');
        return 0;
    }

    $rows = $nodes['data'] ?? [];
    $count = 0;
    foreach ($rows as $node) {
        $a = $node['attributes'] ?? [];
        $nodeId = (int)($a['id'] ?? 0);
        if ($nodeId <= 0) continue;

        $free = 0;
        $total = 0;
        try {
            $alloc = app_ptero('/nodes/' . $nodeId . '/allocations?per_page=200');
            foreach (($alloc['data'] ?? []) as $row) {
                $aa = $row['attributes'] ?? [];
                $total++;
                if (empty($aa['assigned'])) $free++;
            }
        } catch (Throwable $e) {
            continue;
        }

        $cpuUsage = 0.0;
        $ramUsage = 0.0;
        $diskUsage = 0.0;
        $serversCount = 0;

        try {
            $detail = app_ptero('/nodes/' . $nodeId . '?include=servers');
            $da = $detail['attributes'] ?? [];
            $servers = $da['relationships']['servers']['data'] ?? $detail['relationships']['servers']['data'] ?? [];
            $serversCount = is_array($servers) ? count($servers) : 0;

            $usedCpu = 0.0;
            $usedRam = 0.0;
            $usedDisk = 0.0;
            foreach ($servers as $srv) {
                $sa = $srv['attributes'] ?? [];
                $limits = $sa['limits'] ?? [];
                $usedCpu += (float)($limits['cpu'] ?? 0);
                $usedRam += (float)($limits['memory'] ?? 0);
                $usedDisk += (float)($limits['disk'] ?? 0);
            }

            $totalCpu = (float)($da['cpu'] ?? $a['cpu'] ?? 0);
            $totalRam = (float)($da['memory'] ?? $a['memory'] ?? 0);
            $totalDisk = (float)($da['disk'] ?? $a['disk'] ?? 0);

            if ($totalCpu > 0) $cpuUsage = min(100.0, round(($usedCpu / $totalCpu) * 100.0, 2));
            if ($totalRam > 0) $ramUsage = min(100.0, round(($usedRam / $totalRam) * 100.0, 2));
            if ($totalDisk > 0) $diskUsage = min(100.0, round(($usedDisk / $totalDisk) * 100.0, 2));
        } catch (Throwable $e) {
        }

        $isMaintenance = !empty($a['maintenance_mode']) || !empty($a['is_maintenance']) ? 1 : 0;

        $meta = [
            'fqdn' => $a['fqdn'] ?? null,
            'memory' => $a['memory'] ?? null,
            'disk' => $a['disk'] ?? null,
            'cpu' => $a['cpu'] ?? null,
        ];
        $sql = "INSERT INTO node_cache(node_id,node_name,location_id,total_allocations,free_allocations,cpu_usage_percent,ram_usage_percent,disk_usage_percent,servers_count,is_maintenance,metadata_json,last_sync_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE
                node_name=VALUES(node_name),
                location_id=VALUES(location_id),
                total_allocations=VALUES(total_allocations),
                free_allocations=VALUES(free_allocations),
                cpu_usage_percent=VALUES(cpu_usage_percent),
                ram_usage_percent=VALUES(ram_usage_percent),
                disk_usage_percent=VALUES(disk_usage_percent),
                servers_count=VALUES(servers_count),
                is_maintenance=VALUES(is_maintenance),
                metadata_json=VALUES(metadata_json),
                last_sync_at=NOW()";
        db()->prepare($sql)->execute([
            $nodeId,
            (string)($a['name'] ?? ('Node #' . $nodeId)),
            (int)($a['location_id'] ?? 0) ?: null,
            $total,
            $free,
            $cpuUsage,
            $ramUsage,
            $diskUsage,
            $serversCount,
            $isMaintenance,
            json_encode($meta, JSON_UNESCAPED_SLASHES),
        ]);
        $count++;
    }
    provisioning_emit_event('node_cache.refreshed', ['nodes' => $count]);
    return $count;
}

function provisioning_handle_pterodactyl_webhook(array $payload, array $server = []): void {
    $secret = (string)setting('pterodactyl_webhook_secret', '');
    if ($secret === '__EMPTY__') $secret='';
    if (str_starts_with($secret, 'enc:')) $secret=(string)(dec(substr($secret,4))??'');
    if ($secret !== '') {
        $header = (string)($server['HTTP_X_PTERO_SIGNATURE'] ?? $server['HTTP_X_PTERODACTYL_SIGNATURE'] ?? '');
        if (!hash_equals($secret, $header)) {
            throw new RuntimeException('Invalid Pterodactyl webhook signature.');
        }
    }

    $event = (string)($payload['event'] ?? 'unknown');
    $identifier = (string)($payload['data']['server']['identifier'] ?? $payload['attributes']['identifier'] ?? '');
    provisioning_emit_event('webhook.' . $event, ['identifier' => $identifier, 'payload' => $payload]);

    if ($identifier !== '') {
        $q = db()->prepare('SELECT id,status FROM services WHERE ptero_identifier=? LIMIT 1');
        $q->execute([$identifier]);
        $service = $q->fetch();
        if ($service && str_contains($event, 'server.started')) {
            db()->prepare("UPDATE services SET status='active',last_error=NULL WHERE id=?")->execute([(int)$service['id']]);
        }
    }
}
