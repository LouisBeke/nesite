<?php require __DIR__ . '/app/bootstrap.php';
$challenge=security_two_factor_challenge();
$id=(int)($challenge['uid']??0);
if (!$challenge || !$id) {
    $_SESSION['login_security_message']='Your two-factor challenge expired. Sign in again.';
    header('Location:/login.php');
    exit;
}
$q = db()->prepare('SELECT * FROM users WHERE id=?');
$q->execute([$id]);
$u = $q->fetch();
if (!$u || ($u['account_status']??'active')==='disabled' || (int)($u['two_factor_enabled']??0)!==1 || !hash_equals((string)($challenge['email_hash']??''),hash('sha256',strtolower((string)($u['email']??''))))) {
    security_clear_two_factor_challenge();
    header('Location:/login.php');
    exit;
}
$u['two_factor_recovery_codes']=security_recovery_upgrade($id,$u['two_factor_recovery_codes']??null);
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $method=(string)($u['two_factor_method']??'totp');
    $action=(string)($_POST['action']??'verify');
    if($action==='cancel'){
        security_clear_two_factor_challenge();
        $_SESSION['login_security_message']='Sign-in cancelled.';
        header('Location:/login.php');exit;
    }elseif($action==='resend'&&in_array($method,['whatsapp','sms'],true)){
        $last=(int)($_SESSION['2fa_message_sent_at']??0);
        if(time()-$last<60)$err='Wait one minute before requesting another code.';
        else{try{if($method==='sms')sms_verify_start((string)($u['phone']??''));else messaging_verify_start((string)($u['phone']??''));$_SESSION['2fa_message_sent_at']=time();$err='';}catch(Throwable $e){$err='Could not send a new code. You can still use a recovery code.';}}
    }else{
    if(!security_two_factor_attempt_allowed()){
        security_log_event($id,'two_factor_locked',false,'Too many incorrect challenge attempts.');
        security_clear_two_factor_challenge();
        $_SESSION['login_security_message']='Too many incorrect codes. Sign in again to start a new challenge.';
        header('Location:/login.php');exit;
    }
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $ok=false;
    $usedRecovery=false;
    if($method==='totp')$ok=totp_verify_and_consume($id,(string)(dec($u['two_factor_secret']) ?: ''),$code);
    elseif($method==='whatsapp'){try{$ok=messaging_verify_check((string)($u['phone']??''),$code);}catch(Throwable $e){$err=$e->getMessage();}}
    elseif($method==='sms'){try{$ok=sms_verify_check((string)($u['phone']??''),$code);}catch(Throwable $e){$err=$e->getMessage();}}
    if (!$ok) {
        $usedRecovery=security_recovery_consume($id,$u['two_factor_recovery_codes']??null,$code);
        $ok=$usedRecovery;
    }
    if ($ok) {
        security_log_event($id,$usedRecovery?'recovery_code_used':'two_factor_verified',true,$usedRecovery?'A one-time recovery code was used.':ucfirst($method).' challenge completed.');
        security_clear_two_factor_challenge();
        session_regenerate_id(true);
        $_SESSION['uid'] = $id;
        if(!empty($_POST['trust_device']))security_create_trusted_device($id);
        security_log_login($id, $u['email'], true);
        security_touch_session($id);
        try {
            link_existing_ptero_user_for_local_user($u);
        } catch (Throwable $e) {
        }
        try {
            auto_setup_ptero_client_key_for_local_user($u);
        } catch (Throwable $e) {
        }
        db()->prepare('UPDATE users SET last_login_at=NOW(),last_login_ip=? WHERE id=?')->execute([$_SERVER['REMOTE_ADDR'] ?? null, $id]);
        header('Location:/client');
        exit;
    }
    security_log_event($id,'two_factor_failed',false,'An incorrect '.$method.' or recovery code was submitted.');
    $attempts=(int)(($_SESSION['2fa_challenge']['attempts']??0));
    if($attempts>=5){security_clear_two_factor_challenge();$_SESSION['login_security_message']='Too many incorrect codes. Sign in again to start a new challenge.';header('Location:/login.php');exit;}
    if($err==='')$err = 'Invalid authentication or recovery code. '.(5-$attempts).' attempt'.((5-$attempts)===1?'':'s').' remaining.';
    }
} ?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width">
    <title>Two-factor authentication</title>
    <link rel="stylesheet" href="/assets/portal.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/assets/portal.css')) ?>">
</head>

<body>
    <div class="auth">
        <form class="authbox" method="post"><img src="/images/logo.png">
            <h1>Two-factor authentication</h1>
            <p class="muted"><?php $method=(string)($u['two_factor_method']??'totp'); ?>Enter the 6-digit code from <?=e($method==='totp'?'your authenticator app':($method==='sms'?'the SMS we sent':'the WhatsApp message we sent'))?>, or a recovery code.</p><?php if ($err): ?><div class="error"><?= e($err) ?></div><?php endif ?><input type="hidden" name="csrf" value="<?= csrf() ?>">
            <div class="field"><label>Authentication or recovery code</label><input name="code" autocomplete="one-time-code" inputmode="text" maxlength="12" required autofocus></div><label class="check"><input type="checkbox" name="trust_device" value="1"> Trust this browser for 30 days</label><p class="muted small">Only use this on a private device. You can revoke trusted browsers from account settings.</p><button class="btn primary wide">Verify securely</button>
        </form>
        <?php if(in_array((string)($u['two_factor_method']??'totp'),['whatsapp','sms'],true)):?><form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?=csrf()?>"><button class="btn wide" name="action" value="resend">Send a new code</button></form><?php endif?>
        <form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?=csrf()?>"><button class="btn wide" name="action" value="cancel">Cancel sign-in</button></form>
    </div>
<script defer src="/js/marketing-animations.js?v=20260830a"></script>
</body>

</html>
