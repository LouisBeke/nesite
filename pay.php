<?php
declare(strict_types=1);

require __DIR__.'/app/bootstrap.php';
$u = require_user();
$invoiceId = (int)($_GET['invoice'] ?? 0);

$q = db()->prepare('SELECT * FROM invoices WHERE id=? AND user_id=?');
$q->execute([$invoiceId, $u['id']]);
$invoice = $q->fetch();
if (!$invoice) {
    http_response_code(404);
    die('Invoice not found.');
}

try {
    $payment = mollie_payment_for_invoice($invoiceId);
    $url = $payment['_links']['checkout']['href'] ?? '';
    if (!$url) throw new RuntimeException('Mollie did not return a checkout URL.');
    header('Location: '.$url, true, 303);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Payment unavailable | FoxNetwork</title>
        <link rel="stylesheet" href="/assets/portal.css?v=<?=rawurlencode((string)@filemtime(__DIR__.'/assets/portal.css'))?>">
<?= opinly_head() ?>
    </head>
    <body>
    <div class="auth">
        <main class="authbox">
            <img src="/images/logo.png" alt="FoxNetwork">
            <h1>Payment could not be started</h1>
            <p class="muted">Your invoice is unchanged. Please try again or return to billing.</p>
            <div class="error"><?=e($e->getMessage())?></div>
            <p><a class="btn primary wide" href="/billing.php">Back to billing</a></p>
        </main>
    </div>
    <script defer src="/js/marketing-animations.js?v=20260829b"></script>
    </body>
    </html>
    <?php
}
