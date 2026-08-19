<?php
require __DIR__ . '/app/bootstrap.php';

$error = trim((string)($_GET['sso_error']??''));
if(!empty($_SESSION['login_security_message'])){$error=(string)$_SESSION['login_security_message'];unset($_SESSION['login_security_message']);}
$email = strtolower(trim($_POST['email'] ?? ''));

final class LoginPublicException extends RuntimeException {}

function fox_complete_password_login(array $u): void
{
	session_regenerate_id(true);
	$_SESSION['uid'] = (int)$u['id'];
	security_log_login((int)$u['id'], (string)$u['email'], true);
	security_touch_session((int)$u['id']);
	try { link_existing_ptero_user_for_local_user($u); } catch (Throwable $e) {}
	try { auto_setup_ptero_client_key_for_local_user($u); } catch (Throwable $e) {}
	try { db()->prepare('UPDATE users SET last_login_at=NOW(),last_login_ip=? WHERE id=?')->execute([$_SERVER['REMOTE_ADDR'] ?? null, $u['id']]); } catch (Throwable $e) {}
	header('Location:/client');
	exit;
}

function login_public_error_message(Throwable $e): string
{
	if ($e instanceof PDOException) {
		return 'Database connection/query failed. Please check database host, user, password, and schema.';
	}
	if ($e instanceof LoginPublicException) return $e->getMessage();
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
		if(security_login_rate_limited($email))throw new LoginPublicException('Too many sign-in attempts. Wait 15 minutes and try again.');
		$s = db()->prepare('SELECT * FROM users WHERE email=?');
		$s->execute([$email]);
		$u = $s->fetch();
		$pass = $u && password_verify($_POST['password'] ?? '', $u['password_hash']);
		if ($pass) {
			if (($u['account_status']??'active')==='disabled') {
				security_log_login((int)$u['id'], $email, false);
				throw new LoginPublicException('This account is disabled. Please contact support.');
			}
			if(password_needs_rehash((string)$u['password_hash'],PASSWORD_DEFAULT)){
				$u['password_hash']=password_hash((string)($_POST['password']??''),PASSWORD_DEFAULT);
				db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$u['password_hash'],$u['id']]);
			}
			if (($u['role']??'customer')==='customer' && empty($u['email_verified_at'])) {
				$_SESSION['email_verification_pending_email']=$email;
				header('Location:/verify-email.php?pending=1');
				exit;
			}
			if ((int)($u['two_factor_enabled'] ?? 0) === 1) {
				$u['two_factor_recovery_codes']=security_recovery_upgrade((int)$u['id'],$u['two_factor_recovery_codes']??null);
				if(security_trusted_device_valid((int)$u['id'])){
					security_log_event((int)$u['id'],'trusted_device_login',true,'Password sign-in approved by a trusted browser.');
					fox_complete_password_login($u);
				}
				if(security_two_factor_rate_limited((int)$u['id']))throw new LoginPublicException('Too many two-factor attempts. Wait 15 minutes and try again.');
				security_begin_two_factor_challenge($u);
				$method=(string)($u['two_factor_method']??'totp');
				if($method==='whatsapp'||$method==='sms'){
					try{if($method==='sms')sms_verify_start((string)($u['phone']??''));else messaging_verify_start((string)($u['phone']??''));$_SESSION['2fa_message_sent_at']=time();}catch(Throwable $e){error_log('2FA message delivery failed: '.$e->getMessage());}
				}
				header('Location:/two-factor.php');
				exit;
			}
			fox_complete_password_login($u);
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
<?= opinly_head() ?>
</head>

<body>
	<div class="auth">
		<form class="authbox" method="post"><img src="/images/logo.png">
			<h1>Welcome back.</h1>
			<p class="muted">Sign in to FoxNetwork Control Center.</p><?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif ?><input type="hidden" name="csrf" value="<?= csrf() ?>">
			<div class="field"><label>Email</label><input type="email" name="email" required value="<?= e($email) ?>"></div>
			<div class="field"><label>Password</label><input type="password" name="password" required></div><button class="btn primary wide">Sign in</button>
			<?php if(zoho_sso_enabled()):?><div style="margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,.12)"><p class="muted" style="text-align:center;margin-top:0">FOXNETWORK ADMIN</p><a class="btn wide" href="/admin-sso.php" aria-label="Admin login with Zoho Directory">Admin login with Zoho Directory</a></div><?php endif?>
			<p><a class="link" href="/forgot-password.php">Forgot password?</a></p>
			<p><a class="link" href="/register.php">Create a new account</a></p>
		</form>
	</div>
</body>

</html>
