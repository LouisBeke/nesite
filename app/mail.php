<?php
function &setting_request_cache():array{static $cache=[];return $cache;}
function setting(string $key, $default=null){$cache=&setting_request_cache();$cacheKey='setting:'.$key;if(!array_key_exists($cacheKey,$cache)){if(function_exists('fox_setting_cache_get')){$cached=fox_setting_cache_get($cacheKey,30);if($cached!==null){$cache[$cacheKey]=$cached==='__NULL__'?$default:(string)$cached;return $cache[$cacheKey];}}try{$q=db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();$cache[$cacheKey]=$v!==false?$v:$default;if(function_exists('fox_setting_cache_set'))fox_setting_cache_set($cacheKey,$cache[$cacheKey]===null?'__NULL__':$cache[$cacheKey],30);}catch(Throwable $e){$cache[$cacheKey]=$default;}}return $cache[$cacheKey];}
function save_setting(string $key,string $value):void{$q=db()->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute([$key,$value]);$cache=&setting_request_cache();$cache['setting:'.$key]=$value;if(function_exists('fox_setting_cache_set'))fox_setting_cache_set('setting:'.$key,$value,30);}

function microsoft_mail_config():array{
    $config=cfg('microsoft');
    if(!is_array($config))$config=[];
    foreach(['tenant_id','client_id','mail_from'] as $key){
        $stored=(string)setting('m365_'.$key,'');
        if($stored!=='')$config[$key]=$stored;
    }
    $storedSecret=(string)setting('m365_client_secret','');
    if($storedSecret!=='')$config['client_secret']=str_starts_with($storedSecret,'enc:')?(string)(dec(substr($storedSecret,4))??''):$storedSecret;
    return $config;
}
function mail_provider():string{return 'm365';}
function m365_token():string{
    static $token=null,$expires=0;
    if($token!==null&&time()<$expires-60)return $token;
    $config=microsoft_mail_config();
    $tenant=trim((string)($config['tenant_id']??''));
    $client=trim((string)($config['client_id']??''));
    $secret=(string)($config['client_secret']??'');
    $missing=[];
    foreach(['Tenant ID'=>$tenant,'Client ID'=>$client,'Client secret'=>$secret] as $label=>$value)if(trim($value)==='')$missing[]=$label;
    if($missing)throw new RuntimeException('Microsoft 365 is not configured. Missing: '.implode(', ',$missing).'. Open Admin > Settings > Mail, enter the credentials, and select Save & test Microsoft 365.');
    $ch=curl_init('https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/v2.0/token');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>20,CURLOPT_POSTFIELDS=>http_build_query(['client_id'=>$client,'client_secret'=>$secret,'scope'=>'https://graph.microsoft.com/.default','grant_type'=>'client_credentials']),CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($raw===false)throw new RuntimeException('Microsoft token request failed: '.$error);
    $response=json_decode($raw,true)?:[];
    if($code<200||$code>=300||empty($response['access_token']))throw new RuntimeException('Microsoft token error: '.($response['error_description']??$response['error']??('HTTP '.$code)));
    $token=(string)$response['access_token'];$expires=time()+(int)($response['expires_in']??3600);return $token;
}
function m365_test_connection():void{m365_token();}
function m365_send(string $to,string $subject,string $html):void{
    $config=microsoft_mail_config();
    $sender=trim((string)($config['mail_from']??''));
    if($sender===''||!filter_var($sender,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Microsoft 365 sender address is not configured.');
    $payload=['message'=>['subject'=>$subject,'body'=>['contentType'=>'HTML','content'=>$html],'toRecipients'=>[['emailAddress'=>['address'=>$to]]]],'saveToSentItems'=>true];
    $ch=curl_init('https://graph.microsoft.com/v1.0/users/'.rawurlencode($sender).'/sendMail');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.m365_token(),'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($raw===false)throw new RuntimeException('Microsoft Graph sendMail failed: '.$error);
    if($code<200||$code>=300){$response=json_decode($raw,true)?:[];throw new RuntimeException('Microsoft Graph HTTP '.$code.': '.($response['error']['message']??$raw));}
}
function mail_test_connection():void{m365_test_connection();}
function portal_mail_send(string $to,string $subject,string $html):void{m365_send($to,$subject,$html);}

function email_template(string $key):?array{$q=db()->prepare('SELECT * FROM email_templates WHERE template_key=?');$q->execute([$key]);$r=$q->fetch();return $r?:null;}
function render_tokens(string $text,array $vars):string{foreach($vars as $k=>$v)$text=str_replace('{{'.$k.'}}',(string)$v,$text);return $text;}
function branded_email(string $body):string{$name=e((string)setting('company_name','FoxNetwork'));return '<!doctype html><html><body style="margin:0;background:#0d0f12;font-family:Arial,sans-serif;color:#f4f4f4"><div style="max-width:640px;margin:0 auto;padding:32px 18px"><div style="font-size:25px;font-weight:800;margin-bottom:24px">FOX<span style="color:#ff7417">NETWORK</span></div><div style="background:#15181d;border:1px solid #2a2e35;border-radius:14px;padding:28px;line-height:1.55">'.$body.'</div><p style="color:#8e96a2;font-size:12px;margin-top:20px">'.$name.' · '.e((string)setting('support_email','info@foxnetwork.be')).'</p></div></body></html>';}
function email_tracking_enabled():bool{return setting('email_tracking_enabled','1')==='1';}
function email_tracking_encode(string $value):string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
function email_tracking_decode(string $value):?string{$padding=strlen($value)%4;if($padding)$value.=str_repeat('=',4-$padding);$raw=base64_decode(strtr($value,'-_','+/'),true);return $raw===false?null:$raw;}
function email_tracking_signature(string $token,string $encodedUrl):string{return hash_hmac('sha256',$token.'|'.$encodedUrl,hash('sha256',(string)cfg('db.pass').'|email-tracking',true));}
function email_tracking_request_fingerprint():array{
    $forwarded=trim(explode(',',(string)($_SERVER['HTTP_CF_CONNECTING_IP']??$_SERVER['HTTP_X_FORWARDED_FOR']??''))[0]);
    $ip=$forwarded!==''?$forwarded:(string)($_SERVER['REMOTE_ADDR']??'');
    return ['ip_hash'=>$ip!==''?hash_hmac('sha256',$ip,hash('sha256',(string)cfg('db.pass').'|email-privacy',true)):null,'user_agent'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)];
}
function email_tracking_prepare(string $html,string $token):string{
    if($token==='')return $html;
    $html=preg_replace_callback('/href\s*=\s*(["\'])(.*?)\1/i',static function(array $match)use($token):string{
        $url=html_entity_decode(trim($match[2]),ENT_QUOTES,'UTF-8');
        if(str_starts_with($url,'/'))$url=site_url($url);
        if(!preg_match('#^https?://#i',$url)||str_contains($url,'/email-click.php'))return $match[0];
        $encoded=email_tracking_encode($url);$signature=email_tracking_signature($token,$encoded);
        $tracked=site_url('/email-click.php?t='.rawurlencode($token).'&u='.rawurlencode($encoded).'&s='.rawurlencode($signature));
        return 'href='.$match[1].htmlspecialchars($tracked,ENT_QUOTES,'UTF-8').$match[1];
    },$html)??$html;
    $pixel='<img src="'.e(site_url('/email-track.php?t='.rawurlencode($token))).'" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;opacity:0" aria-hidden="true">';
    if(stripos($html,'</body>')!==false)return preg_replace('/<\/body>/i',$pixel.'</body>',$html,1)??($html.$pixel);
    return $html.$pixel;
}
function email_log_create(?int $userId,string $to,string $subject,?string $templateKey):array{
    $token=email_tracking_enabled()?bin2hex(random_bytes(32)):null;
    $q=db()->prepare("INSERT INTO email_log(user_id,recipient,subject,template_key,status,tracking_token) VALUES(?,?,?,?,'pending',?)");
    $q->execute([$userId,$to,$subject,$templateKey,$token]);
    return ['id'=>(int)db()->lastInsertId(),'token'=>(string)$token];
}
function email_send_logged(?int $userId,string $to,string $subject,string $html,?string $templateKey=null,bool $throw=false):bool{
    $log=email_log_create($userId,$to,$subject,$templateKey);$error=null;
    try{portal_mail_send($to,$subject,email_tracking_prepare($html,$log['token']));db()->prepare("UPDATE email_log SET status='sent',sent_at=NOW(),error_message=NULL WHERE id=?")->execute([$log['id']]);}
    catch(Throwable $e){$error=$e;db()->prepare("UPDATE email_log SET status='failed',error_message=? WHERE id=?")->execute([$e->getMessage(),$log['id']]);}
    if($error&&$throw)throw new RuntimeException($error->getMessage(),0,$error);
    return $error===null;
}
function send_template(string $key,array $recipient,array $vars=[]):bool{
    $tpl=email_template($key);if(!$tpl||!(int)$tpl['enabled'])return false;
    $userId=(int)($recipient['user_id']??$recipient['id']??0);
    if($userId>0&&!array_key_exists('notify_whatsapp',$recipient)){try{$q=db()->prepare('SELECT phone,notify_whatsapp,email_notifications FROM users WHERE id=?');$q->execute([$userId]);$preferences=$q->fetch();if($preferences)$recipient=array_merge($preferences,$recipient);}catch(Throwable $e){}}
    $vars+=['customer_name'=>$recipient['name']??'Customer','portal_url'=>rtrim((string)cfg('app_url'),'/').'/','billing_url'=>rtrim((string)cfg('app_url'),'/').'/billing.php'];
    $subject=render_tokens($tpl['subject'],$vars);$body=render_tokens($tpl['body_html'],$vars);$sent=false;
    if(!empty($recipient['email'])&&(!isset($recipient['email_notifications'])||(int)$recipient['email_notifications']))$sent=email_send_logged($userId?:null,(string)$recipient['email'],$subject,branded_email($body),$key,false);
    $phone=(string)($recipient['phone']??'');
    if($phone!==''&&telnyx_enabled()&&!empty($recipient['notify_whatsapp'])){try{$sent=messaging_send_notification($phone,$subject)||$sent;}catch(Throwable $e){error_log('Telnyx WhatsApp notification failed: '.$e->getMessage());}}
    return $sent;
}
function send_custom_email(?int $userId,string $to,string $subject,string $html):bool{return email_send_logged($userId,$to,$subject,branded_email($html),null,true);}
function ticket_notify_staff(int $ticketId,string $event='new'):void{
    try{$q=db()->prepare('SELECT t.subject,t.priority,u.name,u.email,(SELECT message FROM support_messages WHERE ticket_id=t.id AND is_internal=0 ORDER BY id DESC LIMIT 1) latest_message FROM support_tickets t JOIN users u ON u.id=t.user_id WHERE t.id=?');$q->execute([$ticketId]);$t=$q->fetch();if(!$t)return;$to=trim((string)setting('ticket_notification_email',''));if($to==='')$to=trim((string)setting('support_email','info@foxnetwork.be'));if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Ticket notification email is not configured.');$url=site_url('/admin/support.php?id='.$ticketId);$title=$event==='new'?'New support ticket':'Customer replied to ticket';$message=nl2br(e(mb_substr((string)($t['latest_message']??''),0,2000)));$html='<h2>'.e($title).'</h2><p><b>#'.e($ticketId).' '.e($t['subject']).'</b></p><p>Customer: '.e($t['name']).' ('.e($t['email']).')<br>Priority: '.e(ucfirst((string)$t['priority'])).'</p><div style="margin:18px 0;padding:14px;border-left:3px solid #ff7417;background:#101216">'.$message.'</div><p><a href="'.e($url).'">Open ticket</a></p>';send_custom_email(null,$to,$title.' #'.$ticketId.': '.$t['subject'],$html);}catch(Throwable $e){error_log('Ticket staff email failed: '.$e->getMessage());}
}
function ticket_notify_customer(int $ticketId,string $event='reply'):void{
    try{$q=db()->prepare('SELECT t.subject,u.id,u.name,u.email,u.email_notifications FROM support_tickets t JOIN users u ON u.id=t.user_id WHERE t.id=?');$q->execute([$ticketId]);$t=$q->fetch();if(!$t||empty($t['email_notifications']))return;if($event==='closed'&&setting('ticket_closed_email_enabled','1')!=='1')return;$url=site_url('/support.php?id='.$ticketId);$created=$event==='created';$closed=$event==='closed';$heading=$created?'Your support ticket was received':($closed?'Your support ticket was closed':'We replied to your ticket');$copy=$created?'Our support team received your request.':($closed?'Your support ticket has been marked as resolved and closed. You can still view the conversation in your customer portal.':'There is a new staff reply on your ticket.');$subject=$created?'Ticket received #':($closed?'Ticket closed #':'New reply on ticket #');$html='<h2>'.e($heading).'</h2><p>Hello '.e($t['name']).',</p><p>'.e($copy).' <b>#'.e($ticketId).' '.e($t['subject']).'</b></p><p><a href="'.e($url).'">View ticket</a></p>';send_custom_email((int)$t['id'],(string)$t['email'],$subject.$ticketId,$html);}catch(Throwable $e){error_log('Ticket customer email failed: '.$e->getMessage());}
}
function email_verification_send(array $user): bool {
    $id=(int)($user['id']??0);$email=strtolower(trim((string)($user['email']??'')));
    if($id<=0||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('A valid customer email address is required.');
    $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
    db()->prepare('UPDATE users SET email_verification_token_hash=?,email_verification_expires_at=DATE_ADD(NOW(),INTERVAL 24 HOUR) WHERE id=?')->execute([$hash,$id]);
    $url=site_url('/verify-email.php?token='.rawurlencode($token));
    $name=e((string)($user['name']??'Customer'));
    $html='<h2>Verify your email address</h2><p>Hello '.$name.',</p><p>Confirm this email address to activate your FoxNetwork customer account.</p><p><a href="'.e($url).'" style="display:inline-block;padding:12px 18px;border-radius:8px;background:#ff7417;color:#fff;text-decoration:none;font-weight:700">Verify email address</a></p><p style="color:#8e96a2;font-size:13px">This link expires in 24 hours. If you did not request this account, you can ignore this email.</p>';
    return send_custom_email($id,$email,'Verify your FoxNetwork email address',$html);
}
