<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function outp(array $data): never {
    http_response_code(200);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

$u = require_user();

$q = db()->prepare("SELECT s.id,s.name,s.status,s.ptero_server_id,s.ptero_identifier,s.last_error,s.updated_at,o.order_number
                    FROM services s
                    LEFT JOIN orders o ON o.id=s.order_id
                    WHERE s.user_id=? AND s.status IN ('pending','provisioning')
                    ORDER BY s.updated_at DESC, s.id DESC");
$q->execute([(int)$u['id']]);
$services = $q->fetchAll();

$stageMap = [
    'queue.job_created' => ['progress' => 8, 'step' => 'Queued'],
    'queue.job_claimed' => ['progress' => 18, 'step' => 'Worker claimed job'],
    'provisioning.started' => ['progress' => 35, 'step' => 'Creating server'],
    'provisioning.node_selected' => ['progress' => 48, 'step' => 'Capacity reserved'],
    'provisioning.allocation_locked' => ['progress' => 58, 'step' => 'Network assigned'],
    'provisioning.start_sent' => ['progress' => 72, 'step' => 'Starting server'],
    'provisioning.completed' => ['progress' => 100, 'step' => 'Completed'],
    'queue.job_retry_scheduled' => ['progress' => 25, 'step' => 'Retry scheduled'],
    'queue.job_failed' => ['progress' => 30, 'step' => 'Failed'],
];

$positionMap=[];$position=0;
foreach(db()->query("SELECT id FROM provisioning_queue WHERE status IN ('pending','retry_wait') ORDER BY priority DESC,id ASC")->fetchAll() as $waitingJob)$positionMap[(int)$waitingJob['id']]=++$position;
$queueStats=['waiting'=>$position,'running'=>(int)db()->query("SELECT COUNT(*) FROM provisioning_queue WHERE status='running'")->fetchColumn(),'worker_online'=>(int)db()->query("SELECT COUNT(*) FROM provisioning_workers WHERE last_heartbeat>=DATE_SUB(NOW(),INTERVAL 120 SECOND)")->fetchColumn()];

$out = [];
foreach ($services as $s) {
    $queueId = null;
    $job = null;
    $jobKey = 'service-provision-'.(int)$s['id'];

    $jq = db()->prepare('SELECT * FROM provisioning_queue WHERE job_key=? ORDER BY id DESC LIMIT 1');
    $jq->execute([$jobKey]);
    $job = $jq->fetch() ?: null;

    $logs = [];
    if ($job) {
        $queueId = (int)$job['id'];
        $lq = db()->prepare('SELECT event_name,message,created_at,level FROM provisioning_logs WHERE queue_id=? ORDER BY id DESC LIMIT 30');
        $lq->execute([$queueId]);
        $logs = $lq->fetchAll();
    }

    $progress = 5;
    $step = 'Queued';
    $eta = null;

    if ($logs) {
        $latestEvent = (string)($logs[0]['event_name'] ?? '');
        if (isset($stageMap[$latestEvent])) {
            $progress = (int)$stageMap[$latestEvent]['progress'];
            $step = (string)$stageMap[$latestEvent]['step'];
        }
    } elseif ($job && (string)$job['status'] === 'running') {
        $progress = 20;
        $step = 'Running';
    }

    if ($job && in_array((string)$job['status'], ['pending','retry_wait'], true) && !empty($job['run_at'])) {
        $eta = (string)$job['run_at'];
    } elseif ($progress > 0 && $progress < 100) {
        $remain = max(10, (int)round((100 - $progress) * 1.2));
        $eta = date('Y-m-d H:i:s', time() + $remain);
    }

    $timeline = [];
    foreach (array_reverse($logs) as $l) {
        $timeline[] = [
            'time' => $l['created_at'],
            'event' => $l['event_name'],
            'message' => $l['message'] ?: '',
            'level' => $l['level'],
        ];
    }

    $out[] = [
        'service_id' => (int)$s['id'],
        'name' => (string)$s['name'],
        'order_number' => (string)($s['order_number'] ?? ''),
        'status' => (string)$s['status'],
        'progress' => max(0, min(100, $progress)),
        'step' => $step,
        'eta' => $eta,
        'last_error' => (string)($s['last_error'] ?? ''),
        'queue' => $job ? [
            'id' => (int)$job['id'],
            'status' => (string)$job['status'],
            'attempts' => (int)$job['attempts'],
            'max_attempts' => (int)$job['max_attempts'],
            'run_at' => (string)$job['run_at'],
            'updated_at' => (string)$job['updated_at'],
            'position' => $positionMap[(int)$job['id']] ?? null,
            'waiting_total' => $position,
        ] : null,
        'logs' => $logs,
        'timeline' => $timeline,
    ];
}

outp(['ok' => true, 'items' => $out, 'stats'=>$queueStats, 'server_time' => (string)db()->query('SELECT NOW()')->fetchColumn()]);
