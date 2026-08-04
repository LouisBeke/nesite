<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';
$u = require_admin();
$msg = (($_GET['deleted'] ?? '') === '1') ? 'Queue job deleted.' : '';
$err = '';

$allowedPerPage = [25, 50, 100];
$allowedRefresh = [5, 10, 20, 30, 60];
$allowedStatus = ['pending', 'retry_wait', 'running', 'paused', 'completed', 'failed', 'cancelled'];

$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterServiceId = (int)($_GET['service_id'] ?? 0);
$filterDateFrom = trim((string)($_GET['date_from'] ?? ''));
$filterDateTo = trim((string)($_GET['date_to'] ?? ''));
$perPage = (int)($_GET['per_page'] ?? 25);
$page = max(1, (int)($_GET['page'] ?? 1));
$refreshOn = (string)($_GET['refresh'] ?? '0') === '1';
$refreshSec = (int)($_GET['refresh_sec'] ?? 10);
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 25;
if (!in_array($refreshSec, $allowedRefresh, true)) $refreshSec = 10;

if ($filterStatus !== '' && !in_array($filterStatus, $allowedStatus, true)) {
    $filterStatus = '';
}

$filterWhere = '';
$filterParams = [];
if ($filterStatus !== '') {
    $filterWhere .= ' AND q.status=?';
    $filterParams[] = $filterStatus;
}
if ($filterServiceId > 0) {
    $filterWhere .= " AND CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS UNSIGNED)=?";
    $filterParams[] = $filterServiceId;
}
if ($filterDateFrom !== '') {
    $fromTs = strtotime($filterDateFrom . ' 00:00:00');
    if ($fromTs !== false) {
        $filterWhere .= ' AND q.created_at>=?';
        $filterParams[] = date('Y-m-d H:i:s', $fromTs);
    }
}
if ($filterDateTo !== '') {
    $toTs = strtotime($filterDateTo . ' 23:59:59');
    if ($toTs !== false) {
        $filterWhere .= ' AND q.created_at<=?';
        $filterParams[] = date('Y-m-d H:i:s', $toTs);
    }
}

function queue_query_string(array $overrides = []): string {
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = (string)$v;
        }
    }
    return http_build_query($params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'refresh_node_cache') {
            $maxNodes = max(5, min(200, (int)($_POST['max_nodes'] ?? 50)));
            $synced = provisioning_refresh_node_cache($maxNodes);
            $msg = 'Node cache refreshed: ' . $synced . ' node(s) synced.';
        } else {
            $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            throw new RuntimeException('Invalid job ID.');
        }

        $q = db()->prepare('SELECT * FROM provisioning_queue WHERE id=? LIMIT 1');
        $q->execute([$jobId]);
        $job = $q->fetch();
        if (!$job) {
            throw new RuntimeException('Queue job not found.');
        }

        $status = (string)$job['status'];
        if ($action === 'pause') {
            if (!in_array($status, ['pending', 'retry_wait'], true)) {
                throw new RuntimeException('Only waiting jobs can be paused.');
            }
            db()->prepare("UPDATE provisioning_queue SET status='paused',worker_id=NULL WHERE id=?")->execute([$jobId]);
            provisioning_emit_event('queue.job_paused', ['admin_id' => (int)$u['id']], $jobId, 'warning');
            $msg = 'Job #'.$jobId.' paused.';
        } elseif ($action === 'resume') {
            if ($status !== 'paused') {
                throw new RuntimeException('Only paused jobs can be resumed.');
            }
            db()->prepare("UPDATE provisioning_queue SET status='pending',run_at=NOW(),worker_id=NULL WHERE id=?")->execute([$jobId]);
            provisioning_emit_event('queue.job_resumed', ['admin_id' => (int)$u['id']], $jobId);
            $msg = 'Job #'.$jobId.' resumed.';
        } elseif ($action === 'cancel') {
            if (!in_array($status, ['pending', 'retry_wait', 'paused'], true)) {
                throw new RuntimeException('Only waiting/paused jobs can be cancelled safely.');
            }
            db()->prepare("UPDATE provisioning_queue SET status='cancelled',finished_at=NOW(),worker_id=NULL WHERE id=?")->execute([$jobId]);
            provisioning_emit_event('queue.job_cancelled', ['admin_id' => (int)$u['id']], $jobId, 'warning');
            $msg = 'Job #'.$jobId.' cancelled.';
        } elseif ($action === 'retry') {
            if ($status === 'running') {
                throw new RuntimeException('Running jobs cannot be retried.');
            }
            db()->prepare("UPDATE provisioning_queue SET status='pending',attempts=0,last_error=NULL,run_at=NOW(),started_at=NULL,finished_at=NULL,worker_id=NULL WHERE id=?")->execute([$jobId]);
            provisioning_emit_event('queue.job_retry_forced', ['admin_id' => (int)$u['id']], $jobId, 'warning');
            $msg = 'Job #'.$jobId.' moved back to queue for retry.';
        } elseif ($action === 'delete') {
            if ($status === 'running') {
                throw new RuntimeException('Running jobs cannot be deleted.');
            }
            db()->beginTransaction();
            db()->prepare('DELETE FROM provisioning_logs WHERE queue_id=?')->execute([$jobId]);
            db()->prepare('DELETE FROM provisioning_queue WHERE id=?')->execute([$jobId]);
            db()->commit();
            $msg = 'Job #'.$jobId.' and logs deleted.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $err = $e->getMessage();
    }
}

$counts = [
    'total' => 0,
    'running' => 0,
    'waiting' => 0,
    'failed' => 0,
    'paused' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
$workers = [];
$nodeCandidates = [];
$runningJobs = [];
$waitingJobs = [];
$failedJobs = [];
$servicesForFilter = [];
$filteredJobs = [];
$filteredTotal = 0;
$totalPages = 1;
$offset = 0;
$stats = [
    'avg_time_seconds' => 0,
    'success_rate' => 0.0,
    'failed_jobs' => 0,
    'node_usage_percent' => 0.0,
    'queue_load_percent' => 0.0,
    'queue_load_text' => 'idle',
];
$totalAlloc = 0;
$freeAlloc = 0;
$usedAlloc = 0;

try {
    $counts['total'] = (int)db()->query('SELECT COUNT(*) FROM provisioning_queue')->fetchColumn();
    $counts['running'] = (int)db()->query("SELECT COUNT(*) FROM provisioning_queue WHERE status='running'")->fetchColumn();
    $counts['waiting'] = (int)db()->query("SELECT COUNT(*) FROM provisioning_queue WHERE status IN ('pending','retry_wait')")->fetchColumn();
    $counts['failed'] = (int)db()->query("SELECT COUNT(*) FROM provisioning_queue WHERE status='failed'")->fetchColumn();
    $counts['paused'] = (int)db()->query("SELECT COUNT(*) FROM provisioning_queue WHERE status='paused'")->fetchColumn();
    $counts['completed'] = (int)db()->query("SELECT COUNT(*) FROM provisioning_queue WHERE status='completed'")->fetchColumn();
    $counts['cancelled'] = (int)db()->query("SELECT COUNT(*) FROM provisioning_queue WHERE status='cancelled'")->fetchColumn();

    $workers = db()->query("SELECT *,TIMESTAMPDIFF(SECOND,last_heartbeat,NOW()) AS heartbeat_age FROM provisioning_workers ORDER BY updated_at DESC LIMIT 25")->fetchAll();

    $nodeCandidates = db()->query("SELECT node_id,node_name,location_id,free_allocations,total_allocations,cpu_usage_percent,ram_usage_percent,disk_usage_percent,servers_count,is_maintenance,last_sync_at
        FROM node_cache
        ORDER BY is_maintenance ASC, free_allocations DESC, cpu_usage_percent ASC, ram_usage_percent ASC, disk_usage_percent ASC
        LIMIT 12")->fetchAll();

    $runningJobs = db()->query("SELECT q.*,
        JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS payload_service_id,
        s.name AS service_name
        FROM provisioning_queue q
        LEFT JOIN services s ON s.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS UNSIGNED)
        WHERE q.status='running'
        ORDER BY q.started_at ASC,q.id ASC
        LIMIT 30")->fetchAll();

    $waitingJobs = db()->query("SELECT q.*,
        JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS payload_service_id,
        s.name AS service_name
        FROM provisioning_queue q
        LEFT JOIN services s ON s.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS UNSIGNED)
        WHERE q.status IN ('pending','retry_wait','paused')
        ORDER BY q.priority DESC,q.run_at ASC,q.id ASC
        LIMIT 50")->fetchAll();

    $failedJobs = db()->query("SELECT q.*,
        JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS payload_service_id,
        s.name AS service_name
        FROM provisioning_queue q
        LEFT JOIN services s ON s.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS UNSIGNED)
        WHERE q.status='failed'
        ORDER BY q.finished_at DESC,q.id DESC
        LIMIT 50")->fetchAll();

    $servicesForFilter = db()->query("SELECT s.id,s.name FROM services s
        WHERE EXISTS (
            SELECT 1
            FROM provisioning_queue q
            WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS UNSIGNED)=s.id
        )
        ORDER BY s.name ASC
        LIMIT 300")->fetchAll();

    $countStmt = db()->prepare("SELECT COUNT(*)
        FROM provisioning_queue q
        LEFT JOIN services s ON s.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS UNSIGNED)
        WHERE 1=1" . $filterWhere);
    $countStmt->execute($filterParams);
    $filteredTotal = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($filteredTotal / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $dataSql = "SELECT q.*,
        JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS payload_service_id,
        s.name AS service_name
        FROM provisioning_queue q
        LEFT JOIN services s ON s.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS UNSIGNED)
        WHERE 1=1" . $filterWhere . "
        ORDER BY q.id DESC
        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
    $dataStmt = db()->prepare($dataSql);
    $dataStmt->execute($filterParams);
    $filteredJobs = $dataStmt->fetchAll();

    $stats['avg_time_seconds'] = (int)db()->query("SELECT COALESCE(ROUND(AVG(TIMESTAMPDIFF(SECOND,started_at,finished_at))),0)
    FROM provisioning_queue
    WHERE status='completed' AND started_at IS NOT NULL AND finished_at IS NOT NULL")->fetchColumn();

    $terminalJobs = (int)db()->query("SELECT COUNT(*) FROM provisioning_queue WHERE status IN ('completed','failed','cancelled')")->fetchColumn();
    $okJobs = (int)db()->query("SELECT COUNT(*) FROM provisioning_queue WHERE status='completed'")->fetchColumn();
    $stats['success_rate'] = $terminalJobs > 0 ? round(($okJobs / $terminalJobs) * 100, 2) : 0.0;

    $nodeTotals = db()->query('SELECT COALESCE(SUM(total_allocations),0) AS total_alloc,COALESCE(SUM(free_allocations),0) AS free_alloc FROM node_cache')->fetch();
    $totalAlloc = (int)($nodeTotals['total_alloc'] ?? 0);
    $freeAlloc = (int)($nodeTotals['free_alloc'] ?? 0);
    $usedAlloc = max(0, $totalAlloc - $freeAlloc);
    $stats['node_usage_percent'] = $totalAlloc > 0 ? round(($usedAlloc / $totalAlloc) * 100, 2) : 0.0;

    $workerActive = 0;
    foreach ($workers as $w) {
        if ((int)$w['heartbeat_age'] <= 120) {
            $workerActive++;
        }
    }
    $loadDen = max(1, $workerActive);
    $stats['queue_load_percent'] = round((($counts['waiting'] + $counts['running']) / $loadDen) * 100, 2);
    if ($stats['queue_load_percent'] < 80) {
        $stats['queue_load_text'] = 'low';
    } elseif ($stats['queue_load_percent'] < 180) {
        $stats['queue_load_text'] = 'normal';
    } else {
        $stats['queue_load_text'] = 'high';
    }
    $stats['failed_jobs'] = $counts['failed'];
} catch (Throwable $e) {
    $err = $err !== '' ? $err : 'Queue data could not be loaded: ' . $e->getMessage();
}

function queue_job_link(array $job): string {
    return '/admin/queue-job.php?id='.(int)$job['id'];
}

function queue_service_label(array $job): string {
    $sid = (int)($job['payload_service_id'] ?? 0);
    if (!empty($job['service_name'])) {
        return $job['service_name'].' (#'.$sid.')';
    }
    return $sid > 0 ? 'Service #'.$sid : 'Unknown';
}

admin_head($u, 'Queue Manager', 'queue');
?>
<?php if ($msg): ?><div class="notice"><?=e($msg)?></div><?php endif; ?>
<?php if ($err): ?><div class="error"><?=e($err)?></div><?php endif; ?>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>LIVE OPERATIONS</b><span class="muted" id="live-updated-at">Waiting for updates…</span></div>
    <div style="padding:20px;display:grid;grid-template-columns:repeat(5,minmax(140px,1fr));gap:12px">
        <div style="background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:12px"><span class="muted small">Queue total</span><div style="font-size:22px;font-weight:800" id="live-count-total">0</div></div>
        <div style="background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:12px"><span class="muted small">Running</span><div style="font-size:22px;font-weight:800" id="live-count-running">0</div></div>
        <div style="background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:12px"><span class="muted small">Waiting</span><div style="font-size:22px;font-weight:800" id="live-count-waiting">0</div></div>
        <div style="background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:12px"><span class="muted small">Failed</span><div style="font-size:22px;font-weight:800" id="live-count-failed">0</div></div>
        <div style="background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:12px"><span class="muted small">Completed</span><div style="font-size:22px;font-weight:800" id="live-count-completed">0</div></div>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Queue</th><th>Service</th><th>Status</th><th>Progress</th><th>Step</th><th>Attempts</th><th>Run/Start/Finish</th></tr></thead>
            <tbody id="live-queue-body"><tr><td colspan="7" class="muted">Loading queue status…</td></tr></tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>LIVE LOGS</b><span class="muted">Provisioning stream</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Time</th><th>Service</th><th>Level</th><th>Event</th><th>Message</th></tr></thead>
            <tbody id="live-logs-body"><tr><td colspan="5" class="muted">Loading logs…</td></tr></tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>LIVE WORKER STATE</b><span class="muted">Heartbeat and throughput</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Worker ID</th><th>Host</th><th>Status</th><th>Processed</th><th>Failed</th><th>Heartbeat</th></tr></thead>
            <tbody id="live-workers-body"><tr><td colspan="6" class="muted">Loading workers…</td></tr></tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>LIVE NODE HEALTH</b><span class="muted">Smart scheduling inputs</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Node</th><th>Status</th><th>CPU</th><th>RAM</th><th>Disk</th><th>Servers</th><th>Free alloc</th><th>Synced</th></tr></thead>
            <tbody id="live-nodes-body"><tr><td colspan="8" class="muted">Loading node health…</td></tr></tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>LIVE ORDER COMPLETION</b><span class="muted">Provisioning completions</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>When</th><th>Queue</th><th>Order</th><th>Service</th><th>Status</th></tr></thead>
            <tbody id="live-orders-body"><tr><td colspan="5" class="muted">Loading completion stream…</td></tr></tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>FILTERS & VIEW</b><span class="muted">Quick filters, pagination and refresh</span></div>
    <form method="get" style="padding:20px;display:grid;grid-template-columns:repeat(6,minmax(130px,1fr));gap:10px;align-items:end">
        <label>Status
            <select name="status" style="width:100%;background:#0f1318;border:1px solid #2b313a;color:#fff;border-radius:8px;padding:10px">
                <option value="">All</option>
                <?php foreach ($allowedStatus as $st): ?>
                <option value="<?=e($st)?>" <?=$filterStatus === $st ? 'selected' : ''?>><?=e(strtoupper(str_replace('_', ' ', $st)))?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Service
            <select name="service_id" style="width:100%;background:#0f1318;border:1px solid #2b313a;color:#fff;border-radius:8px;padding:10px">
                <option value="0">All services</option>
                <?php foreach ($servicesForFilter as $svc): ?>
                <option value="<?=e($svc['id'])?>" <?=$filterServiceId === (int)$svc['id'] ? 'selected' : ''?>><?=e($svc['name'])?> (#<?=e($svc['id'])?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>From
            <input type="date" name="date_from" value="<?=e($filterDateFrom)?>" style="width:100%;background:#0f1318;border:1px solid #2b313a;color:#fff;border-radius:8px;padding:10px">
        </label>
        <label>To
            <input type="date" name="date_to" value="<?=e($filterDateTo)?>" style="width:100%;background:#0f1318;border:1px solid #2b313a;color:#fff;border-radius:8px;padding:10px">
        </label>
        <label>Per page
            <select name="per_page" style="width:100%;background:#0f1318;border:1px solid #2b313a;color:#fff;border-radius:8px;padding:10px">
                <?php foreach ($allowedPerPage as $pp): ?>
                <option value="<?=e($pp)?>" <?=$perPage === $pp ? 'selected' : ''?>><?=e($pp)?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div style="display:flex;gap:8px">
            <input type="hidden" name="refresh" value="<?=$refreshOn ? '1' : '0'?>">
            <input type="hidden" name="refresh_sec" value="<?=e($refreshSec)?>">
            <button class="btn primary" type="submit">Apply</button>
            <a class="btn" href="/admin/queue.php">Reset</a>
        </div>
    </form>
    <div style="padding:0 20px 20px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <span class="muted">Auto refresh:</span>
        <?php if ($refreshOn): ?>
            <a class="btn warning" href="?<?=e(queue_query_string(['refresh' => '0']))?>">On (disable)</a>
        <?php else: ?>
            <a class="btn" href="?<?=e(queue_query_string(['refresh' => '1']))?>">Off (enable)</a>
        <?php endif; ?>
        <span class="muted">Interval:</span>
        <?php foreach ($allowedRefresh as $sec): ?>
            <a class="btn" href="?<?=e(queue_query_string(['refresh_sec' => (string)$sec]))?>" style="<?=$refreshSec === $sec ? 'border-color:#ff7417;color:#ff7417' : ''?>"><?=e($sec)?>s</a>
        <?php endforeach; ?>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>FILTERED JOBS</b><span class="muted"><?=$filteredTotal?> result(s), page <?=$page?> / <?=$totalPages?></span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Job</th><th>Service</th><th>Status</th><th>Priority</th><th>Attempts</th><th>Created</th><th>Run at</th><th>Actions</th><th>Open</th></tr></thead>
            <tbody>
            <?php if (!$filteredJobs): ?><tr><td colspan="9" class="muted">No jobs for current filters.</td></tr><?php endif; ?>
            <?php foreach ($filteredJobs as $j): ?>
            <?php
                $rowStatus = (string)$j['status'];
                $rowCanPause = in_array($rowStatus, ['pending', 'retry_wait'], true);
                $rowCanResume = ($rowStatus === 'paused');
                $rowCanCancel = in_array($rowStatus, ['pending', 'retry_wait', 'paused'], true);
                $rowCanRetry = ($rowStatus !== 'running');
                $rowCanDelete = ($rowStatus !== 'running');
            ?>
            <tr>
                <td><b>#<?=e($j['id'])?></b><small><?=e($j['job_type'])?></small></td>
                <td><?=e(queue_service_label($j))?></td>
                <td><?=admin_badge((string)$j['status'])?></td>
                <td><?=e($j['priority'])?></td>
                <td><?=e($j['attempts'])?> / <?=e($j['max_attempts'])?></td>
                <td><?=e($j['created_at'])?></td>
                <td><?=e($j['run_at'])?></td>
                <td>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;min-width:320px">
                        <form method="post" style="margin:0">
                            <input type="hidden" name="csrf" value="<?=e(csrf())?>">
                            <input type="hidden" name="job_id" value="<?=e($j['id'])?>">
                            <button class="btn" name="action" value="pause" <?=$rowCanPause ? '' : 'disabled'?>>Pause</button>
                        </form>
                        <form method="post" style="margin:0">
                            <input type="hidden" name="csrf" value="<?=e(csrf())?>">
                            <input type="hidden" name="job_id" value="<?=e($j['id'])?>">
                            <button class="btn" name="action" value="resume" <?=$rowCanResume ? '' : 'disabled'?>>Resume</button>
                        </form>
                        <form method="post" style="margin:0">
                            <input type="hidden" name="csrf" value="<?=e(csrf())?>">
                            <input type="hidden" name="job_id" value="<?=e($j['id'])?>">
                            <button class="btn warning" name="action" value="cancel" <?=$rowCanCancel ? '' : 'disabled'?>>Cancel</button>
                        </form>
                        <form method="post" style="margin:0">
                            <input type="hidden" name="csrf" value="<?=e(csrf())?>">
                            <input type="hidden" name="job_id" value="<?=e($j['id'])?>">
                            <button class="btn primary" name="action" value="retry" <?=$rowCanRetry ? '' : 'disabled'?>>Retry</button>
                        </form>
                        <form method="post" style="margin:0" onsubmit="return confirm('Delete this queue job and all logs?');">
                            <input type="hidden" name="csrf" value="<?=e(csrf())?>">
                            <input type="hidden" name="job_id" value="<?=e($j['id'])?>">
                            <button class="btn danger" name="action" value="delete" <?=$rowCanDelete ? '' : 'disabled'?>>Delete</button>
                        </form>
                    </div>
                </td>
                <td><a class="btn" href="<?=e(queue_job_link($j))?>">Manage</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="padding:16px 20px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <span class="muted">Showing <?=count($filteredJobs)?> of <?=$filteredTotal?> jobs</span>
        <div style="display:flex;gap:8px;align-items:center">
            <?php if ($page > 1): ?><a class="btn" href="?<?=e(queue_query_string(['page' => (string)($page - 1)]))?>">Previous</a><?php endif; ?>
            <span class="muted">Page <?=$page?> / <?=$totalPages?></span>
            <?php if ($page < $totalPages): ?><a class="btn" href="?<?=e(queue_query_string(['page' => (string)($page + 1)]))?>">Next</a><?php endif; ?>
        </div>
    </div>
</section>

<div class="admin-stats">
    <div><span>Total jobs</span><strong><?=$counts['total']?></strong></div>
    <div><span>Running jobs</span><strong><?=$counts['running']?></strong></div>
    <div><span>Waiting jobs</span><strong><?=$counts['waiting']?></strong></div>
    <div><span>Failed jobs</span><strong><?=$counts['failed']?></strong></div>
    <div><span>Workers</span><strong><?=count($workers)?></strong></div>
</div>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>QUEUE OVERVIEW</b><span class="muted">Live operational summary</span></div>
    <div style="padding:20px;display:grid;grid-template-columns:repeat(5,minmax(160px,1fr));gap:14px">
        <div style="background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:14px"><span class="muted small">Average provisioning time</span><div style="margin-top:8px;font-size:24px;font-weight:800"><?=$stats['avg_time_seconds']?>s</div></div>
        <div style="background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:14px"><span class="muted small">Success rate</span><div style="margin-top:8px;font-size:24px;font-weight:800"><?=number_format($stats['success_rate'],2)?>%</div></div>
        <div style="background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:14px"><span class="muted small">Failed jobs</span><div style="margin-top:8px;font-size:24px;font-weight:800"><?=$stats['failed_jobs']?></div></div>
        <div style="background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:14px"><span class="muted small">Node usage</span><div style="margin-top:8px;font-size:24px;font-weight:800"><?=number_format($stats['node_usage_percent'],2)?>%</div><small class="muted"><?=$usedAlloc?> / <?=$totalAlloc?> allocations used</small></div>
        <div style="background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:14px"><span class="muted small">Queue load</span><div style="margin-top:8px;font-size:24px;font-weight:800"><?=number_format($stats['queue_load_percent'],2)?>%</div><small class="muted">Load: <?=e(strtoupper($stats['queue_load_text']))?></small></div>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>NODE HEALTH CANDIDATES</b><span class="muted">Top nodes for upcoming provisioning</span></div>
    <div style="padding:14px 20px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <form method="post" style="margin:0;display:flex;gap:8px;align-items:center">
            <input type="hidden" name="csrf" value="<?=e(csrf())?>">
            <input type="hidden" name="action" value="refresh_node_cache">
            <label class="muted">Max nodes
                <input type="number" name="max_nodes" value="50" min="5" max="200" style="width:90px;margin-left:8px;background:#0f1318;border:1px solid #2b313a;color:#fff;border-radius:8px;padding:8px">
            </label>
            <button class="btn primary">Refresh Node Cache</button>
        </form>
        <span class="muted">Manual refresh supplements cron and updates smart-node scoring inputs.</span>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Node</th><th>Location</th><th>Status</th><th>CPU</th><th>RAM</th><th>Disk</th><th>Servers</th><th>Free Alloc</th><th>Synced</th></tr></thead>
            <tbody>
            <?php if (!$nodeCandidates): ?><tr><td colspan="9" class="muted">No node cache data available. Run cron to refresh node cache.</td></tr><?php endif; ?>
            <?php foreach ($nodeCandidates as $n): ?>
            <?php
                $maint = (int)($n['is_maintenance'] ?? 0) === 1;
                $cpu = (float)($n['cpu_usage_percent'] ?? 0);
                $ram = (float)($n['ram_usage_percent'] ?? 0);
                $disk = (float)($n['disk_usage_percent'] ?? 0);
                $free = (int)($n['free_allocations'] ?? 0);
                $total = (int)($n['total_allocations'] ?? 0);
                $health = (!$maint && $cpu < 85 && $ram < 85 && $disk < 90 && $free > 0) ? 'healthy' : ($maint ? 'maintenance' : 'degraded');
            ?>
            <tr>
                <td><b><?=e($n['node_name'] ?: ('Node #'.$n['node_id']))?></b><small>#<?=e($n['node_id'])?></small></td>
                <td><?=e($n['location_id'] ?: '—')?></td>
                <td><?=admin_badge($health)?></td>
                <td><?=number_format($cpu,2)?>%</td>
                <td><?=number_format($ram,2)?>%</td>
                <td><?=number_format($disk,2)?>%</td>
                <td><?=e((string)($n['servers_count'] ?? 0))?></td>
                <td><?=e((string)$free)?> / <?=e((string)$total)?></td>
                <td><?=e($n['last_sync_at'])?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>RUNNING JOBS</b><span class="muted"><?=count($runningJobs)?> active</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Job</th><th>Service</th><th>Worker</th><th>Attempts</th><th>Started</th><th>Status</th><th>Open</th></tr></thead>
            <tbody>
            <?php if (!$runningJobs): ?><tr><td colspan="7" class="muted">No running jobs.</td></tr><?php endif; ?>
            <?php foreach ($runningJobs as $j): ?>
            <tr>
                <td><b>#<?=e($j['id'])?></b><small><?=e($j['job_type'])?></small></td>
                <td><?=e(queue_service_label($j))?></td>
                <td><?=e($j['worker_id'] ?: '—')?></td>
                <td><?=e($j['attempts'])?> / <?=e($j['max_attempts'])?></td>
                <td><?=e($j['started_at'] ?: '—')?></td>
                <td><?=admin_badge((string)$j['status'])?></td>
                <td><a class="btn" href="<?=e(queue_job_link($j))?>">Manage</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>WAITING JOBS</b><span class="muted"><?=count($waitingJobs)?> queued/paused</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Job</th><th>Service</th><th>Priority</th><th>Run at</th><th>Attempts</th><th>Status</th><th>Open</th></tr></thead>
            <tbody>
            <?php if (!$waitingJobs): ?><tr><td colspan="7" class="muted">No waiting jobs.</td></tr><?php endif; ?>
            <?php foreach ($waitingJobs as $j): ?>
            <tr>
                <td><b>#<?=e($j['id'])?></b><small><?=e($j['job_type'])?></small></td>
                <td><?=e(queue_service_label($j))?></td>
                <td><?=e($j['priority'])?></td>
                <td><?=e($j['run_at'])?></td>
                <td><?=e($j['attempts'])?> / <?=e($j['max_attempts'])?></td>
                <td><?=admin_badge((string)$j['status'])?></td>
                <td><a class="btn" href="<?=e(queue_job_link($j))?>">Manage</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>FAILED JOBS</b><span class="muted"><?=count($failedJobs)?> failures</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Job</th><th>Service</th><th>Error</th><th>Attempts</th><th>Finished</th><th>Open</th></tr></thead>
            <tbody>
            <?php if (!$failedJobs): ?><tr><td colspan="6" class="muted">No failed jobs.</td></tr><?php endif; ?>
            <?php foreach ($failedJobs as $j): ?>
            <tr>
                <td><b>#<?=e($j['id'])?></b><small><?=e($j['job_type'])?></small></td>
                <td><?=e(queue_service_label($j))?></td>
                <td class="redtext"><?=e($j['last_error'] ?: 'Unknown error')?></td>
                <td><?=e($j['attempts'])?> / <?=e($j['max_attempts'])?></td>
                <td><?=e($j['finished_at'] ?: '—')?></td>
                <td><a class="btn" href="<?=e(queue_job_link($j))?>">Manage</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div class="cardhead"><b>WORKERS</b><span class="muted">Heartbeat and throughput</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Worker ID</th><th>Host</th><th>Status</th><th>Processed</th><th>Failed</th><th>Heartbeat</th><th>Seen</th></tr></thead>
            <tbody>
            <?php if (!$workers): ?><tr><td colspan="7" class="muted">No workers registered yet.</td></tr><?php endif; ?>
            <?php foreach ($workers as $w): ?>
            <?php $age = (int)$w['heartbeat_age']; $stale = $age > 120; ?>
            <tr>
                <td><b><?=e($w['worker_id'])?></b></td>
                <td><?=e($w['hostname'] ?: '—')?></td>
                <td><?=admin_badge($stale ? 'stale' : (string)$w['status'])?></td>
                <td><?=e($w['processed_jobs'])?></td>
                <td><?=e($w['failed_jobs'])?></td>
                <td><?=e((string)$age)?>s ago</td>
                <td><?=e($w['last_heartbeat'])?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php admin_foot(); ?>
<script>
(function(){
    function esc(v){return String(v ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
    function badge(status){return '<span class="admin-badge status-'+esc(String(status||'unknown'))+'">'+esc(String(status||'unknown').replaceAll('_',' ').toUpperCase())+'</span>';}

    function renderLive(d){
        const c=d.counts||{};
        const set=(id,val)=>{const el=document.getElementById(id);if(el)el.textContent=String(val??'0');};
        set('live-count-total',c.total);
        set('live-count-running',c.running);
        set('live-count-waiting',c.waiting);
        set('live-count-failed',c.failed);
        set('live-count-completed',c.completed);

        const stamp=document.getElementById('live-updated-at');
        if(stamp) stamp.textContent='Updated '+(d.server_time||new Date().toISOString().slice(0,19).replace('T',' '));

        const queueRows=(d.queue_status||[]).slice(0,25).map(it=>{
            const timeline=[it.run_at,it.started_at,it.finished_at].filter(Boolean).join(' / ');
            const progress=Math.max(0,Math.min(100,Number(it.progress||0)));
            const bar='<div style="height:8px;background:#0d1116;border-radius:999px;overflow:hidden"><div style="height:100%;width:'+progress+'%;background:linear-gradient(90deg,#ff7417,#ff9f5a)"></div></div><small class="muted">'+progress+'%</small>';
            return '<tr>'+
                '<td><b>#'+esc(it.id)+'</b></td>'+
                '<td>'+esc((it.service_name||'Service')+' (#'+(it.service_id||0)+')')+'</td>'+
                '<td>'+badge(it.status)+'</td>'+
                '<td>'+bar+'</td>'+
                '<td>'+esc(it.step||'Queued')+'</td>'+
                '<td>'+esc(it.attempts)+' / '+esc(it.max_attempts)+'</td>'+
                '<td>'+esc(timeline||'—')+'</td>'+
            '</tr>';
        }).join('') || '<tr><td colspan="7" class="muted">No queue activity.</td></tr>';
        const qBody=document.getElementById('live-queue-body'); if(qBody) qBody.innerHTML=queueRows;

        const logRows=(d.logs||[]).slice(0,30).map(l=>'<tr>'+
            '<td>'+esc(l.created_at||'')+'</td>'+
            '<td>'+esc((l.service_name||'Service')+' (#'+(l.service_id||0)+')')+'</td>'+
            '<td>'+badge(l.level||'info')+'</td>'+
            '<td>'+esc(l.event_name||'')+'</td>'+
            '<td>'+esc(l.message||'')+'</td>'+
        '</tr>').join('') || '<tr><td colspan="5" class="muted">No logs yet.</td></tr>';
        const lBody=document.getElementById('live-logs-body'); if(lBody) lBody.innerHTML=logRows;

        const workerRows=(d.workers||[]).map(w=>{
            const age=Number(w.heartbeat_age||0);
            const state=age>120?'stale':(w.status||'idle');
            return '<tr>'+
                '<td><b>'+esc(w.worker_id||'')+'</b></td>'+
                '<td>'+esc(w.hostname||'—')+'</td>'+
                '<td>'+badge(state)+'</td>'+
                '<td>'+esc(w.processed_jobs||0)+'</td>'+
                '<td>'+esc(w.failed_jobs||0)+'</td>'+
                '<td>'+esc(age)+'s ago</td>'+
            '</tr>';
        }).join('') || '<tr><td colspan="6" class="muted">No workers registered.</td></tr>';
        const wBody=document.getElementById('live-workers-body'); if(wBody) wBody.innerHTML=workerRows;

        const nodeRows=(d.node_health||[]).map(n=>{
            const maint=Number(n.is_maintenance||0)===1;
            const cpu=Number(n.cpu_usage_percent||0),ram=Number(n.ram_usage_percent||0),disk=Number(n.disk_usage_percent||0),free=Number(n.free_allocations||0);
            const health=maint?'maintenance':((cpu<85&&ram<85&&disk<90&&free>0)?'healthy':'degraded');
            return '<tr>'+
                '<td><b>'+esc(n.node_name||('Node #'+n.node_id))+'</b> <small>#'+esc(n.node_id||'')+'</small></td>'+
                '<td>'+badge(health)+'</td>'+
                '<td>'+cpu.toFixed(2)+'%</td>'+
                '<td>'+ram.toFixed(2)+'%</td>'+
                '<td>'+disk.toFixed(2)+'%</td>'+
                '<td>'+esc(n.servers_count||0)+'</td>'+
                '<td>'+esc(n.free_allocations||0)+' / '+esc(n.total_allocations||0)+'</td>'+
                '<td>'+esc(n.last_sync_at||'')+'</td>'+
            '</tr>';
        }).join('') || '<tr><td colspan="8" class="muted">No node cache data available.</td></tr>';
        const nBody=document.getElementById('live-nodes-body'); if(nBody) nBody.innerHTML=nodeRows;

        const orderRows=(d.order_completion||[]).map(o=>'<tr>'+
            '<td>'+esc(o.finished_at||'')+'</td>'+
            '<td><b>#'+esc(o.queue_id||'')+'</b></td>'+
            '<td>'+esc(o.order_number||('Order #'+(o.order_id||0)))+'</td>'+
            '<td>'+esc((o.service_name||'Service')+' (#'+(o.service_id||0)+')')+'</td>'+
            '<td>'+badge('completed')+'</td>'+
        '</tr>').join('') || '<tr><td colspan="5" class="muted">No completed provisioning jobs yet.</td></tr>';
        const oBody=document.getElementById('live-orders-body'); if(oBody) oBody.innerHTML=orderRows;
    }

    let pollTimer=null;
    let stream=null;
    let sseFailed=false;

    async function pollLive(){
        try{
            const r=await fetch('/api/admin-queue-live.php',{cache:'no-store'});
            const j=await r.json();
            if(!j.ok) throw new Error(j.error||'Live update failed');
            renderLive(j.data||{});
        }catch(e){
            const stamp=document.getElementById('live-updated-at');
            if(stamp) stamp.textContent='Polling unavailable: '+(e.message||String(e));
        }
    }

    function startPolling(){
        if(pollTimer) return;
        pollLive();
        pollTimer=setInterval(pollLive,4000);
    }

    function startStream(){
        if(!window.EventSource){
            startPolling();
            return;
        }
        stream=new EventSource('/api/admin-queue-stream.php');
        stream.addEventListener('update',ev=>{
            try{
                const p=JSON.parse(ev.data||'{}');
                if(!p.ok) throw new Error(p.error||'SSE update failed');
                renderLive(p.data||{});
            }catch(e){
                const stamp=document.getElementById('live-updated-at');
                if(stamp) stamp.textContent='SSE parse error: '+(e.message||String(e));
            }
        });
        stream.addEventListener('error',()=>{
            if(stream){stream.close();stream=null;}
            if(!sseFailed){
                sseFailed=true;
                const stamp=document.getElementById('live-updated-at');
                if(stamp) stamp.textContent='SSE disconnected, fallback polling enabled.';
                startPolling();
            }
        });
    }

    startStream();
})();
</script>
<?php if ($refreshOn): ?>
<script>
setTimeout(function () {
    location.reload();
}, <?=e((string)($refreshSec * 1000))?>);
</script>
<?php endif; ?>
