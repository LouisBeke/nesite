<?php require __DIR__ . '/app/bootstrap.php';
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$err = '';
$done = false;
$q = db()->prepare('SELECT prt.*,u.email FROM password_reset_tokens prt JOIN users u ON u.id=prt.user_id WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW()');
$q->execute([hash('sha256', $token)]);
$r = $q->fetch();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $r) {
    verify_csrf();
    $p = $_POST['password'] ?? '';
    if (strlen($p) < 10) $err = 'Password must be at least 10 characters.';
    elseif ($p !== ($_POST['password_confirm'] ?? '')) $err = 'Passwords do not match.';
    else {
        db()->beginTransaction();
        db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($p, PASSWORD_DEFAULT), $r['user_id']]);
        db()->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE id=?')->execute([$r['id']]);
        db()->prepare('DELETE FROM user_sessions WHERE user_id=?')->execute([$r['user_id']]);
        try{db()->prepare('DELETE FROM trusted_devices WHERE user_id=?')->execute([$r['user_id']]);}catch(Throwable $e){}
        db()->commit();
        security_forget_trusted_cookie();
        security_log_event((int)$r['user_id'],'password_reset',true,'Password reset completed; sessions and trusted browsers were revoked.');
        $done = true;
    }
} ?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width">
    <title>Choose password</title>
    <link rel="stylesheet" href="/assets/portal.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/assets/portal.css')) ?>">
</head>

<body>
    <div class="auth">
        <form class="authbox" method="post"><img src="/images/logo.png">
            <h1>Choose a new password</h1><?php if ($done): ?><div class="notice">Password changed. All sessions and trusted browsers were signed out. You can sign in now.</div>
                <p><a class="btn primary wide" href="/login.php">Sign in</a></p><?php elseif (!$r): ?><div class="error">This reset link is invalid or expired.</div><?php else: ?><?php if ($err): ?><div class="error"><?= e($err) ?></div><?php endif ?><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="field"><label>New password</label><input type="password" name="password" minlength="10" required></div>
            <div class="field"><label>Confirm password</label><input type="password" name="password_confirm" minlength="10" required></div><button class="btn primary wide">Change password</button><?php endif ?>
        </form>
    </div>
<script defer src="/js/marketing-animations.js?v=20260830a"></script>
</body>

</html>
