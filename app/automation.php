<?php
declare(strict_types=1);

function automation_enqueue(string $provider, string $jobType, string $entityType, int $entityId, array $payload = []): int {
    $provider = strtolower(trim($provider));
    $jobType = strtolower(trim($jobType));
    $entityType = strtolower(trim($entityType));
    if ($provider === '' || $jobType === '' || $entityType === '' || $entityId <= 0) {
        throw new InvalidArgumentException('Invalid automation job.');
    }
    $jobKey = substr($provider.':'.$jobType.':'.$entityType.':'.$entityId, 0, 190);
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) throw new RuntimeException('Could not encode the automation job payload.');

    $sql = "INSERT INTO automation_jobs
            (job_key,provider,job_type,entity_type,entity_id,status,attempts,max_attempts,payload,run_at,requested_version)
            VALUES(?,?,?,?,?,'pending',0,5,?,NOW(),1)
            ON DUPLICATE KEY UPDATE
                payload=VALUES(payload),
                requested_version=requested_version+1,
                attempts=0,
                status=IF(status='running','running','pending'),
                run_at=IF(status='running',run_at,NOW()),
                completed_at=NULL,
                last_error=NULL,
                updated_at=NOW()";
    db()->prepare($sql)->execute([$jobKey, $provider, $jobType, $entityType, $entityId, $encoded]);
    $q = db()->prepare('SELECT id FROM automation_jobs WHERE job_key=? LIMIT 1');
    $q->execute([$jobKey]);
    $jobId = (int)$q->fetchColumn();
    automation_maybe_run_inline();
    return $jobId;
}

function automation_maybe_run_inline(): void {
    static $running = false;
    if ($running || PHP_SAPI === 'cli' || setting('automation_inline_enabled', '1') !== '1') return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') return;
    $running = true;
    try {
        automation_run_worker(1);
    } catch (Throwable $e) {
        error_log('FoxNetwork inline automation worker failed: '.$e->getMessage());
    } finally {
        $running = false;
    }
}

function automation_claim_job(): ?array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->query("SELECT * FROM automation_jobs WHERE status IN ('pending','retry_wait') AND run_at<=NOW() ORDER BY run_at,id LIMIT 1 FOR UPDATE");
        $job = $q->fetch();
        if (!$job) {
            $pdo->commit();
            return null;
        }
        $pdo->prepare("UPDATE automation_jobs SET status='running',attempts=attempts+1,processing_version=requested_version,started_at=NOW(),updated_at=NOW() WHERE id=?")
            ->execute([(int)$job['id']]);
        $pdo->commit();
        $q = $pdo->prepare('SELECT * FROM automation_jobs WHERE id=?');
        $q->execute([(int)$job['id']]);
        return $q->fetch() ?: null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function automation_process_job(array $job): void {
    $provider = (string)($job['provider'] ?? '');
    $type = (string)($job['job_type'] ?? '');
    $entityId = (int)($job['entity_id'] ?? 0);
    if ($provider !== 'zoho') throw new RuntimeException('Unsupported automation provider: '.$provider);
    if (!zoho_crm_enabled()) throw new RuntimeException('Zoho CRM is not connected.');

    $payload = json_decode((string)($job['payload'] ?? ''), true);
    if (!is_array($payload)) $payload = [];

    if ($type === 'sync_customer') {
        $q = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $q->execute([$entityId]);
        $customer = $q->fetch();
        if (!$customer) throw new ZohoCrmEntityNotFoundException('Customer no longer exists; Zoho CRM sync skipped.');
        zoho_crm_sync_customer($customer, true);
        return;
    }
    if ($type === 'sync_lead') {
        zoho_crm_sync_lead(
            (string)($payload['name'] ?? ''),
            (string)($payload['email'] ?? ''),
            (string)($payload['subject'] ?? ''),
            (string)($payload['message'] ?? ''),
            true
        );
        return;
    }
    if (!zoho_crm_full_sync_enabled()) throw new RuntimeException('Zoho CRM full sync is not connected.');

    if ($type === 'sync_order') zoho_crm_sync_order($entityId);
    elseif ($type === 'sync_invoice') zoho_crm_sync_invoice($entityId);
    elseif ($type === 'sync_service') zoho_crm_sync_service($entityId);
    elseif ($type === 'sync_ticket') zoho_crm_sync_ticket($entityId);
    else throw new RuntimeException('Unsupported Zoho automation job: '.$type);
}

function automation_complete_job(array $job): void {
    db()->prepare("UPDATE automation_jobs SET
        status=IF(requested_version>processing_version,'pending','completed'),
        run_at=IF(requested_version>processing_version,NOW(),run_at),
        completed_at=IF(requested_version>processing_version,NULL,NOW()),
        last_error=NULL,
        updated_at=NOW()
        WHERE id=?")->execute([(int)$job['id']]);
}

function automation_fail_job(array $job, Throwable $error): bool {
    $attempts = (int)($job['attempts'] ?? 1);
    $maxAttempts = max(1, (int)($job['max_attempts'] ?? 5));
    $terminal = $attempts >= $maxAttempts;
    $delay = min(3600, 30 * (2 ** max(0, $attempts - 1)));
    $runAt = date('Y-m-d H:i:s', time() + $delay);
    db()->prepare('UPDATE automation_jobs SET status=?,run_at=?,last_error=?,updated_at=NOW() WHERE id=?')
        ->execute([$terminal ? 'failed' : 'retry_wait', $runAt, mb_substr($error->getMessage(), 0, 8000), (int)$job['id']]);
    return $terminal;
}

function automation_skip_job(array $job, ZohoCrmEntityNotFoundException $reason): void {
    db()->prepare("UPDATE automation_jobs SET status='skipped',completed_at=NOW(),last_error=?,updated_at=NOW() WHERE id=?")
        ->execute([mb_substr($reason->getMessage(), 0, 8000), (int)$job['id']]);
}

function automation_retry_job(int $jobId): void {
    db()->prepare("UPDATE automation_jobs SET status='pending',attempts=0,run_at=NOW(),started_at=NULL,completed_at=NULL,last_error=NULL,requested_version=requested_version+1,updated_at=NOW() WHERE id=?")
        ->execute([$jobId]);
}

function automation_recover_stale_jobs(): int {
    $timeout = max(60, (int)setting('automation_worker_timeout_seconds', '300'));
    $cutoff = date('Y-m-d H:i:s', time() - $timeout);
    $q = db()->prepare("UPDATE automation_jobs SET status='retry_wait',run_at=NOW(),last_error='Recovered after an interrupted worker run.',updated_at=NOW() WHERE status='running' AND started_at IS NOT NULL AND started_at<?");
    $q->execute([$cutoff]);
    return $q->rowCount();
}

function automation_run_worker(int $limit = 20): array {
    $processed = 0;
    $failed = 0;
    $skipped = 0;
    automation_recover_stale_jobs();
    for ($i = 0; $i < max(1, min(100, $limit)); $i++) {
        $job = automation_claim_job();
        if (!$job) break;
        try {
            automation_process_job($job);
            automation_complete_job($job);
            $processed++;
        } catch (ZohoCrmEntityNotFoundException $e) {
            automation_skip_job($job, $e);
            $skipped++;
        } catch (Throwable $e) {
            if (automation_fail_job($job, $e)) $failed++;
        }
    }
    $queued = (int)db()->query("SELECT COUNT(*) FROM automation_jobs WHERE status IN ('pending','retry_wait','running')")->fetchColumn();
    return ['processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'queued' => $queued];
}
