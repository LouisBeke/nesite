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
$isLinode = linode_product_provider($p) === 'linode';
$linodeImages=[];$linodeImageError='';
if($isLinode){
   try{$linodeImages=linode_public_images();}catch(Throwable $imageError){$linodeImageError=$imageError->getMessage();}
   $defaultImage=trim((string)($p['linode_image']??''));
   if($defaultImage!==''&&!isset($linodeImages[$defaultImage]))$linodeImages[$defaultImage]=$defaultImage;
}
$displayPrice = $isDeviceRepair ? 0.00 : (float)$p['price_monthly'];
$eggQ = db()->prepare("SELECT * FROM product_eggs WHERE product_id=? AND enabled=1 ORDER BY is_default DESC,sort_order,id");
$eggQ->execute([(int)$p['id']]);
$allowedEggs = $eggQ->fetchAll();
if (!$allowedEggs && !empty($p['ptero_egg_id'])) $allowedEggs = [['egg_id' => (int)$p['ptero_egg_id'], 'display_name' => null, 'is_default' => 1]];
$allowedEggLabels = [];
foreach ($allowedEggs as &$allowedEgg) {
   $customerLabel = trim((string)($allowedEgg['display_name'] ?? ''));
   if ($customerLabel === '') $customerLabel = trim((string)$p['name']).' software';
   if ($customerLabel === ' software') $customerLabel = 'Server software';
   $allowedEgg['customer_label'] = $customerLabel;
   $allowedEggLabels[(int)$allowedEgg['egg_id']] = $customerLabel;
}
unset($allowedEgg);
$varQ = db()->prepare('SELECT * FROM product_egg_variables WHERE product_id=? ORDER BY egg_id,sort_order,id');
$varQ->execute([(int)$p['id']]);
$varsByEgg = [];
foreach ($varQ->fetchAll() as $v) $varsByEgg[(int)$v['egg_id']][(string)$v['env_variable']] = $v;
if (!$isDeviceRepair && !$isLinode) {
   foreach ($allowedEggs as $allowedEgg) {
      $allowedEggId = (int)$allowedEgg['egg_id'];
      try {
         $savedFields = $varsByEgg[$allowedEggId] ?? [];
         $varsByEgg[$allowedEggId] = [];
         foreach (app_ptero_egg_customer_fields($allowedEggId) as $key => $liveField) {
            $savedField = $savedFields[$key] ?? [];
            $merged = array_merge($liveField, $savedField);
            $merged['customer_visible'] = 1;
            $merged['customer_editable'] = 1;
            $merged['required'] = !empty($liveField['required']) || !empty($savedField['required']) ? 1 : 0;
            if (trim((string)($merged['display_name'] ?? '')) === '') $merged['display_name'] = $liveField['display_name'];
            if (trim((string)($merged['description'] ?? '')) === '') $merged['description'] = $liveField['description'];
            $varsByEgg[$allowedEggId][$key] = $merged;
         }
      } catch (Throwable $eggVariableError) {
         error_log('FoxNetwork checkout Egg variable load failed for Egg '.$allowedEggId.': '.$eggVariableError->getMessage());
         break;
      }
   }
}
foreach ($varsByEgg as &$eggFields) {
   uasort($eggFields, fn($left, $right) => ((int)($left['sort_order'] ?? 0) <=> (int)($right['sort_order'] ?? 0)) ?: strcmp((string)$left['env_variable'], (string)$right['env_variable']));
   $eggFields = array_values($eggFields);
}
unset($eggFields);
$error = '';
$ticketId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   verify_csrf();
   $name = trim((string)($_POST['server_name'] ?? ''));
   $repairNote = trim((string)($_POST['repair_note'] ?? ''));
   $eggId = (int)($_POST['egg_id'] ?? 0);
   $softwareLabel = '';
   $allowedIds = array_map(fn($x) => (int)$x['egg_id'], $allowedEggs);
   $customerEnv = [];
   $sshPublicKey = trim((string)($_POST['ssh_public_key'] ?? ''));
   $selectedLinodeImage=trim((string)($_POST['linode_image']??$p['linode_image']??''));
   if ($name === '') $error = $isDeviceRepair ? 'Enter a device name or model.' : 'Choose a server name.';
   if ($error === '' && !$isDeviceRepair && !$isLinode) {
      if (!$eggId || !in_array($eggId, $allowedIds, true)) $error = 'Choose valid server software.';
      else {
         $softwareLabel = (string)($allowedEggLabels[$eggId] ?? 'Server software');
         foreach (($varsByEgg[$eggId] ?? []) as $v) {
            if (empty($v['customer_editable'])) continue;
            $key = (string)$v['env_variable'];
            $val = trim((string)(($_POST['env'][$key] ?? $v['default_value'] ?? '')));
            $opts = json_decode((string)($v['options_json'] ?? '[]'), true) ?: [];
            if ($v['input_type'] === 'select' && $opts && !in_array($val, $opts, true)) {
               $error = 'Choose a valid ' . ($v['display_name'] ?: $key) . '.';
               break;
            }
            if ($v['input_type'] === 'number' && $val !== '' && !is_numeric($val)) {
               $error = ($v['display_name'] ?: $key) . ' must be a number.';
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
   if ($error === '' && $isLinode) {
      try {
         if($selectedLinodeImage===''||!isset($linodeImages[$selectedLinodeImage]))throw new RuntimeException('Choose a valid operating system image.');
         $selectedProduct=$p;$selectedProduct['linode_image']=$selectedLinodeImage;
         linode_validate_product($selectedProduct);
         $sshPublicKey = linode_validate_ssh_key($sshPublicKey);
      } catch (Throwable $x) {
         $error = 'VPS configuration failed: '.$x->getMessage();
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
         } elseif ($isLinode) {
            $cfg = json_encode(['server_name'=>$name,'provisioning_provider'=>'linode','ssh_public_key'=>$sshPublicKey,'linode_type'=>(string)$p['linode_type'],'linode_region'=>(string)$p['linode_region'],'linode_image'=>$selectedLinodeImage,'linode_image_label'=>(string)($linodeImages[$selectedLinodeImage]??$selectedLinodeImage),'ram_mb'=>(int)$p['ram_mb'],'disk_mb'=>(int)$p['disk_mb'],'cpu_percent'=>(int)$p['cpu_percent']], JSON_UNESCAPED_SLASHES);
         } else {
            $cfg = json_encode(['server_name' => $name, 'egg_id' => $eggId, 'software_label' => $softwareLabel, 'environment' => $customerEnv, 'customer_environment_keys' => array_keys($customerEnv), 'ram_mb' => (int)$p['ram_mb'], 'disk_mb' => (int)$p['disk_mb'], 'cpu_percent' => (int)$p['cpu_percent']], JSON_UNESCAPED_SLASHES);
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
         zoho_crm_try_sync_order($oid);
         if ($ticketId > 0) zoho_crm_try_sync_ticket($ticketId);
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
   <link rel="stylesheet" href="/assets/portal.css?v=<?=rawurlencode((string)@filemtime(__DIR__.'/assets/portal.css'))?>">
</head>

<body class="portal-page">
   <div class="app">
      <?php render_client_sidebar($u, 'store'); ?>
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
            <div class="checkout-steps"><span class="active"><?= $isDeviceRepair ? '1 Intake' : ($isLinode ? '1 VPS' : '1 Software') ?></span><span><?= $isDeviceRepair ? '2 Details' : ($isLinode ? '2 Access' : '2 Options') ?></span><span>3 Summary</span><span>4 Payment</span></div>
            <div class="checkout-grid">
               <section class="card form-card">
                  <div class="cardhead"><b><?= $isDeviceRepair ? 'DEVICE REPAIR INTAKE' : 'SERVER CONFIGURATION' ?></b></div>
                  <form method="post" class="order-form" id="configForm"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="product" value="<?= e($p['slug']) ?>">
                     <div class="field"><label><?= $isDeviceRepair ? 'Device name / model' : ($isLinode ? 'VPS hostname' : 'Server name') ?></label><input name="server_name" maxlength="60" required value="<?= e($_POST['server_name'] ?? '') ?>" placeholder="<?= $isDeviceRepair ? 'e.g. iPhone 13 Pro' : ($isLinode ? 'my-vps' : 'My server') ?>"></div><?php if ($isDeviceRepair): ?><div class="field"><label>Issue details</label><textarea name="repair_note" rows="5" placeholder="Describe the issue, damage, and anything we should know."><?= e($_POST['repair_note'] ?? '') ?></textarea></div><?php elseif ($isLinode): ?><div class="field"><label>SSH public key <span class="muted">(optional)</span></label><textarea name="ssh_public_key" rows="4" placeholder="ssh-ed25519 AAAA... you@example.com"><?=e($_POST['ssh_public_key']??'')?></textarea><div class="muted small">Add your public key for passwordless root access. A strong root password is generated automatically.</div></div><div class="field"><label>Operating system</label><input value="<?=e($p['linode_image'])?>" disabled></div><div class="field"><label>Region</label><input value="<?=e($p['linode_region'])?>" disabled></div><?php else: ?><div class="field"><label>Server software</label><select name="egg_id" id="eggSelect" required>
                              <option value="">— Choose software —</option><?php foreach ($allowedEggs as $ae): $label = (string)$ae['customer_label'];
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    $sel = (int)($_POST['egg_id'] ?? 0) === (int)$ae['egg_id'] || (!isset($_POST['egg_id']) && !empty($ae['is_default'])); ?><option value="<?= e($ae['egg_id']) ?>" <?= $sel ? 'selected' : '' ?>><?= e($label) ?><?= !empty($ae['is_default']) ? ' — Recommended' : '' ?></option><?php endforeach ?>
                            </select></div><?php endif ?>
                     <?php if($isLinode):?><div class="field customer-os-field"><label>Operating system image</label><select name="linode_image" required><option value="">— Choose operating system —</option><?php foreach($linodeImages as $imageId=>$imageLabel):$imageSelected=(string)($_POST['linode_image']??$p['linode_image']??'')===(string)$imageId;?><option value="<?=e($imageId)?>" <?=$imageSelected?'selected':''?>><?=e($imageLabel)?> (<?=e($imageId)?>)</option><?php endforeach?></select><div class="muted small">Choose the operating system that will be installed on your VPS.</div><?php if($linodeImageError):?><div class="muted small">The live image catalog is temporarily unavailable; the product default remains available.</div><?php endif?></div><script>document.querySelectorAll('#configForm .field').forEach(field=>{const label=field.querySelector('label');if(label&&label.textContent.trim()==='Operating system'&&field.querySelector('input[disabled]'))field.remove();});</script><?php endif?>
                     <?php if (!$isDeviceRepair && !$isLinode): ?><?php foreach ($varsByEgg as $eid => $vars): ?><div class="egg-options" data-egg="<?= e($eid) ?>" style="display:none">
                        <h3>Software options</h3><?php foreach ($vars as $v): if (empty($v['customer_visible'])) continue;
                                                         $key = $v['env_variable'];
                                                         $label = $v['display_name'] ?: $key;
                                                         $value = $_POST['env'][$key] ?? $v['default_value'] ?? '';
                                                         $opts = json_decode((string)($v['options_json'] ?? '[]'), true) ?: []; ?><div class="field"><label><?= e($label) ?><?= !empty($v['required']) ? ' *' : '' ?></label><?php if (empty($v['customer_editable'])): ?><input value="<?= e($value) ?>" disabled>
                                 <div class="muted small">Fixed by FoxNetwork</div><?php elseif ($v['input_type'] === 'select' && $opts): ?><select name="env[<?= e($key) ?>]" <?= !empty($v['required']) ? 'required' : '' ?>><?php foreach ($opts as $o): ?><option value="<?= e($o) ?>" <?= $value === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach ?></select><?php else: ?><input type="<?= $v['input_type'] === 'number' ? 'number' : 'text' ?>" name="env[<?= e($key) ?>]" value="<?= e($value) ?>" <?= !empty($v['required']) ? 'required' : '' ?>><?php endif ?><?php if (!empty($v['description'])): ?><div class="muted small"><?= e($v['description']) ?></div><?php endif ?>
                           </div><?php endforeach ?>
                     </div><?php endforeach ?><?php endif ?>
                     <button class="btn primary wide" type="submit">Continue to payment</button>
                     <div class="muted small checkout-note"><?= $isDeviceRepair ? 'A support ticket is created automatically and billing is confirmed later in that ticket.' : ($isLinode ? 'Linode type, region and image availability are checked before the invoice is created.' : 'Stock and node capacity are checked before the invoice is created.') ?></div>
                  </form>
               </section>
               <aside class="card summary">
                  <div class="cardhead"><b>ORDER SUMMARY</b></div>
                  <div class="summary-body">
                     <h3><?= e($p['name']) ?></h3><?php if ($isDeviceRepair): ?><div class="summary-row"><span>Service</span><b>Repair intake ticket</b></div>
                        <div class="summary-row"><span>Workflow</span><b>Order + ticket (invoice after confirmation)</b></div><?php else: ?><div class="summary-row"><span>RAM</span><b><?= e((string)round($p['ram_mb'] / 1024, 1)) ?> GB</b></div>
                        <div class="summary-row"><span>CPU</span><b><?= e($p['cpu_percent']) ?>%</b></div>
                        <div class="summary-row"><span>Storage</span><b><?= e((string)round($p['disk_mb'] / 1000)) ?> GB</b></div><?php if($isLinode):?><div class="summary-row"><span>Provider</span><b>Linode</b></div><div class="summary-row"><span>Plan</span><b><?=e($p['linode_type'])?></b></div><div class="summary-row"><span>Region</span><b><?=e($p['linode_region'])?></b></div><?php endif?><?php endif ?><div class="summary-row"><span>Stock</span><b><?= $p['stock'] === null ? 'Unlimited' : e($p['stock']) ?></b></div>
                     <?php if($isLinode):$summaryImage=(string)($_POST['linode_image']??$p['linode_image']??'');?><div class="summary-row"><span>Operating system</span><b data-linode-image-summary><?=e($linodeImages[$summaryImage]??$summaryImage)?></b></div><?php endif?>
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
         document.querySelectorAll('.egg-options').forEach(group => {
            const active = group.dataset.egg === id;
            group.style.display = active ? 'block' : 'none';
            group.querySelectorAll('input,select,textarea').forEach(control => control.disabled = !active);
         });
      }
      const eggSelect = document.getElementById('eggSelect');
      if (eggSelect) eggSelect.addEventListener('change', showEgg);
      showEgg();
      const linodeImageSelect=document.querySelector('[name="linode_image"]');
      const linodeImageSummary=document.querySelector('[data-linode-image-summary]');
      if(linodeImageSelect&&linodeImageSummary)linodeImageSelect.addEventListener('change',()=>{linodeImageSummary.textContent=linodeImageSelect.selectedOptions[0]?.textContent||'—';});
   </script>
</body>

</html>
