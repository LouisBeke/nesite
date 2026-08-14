<?php require __DIR__ . '/app/bootstrap.php';
$settingsPages=['profile','security','sessions'];
$settingsPage=(string)($_GET['page']??'profile');
if(!in_array($settingsPage,$settingsPages,true))$settingsPage='profile';
$u = require_user();
security_touch_session((int)$u['id']);
$ok = '';
$err = '';
$newRecovery = [];
try {
  if (auto_setup_ptero_client_key_for_local_user($u)) $u = user();
} catch (Throwable $e) {
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  try {
    $a = $_POST['action'] ?? 'profile';
    if ($a === 'profile') {
      $profileName = trim((string)($_POST['name'] ?? $u['name']));
      if (mb_strlen($profileName) < 2) throw new RuntimeException('Please enter your full name.');
      $profileFields=[];foreach(['company_name','phone','street','house_number','postal_code','city','state','country_code'] as $field)$profileFields[$field]=trim((string)($_POST[$field]??''));
      $profileFields['country_code']=strtoupper($profileFields['country_code']);
      if($profileFields['country_code']!==''&&!preg_match('/^[A-Z]{2}$/',$profileFields['country_code']))throw new RuntimeException('Country must be a two-letter country code.');
      $notifyWhatsapp=isset($_POST['notify_whatsapp'])?1:0;
      if($notifyWhatsapp&&!preg_match('/^\+[1-9]\d{7,14}$/',preg_replace('/[\s().-]+/','',$profileFields['phone'])))throw new RuntimeException('WhatsApp requires an international phone number such as +32470123456.');
      db()->prepare('UPDATE users SET name=?,email_notifications=?,notify_whatsapp=?,company_name=?,phone=?,street=?,house_number=?,postal_code=?,city=?,state=?,country_code=? WHERE id=?')->execute([$profileName,isset($_POST['email_notifications'])?1:0,$notifyWhatsapp,$profileFields['company_name']?:null,$profileFields['phone']?:null,$profileFields['street']?:null,$profileFields['house_number']?:null,$profileFields['postal_code']?:null,$profileFields['city']?:null,$profileFields['state']?:null,$profileFields['country_code']?:null,$u['id']]);
      $u['name'] = $profileName;
      $u['email_notifications'] = isset($_POST['email_notifications']) ? 1 : 0;
      foreach($profileFields as $profileKey=>$profileValue)$u[$profileKey]=$profileValue;
      $u['notify_whatsapp']=$notifyWhatsapp;
      try {
        zoho_crm_sync_customer($u);
      } catch (Throwable $crmError) {
        error_log('FoxNetwork profile Zoho CRM sync failed for user ' . (int)$u['id'] . ': ' . $crmError->getMessage());
      }
      $ok = 'Account settings saved.';
    } elseif ($a === 'password') {
      if (!password_verify($_POST['current_password'] ?? '', $u['password_hash'])) throw new RuntimeException('Current password is incorrect.');
      $p = $_POST['new_password'] ?? '';
      if (strlen($p) < 10) throw new RuntimeException('New password must be at least 10 characters.');
      if ($p !== ($_POST['confirm_password'] ?? '')) throw new RuntimeException('New passwords do not match.');
      db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($p, PASSWORD_DEFAULT), $u['id']]);
      db()->prepare('DELETE FROM user_sessions WHERE user_id=? AND session_id<>?')->execute([$u['id'], session_id()]);
      $ok = 'Password changed and other sessions signed out.';
    } elseif ($a === '2fa_begin') {
      $secret = b32encode(random_bytes(20));
      $_SESSION['2fa_setup_secret'] = $secret;
      unset($_SESSION['2fa_channel_setup']);
      $ok = 'Secret generated. Add it to your authenticator app, then verify a code below.';
    } elseif ($a === '2fa_channel_begin') {
      $method=(string)($_POST['method']??'');if($method!=='whatsapp')throw new RuntimeException('Choose WhatsApp.');
      unset($_SESSION['2fa_setup_secret']);messaging_verify_start((string)($u['phone']??''));$_SESSION['2fa_channel_setup']=$method;$ok='A verification code was sent by WhatsApp.';
    } elseif ($a === '2fa_channel_enable') {
      $method=(string)($_SESSION['2fa_channel_setup']??'');if($method!=='whatsapp'||!messaging_verify_check((string)($u['phone']??''),(string)($_POST['code']??'')))throw new RuntimeException('Invalid or expired verification code.');
      for($i=0;$i<10;$i++)$newRecovery[]=strtoupper(bin2hex(random_bytes(4)));
      db()->prepare('UPDATE users SET two_factor_enabled=1,two_factor_method=?,two_factor_secret=NULL,two_factor_recovery_codes=? WHERE id=?')->execute([$method,enc(json_encode($newRecovery)),$u['id']]);unset($_SESSION['2fa_channel_setup']);$ok=ucfirst($method).' two-factor authentication enabled. Save your recovery codes now.';
    } elseif ($a === '2fa_enable') {
      $secret = $_SESSION['2fa_setup_secret'] ?? '';
      if (!$secret || !totp_verify($secret, $_POST['code'] ?? '')) throw new RuntimeException('Invalid authenticator code.');
      for ($i = 0; $i < 10; $i++) $newRecovery[] = strtoupper(bin2hex(random_bytes(4)));
      db()->prepare('UPDATE users SET two_factor_secret=?,two_factor_enabled=1,two_factor_recovery_codes=? WHERE id=?')->execute([enc($secret), enc(json_encode($newRecovery)), $u['id']]);
      db()->prepare("UPDATE users SET two_factor_method='totp' WHERE id=?")->execute([$u['id']]);
      unset($_SESSION['2fa_setup_secret']);
      unset($_SESSION['2fa_channel_setup']);
      $ok = 'Two-factor authentication enabled. Save your recovery codes now.';
    } elseif ($a === '2fa_cancel') {
      unset($_SESSION['2fa_setup_secret']);
      unset($_SESSION['2fa_channel_setup']);
      $ok = 'Two-factor setup cancelled.';
    } elseif ($a === '2fa_regenerate') {
      if ((int)($u['two_factor_enabled'] ?? 0) !== 1) throw new RuntimeException('Two-factor authentication is not enabled.');
      if (!password_verify($_POST['current_password'] ?? '', $u['password_hash'])) throw new RuntimeException('Current password is incorrect.');
      $method=(string)($u['two_factor_method']??'totp');$secret=(string)(dec($u['two_factor_secret']??null)??'');
      if ($method==='totp'&&($secret==='' || !totp_verify($secret, (string)($_POST['code'] ?? '')))) throw new RuntimeException('Enter a valid 6-digit authenticator code.');
      if ($method!=='totp'){ $codes=json_decode((string)(dec($u['two_factor_recovery_codes']??null)??'[]'),true)?:[];if(!in_array(strtoupper(trim((string)($_POST['code']??''))),$codes,true))throw new RuntimeException('Enter a valid recovery code.'); }
      for ($i = 0; $i < 10; $i++) $newRecovery[] = strtoupper(bin2hex(random_bytes(4)));
      db()->prepare('UPDATE users SET two_factor_recovery_codes=? WHERE id=?')->execute([enc(json_encode($newRecovery)), $u['id']]);
      $ok = 'New recovery codes generated. Your previous recovery codes no longer work.';
    } elseif ($a === '2fa_disable') {
      if (!password_verify($_POST['current_password'] ?? '', $u['password_hash'])) throw new RuntimeException('Current password is incorrect.');
      $method=(string)($u['two_factor_method']??'totp');$secret=(string)(dec($u['two_factor_secret']??null)??'');
      if ($method==='totp'&&($secret==='' || !totp_verify($secret, (string)($_POST['code'] ?? '')))) throw new RuntimeException('Enter a valid 6-digit authenticator code.');
      if ($method!=='totp'){ $codes=json_decode((string)(dec($u['two_factor_recovery_codes']??null)??'[]'),true)?:[];if(!in_array(strtoupper(trim((string)($_POST['code']??''))),$codes,true))throw new RuntimeException('Enter a valid recovery code.'); }
      db()->prepare('UPDATE users SET two_factor_secret=NULL,two_factor_enabled=0,two_factor_recovery_codes=NULL WHERE id=?')->execute([$u['id']]);
      unset($_SESSION['2fa_setup_secret']);
      $ok = 'Two-factor authentication disabled.';
    } elseif ($a === 'sessions') {
      db()->prepare('DELETE FROM user_sessions WHERE user_id=? AND session_id<>?')->execute([$u['id'], session_id()]);
      $ok = 'Other sessions signed out.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
  $freshUser=db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$freshUser->execute([(int)$u['id']]);$u=$freshUser->fetch()?:$u;
}
$sessions = [];
$history = [];
try {
  $q = db()->prepare('SELECT * FROM user_sessions WHERE user_id=? ORDER BY last_seen_at DESC');
  $q->execute([$u['id']]);
  $sessions = $q->fetchAll();
} catch (Throwable $e) {
}
try {
  $q = db()->prepare('SELECT * FROM login_history WHERE user_id=? ORDER BY id DESC LIMIT 15');
  $q->execute([$u['id']]);
  $history = $q->fetchAll();
} catch (Throwable $e) {
}
$setup = $_SESSION['2fa_setup_secret'] ?? '';
$channelSetup=(string)($_SESSION['2fa_channel_setup']??'');
$recoveryCodes=[];if(!empty($u['two_factor_recovery_codes'])){$recoveryCodes=json_decode((string)(dec($u['two_factor_recovery_codes'])??'[]'),true)?:[];}
$recoveryCount=count($recoveryCodes);
$holderFields=['name','phone','street','house_number','postal_code','city','state','country_code'];$holderComplete=count(array_filter($holderFields,fn($field)=>trim((string)($u[$field]??''))!==''));$holderPercent=(int)round(($holderComplete/count($holderFields))*100);$sessionCount=count($sessions);$hasPteroKey=trim((string)($u['ptero_client_key']??''))!=='';
$totpUri = $setup ? 'otpauth://totp/' . rawurlencode('FoxNetwork:' . $u['email']) . '?secret=' . rawurlencode($setup) . '&issuer=' . rawurlencode('FoxNetwork') . '&algorithm=SHA1&digits=6&period=30' : ''; ?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width">
  <title><?=e($settingsPage==='profile'?'Profile settings':($settingsPage==='security'?'Security settings':'Sessions & login history'))?> | FoxNetwork</title>
  <link rel="stylesheet" href="/assets/portal.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/assets/portal.css')) ?>"><style>.settings-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}.settings-summary article{padding:16px 18px;border:1px solid #2a3038;border-radius:13px;background:#15191f}.settings-summary span,.settings-summary small{display:block;color:#7f8996;font-size:9px}.settings-summary b{display:block;margin:6px 0 4px;font-size:16px}.settings-nav{display:flex;gap:7px;overflow:auto;margin-bottom:18px;padding:5px;border:1px solid #292f37;border-radius:12px;background:#101419}.settings-nav a{padding:9px 12px;border-radius:8px;color:#9ca6b3;text-decoration:none;font-size:11px;white-space:nowrap}.settings-nav a:hover,.settings-nav a.active{background:#252b33;color:#fff}.security-card{scroll-margin-top:20px}.recovery-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.two-factor-actions{display:grid;gap:14px;margin-top:16px;padding-top:16px;border-top:1px solid #2a3038}.two-factor-actions form{margin:0}.settings-page-section{display:none}.settings-page-<?=e($settingsPage)?>{display:block}.settings-page-security .security-grid{grid-template-columns:repeat(2,minmax(0,1fr))}@media(max-width:750px){.settings-summary{grid-template-columns:1fr 1fr}.settings-page-security .security-grid{grid-template-columns:1fr}}@media(max-width:430px){.settings-summary{grid-template-columns:1fr}}</style>
</head>

<body class="portal-page"><?php render_client_page_start($u, 'settings', 'Account settings'); ?><div class="security-page">
    <div class="security-top"><a class="link" href="/client">← Dashboard</a>
      <h1><?=e($settingsPage==='profile'?'Profile settings':($settingsPage==='security'?'Security settings':'Sessions & login history'))?></h1>
      <p class="muted"><?=e($settingsPage==='profile'?'Manage your personal and domain-holder information.':($settingsPage==='security'?'Manage your password, authenticator and recovery codes.':'Review signed-in devices and recent account access.'))?></p>
    </div><section class="settings-summary"><article><span>ACCOUNT</span><b><?=e(ucfirst((string)($u['account_status']??'active')))?></b><small><?=e($u['email'])?></small></article><article><span>DOMAIN PROFILE</span><b><?=$holderPercent?>% complete</b><small><?=$holderComplete?> of <?=count($holderFields)?> required fields</small></article><article><span>TWO-FACTOR</span><b><?=!empty($u['two_factor_enabled'])?'Enabled':'Disabled'?></b><small><?=!empty($u['two_factor_enabled'])?'Extra login protection':'Setup recommended'?></small></article><article><span>GAME PANEL</span><b><?=$hasPteroKey?'Connected':'Not connected'?></b><small><?=$sessionCount?> active session<?=$sessionCount===1?'':'s'?></small></article></section><nav class="settings-nav" aria-label="Settings pages"><a class="<?=$settingsPage==='profile'?'active':''?>" href="/settings-profile.php">Profile &amp; domain holder</a><a class="<?=$settingsPage==='security'?'active':''?>" href="/settings-security.php">Password &amp; 2FA</a><a class="<?=$settingsPage==='sessions'?'active':''?>" href="/settings-sessions.php">Sessions &amp; history</a></nav>
    <?php if ($ok): ?><div class="notice"><?= e($ok) ?></div><?php endif ?><?php if ($err): ?><div class="error"><?= e($err) ?></div><?php endif ?><div class="settings-page-section settings-page-security"><?php if ($newRecovery): ?><section class="security-card">
        <h2>Recovery codes</h2>
        <p class="muted">Store these somewhere safe. Each code can be used once.</p>
        <div class="recovery-grid" id="new-recovery-codes"><?php foreach ($newRecovery as $c): ?><code><?= e($c) ?></code><?php endforeach ?></div><div class="recovery-actions"><button class="btn" type="button" id="copy-recovery-codes">Copy codes</button><button class="btn" type="button" id="download-recovery-codes">Download .txt</button></div>
      </section><?php endif ?></div><div class="settings-page-section settings-page-profile"><div class="security-grid">
      <section class="security-card" id="profile">
        <h2>Profile</h2>
        <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="profile">
          <div class="field"><label>Name</label><input name="name" value="<?= e($u['name']) ?>" required></div>
          <div class="field"><label>Email</label><input value="<?= e($u['email']) ?>" disabled></div><label class="check"><input type="checkbox" name="email_notifications" <?= ((int)($u['email_notifications'] ?? 1)) ? 'checked' : '' ?>> Email notifications</label><label class="check"><input type="checkbox" name="notify_whatsapp" <?=!empty($u['notify_whatsapp'])?'checked':''?>> WhatsApp notifications</label>
          <div class="field"><label>Company <span class="muted">(optional)</span></label><input name="company_name" value="<?=e($u['company_name']??'')?>"></div>
          <div class="field"><label>Phone</label><input name="phone" value="<?=e($u['phone']??'')?>"></div>
          <div class="field"><label>Street</label><input name="street" value="<?=e($u['street']??'')?>"></div>
          <div class="field"><label>House number</label><input name="house_number" value="<?=e($u['house_number']??'')?>"></div>
          <div class="field"><label>Postal code</label><input name="postal_code" value="<?=e($u['postal_code']??'')?>"></div>
          <div class="field"><label>City</label><input name="city" value="<?=e($u['city']??'')?>"></div>
          <div class="field"><label>State / province</label><input name="state" value="<?=e($u['state']??'')?>"></div>
          <div class="field"><label>Country code</label><input name="country_code" maxlength="2" value="<?=e($u['country_code']??'')?>" placeholder="BE"></div>
          <?php if(!empty($u['oxxa_identity_handle'])):?><div class="field"><label>OXXA identity handle</label><input value="<?=e($u['oxxa_identity_handle'])?>" disabled></div><?php endif?>
          <p class="muted">These details are required when registering a domain and are used to create your personal OXXA holder identity.</p><button class="btn primary">Save profile</button>
        </form>
      </section></div></div><div class="settings-page-section settings-page-security"><div class="security-grid"><section class="security-card" id="password">
        <h2>Change password</h2>
        <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="password">
          <div class="field"><label>Current password</label><input type="password" name="current_password" required></div>
          <div class="field"><label>New password</label><input type="password" name="new_password" minlength="10" required></div>
          <div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" minlength="10" required></div><button class="btn">Change password</button>
        </form>
      </section>
      <section class="security-card" id="two-factor">
        <h2>Two-factor authentication</h2><?php if ((int)($u['two_factor_enabled'] ?? 0) === 1): $activeMethod=(string)($u['two_factor_method']??'totp'); ?><div class="notice"><?=e(ucfirst($activeMethod))?> 2FA is enabled. <?= $recoveryCount ?> recovery code<?= $recoveryCount===1?'':'s' ?> remaining.</div><p class="muted">Your selected second-factor channel protects password sign-ins.</p><div class="two-factor-actions"><form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="2fa_regenerate"><h3>Generate new recovery codes</h3><p class="muted">This immediately invalidates every previous recovery code.</p><div class="field"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><div class="field"><label><?=$activeMethod==='totp'?'6-digit authenticator code':'Recovery code'?></label><input name="code" autocomplete="one-time-code" maxlength="12" required></div><button class="btn">Generate new codes</button></form>
          <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="2fa_disable">
            <h3>Disable two-factor authentication</h3><div class="field"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><div class="field"><label><?=$activeMethod==='totp'?'6-digit authenticator code':'Recovery code'?></label><input name="code" autocomplete="one-time-code" maxlength="12" required></div><button class="btn danger" onclick="return confirm('Disable two-factor authentication for this account?')">Disable 2FA</button>
          </form></div><?php elseif ($setup): ?><p class="muted">Scan this QR code with Microsoft Authenticator, Google Authenticator, 1Password, Authy or another TOTP-compatible app.</p>
          <div class="totp-qr-wrap">
            <div id="totp-qrcode" class="totp-qrcode" aria-label="Two-factor authentication QR code"></div>
          </div>
          <p class="muted">Can't scan it? Use the setup key below.</p>
          <div class="secret-box"><span>Secret</span><code><?= e($setup) ?></code></div>
          <div class="secret-box"><span>Account</span><code>FoxNetwork:<?= e($u['email']) ?></code></div>
          <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="2fa_enable">
            <div class="field"><label>6-digit code</label><input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div><button class="btn primary">Verify & enable</button>
          </form><form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= csrf() ?>"><button class="btn" name="action" value="2fa_cancel">Cancel setup</button></form><?php else: ?><?php if($channelSetup):?><div class="notice">A code was sent by WhatsApp.</div><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="2fa_channel_enable"><div class="field"><label>Verification code</label><input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{4,10}" required></div><button class="btn primary">Verify &amp; enable</button></form><?php else:?><p class="muted">Choose an authenticator app or WhatsApp. You will also receive ten one-time recovery codes.</p>
          <div class="recovery-actions"><form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><button class="btn primary" name="action" value="2fa_begin">Authenticator app</button></form><?php if(telnyx_enabled()):?><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="2fa_channel_begin"><button class="btn" name="method" value="whatsapp">WhatsApp</button></form><?php endif?></div><?php endif?><?php endif ?>
      </section></div></div>
    <div class="settings-page-section settings-page-sessions"><section class="security-card" id="sessions">
      <div class="security-card-head">
        <div>
          <h2>Active sessions</h2>
          <p class="muted">Devices currently signed in to your account.</p>
        </div>
        <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><button class="btn" name="action" value="sessions">Sign out other sessions</button></form>
      </div>
      <div class="security-list"><?php foreach ($sessions as $s): ?><div><b><?= session_id() === $s['session_id'] ? 'This session' : 'Signed-in session' ?></b><span><?= e($s['ip_address'] ?: 'Unknown IP') ?> · <?= e($s['last_seen_at']) ?></span><small><?= e($s['user_agent'] ?: 'Unknown device') ?></small></div><?php endforeach ?></div>
    </section>
    <section class="security-card" id="history">
      <h2>Login history</h2>
      <div class="security-list"><?php foreach ($history as $h): ?><div><b><?= $h['success'] ? 'Successful login' : 'Failed login' ?></b><span><?= e($h['ip_address'] ?: 'Unknown IP') ?> · <?= e($h['created_at']) ?></span><small><?= e($h['user_agent'] ?: 'Unknown device') ?></small></div><?php endforeach ?></div>
    </section></div>
  </div><?php render_client_page_end(); ?><?php if ($setup): ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      var el = document.getElementById('totp-qrcode');
      if (el && window.QRCode) {
        new QRCode(el, {
          text: <?= json_encode($totpUri, JSON_UNESCAPED_SLASHES) ?>,
          width: 220,
          height: 220,
          colorDark: '#111111',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M
        });
      }
    });
  </script>
<?php endif ?>
<?php if ($newRecovery): ?>
  <script>
    (()=>{
      const box=document.getElementById('new-recovery-codes');
      const codes=()=>[...box.querySelectorAll('code')].map(el=>el.textContent.trim()).join('\n');
      document.getElementById('copy-recovery-codes')?.addEventListener('click',async function(){
        try{await navigator.clipboard.writeText(codes());this.textContent='Copied';setTimeout(()=>this.textContent='Copy codes',1400)}catch(error){window.prompt('Copy your recovery codes:',codes())}
      });
      document.getElementById('download-recovery-codes')?.addEventListener('click',()=>{
        const blob=new Blob(['FoxNetwork recovery codes\n\n'+codes()+'\n'],{type:'text/plain'});
        const link=document.createElement('a');link.href=URL.createObjectURL(blob);link.download='foxnetwork-recovery-codes.txt';link.click();setTimeout(()=>URL.revokeObjectURL(link.href),1000);
      });
    })();
  </script>
<?php endif ?>
</body>

</html>
