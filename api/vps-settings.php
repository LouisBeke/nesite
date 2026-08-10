<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function vps_settings_out(array $payload,int $status=200): never {
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES);
    exit;
}

if($_SERVER['REQUEST_METHOD']!=='POST')vps_settings_out(['ok'=>false,'error'=>'Method not allowed.'],405);
$user=require_user();
$input=json_decode(file_get_contents('php://input')?:'',true);
if(!is_array($input))vps_settings_out(['ok'=>false,'error'=>'Invalid request body.'],422);
$sessionToken=(string)($_SESSION['csrf']??'');$requestToken=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
if($sessionToken===''||$requestToken===''||!hash_equals($sessionToken,$requestToken))vps_settings_out(['ok'=>false,'error'=>'Security token expired. Refresh the page.'],419);

$serviceId=(int)($input['id']??0);
$q=db()->prepare('SELECT * FROM services WHERE id=? AND user_id=? LIMIT 1');$q->execute([$serviceId,(int)$user['id']]);$service=$q->fetch();
if(!$service||linode_service_provider($service)!=='linode')vps_settings_out(['ok'=>false,'error'=>'VPS service not found.'],404);
if(!in_array((string)$service['status'],['active','provisioning'],true))vps_settings_out(['ok'=>false,'error'=>'Settings cannot be changed while this service is '.$service['status'].'.'],409);

$label=trim((string)($input['label']??''));
if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/',$label))vps_settings_out(['ok'=>false,'error'=>'Label must be 3–64 characters using letters, numbers, dots, underscores or dashes.'],422);
$alerts=[];
foreach(['cpu'=>[0,100],'network_in'=>[0,100000],'network_out'=>[0,100000],'transfer_quota'=>[0,100],'io'=>[0,100000]] as $key=>$range){
    $value=(int)($input['alerts'][$key]??0);
    if($value<$range[0]||$value>$range[1])vps_settings_out(['ok'=>false,'error'=>'Invalid alert value for '.$key.'.'],422);
    $alerts[$key]=$value;
}

try{
    $instanceId=linode_service_instance($service);
    $instance=linode_api('/linode/instances/'.$instanceId,'PUT',['label'=>$label,'watchdog_enabled'=>!empty($input['watchdog_enabled']),'alerts'=>$alerts]);
    service_log($serviceId,0,'linode_settings','Customer updated Linode label, watchdog and alert thresholds.');
    vps_settings_out(['ok'=>true,'instance'=>$instance]);
}catch(Throwable $error){vps_settings_out(['ok'=>false,'error'=>$error->getMessage()],502);}
