<?php
declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';
$u = require_admin();

$stats = [
    'customers' => (int)db()->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn(),
    'services' => (int)db()->query("SELECT COUNT(*) FROM services WHERE status='active'")->fetchColumn(),
    'tickets' => (int)db()->query("SELECT COUNT(*) FROM support_tickets WHERE status NOT IN ('closed','answered')")->fetchColumn(),
    'failed' => (int)db()->query("SELECT COUNT(*) FROM services WHERE status='failed'")->fetchColumn(),
    'unpaid' => (float)db()->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status IN ('unpaid','overdue')")->fetchColumn(),
    'revenue' => (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed'")->fetchColumn(),
];

$orders = db()->query("SELECT o.*,u.name customer_name,u.email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 7")->fetchAll();
$failed = db()->query("SELECT s.id,s.name,s.last_error,u.email FROM services s JOIN users u ON u.id=s.user_id WHERE s.status='failed' ORDER BY s.updated_at DESC LIMIT 5")->fetchAll();
$ptero = 'Connected';
try {
    app_ptero('/nodes?per_page=1');
} catch (Throwable $e) {
    $ptero = 'Unavailable';
}
$maintenance = app_setting('maintenance_mode', '0') === '1';
$mollieReady = (bool)cfg('mollie.api_key');
$nameParts = preg_split('/\s+/', trim((string)$u['name'])) ?: [];
$firstName = (string)($nameParts[0] ?? $u['name']);

admin_head($u, 'Dashboard', 'dashboard');
?>

<section class="admin-overview-hero">
    <div>
        <span class="admin-section-kicker"><i></i> OPERATIONS OVERVIEW</span>
        <h2><?=e(greeting())?>, <?=e($firstName)?></h2>
        <p>Monitor customers, revenue, infrastructure, and support activity from one workspace.</p>
    </div>
    <div class="admin-hero-actions">
        <a class="btn" href="/admin/support.php"><?=admin_icon('support')?> Support queue</a>
        <a class="btn primary" href="/admin/products.php"><?=admin_icon('products')?> Manage products</a>
    </div>
    <span class="admin-hero-glow" aria-hidden="true"></span>
</section>

<div class="admin-dashboard-section-head">
    <div><span>LIVE METRICS</span><h3>Business at a glance</h3></div>
    <p>Current portal activity and billing totals</p>
</div>
<section class="admin-stats modern-admin-stats" aria-label="Business summary">
    <a class="admin-stat-card tone-blue" href="/admin/customers.php">
        <span class="admin-stat-icon"><?=admin_icon('customers')?></span>
        <div><small>Customers</small><strong><?=$stats['customers']?></strong><span>Total accounts</span></div>
        <i class="admin-stat-arrow">&rarr;</i>
    </a>
    <a class="admin-stat-card tone-green" href="/admin/services.php">
        <span class="admin-stat-icon"><?=admin_icon('services')?></span>
        <div><small>Active services</small><strong><?=$stats['services']?></strong><span>Currently online</span></div>
        <i class="admin-stat-arrow">&rarr;</i>
    </a>
    <a class="admin-stat-card tone-purple" href="/admin/support.php">
        <span class="admin-stat-icon"><?=admin_icon('tickets')?></span>
        <div><small>Open tickets</small><strong><?=$stats['tickets']?></strong><span><?=$stats['tickets'] ? 'Need attention' : 'Support inbox clear'?></span></div>
        <i class="admin-stat-arrow">&rarr;</i>
    </a>
    <a class="admin-stat-card <?=$stats['failed'] > 0 ? 'tone-red' : 'tone-green'?>" href="/admin/services.php">
        <span class="admin-stat-icon"><?=admin_icon('automation')?></span>
        <div><small>Provisioning</small><strong><?=$stats['failed']?></strong><span><?=$stats['failed'] ? 'Failed services' : 'All systems healthy'?></span></div>
        <i class="admin-stat-arrow">&rarr;</i>
    </a>
    <a class="admin-stat-card tone-orange" href="/admin/billing.php">
        <span class="admin-stat-icon"><?=admin_icon('billing')?></span>
        <div><small>Paid revenue</small><strong><sup>€</sup><?=number_format($stats['revenue'], 2)?></strong><span>Completed payments</span></div>
        <i class="admin-stat-arrow">&rarr;</i>
    </a>
    <a class="admin-stat-card tone-amber" href="/admin/billing.php">
        <span class="admin-stat-icon"><?=admin_icon('orders')?></span>
        <div><small>Outstanding</small><strong><sup>€</sup><?=number_format($stats['unpaid'], 2)?></strong><span>Unpaid invoices</span></div>
        <i class="admin-stat-arrow">&rarr;</i>
    </a>
</section>

<div class="admin-dashboard-section-head is-compact">
    <div><span>SYSTEM STATUS</span><h3>Platform health</h3></div>
    <p>Live configuration checks</p>
</div>
<section class="admin-health-grid" aria-label="Platform health">
    <a class="admin-health-card" href="/admin/settings.php">
        <span class="health-icon <?=$ptero === 'Connected' ? 'is-good' : 'is-bad'?>"><?=admin_icon('services')?></span>
        <div><small>Pterodactyl API</small><b><?=e($ptero)?></b><span><?=$ptero === 'Connected' ? 'Application API is responding' : 'Check API credentials and network access'?></span></div>
        <em class="health-state <?=$ptero === 'Connected' ? 'is-good' : 'is-bad'?>"><i></i><?=$ptero === 'Connected' ? 'Operational' : 'Offline'?></em>
    </a>
    <a class="admin-health-card" href="/admin/settings.php">
        <span class="health-icon <?=$maintenance ? 'is-warn' : 'is-good'?>"><?=admin_icon('settings')?></span>
        <div><small>Maintenance mode</small><b><?=$maintenance ? 'Enabled' : 'Disabled'?></b><span><?=$maintenance ? 'Customer access is currently restricted' : 'Portal is open to customers'?></span></div>
        <em class="health-state <?=$maintenance ? 'is-warn' : 'is-good'?>"><i></i><?=$maintenance ? 'Attention' : 'Normal'?></em>
    </a>
    <a class="admin-health-card" href="/admin/settings.php">
        <span class="health-icon <?=$mollieReady ? 'is-good' : 'is-warn'?>"><?=admin_icon('billing')?></span>
        <div><small>Mollie payments</small><b><?=$mollieReady ? 'Configured' : 'Setup required'?></b><span><?=$mollieReady ? 'Checkout provider is ready' : 'Add an API key to accept payments'?></span></div>
        <em class="health-state <?=$mollieReady ? 'is-good' : 'is-warn'?>"><i></i><?=$mollieReady ? 'Ready' : 'Action needed'?></em>
    </a>
</section>

<div class="admin-grid admin-dashboard-grid">
    <section class="card admin-panel">
        <div class="cardhead">
            <div><span class="admin-panel-kicker">COMMERCE</span><b>Recent orders</b></div>
            <a class="admin-text-link" href="/admin/orders.php">View all <span>→</span></a>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><b><?=e($order['order_number'])?></b><small><?=e(date('d M Y, H:i', strtotime((string)$order['created_at'])))?></small></td>
                        <td><?=e($order['customer_name'])?><small><?=e($order['email'])?></small></td>
                        <td><?=admin_badge((string)$order['status'])?></td>
                        <td><b>€<?=number_format((float)$order['total'], 2)?></b></td>
                    </tr>
                <?php endforeach ?>
                <?php if (!$orders): ?><tr><td colspan="4" class="admin-empty-cell">No orders yet.</td></tr><?php endif ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card admin-quick admin-panel">
        <div class="cardhead"><div><span class="admin-panel-kicker">SHORTCUTS</span><b>Quick actions</b></div></div>
        <a href="/admin/customers.php"><?=admin_icon('customers')?><span><b>Find a customer</b><small>Accounts and activity</small></span><i>→</i></a>
        <a href="/admin/support.php"><?=admin_icon('support')?><span><b>Support queue</b><small>Open conversations</small></span><i>→</i></a>
        <a href="/admin/services.php"><?=admin_icon('services')?><span><b>Manage services</b><small>Servers and provisioning</small></span><i>→</i></a>
        <a href="/admin/security.php"><?=admin_icon('security')?><span><b>Security center</b><small>Production controls</small></span><i>→</i></a>
    </section>
</div>

<?php if ($failed): ?>
    <section class="card admin-panel admin-attention-panel">
        <div class="cardhead">
            <div><span class="admin-panel-kicker is-danger">ACTION REQUIRED</span><b>Provisioning needs attention</b></div>
            <span class="admin-count-pill"><?=count($failed)?> failed</span>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th>Service</th><th>Customer</th><th>Error</th></tr></thead>
                <tbody><?php foreach ($failed as $service): ?><tr><td><a href="/admin/services.php"><b><?=e($service['name'])?></b></a></td><td><?=e($service['email'])?></td><td class="redtext"><?=e($service['last_error'])?></td></tr><?php endforeach ?></tbody>
            </table>
        </div>
    </section>
<?php endif ?>

<?php admin_foot(); ?>
