<?php
require __DIR__.'/app/bootstrap.php';
$error='';$success='';
$pendingEmail=strtolower(trim((string)($_SESSION['email_verification_pending_email']??'')));
$token=trim((string)($_GET['token']??''));
if($token!==''){
    try{
        if(!preg_match('/^[a-f0-9]{64}$/i',$token))throw new RuntimeException('This verification link is invalid.');
        $q=db()->prepare('SELECT * FROM users WHERE email_verification_token_hash=? LIMIT 1');$q->execute([hash('sha256',$token)]);$account=$q->fetch();
        if(!$account)throw new RuntimeException('This verification link is invalid or has already been used.');
        if(empty($account['email_verification_expires_at'])||strtotime((string)$account['email_verification_expires_at'])<time())throw new RuntimeException('This verification link has expired. Request a new email below.');
        db()->prepare('UPDATE users SET email_verified_at=NOW(),email_verification_token_hash=NULL,email_verification_expires_at=NULL WHERE id=?')->execute([(int)$account['id']]);
        unset($_SESSION['email_verification_pending_email']);
        try{provision_ptero_client_key_for_new_user((int)$account['id']);}catch(Throwable $e){error_log('Post-verification Pterodactyl setup failed: '.$e->getMessage());}
        $success='Your email address is verified. You can now sign in.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $email=strtolower(trim((string)($_POST['email']??'')));$pendingEmail=$email;
    try{
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
        $q=db()->prepare("SELECT * FROM users WHERE email=? AND role='customer' LIMIT 1");$q->execute([$email]);$account=$q->fetch();
        if($account&&!empty($account['email_verified_at'])){$success='This email address is already verified. You can sign in.';}
        elseif($account){
            $expires=strtotime((string)($account['email_verification_expires_at']??''));
            if($expires && $expires>time()+86100)throw new RuntimeException('A verification email was sent recently. Wait a few minutes before trying again.');
            email_verification_send($account);$success='If the address belongs to an unverified account, a new verification email has been sent.';
        }else{$success='If the address belongs to an unverified account, a new verification email has been sent.';}
    }catch(Throwable $e){$error=$e->getMessage();}
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Verify email | FoxNetwork</title><link rel="stylesheet" href="/assets/portal.css?v=<?=rawurlencode((string)@filemtime(__DIR__.'/assets/portal.css'))?>"></head><body><div class="auth"><div class="authbox"><img src="/images/logo.png" alt="FoxNetwork"><h1>Verify your email.</h1><p class="muted">Use the link sent to your inbox. Verification links expire after 24 hours.</p><?php if($success):?><div class="notice"><?=e($success)?></div><p><a class="btn primary wide" href="/login.php">Continue to sign in</a></p><?php endif?><?php if($error):?><div class="error"><?=e($error)?></div><?php endif?><?php if(!$success||isset($_GET['pending'])):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><div class="field"><label>Email address</label><input type="email" name="email" required value="<?=e($pendingEmail)?>" autocomplete="email"></div><button class="btn wide">Resend verification email</button></form><?php endif?><p><a class="link" href="/login.php">Back to sign in</a></p></div></div><script defer src="/js/marketing-animations.js?v=20260829b"></script></body></html>
