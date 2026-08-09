<?php require __DIR__ . '/app/bootstrap.php';
$u = require_user();
$slug = (string)($_GET['product'] ?? $_POST['product'] ?? '');
$s = db()->prepare("SELECT p.*,c.name category_name FROM store_products p JOIN store_categories c ON c.id=p.category_id WHERE p.slug=? AND p.active=1");
$s->execute([$slug]);
$p = $s->fetch();
if (!$p) {
   http_response_code(404);
   die('Product not found');
}
if ($p['stock'] !== null && (int)$p['stock'] <= 0) {
   http_response_code(409);
   die('This product is currently out of stock.');
}
$isDeviceRepair = ((string)($p['slug'] ?? '') === 'device-repair');
$displayPrice = $isDeviceRepair ? 0.00 : (float)$p['price_monthly'];
$eggQ = db()->prepare("SELECT * FROM product_eggs WHERE product_id=? AND enabled=1 ORDER BY is_default DESC,sort_order,id");
$eggQ->execute([(int)$p['id']]);
$allowedEggs = $eggQ->fetchAll();
if (!$allowedEggs && !empty($p['ptero_egg_id'])) $allowedEggs = [['egg_id' => (int)$p['ptero_egg_id'], 'display_name' => null, 'is_default' => 1]];
$varQ = db()->prepare('SELECT * FROM product_egg_variables WHERE product_id=? ORDER BY egg_id,sort_order,id');
$varQ->execute([(int)$p['id']]);
$varsByEgg = [];
foreach ($varQ->fetchAll() as $v) $varsByEgg[(int)$v['egg_id']][] = $v;
$error = '';
$ticketId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   verify_csrf();
   $name = trim((string)($_POST['server_name'] ?? ''));
   $repairNote = trim((string)($_POST['repair_note'] ?? ''));
   $eggId = (int)($_POST['egg_id'] ?? 0);
   $allowedIds = array_map(fn($x) => (int)$x['egg_id'], $allowedEggs);
   $customerEnv = [];
   if ($name === '') $error = $isDeviceRepair ? 'Enter a device name or model.' : 'Choose a server name.';
   if ($error === '' && !$isDeviceRepair) {
      if (!$eggId || !in_array($eggId, $allowedIds, true)) $error = 'Choose valid server software.';
      else {
         foreach (($varsByEgg[$eggId] ?? []) as $v) {
            if (empty($v['customer_editable'])) continue;
            $key = (string)$v['env_variable'];
            $val = trim((string)(($_POST['env'][$key] ?? $v['default_value'] ?? '')));
            $opts = json_decode((string)($v['options_json'] ?? '[]'), true) ?: [];
            if ($v['input_type'] === 'select' && $opts && !in_array($val, $opts, true)) {
               $error = 'Choose a valid ' . ($v['display_name'] ?: $key) . '.';
               break;
            }
            if (!empty($v['required']) && $val === '') {
               $error = ($v['display_name'] ?: $key) . ' is required.';
               break;
            }
            $customerEnv[$key] = $val;
         }
         if ($error === '') { // Preflight capacity before accepting a hosting order.
            try {
               if (!empty($p['ptero_node_id'])) {
                  $ar = app_ptero('/nodes/' . (int)$p['ptero_node_id'] . '/allocations?per_page=100');
                  $free = false;
                  foreach (($ar['data'] ?? []) as $row) if (empty(($row['attributes'] ?? [])['assigned'])) {
                     $free = true;
                     break;
                  }
                  if (!$free) throw new RuntimeException('The selected hosting node currently has no free allocations.');
               }
            } catch (Throwable $x) {
               $error = 'Capacity check failed: ' . $x->getMessage();
            }
         }
      }
   }
   if ($error === '') {
      db()->beginTransaction();
      try {
         $num = 'FN-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
         $orderAmount = $isDeviceRepair ? 0.00 : (float)$p['price_monthly'];
         $initialStatus = $orderAmount <= 0 ? 'paid' : 'pending';
         $q = db()->prepare("INSERT INTO orders(user_id,order_number,status,subtotal,total,currency) VALUES(?,?,?,?,?,'EUR')");
         $q->execute([$u['id'], $num, $initialStatus, $orderAmount, $orderAmount]);
         $oid = (int)db()->lastInsertId();
         if ($isDeviceRepair) {
            $cfg = json_encode(['service_type' => 'device_repair', 'device_name' => $name, 'repair_note' => $repairNote, 'intake_required' => true], JSON_UNESCAPED_SLASHES);
         } else {
            $cfg = json_encode(['server_name' => $name, 'egg_id' => $eggId, 'environment' => $customerEnv, 'ram_mb' => (int)$p['ram_mb'], 'disk_mb' => (int)$p['disk_mb'], 'cpu_percent' => (int)$p['cpu_percent']], JSON_UNESCAPED_SLASHES);
         }
         $q = db()->prepare("INSERT INTO order_items(order_id,product_id,product_name,unit_price,quantity,config_json) VALUES(?,?,?,?,1,?)");
         $q->execute([$oid, $p['id'], $p['name'], $orderAmount, $cfg]);
         if ($isDeviceRepair) {
            $subject = 'Device Repair Order ' . $num;
            $message = "A new device repair order was placed.\n\nOrder: " . $num . "\nProduct: " . $p['name'] . "\nDevice: " . $name;
            if ($repairNote !== '') $message .= "\n\nIssue details:\n" . $repairNote;
            $q = db()->prepare("INSERT INTO support_tickets(user_id,service_id,subject,category,priority,status) VALUES(?,?,?,?,?,'awaiting_staff')");
            $q->execute([(int)$u['id'], null, $subject, 'technical', 'high']);
            $ticketId = (int)db()->lastInsertId();
            db()->prepare('INSERT INTO support_messages(ticket_id,user_id,message) VALUES(?,?,?)')->execute([$ticketId, (int)$u['id'], $message]);
         }
         db()->commit();
         if ($isDeviceRepair) {
            header('Location: /support.php?id=' . (int)$ticketId);
            exit;
         }
         if ($orderAmount > 0) {
            invoice_for_order($oid);
            header('Location: /billing.php?created=' . urlencode($num));
            exit;
         }
         try {
            provisioning_dispatch_order($oid, ['source' => 'free_checkout']);
         } catch (Throwable $provisioningError) {
            error_log('FoxNetwork free-order provisioning dispatch failed for order '.$oid.': '.$provisioningError->getMessage());
         }
         header('Location: /orders.php?created=' . urlencode($num));
         exit;
      } catch (Throwable $e) {
         db()->rollBack();
         $error = 'Could not create order: ' . $e->getMessage();
      }
   }
}
?>
<!doctype html>
<html>

<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width,initial-scale=1">
   <title>FoxNetwork | Configure Order</title>
   <link rel="stylesheet" href="/css/fontawesome-all.min.css">
   <link rel="stylesheet" href="/assets/portal.css?v=13">
</head>

<body>
   <div class="app">
      <aside class="side">
         <div class="brand"><img src="/images/logo.png"><span>FOX<b>NETWORK</b></span></div>
         <nav class="nav"><a href="/client">Overview</a><a class="active" href="/store.php">Store</a><a href="/orders.php">Orders</a><a href="/billing.php">Billing</a><a href="/support.php">Support</a></nav>
         <nav class="nav bottom"><?php if (($u['role'] ?? '') === 'admin'): ?><a href="/admin/"><span>Admin</span></a><?php endif ?><a href="/settings.php">Account Settings</a><a href="/logout.php">Sign out</a></nav>
      </aside>
      <main class="main">
         <header>
            <div class="profile">
               <div><b><?= e($u['name']) ?></b>
                  <div class="muted" style="font-size:12px"><?= e(ucfirst($u['role'])) ?></div>
               </div>
               <div class="avatar"><?= e(strtoupper(substr($u['name'], 0, 1))) ?></div>
            </div>
         </header>
         <div class="content">
            <div class="eyebrow">Advanced Configurator</div>
            <h1><?= e($p['name']) ?></h1>
            <div class="muted"><?= e($p['category_name']) ?> · €<?= number_format($displayPrice, 2) ?><?= $isDeviceRepair ? '' : ' / month' ?></div><?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif ?>
            <div class="checkout-steps"><span class="active"><?= $isDeviceRepair ? '1 Intake' : '1 Software' ?></span><span><?= $isDeviceRepair ? '2 Details' : '2 Options' ?></span><span>3 Summary</span><span>4 Payment</span></div>
            <div class="checkout-grid">
               <section class="card form-card">
                  <div class="cardhead"><b><?= $isDeviceRepair ? 'DEVICE REPAIR INTAKE' : 'SERVER CONFIGURATION' ?></b></div>
                  <form method="post" class="order-form" id="configForm"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="product" value="<?= e($p['slug']) ?>">
                     <div class="field"><label><?= $isDeviceRepair ? 'Device name / model' : 'Server name' ?></label><input name="server_name" maxlength="60" required value="<?= e($_POST['server_name'] ?? '') ?>" placeholder="<?= $isDeviceRepair ? 'e.g. iPhone 13 Pro' : 'My server' ?>"></div><?php if ($isDeviceRepair): ?><div class="field"><label>Issue details</label><textarea name="repair_note" rows="5" placeholder="Describe the issue, damage, and anything we should know."><?= e($_POST['repair_note'] ?? '') ?></textarea></div><?php else: ?><div class="field"><label>Server software</label><select name="egg_id" id="eggSelect" required>
                              <option value="">— Choose software —</option><?php foreach ($allowedEggs as $ae): $label = trim((string)($ae['display_name'] ?? ''));
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    if ($label === '') $label = 'Egg #' . (int)$ae['egg_id'];
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    $sel = (int)($_POST['egg_id'] ?? 0) === (int)$ae['egg_id'] || (!isset($_POST['egg_id']) && !empty($ae['is_default'])); ?><option value="<?= e($ae['egg_id']) ?>" <?= $sel ? 'selected' : '' ?>><?= e($label) ?><?= !empty($ae['is_default']) ? ' — Recommended' : '' ?></option><?php endforeach ?>
                           </select></div><?php endif ?>
                     <?php if (!$isDeviceRepair): ?><?php foreach ($varsByEgg as $eid => $vars): ?><div class="egg-options" data-egg="<?= e($eid) ?>" style="display:none">
                        <h3>Software options</h3><?php foreach ($vars as $v): if (empty($v['customer_visible'])) continue;
                                                         $key = $v['env_variable'];
                                                         $label = $v['display_name'] ?: $key;
                                                         $value = $_POST['env'][$key] ?? $v['default_value'] ?? '';
                                                         $opts = json_decode((string)($v['options_json'] ?? '[]'), true) ?: []; ?><div class="field"><label><?= e($label) ?><?= !empty($v['required']) ? ' *' : '' ?></label><?php if (empty($v['customer_editable'])): ?><input value="<?= e($value) ?>" disabled>
                                 <div class="muted small">Fixed by FoxNetwork</div><?php elseif ($v['input_type'] === 'select' && $opts): ?><select name="env[<?= e($key) ?>]" <?= !empty($v['required']) ? 'required' : '' ?>><?php foreach ($opts as $o): ?><option value="<?= e($o) ?>" <?= $value === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach ?></select><?php else: ?><input type="<?= $v['input_type'] === 'number' ? 'number' : 'text' ?>" name="env[<?= e($key) ?>]" value="<?= e($value) ?>" <?= !empty($v['required']) ? 'required' : '' ?>><?php endif ?><?php if (!empty($v['description'])): ?><div class="muted small"><?= e($v['description']) ?></div><?php endif ?>
                           </div><?php endforeach ?>
                     </div><?php endforeach ?><?php endif ?>
                     <button class="btn primary wide" type="submit">Continue to payment</button>
                     <div class="muted small checkout-note"><?= $isDeviceRepair ? 'A support ticket is created automatically and billing is confirmed later in that ticket.' : 'Stock and node capacity are checked before the invoice is created.' ?></div>
                  </form>
               </section>
               <aside class="card summary">
                  <div class="cardhead"><b>ORDER SUMMARY</b></div>
                  <div class="summary-body">
                     <h3><?= e($p['name']) ?></h3><?php if ($isDeviceRepair): ?><div class="summary-row"><span>Service</span><b>Repair intake ticket</b></div>
                        <div class="summary-row"><span>Workflow</span><b>Order + ticket (invoice after confirmation)</b></div><?php else: ?><div class="summary-row"><span>RAM</span><b><?= e((string)round($p['ram_mb'] / 1024, 1)) ?> GB</b></div>
                        <div class="summary-row"><span>CPU</span><b><?= e($p['cpu_percent']) ?>%</b></div>
                        <div class="summary-row"><span>Storage</span><b><?= e((string)round($p['disk_mb'] / 1000)) ?> GB</b></div><?php endif ?><div class="summary-row"><span>Stock</span><b><?= $p['stock'] === null ? 'Unlimited' : e($p['stock']) ?></b></div>
                     <div class="summary-total"><span>Total</span><strong>€<?= number_format($displayPrice, 2) ?><small><?= $isDeviceRepair ? '' : '/mo' ?></small></strong></div>
                  </div>
               </aside>
            </div>
         </div>
      </main>
   </div>
   <script>
      function showEgg() {
         const sel = document.getElementById('eggSelect');
         if (!sel) return;
         const id = sel.value;
         document.querySelectorAll('.egg-options').forEach(x => x.style.display = x.dataset.egg === id ? 'block' : 'none')
      }
      const eggSelect = document.getElementById('eggSelect');
      if (eggSelect) eggSelect.addEventListener('change', showEgg);
      showEgg();
   </script>
</body>

</html>
