<?php
declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';
$u = require_admin();
$msg = '';
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'retry') {
            $id = (int)($_POST['job_id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Automation job not found.');
            automation_retry_job($id);
            $msg = 'Automation job queued for retry.';
        } elseif ($action === 'retry_failed') {
            $count = db()->exec("UPDATE automation_jobs SET status='pending',attempts=0,run_at=NOW(),started_at=NULL,completed_at=NULL,last_error=NULL,requested_version=requested_version+1,updated_at=NOW() WHERE status='failed'");
            $msg = (int)$count.' failed job(s) queued for retry.';
        } elseif ($action === 'run') {
            @set_time_limit(120);
            $result = automation_run_worker(5);
            $msg = $result['processed'].' processed, '.$result['skipped'].' skipped, '.$result['failed'].' failed, '.$result['queued'].' still queued.';
        } else {
            throw new RuntimeException('Unknown automation action.');
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$stats = ['pending' => 0, 'retry_wait' => 0, 'running' => 0, 'completed' => 0, 'skipped' => 0, 'failed' => 0];
foreach (db()->query('SELECT status,COUNT(*) total FROM automation_jobs GROUP BY status')->fetchAll() as $row) {
    $stats[(string)$row['status']] = (int)$row['total'];
}
$jobs = db()->query('SELECT * FROM automation_jobs ORDER BY updated_at DESC,id DESC LIMIT 200')->fetchAll();

admin_head($u, 'Automation', 'automation');
?>
<?php if ($msg): ?><div class="notice"><?=e($msg)?></div><?php endif ?>
<?php if ($err): ?><div class="error"><?=e($err)?></div><?php endif ?>

<section class="stats">
    <div class="stat"><span class="muted">Queued</span><strong><?=e($stats['pending'] + $stats['retry_wait'])?></strong></div>
    <div class="stat"><span class="muted">Running</span><strong><?=e($stats['running'])?></strong></div>
    <div class="stat"><span class="muted">Completed</span><strong><?=e($stats['completed'])?></strong></div>
    <div class="stat"><span class="muted">Skipped</span><strong><?=e($stats['skipped'])?></strong></div>
    <div class="stat"><span class="muted">Failed</span><strong><?=e($stats['failed'])?></strong></div>
</section>

<section class="card" style="margin-top:18px">
    <div class="cardhead">
        <div><b>AUTOMATION JOBS</b><div class="muted small">Temporary Zoho errors retry automatically. Jobs for deleted local records are skipped.</div></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><button class="btn primary" name="action" value="run">Run queued jobs now</button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><button class="btn" name="action" value="retry_failed">Retry all failed</button></form>
        </div>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Job</th><th>Entity</th><th>Status</th><th>Attempts</th><th>Next run</th><th>Last error</th><th></th></tr></thead>
            <tbody>
            <?php if (!$jobs): ?><tr><td colspan="7" class="muted">No automation jobs yet.</td></tr><?php endif ?>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td><b><?=e(strtoupper((string)$job['provider']))?></b><small><?=e(str_replace('_', ' ', (string)$job['job_type']))?></small></td>
                    <td><?=e($job['entity_type'])?> #<?=e($job['entity_id'])?></td>
                    <td><?=admin_badge((string)$job['status'])?></td>
                    <td><?=e($job['attempts'])?> / <?=e($job['max_attempts'])?></td>
                    <td><small><?=e($job['run_at'])?></small></td>
                    <td><small><?=e(mb_substr((string)($job['last_error'] ?? ''), 0, 180))?></small></td>
                    <td>
                        <?php if (in_array($job['status'], ['failed', 'retry_wait'], true)): ?>
                        <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="job_id" value="<?=e($job['id'])?>"><button class="btn" name="action" value="retry">Retry</button></form>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>

<?php admin_foot(); ?>
