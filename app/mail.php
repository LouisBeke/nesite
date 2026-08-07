<?php
function setting(string $key, $default=null){static $cache=[];$cacheKey='setting:'.$key;if(!array_key_exists($cacheKey,$cache)){if(function_exists('fox_setting_cache_get')){$cached=fox_setting_cache_get($cacheKey,30);if($cached!==null){$cache[$cacheKey]=$cached==='__NULL__'?$default:(string)$cached;return $cache[$cacheKey];}}try{$q=db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();$cache[$cacheKey]=$v!==false?$v:$default;if(function_exists('fox_setting_cache_set'))fox_setting_cache_set($cacheKey,$cache[$cacheKey]===null?'__NULL__':$cache[$cacheKey],30);}catch(Throwable $e){$cache[$cacheKey]=$default;}}return $cache[$cacheKey];}
function save_setting(string $key,string $value):void{$q=db()->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute([$key,$value]);if(function_exists('fox_setting_cache_set'))fox_setting_cache_set('setting:'.$key,$value,30);}

function smtp_read($fp):string{$out='';while(($line=fgets($fp,515))!==false){$out.=$line;if(strlen($line)<4||$line[3]===' ')break;}return $out;}
function smtp_cmd($fp,string $cmd,array $ok):string{fwrite($fp,$cmd."\r\n");$r=smtp_read($fp);$c=(int)substr($r,0,3);if(!in_array($c,$ok,true))throw new RuntimeException(trim($r));return $r;}
function smtp_password():string{$v=(string)setting('smtp_password','');if(str_starts_with($v,'enc:'))return (string)(dec(substr($v,4))??'');return $v;}
function smtp_open_authenticated(){
    $host=trim((string)setting('smtp_host','smtppro.zoho.eu'));
    $port=(int)setting('smtp_port','587');
    $sec=(string)setting('smtp_security','tls');
    $user=trim((string)setting('smtp_username',''));
    $pass=smtp_password();
    if($host===''||$user===''||$pass==='')throw new RuntimeException('Zoho Mail SMTP is not configured in Admin > Settings.');
    $target=($sec==='ssl'?'ssl://':'').$host;
    $fp=@fsockopen($target,$port,$errno,$errstr,15);
    if(!$fp)throw new RuntimeException("SMTP connection failed: $errstr ($errno)");
    stream_set_timeout($fp,15);
    $g=smtp_read($fp);
    if((int)substr($g,0,3)!==220)throw new RuntimeException(trim($g));
    smtp_cmd($fp,'EHLO '.($_SERVER['SERVER_NAME']??'foxnetwork.be'),[250]);
    if($sec==='tls'){
        smtp_cmd($fp,'STARTTLS',[220]);
        if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('SMTP TLS negotiation failed.');
        smtp_cmd($fp,'EHLO '.($_SERVER['SERVER_NAME']??'foxnetwork.be'),[250]);
    }
    smtp_cmd($fp,'AUTH LOGIN',[334]);
    smtp_cmd($fp,base64_encode($user),[334]);
    smtp_cmd($fp,base64_encode($pass),[235]);
    return $fp;
}
function smtp_close($fp):void{try{smtp_cmd($fp,'QUIT',[221]);}catch(Throwable $e){}fclose($fp);}
function smtp_test_connection():void{$fp=smtp_open_authenticated();smtp_close($fp);}
function smtp_send(string $to,string $subject,string $html):void{
    $from=trim((string)setting('smtp_from_email','info@foxnetwork.be'));
    $fromName=(string)setting('smtp_from_name','FoxNetwork');
    if($from==='')throw new RuntimeException('Zoho Mail sender address is not configured in Admin > Settings.');
    $fp=smtp_open_authenticated();
    try{
        smtp_cmd($fp,'MAIL FROM:<'.$from.'>',[250]);
        smtp_cmd($fp,'RCPT TO:<'.$to.'>',[250,251]);
        smtp_cmd($fp,'DATA',[354]);
        $headers=['From: '.$fromName.' <'.$from.'>','To: <'.$to.'>','Subject: =?UTF-8?B?'.base64_encode($subject).'?=','MIME-Version: 1.0','Content-Type: text/html; charset=UTF-8','Content-Transfer-Encoding: 8bit'];
        $msg=implode("\r\n",$headers)."\r\n\r\n".$html;
        $msg=preg_replace('/(?m)^\./','..',$msg);
        fwrite($fp,$msg."\r\n.\r\n");
        $r=smtp_read($fp);
        if((int)substr($r,0,3)!==250)throw new RuntimeException(trim($r));
    }finally{
        smtp_close($fp);
    }
}

function mail_provider():string{return 'zoho';}
function zoho_test_connection():void{smtp_test_connection();}
function portal_mail_send(string $to,string $subject,string $html):void{smtp_send($to,$subject,$html);}

function email_template(string $key):?array{$q=db()->prepare('SELECT * FROM email_templates WHERE template_key=?');$q->execute([$key]);$r=$q->fetch();return $r?:null;}
function render_tokens(string $text,array $vars):string{foreach($vars as $k=>$v)$text=str_replace('{{'.$k.'}}',(string)$v,$text);return $text;}
function branded_email(string $body):string{$name=e((string)setting('company_name','FoxNetwork'));return '<!doctype html><html><body style="margin:0;background:#0d0f12;font-family:Arial,sans-serif;color:#f4f4f4"><div style="max-width:640px;margin:0 auto;padding:32px 18px"><div style="font-size:25px;font-weight:800;margin-bottom:24px">FOX<span style="color:#ff7417">NETWORK</span></div><div style="background:#15181d;border:1px solid #2a2e35;border-radius:14px;padding:28px;line-height:1.55">'.$body.'</div><p style="color:#8e96a2;font-size:12px;margin-top:20px">'.$name.' · '.e((string)setting('support_email','info@foxnetwork.be')).'</p></div></body></html>';}
function send_template(string $key,array $recipient,array $vars=[]):bool{$tpl=email_template($key);if(!$tpl||!(int)$tpl['enabled']||empty($recipient['email']))return false;if(isset($recipient['email_notifications'])&&!(int)$recipient['email_notifications'])return false;$vars+=['customer_name'=>$recipient['name']??'Customer','portal_url'=>rtrim((string)cfg('app_url'),'/').'/','billing_url'=>rtrim((string)cfg('app_url'),'/').'/billing.php'];$subject=render_tokens($tpl['subject'],$vars);$html=branded_email(render_tokens($tpl['body_html'],$vars));$status='sent';$err=null;try{portal_mail_send($recipient['email'],$subject,$html);}catch(Throwable $e){$status='failed';$err=$e->getMessage();}$q=db()->prepare('INSERT INTO email_log(user_id,recipient,subject,template_key,status,error_message) VALUES(?,?,?,?,?,?)');$q->execute([$recipient['id']??null,$recipient['email'],$subject,$key,$status,$err]);return $status==='sent';}
function send_custom_email(?int $userId,string $to,string $subject,string $html):bool{$status='sent';$err=null;try{portal_mail_send($to,$subject,branded_email($html));}catch(Throwable $e){$status='failed';$err=$e->getMessage();}$q=db()->prepare('INSERT INTO email_log(user_id,recipient,subject,status,error_message) VALUES(?,?,?,?,?)');$q->execute([$userId,$to,$subject,$status,$err]);if($err)throw new RuntimeException($err);return true;}
