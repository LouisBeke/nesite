<?php
declare(strict_types=1);
session_start();

function is_suspicious_request_path(?string $path): bool {
    if ($path === null || $path === '') return false;
    $decoded = rawurldecode($path);
    $decoded = str_replace('\\', '/', $decoded);
    $decoded = strtolower($decoded);
    if ($decoded === '/' || $decoded === '') return false;
    if (str_contains($decoded, '/..') || str_contains($decoded, '..')) return true;
    $badSegments = ['.env', '.git', '.htpasswd', '.well-known', 'phpinfo', 'wp-config', 'config.php', 'composer.json', 'package.json', 'docker-compose.yml'];
    foreach ($badSegments as $segment) {
        if (str_contains($decoded, $segment)) return true;
    }
    return preg_match('#(^|/)(\.env|\.git|\.htpasswd|\.svn|\.gitignore|\.gitmodules|config\.php|wp-config|phpinfo)(/|$)#', $decoded) === 1;
}

if (is_suspicious_request_path($_SERVER['REQUEST_URI'] ?? '')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    die('Not Found');
}

$configFile=__DIR__.'/../config.php';
if(!file_exists($configFile)){http_response_code(500);die('Copy config.example.php to config.php and configure it.');}
$config=require $configFile;
function cfg(string $key=null){global $config;if($key===null)return $config;$v=$config;foreach(explode('.',$key) as $p){$v=$v[$p]??null;}return $v;}
function site_url(string $path='/'): string {
    $base=(string)cfg('app_url');
    if($base==='')return $path;
    $base=rtrim($base,'/');
    $path=$path==='/'?'/':('/'.ltrim($path,'/'));
    return $base.$path;
}
function enforce_https_redirect(): void {
    if(PHP_SAPI==='cli')return;
    if(isset($_SERVER['HTTPS']) && in_array((string)$_SERVER['HTTPS'],['on','1'],true))return;
    if(!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO'])==='https')return;
    $base=(string)cfg('app_url');
    if($base==='' || !str_starts_with($base,'https://'))return;
    $uri=$_SERVER['REQUEST_URI']??'/';
    $qs='';
    if(($pos=strpos($uri,'?'))!==false){$qs=substr($uri,$pos);$uri=substr($uri,0,$pos);} 
    $target=rtrim($base,'/').'/'.ltrim($uri,'/');
    if($qs!=='')$target.=$qs;
    header('Location: '.$target, true, 301);
    exit;
}
enforce_https_redirect();
require_once __DIR__.'/mollie.php';
function db(): PDO {static $pdo;if(!$pdo){$d=cfg('db');$pdo=new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",$d['user'],$d['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}return $pdo;}
require_once __DIR__.'/migrations.php';
fox_auto_migrate();
fox_v9c_migrate();
fox_v11_migrate();
fox_v12_migrate();
fox_v13_migrate();
fox_v13a_migrate();
fox_v14_migrate();
fox_v14b_migrate();
fox_v15_migrate();
function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
require_once __DIR__.'/mail.php';
require_once __DIR__.'/provisioning.php';
function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function verify_csrf():void{if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(419);die('Invalid request token');}}
function user():?array{
    static $cache=[];
    if (empty($_SESSION['uid'])) return null;
    $uid=(int)$_SESSION['uid'];
    $key='user:'.$uid;
    if (!array_key_exists($key, $cache)) {
        $s=db()->prepare('SELECT * FROM users WHERE id=?');
        $s->execute([$uid]);
        $user = $s->fetch() ?: null;
        if ($user) {
            $linkedPteroUserId = link_existing_ptero_user_for_local_user($user);
            if ($linkedPteroUserId !== null) {
                $user['ptero_user_id'] = (string)$linkedPteroUserId;
            }
        }
        $cache[$key]=$user;
    }
    return $cache[$key];
}
function require_user():array{$u=user();if(!$u){header('Location: '.site_url('/login.php'));exit;}if(($u['account_status']??'active')==='disabled'){session_destroy();http_response_code(403);die('This FoxNetwork account has been disabled. Please contact support.');}return $u;}
function enc(string $plain):string{$key=hash('sha256',cfg('db.pass'),true);$iv=random_bytes(12);$tag='';$ct=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);return base64_encode($iv.$tag.$ct);}
function dec(?string $blob):?string{if(!$blob)return null;$raw=base64_decode($blob,true);if($raw===false||strlen($raw)<28)return null;$key=hash('sha256',cfg('db.pass'),true);$iv=substr($raw,0,12);$tag=substr($raw,12,16);$pt=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);return $pt===false?null:$pt;}
function ptero_cache_dir(): string { $dir=rtrim(sys_get_temp_dir(),'\\/').DIRECTORY_SEPARATOR.'foxnetwork-ptero-cache'; if(!is_dir($dir)) @mkdir($dir,0777,true); return $dir; }
function ptero_cache_key(string $scope,string $token,string $path,string $method,?array $body): string { return sha1($scope.'|'.$token.'|'.$method.'|'.$path.'|'.($body===null?'':json_encode($body,JSON_UNESCAPED_SLASHES))); }
function ptero_cache_get(string $key,int $ttl): mixed { $file=ptero_cache_dir().DIRECTORY_SEPARATOR.$key.'.json'; if(!is_file($file)) return null; $raw=@file_get_contents($file); if($raw===false||$raw==='') return null; $data=json_decode($raw,true); if(!is_array($data)||($data['expires_at']??0)<time()) return null; return $data['value'] ?? null; }
function ptero_cache_set(string $key,mixed $value,int $ttl): void { $file=ptero_cache_dir().DIRECTORY_SEPARATOR.$key.'.json'; @file_put_contents($file,json_encode(['expires_at'=>time()+$ttl,'value'=>$value],JSON_UNESCAPED_SLASHES),LOCK_EX); }
function ptero(string $path,string $method='GET',?array $body=null){$u=user();$token=dec($u['ptero_client_key']??null);if(!$token)throw new RuntimeException('Pterodactyl API key not configured.');$method=strtoupper($method);$cacheTtl=$method==='GET'?3:0;$cacheKey=$cacheTtl>0?ptero_cache_key('client',$token,$path,$method,$body):null;if($cacheKey){$cached=ptero_cache_get($cacheKey,$cacheTtl);if($cached!==null)return $cached;}$ch=curl_init(rtrim(cfg('pterodactyl.url'),'/').'/api/client'.$path);$headers=['Authorization: Bearer '.$token,'Accept: Application/vnd.pterodactyl.v1+json','Content-Type: application/json'];curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>12,CURLOPT_CUSTOMREQUEST=>$method]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));$raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);if($raw===false)throw new RuntimeException(curl_error($ch));curl_close($ch);$json=json_decode($raw,true);if($code<200||$code>=300)throw new RuntimeException($json['errors'][0]['detail']??('Pterodactyl HTTP '.$code));if($cacheKey)ptero_cache_set($cacheKey,$json,$cacheTtl);return $json;}
function greeting():string{$h=(int)date('G');return $h<12?'Good morning':($h<18?'Good afternoon':'Good evening');}

function require_admin(): array {
    $u = require_user();
    if (($u['role'] ?? 'customer') !== 'admin') {
        http_response_code(403);
        die('Admin access required.');
    }
    return $u;
}

function fox_setting_cache_dir(): string {
    $dir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'foxnetwork-setting-cache';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
}

function fox_setting_cache_key(string $key): string {
    return sha1($key);
}

function fox_setting_cache_get(string $key, int $ttl = 30): mixed {
    $file = fox_setting_cache_dir() . DIRECTORY_SEPARATOR . fox_setting_cache_key($key) . '.json';
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['expires_at'] ?? 0) < time()) return null;
    return $data['value'] ?? null;
}

function fox_setting_cache_set(string $key, mixed $value, int $ttl = 30): void {
    $file = fox_setting_cache_dir() . DIRECTORY_SEPARATOR . fox_setting_cache_key($key) . '.json';
    @file_put_contents($file, json_encode(['expires_at' => time() + $ttl, 'value' => $value], JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function fox_setting_cache_delete(string $key): void {
    $file = fox_setting_cache_dir() . DIRECTORY_SEPARATOR . fox_setting_cache_key($key) . '.json';
    if (is_file($file)) @unlink($file);
}

function app_setting(string $key, ?string $default=null): ?string {
    static $cache=[];
    $cacheKey='setting:'.$key;
    if (!array_key_exists($cacheKey, $cache)) {
        $cached = fox_setting_cache_get($cacheKey, 30);
        if ($cached !== null) {
            $cache[$cacheKey] = $cached === '__NULL__' ? $default : (string)$cached;
            return $cache[$cacheKey];
        }
        try {
            $q=db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
            $q->execute([$key]);
            $v=$q->fetchColumn();
            $cache[$cacheKey]=$v===false?$default:(string)$v;
            fox_setting_cache_set($cacheKey, $cache[$cacheKey] === null ? '__NULL__' : $cache[$cacheKey], 30);
        } catch (Throwable $e) {
            $cache[$cacheKey]=$default;
        }
    }
    return $cache[$cacheKey];
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
    $method=strtoupper($method);
    $cacheTtl=$method==='GET'?3:0;
    $cacheKey=$cacheTtl>0?ptero_cache_key('application',$key,$path,$method,$body):null;
    if($cacheKey){$cached=ptero_cache_get($cacheKey,$cacheTtl);if($cached!==null)return $cached;}
    $ch=curl_init(rtrim((string)cfg('pterodactyl.url'),'/').'/api/application'.$path);
    $headers=['Authorization: Bearer '.$key,'Accept: Application/vnd.pterodactyl.v1+json','Content-Type: application/json'];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>20,CURLOPT_CUSTOMREQUEST=>$method]);
    if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));
    $raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    if($raw===false){$x=curl_error($ch);curl_close($ch);throw new RuntimeException($x);} curl_close($ch);
    $json=$raw!==''?json_decode($raw,true):[];
    if($code<200||$code>=300)throw new RuntimeException($json['errors'][0]['detail']??('Pterodactyl Application API HTTP '.$code));
    if($cacheKey)ptero_cache_set($cacheKey,$json,$cacheTtl);
    return $json;
}

function ensure_ptero_user_for_local_user(string $email, string $name = '', bool $createIfMissing = true): ?int {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid email address for Pterodactyl lookup.');
    }

    $users = app_ptero('/users?filter[email]=' . rawurlencode($email) . '&per_page=1');
    $match = $users['data'][0]['attributes'] ?? null;
    if (is_array($match) && !empty($match['id'])) {
        return (int)$match['id'];
    }

    if (!$createIfMissing) {
        return null;
    }

    $cleanName = trim($name);
    $parts = $cleanName !== '' ? preg_split('/\s+/', $cleanName) : [];
    $first = (string)($parts[0] ?? 'Fox');
    $last = (string)($parts[1] ?? 'Customer');
    $base = strtolower(preg_replace('/[^a-z0-9]/', '', explode('@', $email)[0] ?? 'foxcustomer'));
    if ($base === '') $base = 'foxcustomer';
    $username = substr($base, 0, 18) . substr(bin2hex(random_bytes(3)), 0, 6);

    $create = app_ptero('/users', 'POST', [
        'username' => $username,
        'email' => $email,
        'first_name' => $first,
        'last_name' => $last,
        'password' => bin2hex(random_bytes(16)),
        'external_id' => 'foxnetwork-user-' . sha1($email),
    ]);

    $id = (int)($create['attributes']['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Could not create the matching Pterodactyl account.');
    }
    return $id;
}

function link_existing_ptero_user_for_local_user(array $user): ?int {
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) {
        return null;
    }

    if (!empty($user['ptero_user_id'])) {
        return (int)$user['ptero_user_id'];
    }

    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email === '') {
        return null;
    }

    try {
        $pteroUserId = ensure_ptero_user_for_local_user($email, (string)($user['name'] ?? ''), false);
        if ($pteroUserId) {
            db()->prepare('UPDATE users SET ptero_user_id=? WHERE id=?')->execute([$pteroUserId, $uid]);
            return $pteroUserId;
        }
    } catch (Throwable $e) {
    }

    return null;
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
function provision_service(int $serviceId, array &$runtime=[]): array {
    $q=db()->prepare('SELECT s.*,u.email,u.name customer_name,u.ptero_user_id,p.* FROM services s JOIN users u ON u.id=s.user_id LEFT JOIN store_products p ON p.id=s.product_id WHERE s.id=?');$q->execute([$serviceId]);$r=$q->fetch();if(!$r)throw new RuntimeException('Service not found.');
    if(empty($r['ptero_egg_id']))throw new RuntimeException('Product has no Pterodactyl Egg ID configured.');
    $forcedNodeId=(int)($runtime['node_id']??0);
    $forcedAllocationId=(int)($runtime['allocation_id']??0);
    $createdPteroUser=false;
    $puid=(int)($r['ptero_user_id']??0);if(!$puid){$users=app_ptero('/users?filter[email]='.rawurlencode($r['email']));$puid=(int)($users['data'][0]['attributes']['id']??0);if(!$puid){$parts=preg_split('/\s+/',trim((string)$r['customer_name']))?:[];$first=(string)($parts[0]??'Fox');$last=(string)($parts[1]??'Customer');$base=strtolower(preg_replace('/[^a-z0-9]/','',explode('@',(string)$r['email'])[0]??'foxcustomer'));if($base==='')$base='foxcustomer';$username=substr($base,0,18).substr(bin2hex(random_bytes(3)),0,6);$create=app_ptero('/users','POST',['username'=>$username,'email'=>(string)$r['email'],'first_name'=>$first,'last_name'=>$last,'password'=>bin2hex(random_bytes(16)),'external_id'=>'foxnetwork-user-'.(int)$r['user_id']]);$puid=(int)($create['attributes']['id']??0);if(!$puid)throw new RuntimeException('Could not create a Pterodactyl user for the customer.');$createdPteroUser=true;}db()->prepare('UPDATE users SET ptero_user_id=? WHERE id=?')->execute([$puid,$r['user_id']]);}
    $runtime['ptero_user_id']=$puid;
    $runtime['created_ptero_user']=$createdPteroUser?1:0;
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
    if($forcedAllocationId>0){
        $payload['allocation']=['default'=>$forcedAllocationId];
        $runtime['allocation_id']=$forcedAllocationId;
        if($forcedNodeId>0)$runtime['node_id']=$forcedNodeId;
    } elseif($forcedNodeId>0){
        $ar=app_ptero('/nodes/'.$forcedNodeId.'/allocations?per_page=100');
        $allocationId=0;
        foreach(($ar['data']??[]) as $row){$aa=$row['attributes']??[];if(empty($aa['assigned'])){$allocationId=(int)($aa['id']??0);if($allocationId)break;}}
        if(!$allocationId)throw new RuntimeException('Selected smart node has no free allocations.');
        $payload['allocation']=['default'=>$allocationId];
        $runtime['node_id']=$forcedNodeId;
        $runtime['allocation_id']=$allocationId;
    } elseif(!empty($r['ptero_node_id'])){
        // Exact-node provisioning requires a free allocation on that node.
        $ar=app_ptero('/nodes/'.(int)$r['ptero_node_id'].'/allocations?per_page=100');
        $allocationId=0;
        foreach(($ar['data']??[]) as $row){$aa=$row['attributes']??[];if(empty($aa['assigned'])){$allocationId=(int)($aa['id']??0);if($allocationId)break;}}
        if(!$allocationId)throw new RuntimeException('Selected Pterodactyl node has no free allocations.');
        $payload['allocation']=['default'=>$allocationId];
        $runtime['node_id']=(int)$r['ptero_node_id'];
        $runtime['allocation_id']=$allocationId;
    } elseif(!empty($r['ptero_location_id'])){
        $payload['deploy']=['locations'=>[(int)$r['ptero_location_id']],'dedicated_ip'=>false,'port_range'=>[]];
    } else throw new RuntimeException('Product has no Pterodactyl Location ID configured.');
    db()->prepare("UPDATE services SET status='provisioning',last_error=NULL WHERE id=?")->execute([$serviceId]);
    try{$res=app_ptero('/servers','POST',$payload);$a=$res['attributes']??[];$sid=(int)($a['id']??0);$runtime['server_id']=$sid;db()->prepare("UPDATE services SET status='active',ptero_server_id=?,ptero_identifier=?,last_error=NULL WHERE id=?")->execute([$sid?:null,$a['identifier']??null,$serviceId]);if($sid){try{app_ptero('/servers/'.$sid.'/startup','POST');}catch(Throwable $ignore){}}if($sid&&!provisioning_verify_online($sid,4,700))throw new RuntimeException('Pterodactyl server did not report online state after startup.');if($r['order_id'])db()->prepare("UPDATE orders SET status='active' WHERE id=?")->execute([$r['order_id']]);}catch(Throwable $e){db()->prepare("UPDATE services SET status='failed',last_error=? WHERE id=?")->execute([$e->getMessage(),$serviceId]);throw $e;}
    return ['server_id'=>(int)($runtime['server_id']??0),'ptero_user_id'=>$puid,'created_ptero_user'=>$createdPteroUser?1:0,'node_id'=>(int)($runtime['node_id']??0),'allocation_id'=>(int)($runtime['allocation_id']??0)];
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
