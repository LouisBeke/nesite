<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user();
$id=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($_GET['id']??$_POST['id']??''));
if($id===''){header('Location:/services.php');exit;}
$q=db()->prepare("SELECT id,name,status FROM services WHERE user_id=? AND ptero_identifier=? AND status<>'terminated' LIMIT 1");
$q->execute([(int)$u['id'],$id]);$service=$q->fetch();if(!$service){http_response_code(404);die('Service not found.');}
$msg='';$err='';
try{if(auto_setup_ptero_client_key_for_local_user($u,true))$u=user();}catch(Throwable $e){$err='Automatic panel access setup failed: '.$e->getMessage();}
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  try{
    if(empty($u['ptero_client_key']))throw new RuntimeException('Pterodactyl client access is not configured yet.');
    $url=trim((string)($_POST['url']??''));$type=(string)($_POST['type']??'plugin');
    if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')throw new RuntimeException('Enter a valid HTTPS download URL.');
    $host=strtolower((string)parse_url($url,PHP_URL_HOST));if($host===''||$host==='localhost'||str_ends_with($host,'.local')||filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false&&filter_var($host,FILTER_VALIDATE_IP))throw new RuntimeException('Private or local download addresses are not allowed.');
    $filename=basename((string)parse_url($url,PHP_URL_PATH));if(!preg_match('/^[a-zA-Z0-9._-]+\.(jar|zip)$/i',$filename))throw new RuntimeException('The URL must end in a .jar or .zip filename.');
    $directory=$type==='mod'?'/mods':'/plugins';
    ptero('/servers/'.$id.'/files/pull','POST',['url'=>$url,'directory'=>$directory,'filename'=>$filename,'use_header'=>true,'foreground'=>true]);
    try{db()->prepare('INSERT INTO service_activity(service_id,action,details) VALUES(?,?,?)')->execute([(int)$service['id'],'addon_install','Installed '.$filename.' into '.$directory.' from customer portal.']);}catch(Throwable $ignore){}
    $msg=$filename.' was installed in '.$directory.'. Restart the server to load it.';
  }catch(Throwable $e){$err=$e->getMessage();}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mods &amp; plugins | FoxNetwork</title><link rel="stylesheet" href="/css/fontawesome-all.min.css"><link rel="stylesheet" href="/assets/portal.css?v=<?=rawurlencode((string)@filemtime(__DIR__.'/assets/portal.css'))?>"><style>.addon-grid{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(260px,.7fr);gap:18px;margin-top:24px}.addon-card{padding:24px}.addon-card form{display:grid;gap:16px}.addon-card input,.addon-card select{width:100%;padding:13px;border:1px solid var(--line);border-radius:9px;background:#0f1216;color:#fff}.addon-note{line-height:1.65}@media(max-width:760px){.addon-grid{grid-template-columns:1fr}.addon-card{padding:17px}}</style></head><body class="portal-page"><?php render_client_page_start($u,'services','Mods & plugins');?><div class="eyebrow">SERVER ADD-ONS</div><h1>Install mods &amp; plugins</h1><p class="muted">Install a direct HTTPS download on <?=e($service['name'])?>.</p><?php if($msg):?><div class="notice"><?=e($msg)?></div><?php endif?><?php if($err):?><div class="error"><?=e($err)?></div><?php endif?><div class="addon-grid"><section class="card addon-card"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="id" value="<?=e($id)?>"><label>Type<select name="type"><option value="plugin">Plugin (/plugins)</option><option value="mod">Mod (/mods)</option></select></label><label>Direct download URL<input type="url" name="url" required placeholder="https://example.com/addon.jar"></label><button class="btn primary">Install add-on</button></form></section><aside class="card addon-card addon-note"><b>Before installing</b><p class="muted">Use only trusted `.jar` or `.zip` downloads compatible with your server software and version. Back up the server first. Restart after installation.</p><a class="btn" href="/server.php?id=<?=e($id)?>">Back to server</a></aside></div><?php render_client_page_end();?></body></html>
