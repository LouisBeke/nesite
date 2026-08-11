<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';

$u = require_admin();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = (string)($_POST['action'] ?? 'mark_paid');

        if ($action === 'create') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $orderId = (int)($_POST['order_id'] ?? 0);
            $invoiceNumber = trim((string)($_POST['invoice_number'] ?? ''));
            $subtotal = (float)($_POST['subtotal'] ?? 0);
            $total = (float)($_POST['total'] ?? 0);
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'EUR')));
            $dueRaw = trim((string)($_POST['due_at'] ?? ''));
            $itemDescription = trim((string)($_POST['item_description'] ?? 'Manual invoice item'));
            $status = (string)($_POST['status'] ?? 'unpaid');

            if ($userId < 1) {
                throw new RuntimeException('Customer is required.');
            }
            if ($invoiceNumber === '') {
                $invoiceNumber = (string)setting('invoice_prefix', 'INV').'-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
            }
            if ($subtotal < 0 || $total < 0) {
                throw new RuntimeException('Amounts cannot be negative.');
            }
            if ($dueRaw === '' || !strtotime($dueRaw)) {
                throw new RuntimeException('Invalid due date.');
            }
            if (strlen($currency) !== 3) {
                throw new RuntimeException('Currency must be a 3-letter code.');
            }
            if (!in_array($status, ['unpaid', 'overdue', 'cancelled'], true)) {
                throw new RuntimeException('Invalid invoice status.');
            }

            $q = db()->prepare('SELECT id FROM users WHERE id=? LIMIT 1');
            $q->execute([$userId]);
            if (!(int)$q->fetchColumn()) {
                throw new RuntimeException('Customer not found.');
            }

            $orderRef = null;
            if ($orderId > 0) {
                $q = db()->prepare('SELECT id,user_id FROM orders WHERE id=? LIMIT 1');
                $q->execute([$orderId]);
                $order = $q->fetch();
                if (!$order) {
                    throw new RuntimeException('Order not found.');
                }
                if ((int)$order['user_id'] !== $userId) {
                    throw new RuntimeException('Selected order does not belong to the selected customer.');
                }
                $orderRef = $orderId;
            }

            if ($itemDescription === '') {
                $itemDescription = 'Manual invoice item';
            }

            $dueAt = date('Y-m-d H:i:s', strtotime($dueRaw));
            db()->beginTransaction();
            try {
                $q = db()->prepare('INSERT INTO invoices(user_id,order_id,invoice_number,status,subtotal,total,currency,due_at) VALUES(?,?,?,?,?,?,?,?)');
                $q->execute([$userId, $orderRef, $invoiceNumber, $status, $subtotal, $total, $currency, $dueAt]);
                $newId = (int)db()->lastInsertId();
                db()->prepare('INSERT INTO invoice_items(invoice_id,description,amount,quantity) VALUES(?,?,?,1)')->execute([$newId, $itemDescription, $total]);
                db()->commit();
            } catch (Throwable $txe) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $txe;
            }

            $msg = 'Invoice created.';
        } elseif ($action === 'save') {
            $iid = (int)($_POST['invoice_id'] ?? 0);
            if ($iid < 1) {
                throw new RuntimeException('Invoice not found.');
            }
            $q = db()->prepare('SELECT * FROM invoices WHERE id=? LIMIT 1');
            $q->execute([$iid]);
            $inv = $q->fetch();
            if (!$inv) {
                throw new RuntimeException('Invoice not found.');
            }
            if (($inv['status'] ?? '') === 'paid') {
                throw new RuntimeException('Paid invoices cannot be edited.');
            }

            $invoiceNumber = trim((string)($_POST['invoice_number'] ?? ''));
            $subtotal = (float)($_POST['subtotal'] ?? 0);
            $total = (float)($_POST['total'] ?? 0);
            $dueRaw = trim((string)($_POST['due_at'] ?? ''));
            $status = (string)($_POST['status'] ?? 'unpaid');

            if ($invoiceNumber === '') {
                throw new RuntimeException('Invoice number is required.');
            }
            if ($subtotal < 0 || $total < 0) {
                throw new RuntimeException('Amounts cannot be negative.');
            }
            if ($dueRaw === '' || !strtotime($dueRaw)) {
                throw new RuntimeException('Invalid due date.');
            }
            if (!in_array($status, ['unpaid', 'overdue', 'cancelled'], true)) {
                throw new RuntimeException('Invalid invoice status.');
            }

            $dueAt = date('Y-m-d H:i:s', strtotime($dueRaw));
            $save = db()->prepare('UPDATE invoices SET invoice_number=?, subtotal=?, total=?, due_at=?, status=? WHERE id=? AND status<>\'paid\'');
            $save->execute([$invoiceNumber, $subtotal, $total, $dueAt, $status, $iid]);

            $msg = 'Invoice updated.';
        } elseif ($action === 'mark_paid') {
            $iid = (int)($_POST['invoice_id'] ?? 0);
            if ($iid < 1) {
                throw new RuntimeException('Invoice not found.');
            }
            mark_invoice_paid($iid, 'manual', 'ADMIN');

            $q = db()->prepare('SELECT order_id FROM invoices WHERE id=?');
            $q->execute([$iid]);
            $oid = (int)$q->fetchColumn();
            if ($oid) {
                $sid = oxxa_order_is_domain_only($oid) ? 0 : ensure_service_for_order($oid);
                if (($_POST['provision'] ?? '') === '1') {
                    provisioning_dispatch_order($oid, ['source' => 'admin_billing', 'invoice_id' => $iid, 'service_id' => $sid]);
                }
            }

            $msg = 'Invoice marked paid'.((($_POST['provision'] ?? '') === '1') ? ' and queued for provisioning.' : '.');
        } else {
            throw new RuntimeException('Unknown billing action.');
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$rows = db()->query("SELECT i.*,u.name customer_name,u.email,o.order_number FROM invoices i JOIN users u ON u.id=i.user_id LEFT JOIN orders o ON o.id=i.order_id ORDER BY i.id DESC")->fetchAll();
$customers = db()->query("SELECT id,name,email FROM users WHERE role='customer' ORDER BY name")->fetchAll();

admin_head($u, 'Billing', 'billing');
?>
<?php if ($msg): ?><div class="notice"><?=e($msg)?></div><?php endif ?>
<?php if ($err): ?><div class="error"><?=e($err)?></div><?php endif ?>

<section class="card" style="margin-bottom:16px">
    <div class="cardhead">
        <b>CREATE INVOICE</b>
        <span class="muted">Manual billing</span>
    </div>
    <form method="post" class="admin-form-grid" style="padding:14px;gap:10px">
        <input type="hidden" name="csrf" value="<?=e(csrf())?>">
        <input type="hidden" name="action" value="create">

        <label>Customer
            <select name="user_id" required>
                <option value="">Select customer</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?=e($c['id'])?>"><?=e($c['name'])?> (<?=e($c['email'])?>)</option>
                <?php endforeach ?>
            </select>
        </label>
        <label>Order ID (optional)
            <input type="number" min="1" name="order_id" placeholder="e.g. 125">
        </label>
        <label>Invoice number (optional)
            <input name="invoice_number" maxlength="32" placeholder="Auto generated if empty">
        </label>
        <label>Subtotal
            <input type="number" step="0.01" min="0" name="subtotal" value="0.00" required>
        </label>
        <label>Total
            <input type="number" step="0.01" min="0" name="total" value="0.00" required>
        </label>
        <label>Currency
            <input name="currency" maxlength="3" value="EUR" required>
        </label>
        <label>Due
            <input type="datetime-local" name="due_at" value="<?=e(date('Y-m-d\\TH:i', strtotime('+7 days')) )?>" required>
        </label>
        <label>Status
            <select name="status">
                <option value="unpaid">Unpaid</option>
                <option value="overdue">Overdue</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </label>
        <label class="fullfield">Line item description
            <input name="item_description" maxlength="255" value="Manual invoice item" required>
        </label>
        <div>
            <button class="btn primary">Create invoice</button>
        </div>
    </form>
</section>

<section class="card">
    <div class="cardhead">
        <b>INVOICES</b>
        <span class="muted"><?=count($rows)?> invoices</span>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th>Invoice</th>
                <th>Customer</th>
                <th>Order</th>
                <th>Total</th>
                <th>Due</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <b><?=e($r['invoice_number'])?></b>
                        <small>#<?=e($r['id'])?></small>
                    </td>
                    <td><?=e($r['customer_name'])?><small><?=e($r['email'])?></small></td>
                    <td><?=e($r['order_number'] ?? '—')?></td>
                    <td>€<?=number_format((float)$r['total'], 2)?></td>
                    <td><?=e(date('d M Y', strtotime($r['due_at'])))?></td>
                    <td><?=admin_badge($r['status'])?></td>
                    <td>
                        <?php if ($r['status'] !== 'paid'): ?>
                            <form method="post" class="inline-form" style="margin-bottom:8px">
                                <input type="hidden" name="csrf" value="<?=e(csrf())?>">
                                <input type="hidden" name="invoice_id" value="<?=e($r['id'])?>">
                                <input type="hidden" name="action" value="mark_paid">
                                <button class="btn primary">Mark paid</button>
                                <button class="btn" name="provision" value="1">Paid + provision</button>
                            </form>

                            <form method="post" class="admin-form-grid" style="gap:8px;min-width:300px">
                                <input type="hidden" name="csrf" value="<?=e(csrf())?>">
                                <input type="hidden" name="invoice_id" value="<?=e($r['id'])?>">
                                <input type="hidden" name="action" value="save">

                                <label>Number
                                    <input name="invoice_number" value="<?=e($r['invoice_number'])?>" maxlength="32" required>
                                </label>
                                <label>Subtotal
                                    <input type="number" step="0.01" min="0" name="subtotal" value="<?=e(number_format((float)$r['subtotal'], 2, '.', ''))?>" required>
                                </label>
                                <label>Total
                                    <input type="number" step="0.01" min="0" name="total" value="<?=e(number_format((float)$r['total'], 2, '.', ''))?>" required>
                                </label>
                                <label>Due
                                    <input type="datetime-local" name="due_at" value="<?=e(date('Y-m-d\\TH:i', strtotime($r['due_at'])))?>" required>
                                </label>
                                <label>Status
                                    <select name="status">
                                        <?php foreach (['unpaid', 'overdue', 'cancelled'] as $st): ?>
                                            <option value="<?=e($st)?>" <?=$r['status'] === $st ? 'selected' : ''?>><?=e(ucfirst($st))?></option>
                                        <?php endforeach ?>
                                    </select>
                                </label>
                                <div>
                                    <button class="btn">Save changes</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <span class="muted small">Paid <?=e($r['paid_at'] ?? '')?></span>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>

<?php admin_foot(); ?>
