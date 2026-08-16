<?php require __DIR__ . '/app/bootstrap.php';
$id = (int)($_SESSION['2fa_pending_uid'] ?? 0);
if (!$id) {
    header('Location:/login.php');
    exit;
}
$q = db()->prepare('SELECT * FROM users WHERE id=?');
$q->execute([$id]);
$u = $q->fetch();
if (!$u) {
    header('Location:/login.php');
    exit;
}
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $method=(string)($u['two_factor_method']??'totp');
    if(($_POST['action']??'verify')==='resend'&&in_array($method,['whatsapp','sms'],true)){
        $last=(int)($_SESSION['2fa_message_sent_at']??0);
        if(time()-$last<60)$err='Wait one minute before requesting another code.';
        else{try{if($method==='sms')sms_verify_start((string)($u['phone']??''));else messaging_verify_start((string)($u['phone']??''));$_SESSION['2fa_message_sent_at']=time();$err='';}catch(Throwable $e){$err='Could not send a new code. You can still use a recovery code.';}}
    }else{
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $ok=false;
    if($method==='totp')$ok=totp_verify(dec($u['two_factor_secret']) ?: '', $code);
    elseif($method==='whatsapp'){try{$ok=messaging_verify_check((string)($u['phone']??''),$code);}catch(Throwable $e){$err=$e->getMessage();}}
    elseif($method==='sms'){try{$ok=sms_verify_check((string)($u['phone']??''),$code);}catch(Throwable $e){$err=$e->getMessage();}}
    if (!$ok) {
        $codes = json_decode(dec($u['two_factor_recovery_codes']) ?: '[]', true) ?: [];
        $idx = array_search(strtoupper($code), $codes, true);
        if ($idx !== false) {
            unset($codes[$idx]);
            db()->prepare('UPDATE users SET two_factor_recovery_codes=? WHERE id=?')->execute([enc(json_encode(array_values($codes))), $id]);
            $ok = true;
        }
    }
    if ($ok) {
        unset($_SESSION['2fa_pending_uid'], $_SESSION['2fa_pending_email'],$_SESSION['2fa_message_sent_at'],$_SESSION['sms_2fa']);
        session_regenerate_id(true);
        $_SESSION['uid'] = $id;
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
    if($err==='')$err = 'Invalid authentication or recovery code.';
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
            <div class="field"><label>Authentication or recovery code</label><input name="code" autocomplete="one-time-code" inputmode="text" maxlength="12" required autofocus></div><button class="btn primary wide">Verify securely</button>
        </form>
        <?php if(in_array((string)($u['two_factor_method']??'totp'),['whatsapp','sms'],true)):?><form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?=csrf()?>"><button class="btn wide" name="action" value="resend">Send a new code</button></form><?php endif?>
    </div>
</body>

</html>
