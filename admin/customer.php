<?php require __DIR__.'/../app/bootstrap.php';require __DIR__.'/_layout.php';$u=require_admin();$id=(int)($_GET['id']??0);$msg='';$err='';$q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);$c=$q->fetch();if(!$c){http_response_code(404);die('Customer not found.');}
if(empty($c['ptero_user_id']) && !empty($c['email'])){
	try{
		$pteroUserId=ensure_ptero_user_for_local_user((string)$c['email'],(string)$c['name'],false);
		if($pteroUserId){db()->prepare('UPDATE users SET ptero_user_id=? WHERE id=?')->execute([$pteroUserId,$id]);$q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);$c=$q->fetch();}
	}catch(Throwable $e){}
}
if($_SERVER['REQUEST_METHOD']==='POST'&&in_array($_POST['action']??'', ['ptero_key_save','ptero_key_auto','ptero_key_clear'],true)){
 verify_csrf();
 try{
  $a=(string)$_POST['action'];
  if($a==='ptero_key_save'){
   $token=trim((string)($_POST['ptero_client_key']??''));
   if($token==='')throw new RuntimeException('Enter a Pterodactyl Client API key.');
   $account=ptero_client_account_for_token($token);
   if(!is_array($account))throw new RuntimeException('This Client API key is invalid or cannot access the configured Pterodactyl panel.');
   $accountId=(int)($account['id']??0);$pteroUserId=(int)($c['ptero_user_id']??0);
   $accountEmail=strtolower(trim((string)($account['email']??'')));$customerEmail=strtolower(trim((string)($c['email']??'')));
   if($pteroUserId>0&&$accountId>0&&$accountId!==$pteroUserId)throw new RuntimeException('This key belongs to a different Pterodactyl user.');
   if($customerEmail!==''&&$accountEmail!==''&&!hash_equals($customerEmail,$accountEmail))throw new RuntimeException('This key belongs to a different Pterodactyl email address.');
   db()->prepare('UPDATE users SET ptero_client_key=? WHERE id=?')->execute([enc($token),$id]);
   $msg='Personal Pterodactyl Client API key saved and verified.';
  }elseif($a==='ptero_key_auto'){
   $pteroUserId=ensure_ptero_user_for_local_user((string)$c['email'],(string)$c['name'],true);
   db()->prepare('UPDATE users SET ptero_user_id=? WHERE id=?')->execute([$pteroUserId,$id]);
   $c['ptero_user_id']=$pteroUserId;
   if(!provision_personal_ptero_client_key_for_local_user($c,true))throw new RuntimeException('Pterodactyl user linked, but no personal Client API key was created. Install the panel API-key addon, or create a key in the customer Pterodactyl account and save it below.');
   $msg='Pterodactyl user linked and personal Client API key created on the panel.';
   }else{
   db()->prepare('UPDATE users SET ptero_client_key=NULL WHERE id=?')->execute([$id]);
   $msg='Pterodactyl Client API key removed.';
  }
  db()->prepare('INSERT INTO customer_activity(user_id,admin_user_id,action,details) VALUES(?,?,?,?)')->execute([$id,$u['id'],$a,'Pterodactyl Client API credential updated by admin.']);
 }catch(Throwable $e){$err=$e->getMessage();}
  $q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);$c=$q->fetch();
 }
if($_SERVER['REQUEST_METHOD']==='POST'&&in_array((string)($_POST['action']??''),['security_reset_password','security_reset_2fa'],true)){
 verify_csrf();
 try{
  $securityAction=(string)$_POST['action'];
  if($securityAction==='security_reset_password'){
   if($id===(int)$u['id'])throw new RuntimeException('Use Account & Security to change your own password.');
   $temporaryPassword=(string)($_POST['new_password']??'');if(strlen($temporaryPassword)<10)throw new RuntimeException('Temporary password must be at least 10 characters.');
   db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($temporaryPassword,PASSWORD_DEFAULT),$id]);
   db()->prepare('DELETE FROM user_sessions WHERE user_id=?')->execute([$id]);security_revoke_trusted_devices($id);
   security_log_event($id,'password_admin_reset',true,'Password was reset by an administrator; sessions and trusted browsers were revoked.');
   db()->prepare('INSERT INTO customer_activity(user_id,admin_user_id,action,details) VALUES(?,?,?,?)')->execute([$id,$u['id'],'security_reset_password','Password, trusted browsers, and active sessions reset by admin.']);
   $msg='Password reset. All sessions and trusted browsers were revoked.';
  }else{
   if($id===(int)$u['id'])throw new RuntimeException('Use Account & Security to change your own two-factor authentication.');
   db()->prepare('UPDATE users SET two_factor_secret=NULL,two_factor_enabled=0,two_factor_recovery_codes=NULL,two_factor_last_counter=NULL,two_factor_changed_at=NOW() WHERE id=?')->execute([$id]);
   db()->prepare('DELETE FROM user_sessions WHERE user_id=?')->execute([$id]);security_revoke_trusted_devices($id);
   security_log_event($id,'two_factor_admin_reset',true,'Two-factor authentication was reset by an administrator.');
   db()->prepare('INSERT INTO customer_activity(user_id,admin_user_id,action,details) VALUES(?,?,?,?)')->execute([$id,$u['id'],'security_reset_2fa','2FA, trusted browsers, and active sessions reset by admin.']);
   $msg='Customer 2FA, trusted browsers, and active sessions reset.';
  }
  $_POST['action']='security_handled';
 }catch(Throwable $e){$err=$e->getMessage();$_POST['action']='security_handled';}
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='customer_profile'){
 verify_csrf();
 try{
  $profileName=trim((string)($_POST['name']??''));
  $profileEmail=fox_email_normalize((string)($_POST['email']??''));
  if(mb_strlen($profileName)<2||mb_strlen($profileName)>120)throw new RuntimeException('Customer name must contain 2 to 120 characters.');
  if(!filter_var($profileEmail,FILTER_VALIDATE_EMAIL)||mb_strlen($profileEmail)>190)throw new RuntimeException('Enter a valid customer email address.');
  $emailOwner=db()->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');$emailOwner->execute([$profileEmail,$id]);
  if($emailOwner->fetchColumn())throw new RuntimeException('Another customer already uses this email address.');

  $profileFields=[];
  foreach(['company_name','phone','street','house_number','postal_code','city','state','country_code'] as $field)$profileFields[$field]=trim((string)($_POST[$field]??''));
  $profileFields['country_code']=strtoupper($profileFields['country_code']);
  foreach(['company_name'=>190,'phone'=>40,'street'=>190,'house_number'=>30,'postal_code'=>30,'city'=>120,'state'=>120] as $field=>$max){
   if(mb_strlen($profileFields[$field])>$max)throw new RuntimeException(ucwords(str_replace('_',' ',$field)).' is too long.');
  }
  if($profileFields['country_code']!==''&&!preg_match('/^[A-Z]{2}$/',$profileFields['country_code']))throw new RuntimeException('Country must be a two-letter country code.');
  $notifyWhatsapp=isset($_POST['notify_whatsapp'])?1:0;
  if($notifyWhatsapp&&!preg_match('/^\+[1-9]\d{7,14}$/',preg_replace('/[\s().-]+/','',$profileFields['phone'])))throw new RuntimeException('WhatsApp requires an international phone number such as +32470123456.');

  $profileRole=($_POST['role']??'customer')==='admin'?'admin':'customer';
  $profileStatus=($_POST['account_status']??'active')==='disabled'?'disabled':'active';
  if($id===(int)$u['id']&&($profileRole!=='admin'||$profileStatus!=='active'))throw new RuntimeException('You cannot demote or disable your own administrator account.');
  $emailVerified=($_POST['email_verification']??'required')==='verified';
  $emailChanged=!hash_equals(fox_email_normalize((string)$c['email']),$profileEmail);
  $verificationChanged=$emailVerified!==!empty($c['email_verified_at']);
  $adminNotes=trim((string)($_POST['admin_notes']??''));

  $database=db();$database->beginTransaction();
  try{
   $database->prepare('UPDATE users SET name=?,email=?,role=?,account_status=?,email_notifications=?,notify_whatsapp=?,company_name=?,phone=?,street=?,house_number=?,postal_code=?,city=?,state=?,country_code=?,admin_notes=? WHERE id=?')->execute([
    $profileName,$profileEmail,$profileRole,$profileStatus,isset($_POST['email_notifications'])?1:0,$notifyWhatsapp,
    $profileFields['company_name']?:null,$profileFields['phone']?:null,$profileFields['street']?:null,$profileFields['house_number']?:null,
    $profileFields['postal_code']?:null,$profileFields['city']?:null,$profileFields['state']?:null,$profileFields['country_code']?:null,$adminNotes?:null,$id
   ]);
   if($emailChanged||$verificationChanged){
    if($emailVerified)$database->prepare('UPDATE users SET email_verified_at=COALESCE(email_verified_at,NOW()),email_verification_token_hash=NULL,email_verification_expires_at=NULL WHERE id=?')->execute([$id]);
    else $database->prepare('UPDATE users SET email_verified_at=NULL,email_verification_token_hash=NULL,email_verification_expires_at=NULL WHERE id=?')->execute([$id]);
   }
   $changes=[];
   if($emailChanged)$changes[]='email changed';
   if($verificationChanged)$changes[]=$emailVerified?'email marked verified':'email verification required';
   if($profileRole!==(string)$c['role'])$changes[]='role changed to '.$profileRole;
   if($profileStatus!==(string)($c['account_status']??'active'))$changes[]='status changed to '.$profileStatus;
   $details='Customer account details updated by admin'.($changes?': '.implode(', ',$changes):'.');
   $database->prepare('INSERT INTO customer_activity(user_id,admin_user_id,action,details) VALUES(?,?,?,?)')->execute([$id,$u['id'],'customer_profile',$details]);
   $database->commit();
  }catch(Throwable $databaseError){if($database->inTransaction())$database->rollBack();throw $databaseError;}

  $sensitiveAccountChange=$emailChanged||$profileStatus==='disabled'||$profileRole!==(string)$c['role'];
  if($sensitiveAccountChange){
   if($id===(int)$u['id'])db()->prepare('DELETE FROM user_sessions WHERE user_id=? AND session_id<>?')->execute([$id,session_id()]);
   else db()->prepare('DELETE FROM user_sessions WHERE user_id=?')->execute([$id]);
   security_revoke_trusted_devices($id);
   security_log_event($id,'profile_admin_changed',true,'An administrator changed email, role, or account status; active sessions and trusted browsers were revoked.');
  }

  $q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);$c=$q->fetch();
  try{zoho_crm_sync_customer($c);}catch(Throwable $crmError){error_log('FoxNetwork admin customer Zoho CRM sync failed for user '.$id.': '.$crmError->getMessage());}
  $verificationSent=null;
  if(!$emailVerified&&($emailChanged||$verificationChanged)){
   try{$verificationSent=email_verification_send($c);}catch(Throwable $mailError){$verificationSent=false;error_log('FoxNetwork admin email verification failed for user '.$id.': '.$mailError->getMessage());}
  }
  $msg='Customer account details saved.';
  if($verificationSent===true)$msg.=' A verification link was sent to the customer.';
  elseif($verificationSent===false)$msg.=' The verification email could not be sent; the customer can resend it from the verification page.';
  if($emailChanged&&!empty($c['ptero_user_id']))$msg.=' Review the linked Pterodactyl account because its login email may still be the previous address.';
  $_POST['action']='customer_profile_handled';
 }catch(Throwable $e){$err=$e->getMessage();$_POST['action']='customer_profile_handled';}
}
if($_SERVER['REQUEST_METHOD']==='POST'){verify_csrf();try{$a=$_POST['action']??'';if($a==='status'){if($id===(int)$u['id'])throw new RuntimeException('You cannot disable your own account.');$st=($_POST['account_status']??'active')==='disabled'?'disabled':'active';db()->prepare('UPDATE users SET account_status=? WHERE id=?')->execute([$st,$id]);db()->prepare('INSERT INTO customer_activity(user_id,admin_user_id,action,details) VALUES(?,?,?,?)')->execute([$id,$u['id'],$st==='disabled'?'disable':'enable','Account status changed to '.$st]);$msg='Account status updated.';}elseif($a==='notes'){db()->prepare('UPDATE users SET admin_notes=? WHERE id=?')->execute([trim($_POST['admin_notes']??''),$id]);db()->prepare('INSERT INTO customer_activity(user_id,admin_user_id,action,details) VALUES(?,?,?,?)')->execute([$id,$u['id'],'notes','Admin notes updated.']);$msg='Admin notes saved.';}elseif($a==='ptero_sync'){if(empty($c['email']))throw new RuntimeException('Customer email is required for Pterodactyl sync.');$pteroUserId=ensure_ptero_user_for_local_user((string)$c['email'],(string)$c['name'],true);db()->prepare('UPDATE users SET ptero_user_id=? WHERE id=?')->execute([$pteroUserId,$id]);$c['ptero_user_id']=$pteroUserId;if(!auto_setup_ptero_client_key_for_local_user($c,true))throw new RuntimeException('Pterodactyl user linked, but Client API access is unavailable. Configure the administrator Client API key in Settings.');db()->prepare('INSERT INTO customer_activity(user_id,admin_user_id,action,details) VALUES(?,?,?,?)')->execute([$id,$u['id'],'ptero_sync','Pterodactyl account and portal Client API access linked automatically.']);$msg='Pterodactyl account and portal access connected.';}elseif($a==='security_reset_password'){if($id===(int)$u['id'])throw new RuntimeException('Use Account & Security to change your own password.');$p1=$_POST['new_password']??'';if(strlen($p1)<10)throw new RuntimeException('Temporary password must be at least 10 characters.');db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($p1,PASSWORD_DEFAULT),$id]);db()->prepare('DELETE FROM user_sessions WHERE user_id=?')->execute([$id]);$msg='Password reset. Give the temporary password to the customer securely.';}elseif($a==='security_reset_2fa'){db()->prepare('UPDATE users SET two_factor_secret=NULL,two_factor_enabled=0,two_factor_recovery_codes=NULL WHERE id=?')->execute([$id]);$msg='Customer 2FA reset.';}elseif($a==='impersonate'){if($id===(int)$u['id'])throw new RuntimeException('Already using this account.');if(($c['account_status']??'active')==='disabled')throw new RuntimeException('Enable this account before impersonating it.');$_SESSION['admin_return_uid']=$u['id'];$_SESSION['admin_return_customer_id']=$id;$_SESSION['uid']=$id;header('Location: /client');exit;}}catch(Throwable $e){$err=$e->getMessage();}$q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);$c=$q->fetch();}
function rows_for($sql,$id){$q=db()->prepare($sql);$q->execute([$id]);return $q->fetchAll();}$services=rows_for('SELECT s.*,p.name product_name FROM services s LEFT JOIN store_products p ON p.id=s.product_id WHERE s.user_id=? ORDER BY s.id DESC',$id);$invoices=rows_for('SELECT * FROM invoices WHERE user_id=? ORDER BY id DESC LIMIT 20',$id);$orders=rows_for('SELECT * FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 20',$id);$tickets=rows_for('SELECT * FROM support_tickets WHERE user_id=? ORDER BY id DESC LIMIT 20',$id);$activity=rows_for('SELECT ca.*,a.name admin_name FROM customer_activity ca LEFT JOIN users a ON a.id=ca.admin_user_id WHERE ca.user_id=? ORDER BY ca.id DESC LIMIT 30',$id);$pteroStatus=(int)($c['ptero_user_id']??0)>0?'Linked to Pterodactyl user':'Not linked to Pterodactyl';$pteroClientKey=(trim((string)($c['ptero_client_key']??''))!=='')?'Client key saved':((int)($c['ptero_user_id']??0)>0?'Linked by email':'Not linked');admin_head($u,'Customer Profile','customers');?><?php if($msg):?><div class="notice"><?=e($msg)?></div><?php endif?><?php if($err):?><div class="error"><?=e($err)?></div><?php endif?><div class="customer-hero card"><div><div class="eyebrow">CUSTOMER #<?=$c['id']?></div><h2><?=e($c['name'])?></h2><div class="muted"><!--email_off--><?=e($c['email'])?><!--/email_off--> · Joined <?=e($c['created_at'])?></div></div><div><?=admin_badge($c['account_status']??'active')?></div></div><div class="customer-actions"><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="status"><select name="account_status"><option value="active" <?=($c['account_status']??'active')==='active'?'selected':''?>>Active</option><option value="disabled" <?=($c['account_status']??'active')==='disabled'?'selected':''?>>Disabled</option></select><button class="btn">Update account</button></form><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><button class="btn primary" name="action" value="impersonate">Login as customer</button></form></div><section class="card settings-card"><div class="cardhead"><b>ACCOUNT SECURITY</b></div><div class="ticket-form"><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="security_reset_password"><input type="password" name="new_password" minlength="10" placeholder="Temporary password" required><button class="btn">Set temporary password</button></form><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><button class="btn danger" name="action" value="security_reset_2fa" onclick="return confirm('Reset this customer’s two-factor authentication?')">Reset customer 2FA</button></form></div></section><section class="stats"><div class="stat"><span class="muted">Services</span><strong><?=count($services)?></strong></div><div class="stat"><span class="muted">Invoices</span><strong><?=count($invoices)?></strong></div><div class="stat"><span class="muted">Tickets</span><strong><?=count($tickets)?></strong></div></section><section class="card"><div class="cardhead"><b>PTERODACTYL</b><span class="muted"><?=e($pteroStatus)?> · <?=e($pteroClientKey)?></span></div><form method="post" class="ticket-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="ptero_sync"><p class="muted">Use the customer email to match or create the Pterodactyl account, then store the internal user ID.</p><button class="btn primary">Sync Pterodactyl by email</button></form></section><section class="card"><div class="cardhead"><b>ADMIN NOTES</b><span class="muted">Private</span></div><form method="post" class="ticket-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="notes"><textarea name="admin_notes" rows="4" placeholder="Private notes about this customer..."><?=e($c['admin_notes']??'')?></textarea><button class="btn">Save notes</button></form></section>
<section class="card" style="margin-top:20px"><div class="cardhead"><b>ALL CUSTOMER SERVICES</b><a class="link" href="/admin/services.php">View every service</a></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Service</th><th>Product</th><th>Status</th><th>Pterodactyl</th><th>Expiration / Next due</th><th>Price</th><th></th></tr></thead><tbody><?php if(!$services):?><tr><td colspan="7" class="muted">This customer has no services.</td></tr><?php endif?><?php foreach($services as $service):?><?php $serviceCfg=json_decode((string)($service['config_json']??''),true)?:[];$serviceProduct=$service['product_name']??($serviceCfg['product_name']??'—');$serviceIsTrial=!empty($service['is_trial']);$serviceIsFree=(float)($service['price_monthly']??0)<=0&&!$serviceIsTrial;?><tr><td><b><?=e($service['name'])?></b><small>#<?=e($service['id'])?></small></td><td><?=e($serviceProduct)?></td><td><?=admin_badge($serviceIsTrial?'trial':(string)$service['status'])?></td><td><?=e($service['ptero_identifier']??'Not provisioned')?></td><td><?=e($serviceIsFree?'Never':(!empty($service['next_due_at'])?date('d M Y',strtotime($service['next_due_at'])):'—'))?><?php if($serviceIsTrial):?><small>Trial expiration</small><?php endif?></td><td><?=e(strtoupper((string)($service['currency']??'EUR')))?> <?=number_format((float)($service['price_monthly']??0),2)?><?php if($serviceIsTrial):?><small>After trial</small><?php endif?></td><td><div class="service-actions"><?php if(!empty($service['ptero_server_id'])):?><form method="post" action="/admin/open-service.php"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="service_id" value="<?=e($service['id'])?>"><button class="btn primary">Open service</button></form><button class="btn" type="button" data-admin-sftp="<?=e($service['id'])?>">SFTP</button><?php endif?><a class="btn" href="/admin/services.php#service-<?=e($service['id'])?>">Admin manage</a></div></td></tr><?php endforeach?></tbody></table></div></section>
<section class="card settings-card" style="margin-top:20px"><div class="cardhead"><b>PTERODACTYL CLIENT API KEY</b><span class="muted"><?=e($pteroClientKey)?></span></div><div class="ticket-form"><p class="muted">Create and store a personal key on the panel when its API-key provisioning addon is installed, or save a key made in this customer&rsquo;s Pterodactyl account.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><button class="btn primary" name="action" value="ptero_key_auto">Create personal key on panel</button></form><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="password" name="ptero_client_key" placeholder="ptlc_..." autocomplete="new-password" required><button class="btn" name="action" value="ptero_key_save">Verify &amp; save personal key</button></form><?php if(!empty($c['ptero_client_key'])):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><button class="btn danger" name="action" value="ptero_key_clear" onclick="return confirm('Remove this customer client key?')">Remove personal key</button></form><?php endif?></div></section>
<section class="card settings-card" style="margin-top:20px"><div class="cardhead"><b>ALL CUSTOMER DATA</b><span class="muted">Administrators may use business or custom-domain email addresses</span></div><form method="post" class="admin-form-grid customer-profile-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="customer_profile">
<label>Name<input name="name" maxlength="120" autocomplete="name" required value="<?=e($c['name'])?>"></label><label>Email<input type="email" name="email" maxlength="190" autocomplete="email" required value="<?=e($c['email'])?>"><small>Changing this revokes the customer&rsquo;s sessions. Review Pterodactyl afterward when linked.</small></label>
<label>Role<select name="role"><option value="customer" <?=$c['role']==='customer'?'selected':''?>>Customer</option><option value="admin" <?=$c['role']==='admin'?'selected':''?>>Administrator</option></select></label><label>Account status<select name="account_status"><option value="active" <?=($c['account_status']??'active')==='active'?'selected':''?>>Active</option><option value="disabled" <?=($c['account_status']??'active')==='disabled'?'selected':''?>>Disabled</option></select></label>
<label>Email verification<select name="email_verification"><option value="verified" <?=!empty($c['email_verified_at'])?'selected':''?>>Verified (admin-approved)</option><option value="required" <?=empty($c['email_verified_at'])?'selected':''?>>Require verification</option></select><small>Require verification sends a fresh link when the email or verification state changes.</small></label><label>Company<input name="company_name" maxlength="190" autocomplete="organization" value="<?=e($c['company_name']??'')?>"></label>
<label>Phone<input name="phone" maxlength="40" autocomplete="tel" value="<?=e($c['phone']??'')?>"></label><label>Street<input name="street" maxlength="190" autocomplete="address-line1" value="<?=e($c['street']??'')?>"></label><label>House number<input name="house_number" maxlength="30" value="<?=e($c['house_number']??'')?>"></label><label>Postal code<input name="postal_code" maxlength="30" autocomplete="postal-code" value="<?=e($c['postal_code']??'')?>"></label><label>City<input name="city" maxlength="120" autocomplete="address-level2" value="<?=e($c['city']??'')?>"></label><label>State / province<input name="state" maxlength="120" autocomplete="address-level1" value="<?=e($c['state']??'')?>"></label><label>Country code<input name="country_code" maxlength="2" pattern="[A-Za-z]{2}" autocomplete="country" placeholder="BE" value="<?=e($c['country_code']??'')?>"></label>
<div class="fullfield customer-profile-options"><label><input type="checkbox" name="email_notifications" value="1" <?=!isset($c['email_notifications'])||!empty($c['email_notifications'])?'checked':''?>> Email notifications</label><label><input type="checkbox" name="notify_whatsapp" value="1" <?=!empty($c['notify_whatsapp'])?'checked':''?>> WhatsApp notifications</label></div><label class="fullfield">Private admin notes<textarea name="admin_notes" rows="4" placeholder="Private notes about this customer..."><?=e($c['admin_notes']??'')?></textarea></label><div class="fullfield"><button class="btn primary" onclick="return confirm('Save all customer account changes?')">Save all customer data</button></div></form></section>
<style>.customer-profile-form small{display:block;margin-top:6px;color:#7f8996;font-weight:400}.admin-form-grid .customer-profile-options{display:flex;align-items:center;gap:18px;flex-wrap:wrap}.admin-form-grid .customer-profile-options label{display:flex;align-items:center;gap:8px}.admin-form-grid .customer-profile-options input{width:auto;margin:0}</style>
<?php $personalKeyStatus=ptero_personal_client_key_status($c,true);$sharedClientAccess=ptero_admin_client_token(false)!=='';$portalClientAccess=ptero_client_access_available($c); ?>
<section class="card settings-card admin-client-key-visibility" style="margin-top:20px">
  <div class="cardhead"><b>ADMIN-ONLY CLIENT KEY STATUS</b><span class="admin-badge <?=$personalKeyStatus['configured']?($personalKeyStatus['valid']?'status-active':'status-cancelled'):'status-skipped'?>"><?=$personalKeyStatus['configured']?($personalKeyStatus['valid']?'VALID':'INVALID'):'NOT SAVED'?></span></div>
  <div class="admin-key-status-grid">
    <div><small>Personal Client API key</small><b><?=$personalKeyStatus['configured']?'Saved in this customer account':'No personal key saved'?></b><span><?=$personalKeyStatus['configured']?($personalKeyStatus['valid']?'Encrypted and verified against Pterodactyl.':'Stored value could not be verified.'):'No customer-specific secret is stored.'?></span></div>
    <div><small>Automatic portal access</small><b><?=$portalClientAccess?'Available':'Unavailable'?></b><span><?=$sharedClientAccess?'Using the protected administrator fallback when required.':'No administrator Client API fallback is configured.'?></span></div>
    <div><small>Pterodactyl user</small><b><?=!empty($c['ptero_user_id'])?'Linked as #'.e($c['ptero_user_id']):'Not linked'?></b><span>This status and all key controls are visible to administrators only.</span></div>
  </div>
</section>
<?php admin_foot(); ?>
