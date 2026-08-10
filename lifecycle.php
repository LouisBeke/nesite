<?php require __DIR__ . '/app/bootstrap.php';
$u = require_user();
$sid = (int)($_GET['service'] ?? $_POST['service_id'] ?? 0);
$msg = $err = '';
$q = db()->prepare("SELECT s.*,p.name product_name FROM services s LEFT JOIN store_products p ON p.id=s.product_id WHERE s.id=? AND s.user_id=? AND s.status<>'terminated'");
$q->execute([$sid, $u['id']]);
$s = $q->fetch();
if (!$s) {
    http_response_code(404);
    die('Service not found.');
}
function life_log(int $sid, int $uid, string $event, string $details = ''): void
{
    db()->prepare('INSERT INTO service_lifecycle(service_id,actor_user_id,event,details) VALUES(?,?,?,?)')->execute([$sid, $uid, $event, $details]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $a = $_POST['action'] ?? '';
        if ($a === 'cancel_period') {
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (strlen($reason) < 3) throw new RuntimeException('Please provide a cancellation reason.');
            if (empty($s['next_due_at'])) {
                $nd = date('Y-m-d H:i:s', strtotime('+30 days'));
                db()->prepare('UPDATE services SET next_due_at=COALESCE(next_due_at,?), renewal_unit=COALESCE(renewal_unit,?) WHERE id=?')->execute([$nd, 'month', $sid]);
                $s['next_due_at'] = $nd;
                $s['renewal_unit'] = 'month';
            }
            db()->prepare('UPDATE services SET cancel_at_period_end=1,cancel_at=next_due_at,cancellation_reason=?,cancellation_requested_at=NOW() WHERE id=? AND user_id=?')->execute([$reason, $sid, $u['id']]);
            life_log($sid, $u['id'], 'cancel_at_period_end', 'Cancellation requested. Reason: ' . $reason);
            $msg = 'Cancellation scheduled. Your server remains active until the end of the billing period.';
        } elseif ($a === 'undo_cancel') {
            db()->prepare('UPDATE services SET cancel_at_period_end=0,cancel_at=NULL,cancellation_reason=NULL,cancellation_requested_at=NULL WHERE id=? AND user_id=?')->execute([$sid, $u['id']]);
            life_log($sid, $u['id'], 'cancellation_reversed', 'Scheduled cancellation removed.');
            $msg = 'Scheduled cancellation removed.';
        } elseif ($a === 'renew') {
            if ((float)$s['price_monthly'] <= 0) throw new RuntimeException('This service has no renewal price configured.');
            $existing = db()->prepare("SELECT id FROM invoices WHERE service_id=? AND status IN ('unpaid','overdue') LIMIT 1");
            $existing->execute([$sid]);
            $iid = (int)$existing->fetchColumn();
            if (!$iid) {
                $num = (string)setting('invoice_prefix', 'INV') . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $due = date('Y-m-d H:i:s', strtotime('+3 days'));
                db()->prepare("INSERT INTO invoices(user_id,order_id,service_id,invoice_number,status,subtotal,total,currency,due_at) VALUES(?,NULL,?,?,'unpaid',?,?,?,?)")->execute([$u['id'], $sid, $num, $s['price_monthly'], $s['price_monthly'], $s['currency'], $due]);
                $iid = (int)db()->lastInsertId();
                db()->prepare('INSERT INTO invoice_items(invoice_id,description,amount,quantity) VALUES(?,?,?,1)')->execute([$iid, $s['name'] . ' — renewal', $s['price_monthly']]);
                life_log($sid, $u['id'], 'renewal_invoice', 'Created invoice ' . $num);
            }
            header('Location: /invoice.php?id=' . $iid);
            exit;
        } else throw new RuntimeException('Unknown action.');
        $q->execute([$sid, $u['id']]);
        $s = $q->fetch();
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
$hist = db()->prepare('SELECT * FROM service_lifecycle WHERE service_id=? ORDER BY id DESC LIMIT 30');
$hist->execute([$sid]);
$hist = $hist->fetchAll();
$days = $s['next_due_at'] ? (int)floor((strtotime($s['next_due_at']) - time()) / 86400) : null;
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Billing & cancellation | FoxNetwork</title>
    <link rel="stylesheet" href="/assets/portal.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/assets/portal.css')) ?>">
</head>

<body class="portal-page"><?php render_client_page_start($u, 'billing', 'Billing and cancellation'); ?><div class="upgrade-page">
        <div class="eyebrow">SERVICE BILLING & CANCELLATION</div>
        <h1><?= e($s['name']) ?></h1>
        <p><a class="btn" href="/billing.php">← Back to Billing</a></p><?php if ($msg): ?><div class="notice success"><?= e($msg) ?></div><?php endif ?><?php if ($err): ?><div class="error"><?= e($err) ?></div><?php endif ?><section class="card lifecycle-card">
            <div class="lifecycle-stats">
                <div><span>Status</span><b><?= e(!empty($s['is_trial'])?'TRIAL':strtoupper($s['status'])) ?></b></div>
                <div><span>Package</span><b><?= e($s['product_name'] ?? 'Unmapped') ?></b></div>
                <div><span>Recurring price</span><b>€<?= number_format((float)$s['price_monthly'], 2) ?> / <?= e($s['renewal_unit'] ?? 'month') ?></b><small><?=!empty($s['is_trial'])?'Charged after trial':''?></small></div>
                <div><span><?=!empty($s['is_trial'])?'Trial expiration':'Next renewal'?></span><b><?= e($s['next_due_at'] ? date('d M Y', strtotime($s['next_due_at'])) : 'Not set') ?></b><small><?= e($days === null ? '' : ($days >= 0 ? $days . ' days remaining' : abs($days) . ' days overdue')) ?></small></div>
            </div>
            <div class="lifecycle-actions"><?php if ((float)$s['price_monthly'] > 0 && !$s['cancel_at_period_end']): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="service_id" value="<?= $sid ?>"><button class="btn primary" name="action" value="renew">Renew now</button></form><?php endif ?><a class="btn" href="/upgrades.php?service=<?= $sid ?>">Upgrade / Downgrade</a></div><?php if (!$s['cancel_at_period_end']): ?><div style="margin-top:22px;padding-top:20px;border-top:1px solid rgba(255,255,255,.08)">
                    <h3>Cancel service</h3>
                    <p class="muted">Cancellation is scheduled for the end of the current billing period. Your Pterodactyl server is not deleted immediately.</p>
                    <form method="post" onsubmit="return confirm('Schedule this service for cancellation at the end of the billing period?');"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="service_id" value="<?= $sid ?>"><label class="muted small">Reason for cancellation</label><textarea name="reason" required minlength="3" rows="3" placeholder="Tell us why you want to cancel"></textarea>
                        <div style="margin-top:10px"><button class="btn danger" name="action" value="cancel_period">Cancel at period end</button></div>
                    </form>
                </div><?php else: ?><div class="notice warning" style="margin-top:20px"><b>Cancellation scheduled<?= e($s['cancel_at'] ? ' for ' . date('d M Y', strtotime($s['cancel_at'])) : '') ?>.</b><?php if (!empty($s['cancellation_reason'])): ?><div class="muted small">Reason: <?= e($s['cancellation_reason']) ?></div><?php endif ?></div>
                <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="service_id" value="<?= $sid ?>"><button class="btn primary" name="action" value="undo_cancel">Undo cancellation</button></form><?php endif ?>
        </section>
        <section class="card" style="margin-top:20px">
            <div class="cardhead"><b>LIFECYCLE HISTORY</b></div><?php if (!$hist): ?><div class="empty muted">No lifecycle events yet.</div><?php endif ?><?php foreach ($hist as $h): ?><div class="order-row">
                    <div><b><?= e(str_replace('_', ' ', ucwords($h['event'], '_'))) ?></b>
                        <div class="muted small"><?= e($h['details']) ?></div>
                    </div><span class="muted"><?= e($h['created_at']) ?></span>
                </div><?php endforeach ?>
        </section>
    </div><?php render_client_page_end(); ?></body>

</html>
