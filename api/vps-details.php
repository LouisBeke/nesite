<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function vps_details_out(array $payload,int $status=200): never {
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES);
    exit;
}

$user=require_user();
$serviceId=(int)($_GET['id']??0);
$section=strtolower(trim((string)($_GET['section']??'summary')));
if($serviceId<=0||!in_array($section,['summary','metrics','network','storage','configurations','backups','activity'],true))vps_details_out(['ok'=>false,'error'=>'Invalid VPS request.'],422);

$q=db()->prepare('SELECT s.*,p.linode_type,p.linode_region,p.linode_image FROM services s LEFT JOIN store_products p ON p.id=s.product_id WHERE s.id=? AND s.user_id=? LIMIT 1');
$q->execute([$serviceId,(int)$user['id']]);
$service=$q->fetch();
if(!$service||linode_service_provider($service)!=='linode')vps_details_out(['ok'=>false,'error'=>'VPS service not found.'],404);
$instanceId=linode_service_instance($service);

try {
    if($section==='summary'){
        $instance=linode_api('/linode/instances/'.$instanceId);
        $type=[];$region=[];
        try{$type=linode_api('/linode/types/'.rawurlencode((string)($instance['type']??$service['linode_type']??'')),'GET',null,false);}catch(Throwable $ignore){}
        try{$region=linode_api('/regions/'.rawurlencode((string)($instance['region']??$service['linode_region']??'')),'GET',null,false);}catch(Throwable $ignore){}
        vps_details_out(['ok'=>true,'section'=>$section,'instance'=>$instance,'type'=>$type,'region'=>$region]);
    }
    if($section==='metrics'){
        try{$stats=linode_api('/linode/instances/'.$instanceId.'/stats');vps_details_out(['ok'=>true,'section'=>$section,'available'=>true,'stats'=>$stats]);}
        catch(Throwable $statsError){vps_details_out(['ok'=>true,'section'=>$section,'available'=>false,'message'=>$statsError->getMessage()]);}
    }
    if($section==='network'){
        $network=linode_api('/linode/instances/'.$instanceId.'/ips');
        vps_details_out(['ok'=>true,'section'=>$section,'network'=>$network]);
    }
    if($section==='storage'){
        $disks=linode_api('/linode/instances/'.$instanceId.'/disks?page_size=100');
        $volumes=linode_api('/linode/instances/'.$instanceId.'/volumes?page_size=100');
        vps_details_out(['ok'=>true,'section'=>$section,'disks'=>(array)($disks['data']??[]),'volumes'=>(array)($volumes['data']??[])]);
    }
    if($section==='configurations'){
        $configs=linode_api('/linode/instances/'.$instanceId.'/configs?page_size=100');
        vps_details_out(['ok'=>true,'section'=>$section,'configurations'=>(array)($configs['data']??[])]);
    }
    if($section==='backups'){
        $instance=linode_api('/linode/instances/'.$instanceId);
        $backups=linode_api('/linode/instances/'.$instanceId.'/backups');
        vps_details_out(['ok'=>true,'section'=>$section,'status'=>(array)($instance['backups']??[]),'backups'=>$backups]);
    }
    $events=linode_api('/account/events?page_size=100');
    $filtered=[];
    foreach((array)($events['data']??[]) as $event){
        $entity=(array)($event['entity']??[]);
        if((int)($entity['id']??0)!==$instanceId)continue;
        $filtered[]=['id'=>(int)($event['id']??0),'action'=>(string)($event['action']??''),'status'=>(string)($event['status']??''),'created'=>(string)($event['created']??''),'duration'=>$event['duration']??null,'username'=>(string)($event['username']??'System'),'message'=>(string)($event['message']??'')];
        if(count($filtered)>=30)break;
    }
    vps_details_out(['ok'=>true,'section'=>$section,'events'=>$filtered]);
} catch(Throwable $error){
    vps_details_out(['ok'=>false,'error'=>$error->getMessage()],502);
}
