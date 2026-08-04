<?php
require __DIR__.'/app/bootstrap.php';
$count=(int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
if($count>0){http_response_code(403);die('Setup locked: a user already exists.');}
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
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
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>FoxNetwork Setup</title><link rel="stylesheet" href="/assets/portal.css?v=4.2"></head><body><div class="auth"><form class="authbox" method="post"><img src="/images/logo.png"><h1>Create administrator</h1><p class="muted">First-time setup for my.foxnetwork.be</p><?php if($msg):?><div class="error"><?=e($msg)?></div><?php endif?><input type="hidden" name="csrf" value="<?=csrf()?>"><div class="field"><label>Name</label><input name="name" required></div><div class="field"><label>Email</label><input type="email" name="email" required></div><div class="field"><label>Password</label><input type="password" name="password" minlength="10" required></div><button class="btn primary wide">Create administrator</button></form></div></body></html>