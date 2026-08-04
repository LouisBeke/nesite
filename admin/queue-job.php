<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';
$u = require_admin();
$msg = '';
$err = '';
$jobId = (int)($_GET['id'] ?? $_POST['job_id'] ?? 0);
$allowedRefresh = [5, 10, 20, 30, 60];
$refreshOn = (string)($_GET['refresh'] ?? '0') === '1';
$refreshSec = (int)($_GET['refresh_sec'] ?? 10);
if (!in_array($refreshSec, $allowedRefresh, true)) $refreshSec = 10;

function queue_job_query_string(array $overrides = []): string {
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

function load_queue_job(int $jobId): array {
    $q = db()->prepare("SELECT q.*,
        JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS payload_service_id,
        s.name AS service_name,
        s.status AS service_status
        FROM provisioning_queue q
        LEFT JOIN services s ON s.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.service_id')) AS UNSIGNED)
        WHERE q.id=? LIMIT 1");
    $q->execute([$jobId]);
    $j = $q->fetch();
    if (!$j) {
        throw new RuntimeException('Queue job not found.');
    }
    return $j;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($jobId <= 0) {
            throw new RuntimeException('Invalid job ID.');
        }

        $job = load_queue_job($jobId);
        $status = (string)$job['status'];

        if ($action === 'pause') {
            if (!in_array($status, ['pending', 'retry_wait'], true)) {
                throw new RuntimeException('Only waiting jobs can be paused.');
            }
            db()->prepare("UPDATE provisioning_queue SET status='paused',worker_id=NULL WHERE id=?")->execute([$jobId]);
            provisioning_emit_event('queue.job_paused', ['admin_id' => (int)$u['id']], $jobId, 'warning');
            $msg = 'Job paused.';
        } elseif ($action === 'resume') {
            if ($status !== 'paused') {
                throw new RuntimeException('Only paused jobs can be resumed.');
            }
            db()->prepare("UPDATE provisioning_queue SET status='pending',run_at=NOW(),worker_id=NULL WHERE id=?")->execute([$jobId]);
            provisioning_emit_event('queue.job_resumed', ['admin_id' => (int)$u['id']], $jobId);
            $msg = 'Job resumed.';
        } elseif ($action === 'cancel') {
            if (!in_array($status, ['pending', 'retry_wait', 'paused'], true)) {
                throw new RuntimeException('Only waiting/paused jobs can be cancelled safely.');
            }
            db()->prepare("UPDATE provisioning_queue SET status='cancelled',finished_at=NOW(),worker_id=NULL WHERE id=?")->execute([$jobId]);
            provisioning_emit_event('queue.job_cancelled', ['admin_id' => (int)$u['id']], $jobId, 'warning');
            $msg = 'Job cancelled.';
        } elseif ($action === 'retry') {
            if ($status === 'running') {
                throw new RuntimeException('Running jobs cannot be retried.');
            }
            db()->prepare("UPDATE provisioning_queue SET status='pending',attempts=0,last_error=NULL,run_at=NOW(),started_at=NULL,finished_at=NULL,worker_id=NULL WHERE id=?")->execute([$jobId]);
            provisioning_emit_event('queue.job_retry_forced', ['admin_id' => (int)$u['id']], $jobId, 'warning');
            $msg = 'Job moved back to pending state.';
        } elseif ($action === 'delete') {
            if ($status === 'running') {
                throw new RuntimeException('Running jobs cannot be deleted.');
            }
            db()->beginTransaction();
            db()->prepare('DELETE FROM provisioning_logs WHERE queue_id=?')->execute([$jobId]);
            db()->prepare('DELETE FROM provisioning_queue WHERE id=?')->execute([$jobId]);
            db()->commit();
            header('Location: /admin/queue.php?deleted=1');
            exit;
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $err = $e->getMessage();
    }
}

if ($jobId <= 0) {
    http_response_code(400);
    die('Missing job ID.');
}

try {
    $job = load_queue_job($jobId);
} catch (Throwable $e) {
    http_response_code(404);
    die(e($e->getMessage()));
}

$logsQ = db()->prepare('SELECT * FROM provisioning_logs WHERE queue_id=? ORDER BY id DESC LIMIT 250');
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

$contextMeta = [];
if (!empty($job['payload'])) {
    $decoded = json_decode((string)$job['payload'], true);
    if (is_array($decoded)) {
        $contextMeta = $decoded;
    }
}

$canPause = in_array((string)$job['status'], ['pending', 'retry_wait'], true);
$canResume = ((string)$job['status'] === 'paused');
$canCancel = in_array((string)$job['status'], ['pending', 'retry_wait', 'paused'], true);
$canRetry = ((string)$job['status'] !== 'running');
$canDelete = ((string)$job['status'] !== 'running');

admin_head($u, 'Queue Job #'.$jobId, 'queue');
?>
<?php if ($msg): ?><div class="notice"><?=e($msg)?></div><?php endif; ?>
<?php if ($err): ?><div class="error"><?=e($err)?></div><?php endif; ?>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>JOB OVERVIEW</b><span class="muted" id="live-job-updated">Waiting for live data…</span><a class="link" href="/admin/queue.php">Back to queue</a></div>
    <div style="padding:14px 22px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <span class="muted">Auto refresh:</span>
        <?php if ($refreshOn): ?>
            <a class="btn warning" href="?<?=e(queue_job_query_string(['refresh' => '0']))?>">On (disable)</a>
        <?php else: ?>
            <a class="btn" href="?<?=e(queue_job_query_string(['refresh' => '1']))?>">Off (enable)</a>
        <?php endif; ?>
        <span class="muted">Interval:</span>
        <?php foreach ($allowedRefresh as $sec): ?>
            <a class="btn" href="?<?=e(queue_job_query_string(['refresh_sec' => (string)$sec]))?>" style="<?=$refreshSec === $sec ? 'border-color:#ff7417;color:#ff7417' : ''?>"><?=e($sec)?>s</a>
        <?php endforeach; ?>
    </div>
    <div style="padding:22px;display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:14px">
        <div style="background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:13px"><span class="muted small">Status</span><div style="margin-top:7px" id="live-job-status"><?=admin_badge((string)$job['status'])?></div></div>
        <div style="background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:13px"><span class="muted small">Service</span><div style="margin-top:7px;font-weight:700" id="live-job-service"><?=e(($job['service_name'] ?: 'Unknown').' (#'.((int)$job['payload_service_id']).')')?></div></div>
        <div style="background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:13px"><span class="muted small">Attempts</span><div style="margin-top:7px;font-weight:700" id="live-job-attempts"><?=e($job['attempts'])?> / <?=e($job['max_attempts'])?></div></div>
        <div style="background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:13px"><span class="muted small">Worker</span><div style="margin-top:7px;font-weight:700" id="live-job-worker"><?=e($job['worker_id'] ?: '—')?></div></div>
    </div>
    <div style="padding:0 22px 18px">
        <div class="muted small" style="margin-bottom:6px">Progress</div>
        <div style="height:9px;background:#0d1116;border-radius:999px;overflow:hidden"><div id="live-job-progress-fill" style="height:100%;width:0;background:linear-gradient(90deg,#ff7417,#ff9f5a)"></div></div>
        <div style="display:flex;justify-content:space-between;gap:10px;margin-top:6px"><small class="muted" id="live-job-progress-text">0%</small><small class="muted" id="live-job-step">Queued</small></div>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>INFRASTRUCTURE DECISION</b><span class="muted">Smart node and allocation tracking</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <tbody>
                <tr><th>Selected node</th><td id="live-infra-node"><?=e($infra['node_id'] > 0 ? ('#'.$infra['node_id']) : '—')?></td></tr>
                <tr><th>Reserved allocation</th><td id="live-infra-allocation"><?=e($infra['allocation_id'] > 0 ? ('#'.$infra['allocation_id']) : '—')?></td></tr>
                <tr><th>Selection score</th><td id="live-infra-score"><?=e($infra['score'] !== '' ? $infra['score'] : '—')?></td></tr>
                <tr><th>Node CPU usage</th><td id="live-infra-cpu"><?=e($infra['cpu'] !== '' ? ($infra['cpu'].'%') : '—')?></td></tr>
                <tr><th>Node RAM usage</th><td id="live-infra-ram"><?=e($infra['ram'] !== '' ? ($infra['ram'].'%') : '—')?></td></tr>
                <tr><th>Node Disk usage</th><td id="live-infra-disk"><?=e($infra['disk'] !== '' ? ($infra['disk'].'%') : '—')?></td></tr>
                <tr><th>Existing servers on node</th><td id="live-infra-servers"><?=e($infra['servers'] !== '' ? $infra['servers'] : '—')?></td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>ALLOCATION LOCK TIMELINE</b><span class="muted"><?=count($locks)?> lock event(s)</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Node</th><th>Allocation</th><th>Status</th><th>Reason</th><th>Locked at</th><th>Released at</th></tr></thead>
            <tbody id="live-locks-body">
            <?php if (!$locks): ?><tr><td colspan="6" class="muted">No allocation locks recorded for this job.</td></tr><?php endif; ?>
            <?php foreach ($locks as $lk): ?>
            <tr>
                <td><?=e(($lk['node_name'] ?: 'Node').' #'.$lk['node_id'])?></td>
                <td>#<?=e($lk['allocation_id'])?></td>
                <td><?=admin_badge((string)$lk['status'])?></td>
                <td><?=e($lk['release_reason'] ?: '—')?></td>
                <td><?=e($lk['locked_at'])?></td>
                <td><?=e($lk['released_at'] ?: '—')?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>JOB ACTIONS</b><span class="muted">Retry, cancel, pause, resume, delete</span></div>
    <div style="padding:22px;display:flex;flex-wrap:wrap;gap:10px">
        <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="job_id" value="<?=e($jobId)?>"><button class="btn" name="action" value="pause" <?=$canPause?'':'disabled'?>>Pause</button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="job_id" value="<?=e($jobId)?>"><button class="btn" name="action" value="resume" <?=$canResume?'':'disabled'?>>Resume</button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="job_id" value="<?=e($jobId)?>"><button class="btn warning" name="action" value="cancel" <?=$canCancel?'':'disabled'?>>Cancel</button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="job_id" value="<?=e($jobId)?>"><button class="btn primary" name="action" value="retry" <?=$canRetry?'':'disabled'?>>Retry</button></form>
        <form method="post" onsubmit="return confirm('Delete this queue job and all logs?');"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="job_id" value="<?=e($jobId)?>"><button class="btn danger" name="action" value="delete" <?=$canDelete?'':'disabled'?>>Delete</button></form>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>JOB DETAILS</b><span class="muted">Queue metadata</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <tbody>
                <tr><th>Job key</th><td><?=e($job['job_key'] ?: '—')?></td></tr>
                <tr><th>Job type</th><td><?=e($job['job_type'])?></td></tr>
                <tr><th>Priority</th><td><?=e($job['priority'])?></td></tr>
                <tr><th>Run at</th><td id="live-job-run-at"><?=e($job['run_at'])?></td></tr>
                <tr><th>Created at</th><td><?=e($job['created_at'])?></td></tr>
                <tr><th>Updated at</th><td><?=e($job['updated_at'])?></td></tr>
                <tr><th>Started at</th><td id="live-job-started-at"><?=e($job['started_at'] ?: '—')?></td></tr>
                <tr><th>Finished at</th><td id="live-job-finished-at"><?=e($job['finished_at'] ?: '—')?></td></tr>
                <tr><th>Last error</th><td class="redtext" id="live-job-last-error"><?=e($job['last_error'] ?: '—')?></td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-bottom:20px">
    <div class="cardhead"><b>PAYLOAD</b><span class="muted">Raw queued context</span></div>
    <div style="padding:22px"><pre style="margin:0;background:#0f1318;border:1px solid #2b313a;border-radius:12px;padding:14px;overflow:auto;color:#e9edf3"><?=e(json_encode($contextMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')?></pre></div>
</section>

<section class="card">
    <div class="cardhead"><b>LOGS</b><span class="muted"><?=count($logs)?> events</span></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Time</th><th>Level</th><th>Event</th><th>Message</th><th>Context</th></tr></thead>
            <tbody id="live-job-logs-body">
            <?php if (!$logs): ?><tr><td colspan="5" class="muted">No logs yet for this job.</td></tr><?php endif; ?>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td><?=e($log['created_at'])?></td>
                <td><?=admin_badge((string)$log['level'])?></td>
                <td><?=e($log['event_name'])?></td>
                <td><?=e($log['message'] ?: '—')?></td>
                <td>
                    <?php
                    $ctx = (string)($log['context_json'] ?? '');
                    if ($ctx === '') {
                        echo '—';
                    } else {
                        echo '<pre style="margin:0;background:#0f1318;border:1px solid #2b313a;border-radius:8px;padding:10px;white-space:pre-wrap;max-width:520px">'.e($ctx).'</pre>';
                    }
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php admin_foot(); ?>
<script>
(function(){
    const jobId = <?=json_encode($jobId)?>;
    function esc(v){return String(v ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
    function badge(status){return '<span class="admin-badge status-'+esc(String(status||'unknown'))+'">'+esc(String(status||'unknown').replaceAll('_',' ').toUpperCase())+'</span>';}
    function setText(id, val){const el=document.getElementById(id); if(el) el.textContent = String(val ?? '');}
    function setHtml(id, val){const el=document.getElementById(id); if(el) el.innerHTML = val;}

    function render(d){
        const job=d.job||{};
        setHtml('live-job-status', badge(job.status||'unknown'));
        setText('live-job-service', (job.service_name||'Service')+' (#'+(job.service_id||0)+')');
        setText('live-job-attempts', (job.attempts||0)+' / '+(job.max_attempts||0));
        setText('live-job-worker', job.worker_id||'—');
        setText('live-job-run-at', job.run_at||'—');
        setText('live-job-started-at', job.started_at||'—');
        setText('live-job-finished-at', job.finished_at||'—');
        setText('live-job-last-error', job.last_error||'—');
        const progress=Math.max(0,Math.min(100,Number(job.progress||0)));
        const fill=document.getElementById('live-job-progress-fill'); if(fill) fill.style.width=progress+'%';
        setText('live-job-progress-text', progress+'%');
        setText('live-job-step', job.step||'Queued');

        const infra=d.infra||{};
        setText('live-infra-node', infra.node_id ? ('#'+infra.node_id) : '—');
        setText('live-infra-allocation', infra.allocation_id ? ('#'+infra.allocation_id) : '—');
        setText('live-infra-score', infra.score || '—');
        setText('live-infra-cpu', infra.cpu !== '' ? (infra.cpu+'%') : '—');
        setText('live-infra-ram', infra.ram !== '' ? (infra.ram+'%') : '—');
        setText('live-infra-disk', infra.disk !== '' ? (infra.disk+'%') : '—');
        setText('live-infra-servers', infra.servers !== '' ? infra.servers : '—');

        const lockRows=(d.locks||[]).map(l=>'<tr>'+
            '<td>'+esc((l.node_name||'Node')+' #'+l.node_id)+'</td>'+
            '<td>#'+esc(l.allocation_id)+'</td>'+
            '<td>'+badge(l.status||'unknown')+'</td>'+
            '<td>'+esc(l.release_reason||'—')+'</td>'+
            '<td>'+esc(l.locked_at||'—')+'</td>'+
            '<td>'+esc(l.released_at||'—')+'</td>'+
        '</tr>').join('') || '<tr><td colspan="6" class="muted">No allocation locks recorded for this job.</td></tr>';
        setHtml('live-locks-body', lockRows);

        const logRows=(d.logs||[]).slice(0,120).map(l=>'<tr>'+
            '<td>'+esc(l.created_at||'')+'</td>'+
            '<td>'+badge(l.level||'info')+'</td>'+
            '<td>'+esc(l.event_name||'')+'</td>'+
            '<td>'+esc(l.message||'—')+'</td>'+
            '<td>'+(l.context_json?('<pre style="margin:0;background:#0f1318;border:1px solid #2b313a;border-radius:8px;padding:10px;white-space:pre-wrap;max-width:520px">'+esc(l.context_json)+'</pre>'):'—')+'</td>'+
        '</tr>').join('') || '<tr><td colspan="5" class="muted">No logs yet for this job.</td></tr>';
        setHtml('live-job-logs-body', logRows);

        setText('live-job-updated', 'Updated '+(d.server_time||new Date().toISOString().slice(0,19).replace('T',' ')));
    }

    let pollTimer=null;
    let stream=null;
    let sseFailed=false;

    async function poll(){
        try{
            const r=await fetch('/api/admin-queue-job-live.php?id='+encodeURIComponent(jobId),{cache:'no-store'});
            const j=await r.json();
            if(!j.ok) throw new Error(j.error||'Live update failed');
            render(j.data||{});
        }catch(e){
            setText('live-job-updated','Polling unavailable: '+(e.message||String(e)));
        }
    }

    function startPolling(){
        if(pollTimer) return;
        poll();
        pollTimer=setInterval(poll,4000);
    }

    function startStream(){
        if(!window.EventSource){ startPolling(); return; }
        stream=new EventSource('/api/admin-queue-job-stream.php?id='+encodeURIComponent(jobId));
        stream.addEventListener('update',ev=>{
            try{
                const p=JSON.parse(ev.data||'{}');
                if(!p.ok) throw new Error(p.error||'SSE update failed');
                render(p.data||{});
            }catch(e){
                setText('live-job-updated','SSE parse error: '+(e.message||String(e)));
            }
        });
        stream.addEventListener('error',()=>{
            if(stream){stream.close();stream=null;}
            if(!sseFailed){
                sseFailed=true;
                setText('live-job-updated','SSE disconnected, fallback polling enabled.');
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
