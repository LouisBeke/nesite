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
.file-toolbar{display:flex;gap:10px;flex-wrap:wrap;padding:14px;border-bottom:1px solid #2a2e35;background:#101318}
.file-toolbar input{height:40px;padding:0 10px;background:#0b0e12;color:#fff;border:1px solid #303640;border-radius:8px;min-width:260px}
.file-layout{display:grid;grid-template-columns:320px 1fr;min-height:520px}
.file-list{border-right:1px solid #252a31;overflow:auto;max-height:520px}
.file-entry{display:flex;align-items:center;gap:10px;width:100%;text-align:left;background:none;border:0;color:#dbe1ea;padding:10px 14px;border-top:1px solid #252a31;cursor:pointer}
.file-entry:hover,.file-entry.active{background:#1a1f27}
.file-entry .entry-icon{width:18px;text-align:center;color:#ff7417;flex:0 0 18px}
.file-entry .entry-icon svg{display:block;width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.file-entry .entry-name{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.file-editor{display:flex;flex-direction:column;min-height:520px}
.file-meta{padding:12px 14px;border-bottom:1px solid #252a31;color:#9ca4b0;font-size:12px}
.file-editor textarea{flex:1;min-height:360px;border:0;resize:vertical;background:#0b0e12;color:#dce2ea;font:13px/1.5 Consolas,monospace;padding:14px}
.file-actions{display:flex;justify-content:flex-end;gap:10px;padding:12px 14px;border-top:1px solid #252a31;background:#101318}
@media (max-width:980px){.file-layout{grid-template-columns:1fr}.file-list{border-right:0;border-bottom:1px solid #252a31;max-height:260px}}
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
<nav class="nav bottom"><?php if(($u['role'] ?? '') === 'admin'): ?><a href="/admin/"><span>Admin</span></a><?php endif?><a href="/settings.php">Account Settings</a><a href="/logout.php">Sign out</a></nav>
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
<button class="tab" data-tab="files">Files</button>
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

<section class="pane" id="files">
<div class="manage-card">
<div class="cardhead"><b>FILES</b></div>
<div class="file-toolbar">
  <input id="filepath" value="/" placeholder="Directory path, e.g. / or /plugins">
  <button class="btn" id="loadfiles">Load folder</button>
  <button class="btn" id="goup">Go up</button>
</div>
<div class="file-layout">
  <div id="filelist" class="file-list"></div>
  <div class="file-editor">
    <div class="file-meta" id="filemeta">Select a file to view or edit.</div>
    <textarea id="filecontent" placeholder="File content will appear here" spellcheck="false"></textarea>
    <div class="file-actions">
      <button class="btn" id="reloadfile">Reload file</button>
      <button class="btn primary" id="savefile">Save file</button>
    </div>
  </div>
</div>
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
function stripAnsi(text){return String(text??'').replace(/\x1b\[[0-9;]*[A-Za-z]/g,'');}

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
  if(b.dataset.tab==='files') loadFiles();
  if(b.dataset.tab==='databases') loadDB();
  if(b.dataset.tab==='backups') loadBackups();
  if(b.dataset.tab==='schedules') loadSchedules();
  if(b.dataset.tab==='network') loadNetwork();
  if(b.dataset.tab==='startup') loadStartup();
  if(b.dataset.tab==='activity') loadActivity();
});

let ws=null;
function setWsState(text,type=''){const el=q('#wsstate');el.className='status-pill '+type;el.querySelector('.status-text').textContent=text;}

function escapeHtml(text){return String(text??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}

function ansiToHtml(text){
  const colors=['#000000','#cd3131','#0dbc79','#e5e510','#2472c8','#bc3fbc','#11a8cd','#e5e5e5'];
  const bright=['#666666','#f14c4c','#23d18b','#f5f543','#3b8eea','#d670d6','#29b8db','#ffffff'];
  let html='';
  let open=false;
  let fg='';
  let bg='';
  let bold=false;
  const closeSpan=()=>{if(open){html+='</span>';open=false;}};
  const openSpan=()=>{closeSpan(); const styles=[]; if(fg) styles.push('color:'+fg); if(bg) styles.push('background-color:'+bg); if(bold) styles.push('font-weight:700'); if(styles.length){html+='<span style="'+styles.join(';')+'">'; open=true;}};
  const applyCode=(code)=>{
    if(code===0){fg='';bg='';bold=false;openSpan();return;}
    if(code===1){bold=true;openSpan();return;}
    if(code===22){bold=false;openSpan();return;}
    if(code===39){fg='';openSpan();return;}
    if(code===49){bg='';openSpan();return;}
    if(code>=30&&code<=37){fg=colors[code-30];openSpan();return;}
    if(code>=90&&code<=97){fg=bright[code-90];openSpan();return;}
    if(code>=40&&code<=47){bg=colors[code-40];openSpan();return;}
    if(code>=100&&code<=107){bg=bright[code-100];openSpan();return;}
  };
  const parts=String(text??'').split(/(\x1b\[[0-9;]*m)/g);
  for(const part of parts){
    if(!part) continue;
    const match=/^\x1b\[([0-9;]*)m$/.exec(part);
    if(match){
      const codes=(match[1]||'0').split(';').filter(Boolean).map(n=>parseInt(n,10));
      if(!codes.length) applyCode(0);
      else for(const code of codes) applyCode(Number.isFinite(code)?code:0);
      continue;
    }
    html+=escapeHtml(part).replace(/\n/g,'<br>');
  }
  closeSpan();
  return html;
}

function appendConsole(line){const el=q('#terminal');el.insertAdjacentHTML('beforeend',ansiToHtml(line)+'<br>');if(el.innerHTML.length>180000)el.innerHTML=el.innerHTML.slice(-120000);el.scrollTop=el.scrollHeight;}

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

let currentDir='/';
let currentFilePath='';

function normalizePath(path){
  let p=String(path||'/').trim();
  if(!p.startsWith('/')) p='/'+p;
  p=p.replace(/\\+/g,'/').replace(/\/+/g,'/');
  return p || '/';
}

function dirname(path){
  const p=normalizePath(path);
  if(p==='/'||!p.includes('/')) return '/';
  const i=p.lastIndexOf('/');
  return i<=0?'/':p.slice(0,i);
}

function joinPath(dir,name){
  const d=normalizePath(dir);
  if(d==='/') return '/'+name;
  return d.replace(/\/$/,'')+'/'+name;
}

function basename(path){
  const p=normalizePath(path);
  if(p==='/') return '/';
  const i=p.lastIndexOf('/');
  return i===-1?p:p.slice(i+1);
}

function fileEntryIcon(type){
  if(type==='up') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V6"></path><path d="M6.5 11.5 12 6l5.5 5.5"></path></svg>';
  if(type==='dir') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 7.5h6l2 2H20.5v7.5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2v-7.5a2 2 0 0 1 2-2Z"></path></svg>';
  return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3.5h7l4 4V20a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 20V5A1.5 1.5 0 0 1 7.5 3.5Z"></path><path d="M14 3.5V8h4"></path></svg>';
}

async function loadFiles(path){
  const target=normalizePath(path||q('#filepath')?.value||currentDir||'/');
  currentDir=target;
  if(q('#filepath')) q('#filepath').value=target;
  try{
    const d=await api('files',{path:target});
    const rows=(d||[]).map(x=>x.attributes||{});
    const dirs=[]; const files=[];
    for(const r of rows){ if(r.is_file) files.push(r); else dirs.push(r); }
    dirs.sort((a,b)=>String(a.name||'').localeCompare(String(b.name||'')));
    files.sort((a,b)=>String(a.name||'').localeCompare(String(b.name||'')));
    const html=[];
    html.push('<button class="file-entry" data-type="dir" data-path="'+em(dirname(target))+'"><span class="entry-icon">'+fileEntryIcon('up')+'</span><span class="entry-name">(parent)</span></button>');
    for(const r of dirs){const p=joinPath(target,String(r.name||''));html.push('<button class="file-entry" data-type="dir" data-path="'+em(p)+'"><span class="entry-icon">'+fileEntryIcon('dir')+'</span><span class="entry-name">'+em(String(r.name||''))+'</span></button>');}
    for(const r of files){const p=joinPath(target,String(r.name||''));html.push('<button class="file-entry" data-type="file" data-path="'+em(p)+'"><span class="entry-icon">'+fileEntryIcon('file')+'</span><span class="entry-name">'+em(String(r.name||''))+'</span></button>');}
    q('#filelist').innerHTML=html.join('')||'<div class="muted" style="padding:14px">No files found.</div>';
    if(!files.length) q('#filemeta').textContent='Folder loaded. No files in this directory.';
  }catch(e){
    q('#filelist').innerHTML='<div class="error" style="margin:12px">'+em(e.message||String(e))+'</div>';
  }
}

async function openFile(path){
  currentFilePath=normalizePath(path);
  try{
    const content=await api('file-content',{path:currentFilePath});
    q('#filecontent').value=String(content||'');
    q('#filemeta').textContent='Editing '+currentFilePath;
    document.querySelectorAll('.file-entry').forEach(el=>el.classList.remove('active'));
    document.querySelectorAll('.file-entry[data-type="file"]').forEach(el=>{if((el.getAttribute('data-path')||'')===currentFilePath)el.classList.add('active');});
  }catch(e){
    q('#filemeta').textContent='Could not open file: '+(e.message||String(e));
  }
}

document.addEventListener('click',e=>{
  const btn=e.target.closest('.file-entry');
  if(!btn) return;
  const type=btn.getAttribute('data-type')||'';
  const path=btn.getAttribute('data-path')||'/';
  if(type==='dir') loadFiles(path);
  if(type==='file') openFile(path);
});

q('#loadfiles').onclick=()=>loadFiles();
q('#goup').onclick=()=>loadFiles(dirname(currentDir));
q('#reloadfile').onclick=()=>{ if(currentFilePath) openFile(currentFilePath); };
q('#savefile').onclick=async()=>{
  if(!currentFilePath){alert('Select a file first.');return;}
  try{
    await api('save-file',{body:{path:currentFilePath,content:q('#filecontent').value}});
    q('#filemeta').textContent='Saved '+basename(currentFilePath)+' at '+new Date().toLocaleTimeString();
    loadActivity();
  }catch(e){
    alert(e.message||String(e));
  }
};

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
loadFiles('/');
</script>
</body>
</html>
