<?php
declare(strict_types=1);
function telnyx_secret(string $key):string{$v=(string)setting('telnyx_'.$key,'');if(str_starts_with($v,'enc:'))$v=(string)(dec(substr($v,4))??'');return $v==='__EMPTY__'?'':trim($v);}
function telnyx_enabled():bool{return setting('telnyx_enabled','0')==='1'&&telnyx_secret('api_key')!=='';}
function messaging_phone(string $phone):string{$phone=preg_replace('/[\s().-]+/','',trim($phone));if(!preg_match('/^\+[1-9]\d{7,14}$/',$phone))throw new RuntimeException('Use an international E.164 phone number, for example +32470123456.');return $phone;}
function telnyx_request(string $path,array $payload):array{if(!telnyx_enabled())throw new RuntimeException('Telnyx messaging is not configured.');$ch=curl_init('https://api.telnyx.com/v2'.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>['Authorization: Bearer '.telnyx_secret('api_key'),'Accept: application/json','Content-Type: application/json'],CURLOPT_TIMEOUT=>15]);$raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);$json=json_decode((string)$raw,true)?:[];if($raw===false||$code<200||$code>=300)throw new RuntimeException((string)($json['errors'][0]['detail']??$json['errors'][0]['title']??$error?:('Telnyx HTTP '.$code)));return $json;}
function messaging_verify_start(string $phone):void{$profile=trim((string)setting('telnyx_verify_profile_id',''));if(!preg_match('/^[a-f0-9-]{36}$/i',$profile))throw new RuntimeException('Telnyx Verify Profile ID is not configured.');telnyx_request('/verifications/whatsapp',['phone_number'=>messaging_phone($phone),'verify_profile_id'=>$profile,'timeout_secs'=>300]);}
function messaging_verify_check(string $phone,string $code):bool{$profile=trim((string)setting('telnyx_verify_profile_id',''));if(!preg_match('/^[a-f0-9-]{36}$/i',$profile))return false;$r=telnyx_request('/verifications/by_phone_number/'.rawurlencode(messaging_phone($phone)).'/actions/verify',['code'=>preg_replace('/\D/','',$code),'verify_profile_id'=>$profile]);return($r['data']['response_code']??'')==='accepted';}
function messaging_send_notification(string $phone,string $body):bool{if(!telnyx_enabled()||setting('messaging_notifications_enabled','0')!=='1')return false;$from=trim((string)setting('telnyx_whatsapp_from',''));if($from==='')return false;$text=mb_substr(strip_tags($body),0,1000);telnyx_request('/messages/whatsapp',['from'=>messaging_phone($from),'to'=>messaging_phone($phone),'whatsapp_message'=>['type'=>'text','text'=>['body'=>$text,'preview_url'=>false]]]);return true;}

function sms_gateway_messages_file():string{return dirname(__DIR__).'/sms/data/messages.json';}
function sms_gateway_enabled():bool{$file=sms_gateway_messages_file();return is_file($file)&&is_writable($file);}
function sms_gateway_queue(string $phone,string $body):int{
    $phone=messaging_phone($phone);$file=sms_gateway_messages_file();
    if(!sms_gateway_enabled())throw new RuntimeException('The SMS gateway queue is not writable.');
    $handle=fopen($file,'c+');if(!$handle)throw new RuntimeException('Could not open the SMS gateway queue.');
    try{
        if(!flock($handle,LOCK_EX))throw new RuntimeException('Could not lock the SMS gateway queue.');
        rewind($handle);$raw=stream_get_contents($handle);$messages=json_decode((string)$raw,true);if(!is_array($messages))$messages=[];
        $ids=array_map('intval',array_column($messages,'id'));$id=$ids?max($ids)+1:1;$now=date('c');
        $messages[]=['to'=>$phone,'body'=>$body,'status'=>'queued','created_at'=>$now,'updated_at'=>$now,'id'=>$id];
        $json=json_encode($messages,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);if($json===false)throw new RuntimeException('Could not encode the SMS queue.');
        rewind($handle);if(!ftruncate($handle,0)||fwrite($handle,$json)===false||!fflush($handle))throw new RuntimeException('Could not write the SMS gateway queue.');
        flock($handle,LOCK_UN);return $id;
    }finally{fclose($handle);}
}
function sms_verify_start(string $phone):void{
    $phone=messaging_phone($phone);$code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
    sms_gateway_queue($phone,'Your FoxNetwork verification code is '.$code.'. It expires in 5 minutes.');
    $_SESSION['sms_2fa']=['phone'=>hash('sha256',$phone),'code'=>password_hash($code,PASSWORD_DEFAULT),'expires'=>time()+300,'attempts'=>0];
}
function sms_verify_check(string $phone,string $code):bool{
    $pending=$_SESSION['sms_2fa']??null;if(!is_array($pending))return false;
    if(($pending['expires']??0)<time()){unset($_SESSION['sms_2fa']);return false;}
    if(!hash_equals((string)($pending['phone']??''),hash('sha256',messaging_phone($phone))))return false;
    $attempts=(int)($pending['attempts']??0)+1;$_SESSION['sms_2fa']['attempts']=$attempts;
    if($attempts>5){unset($_SESSION['sms_2fa']);return false;}
    $valid=preg_match('/^\d{6}$/',$code)===1&&password_verify($code,(string)($pending['code']??''));
    if($valid)unset($_SESSION['sms_2fa']);return $valid;
}
