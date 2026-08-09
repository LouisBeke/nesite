<?php require __DIR__ . '/app/bootstrap.php';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $q = db()->prepare('SELECT * FROM users WHERE email=?');
    $q->execute([$email]);
    $u = $q->fetch();
    if ($u) {
        $token = bin2hex(random_bytes(32));
        db()->prepare('DELETE FROM password_reset_tokens WHERE user_id=? OR expires_at<NOW()')->execute([$u['id']]);
        db()->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 60 MINUTE))')->execute([$u['id'], hash('sha256', $token)]);
        $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/reset-password.php?token=' . $token;
        try {
            send_portal_mail($u['email'], 'Reset your FoxNetwork password', '<p>A password reset was requested for your FoxNetwork account.</p><p><a href="' . e($url) . '">Reset password</a></p><p>This link expires in 60 minutes.</p>');
        } catch (Throwable $e) {
        }
    }
    $msg = 'If that email exists, a password reset link has been created and sent when email delivery is available.';
} ?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width">
    <title>Reset password</title>
    <link rel="stylesheet" href="/assets/portal.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/assets/portal.css')) ?>">
</head>

<body>
    <div class="auth">
        <form class="authbox" method="post"><img src="/images/logo.png">
            <h1>Reset password</h1><?php if ($msg): ?><div class="notice"><?= e($msg) ?></div><?php endif ?><input type="hidden" name="csrf" value="<?= csrf() ?>">
            <div class="field"><label>Email</label><input type="email" name="email" required></div><button class="btn primary wide">Send reset link</button>
            <p><a class="link" href="/login.php">← Sign in</a></p>
        </form>
    </div>
</body>

</html>