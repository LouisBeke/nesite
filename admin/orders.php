<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';

$u = require_admin();
$msg = '';
$err = '';

$allowed = ['pending', 'awaiting_payment', 'paid', 'provisioning', 'active', 'cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Order not found.');
        }
        $st = (string)($_POST['status'] ?? '');
        if (!in_array($st, $allowed, true)) {
            throw new RuntimeException('Invalid order status.');
        }
        $q = db()->prepare('UPDATE orders SET status=? WHERE id=?');
        $q->execute([$st, $id]);
        $msg = 'Order updated.';
    } catch (Throwable $x) {
        $err = $x->getMessage();
    }
}

$rows = db()->query("SELECT o.*,u.name customer_name,u.email,(SELECT GROUP_CONCAT(product_name SEPARATOR ', ') FROM order_items i WHERE i.order_id=o.id) items FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC")->fetchAll();

admin_head($u, 'Orders', 'orders');
?>
<?php if ($msg): ?><div class="notice"><?=e($msg)?></div><?php endif ?>
<?php if ($err): ?><div class="error"><?=e($err)?></div><?php endif ?>

<section class="card">
    <div class="cardhead">
        <b>ALL ORDERS</b>
        <span class="muted"><?=count($rows)?> orders</span>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Product</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Update</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <b><?=e($r['order_number'])?></b>
                            <small>#<?=e($r['id'])?> · <?=e($r['created_at'])?></small>
                        </td>
                        <td>
                            <?=e($r['customer_name'])?>
                            <small><?=e($r['email'])?></small>
                        </td>
                        <td><?=e($r['items'] ?? '—')?></td>
                        <td>€<?=number_format((float)$r['total'], 2)?></td>
                        <td><?=admin_badge($r['status'])?></td>
                        <td>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf" value="<?=e(csrf())?>">
                                <input type="hidden" name="id" value="<?=e($r['id'])?>">
                                <select name="status">
                                    <?php foreach ($allowed as $st): ?>
                                        <option value="<?=e($st)?>" <?=$r['status'] === $st ? 'selected' : ''?>><?=e(ucwords(str_replace('_', ' ', $st)))?></option>
                                    <?php endforeach ?>
                                </select>
                                <button class="btn">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>

<?php admin_foot(); ?>
