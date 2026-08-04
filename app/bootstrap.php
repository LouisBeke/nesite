<?php
declare(strict_types=1);
session_start();
$configFile=__DIR__.'/../config.php';
if(!file_exists($configFile)){http_response_code(500);die('Copy config.example.php to config.php and configure it.');}
$config=require $configFile;
function cfg(string $key=null){global $config;if($key===null)return $config;$v=$config;foreach(explode('.',$key) as $p){$v=$v[$p]??null;}return $v;}
require_once __DIR__.'/mollie.php';
function db(): PDO {static $pdo;if(!$pdo){$d=cfg('db');$pdo=new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",$d['user'],$d['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}return $pdo;}
require_once __DIR__.'/migrations.php';
fox_auto_migrate();
fox_v11_migrate();
fox_v12_migrate();
fox_v13_migrate();
fox_v13a_migrate();
function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
require_once __DIR__.'/mail.php';
function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function verify_csrf():void{if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(419);die('Invalid request token');}}
function user():?array{if(empty($_SESSION['uid']))return null;$s=db()->prepare('SELECT * FROM users WHERE id=?');$s->execute([$_SESSION['uid']]);return $s->fetch()?:null;}
function require_user():array{$u=user();if(!$u){header('Location: /login.php');exit;}if(($u['account_status']??'active')==='disabled'){session_destroy();http_response_code(403);die('This FoxNetwork account has been disabled. Please contact support.');}return $u;}
function enc(string $plain):string{$key=hash('sha256',cfg('db.pass'),true);$iv=random_bytes(12);$tag='';$ct=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);return base64_encode($iv.$tag.$ct);}
function dec(?string $blob):?string{if(!$blob)return null;$raw=base64_decode($blob,true);if($raw===false||strlen($raw)<28)return null;$key=hash('sha256',cfg('db.pass'),true);$iv=substr($raw,0,12);$tag=substr($raw,12,16);$pt=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);return $pt===false?null:$pt;}
function ptero(string $path,string $method='GET',?array $body=null){$u=user();$token=dec($u['ptero_client_key']??null);if(!$token)throw new RuntimeException('Pterodactyl API key not configured.');$ch=curl_init(rtrim(cfg('pterodactyl.url'),'/').'/api/client'.$path);$headers=['Authorization: Bearer '.$token,'Accept: Application/vnd.pterodactyl.v1+json','Content-Type: application/json'];curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>12,CURLOPT_CUSTOMREQUEST=>$method]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));$raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);if($raw===false)throw new RuntimeException(curl_error($ch));curl_close($ch);$json=json_decode($raw,true);if($code<200||$code>=300)throw new RuntimeException($json['errors'][0]['detail']??('Pterodactyl HTTP '.$code));return $json;}
function greeting():string{$h=(int)date('G');return $h<12?'Good morning':($h<18?'Good afternoon':'Good evening');}

function require_admin(): array {
    $u = require_user();
    if (($u['role'] ?? 'customer') !== 'admin') {
        http_response_code(403);
        die('Admin access required.');
    }
    return $u;
}

function app_setting(string $key, ?string $default=null): ?string {
    try {$q=db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$default:(string)$v;} catch(Throwable $e){return $default;}
}
function audit_log(string $action, ?string $targetType=null, $targetId=null, string $details=''): void {
    try {$u=user();$q=db()->prepare('INSERT INTO admin_audit_log(admin_user_id,action,target_type,target_id,ip_address,details) VALUES(?,?,?,?,?,?)');$q->execute([$u['id']??null,$action,$targetType,$targetId===null?null:(string)$targetId,$_SERVER['REMOTE_ADDR']??null,$details]);} catch(Throwable $e){}
}
function maintenance_guard(): void {
    if(PHP_SAPI==='cli') return;
    if(app_setting('maintenance_mode','0')!=='1') return;
    $u=user(); if(($u['role']??'')==='admin') return;
    http_response_code(503); header('Retry-After: 900');
    $m=e(app_setting('maintenance_message','FoxNetwork is undergoing maintenance.'));
    die('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>FoxNetwork Maintenance</title><style>body{margin:0;background:#0d0f12;color:#fff;font:16px Arial;display:grid;place-items:center;min-height:100vh}.x{max-width:620px;padding:42px;background:#15181d;border:1px solid #2a2e35;border-radius:18px;text-align:center}b{color:#ff7417;font-size:28px}p{color:#aab1bc;line-height:1.6}</style><div class="x"><b>FOXNETWORK</b><h1>Maintenance</h1><p>'.$m.'</p></div>');
}
maintenance_guard();

function app_ptero(string $path,string $method='GET',?array $body=null){
    $key=(string)(cfg('pterodactyl.application_key')??'');
    if($key==='') throw new RuntimeException('Pterodactyl Application API key is not configured.');
    $ch=curl_init(rtrim((string)cfg('pterodactyl.url'),'/').'/api/application'.$path);
    $headers=['Authorization: Bearer '.$key,'Accept: Application/vnd.pterodactyl.v1+json','Content-Type: application/json'];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>20,CURLOPT_CUSTOMREQUEST=>$method]);
    if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));
    $raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    if($raw===false){$x=curl_error($ch);curl_close($ch);throw new RuntimeException($x);} curl_close($ch);
    $json=$raw!==''?json_decode($raw,true):[];
    if($code<200||$code>=300)throw new RuntimeException($json['errors'][0]['detail']??('Pterodactyl Application API HTTP '.$code));
    return $json;
}
function invoice_for_order(int $orderId): int {
    $q=db()->prepare('SELECT id FROM invoices WHERE order_id=? LIMIT 1');$q->execute([$orderId]);$id=$q->fetchColumn();if($id)return (int)$id;
    $q=db()->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$orderId]);$o=$q->fetch();if(!$o)throw new RuntimeException('Order not found.');
    $num='INV-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));$due=date('Y-m-d H:i:s',strtotime('+7 days'));
    $q=db()->prepare("INSERT INTO invoices(user_id,order_id,invoice_number,status,subtotal,total,currency,due_at) VALUES(?,?,?,'unpaid',?,?,?,?)");$q->execute([$o['user_id'],$o['id'],$num,$o['subtotal'],$o['total'],$o['currency'],$due]);$iid=(int)db()->lastInsertId();
    $items=db()->prepare('SELECT product_name,unit_price,quantity FROM order_items WHERE order_id=?');$items->execute([$orderId]);$ins=db()->prepare('INSERT INTO invoice_items(invoice_id,description,amount,quantity) VALUES(?,?,?,?)');foreach($items as $it)$ins->execute([$iid,$it['product_name'].' — monthly hosting',$it['unit_price'],$it['quantity']]);
    db()->prepare("UPDATE orders SET status='awaiting_payment' WHERE id=?")->execute([$orderId]);
    try{$uq=db()->prepare('SELECT * FROM users WHERE id=?');$uq->execute([$o['user_id']]);$cu=$uq->fetch();if($cu)send_template('invoice_created',$cu,['invoice_number'=>$num,'total'=>number_format((float)$o['total'],2),'currency'=>$o['currency'],'due_date'=>date('d M Y',strtotime($due))]);}catch(Throwable $e){}
    return $iid;
}
function mark_invoice_paid(int $invoiceId,string $provider='manual',?string $reference=null): void {
    db()->beginTransaction();try{$q=db()->prepare('SELECT * FROM invoices WHERE id=? FOR UPDATE');$q->execute([$invoiceId]);$i=$q->fetch();if(!$i)throw new RuntimeException('Invoice not found.');if($i['status']!=='paid'){if(!empty($i['order_id'])){$sq=db()->prepare('SELECT p.id,p.stock,oi.quantity FROM order_items oi JOIN store_products p ON p.id=oi.product_id WHERE oi.order_id=? FOR UPDATE');$sq->execute([(int)$i['order_id']]);foreach($sq->fetchAll() as $sp){if($sp['stock']!==null){$need=max(1,(int)$sp['quantity']);if((int)$sp['stock']<$need)throw new RuntimeException('Product is out of stock. Payment cannot be completed automatically.');db()->prepare('UPDATE store_products SET stock=stock-? WHERE id=?')->execute([$need,(int)$sp['id']]);}}}$q=db()->prepare("UPDATE invoices SET status='paid',paid_at=NOW() WHERE id=?");$q->execute([$invoiceId]);$q=db()->prepare("INSERT INTO payments(user_id,invoice_id,provider,provider_reference,amount,currency,status) VALUES(?,?,?,?,?,?,'completed')");$q->execute([$i['user_id'],$invoiceId,$provider,$reference,$i['total'],$i['currency']]);if($i['order_id'])db()->prepare("UPDATE orders SET status='paid' WHERE id=?")->execute([$i['order_id']]);}db()->commit();}catch(Throwable $e){db()->rollBack();throw $e;}
}
function ensure_service_for_order(int $orderId): int {
    $q=db()->prepare('SELECT id FROM services WHERE order_id=? LIMIT 1');$q->execute([$orderId]);$id=$q->fetchColumn();if($id)return (int)$id;
    $q=db()->prepare('SELECT o.user_id,oi.product_id,oi.product_name,oi.unit_price,oi.config_json FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.id=? ORDER BY oi.id LIMIT 1');$q->execute([$orderId]);$r=$q->fetch();if(!$r)throw new RuntimeException('Order item not found.');
    $cfg=json_decode($r['config_json']?:'{}',true)?:[];$name=$cfg['server_name']??$r['product_name'];$q=db()->prepare("INSERT INTO services(user_id,order_id,product_id,name,status,price_monthly,next_due_at,config_json) VALUES(?,?,?,?, 'pending',?,DATE_ADD(NOW(),INTERVAL 1 MONTH),?)");$q->execute([$r['user_id'],$orderId,$r['product_id'],$name,$r['unit_price'],$r['config_json']]);return (int)db()->lastInsertId();
}
function provision_service(int $serviceId): void {
    $q=db()->prepare('SELECT s.*,u.email,u.name customer_name,u.ptero_user_id,p.* FROM services s JOIN users u ON u.id=s.user_id LEFT JOIN store_products p ON p.id=s.product_id WHERE s.id=?');$q->execute([$serviceId]);$r=$q->fetch();if(!$r)throw new RuntimeException('Service not found.');
    if(empty($r['ptero_egg_id']))throw new RuntimeException('Product has no Pterodactyl Egg ID configured.');
    $puid=(int)($r['ptero_user_id']??0);if(!$puid){$users=app_ptero('/users?filter[email]='.rawurlencode($r['email']));$puid=(int)($users['data'][0]['attributes']['id']??0);if(!$puid)throw new RuntimeException('Customer does not exist in Pterodactyl. Create/link the Pterodactyl user first.');db()->prepare('UPDATE users SET ptero_user_id=? WHERE id=?')->execute([$puid,$r['user_id']]);}
    $cfg=json_decode($r['config_json']?:'{}',true)?:[];$env=json_decode($r['ptero_environment']?:'{}',true)?:[];
    // v12: use the software/Egg selected by the customer for this order.
    $selectedEggId=(int)($cfg['egg_id']??0);
    if($selectedEggId){
        $eq=db()->prepare('SELECT * FROM product_eggs WHERE product_id=? AND egg_id=? AND enabled=1 LIMIT 1');
        $eq->execute([(int)$r['product_id'],$selectedEggId]);$pe=$eq->fetch();
        if(!$pe) throw new RuntimeException('The selected server software is no longer available for this product.');
        $r['ptero_egg_id']=$selectedEggId;
        if(!empty($pe['docker_image']))$r['ptero_docker_image']=$pe['docker_image'];
        if(!empty($pe['startup']))$r['ptero_startup']=$pe['startup'];
        $peEnv=json_decode((string)($pe['environment']??''),true)?:[]; if($peEnv)$env=array_merge($env,$peEnv);
        // v13: merge validated customer-configurable Egg variables saved with the order.
        $customerEnv=$cfg['environment']??[]; if(is_array($customerEnv)){
            $vq=db()->prepare('SELECT env_variable,customer_editable FROM product_egg_variables WHERE product_id=? AND egg_id=?');$vq->execute([(int)$r['product_id'],$selectedEggId]);
            $allowed=[];foreach($vq->fetchAll() as $vv)if(!empty($vv['customer_editable']))$allowed[(string)$vv['env_variable']]=true;
            foreach($customerEnv as $k=>$v)if(isset($allowed[(string)$k]))$env[(string)$k]=(string)$v;
        }
    }
    // Validate and complete required Egg environment variables before provisioning.
    $eggVars=[];
    try {
        $nests=app_ptero('/nests?include=eggs&per_page=100');
        $nestId=0;
        foreach(($nests['data']??[]) as $nest){
            $na=$nest['attributes']??[];
            foreach(($na['relationships']['eggs']['data']??$nest['relationships']['eggs']['data']??[]) as $egg){
                $ea=$egg['attributes']??[]; if((int)($ea['id']??0)===(int)$r['ptero_egg_id']){$nestId=(int)($na['id']??0);break 2;}
            }
        }
        if($nestId){
            $detail=app_ptero('/nests/'.$nestId.'/eggs/'.(int)$r['ptero_egg_id'].'?include=variables');
            $da=$detail['attributes']??[];
            if(empty($r['ptero_docker_image']) && !empty($da['docker_image'])) $r['ptero_docker_image']=$da['docker_image'];
            if(empty($r['ptero_startup']) && !empty($da['startup'])) $r['ptero_startup']=$da['startup'];
            $eggVars=$da['relationships']['variables']['data']??$detail['relationships']['variables']['data']??[];
            foreach($eggVars as $v){$va=$v['attributes']??[];$key=(string)($va['env_variable']??'');if($key==='')continue;if(!array_key_exists($key,$env) || $env[$key]===''){$default=$va['default_value']??null;if($default!==null && $default!=='')$env[$key]=(string)$default;}}
            $missing=[];foreach($eggVars as $v){$va=$v['attributes']??[];$key=(string)($va['env_variable']??'');$rules=(string)($va['rules']??'');if($key!=='' && str_contains($rules,'required') && (!array_key_exists($key,$env)||$env[$key]===''))$missing[]=(string)($va['name']??$key).' ('.$key.')';}
            if($missing) throw new RuntimeException('Missing required Egg variables: '.implode(', ',$missing).'. Configure them in Admin → Products.');
        }
    } catch(RuntimeException $e){ if(str_starts_with($e->getMessage(),'Missing required Egg variables:')) throw $e; }
    $payload=['name'=>$r['name'],'user'=>$puid,'egg'=>(int)$r['ptero_egg_id'],'docker_image'=>$r['ptero_docker_image']?:'ghcr.io/pterodactyl/yolks:java_21','startup'=>$r['ptero_startup']?:'java -Xms128M -Xmx{{SERVER_MEMORY}}M -jar {{SERVER_JARFILE}}','environment'=>$env,'limits'=>['memory'=>(int)($cfg['ram_mb']??$r['ram_mb']),'swap'=>0,'disk'=>(int)($cfg['disk_mb']??$r['disk_mb']),'io'=>500,'cpu'=>(int)($cfg['cpu_percent']??$r['cpu_percent'])],'feature_limits'=>['databases'=>(int)$r['database_limit'],'allocations'=>(int)$r['allocation_limit'],'backups'=>(int)$r['backups']]];
    if(!empty($r['ptero_node_id'])){
        // Exact-node provisioning requires a free allocation on that node.
        $ar=app_ptero('/nodes/'.(int)$r['ptero_node_id'].'/allocations?per_page=100');
        $allocationId=0;
        foreach(($ar['data']??[]) as $row){$aa=$row['attributes']??[];if(empty($aa['assigned'])){$allocationId=(int)($aa['id']??0);if($allocationId)break;}}
        if(!$allocationId)throw new RuntimeException('Selected Pterodactyl node has no free allocations.');
        $payload['allocation']=['default'=>$allocationId];
    } elseif(!empty($r['ptero_location_id'])){
        $payload['deploy']=['locations'=>[(int)$r['ptero_location_id']],'dedicated_ip'=>false,'port_range'=>[]];
    } else throw new RuntimeException('Product has no Pterodactyl Location ID configured.');
    db()->prepare("UPDATE services SET status='provisioning',last_error=NULL WHERE id=?")->execute([$serviceId]);
    try{$res=app_ptero('/servers','POST',$payload);$a=$res['attributes']??[];db()->prepare("UPDATE services SET status='active',ptero_server_id=?,ptero_identifier=?,last_error=NULL WHERE id=?")->execute([$a['id']??null,$a['identifier']??null,$serviceId]);if($r['order_id'])db()->prepare("UPDATE orders SET status='active' WHERE id=?")->execute([$r['order_id']]);}catch(Throwable $e){db()->prepare("UPDATE services SET status='failed',last_error=? WHERE id=?")->execute([$e->getMessage(),$serviceId]);throw $e;}
}

function service_row(int $serviceId): array {
    $q=db()->prepare('SELECT * FROM services WHERE id=?');$q->execute([$serviceId]);$r=$q->fetch();
    if(!$r) throw new RuntimeException('Service not found.');
    return $r;
}
function service_log(int $serviceId,int $adminId,string $action,string $details=''): void {
    $q=db()->prepare('INSERT INTO service_activity(service_id,admin_user_id,action,details) VALUES(?,?,?,?)');
    $q->execute([$serviceId,$adminId,$action,$details]);
}
function require_ptero_server(array $service): int {
    $id=(int)($service['ptero_server_id']??0); if(!$id) throw new RuntimeException('This service has no Pterodactyl server yet.'); return $id;
}
function suspend_service(int $serviceId,int $adminId): void {
    $s=service_row($serviceId);$pid=require_ptero_server($s);app_ptero('/servers/'.$pid.'/suspend','POST');
    db()->prepare("UPDATE services SET status='suspended',last_error=NULL WHERE id=?")->execute([$serviceId]);service_log($serviceId,$adminId,'suspend','Pterodactyl server suspended.');
}
function unsuspend_service(int $serviceId,int $adminId): void {
    $s=service_row($serviceId);$pid=require_ptero_server($s);app_ptero('/servers/'.$pid.'/unsuspend','POST');
    db()->prepare("UPDATE services SET status='active',last_error=NULL WHERE id=?")->execute([$serviceId]);service_log($serviceId,$adminId,'unsuspend','Pterodactyl server unsuspended.');
}
function reinstall_service(int $serviceId,int $adminId): void {
    $s=service_row($serviceId);$pid=require_ptero_server($s);app_ptero('/servers/'.$pid.'/reinstall','POST');service_log($serviceId,$adminId,'reinstall','Pterodactyl reinstall requested.');
}
function cancel_service(int $serviceId,int $adminId): void {
    $s=service_row($serviceId);if($s['status']==='terminated')throw new RuntimeException('A terminated service cannot be cancelled.');
    db()->prepare("UPDATE services SET status='cancelled' WHERE id=?")->execute([$serviceId]);service_log($serviceId,$adminId,'cancel','Service cancelled in FoxNetwork. Server was not deleted.');
}
function reactivate_service(int $serviceId,int $adminId): void {
    $s=service_row($serviceId);if($s['status']==='terminated')throw new RuntimeException('A terminated service cannot be reactivated.');
    if(!empty($s['ptero_server_id'])){try{app_ptero('/servers/'.(int)$s['ptero_server_id'].'/unsuspend','POST');}catch(Throwable $e){/* already unsuspended is harmless for local reactivation */}}
    db()->prepare("UPDATE services SET status='active',last_error=NULL WHERE id=?")->execute([$serviceId]);service_log($serviceId,$adminId,'reactivate','Service reactivated.');
}
function terminate_service(int $serviceId,int $adminId,string $confirmation): void {
    $s=service_row($serviceId);if(!hash_equals((string)$s['name'],trim($confirmation)))throw new RuntimeException('Confirmation does not match the service name.');
    $pid=(int)($s['ptero_server_id']??0);if($pid)app_ptero('/servers/'.$pid,'DELETE');
    db()->prepare("UPDATE services SET status='terminated',ptero_server_id=NULL,ptero_identifier=NULL,last_error=NULL WHERE id=?")->execute([$serviceId]);service_log($serviceId,$adminId,'terminate','Pterodactyl server permanently deleted and service terminated.');
}

// Stage 9 security helpers.
function security_log_login(?int $uid,string $email,bool $success):void{try{$q=db()->prepare('INSERT INTO login_history(user_id,email,ip_address,user_agent,success) VALUES(?,?,?,?,?)');$q->execute([$uid,$email,$_SERVER['REMOTE_ADDR']??null,substr($_SERVER['HTTP_USER_AGENT']??'',0,500),$success?1:0]);}catch(Throwable $e){}}
function security_touch_session(int $uid):void{try{$sid=session_id();$q=db()->prepare('INSERT INTO user_sessions(session_id,user_id,ip_address,user_agent,last_seen_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),ip_address=VALUES(ip_address),user_agent=VALUES(user_agent),last_seen_at=NOW()');$q->execute([$sid,$uid,$_SERVER['REMOTE_ADDR']??null,substr($_SERVER['HTTP_USER_AGENT']??'',0,500)]);}catch(Throwable $e){}}
function security_logout_session():void{try{db()->prepare('DELETE FROM user_sessions WHERE session_id=?')->execute([session_id()]);}catch(Throwable $e){}}
function b32encode(string $data):string{$abc='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split($data) as $c)$bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);$out='';foreach(str_split($bits,5) as $b){$b=str_pad($b,5,'0');$out.=$abc[bindec($b)];}return $out;}
function b32decode(string $s):string{$abc='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split(strtoupper(preg_replace('/[^A-Z2-7]/','',$s))) as $c){$p=strpos($abc,$c);if($p===false)continue;$bits.=str_pad(decbin($p),5,'0',STR_PAD_LEFT);} $out='';foreach(str_split($bits,8) as $b)if(strlen($b)===8)$out.=chr(bindec($b));return $out;}
function totp_code(string $secret,?int $time=null):string{$key=b32decode($secret);$counter=intdiv($time??time(),30);$bin=pack('N2',0,$counter);$hash=hash_hmac('sha1',$bin,$key,true);$o=ord($hash[19])&15;$n=((ord($hash[$o])&127)<<24)|((ord($hash[$o+1])&255)<<16)|((ord($hash[$o+2])&255)<<8)|(ord($hash[$o+3])&255);return str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);}
function totp_verify(string $secret,string $code):bool{$code=preg_replace('/\D/','',$code);if(strlen($code)!==6)return false;for($i=-1;$i<=1;$i++)if(hash_equals(totp_code($secret,time()+$i*30),$code))return true;return false;}

/* Stage 10: service upgrades and add-ons */
function service_effective_limits(array $s): array {
    $cfg=json_decode((string)($s['config_json']??''),true)?:[];
    $p=[];
    if(!empty($s['product_id'])){$q=db()->prepare('SELECT * FROM store_products WHERE id=?');$q->execute([(int)$s['product_id']]);$p=$q->fetch()?:[];}
    return [
      'memory'=>(int)($cfg['ram_mb']??$p['ram_mb']??2048),
      'disk'=>(int)($cfg['disk_mb']??$p['disk_mb']??10000),
      'cpu'=>(int)($cfg['cpu_percent']??$p['cpu_percent']??100),
      'databases'=>(int)($cfg['database_limit']??$p['database_limit']??1),
      'allocations'=>(int)($cfg['allocation_limit']??$p['allocation_limit']??1),
      'backups'=>(int)($cfg['backups']??$p['backups']??1),
    ];
}
function ptero_apply_limits(int $serviceId,array $limits): void {
    $s=service_row($serviceId);$pid=require_ptero_server($s);
    $server=app_ptero('/servers/'.$pid);$a=$server['attributes']??[];
    $allocation=(int)($a['allocation']??0);if(!$allocation){$allocation=(int)($a['relationships']['allocations']['data'][0]['attributes']['id']??0);}
    if(!$allocation) throw new RuntimeException('Could not determine the server primary allocation.');
    app_ptero('/servers/'.$pid.'/build','PATCH',[
      'allocation'=>$allocation,
      'memory'=>(int)$limits['memory'],'swap'=>0,'disk'=>(int)$limits['disk'],'io'=>500,'cpu'=>(int)$limits['cpu'],'threads'=>null,
      'feature_limits'=>['databases'=>(int)$limits['databases'],'allocations'=>(int)$limits['allocations'],'backups'=>(int)$limits['backups']]
    ]);
}
function create_upgrade_invoice(int $serviceId,int $newProductId,array $extras=[]): int {
    $q=db()->prepare('SELECT s.*,p.name product_name,p.price_monthly,p.ram_mb,p.disk_mb,p.cpu_percent,p.backups,p.database_limit,p.allocation_limit FROM services s LEFT JOIN store_products p ON p.id=s.product_id WHERE s.id=?');$q->execute([$serviceId]);$s=$q->fetch();if(!$s)throw new RuntimeException('Service not found.');
    $q=db()->prepare('SELECT * FROM store_products WHERE id=? AND active=1');$q->execute([$newProductId]);$np=$q->fetch();if(!$np)throw new RuntimeException('Selected product is unavailable.');
    $old=(float)$s['price_monthly'];$new=(float)$np['price_monthly'];
    $addon=(float)($extras['addon_monthly']??0);$newTotal=$new+$addon;$due=max(0,$newTotal-$old);
    $num='INV-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));$dueAt=date('Y-m-d H:i:s',strtotime('+7 days'));
    db()->beginTransaction();try{
      db()->prepare("INSERT INTO invoices(user_id,service_id,invoice_number,status,subtotal,total,currency,due_at) VALUES(?,?,?,'unpaid',?,?,?,?)")->execute([$s['user_id'],$serviceId,$num,$due,$due,$s['currency'],$dueAt]);$iid=(int)db()->lastInsertId();
      db()->prepare('INSERT INTO invoice_items(invoice_id,description,amount,quantity) VALUES(?,?,?,1)')->execute([$iid,'Service upgrade to '.$np['name'],$due]);
      $oldCfg=json_encode(service_effective_limits($s));$newCfg=json_encode(['ram_mb'=>(int)$np['ram_mb'],'disk_mb'=>(int)$np['disk_mb'],'cpu_percent'=>(int)$np['cpu_percent'],'backups'=>(int)$np['backups'],'database_limit'=>(int)$np['database_limit'],'allocation_limit'=>(int)$np['allocation_limit'],'addons'=>$extras]);
      db()->prepare("INSERT INTO service_changes(service_id,user_id,invoice_id,change_type,status,old_product_id,new_product_id,old_price,new_price,amount_due,old_config,new_config) VALUES(?,?,?,'package','pending_payment',?,?,?,?,?,?,?)")->execute([$serviceId,$s['user_id'],$iid,$s['product_id'],$newProductId,$old,$newTotal,$due,$oldCfg,$newCfg]);
      db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    if($due<=0){mark_invoice_paid($iid,'credit','upgrade-no-charge');apply_pending_service_change_for_invoice($iid);} return $iid;
}
function apply_pending_service_change_for_invoice(int $invoiceId): void {
    $q=db()->prepare("SELECT * FROM service_changes WHERE invoice_id=? AND status IN ('pending_payment','paid','failed') ORDER BY id DESC LIMIT 1");$q->execute([$invoiceId]);$c=$q->fetch();if(!$c)return;
    db()->prepare("UPDATE service_changes SET status='applying',error_message=NULL WHERE id=?")->execute([$c['id']]);
    try{
      $cfg=json_decode((string)$c['new_config'],true)?:[];$limits=['memory'=>(int)($cfg['ram_mb']??2048),'disk'=>(int)($cfg['disk_mb']??10000),'cpu'=>(int)($cfg['cpu_percent']??100),'backups'=>(int)($cfg['backups']??1),'databases'=>(int)($cfg['database_limit']??1),'allocations'=>(int)($cfg['allocation_limit']??1)];
      $s=service_row((int)$c['service_id']);if(!empty($s['ptero_server_id']))ptero_apply_limits((int)$c['service_id'],$limits);
      $newServiceCfg=json_decode((string)($s['config_json']??''),true)?:[];foreach(['ram_mb','disk_mb','cpu_percent','backups','database_limit','allocation_limit'] as $k)$newServiceCfg[$k]=$cfg[$k]??$newServiceCfg[$k]??null;
      db()->prepare('UPDATE services SET product_id=?,price_monthly=?,config_json=?,last_error=NULL WHERE id=?')->execute([$c['new_product_id'],$c['new_price'],json_encode($newServiceCfg),(int)$c['service_id']]);
      db()->prepare("UPDATE service_changes SET status='completed',completed_at=NOW() WHERE id=?")->execute([$c['id']]);service_log((int)$c['service_id'],0,'upgrade','Service package/resources updated after invoice payment.');
    }catch(Throwable $e){db()->prepare("UPDATE service_changes SET status='failed',error_message=? WHERE id=?")->execute([$e->getMessage(),$c['id']]);db()->prepare('UPDATE services SET last_error=? WHERE id=?')->execute([$e->getMessage(),$c['service_id']]);throw $e;}
}
