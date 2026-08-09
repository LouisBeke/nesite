<?php require __DIR__ . '/app/bootstrap.php';
$u = require_user(); ?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>FoxNetwork | Orders</title>
    <link rel="stylesheet" href="/css/fontawesome-all.min.css">
    <link rel="stylesheet" href="/assets/portal.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/assets/portal.css')) ?>">
</head>

<body class="portal-page">
    <div class="app"><?php render_client_sidebar($u, 'orders'); ?><main class="main">
            <header>
                <div class="profile">
                    <div><b><?= e($u['name']) ?></b>
                        <div class="muted" style="font-size:12px"><?= e(ucfirst($u['role'])) ?></div>
                    </div>
                    <div class="avatar"><?= e(strtoupper(substr($u['name'], 0, 1))) ?></div>
                </div>
            </header>
            <div class="content"><?php $q = db()->prepare("SELECT o.*,GROUP_CONCAT(oi.product_name SEPARATOR ', ') products FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.id WHERE o.user_id=? GROUP BY o.id ORDER BY o.id DESC");
                                    $q->execute([$u['id']]);
                                    $orders = $q->fetchAll(); ?>
                <div class="eyebrow">Customer Orders</div>
                <h1>Your orders.</h1>
                <div class="muted">Track new services before they become active servers.</div><?php if (isset($_GET['created'])): ?><div class="notice">Order <b><?= e($_GET['created']) ?></b> was created successfully.</div><?php endif ?><section class="card orders-card">
                    <div class="cardhead"><b>ORDERS</b><span class="muted"><?= count($orders) ?> total</span></div><?php if (!$orders): ?><div class="empty muted">No orders yet. <a class="link" href="/store.php">Visit the Store</a>.</div><?php endif ?><?php foreach ($orders as $o): ?><div class="order-row">
                            <div><b><?= e($o['order_number']) ?></b>
                                <div class="muted small"><?= e($o['products'] ?: 'Service') ?> · <?= e(date('d M Y H:i', strtotime($o['created_at']))) ?></div>
                            </div>
                            <div class="order-price">€<?= number_format((float)$o['total'], 2) ?></div><span class="order-status status-<?= e($o['status']) ?>"><?= e(strtoupper(str_replace('_', ' ', $o['status']))) ?></span>
                        </div><?php endforeach ?>
                </section>
            </div>
        </main>
    </div>
</body>

</html>