<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$u = require_admin();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = (string)($_POST['action'] ?? 'role');
        if ($action === 'sync_zoho_all') {
            if (!zoho_crm_enabled()) throw new RuntimeException('Enable Zoho CRM sync in Admin > Settings first.');
            $customers = db()->query("SELECT * FROM users WHERE role='customer' ORDER BY id")->fetchAll();
            $synced = 0;
            $failed = 0;
            foreach ($customers as $customer) {
                try {
                    zoho_crm_sync_customer($customer);
                    $synced++;
                } catch (Throwable $syncError) {
                    $failed++;
                    error_log('FoxNetwork bulk Zoho CRM sync failed for user '.(int)$customer['id'].': '.$syncError->getMessage());
                }
            }
            $msg = 'Zoho CRM sync finished: '.$synced.' synced, '.$failed.' failed.';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === (int)$u['id']) {
                throw new RuntimeException('You cannot change your own administrator role here.');
            }
            $role = (($_POST['role'] ?? 'customer') === 'admin') ? 'admin' : 'customer';
            $q = db()->prepare('UPDATE users SET role=? WHERE id=?');
            $q->execute([$role, $id]);
            $msg = 'Customer role updated.';
        }
    } catch (Throwable $x) {
        $err = $x->getMessage();
    }
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $s = db()->prepare("SELECT u.*,(SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id) order_count FROM users u WHERE u.name LIKE ? OR u.email LIKE ? ORDER BY u.id DESC");
    $s->execute(['%' . $q . '%', '%' . $q . '%']);
    $rows = $s->fetchAll();
} else {
    $rows = db()->query("SELECT u.*,(SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id) order_count FROM users u ORDER BY u.id DESC")->fetchAll();
}

admin_head($u, 'Customers', 'customers');
?>
<?php if ($msg): ?>
<div class="notice"><?= e($msg) ?></div>
<?php endif ?>
<?php if ($err): ?>
<div class="error"><?= e($err) ?></div>
<?php endif ?>

<form class="admin-search">
    <input name="q" value="<?= e($q) ?>" placeholder="Search name or email...">
    <button class="btn">Search</button>
</form>

<section class="card">
    <div class="cardhead">
        <b>CUSTOMER ACCOUNTS</b>
        <div style="display:flex;align-items:center;gap:10px">
            <span class="muted"><?= count($rows) ?> shown</span>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                <input type="hidden" name="action" value="sync_zoho_all">
                <button class="btn">Sync all to Zoho CRM</button>
            </form>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Role</th>
                    <th>Orders</th>
                    <th>Pterodactyl</th>
                    <th>Joined</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <b><a class="link" href="/admin/customer.php?id=<?= e($r['id']) ?>"><?= e($r['name']) ?></a></b>
                        <small><?= e($r['email']) ?></small>
                    </td>
                    <td><?= e(ucfirst((string)$r['role'])) ?></td>
                    <td><?= e($r['order_count']) ?></td>
                    <td>
                        <?php if (!empty($r['ptero_user_id'])): ?>
                            Linked by email
                        <?php elseif (!empty($r['ptero_client_key'])): ?>
                            Client key saved
                        <?php else: ?>
                            Not linked
                        <?php endif ?>
                    </td>
                    <td><?= e($r['created_at']) ?></td>
                    <td>
                        <?php if ((int)$r['id'] !== (int)$u['id']): ?>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                            <input type="hidden" name="action" value="role">
                            <input type="hidden" name="id" value="<?= e($r['id']) ?>">
                            <select name="role">
                                <option value="customer" <?= $r['role'] === 'customer' ? 'selected' : '' ?>>Customer</option>
                                <option value="admin" <?= $r['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                            <button class="btn">Save</button>
                        </form>
                        <?php else: ?>
                        <span class="muted">Current user</span>
                        <?php endif ?>
                    </td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>

<?php admin_foot(); ?>
