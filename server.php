<?php
require __DIR__.'/app/bootstrap.php';
$u = require_user();
$id = preg_replace('/[^a-zA-Z0-9_-]/','',$_GET['id'] ?? '');
if(!$id){header('Location:/');exit;}

try{
  $srv = ptero('/servers/'.$id)['attributes'] ?? [];
}catch(Throwable $e){
  $err = $e->getMessage();
  $srv = ['name'=>'Server'];
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($srv['name']??'Server')?> | FoxNetwork</title>
<link rel="stylesheet" href="/css/fontawesome-all.min.css">
<link rel="stylesheet" href="/assets/portal.css?v=14.0">
<style>
.server-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:20px 0;padding:6px;background:#111419;border:1px solid #2a2e35;border-radius:13px;width:max-content;max-width:100%}
.server-tabs .tab{appearance:none;border:0;background:transparent;color:#aeb5c0;padding:11px 17px;border-radius:9px;cursor:pointer;font:700 14px Arial,sans-serif}
.server-tabs .tab:hover{background:#1b1f25;color:#fff}
.server-tabs .tab.active{background:#ff7417;color:#111}
.pane{display:none}.pane.active{display:block}
.manage-card{background:#15181d;border:1px solid #2a2e35;border-radius:15px;overflow:hidden;margin-bottom:18px}
.control-grid{display:flex;gap:10px;flex-wrap:wrap;padding:20px}
.control-grid .btn{min-width:100px}
.terminal{height:430px;background:#0a0d11;color:#dce2ea;padding:14px;font:13px/1.5 Consolas,monospace;overflow:auto;white-space:pre-wrap}
.commandbar{display:flex;gap:10px;padding:14px;background:#101318;border-top:1px solid #2a2e35}
.commandbar input{flex:1;min-width:0;height:42px;padding:0 12px;background:#0b0e12;color:#fff;border:1px solid #303640;border-radius:9px}
.listbox .listrow{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:12px 16px;border-top:1px solid #252a31}
.activity-log{max-height:420px;overflow:auto}
.activity-row{padding:11px 14px;border-top:1px solid #252a31}
.activity-row .meta{font-size:12px;color:#9ca4b0}
.status-pill{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.6px}
.status-dot{width:8px;height:8px;border-radius:50%;background:#9aa1ad}
.status-pill.connected .status-dot{background:#35d07f}
.status-pill.error .status-dot{background:#ff5f62}
</style>
</head>
<body>
<div class="app">
<aside class="side">
<div class="brand"><img src="/images/logo.png"><span>FOX<b>NETWORK</b></span></div>
<nav class="nav">
<a href="/">My Servers</a>
<a class="active" href="#">Manage Service</a>
</nav>
<nav class="nav bottom"><a href="/settings.php">Account Settings</a><a href="/logout.php">Sign out</a></nav>
</aside>
<main class="main">
<header><div class="profile"><div><b><?=e($u['name'])?></b><div class="muted" style="font-size:12px"><?=e(ucfirst($u['role']))?></div></div><div class="avatar"><?=e(strtoupper(substr($u['name'],0,1)))?></div></div></header>
<div class="content server-manager">
<div class="eyebrow">Game Server</div>
<h1><?=e($srv['name']??'Server')?></h1>
<div class="muted"><?=e($srv['description']??'FoxNetwork game server')?></div>
<?php if(!empty($err)):?><div class="error"><?=e($err)?></div><?php endif?>

<div class="server-tabs">
<button class="tab active" data-tab="overview">Overview</button>
<button class="tab" data-tab="console">Console</button>
<button class="tab" data-tab="databases">Databases</button>
<button class="tab" data-tab="backups">Backups</button>
<button class="tab" data-tab="schedules">Schedules</button>
<button class="tab" data-tab="network">Network</button>
<button class="tab" data-tab="startup">Startup</button>
<button class="tab" data-tab="activity">Activity Log</button>
</div>

<section class="pane active" id="overview">
<div class="stats">
<div class="stat"><span class="muted">Status</span><strong id="status">Loading…</strong></div>
<div class="stat"><span class="muted">CPU</span><strong id="cpu">—</strong></div>
<div class="stat"><span class="muted">Memory</span><strong id="memory">—</strong></div>
</div>
<div class="manage-card">
<div class="cardhead"><b>SERVICE CONTROLS</b></div>
<div class="control-grid">
<button class="btn primary" data-power="start">Start</button>
<button class="btn" data-power="restart">Restart</button>
<button class="btn" data-power="stop">Stop</button>
<button class="btn warning" id="reinstall">Reinstall</button>
<a class="btn" href="<?=e(rtrim(cfg('pterodactyl.url'),'/').'/server/'.$id)?>" target="_blank">Advanced Panel</a>
</div>
</div>
</section>

<section class="pane" id="console">
<div class="manage-card">
<div class="cardhead"><b>LIVE CONSOLE</b><span id="wsstate" class="status-pill"><span class="status-dot"></span><span class="status-text">Not connected</span></span></div>
<div id="terminal" class="terminal"></div>
<div class="commandbar"><input id="command" placeholder="Type a command"><button class="btn primary" id="sendcmd">Send</button></div>
</div>
</section>

<section class="pane" id="databases"><div class="manage-card"><div class="cardhead"><b>DATABASES</b></div><div id="dblist" class="listbox"></div></div></section>
<section class="pane" id="backups"><div class="manage-card"><div class="cardhead"><b>BACKUPS</b><button class="btn primary" id="createbackup">Create Backup</button></div><div id="backuplist" class="listbox"></div></div></section>
<section class="pane" id="schedules"><div class="manage-card"><div class="cardhead"><b>SCHEDULES</b></div><div id="schedulelist" class="listbox"></div></div></section>
<section class="pane" id="network"><div class="manage-card"><div class="cardhead"><b>NETWORK</b></div><div id="netlist" class="listbox"></div><div style="padding:16px;border-top:1px solid #252a31"><h3 style="margin:0 0 10px;font-size:14px">Assign additional allocation</h3><div style="display:flex;gap:10px;flex-wrap:wrap"><input id="allocip" placeholder="IP" style="height:40px;padding:0 10px;background:#0b0e12;color:#fff;border:1px solid #303640;border-radius:8px"><input id="allocport" type="number" min="1" placeholder="Port" style="width:120px;height:40px;padding:0 10px;background:#0b0e12;color:#fff;border:1px solid #303640;border-radius:8px"><input id="allocalias" placeholder="Alias (optional)" style="height:40px;padding:0 10px;background:#0b0e12;color:#fff;border:1px solid #303640;border-radius:8px"><button class="btn" id="addalloc">Add allocation</button></div><div class="muted small" style="margin-top:8px">Only available when enabled by the host.</div></div></div></section>
<section class="pane" id="startup"><div class="manage-card"><div class="cardhead"><b>STARTUP & VARIABLES</b></div><div id="startupbox" style="padding:16px" class="muted">Loading startup configuration…</div></div></section>
<section class="pane" id="activity"><div class="manage-card"><div class="cardhead"><b>ACTIVITY LOG</b></div><div id="activitylog" class="activity-log"></div></div></section>
</div>
</main>
</div>

<script>
const ID=<?=json_encode($id)?>,CSRF=<?=json_encode(csrf())?>;
const q=s=>document.querySelector(s);
function em(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
function mb(n){return (n/1024/1024).toFixed(1)+' MB'}

async function api(action,opt={}){
  let url='/api/manage.php?action='+encodeURIComponent(action)+'&id='+encodeURIComponent(ID);
  if(opt.path) url+='&path='+encodeURIComponent(opt.path);
  const init={headers:{'X-CSRF-Token':CSRF}};
  if(opt.body!==undefined){init.method='POST';init.headers['Content-Type']='application/json';init.body=JSON.stringify(opt.body)}
  const r=await fetch(url,init); const t=await r.text();
  let j; try{j=JSON.parse(t)}catch(e){throw Error('Invalid response');}
  if(!j.ok) throw Error(j.error||'Request failed');
  return j.data;
}

async function resources(){
  try{
    const r=await fetch('/api/resources.php?id='+encodeURIComponent(ID));
    const j=await r.json();
    const a=j.data.attributes;
    q('#status').textContent=(a.current_state||'unknown').toUpperCase();
    q('#cpu').textContent=(a.resources.cpu_absolute||0).toFixed(1)+'%';
    q('#memory').textContent=mb(a.resources.memory_bytes||0);
  }catch(e){q('#status').textContent='UNAVAILABLE';}
}

async function power(signal){
  const r=await fetch('/api/power.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id:ID,signal})});
  const j=await r.json();
  if(!j.ok) throw Error(j.error||'Power action failed');
  setTimeout(resources,900);
  setTimeout(loadActivity,1200);
}

document.querySelectorAll('[data-power]').forEach(b=>b.onclick=()=>power(b.dataset.power).catch(e=>alert(e.message)));
q('#reinstall').onclick=async()=>{
  if(!confirm('Reinstall this server now?')) return;
  try{await api('reinstall',{body:{confirm:true}}); alert('Reinstall requested.'); loadActivity();}
  catch(e){alert(e.message);}
};

document.querySelectorAll('.tab').forEach(b=>b.onclick=()=>{
  document.querySelectorAll('.tab,.pane').forEach(x=>x.classList.remove('active'));
  b.classList.add('active');
  q('#'+b.dataset.tab).classList.add('active');
  if(b.dataset.tab==='console') connectConsole();
  if(b.dataset.tab==='databases') loadDB();
  if(b.dataset.tab==='backups') loadBackups();
  if(b.dataset.tab==='schedules') loadSchedules();
  if(b.dataset.tab==='network') loadNetwork();
  if(b.dataset.tab==='startup') loadStartup();
  if(b.dataset.tab==='activity') loadActivity();
});

let ws=null;
function setWsState(text,type=''){const el=q('#wsstate');el.className='status-pill '+type;el.querySelector('.status-text').textContent=text;}
function appendConsole(line){const el=q('#terminal');el.textContent+=(line||'')+'\n';if(el.textContent.length>180000)el.textContent=el.textContent.slice(-120000);el.scrollTop=el.scrollHeight;}

async function connectConsole(){
  if(ws&&(ws.readyState===WebSocket.OPEN||ws.readyState===WebSocket.CONNECTING)) return;
  try{
    const d=await api('websocket');
    let socketUrl=(d.socket||'').trim();
    if(location.protocol==='https:'&&socketUrl.startsWith('ws://')) socketUrl='wss://'+socketUrl.slice(5);
    ws=new WebSocket(socketUrl);
    ws.onopen=()=>{setWsState('Authenticating…');ws.send(JSON.stringify({event:'auth',args:[d.token]}));};
    ws.onmessage=e=>{
      let m; try{m=JSON.parse(e.data);}catch(_){appendConsole(e.data);return;}
      if(m.event==='auth success'){setWsState('Connected','connected');ws.send(JSON.stringify({event:'send logs',args:[null]}));return;}
      if(m.event==='console output'){appendConsole(m.args?.[0]||'');return;}
      if(m.event==='status'){setWsState('Server: '+(m.args?.[0]||'unknown'),'connected');}
    };
    ws.onerror=()=>setWsState('Connection error','error');
    ws.onclose=()=>{setWsState('Disconnected','error');ws=null;};
  }catch(e){setWsState(e.message,'error');}
}

q('#sendcmd').onclick=async()=>{
  const cmd=q('#command').value.trim();
  if(!cmd) return;
  try{await api('command',{body:{command:cmd}}); q('#command').value='';}
  catch(e){alert(e.message);}
};
q('#command').addEventListener('keydown',e=>{if(e.key==='Enter') q('#sendcmd').click();});

async function loadDB(){
  try{const d=await api('databases'); q('#dblist').innerHTML=(d||[]).map(x=>{const a=x.attributes||{};return `<div class="listrow"><div><b>${em(a.name)}</b><div class="muted small">${em(a.host?.address||'')} : ${em(a.host?.port||'')}</div></div><span>${em(a.username||'')}</span></div>`;}).join('')||'<div class="empty muted">No databases.</div>';}
  catch(e){q('#dblist').innerHTML='<div class="error">'+em(e.message)+'</div>';}
}

async function loadBackups(){
  try{const d=await api('backups'); q('#backuplist').innerHTML=(d||[]).map(x=>{const a=x.attributes||{};return `<div class="listrow"><div><b>${em(a.name)}</b><div class="muted small">${a.is_successful?'Ready':'Processing'} · ${a.bytes?Math.round(a.bytes/1048576)+' MB':'—'}</div></div></div>`;}).join('')||'<div class="empty muted">No backups.</div>';}
  catch(e){q('#backuplist').innerHTML='<div class="error">'+em(e.message)+'</div>';}
}
q('#createbackup').onclick=async()=>{try{await api('create-backup',{body:{name:'Backup '+new Date().toLocaleString()}});loadBackups();loadActivity();}catch(e){alert(e.message);}};

async function loadSchedules(){
  try{const d=await api('schedules'); q('#schedulelist').innerHTML=(d||[]).map(x=>{const a=x.attributes||{};return `<div class="listrow"><div><b>${em(a.name||'Schedule')}</b><div class="muted small">${em((a.is_active?'Active':'Paused')+' · '+(a.cron?.minute||'*')+' '+(a.cron?.hour||'*')+' '+(a.cron?.day_of_month||'*')+' '+(a.cron?.month||'*')+' '+(a.cron?.day_of_week||'*'))}</div></div><span>${em(a.only_when_online?'Online only':'Always')}</span></div>`;}).join('')||'<div class="empty muted">No schedules.</div>';}
  catch(e){q('#schedulelist').innerHTML='<div class="error">'+em(e.message)+'</div>';}
}

async function loadNetwork(){
  try{const d=await api('network'); q('#netlist').innerHTML=(d||[]).map(x=>{const a=x.attributes||{};const id=a.id||0;return `<div class="listrow"><div><b>${em(a.ip_alias||a.ip)}:${em(a.port)}</b><div class="muted small">${a.is_default?'Primary allocation':'Additional allocation'}</div></div><div>${a.is_default?'<span class="muted small">Primary</span>':`<button class="btn" onclick="setPrimaryAllocation(${Number(id)||0})">Set primary</button>`}</div></div>`;}).join('')||'<div class="empty muted">No network allocations.</div>';}
  catch(e){q('#netlist').innerHTML='<div class="error">'+em(e.message)+'</div>';}
}

async function setPrimaryAllocation(allocationId){
  if(!allocationId) return;
  try{await api('set-primary-allocation',{body:{allocation_id:allocationId}});loadNetwork();loadActivity();}
  catch(e){alert(e.message);}
}

q('#addalloc').onclick=async()=>{
  const ip=q('#allocip').value.trim();
  const port=Number(q('#allocport').value||0);
  const alias=q('#allocalias').value.trim();
  if(!ip||!port){alert('IP and port are required.');return;}
  try{await api('add-allocation',{body:{ip,port,alias}});q('#allocport').value='';q('#allocalias').value='';loadNetwork();loadActivity();}
  catch(e){alert(e.message);}
};

async function loadStartup(){
  try{
    const d=await api('startup');
    const s=d.startup||{};
    const vars=(s.relationships?.variables?.data)||[];
    const startup=s.startup_command||s.startup||'';
    const image=s.docker_image||s.image||'';
    let html='';
    if(d.allow_custom_startup||d.allow_docker_image){
      html+='<div style="display:grid;grid-template-columns:1fr;gap:10px;margin-bottom:14px">';
      html+='<label class="muted small">Custom startup command</label><input id="startupcmd" value="'+em(startup)+'" style="height:40px;padding:0 10px;background:#0b0e12;color:#fff;border:1px solid #303640;border-radius:8px" '+(d.allow_custom_startup?'':'disabled')+'>';
      html+='<label class="muted small">Docker image</label><input id="startupimage" value="'+em(image)+'" style="height:40px;padding:0 10px;background:#0b0e12;color:#fff;border:1px solid #303640;border-radius:8px" '+(d.allow_docker_image?'':'disabled')+'>';
      html+='<div><button class="btn" id="savestartup">Save startup settings</button></div>';
      html+='</div>';
    }
    html+='<div class="muted small" style="margin-bottom:8px">Startup variables</div>';
    html+='<div class="listbox">';
    html+=(vars||[]).map(v=>{const a=v.attributes||{};const key=a.env_variable||'';const val=a.server_value??a.default_value??'';const editable=Boolean(d.allow_variable_edit);const sid='var_'+String(key).replace(/[^a-zA-Z0-9_-]/g,'_');return `<div class="listrow"><div><b>${em(a.name||key)}</b><div class="muted small">${em(key)}</div></div><div style="display:flex;gap:8px;align-items:center"><input id="${sid}" value="${em(val)}" style="height:36px;padding:0 10px;background:#0b0e12;color:#fff;border:1px solid #303640;border-radius:8px" ${editable?'':'disabled'}><button class="btn" ${editable?'':'disabled'} onclick="saveStartupVar('${em(key)}','${sid}')">Save</button></div></div>`;}).join('');
    html+='</div>';
    if(!vars.length) html+='<div class="empty muted" style="margin-top:10px">No startup variables exposed by this egg.</div>';
    q('#startupbox').innerHTML=html;
    const saveBtn=q('#savestartup');
    if(saveBtn){
      saveBtn.onclick=async()=>{
        try{await api('set-startup',{body:{startup:(q('#startupcmd')?.value||''),image:(q('#startupimage')?.value||'')}});alert('Startup settings updated.');loadStartup();loadActivity();}
        catch(e){alert(e.message);}
      };
    }
  }catch(e){q('#startupbox').innerHTML='<div class="error">'+em(e.message)+'</div>';}
}

async function saveStartupVar(key, domId){
  try{const val=document.getElementById(domId)?.value??'';await api('set-startup-variable',{body:{key:key,value:val}});loadActivity();}
  catch(e){alert(e.message);}
}

async function loadActivity(){
  try{
    const d=await api('activity');
    q('#activitylog').innerHTML=(d||[]).map(a=>`<div class="activity-row"><div><b>${em(a.action||'event')}</b></div><div>${em(a.details||'')}</div><div class="meta">${em(a.created_at||'')}</div></div>`).join('')||'<div class="empty muted">No activity yet.</div>';
  }catch(e){q('#activitylog').innerHTML='<div class="error">'+em(e.message)+'</div>';}
}

resources();
setInterval(resources,10000);
loadActivity();
</script>
</body>
</html>
