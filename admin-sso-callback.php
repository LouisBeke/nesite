<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
try{
    if(isset($_GET['error']))throw new RuntimeException('Zoho denied sign-in: '.(string)($_GET['error_description']??$_GET['error']));
    $admin=zoho_sso_complete((string)($_GET['code']??''),(string)($_GET['state']??''));session_regenerate_id(true);$_SESSION['uid']=(int)$admin['id'];
    security_log_login((int)$admin['id'],(string)$admin['email'],true);security_touch_session((int)$admin['id']);try{db()->prepare('UPDATE users SET last_login_at=NOW(),last_login_ip=? WHERE id=?')->execute([$_SERVER['REMOTE_ADDR']??null,$admin['id']]);}catch(Throwable $e){}
    audit_log('admin.sso.login','user',(int)$admin['id'],'Admin signed in through Zoho Directory.');header('Location: /admin/');exit;
}catch(Throwable $e){error_log('Zoho admin SSO callback failed: '.$e->getMessage());header('Location: /login.php?sso_error='.rawurlencode($e->getMessage()));exit;}
