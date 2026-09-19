<?php
require __DIR__.'/app/bootstrap.php';

function setup_initialize_schema_from_sql(): void {
	$sqlFile = __DIR__ . '/database.sql';
	if (!is_file($sqlFile)) {
		throw new RuntimeException('database.sql not found.');
	}

	$raw = (string)file_get_contents($sqlFile);
	if ($raw === '') {
		throw new RuntimeException('database.sql is empty.');
	}

	// Normalize newer SQL syntax so this can run on older MySQL/MariaDB releases.
	$raw = preg_replace('/CREATE\s+INDEX\s+IF\s+NOT\s+EXISTS\s+/i', 'CREATE INDEX ', $raw);
	$raw = preg_replace('/ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+/i', 'ADD COLUMN ', $raw);

	$lines = preg_split('/\R/', $raw) ?: [];
	$clean = [];
	foreach ($lines as $line) {
		$trimmed = ltrim($line);
		if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;
		$clean[] = $line;
	}
	$script = trim(implode("\n", $clean));
	if ($script === '') {
		throw new RuntimeException('No executable SQL statements found in database.sql.');
	}

	$parts = preg_split('/;\s*(?:\R|$)/', $script) ?: [];
	$pdo = db();
	$ignored = [
		'42S01', // table exists
		'42S21', // column exists
		'42000', // duplicate key / duplicate index on some engines
	];
	foreach ($parts as $stmt) {
		$sql = trim($stmt);
		if ($sql === '') continue;
		try {
			$pdo->exec($sql);
		} catch (PDOException $e) {
			$msg = strtolower((string)$e->getMessage());
			$code = (string)$e->getCode();
			$duplicateLike = strpos($msg, 'already exists') !== false || strpos($msg, 'duplicate column') !== false || strpos($msg, 'duplicate key') !== false;
			if ($duplicateLike || in_array($code, $ignored, true)) {
				continue;
			}
			throw $e;
		}
	}
}

$msg='';
$schemaReady=true;

try {
	$count=(int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
	if($count>0){http_response_code(403);die('Setup locked: a user already exists.');}
} catch (Throwable $e) {
	error_log('FoxNetwork setup precheck failed: '.$e->getMessage());
	try {
		setup_initialize_schema_from_sql();
		$count=(int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
		if($count>0){http_response_code(403);die('Setup locked: a user already exists.');}
		$msg='Schema initialized successfully from database.sql. You can now create the administrator account.';
	} catch (Throwable $schemaError) {
		$schemaReady=false;
		error_log('FoxNetwork setup schema init failed: '.$schemaError->getMessage());
		$msg='Schema initialization failed. Check DB permissions and SQL compatibility, then import database.sql manually.';
	}
}

if($schemaReady && $_SERVER['REQUEST_METHOD']==='POST'){
	try {
		verify_csrf();
		$name=trim($_POST['name']??'');
		$email=strtolower(trim($_POST['email']??''));
		$pw=$_POST['password']??'';
		if($name&&filter_var($email,FILTER_VALIDATE_EMAIL)&&strlen($pw)>=10){
			$s=db()->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'admin')");
			$s->execute([$name,$email,password_hash($pw,PASSWORD_DEFAULT)]);
			$userId=(int)db()->lastInsertId();
			try {
				$pteroUserId = ensure_ptero_user_for_local_user($email, $name, true);
				db()->prepare('UPDATE users SET ptero_user_id=? WHERE id=?')->execute([$pteroUserId,$userId]);
			} catch (Throwable $e) {
			}
			header('Location:/login.php');
			exit;
		}
		$msg='Use a valid email and a password of at least 10 characters.';
	} catch (Throwable $e) {
		error_log('FoxNetwork setup POST failed: '.$e->getMessage());
		$msg='Setup failed due to a database/server error. Check PHP error logs and try again.';
	}
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>FoxNetwork Setup</title><link rel="stylesheet" href="/assets/portal.css?v=<?=rawurlencode((string)@filemtime(__DIR__.'/assets/portal.css'))?>"></head><body><div class="auth"><form class="authbox" method="post"><img src="/images/logo.png"><h1>Create administrator</h1><p class="muted">First-time setup for foxnetwork.be</p><?php if($msg):?><div class="error"><?=e($msg)?></div><?php endif?><input type="hidden" name="csrf" value="<?=csrf()?>"><div class="field"><label>Name</label><input name="name" required <?=!$schemaReady?'disabled':''?>></div><div class="field"><label>Email</label><input type="email" name="email" required <?=!$schemaReady?'disabled':''?>></div><div class="field"><label>Password</label><input type="password" name="password" minlength="10" required <?=!$schemaReady?'disabled':''?>></div><button class="btn primary wide" <?=!$schemaReady?'disabled':''?>>Create administrator</button></form></div><script defer src="/js/marketing-animations.js?v=20260830a"></script></body></html>
