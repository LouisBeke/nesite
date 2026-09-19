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
      if (mb_strlen($profileName) > 120) throw new RuntimeException('Name must be 120 characters or fewer.');
      $profileEmail=fox_email_normalize((string)($_POST['email']??$u['email']));
      $emailChanged=!hash_equals(fox_email_normalize((string)$u['email']),$profileEmail);
      if(!filter_var($profileEmail,FILTER_VALIDATE_EMAIL)||mb_strlen($profileEmail)>190)throw new RuntimeException('Please enter a valid email address.');
      if($emailChanged){
        if(!fox_email_is_personal($profileEmail))throw new RuntimeException(fox_personal_email_required_message());
        if(!password_verify((string)($_POST['current_password']??''),(string)$u['password_hash']))throw new RuntimeException('Enter your current password to change your email address.');
        $emailOwner=db()->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');$emailOwner->execute([$profileEmail,$u['id']]);
        if($emailOwner->fetchColumn())throw new RuntimeException('An account with this email already exists.');
      }
      $profileFields=[];foreach(['company_name','phone','street','house_number','postal_code','city','state','country_code'] as $field)$profileFields[$field]=trim((string)($_POST[$field]??''));
      $profileFields['country_code']=strtoupper($profileFields['country_code']);
      foreach(['company_name'=>190,'phone'=>40,'street'=>190,'house_number'=>30,'postal_code'=>30,'city'=>120,'state'=>120] as $field=>$max){if(mb_strlen($profileFields[$field])>$max)throw new RuntimeException(ucwords(str_replace('_',' ',$field)).' is too long.');}
      $activeTwoFactorMethod=(string)($u['two_factor_method']??'totp');
      if((int)($u['two_factor_enabled']??0)===1&&in_array($activeTwoFactorMethod,['sms','whatsapp'],true)&&!hash_equals(trim((string)($u['phone']??'')),$profileFields['phone']))throw new RuntimeException('Disable phone-based two-factor authentication before changing its phone number, then verify the new number when you enable it again.');
      if($profileFields['country_code']!==''&&!preg_match('/^[A-Z]{2}$/',$profileFields['country_code']))throw new RuntimeException('Country must be a two-letter country code.');
      $notifyWhatsapp=isset($_POST['notify_whatsapp'])?1:0;
      if($notifyWhatsapp&&!preg_match('/^\+[1-9]\d{7,14}$/',preg_replace('/[\s().-]+/','',$profileFields['phone'])))throw new RuntimeException('WhatsApp requires an international phone number such as +32470123456.');
      $database=db();$database->beginTransaction();
      try{
        $database->prepare('UPDATE users SET name=?,email_notifications=?,notify_whatsapp=?,company_name=?,phone=?,street=?,house_number=?,postal_code=?,city=?,state=?,country_code=? WHERE id=?')->execute([$profileName,isset($_POST['email_notifications'])?1:0,$notifyWhatsapp,$profileFields['company_name']?:null,$profileFields['phone']?:null,$profileFields['street']?:null,$profileFields['house_number']?:null,$profileFields['postal_code']?:null,$profileFields['city']?:null,$profileFields['state']?:null,$profileFields['country_code']?:null,$u['id']]);
        if($emailChanged)$database->prepare('UPDATE users SET email=?,email_verified_at=NULL,email_verification_token_hash=NULL,email_verification_expires_at=NULL WHERE id=?')->execute([$profileEmail,$u['id']]);
        $database->commit();
      }catch(Throwable $databaseError){if($database->inTransaction())$database->rollBack();throw $databaseError;}
      $u['name'] = $profileName;
      $u['email'] = $profileEmail;
      if($emailChanged)$u['email_verified_at']=null;
      $u['email_notifications'] = isset($_POST['email_notifications']) ? 1 : 0;
      foreach($profileFields as $profileKey=>$profileValue)$u[$profileKey]=$profileValue;
      $u['notify_whatsapp']=$notifyWhatsapp;
      try {
        zoho_crm_sync_customer($u);
      } catch (Throwable $crmError) {
        error_log('FoxNetwork profile Zoho CRM sync failed for user ' . (int)$u['id'] . ': ' . $crmError->getMessage());
      }
      if($emailChanged){
        db()->prepare('DELETE FROM user_sessions WHERE user_id=? AND session_id<>?')->execute([$u['id'],session_id()]);
        security_revoke_trusted_devices((int)$u['id']);
        security_log_event((int)$u['id'],'email_changed',true,'Email address changed; verification is required and other sessions and trusted browsers were revoked.');
        $_SESSION['email_verification_pending_email']=$profileEmail;
        try{$verificationSent=email_verification_send($u);}catch(Throwable $mailError){$verificationSent=false;error_log('FoxNetwork changed-email verification failed for user '.(int)$u['id'].': '.$mailError->getMessage());}
        $ok=$verificationSent?'Account settings saved. Check your new inbox and verify the email address before your next sign-in.':'Your email was changed, but the verification message could not be sent. Use the resend option on the verification page.';
      }else{$ok = 'Account settings saved.';}
    } elseif ($a === 'password') {
      if (!password_verify($_POST['current_password'] ?? '', $u['password_hash'])) throw new RuntimeException('Current password is incorrect.');
      $p = $_POST['new_password'] ?? '';
      if (strlen($p) < 10) throw new RuntimeException('New password must be at least 10 characters.');
      if ($p !== ($_POST['confirm_password'] ?? '')) throw new RuntimeException('New passwords do not match.');
      db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($p, PASSWORD_DEFAULT), $u['id']]);
      db()->prepare('DELETE FROM user_sessions WHERE user_id=? AND session_id<>?')->execute([$u['id'], session_id()]);
      security_revoke_trusted_devices((int)$u['id']);
      security_log_event((int)$u['id'],'password_changed',true,'Password changed; other sessions and trusted browsers were revoked.');
      $ok = 'Password changed. Other sessions and trusted browsers were signed out.';
    } elseif ($a === '2fa_begin') {
      if (!password_verify($_POST['current_password'] ?? '', $u['password_hash'])) throw new RuntimeException('Current password is incorrect.');
      $secret = b32encode(random_bytes(20));
      $_SESSION['2fa_setup_secret'] = $secret;
      $_SESSION['2fa_setup_expires_at'] = time()+600;
      $_SESSION['2fa_setup_uid'] = (int)$u['id'];
      unset($_SESSION['2fa_channel_setup']);
      $ok = 'Secret generated. Add it to your authenticator app, then verify a code below.';
    } elseif ($a === '2fa_channel_begin' || $a === '2fa_channel_resend') {
      if($a==='2fa_channel_begin'&&!password_verify($_POST['current_password'] ?? '', $u['password_hash']))throw new RuntimeException('Current password is incorrect.');
      $method=$a==='2fa_channel_resend'?(string)($_SESSION['2fa_channel_setup']??''):(string)($_POST['method']??'');if(!in_array($method,['whatsapp','sms'],true))throw new RuntimeException('Choose WhatsApp or SMS.');
      if($a==='2fa_channel_resend'&&((int)($_SESSION['2fa_setup_uid']??0)!==(int)$u['id']||(int)($_SESSION['2fa_setup_expires_at']??0)<time()))throw new RuntimeException('Two-factor setup expired. Start again.');
      $last=(int)($_SESSION['2fa_setup_message_sent_at']??0);if($a==='2fa_channel_resend'&&time()-$last<60)throw new RuntimeException('Wait one minute before requesting another code.');
      unset($_SESSION['2fa_setup_secret']);if($method==='sms')sms_verify_start((string)($u['phone']??''));else messaging_verify_start((string)($u['phone']??''));$_SESSION['2fa_channel_setup']=$method;$_SESSION['2fa_setup_message_sent_at']=time();$_SESSION['2fa_setup_expires_at']=time()+600;$_SESSION['2fa_setup_uid']=(int)$u['id'];$ok='A new verification code was sent by '.($method==='sms'?'SMS':'WhatsApp').'.';
    } elseif ($a === '2fa_channel_enable') {
      if((int)($_SESSION['2fa_setup_uid']??0)!==(int)$u['id']||(int)($_SESSION['2fa_setup_expires_at']??0)<time())throw new RuntimeException('Two-factor setup expired. Start again.');
      $method=(string)($_SESSION['2fa_channel_setup']??'');$valid=$method==='sms'?sms_verify_check((string)($u['phone']??''),(string)($_POST['code']??'')):($method==='whatsapp'&&messaging_verify_check((string)($u['phone']??''),(string)($_POST['code']??'')));if(!$valid)throw new RuntimeException('Invalid or expired verification code.');
      [$newRecovery,$recoveryStorage]=security_recovery_generate();
      db()->prepare('UPDATE users SET two_factor_enabled=1,two_factor_method=?,two_factor_secret=NULL,two_factor_recovery_codes=?,two_factor_last_counter=NULL,two_factor_changed_at=NOW() WHERE id=?')->execute([$method,$recoveryStorage,$u['id']]);unset($_SESSION['2fa_channel_setup'],$_SESSION['2fa_setup_message_sent_at'],$_SESSION['2fa_setup_expires_at'],$_SESSION['2fa_setup_uid']);security_log_event((int)$u['id'],'two_factor_enabled',true,ucfirst($method).' was enabled.');$ok=ucfirst($method).' two-factor authentication enabled. Save your recovery codes now.';
    } elseif ($a === '2fa_enable') {
      $secret = $_SESSION['2fa_setup_secret'] ?? '';
      if ((int)($_SESSION['2fa_setup_uid']??0)!==(int)$u['id']||(int)($_SESSION['2fa_setup_expires_at']??0)<time()) throw new RuntimeException('Two-factor setup expired. Start again.');
      $counter=totp_matching_counter((string)$secret,(string)($_POST['code']??''));
      if (!$secret || $counter===null) throw new RuntimeException('Invalid authenticator code.');
      [$newRecovery,$recoveryStorage]=security_recovery_generate();
      db()->prepare("UPDATE users SET two_factor_secret=?,two_factor_enabled=1,two_factor_method='totp',two_factor_recovery_codes=?,two_factor_last_counter=?,two_factor_changed_at=NOW() WHERE id=?")->execute([enc($secret),$recoveryStorage,$counter,$u['id']]);
      unset($_SESSION['2fa_setup_secret']);
      unset($_SESSION['2fa_channel_setup'],$_SESSION['sms_2fa'],$_SESSION['2fa_setup_message_sent_at'],$_SESSION['2fa_setup_expires_at'],$_SESSION['2fa_setup_uid']);
      security_log_event((int)$u['id'],'two_factor_enabled',true,'Authenticator app was enabled.');
      $ok = 'Two-factor authentication enabled. Save your recovery codes now.';
    } elseif ($a === '2fa_cancel') {
      unset($_SESSION['2fa_setup_secret'],$_SESSION['sms_2fa']);
      unset($_SESSION['2fa_channel_setup'],$_SESSION['2fa_setup_message_sent_at'],$_SESSION['2fa_setup_expires_at'],$_SESSION['2fa_setup_uid']);
      $ok = 'Two-factor setup cancelled.';
    } elseif ($a === '2fa_regenerate') {
      if ((int)($u['two_factor_enabled'] ?? 0) !== 1) throw new RuntimeException('Two-factor authentication is not enabled.');
      if (!password_verify($_POST['current_password'] ?? '', $u['password_hash'])) throw new RuntimeException('Current password is incorrect.');
      $method=(string)($u['two_factor_method']??'totp');$secret=(string)(dec($u['two_factor_secret']??null)??'');
      if ($method==='totp'&&($secret==='' || !totp_verify_and_consume((int)$u['id'],$secret,(string)($_POST['code'] ?? '')))) throw new RuntimeException('Enter a fresh 6-digit authenticator code.');
      if ($method!=='totp'&&!security_recovery_consume((int)$u['id'],$u['two_factor_recovery_codes']??null,(string)($_POST['code']??'')))throw new RuntimeException('Enter a valid recovery code.');
      [$newRecovery,$recoveryStorage]=security_recovery_generate();
      db()->prepare('UPDATE users SET two_factor_recovery_codes=?,two_factor_changed_at=NOW() WHERE id=?')->execute([$recoveryStorage,$u['id']]);
      security_revoke_trusted_devices((int)$u['id']);db()->prepare('DELETE FROM user_sessions WHERE user_id=? AND session_id<>?')->execute([$u['id'],session_id()]);security_log_event((int)$u['id'],'recovery_codes_regenerated',true,'Recovery codes replaced; trusted browsers and other sessions were revoked.');
      $ok = 'New recovery codes generated. Previous codes, trusted browsers, and other sessions were revoked.';
    } elseif ($a === '2fa_disable') {
      if (!password_verify($_POST['current_password'] ?? '', $u['password_hash'])) throw new RuntimeException('Current password is incorrect.');
      $method=(string)($u['two_factor_method']??'totp');$secret=(string)(dec($u['two_factor_secret']??null)??'');
      if ($method==='totp'&&($secret==='' || !totp_verify_and_consume((int)$u['id'],$secret,(string)($_POST['code'] ?? '')))) throw new RuntimeException('Enter a fresh 6-digit authenticator code.');
      db()->prepare('UPDATE users SET two_factor_secret=NULL,two_factor_enabled=0,two_factor_recovery_codes=NULL,two_factor_last_counter=NULL,two_factor_changed_at=NOW() WHERE id=?')->execute([$u['id']]);
      security_revoke_trusted_devices((int)$u['id']);db()->prepare('DELETE FROM user_sessions WHERE user_id=? AND session_id<>?')->execute([$u['id'],session_id()]);security_log_event((int)$u['id'],'two_factor_disabled',true,'2FA disabled; trusted browsers and other sessions were revoked.');
      unset($_SESSION['2fa_setup_secret']);
      $ok = 'Two-factor authentication disabled. Trusted browsers and other sessions were revoked.';
    } elseif ($a === 'sessions') {
      db()->prepare('DELETE FROM user_sessions WHERE user_id=? AND session_id<>?')->execute([$u['id'], session_id()]);
      security_log_event((int)$u['id'],'other_sessions_revoked',true,'All other active sessions were signed out.');
      $ok = 'Other sessions signed out.';
    } elseif ($a === 'trusted_devices') {
      if (!password_verify($_POST['current_password'] ?? '', $u['password_hash'])) throw new RuntimeException('Current password is incorrect.');
      security_revoke_trusted_devices((int)$u['id']);security_log_event((int)$u['id'],'trusted_devices_revoked',true,'All trusted browsers were revoked.');$ok='All trusted browsers revoked.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
  $freshUser=db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$freshUser->execute([(int)$u['id']]);$u=$freshUser->fetch()?:$u;
}
$sessions = [];
$history = [];
$trustedDevices = [];
$securityEvents = [];
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
try {
  db()->prepare('DELETE FROM trusted_devices WHERE user_id=? AND expires_at<=NOW()')->execute([$u['id']]);
  $q=db()->prepare('SELECT * FROM trusted_devices WHERE user_id=? AND expires_at>NOW() ORDER BY last_used_at DESC,created_at DESC');$q->execute([$u['id']]);$trustedDevices=$q->fetchAll();
} catch (Throwable $e) {
}
try {
  $q=db()->prepare('SELECT * FROM account_security_events WHERE user_id=? ORDER BY id DESC LIMIT 20');$q->execute([$u['id']]);$securityEvents=$q->fetchAll();
} catch (Throwable $e) {
}
$setup = ((int)($_SESSION['2fa_setup_uid']??0)===(int)$u['id']&&(int)($_SESSION['2fa_setup_expires_at']??0)>=time())?($_SESSION['2fa_setup_secret']??''):'';
$channelSetup=((int)($_SESSION['2fa_setup_uid']??0)===(int)$u['id']&&(int)($_SESSION['2fa_setup_expires_at']??0)>=time())?(string)($_SESSION['2fa_channel_setup']??''):'';
$u['two_factor_recovery_codes']=security_recovery_upgrade((int)$u['id'],$u['two_factor_recovery_codes']??null);
$recoveryCount=security_recovery_count($u['two_factor_recovery_codes']??null);
$holderFields=['name','phone','street','house_number','postal_code','city','state','country_code'];$holderComplete=count(array_filter($holderFields,fn($field)=>trim((string)($u[$field]??''))!==''));$holderPercent=(int)round(($holderComplete/count($holderFields))*100);$sessionCount=count($sessions);$hasPteroKey=ptero_client_access_available($u);
$totpUri = $setup ? 'otpauth://totp/' . rawurlencode('FoxNetwork:' . $u['email']) . '?secret=' . rawurlencode($setup) . '&issuer=' . rawurlencode('FoxNetwork') . '&algorithm=SHA1&digits=6&period=30' : ''; ?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width">
  <title><?=e($settingsPage==='profile'?'Profile settings':($settingsPage==='security'?'Security settings':'Sessions & login history'))?> | FoxNetwork</title>
  <link rel="stylesheet" href="/assets/portal.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/assets/portal.css')) ?>"><style>.settings-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}.settings-summary article{padding:16px 18px;border:1px solid #2a3038;border-radius:13px;background:#15191f}.settings-summary span,.settings-summary small{display:block;color:#7f8996;font-size:9px}.settings-summary b{display:block;margin:6px 0 4px;font-size:16px}.settings-nav{display:flex;gap:7px;overflow:auto;margin-bottom:18px;padding:5px;border:1px solid #292f37;border-radius:12px;background:#101419}.settings-nav a{padding:9px 12px;border-radius:8px;color:#9ca6b3;text-decoration:none;font-size:11px;white-space:nowrap}.settings-nav a:hover,.settings-nav a.active{background:#252b33;color:#fff}.security-card{scroll-margin-top:20px}.recovery-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.two-factor-actions{display:grid;gap:14px;margin-top:16px;padding-top:16px;border-top:1px solid #2a3038}.two-factor-actions form{margin:0}.method-choices{display:grid;gap:12px;margin-top:14px}.method-choice{padding:14px;border:1px solid #2a3038;border-radius:11px;background:#11151a}.method-choice h3{margin-top:0}.full-card{margin-top:16px}.settings-page-section{display:none}.settings-page-<?=e($settingsPage)?>{display:block}.settings-page-security .security-grid{grid-template-columns:repeat(2,minmax(0,1fr))}@media(max-width:750px){.settings-summary{grid-template-columns:1fr 1fr}.settings-page-security .security-grid{grid-template-columns:1fr}}@media(max-width:430px){.settings-summary{grid-template-columns:1fr}}</style>
</head>

<body class="portal-page"><?php render_client_page_start($u, 'settings', 'Account settings'); ?><div class="security-page">
    <div class="security-top"><a class="link" href="/client">← Dashboard</a>
      <h1><?=e($settingsPage==='profile'?'Profile settings':($settingsPage==='security'?'Security settings':'Sessions & login history'))?></h1>
      <p class="muted"><?=e($settingsPage==='profile'?'Manage your personal and domain-holder information.':($settingsPage==='security'?'Manage your password, authenticator and recovery codes.':'Review signed-in devices and recent account access.'))?></p>
    </div><section class="settings-summary"><article><span>ACCOUNT</span><b><?=e(ucfirst((string)($u['account_status']??'active')))?></b><small><?=e($u['email'])?></small></article><article><span>DOMAIN PROFILE</span><b><?=$holderPercent?>% complete</b><small><?=$holderComplete?> of <?=count($holderFields)?> required fields</small></article><article><span>TWO-FACTOR</span><b><?=!empty($u['two_factor_enabled'])?'Enabled':'Disabled'?></b><small><?=!empty($u['two_factor_enabled'])?e(ucfirst((string)($u['two_factor_method']??'totp')).' · '.$recoveryCount.' recovery codes'):'Setup recommended'?></small></article><article><span>TRUSTED BROWSERS</span><b><?=count($trustedDevices)?></b><small><?=$sessionCount?> active session<?=$sessionCount===1?'':'s'?></small></article></section><nav class="settings-nav" aria-label="Settings pages"><a class="<?=$settingsPage==='profile'?'active':''?>" href="/settings-profile.php">Profile &amp; domain holder</a><a class="<?=$settingsPage==='security'?'active':''?>" href="/settings-security.php">Password &amp; 2FA</a><a class="<?=$settingsPage==='sessions'?'active':''?>" href="/settings-sessions.php">Sessions &amp; history</a></nav>
    <?php if ($ok): ?><div class="notice"><?= e($ok) ?></div><?php endif ?><?php if ($err): ?><div class="error"><?= e($err) ?></div><?php endif ?><div class="settings-page-section settings-page-security"><?php if ($newRecovery): ?><section class="security-card">
        <h2>Recovery codes</h2>
        <p class="muted">Store these somewhere safe. Each code can be used once.</p>
        <div class="recovery-grid" id="new-recovery-codes"><?php foreach ($newRecovery as $c): ?><code><?= e($c) ?></code><?php endforeach ?></div><div class="recovery-actions"><button class="btn" type="button" id="copy-recovery-codes">Copy codes</button><button class="btn" type="button" id="download-recovery-codes">Download .txt</button></div>
      </section><?php endif ?></div><div class="settings-page-section settings-page-profile"><div class="security-grid">
      <section class="security-card" id="profile">
        <h2>Profile</h2>
        <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="profile">
          <div class="field"><label>Name</label><input name="name" value="<?= e($u['name']) ?>" required></div>
          <div class="field"><label>Email</label><input type="email" name="email" autocomplete="email" value="<?= e($u['email']) ?>" required><small class="muted"><?=empty($u['email_verified_at'])?'Not verified.':'Verified.'?> Changing this signs out other sessions and requires verification of the new address.</small></div>
          <div class="field"><label>Current password <span class="muted">(required only to change email)</span></label><input type="password" name="current_password" autocomplete="current-password"><small class="muted">Self-service email changes accept personal providers such as Gmail, Yahoo, Outlook, iCloud, and Proton. For business email, <a class="link" href="mailto:info@foxnetwork.be?subject=FoxNetwork%20business%20email%20change">email us</a>.</small></div><label class="check"><input type="checkbox" name="email_notifications" <?= ((int)($u['email_notifications'] ?? 1)) ? 'checked' : '' ?>> Email notifications</label><label class="check"><input type="checkbox" name="notify_whatsapp" <?=!empty($u['notify_whatsapp'])?'checked':''?>> WhatsApp notifications</label>
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
        <h2>Two-factor authentication</h2><?php if ((int)($u['two_factor_enabled'] ?? 0) === 1): $activeMethod=(string)($u['two_factor_method']??'totp'); ?><div class="notice"><?=e(ucfirst($activeMethod))?> 2FA is enabled. <?= $recoveryCount ?> recovery code<?= $recoveryCount===1?'':'s' ?> remaining.</div><?php if($recoveryCount<3):?><div class="error">You are running low on recovery codes. Generate a fresh set now.</div><?php endif?><p class="muted">Authenticator apps are the strongest available option. Every authenticator code and recovery code can now be accepted only once.</p><div class="two-factor-actions"><form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="2fa_regenerate"><h3>Generate new recovery codes</h3><p class="muted">This immediately invalidates every previous recovery code.</p><div class="field"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><div class="field"><label><?=$activeMethod==='totp'?'6-digit authenticator code':'Recovery code'?></label><input name="code" autocomplete="one-time-code" maxlength="12" required></div><button class="btn">Generate new codes</button></form>
          <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="2fa_disable">
            <h3>Disable two-factor authentication</h3><div class="field"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><?php if($activeMethod==='totp'):?><div class="field"><label>6-digit authenticator code</label><input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div><?php else:?><p class="muted">Your current password is enough to disable phone-based 2FA. No recovery code is required.</p><?php endif?><button class="btn danger" onclick="return confirm('Disable two-factor authentication for this account?')">Disable 2FA</button>
          </form></div><?php elseif ($setup): ?><p class="muted">Scan this QR code with Microsoft Authenticator, Google Authenticator, 1Password, Authy or another TOTP-compatible app.</p>
          <div class="totp-qr-wrap">
            <div id="totp-qrcode" class="totp-qrcode" aria-label="Two-factor authentication QR code"></div>
          </div>
          <p class="muted">Can't scan it? Use the setup key below.</p>
          <div class="secret-box"><span>Secret</span><code><?= e($setup) ?></code></div>
          <div class="secret-box"><span>Account</span><code>FoxNetwork:<?= e($u['email']) ?></code></div>
          <form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="2fa_enable">
            <div class="field"><label>6-digit code</label><input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div><button class="btn primary">Verify & enable</button>
          </form><form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= csrf() ?>"><button class="btn" name="action" value="2fa_cancel">Cancel setup</button></form><?php else: ?><?php if($channelSetup):?><div class="notice">A code was sent by <?=e($channelSetup==='sms'?'SMS':'WhatsApp')?>. This setup expires in 10 minutes.</div><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="2fa_channel_enable"><div class="field"><label>Verification code</label><input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{4,10}" required></div><button class="btn primary">Verify &amp; enable</button></form><form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?=csrf()?>"><button class="btn" name="action" value="2fa_channel_resend">Send a new <?=e($channelSetup==='sms'?'SMS':'WhatsApp')?> code</button> <button class="btn" name="action" value="2fa_cancel">Cancel setup</button></form><?php else:?><p class="muted">Choose a method and confirm your current password. You will also receive ten one-time recovery codes.</p>
          <div class="method-choices"><form method="post" class="method-choice"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="2fa_begin"><h3>Authenticator app · Recommended</h3><p class="muted">Works offline and resists phone-number takeover.</p><div class="field"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><button class="btn primary">Set up authenticator</button></form><?php if(sms_gateway_enabled()):?><form method="post" class="method-choice"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="2fa_channel_begin"><input type="hidden" name="method" value="sms"><h3>SMS</h3><p class="muted">Receive a code at your saved phone number.</p><div class="field"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><button class="btn">Set up SMS</button></form><?php endif?><?php if(telnyx_enabled()):?><form method="post" class="method-choice"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="2fa_channel_begin"><input type="hidden" name="method" value="whatsapp"><h3>WhatsApp</h3><p class="muted">Receive a code through WhatsApp.</p><div class="field"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><button class="btn">Set up WhatsApp</button></form><?php endif?></div><?php endif?><?php endif ?>
      </section></div><section class="security-card full-card" id="trusted-devices"><div class="security-card-head"><div><h2>Trusted browsers</h2><p class="muted">Browsers you allowed to skip the second-factor prompt for 30 days.</p></div></div><?php if($trustedDevices):?><div class="security-list"><?php foreach($trustedDevices as $device):?><div><b>Trusted browser</b><span><?=e($device['ip_address']?:'Unknown IP')?> · expires <?=e($device['expires_at'])?></span><small><?=e($device['user_agent']?:'Unknown device')?></small></div><?php endforeach?></div><form method="post" class="two-factor-actions"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="trusted_devices"><div class="field"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><button class="btn danger" onclick="return confirm('Revoke every trusted browser?')">Revoke all trusted browsers</button></form><?php else:?><p class="muted">No browsers are currently trusted.</p><?php endif?></section></div>
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
    </section><section class="security-card" id="security-activity"><h2>Security activity</h2><p class="muted">Recent changes and second-factor activity on your account.</p><div class="security-list"><?php if(!$securityEvents):?><div><b>No security activity recorded yet</b></div><?php endif?><?php foreach($securityEvents as $event):?><div><b><?=e(ucwords(str_replace('_',' ',(string)$event['event_type'])))?><?=empty($event['success'])?' · Blocked':''?></b><span><?=e($event['ip_address']?:'Unknown IP')?> · <?=e($event['created_at'])?></span><small><?=e($event['details']?:'Account security event')?></small></div><?php endforeach?></div></section></div>
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
>
y>

</html>
</body>

</html>
>
y>

</html>
