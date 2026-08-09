<?php require __DIR__ . '/app/bootstrap.php';
$u = require_user();
$id = (int)($_GET['id'] ?? 0);
$q = db()->prepare("SELECT i.*,o.order_number,s.name service_name FROM invoices i LEFT JOIN orders o ON o.id=i.order_id LEFT JOIN services s ON s.id=i.service_id WHERE i.id=? AND i.user_id=?");
$q->execute([$id, $u['id']]);
$i = $q->fetch();
if (!$i) {
    http_response_code(404);
    die('Invoice not found.');
}
$x = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id');
$x->execute([$id]);
$items = $x->fetchAll(); ?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($i['invoice_number']) ?> | FoxNetwork</title>
    <link rel="stylesheet" href="/assets/portal.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/assets/portal.css')) ?>">
    <style>
        @media print {
            .no-print {
                display: none !important
            }

            body {
                background: #fff !important;
                color: #111 !important
            }

            .card {
                border: 1px solid #ddd !important;
                background: #fff !important
            }
        }
    </style>
</head>

<body class="portal-page"><?php render_client_page_start($u, 'billing', 'Invoice'); ?><div class="upgrade-page">
        <div class="eyebrow">FOXNETWORK INVOICE</div>
        <h1><?= e($i['invoice_number']) ?></h1>
        <section class="card">
            <div class="lifecycle-stats">
                <div><span>Status</span><b><?= e(strtoupper($i['status'])) ?></b></div>
                <div><span>Issued</span><b><?= e(date('d M Y', strtotime($i['created_at']))) ?></b></div>
                <div><span>Due</span><b><?= e(date('d M Y', strtotime($i['due_at']))) ?></b></div>
                <div><span>Total</span><b>€<?= number_format((float)$i['total'], 2) ?></b></div>
            </div><?php foreach ($items as $it): ?><div class="order-row">
                    <div><b><?= e($it['description']) ?></b>
                        <div class="muted small">Quantity <?= e($it['quantity']) ?></div>
                    </div>
                    <div class="order-price">€<?= number_format((float)$it['amount'] * (int)$it['quantity'], 2) ?></div>
                </div><?php endforeach ?><div class="order-row">
                <div><b>Total</b></div>
                <div class="order-price"><b>€<?= number_format((float)$i['total'], 2) ?> <?= e($i['currency']) ?></b></div>
            </div>
        </section>
        <p class="no-print"><a class="btn" href="/billing.php">← Billing</a> <button class="btn" onclick="window.print()">Print / Save PDF</button><?php if (in_array($i['status'], ['unpaid', 'overdue'], true)): ?> <a class="btn primary" href="/pay.php?invoice=<?= $id ?>">Pay with Mollie</a><?php endif ?></p>
    </div><?php render_client_page_end(); ?></body>

</html>