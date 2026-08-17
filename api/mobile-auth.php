<?php
require __DIR__.'/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function mobile_out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function mobile_login_complete(array $user): never {
    security_clear_two_factor_challenge();
    session_regenerate_id(true);
    $_SESSION['uid']=(int)$user['id'];
    $_SESSION['mobile_api_session']=true;
    security_log_login((int)$user['id'],(string)$user['email'],true);
    security_touch_session((int)$user['id']);
    try{link_existing_ptero_user_for_local_user($user);auto_setup_ptero_client_key_for_local_user($user);}catch(Throwable $e){}
    try{db()->prepare('UPDATE users SET last_login_at=NOW(),last_login_ip=? WHERE id=?')->execute([$_SERVER['REMOTE_ADDR']??null,$user['id']]);}catch(Throwable $e){}
    mobile_out(['ok'=>true,'token'=>session_id(),'user'=>['id'=>(int)$user['id'],'name'=>(string)($user['name']??''),'email'=>(string)($user['email']??''),'role'=>(string)($user['role']??'customer')]]);
}

if($_SERVER['REQUEST_METHOD']!=='POST')mobile_out(['ok'=>false,'error'=>'Method not allowed'],405);
$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input))mobile_out(['ok'=>false,'error'=>'Invalid JSON body'],422);

$challenge=security_two_factor_challenge();
$code=strtoupper(trim((string)($input['two_factor_code']??'')));
$action=(string)($input['action']??'verify');
if($challenge){
    $q=db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$q->execute([(int)$challenge['uid']]);$user=$q->fetch();
    if(!$user||($user['account_status']??'active')==='disabled'||(int)($user['two_factor_enabled']??0)!==1){security_clear_two_factor_challenge();mobile_out(['ok'=>false,'error'=>'Two-factor challenge is no longer valid'],401);}
    $method=(string)($user['two_factor_method']??'totp');
    if($action==='resend'&&in_array($method,['sms','whatsapp'],true)){
        $last=(int)($_SESSION['2fa_message_sent_at']??0);if(time()-$last<60)mobile_out(['ok'=>false,'error'=>'Wait one minute before requesting another code'],429);
        try{if($method==='sms')sms_verify_start((string)($user['phone']??''));else messaging_verify_start((string)($user['phone']??''));$_SESSION['2fa_message_sent_at']=time();mobile_out(['ok'=>true,'two_factor_required'=>true,'challenge_token'=>session_id(),'method'=>$method,'expires_in'=>max(0,(int)$challenge['expires_at']-time())]);}catch(Throwable $e){mobile_out(['ok'=>false,'error'=>'Could not send a new code. A recovery code can still be used.'],503);}
    }
    if($code==='')mobile_out(['ok'=>false,'two_factor_required'=>true,'challenge_token'=>session_id(),'method'=>$method,'expires_in'=>max(0,(int)$challenge['expires_at']-time()),'error'=>'Two-factor code is required'],401);
    if(!security_two_factor_attempt_allowed()){security_log_event((int)$user['id'],'two_factor_locked',false,'Mobile challenge exceeded its attempt limit.');security_clear_two_factor_challenge();mobile_out(['ok'=>false,'error'=>'Too many incorrect codes. Sign in again.'],429);}
    $user['two_factor_recovery_codes']=security_recovery_upgrade((int)$user['id'],$user['two_factor_recovery_codes']??null);
    $valid=false;$usedRecovery=false;
    if($method==='totp')$valid=totp_verify_and_consume((int)$user['id'],(string)(dec($user['two_factor_secret']??null)??''),$code);
    elseif($method==='sms'){try{$valid=sms_verify_check((string)($user['phone']??''),$code);}catch(Throwable $e){}}
    elseif($method==='whatsapp'){try{$valid=messaging_verify_check((string)($user['phone']??''),$code);}catch(Throwable $e){}}
    if(!$valid){$usedRecovery=security_recovery_consume((int)$user['id'],$user['two_factor_recovery_codes']??null,$code);$valid=$usedRecovery;}
    if($valid){security_log_event((int)$user['id'],$usedRecovery?'recovery_code_used':'two_factor_verified',true,$usedRecovery?'A recovery code was used for mobile sign-in.':ucfirst($method).' mobile challenge completed.');mobile_login_complete($user);}
    security_log_event((int)$user['id'],'two_factor_failed',false,'An incorrect mobile '.$method.' or recovery code was submitted.');
    $attempts=(int)($_SESSION['2fa_challenge']['attempts']??0);if($attempts>=5){security_clear_two_factor_challenge();mobile_out(['ok'=>false,'error'=>'Too many incorrect codes. Sign in again.'],429);}
    mobile_out(['ok'=>false,'two_factor_required'=>true,'challenge_token'=>session_id(),'method'=>$method,'attempts_remaining'=>5-$attempts,'error'=>'Invalid authentication or recovery code'],401);
}

if($code!=='')mobile_out(['ok'=>false,'error'=>'Two-factor challenge expired. Sign in again.'],401);
$email=strtolower(trim((string)($input['email']??'')));$password=(string)($input['password']??'');
if($email===''||$password==='')mobile_out(['ok'=>false,'error'=>'Email and password are required'],422);
if(security_login_rate_limited($email))mobile_out(['ok'=>false,'error'=>'Too many sign-in attempts. Wait 15 minutes and try again.'],429);
$statement=db()->prepare('SELECT * FROM users WHERE lower(email)=? LIMIT 1');$statement->execute([$email]);$user=$statement->fetch();
if(!$user||!password_verify($password,(string)($user['password_hash']??''))){security_log_login($user?(int)$user['id']:null,$email,false);mobile_out(['ok'=>false,'error'=>'Invalid email or password'],401);}
if(($user['account_status']??'active')==='disabled')mobile_out(['ok'=>false,'error'=>'This account has been disabled.'],403);
if(($user['role']??'customer')==='customer'&&empty($user['email_verified_at']))mobile_out(['ok'=>false,'error'=>'Verify your email address before signing in.'],403);
if(password_needs_rehash((string)$user['password_hash'],PASSWORD_DEFAULT))db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$user['id']]);
if((int)($user['two_factor_enabled']??0)===1){
    if(security_two_factor_rate_limited((int)$user['id']))mobile_out(['ok'=>false,'error'=>'Too many two-factor attempts. Wait 15 minutes and try again.'],429);
    security_begin_two_factor_challenge($user);$method=(string)($user['two_factor_method']??'totp');
    if(in_array($method,['sms','whatsapp'],true)){try{if($method==='sms')sms_verify_start((string)($user['phone']??''));else messaging_verify_start((string)($user['phone']??''));$_SESSION['2fa_message_sent_at']=time();}catch(Throwable $e){}}
    mobile_out(['ok'=>false,'two_factor_required'=>true,'challenge_token'=>session_id(),'method'=>$method,'expires_in'=>600,'recovery_codes_remaining'=>security_recovery_count($user['two_factor_recovery_codes']??null)],401);
}
mobile_login_complete($user);
