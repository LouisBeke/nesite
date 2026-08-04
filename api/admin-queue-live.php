<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out_live(bool $ok, array $data = [], string $error = ''): never {
    http_response_code(200);
    echo json_encode($ok ? ['ok' => true, 'data' => $data] : ['ok' => false, 'error' => $error], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    require_admin();

    $counts = [
        'total' => (int)db()->query('SELECT COUNT(*) FROM provisioning_queue')->fetchColumn(),
        'running' => (int)db()->query("SELECT COUNT(*) FROM provisioning_queue WHERE status='running'")->fetchColumn(),
        'waiting' => (int)db()->query("SELECT COUNT(*) FROM provisioning_queue WHERE status IN ('pending','retry_wait','paused')")->fetchColumn(),
        'failed' => (int)db()->query("SELECT COUNT(*) FROM provisioning_queue WHERE status='failed'")->fetchColumn(),
        'completed' => (int)db()->query("SELECT COUNT(*) FROM provisioning_queue WHERE status='completed'")->fetchColumn(),
    ];

    $jobs = db()->query("SELECT q.id,q.job_type,q.status,q.priority,q.attempts,q.max_attempts,q.run_at,q.started_at,q.finished_at,
        JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS service_id,
        s.name AS service_name
        FROM provisioning_queue q
        LEFT JOIN services s ON s.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS UNSIGNED)
        ORDER BY q.id DESC
        LIMIT 80")->fetchAll();

    $queueIds = [];
    foreach ($jobs as $j) {
        $queueIds[] = (int)$j['id'];
    }

    $latestEvents = [];
    if ($queueIds) {
        $placeholders = implode(',', array_fill(0, count($queueIds), '?'));
        $sql = "SELECT l.queue_id,l.event_name,l.created_at,l.message,l.level
            FROM provisioning_logs l
            JOIN (
                SELECT queue_id,MAX(id) AS max_id
                FROM provisioning_logs
                WHERE queue_id IN ($placeholders)
                GROUP BY queue_id
            ) t ON t.max_id=l.id";
        $q = db()->prepare($sql);
        $q->execute($queueIds);
        foreach ($q->fetchAll() as $r) {
            $latestEvents[(int)$r['queue_id']] = $r;
        }
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

    $queueStatus = [];
    foreach ($jobs as $j) {
        $qid = (int)$j['id'];
        $status = (string)$j['status'];
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

        if (isset($latestEvents[$qid])) {
            $ev = (string)$latestEvents[$qid]['event_name'];
            if (isset($stageMap[$ev])) {
                $progress = (int)$stageMap[$ev]['progress'];
                $step = (string)$stageMap[$ev]['step'];
            }
        }

        $queueStatus[] = [
            'id' => $qid,
            'service_id' => (int)($j['service_id'] ?? 0),
            'service_name' => (string)($j['service_name'] ?? ''),
            'status' => $status,
            'progress' => max(0, min(100, $progress)),
            'step' => $step,
            'attempts' => (int)$j['attempts'],
            'max_attempts' => (int)$j['max_attempts'],
            'run_at' => (string)$j['run_at'],
            'started_at' => (string)($j['started_at'] ?? ''),
            'finished_at' => (string)($j['finished_at'] ?? ''),
        ];
    }

    $workers = db()->query("SELECT worker_id,hostname,status,processed_jobs,failed_jobs,last_heartbeat,
        TIMESTAMPDIFF(SECOND,last_heartbeat,NOW()) AS heartbeat_age
        FROM provisioning_workers
        ORDER BY updated_at DESC
        LIMIT 30")->fetchAll();

    $nodes = db()->query("SELECT node_id,node_name,location_id,free_allocations,total_allocations,
        cpu_usage_percent,ram_usage_percent,disk_usage_percent,servers_count,is_maintenance,last_sync_at
        FROM node_cache
        ORDER BY is_maintenance ASC,free_allocations DESC,cpu_usage_percent ASC,ram_usage_percent ASC,disk_usage_percent ASC
        LIMIT 20")->fetchAll();

    $logs = db()->query("SELECT l.queue_id,l.created_at,l.level,l.event_name,l.message,
        JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS service_id,
        s.name AS service_name
        FROM provisioning_logs l
        LEFT JOIN provisioning_queue q ON q.id=l.queue_id
        LEFT JOIN services s ON s.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS UNSIGNED)
        ORDER BY l.id DESC
        LIMIT 50")->fetchAll();

    $orders = db()->query("SELECT q.id AS queue_id,q.finished_at,s.id AS service_id,s.name AS service_name,o.id AS order_id,o.order_number
        FROM provisioning_queue q
        LEFT JOIN services s ON s.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS UNSIGNED)
        LEFT JOIN orders o ON o.id=s.order_id
        WHERE q.job_type='provision_service' AND q.status='completed'
        ORDER BY q.finished_at DESC,q.id DESC
        LIMIT 25")->fetchAll();

    out_live(true, [
        'server_time' => date('Y-m-d H:i:s'),
        'counts' => $counts,
        'queue_status' => $queueStatus,
        'workers' => $workers,
        'node_health' => $nodes,
        'logs' => $logs,
        'order_completion' => $orders,
    ]);
} catch (Throwable $e) {
    out_live(false, [], $e->getMessage());
}
