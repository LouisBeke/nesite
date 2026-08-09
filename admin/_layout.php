<?php
function admin_head(array $u, string $title, string $active='dashboard'): void {
header('Cache-Control: private, no-store, no-transform, max-age=0');
$items=[
 'dashboard'=>['/admin/','Dashboard','▦'],
 'customers'=>['/admin/customers.php','Customers','♙'],
 'support'=>['/admin/support.php','Support','✉'],
 'orders'=>['/admin/orders.php','Orders','▤'],
 'products'=>['/admin/products.php','Products','◇'],
 'billing'=>['/admin/billing.php','Billing','€'],
 'services'=>['/admin/services.php','Services','▣'],
 'email'=>['/admin/email.php','Email','✉'],
 'settings'=>['/admin/settings.php','Settings','⚙'],
 'migration'=>['/admin/migration.php','Migration','⇄'],
 'security'=>['/admin/security.php','Security','◆'],
 'system'=>['/admin/system.php','System','⚙'],
];
$items['automation']=['/admin/automation.php','Automation','A'];
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FoxNetwork Admin | <?=e($title)?></title><link rel="stylesheet" href="/assets/portal.css?v=<?=rawurlencode((string)@filemtime(__DIR__.'/../assets/portal.css'))?>"></head><body><div class="admin-app"><aside class="admin-side"><a class="admin-brand" href="/admin/"><img src="/images/logo.png"><div><b>FOX<span>NETWORK</span></b><small>ADMIN CENTER</small></div></a><nav class="admin-nav"><?php foreach($items as $key=>$it):?><a class="<?=$active===$key?'active':''?>" href="<?=e($it[0])?>"><i><?=e($it[2])?></i><span><?=e($it[1])?></span></a><?php endforeach?></nav><div class="admin-side-bottom"><a href="/client">← Customer Portal</a><a href="/logout.php">Sign out</a></div></aside><main class="admin-main"><header class="admin-top"><div><span class="admin-kicker">FOXNETWORK ADMIN</span><h1><?=e($title)?></h1></div><div class="profile"><div><b><?=e($u['name'])?></b><div class="muted small">Administrator</div></div><div class="avatar"><?=e(strtoupper(substr($u['name'],0,1)))?></div></div></header><div class="admin-content"><?php }
function admin_foot(): void { ?>
</div></main></div>
<dialog class="danger-dialog manage-service-dialog" id="admin-sftp-dialog">
  <h3 id="admin-sftp-title">SFTP access</h3>
  <div id="admin-sftp-error" class="error" style="display:none"></div>
  <div class="resource-grid">
    <label>Host<input id="admin-sftp-host" readonly></label>
    <label>Port<input id="admin-sftp-port" readonly></label>
    <label>Username<input id="admin-sftp-username" readonly></label>
    <label>Command<input id="admin-sftp-command" readonly></label>
  </div>
  <p class="muted small">The customer signs in with their Pterodactyl account password. Passwords are not displayed or stored here.</p>
  <div class="dialog-actions"><button class="btn" type="button" id="admin-sftp-copy">Copy command</button><a class="btn primary" id="admin-sftp-open" href="#">Open SFTP application</a><button class="btn" type="button" onclick="document.getElementById('admin-sftp-dialog').close()">Close</button></div>
</dialog>
<script>
document.addEventListener('click',async function(event){
  const button=event.target.closest('[data-admin-sftp]');
  if(!button)return;
  const dialog=document.getElementById('admin-sftp-dialog');
  const error=document.getElementById('admin-sftp-error');
  error.style.display='none';
  document.getElementById('admin-sftp-title').textContent='Loading SFTP details…';
  ['host','port','username','command'].forEach(function(key){document.getElementById('admin-sftp-'+key).value='';});
  document.getElementById('admin-sftp-open').href='#';
  dialog.showModal();
  try{
    const response=await fetch('/api/admin-sftp.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':<?=json_encode(csrf())?>},body:JSON.stringify({service_id:Number(button.dataset.adminSftp||0)})});
    const result=await response.json();
    if(!result.ok)throw new Error(result.error||'Could not load SFTP details.');
    const data=result.data||{};
    document.getElementById('admin-sftp-title').textContent='SFTP · '+(data.service||'Service');
    ['host','port','username','command'].forEach(function(key){document.getElementById('admin-sftp-'+key).value=data[key]||'';});
    document.getElementById('admin-sftp-open').href=data.uri||'#';
  }catch(e){error.textContent=e.message||String(e);error.style.display='block';document.getElementById('admin-sftp-title').textContent='SFTP access';}
});
document.getElementById('admin-sftp-copy').addEventListener('click',async function(){
  const input=document.getElementById('admin-sftp-command');if(!input.value)return;
  try{await navigator.clipboard.writeText(input.value)}catch(e){input.focus();input.select();document.execCommand('copy')}
  const old=this.textContent;this.textContent='Copied';setTimeout(()=>this.textContent=old,1200);
});
</script>
</body></html><?php }
function admin_badge(string $status): string { return '<span class="admin-badge status-'.e($status).'">'.e(strtoupper(str_replace('_',' ',$status))).'</span>'; }
