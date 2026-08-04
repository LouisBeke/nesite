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

	$existing = $pdo->prepare("SELECT id,status FROM provisioning_queue WHERE job_key=? ORDER BY id DESC LIMIT 1");
	$existing->execute([$jobKey]);
	$row = $existing->fetch();
	if ($row && !$force && in_array((string)$row['status'], ['pending', 'running', 'retry_wait'], true)) {
		return (int)$row['id'];
	}

	if ($row && $force) {
		$pdo->prepare("UPDATE provisioning_queue SET status='cancelled',finished_at=NOW() WHERE id=? AND status IN ('pending','retry_wait')")->execute([(int)$row['id']]);
	}

	$maxAttempts = max(1, (int)setting('provisioning_max_attempts', '5'));
	$ins = $pdo->prepare("INSERT INTO provisioning_queue(job_key,job_type,status,priority,max_attempts,attempts,payload,run_at) VALUES(?, 'provision_service', 'pending', ?, ?, 0, ?, NOW())");
	$ins->execute([$jobKey, $priority, $maxAttempts, $payload]);
	$id = (int)$pdo->lastInsertId();
	provisioning_emit_event('queue.job_created', ['service_id' => $serviceId, 'priority' => $priority], $id);
	return $id;
}

function provisioning_dispatch_order(int $orderId, array $meta = []): int {
	$serviceId = ensure_service_for_order($orderId);
	db()->prepare("UPDATE orders SET status='provisioning' WHERE id=? AND status IN ('paid','awaiting_payment')")->execute([$orderId]);
	return provisioning_queue_service($serviceId, $meta, 70, false);
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
		$pdo->prepare("UPDATE provisioning_queue SET status='running',worker_id=?,attempts=attempts+1,started_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$workerId, (int)$job['id']]);
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

function provisioning_verify_online(int $serverId, int $tries = 8, int $sleepMs = 900): bool {
	for ($i = 0; $i < $tries; $i++) {
		try {
			$res = app_ptero('/servers/' . $serverId . '/resources');
			$state = strtolower((string)($res['attributes']['current_state'] ?? ''));
			if ($state === 'running') return true;
		} catch (Throwable $e) {
		}
		usleep(max(100, $sleepMs) * 1000);
	}
	return false;
}

function provisioning_process_service_job(array $job): void {
	$payload = provisioning_job_payload($job);
	$serviceId = (int)($payload['service_id'] ?? 0);
	if ($serviceId <= 0) throw new RuntimeException('Provisioning payload is missing service_id.');

	$serviceBefore = service_row($serviceId);
	$qb = db()->prepare('SELECT ptero_user_id,email,name FROM users WHERE id=?');
	$qb->execute([(int)$serviceBefore['user_id']]);
	$userBefore = $qb->fetch() ?: ['ptero_user_id' => null, 'email' => '', 'name' => ''];

	provisioning_emit_event('provisioning.started', ['service_id' => $serviceId], (int)$job['id']);
	provision_service($serviceId);

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
		throw new RuntimeException('Server did not become online during post-provision verification.');
	}

	if (!empty($serviceAfter['order_id'])) {
		db()->prepare("UPDATE orders SET status='active' WHERE id=?")->execute([(int)$serviceAfter['order_id']]);
	}

	$qu = db()->prepare('SELECT id,name,email,email_notifications FROM users WHERE id=?');
	$qu->execute([(int)$serviceAfter['user_id']]);
	$recipient = $qu->fetch();
	if ($recipient) {
		send_template('service_ready', $recipient, ['service_name' => $serviceAfter['name']]);
	}

	provisioning_emit_event('provisioning.completed', ['service_id' => $serviceId, 'server_id' => $newServerId], (int)$job['id']);
}

function provisioning_rollback_service_job(array $job, Throwable $error): void {
	$payload = provisioning_job_payload($job);
	$serviceId = (int)($payload['service_id'] ?? 0);
	if ($serviceId <= 0) return;

	try {
		$service = service_row($serviceId);
		$serverId = (int)($service['ptero_server_id'] ?? 0);
		if ($serverId > 0) {
			try {
				app_ptero('/servers/' . $serverId, 'DELETE');
				provisioning_emit_event('provisioning.rollback_server_deleted', ['service_id' => $serviceId, 'server_id' => $serverId], (int)$job['id'], 'warning');
			} catch (Throwable $rollbackErr) {
				provisioning_emit_event('provisioning.rollback_server_delete_failed', ['service_id' => $serviceId, 'server_id' => $serverId, 'error' => $rollbackErr->getMessage()], (int)$job['id'], 'error');
			}
		}
		db()->prepare("UPDATE services SET status='failed',ptero_server_id=NULL,ptero_identifier=NULL,last_error=? WHERE id=?")->execute([$error->getMessage(), $serviceId]);
		if (!empty($service['order_id'])) {
			db()->prepare("UPDATE orders SET status='paid' WHERE id=? AND status='provisioning'")->execute([(int)$service['order_id']]);
		}
	} catch (Throwable $e) {
	}
}

function provisioning_process_job(array $job): void {
	$type = (string)($job['job_type'] ?? '');
	if ($type === 'provision_service') {
		provisioning_process_service_job($job);
		return;
	}
	throw new RuntimeException('Unknown provisioning job type: ' . $type);
}

function provisioning_run_worker(int $limit = 5): array {
	$enabled = setting('provisioning_enabled', '1');
	if ($enabled !== '1') return ['processed' => 0, 'failed' => 0, 'queued' => 0, 'enabled' => false];

	$workerId = provisioning_worker_id();
	$processed = 0;
	$failed = 0;

	for ($i = 0; $i < max(1, $limit); $i++) {
		provisioning_register_worker($workerId, 'idle', false);
		$job = provisioning_claim_job($workerId);
		if (!$job) break;

		provisioning_register_worker($workerId, 'running', false);
		provisioning_emit_event('queue.job_claimed', ['worker_id' => $workerId], (int)$job['id']);
		try {
			provisioning_process_job($job);
			db()->prepare("UPDATE provisioning_queue SET status='completed',finished_at=NOW(),last_error=NULL WHERE id=?")->execute([(int)$job['id']]);
			$processed++;
		} catch (Throwable $e) {
			$failed++;
			provisioning_emit_event('queue.job_failed', ['error' => $e->getMessage()], (int)$job['id'], 'error', $e->getMessage());
			provisioning_rollback_service_job($job, $e);

			$attempts = (int)$job['attempts'];
			$maxAttempts = (int)$job['max_attempts'];
			if ($attempts < $maxAttempts) {
				$delay = provisioning_retry_delay($attempts);
				db()->prepare("UPDATE provisioning_queue SET status='retry_wait',last_error=?,run_at=DATE_ADD(NOW(),INTERVAL ? SECOND),worker_id=NULL WHERE id=?")
					->execute([$e->getMessage(), $delay, (int)$job['id']]);
				provisioning_emit_event('queue.job_retry_scheduled', ['delay_seconds' => $delay, 'attempt' => $attempts], (int)$job['id'], 'warning');
			} else {
				db()->prepare("UPDATE provisioning_queue SET status='failed',last_error=?,finished_at=NOW() WHERE id=?")->execute([$e->getMessage(), (int)$job['id']]);
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
			$alloc = app_ptero('/nodes/' . $nodeId . '/allocations?per_page=100');
			foreach (($alloc['data'] ?? []) as $row) {
				$aa = $row['attributes'] ?? [];
				$total++;
				if (empty($aa['assigned'])) $free++;
			}
		} catch (Throwable $e) {
			continue;
		}

		$meta = ['fqdn' => $a['fqdn'] ?? null, 'memory' => $a['memory'] ?? null, 'disk' => $a['disk'] ?? null];
		$sql = "INSERT INTO node_cache(node_id,node_name,location_id,total_allocations,free_allocations,metadata_json,last_sync_at)
				VALUES(?,?,?,?,?,?,NOW())
				ON DUPLICATE KEY UPDATE
				node_name=VALUES(node_name),
				location_id=VALUES(location_id),
				total_allocations=VALUES(total_allocations),
				free_allocations=VALUES(free_allocations),
				metadata_json=VALUES(metadata_json),
				last_sync_at=NOW()";
		db()->prepare($sql)->execute([$nodeId, (string)($a['name'] ?? ('Node #' . $nodeId)), (int)($a['location_id'] ?? 0) ?: null, $total, $free, json_encode($meta, JSON_UNESCAPED_SLASHES)]);
		$count++;
	}
	provisioning_emit_event('node_cache.refreshed', ['nodes' => $count]);
	return $count;
}

function provisioning_handle_pterodactyl_webhook(array $payload, array $server = []): void {
	$secret = (string)setting('pterodactyl_webhook_secret', '');
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
