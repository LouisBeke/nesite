<?php
require __DIR__.'/app/bootstrap.php';
$u = require_user();

$servers = [];
$error = '';
$serviceMap = [];
try {
    $q = db()->prepare("SELECT id,ptero_identifier,ptero_server_id,status FROM services WHERE user_id=? AND status<>'terminated'");
    $q->execute([(int)$u['id']]);
    foreach ($q->fetchAll() as $svc) {
        if (!empty($svc['ptero_identifier'])) $serviceMap[(string)$svc['ptero_identifier']] = $svc;
    }
} catch (Throwable $e) {
}

if ($u['ptero_client_key']) {
    try {
        $r = ptero('/');
        $servers = $r['data'] ?? [];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>FoxNetwork | Dashboard</title>
<link rel="stylesheet" href="/css/fontawesome-all.min.css">
<link rel="stylesheet" href="/assets/portal.css?v=14.0">
<style>
.prov-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;margin-top:16px}
.prov-card{background:#15181d;border:1px solid #2a2e35;border-radius:14px;padding:14px}
.prov-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.prov-title{font-weight:800}
.prov-step{font-size:12px;color:#a8afbb}
.prov-bar{height:10px;border-radius:999px;background:#0d1116;overflow:hidden;margin:10px 0}
.prov-fill{height:100%;background:linear-gradient(90deg,#ff7417,#ff9f5a);width:0;transition:width .35s ease}
.prov-meta{display:flex;justify-content:space-between;font-size:12px;color:#9aa3b2}
.prov-logs{margin-top:10px;background:#0c0f13;border:1px solid #252b33;border-radius:10px;max-height:130px;overflow:auto;padding:8px}
.prov-log{font:12px/1.45 Consolas,monospace;color:#d1d7e0;padding:2px 0}
.prov-timeline{margin-top:8px;border-top:1px solid #252b33;padding-top:8px}
.prov-event{display:flex;gap:9px;align-items:flex-start;font-size:12px;padding:4px 0}
.prov-dot{width:8px;height:8px;border-radius:50%;background:#ff7417;margin-top:5px;flex:0 0 auto}
.status-chip{display:inline-flex;padding:5px 8px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase}
.status-chip.pending{background:#2a2e35;color:#c8ced8}
.status-chip.provisioning{background:#2d241a;color:#ffbb81}
.status-chip.active{background:#1a2f21;color:#8fe6b1}
.status-chip.failed{background:#3a1f24;color:#ff9fae}
.status-chip.suspended{background:#253040;color:#9ec5ff}
</style>
</head>
<body>
<div class="app">
<aside class="side">
<div class="brand"><img src="/images/logo.png"><span>FOX<b>NETWORK</b></span></div>
<nav class="nav">
<a class="active" href="/">Overview</a>
<a href="/store.php">Store</a>
<a href="/orders.php">Orders</a>
<a href="/billing.php">Billing</a>
<a href="/support.php">Support</a>
</nav>
<nav class="nav bottom">
<a href="/settings.php">Account Settings</a>
<a href="/logout.php">Sign out</a>
</nav>
</aside>
<main class="main">
<header>
<div class="profile"><div><b><?=e($u['name'])?></b><div class="muted" style="font-size:12px"><?=e(ucfirst($u['role']))?></div></div><div class="avatar"><?=e(strtoupper(substr($u['name'],0,1)))?></div></div>
</header>
<div class="content">
<div class="eyebrow">FoxNetwork Control Center</div>
<h1><?=greeting()?>, <?=e(explode(' ', $u['name'])[0])?>.</h1>
<div class="muted">Live server operations and provisioning timeline.</div>
<?php if(!$u['ptero_client_key']):?><div class="notice">Connect your Pterodactyl account in <a class="link" href="/settings.php">Account Settings</a> to load your servers.</div><?php endif?>
<?php if($error):?><div class="error"><?=e($error)?></div><?php endif?>

<section class="card" id="prov-section" style="display:none">
<div class="cardhead"><b>LIVE PROVISIONING STATUS</b><span class="muted" id="prov-summary">Loading…</span></div>
<div id="prov-grid" class="prov-grid">
<div class="muted">Checking provisioning queue…</div>
</div>
</section>

<section class="card" style="margin-top:20px">
<div class="cardhead"><b>MY SERVERS</b><span class="muted"><?=count($servers)?> server<?=count($servers)===1?'':'s'?></span></div>
<div class="servers">
<?php if(!$servers):?><div class="empty muted">No servers found.</div><?php endif?>
<?php foreach($servers as $row):
    $a=$row['attributes'];
    $id=(string)$a['identifier'];
    $service=(array)($serviceMap[$id] ?? []);
    $serviceId=(int)($service['id'] ?? 0);
?>
<article class="server" data-server="<?=e($id)?>">
<div class="server-top"><div><div class="server-name"><?=e($a['name'])?></div><div class="muted"><?=e($a['description']?:'FoxNetwork game server')?></div></div><div class="status offline" data-status>LOADING...</div></div>
<div class="metrics"><div class="metric"><span class="muted">CPU</span><b data-cpu>-</b></div><div class="metric"><span class="muted">Memory</span><b data-memory>-</b></div><div class="metric"><span class="muted">Disk</span><b data-disk>-</b></div></div>
<div class="buttons">
<button class="btn primary" data-power="start">Start</button>
<button class="btn" data-power="restart">Restart</button>
<button class="btn" data-power="stop">Stop</button>
<a class="btn primary" href="/server.php?id=<?=e($id)?>">Manage Service</a>
<?php if($serviceId>0):?><a class="btn" href="/upgrades.php?service=<?=$serviceId?>">Upgrade</a><?php endif?>
</div>
</article>
<?php endforeach?>
</div>
</section>
</div>
</main>
</div>
<script>
const CSRF=<?=json_encode(csrf())?>;
function em(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
function mb(n){return (n/1024/1024).toFixed(1)+' MB'}

async function refresh(card){
  const id=card.dataset.server;
  try{
    const r=await fetch('/api/resources.php?id='+encodeURIComponent(id));
    const j=await r.json();
    if(!j.ok) throw Error(j.error||'Unavailable');
    const a=j.data.attributes,s=a.current_state;
    const st=card.querySelector('[data-status]');
    st.textContent=s.toUpperCase();
    st.className='status '+(s==='running'?'online':(s==='starting'?'starting':'offline'));
    card.querySelector('[data-cpu]').textContent=(a.resources.cpu_absolute||0).toFixed(1)+'%';
    card.querySelector('[data-memory]').textContent=mb(a.resources.memory_bytes||0);
    card.querySelector('[data-disk]').textContent=mb(a.resources.disk_bytes||0);
  }catch(e){card.querySelector('[data-status]').textContent='UNAVAILABLE';}
}

async function power(card,signal){
  const buttons=card.querySelectorAll('button');
  buttons.forEach(b=>b.disabled=true);
  try{
    const r=await fetch('/api/power.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id:card.dataset.server,signal})});
    const j=await r.json();
    if(!j.ok) throw Error(j.error||'Power action failed');
    setTimeout(()=>refresh(card),800);
    setTimeout(()=>refresh(card),2500);
  }catch(e){alert(e.message||String(e));}
  finally{setTimeout(()=>buttons.forEach(b=>b.disabled=false),900);}
}

function renderProvisioning(items){
  const section=document.getElementById('prov-section');
  const box=document.getElementById('prov-grid');
  const summary=document.getElementById('prov-summary');
  if(!Array.isArray(items)||!items.length){
    if(section) section.style.display='none';
    box.innerHTML='';
    summary.textContent='0 jobs';
    return;
  }
  if(section) section.style.display='block';
  let active=0;
  const html=[];
  for(const it of items){
    const status=(it.status||'pending').toLowerCase();
    if(status==='provisioning'||status==='pending') active++;
    const logs=(it.logs||[]).slice(0,6).map(l=>`<div class="prov-log">${em(l.created_at||'')} · ${em(l.event_name||'')} ${l.message?(' - '+em(l.message)):''}</div>`).join('');
    const timeline=(it.timeline||[]).slice(-5).map(t=>`<div class="prov-event"><span class="prov-dot"></span><div><b>${em(t.event||'event')}</b><div class="muted">${em(t.time||'')}</div></div></div>`).join('');
    html.push(`<article class="prov-card">
      <div class="prov-head"><div><div class="prov-title">${em(it.name)}</div><div class="prov-step">${em(it.step||'Queued')}</div></div><span class="status-chip ${em(status)}">${em(status)}</span></div>
      <div class="prov-bar"><div class="prov-fill" style="width:${Math.max(0,Math.min(100,Number(it.progress||0)))}%"></div></div>
      <div class="prov-meta"><span>${Math.max(0,Math.min(100,Number(it.progress||0)))}%</span><span>ETA ${it.eta?em(new Date(it.eta.replace(' ','T')).toLocaleTimeString()):'—'}</span></div>
      ${it.last_error?`<div class="error" style="margin-top:8px">${em(it.last_error)}</div>`:''}
      <div class="prov-logs">${logs||'<div class="muted">No log events yet.</div>'}</div>
      <div class="prov-timeline">${timeline||'<div class="muted">Timeline will appear during execution.</div>'}</div>
    </article>`);
  }
  box.innerHTML=html.join('');
  summary.textContent=active+' active · '+items.length+' tracked';
}

async function refreshProvisioning(){
  try{
    const r=await fetch('/api/provisioning-status.php');
    const j=await r.json();
    if(!j.ok) throw Error(j.error||'Provisioning status unavailable');
    renderProvisioning(j.items||[]);
  }catch(e){
    const section=document.getElementById('prov-section');
    if(section) section.style.display='block';
    document.getElementById('prov-grid').innerHTML='<div class="error">'+em(e.message||String(e))+'</div>';
    document.getElementById('prov-summary').textContent='Unavailable';
  }
}

document.querySelectorAll('.server').forEach(card=>{
  card.querySelectorAll('[data-power]').forEach(b=>b.onclick=()=>power(card,b.dataset.power));
});

async function refreshAll(){
  const cards=[...document.querySelectorAll('.server')];
  await Promise.all(cards.map(c=>refresh(c)));
}

refreshAll();
refreshProvisioning();
setInterval(refreshAll,10000);
setInterval(refreshProvisioning,4000);
</script>
</body>
</html>
