<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Connection: keep-alive');

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

function sse_job_emit(string $event, array $payload): void {
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";
    @flush();
}

function load_job_live_payload_stream(int $jobId): array {
    $q = db()->prepare("SELECT q.*,JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS service_id,s.name AS service_name,s.order_id
        FROM provisioning_queue q
        LEFT JOIN services s ON s.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS UNSIGNED)
        WHERE q.id=? LIMIT 1");
    $q->execute([$jobId]);
    $job = $q->fetch();
    if (!$job) {
        throw new RuntimeException('Queue job not found.');
    }

    $stageMap = [
        'queue.job_created' => ['progress' => 8, 'step' => 'Queued'],
        'queue.job_claimed' => ['progress' => 18, 'step' => 'Worker claimed job'],
        'provisioning.started' => ['progress' => 35, 'step' => 'Creating server'],
        'provisioning.node_selected' => ['progress' => 28, 'step' => 'Node selected'],
        'provisioning.allocation_locked' => ['progress' => 32, 'step' => 'Allocation locked'],
        'provisioning.start_sent' => ['progress' => 72, 'step' => 'Starting server'],
        'provisioning.completed' => ['progress' => 100, 'step' => 'Completed'],
        'queue.job_retry_scheduled' => ['progress' => 25, 'step' => 'Retry scheduled'],
        'queue.job_failed' => ['progress' => 30, 'step' => 'Failed'],
    ];

    $eventQ = db()->prepare('SELECT event_name FROM provisioning_logs WHERE queue_id=? ORDER BY id DESC LIMIT 1');
    $eventQ->execute([$jobId]);
    $latestEvent = (string)($eventQ->fetchColumn() ?: '');

    $status = (string)$job['status'];
    $progress = 0;
    $step = 'Queued';
    if ($status === 'completed') {
        $progress = 100;
        $step = 'Completed';
    } elseif ($status === 'failed') {
        $progress = 100;
        $step = 'Failed';
    } elseif ($status === 'running') {
        $progress = 20;
        $step = 'Running';
    }
    if ($latestEvent !== '' && isset($stageMap[$latestEvent])) {
        $progress = (int)$stageMap[$latestEvent]['progress'];
        $step = (string)$stageMap[$latestEvent]['step'];
    }

    $logsQ = db()->prepare('SELECT id,queue_id,created_at,level,event_name,message,context_json FROM provisioning_logs WHERE queue_id=? ORDER BY id DESC LIMIT 120');
    $logsQ->execute([$jobId]);
    $logs = $logsQ->fetchAll();

    $locksQ = db()->prepare("SELECT l.*,n.node_name
        FROM provisioning_allocation_locks l
        LEFT JOIN node_cache n ON n.node_id=l.node_id
        WHERE l.queue_id=?
        ORDER BY l.id DESC");
    $locksQ->execute([$jobId]);
    $locks = $locksQ->fetchAll();

    $infra = ['node_id' => 0, 'allocation_id' => 0, 'score' => '', 'cpu' => '', 'ram' => '', 'disk' => '', 'servers' => ''];
    foreach ($logs as $l) {
        $ctx = json_decode((string)($l['context_json'] ?? ''), true);
        if (!is_array($ctx)) continue;
        if ($infra['node_id'] === 0 && isset($ctx['node_id'])) $infra['node_id'] = (int)$ctx['node_id'];
        if ($infra['allocation_id'] === 0 && isset($ctx['allocation_id'])) $infra['allocation_id'] = (int)$ctx['allocation_id'];
        if ($infra['score'] === '' && isset($ctx['score'])) $infra['score'] = (string)$ctx['score'];
        if ($infra['cpu'] === '' && isset($ctx['cpu'])) $infra['cpu'] = (string)$ctx['cpu'];
        if ($infra['ram'] === '' && isset($ctx['ram'])) $infra['ram'] = (string)$ctx['ram'];
        if ($infra['disk'] === '' && isset($ctx['disk'])) $infra['disk'] = (string)$ctx['disk'];
        if ($infra['servers'] === '' && isset($ctx['servers'])) $infra['servers'] = (string)$ctx['servers'];
    }

    $worker = null;
    if (!empty($job['worker_id'])) {
        $wq = db()->prepare("SELECT worker_id,hostname,status,processed_jobs,failed_jobs,last_heartbeat,
            TIMESTAMPDIFF(SECOND,last_heartbeat,NOW()) AS heartbeat_age
            FROM provisioning_workers WHERE worker_id=? LIMIT 1");
        $wq->execute([(string)$job['worker_id']]);
        $worker = $wq->fetch() ?: null;
    }

    $order = null;
    if (!empty($job['order_id'])) {
        $oq = db()->prepare('SELECT id,order_number,status,updated_at FROM orders WHERE id=? LIMIT 1');
        $oq->execute([(int)$job['order_id']]);
        $order = $oq->fetch() ?: null;
    }

    return [
        'server_time' => date('Y-m-d H:i:s'),
        'job' => [
            'id' => (int)$job['id'],
            'status' => $status,
            'progress' => max(0, min(100, $progress)),
            'step' => $step,
            'service_id' => (int)($job['service_id'] ?? 0),
            'service_name' => (string)($job['service_name'] ?? ''),
            'attempts' => (int)$job['attempts'],
            'max_attempts' => (int)$job['max_attempts'],
            'run_at' => (string)$job['run_at'],
            'started_at' => (string)($job['started_at'] ?? ''),
            'finished_at' => (string)($job['finished_at'] ?? ''),
            'worker_id' => (string)($job['worker_id'] ?? ''),
            'last_error' => (string)($job['last_error'] ?? ''),
        ],
        'worker' => $worker,
        'infra' => $infra,
        'locks' => $locks,
        'logs' => $logs,
        'order' => $order,
    ];
}

try {
    require_admin();
    $jobId = (int)($_GET['id'] ?? 0);
    if ($jobId <= 0) {
        throw new RuntimeException('Missing job id.');
    }

    ignore_user_abort(true);
    @set_time_limit(0);

    sse_job_emit('hello', ['ok' => true, 'data' => ['server_time' => date('Y-m-d H:i:s')]]);

    $ticks = 0;
    while (!connection_aborted() && $ticks < 180) {
        sse_job_emit('update', ['ok' => true, 'data' => load_job_live_payload_stream($jobId)]);
        $ticks++;
        usleep(2000000);
    }

    sse_job_emit('end', ['ok' => true, 'data' => ['reason' => 'stream_rotation']]);
} catch (Throwable $e) {
    sse_job_emit('error', ['ok' => false, 'error' => $e->getMessage()]);
}
