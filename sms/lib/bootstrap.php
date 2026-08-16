<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', getenv('APP_DEBUG') === '1' ? '1' : '0');
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('foxnetwork_sms'); session_start(); }

function dotenv(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (getenv($key) !== false) continue;
        $value = trim($value, "\"'"); putenv("$key=$value"); $_ENV[$key] = $value;
    }
}
dotenv(dirname(__DIR__) . '/.env');

$config = require dirname(__DIR__) . '/config.php';
$CONFIG = ['token'=>$config['wvs_token'], 'iccid'=>$config['default_iccid'], 'originator'=>$config['originator'], 'api_key'=>$config['api_key'], 'endpoint'=>$config['wvs_endpoint']];
$C = ['user'=>$config['admin_user'], 'pass'=>$config['admin_password'], 'gateway_key'=>getenv('SMS_GATEWAY_KEY') ?: 'change-this-gateway-key'];
$D = dirname(__DIR__) . '/data'; $DATA = $D;
if (!is_dir($D)) mkdir($D, 0775, true);

function app_url(string $path = ''): string {
    $base = '/' . trim((string)(getenv('SMS_BASE_PATH') ?: '/sms'), '/');
    return ($base === '/' ? '' : $base) . '/' . ltrim($path, '/');
}
function f(string $name): string { global $D; if (!preg_match('/^[a-z0-9_-]+$/i', $name)) throw new InvalidArgumentException('Invalid data name'); return "$D/$name.json"; }
function rows(string $name): array { $decoded=json_decode((string)@file_get_contents(f($name)), true); return is_array($decoded)?$decoded:[]; }
function save(string $name, array $data): void { if (file_put_contents(f($name), json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX)===false) throw new RuntimeException('Data directory is not writable'); }
function add(string $name, array $row): int { $all=rows($name); $ids=array_column($all,'id'); $row['id']=$ids?max($ids)+1:1; $all[]=$row; save($name,$all); return $row['id']; }
function logged(): bool { return !empty($_SESSION['ok']); }
function need(): void { if (!logged()) { header('Location: '.app_url('login.php')); exit; } }
function jout(mixed $value, int $code=200): never { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($value); exit; }
function gateway(): void { global $C; $key=$_SERVER['HTTP_X_GATEWAY_KEY']??''; if ($C['gateway_key']==='change-this-gateway-key'||!hash_equals($C['gateway_key'],$key)) jout(['error'=>'unauthorized'],401); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(24)); }
function check_csrf(): void { if (!hash_equals(csrf(),(string)($_POST['csrf']??''))) throw new RuntimeException('Invalid form token'); }

// Compatibility aliases for pages from the second revision in the archive.
function need_login(): void { need(); } function needLogin(): void { need(); }
function load_rows(string $name): array { return rows($name); } function add_row(string $name,array $row): int { return add($name,$row); }
function json_out(mixed $value,int $code=200): never { jout($value,$code); }

function send_sms(string $iccid,string $message): array {
    global $CONFIG;
    if ($CONFIG['token']==='') throw new RuntimeException('WhereverSIM API token is not configured');
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required');
    $query='mutation SendSms($iccid: String!, $message: String!, $originator: String) { sendSms(iccid: $iccid, message: $message, originator: $originator) { id status } }';
    $payload=json_encode(['query'=>$query,'variables'=>['iccid'=>$iccid,'message'=>$message,'originator'=>$CONFIG['originator']?:null]]);
    $curl=curl_init($CONFIG['endpoint']);
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Authorization: '.$CONFIG['token'],'Content-Type: application/json'],CURLOPT_POSTFIELDS=>$payload]);
    $body=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    if ($body===false) throw new RuntimeException('WhereverSIM request failed: '.curl_error($curl));
    $result=json_decode($body,true);
    if ($status>=400||!is_array($result)||!empty($result['errors'])) throw new RuntimeException('WhereverSIM rejected the SMS request');
    return $result;
}
