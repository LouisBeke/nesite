<?php
require __DIR__ . '/app/bootstrap.php';

if (user()) {
    header('Location:/client');
    exit;
}

$error = '';
$name = trim((string)($_POST['name'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        if (mb_strlen($name) < 2) {
            throw new RuntimeException('Please enter your full name.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (strlen($password) < 10) {
            throw new RuntimeException('Password must be at least 10 characters.');
        }
        if (!hash_equals($password, $confirm)) {
            throw new RuntimeException('Passwords do not match.');
        }

        $exists = db()->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $exists->execute([$email]);
        if ($exists->fetchColumn()) {
            throw new RuntimeException('An account with this email already exists.');
        }

        $ins = db()->prepare("INSERT INTO users(name,email,password_hash,role,email_notifications) VALUES(?,?,?,'customer',1)");
        $ins->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $uid = (int)db()->lastInsertId();

        $q = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $q->execute([$uid]);
        $u = $q->fetch();
        if (!$u) {
            throw new RuntimeException('Could not create your account. Please try again.');
        }

        try {
            zoho_crm_sync_customer($u);
        } catch (Throwable $e) {
            error_log('FoxNetwork registration Zoho CRM sync failed for user '.$uid.': '.$e->getMessage());
        }

        try {
            provision_ptero_client_key_for_new_user($uid);
        } catch (Throwable $e) {
            error_log('FoxNetwork registration Pterodactyl key setup failed for user '.$uid.': '.$e->getMessage());
        }

        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        security_log_login($uid, $email, true);
        security_touch_session($uid);
        db()->prepare('UPDATE users SET last_login_at=NOW(),last_login_ip=? WHERE id=?')->execute([$_SERVER['REMOTE_ADDR'] ?? null, $uid]);

        header('Location:/client');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width">
    <title>FoxNetwork Register</title>
    <link rel="stylesheet" href="/assets/portal.css?v=9">
</head>
<body>
<div class="auth">
    <form class="authbox" method="post">
        <img src="/images/logo.png">
        <h1>Create account.</h1>
        <p class="muted">Sign up for FoxNetwork Control Center.</p>

        <?php if ($error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif ?>

        <input type="hidden" name="csrf" value="<?= csrf() ?>">

        <div class="field">
            <label>Name</label>
            <input name="name" required value="<?= e($name) ?>">
        </div>

        <div class="field">
            <label>Email</label>
            <input type="email" name="email" required value="<?= e($email) ?>">
        </div>

        <div class="field">
            <label>Password</label>
            <input type="password" name="password" minlength="10" required>
        </div>

        <div class="field">
            <label>Confirm password</label>
            <input type="password" name="confirm_password" minlength="10" required>
        </div>

        <button class="btn primary wide">Create account</button>
        <p><a class="link" href="/login.php">Already have an account? Sign in</a></p>
    </form>
</div>
</body>
</html>
