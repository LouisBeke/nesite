<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';
$u=require_admin();
$err='';$temporaryPassword='';$customerId=0;$verificationSent=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $name=trim((string)($_POST['name']??''));$email=strtolower(trim((string)($_POST['email']??'')));
        $company=trim((string)($_POST['company_name']??''));$phone=trim((string)($_POST['phone']??''));
        $street=trim((string)($_POST['street']??''));$houseNumber=trim((string)($_POST['house_number']??''));
        $postalCode=trim((string)($_POST['postal_code']??''));$city=trim((string)($_POST['city']??''));
        $state=trim((string)($_POST['state']??''));$countryCode=strtoupper(trim((string)($_POST['country_code']??'')));
        if(mb_strlen($name)<2)throw new RuntimeException('Enter the customer full name.');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid customer email address.');
        foreach(['phone'=>$phone,'street'=>$street,'house number'=>$houseNumber,'postal code'=>$postalCode,'city'=>$city,'state or province'=>$state] as $label=>$value)if($value==='')throw new RuntimeException('Enter the customer '.$label.'.');
        if(!preg_match('/^[A-Z]{2}$/',$countryCode))throw new RuntimeException('Country code must contain two letters, for example BE or NL.');
        $exists=db()->prepare('SELECT id FROM users WHERE email=? LIMIT 1');$exists->execute([$email]);
        if($exists->fetchColumn())throw new RuntimeException('A customer with this email address already exists.');
        $temporaryPassword=trim((string)($_POST['password']??''));
        if($temporaryPassword==='')$temporaryPassword='Fx!'.bin2hex(random_bytes(8));
        if(strlen($temporaryPassword)<10)throw new RuntimeException('The temporary password must be at least 10 characters.');
        $insert=db()->prepare("INSERT INTO users(name,email,password_hash,role,email_notifications,company_name,phone,street,house_number,postal_code,city,state,country_code) VALUES(?,?,?,'customer',1,?,?,?,?,?,?,?,?)");
        $insert->execute([$name,$email,password_hash($temporaryPassword,PASSWORD_DEFAULT),$company?:null,$phone,$street,$houseNumber,$postalCode,$city,$state,$countryCode]);
        $customerId=(int)db()->lastInsertId();
        $query=db()->prepare('SELECT * FROM users WHERE id=?');$query->execute([$customerId]);$customer=$query->fetch();
        if($customer){try{zoho_crm_sync_customer($customer);}catch(Throwable $e){error_log('Admin-created customer CRM sync failed: '.$e->getMessage());}try{$verificationSent=email_verification_send($customer);}catch(Throwable $e){error_log('Admin-created customer verification email failed: '.$e->getMessage());}}
        try{db()->prepare('INSERT INTO customer_activity(user_id,admin_user_id,action,details) VALUES(?,?,?,?)')->execute([$customerId,$u['id'],'created','Customer account created by administrator.']);}catch(Throwable $e){}
        $_POST=[];
    }catch(Throwable $e){$err=$e->getMessage();$temporaryPassword='';}
}
admin_head($u,'Create Customer','customers');
?>
<div style="margin-bottom:16px"><a class="btn" href="/admin/customers.php">← Back to customers</a></div>
<?php if($err):?><div class="error"><?=e($err)?></div><?php endif?>
<?php if($customerId):?><div class="notice"><b>Customer created<?=$verificationSent?' and verification email sent':''?></b><p><?=$verificationSent?'The customer must verify their email before signing in.':'The verification email could not be sent. Ask the customer to use “Resend verification email” on the verification page.'?> Copy this temporary password now.</p><div style="display:flex;align-items:center;gap:10px"><code id="new-customer-password" style="font-size:15px"><?=e($temporaryPassword)?></code><button class="btn" type="button" id="copy-new-customer-password">Copy password</button><a class="btn primary" href="/admin/customer.php?id=<?=$customerId?>">Open customer</a></div></div><?php else:?>
<section class="card settings-card"><div class="cardhead"><b>NEW CUSTOMER ACCOUNT</b><span class="muted">Company is optional</span></div><form method="post" class="admin-form-grid" style="padding:18px">
<input type="hidden" name="csrf" value="<?=e(csrf())?>">
<label>Full name<input name="name" required value="<?=e($_POST['name']??'')?>" autocomplete="name"></label><label>Email<input type="email" name="email" required value="<?=e($_POST['email']??'')?>" autocomplete="email"></label>
<label>Company <span class="muted">(optional)</span><input name="company_name" value="<?=e($_POST['company_name']??'')?>" autocomplete="organization"></label><label>Phone<input type="tel" name="phone" required value="<?=e($_POST['phone']??'')?>" autocomplete="tel"></label>
<label>Street<input name="street" required value="<?=e($_POST['street']??'')?>" autocomplete="address-line1"></label><label>House number<input name="house_number" required value="<?=e($_POST['house_number']??'')?>"></label>
<label>Postal code<input name="postal_code" required value="<?=e($_POST['postal_code']??'')?>" autocomplete="postal-code"></label><label>City<input name="city" required value="<?=e($_POST['city']??'')?>" autocomplete="address-level2"></label>
<label>State / province<input name="state" required value="<?=e($_POST['state']??'')?>" autocomplete="address-level1"></label><label>Country code<input name="country_code" required maxlength="2" pattern="[A-Za-z]{2}" placeholder="BE" value="<?=e($_POST['country_code']??'')?>" autocomplete="country"></label>
<label class="fullfield">Temporary password <span class="muted">(leave empty to generate)</span><input type="password" name="password" minlength="10" autocomplete="new-password" placeholder="Automatically generate a secure password"></label>
<div class="fullfield"><button class="btn primary">Create customer</button></div></form></section><?php endif?>
<?php if($customerId):?><script>document.getElementById('copy-new-customer-password')?.addEventListener('click',async function(){const value=document.getElementById('new-customer-password').textContent.trim();try{await navigator.clipboard.writeText(value);this.textContent='Copied'}catch(error){window.prompt('Copy the temporary password:',value)}});</script><?php endif?>
<?php admin_foot(); ?>
