<?php
declare(strict_types=1);

function zoho_sso_setting(string $key,string $default=''): string {
    $value=(string)setting('zoho_sso_'.$key,$default);
    if(str_starts_with($value,'enc:'))return (string)(dec(substr($value,4))??'');
    return $value==='__EMPTY__'?'':trim($value);
}
function zoho_sso_enabled(): bool { return zoho_sso_setting('enabled','0')==='1'; }
function zoho_sso_callback_url(): string { return site_url('/admin-sso-callback.php'); }
function zoho_sso_http(string $url,string $method='GET',array $headers=[],?array $form=null): array {
    $ch=curl_init($url);if($ch===false)throw new RuntimeException('Could not initialize Zoho SSO connection.');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers]);
    if($form!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($form,'','&',PHP_QUERY_RFC3986));
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($raw===false)throw new RuntimeException('Zoho SSO connection failed: '.$error);$json=json_decode((string)$raw,true);
    if($status<200||$status>=300||!is_array($json))throw new RuntimeException('Zoho SSO returned HTTP '.$status.'.');return $json;
}
function zoho_sso_metadata(): array {
    $authorization=zoho_sso_setting('authorization_endpoint');$token=zoho_sso_setting('token_endpoint');$userinfo=zoho_sso_setting('userinfo_endpoint');
    if($authorization!==''&&$token!==''&&$userinfo!=='')return compact('authorization','token','userinfo');
    $discovery=zoho_sso_setting('discovery_url');if($discovery==='')throw new RuntimeException('Configure the Zoho Directory OIDC discovery URL or all three endpoints.');
    $doc=zoho_sso_http($discovery);$authorization=(string)($doc['authorization_endpoint']??'');$token=(string)($doc['token_endpoint']??'');$userinfo=(string)($doc['userinfo_endpoint']??'');
    if($authorization===''||$token===''||$userinfo==='')throw new RuntimeException('Zoho Directory discovery metadata is incomplete.');return compact('authorization','token','userinfo');
}
function zoho_sso_start_url(): string {
    if(!zoho_sso_enabled())throw new RuntimeException('Zoho Directory admin SSO is disabled.');$clientId=zoho_sso_setting('client_id');if($clientId==='')throw new RuntimeException('Zoho Directory SSO client ID is missing.');$meta=zoho_sso_metadata();
    $state=bin2hex(random_bytes(32));$verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');$_SESSION['zoho_sso_state']=$state;$_SESSION['zoho_sso_verifier']=$verifier;$_SESSION['zoho_sso_started_at']=time();
    return $meta['authorization'].'?'.http_build_query(['client_id'=>$clientId,'response_type'=>'code','scope'=>'openid email profile','redirect_uri'=>zoho_sso_callback_url(),'state'=>$state,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'],'','&',PHP_QUERY_RFC3986);
}
function zoho_sso_complete(string $code,string $state): array {
    $expected=(string)($_SESSION['zoho_sso_state']??'');$started=(int)($_SESSION['zoho_sso_started_at']??0);$verifier=(string)($_SESSION['zoho_sso_verifier']??'');unset($_SESSION['zoho_sso_state'],$_SESSION['zoho_sso_started_at'],$_SESSION['zoho_sso_verifier']);
    if($expected===''||!hash_equals($expected,$state)||$started<time()-600||$verifier==='')throw new RuntimeException('The Zoho SSO request expired or has an invalid state.');
    $clientId=zoho_sso_setting('client_id');$secret=zoho_sso_setting('client_secret');if($clientId===''||$secret==='')throw new RuntimeException('Zoho Directory SSO credentials are incomplete.');$meta=zoho_sso_metadata();
    $tokens=zoho_sso_http($meta['token'],'POST',['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],['grant_type'=>'authorization_code','code'=>$code,'client_id'=>$clientId,'client_secret'=>$secret,'redirect_uri'=>zoho_sso_callback_url(),'code_verifier'=>$verifier]);$access=trim((string)($tokens['access_token']??''));if($access==='')throw new RuntimeException('Zoho Directory returned no access token.');
    $profile=zoho_sso_http($meta['userinfo'],'GET',['Authorization: Bearer '.$access,'Accept: application/json']);$email=strtolower(trim((string)($profile['email']??$profile['email_id']??'')));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Zoho Directory returned no verified email address.');
    if(array_key_exists('email_verified',$profile)&&!filter_var($profile['email_verified'],FILTER_VALIDATE_BOOL))throw new RuntimeException('Zoho Directory has not verified this email address.');
    $q=db()->prepare("SELECT * FROM users WHERE LOWER(email)=? AND role='admin' AND account_status='active' LIMIT 1");$q->execute([$email]);$admin=$q->fetch();if(!$admin)throw new RuntimeException('This Zoho Directory account is not linked to an active portal administrator.');return $admin;
}
