<?php
require __DIR__ . '/app/bootstrap.php';

$error = '';
$email = strtolower(trim($_POST['email'] ?? ''));

function login_public_error_message(Throwable $e): string
{
	if ($e instanceof PDOException) {
		return 'Database connection/query failed. Please check database host, user, password, and schema.';
	}
	return 'Login failed due to a server-side error. Please contact support.';
}

try {
	if (user()) {
		header('Location:/client');
		exit;
	}
} catch (Throwable $e) {
	error_log('FoxNetwork login pre-check failed: ' . $e->getMessage());
	$_SESSION = [];
	if (session_status() === PHP_SESSION_ACTIVE) {
		@session_regenerate_id(true);
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	try {
		verify_csrf();
		$s = db()->prepare('SELECT * FROM users WHERE email=?');
		$s->execute([$email]);
		$u = $s->fetch();
		$pass = $u && password_verify($_POST['password'] ?? '', $u['password_hash']);
		if ($pass) {
			if ((int)($u['two_factor_enabled'] ?? 0) === 1) {
				$_SESSION['2fa_pending_uid'] = $u['id'];
				$_SESSION['2fa_pending_email'] = $email;
				header('Location:/two-factor.php');
				exit;
			}
			session_regenerate_id(true);
			$_SESSION['uid'] = $u['id'];
			security_log_login((int)$u['id'], $email, true);
			security_touch_session((int)$u['id']);
			try {
				link_existing_ptero_user_for_local_user($u);
			} catch (Throwable $e) {
			}
			try {
				auto_setup_ptero_client_key_for_local_user($u);
			} catch (Throwable $e) {
			}
			// Legacy databases may not have these columns yet; login should still succeed.
			try {
				db()->prepare('UPDATE users SET last_login_at=NOW(),last_login_ip=? WHERE id=?')->execute([$_SERVER['REMOTE_ADDR'] ?? null, $u['id']]);
			} catch (Throwable $e) {
			}
			header('Location:/client');
			exit;
		}
		security_log_login($u ? (int)$u['id'] : null, $email, false);
		$error = 'Invalid email or password.';
	} catch (Throwable $e) {
		error_log('FoxNetwork login POST failed: ' . $e->getMessage());
		$error = login_public_error_message($e);
	}
}
?>
<!doctype html>
<html>

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width">
	<title>FoxNetwork Login</title>
	<link rel="stylesheet" href="/assets/portal.css?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/assets/portal.css')) ?>">
</head>

<body>
	<div class="auth">
		<form class="authbox" method="post"><img src="/images/logo.png">
			<h1>Welcome back.</h1>
			<p class="muted">Sign in to FoxNetwork Control Center.</p><?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif ?><input type="hidden" name="csrf" value="<?= csrf() ?>">
			<div class="field"><label>Email</label><input type="email" name="email" required value="<?= e($email) ?>"></div>
			<div class="field"><label>Password</label><input type="password" name="password" required></div><button class="btn primary wide">Sign in</button>
			<p><a class="link" href="/forgot-password.php">Forgot password?</a></p>
			<p><a class="link" href="/register.php">Create a new account</a></p>
		</form>
	</div>
</body>

</html>