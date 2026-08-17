<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
$u = require_user();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $serviceType = trim((string)($_POST['service_type'] ?? ''));
        $budget = trim((string)($_POST['budget'] ?? ''));
        $timeline = trim((string)($_POST['timeline'] ?? ''));
        $details = trim((string)($_POST['details'] ?? ''));

        if ($serviceType === '' || $details === '') {
            throw new RuntimeException('Please choose a service type and describe your requirements.');
        }

        $subject = 'Service request: ' . $serviceType;
        $body = "Customer: {$u['name']} ({$u['email']})\n"
            . "Service type: {$serviceType}\n"
            . "Budget: " . ($budget !== '' ? $budget : 'Not specified') . "\n"
            . "Timeline: " . ($timeline !== '' ? $timeline : 'Not specified') . "\n\n"
            . "Requirements:\n{$details}";

        $insertTicket = db()->prepare("INSERT INTO support_tickets(user_id,service_id,subject,category,priority,status) VALUES(?,NULL,?,?,?,'awaiting_staff')");
        $insertTicket->execute([$u['id'], $subject, 'sales', 'normal']);

        $ticketId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO support_messages(ticket_id,user_id,message) VALUES(?,?,?)')->execute([$ticketId, $u['id'], $body]);

        zoho_crm_try_sync_ticket($ticketId);
        ticket_notify_staff($ticketId, 'new');

        header('Location: /support.php?id=' . $ticketId);
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#0b0d10">
    <title>Service request | FoxNetwork</title>
    <link rel="stylesheet" href="/css/fontawesome-all.min.css">
    <link rel="stylesheet" href="/assets/portal.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/assets/portal.css')) ?>">
    <style>
        .request-page-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:18px}.request-panel,.request-info{background:linear-gradient(145deg,#14181e,#0d1015);border:1px solid var(--client-line);border-radius:18px;padding:24px}.request-card-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:15px}.request-card-head h2{margin:0;font-size:22px}.request-card-head small{color:#7f8997}.request-form{display:grid;gap:14px}.request-form label{display:grid;gap:7px;color:#dfe6ee;font-size:12px;font-weight:700}.request-form input,.request-form select,.request-form textarea{width:100%;box-sizing:border-box;border:1px solid rgba(255,255,255,.08);background:#0b0e12;border-radius:10px;padding:12px 14px;color:#edf4ff}.request-form textarea{min-height:160px;resize:vertical}.request-form .inline-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.request-badges{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}.request-badges span{padding:7px 10px;border-radius:999px;border:1px solid rgba(255,116,23,.3);background:rgba(255,116,23,.08);color:#ffb37d;font-size:11px;font-weight:700}.request-details{display:grid;gap:12px}.request-details p{margin:0;color:#95a1af;line-height:1.65}.request-inline{display:flex;justify-content:space-between;align-items:center;gap:10px}.request-inline strong{font-size:20px}.request-cta{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:none;border-radius:12px;padding:12px 16px;background:linear-gradient(135deg,#ff7417,#ff9a4d);color:#111317;font-weight:800;cursor:pointer;text-decoration:none}.request-note{padding:10px 12px;border-radius:10px;background:rgba(53,207,131,.09);color:#b5f5d0;border:1px solid rgba(53,207,131,.25)}@media(max-width:900px){.request-page-grid{grid-template-columns:1fr}.request-form .inline-grid{grid-template-columns:1fr}}
    </style>
</head>
<body class="client-body">
<div class="client-shell">
    <?php render_client_sidebar($u, 'services', null, 'client'); ?>
    <main class="client-main">
        <header class="client-topbar">
            <div class="client-page-title"><span>Control Center</span><small>Request a service</small></div>
            <div class="client-top-actions">
                <a class="topbar-action" href="/support.php" aria-label="Open support"><i class="far fa-question-circle" aria-hidden="true"></i></a>
                <a class="client-profile" href="/settings-profile.php">
                    <span class="client-profile-copy"><b><?= e($u['name']) ?></b><small>Customer account</small></span>
                    <span class="client-avatar"><?= e(mb_strtoupper(mb_substr(trim((string)$u['name']), 0, 1))) ?></span>
                </a>
            </div>
        </header>

        <div class="client-content">
            <div class="services-page-head">
                <div>
                    <span class="section-kicker">CUSTOM SOLUTIONS</span>
                    <h1>Request a service</h1>
                    <p>Tell us what you need and the team will recommend the right hosting setup.</p>
                </div>
                <a class="client-btn client-btn-ghost" href="/store.php"><i class="fas fa-shopping-cart"></i> Browse plans</a>
            </div>

            <?php if ($msg): ?><div class="notice"><?= e($msg) ?></div><?php endif ?>
            <?php if ($err): ?><div class="error"><?= e($err) ?></div><?php endif ?>

            <div class="request-page-grid">
                <section class="request-panel">
                    <div class="request-card-head">
                        <div>
                            <h2>Service details</h2>
                            <small>Fast request form</small>
                        </div>
                    </div>

                    <form method="post" class="request-form">
                        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

                        <label>
                            Service type
                            <select name="service_type" required>
                                <option value="">Select a type</option>
                                <option value="Game hosting">Game hosting</option>
                                <option value="Cloud VPS">Cloud VPS</option>
                                <option value="Web hosting">Web hosting</option>
                                <option value="Discord bot hosting">Discord bot hosting</option>
                                <option value="Custom setup">Custom setup</option>
                            </select>
                        </label>

                        <div class="inline-grid">
                            <label>
                                Budget
                                <input name="budget" type="text" placeholder="€15/month or custom range">
                            </label>
                            <label>
                                Timeline
                                <input name="timeline" type="text" placeholder="ASAP / within 2 weeks / flexible">
                            </label>
                        </div>

                        <label>
                            Requirements
                            <textarea name="details" placeholder="Describe your use case, expected traffic, software, CPU/RAM requirements, or any specific setup details." required></textarea>
                        </label>

                        <button class="request-cta" type="submit"><i class="fas fa-paper-plane"></i> Send request</button>
                    </form>
                </section>

                <aside class="request-info">
                    <div class="request-card-head">
                        <div>
                            <h2>What happens next?</h2>
                            <small>Usually within 1 business day</small>
                        </div>
                    </div>

                    <div class="request-details">
                        <div class="request-note">Your request is created as a support ticket and reviewed by the FoxNetwork team.</div>
                        <p>We’ll help confirm the best hosting option, estimate pricing, and recommend the right server configuration for your project.</p>
                        <p>Examples include custom game-server setups, VPS sizing, website hosting, bot hosting, or a project requiring specific resources.</p>
                        <div class="request-badges">
                            <span>Game servers</span>
                            <span>VPS</span>
                            <span>Web hosting</span>
                            <span>Custom builds</span>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </main>
</div>
</body>
</html>
