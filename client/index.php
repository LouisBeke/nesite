<?php
declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';
$u = require_user();

$servers = [];
$error = '';
$serviceMap = [];
$serviceRows = [];
$dashboard = [
    'active_services' => 0,
    'total_services' => 0,
    'open_tickets' => 0,
    'unpaid_invoices' => 0,
    'unpaid_total' => 0.0,
    'currency' => (string)setting('currency', 'EUR'),
    'orders' => 0,
];
$nextRenewal = null;

try {
    $q = db()->prepare("SELECT id,name,provisioning_provider,linode_instance_id,linode_ipv4,linode_ipv6,ptero_identifier,ptero_server_id,status,price_monthly,currency,next_due_at,is_trial FROM services WHERE user_id=? AND status<>'terminated' ORDER BY FIELD(status,'active','provisioning','pending','suspended','failed','cancelled'),id DESC");
    $q->execute([(int)$u['id']]);
    $serviceRows = $q->fetchAll();
    $dashboard['total_services'] = count($serviceRows);
    foreach ($serviceRows as $svc) {
        if ((string)$svc['status'] === 'active') $dashboard['active_services']++;
        if (!empty($svc['ptero_identifier'])) $serviceMap[(string)$svc['ptero_identifier']] = $svc;
        if (linode_service_provider($svc)==='linode') $serviceMap[(string)$svc['id']] = $svc;
        if (in_array((string)$svc['status'], ['active', 'suspended', 'provisioning', 'pending'], true) && !empty($svc['next_due_at']) && strtotime((string)$svc['next_due_at']) !== false) {
            if ($nextRenewal === null || strtotime((string)$svc['next_due_at']) < strtotime((string)$nextRenewal['next_due_at'])) {
                $nextRenewal = $svc;
            }
        }
    }

    $q = db()->prepare("SELECT COUNT(*) invoice_count,COALESCE(SUM(total),0) invoice_total,MAX(currency) currency FROM invoices WHERE user_id=? AND status IN ('unpaid','overdue')");
    $q->execute([(int)$u['id']]);
    $invoiceStats = $q->fetch() ?: [];
    $dashboard['unpaid_invoices'] = (int)($invoiceStats['invoice_count'] ?? 0);
    $dashboard['unpaid_total'] = (float)($invoiceStats['invoice_total'] ?? 0);
    if (!empty($invoiceStats['currency'])) $dashboard['currency'] = (string)$invoiceStats['currency'];

    $q = db()->prepare("SELECT COUNT(*) FROM support_tickets WHERE user_id=? AND status<>'closed'");
    $q->execute([(int)$u['id']]);
    $dashboard['open_tickets'] = (int)$q->fetchColumn();

    $q = db()->prepare('SELECT COUNT(*) FROM orders WHERE user_id=?');
    $q->execute([(int)$u['id']]);
    $dashboard['orders'] = (int)$q->fetchColumn();
} catch (Throwable $e) {
    error_log('FoxNetwork client dashboard summary failed: '.$e->getMessage());
}

if (!empty($u['ptero_client_key'])) {
    try {
        $r = ptero('/');
        $servers = $r['data'] ?? [];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (!$servers && !empty($u['ptero_user_id'])) {
    foreach ($serviceRows as $svc) {
        $identifier = (string)($svc['ptero_identifier'] ?? '');
        if ($identifier === '' && !empty($svc['ptero_server_id'])) {
            try {
                $srv = app_ptero('/servers/'.(int)$svc['ptero_server_id']);
                $identifier = (string)($srv['attributes']['identifier'] ?? '');
                if ($identifier !== '') {
                    db()->prepare('UPDATE services SET ptero_identifier=? WHERE id=?')->execute([$identifier, (int)$svc['id']]);
                    $svc['ptero_identifier'] = $identifier;
                    $serviceMap[$identifier] = $svc;
                }
            } catch (Throwable $e) {
                if (ptero_deleted_server_error($e->getMessage())) {
                    $cleared=clear_deleted_ptero_service_link((int)$svc['id'], (int)$u['id']);
                    if (!$cleared) {
                        try {
                            $fresh=service_row((int)$svc['id']);
                            $identifier=(string)($fresh['ptero_identifier']??'');
                            if ($identifier!=='') {
                                $svc['ptero_identifier']=$identifier;
                                $serviceMap[$identifier]=$svc;
                            }
                        } catch (Throwable $ignore) {
                        }
                    }
                }
            }
        }
        if ($identifier === '') continue;
        $servers[] = [
            'attributes' => [
                'identifier' => $identifier,
                'name' => (string)($svc['name'] ?? ('Service #'.(int)$svc['id'])),
                'description' => 'FoxNetwork game server',
            ],
        ];
    }
}

foreach($serviceRows as $svc){
    if(linode_service_provider($svc)!=='linode')continue;
    $servers[]=['attributes'=>['identifier'=>(string)$svc['id'],'name'=>(string)$svc['name'],'description'=>!empty($svc['linode_ipv4'])?'IPv4 '.$svc['linode_ipv4']:'Cloud VPS provisioning','provider'=>'linode']];
}

$nameParts = preg_split('/\s+/', trim((string)$u['name'])) ?: [];
$firstName = (string)($nameParts[0] ?? $u['name']);
$hasClientKey = !empty($u['ptero_client_key']);
$hasPteroLink = $hasClientKey;
$initial = mb_strtoupper(mb_substr(trim((string)$u['name']), 0, 1));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#0b0d10">
    <title>FoxNetwork | Control Center</title>
    <link rel="stylesheet" href="/css/fontawesome-all.min.css">
    <link rel="stylesheet" href="/assets/portal.css?v=<?=rawurlencode((string)@filemtime(__DIR__.'/../assets/portal.css'))?>">
</head>
<body class="client-body">
<div class="client-shell">
    <?php render_client_sidebar($u, 'overview', (int)$dashboard['open_tickets'], 'client'); ?>

    <main class="client-main">
        <header class="client-topbar">
            <div class="client-page-title">
                <span>Control Center</span>
                <small>Overview</small>
            </div>
            <div class="client-top-actions">
                <a class="topbar-action" href="/support.php" aria-label="Open support"><i class="far fa-question-circle" aria-hidden="true"></i></a>
                <a class="client-profile" href="/settings-profile.php">
                    <span class="client-profile-copy"><b><?=e($u['name'])?></b><small><?=e(ucfirst((string)$u['role']))?> account</small></span>
                    <span class="client-avatar"><?=e($initial)?></span>
                </a>
            </div>
        </header>

        <div class="client-content">
            <section class="client-hero">
                <div class="hero-copy">
                    <div class="client-eyebrow"><span></span> FOXNETWORK CONTROL CENTER</div>
                    <h1><?=e(greeting())?>, <?=e($firstName)?></h1>
                    <p>Everything you need to monitor, manage, and grow your services—without the clutter.</p>
                </div>
                <div class="hero-actions">
                    <a class="client-btn client-btn-ghost" href="/support.php"><i class="far fa-comment-dots" aria-hidden="true"></i> Get support</a>
                    <a class="client-btn client-btn-primary" href="/store.php"><i class="fas fa-plus" aria-hidden="true"></i> Add a service</a>
                </div>
                <div class="hero-orb" aria-hidden="true"></div>
            </section>

            <?php if(!$hasPteroLink): ?>
                <div class="client-alert client-alert-info">
                    <span class="alert-icon"><i class="fas fa-cog" aria-hidden="true"></i></span>
                    <div><b>Server controls are being configured</b><span>FoxNetwork connects game-server controls automatically. Contact support if this message remains visible.</span></div>
                    <a href="/support.php">Get support <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                </div>
            <?php endif ?>
            <?php if($error): ?>
                <div class="client-alert client-alert-error">
                    <span class="alert-icon"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></span>
                    <div><b>Live server data is temporarily unavailable</b><span><?=e($error)?></span></div>
                </div>
            <?php endif ?>

            <section class="client-stats" aria-label="Account summary">
                <article class="client-stat">
                    <span class="stat-icon stat-icon-orange"><i class="fas fa-server" aria-hidden="true"></i></span>
                    <div><small>Active services</small><strong><?=e($dashboard['active_services'])?></strong><span><?=e($dashboard['total_services'])?> total service<?=((int)$dashboard['total_services']===1?'':'s')?></span></div>
                </article>
                <article class="client-stat">
                    <span class="stat-icon stat-icon-green"><i class="fas fa-heartbeat" aria-hidden="true"></i></span>
                    <div><small>Infrastructure</small><strong class="stat-word"><?=$hasPteroLink?'Linked':'Setup'?></strong><span><?=$hasPteroLink?'Account connected':'Action required'?></span></div>
                </article>
                <article class="client-stat">
                    <span class="stat-icon stat-icon-blue"><i class="fas fa-file-invoice" aria-hidden="true"></i></span>
                    <div><small>Outstanding</small><strong><?=e(number_format((float)$dashboard['unpaid_total'], 2))?> <sup><?=e($dashboard['currency'])?></sup></strong><span><?=e($dashboard['unpaid_invoices'])?> open invoice<?=((int)$dashboard['unpaid_invoices']===1?'':'s')?></span></div>
                </article>
                <article class="client-stat">
                    <span class="stat-icon stat-icon-purple"><i class="fas fa-life-ring" aria-hidden="true"></i></span>
                    <div><small>Support</small><strong><?=e($dashboard['open_tickets'])?></strong><span>open ticket<?=((int)$dashboard['open_tickets']===1?'':'s')?></span></div>
                </article>
            </section>

            <section class="dashboard-section provisioning-section" id="prov-section" hidden>
                <div class="dashboard-section-head">
                    <div><span class="section-kicker">Live activity</span><h2>Provisioning</h2><p>Follow new services from queue to completion.</p></div>
                    <span class="section-count" id="prov-summary">Loading…</span>
                </div>
                <div id="prov-grid" class="client-provision-grid"><div class="client-empty compact"><span class="empty-loader"></span><p>Checking provisioning activity…</p></div></div>
            </section>

            <div class="client-dashboard-grid">
                <section class="dashboard-section server-section" id="services">
                    <div class="dashboard-section-head">
                        <div><span class="section-kicker">Infrastructure</span><h2>Your servers</h2><p>Live performance and service controls.</p></div>
                        <span class="section-count"><?=count($servers)?> server<?=count($servers)===1?'':'s'?></span>
                    </div>

                    <div class="client-server-grid">
                        <?php if(!$servers): ?>
                            <div class="client-empty">
                                <span class="empty-illustration"><i class="fas fa-server" aria-hidden="true"></i></span>
                                <h3>No servers here yet</h3>
                                <p>Choose a plan and your new service will appear here automatically.</p>
                                <a class="client-btn client-btn-primary" href="/store.php">Explore services</a>
                            </div>
                        <?php endif ?>

                        <?php foreach($servers as $row):
                            $a = (array)($row['attributes'] ?? []);
                            $id = (string)($a['identifier'] ?? '');
                            if ($id === '') continue;
                            $service = (array)($serviceMap[$id] ?? []);
                            $serviceId = (int)($service['id'] ?? 0);
                            $localStatus = (string)($service['status'] ?? 'unknown');
                            $serviceProvider = linode_service_provider($service);
                            $canPower = $serviceProvider==='linode'?!empty($service['linode_instance_id'])&&in_array($localStatus,['active','provisioning'],true):$hasClientKey;
                        ?>
                        <article class="client-server-card server" data-server="<?=e($id)?>" data-provider="<?=e($serviceProvider)?>" data-can-power="<?=$canPower?'1':'0'?>" data-local-status="<?=e($localStatus)?>">
                            <div class="client-server-head">
                                <div class="server-identity">
                                    <span class="server-icon"><i class="fas fa-cube" aria-hidden="true"></i></span>
                                    <div><small><?=$serviceProvider==='linode'?'CLOUD VPS':'GAME SERVER'?></small><h3><?=e($a['name'] ?? 'FoxNetwork server')?></h3><p><?=e(($a['description'] ?? '') ?: 'FoxNetwork managed service')?></p></div>
                                </div>
                                <span class="client-server-status is-loading" data-status><i></i> Loading</span>
                            </div>

                            <div class="client-metrics">
                                <div><span><i class="fas fa-microchip" aria-hidden="true"></i> CPU</span><b data-cpu>—</b></div>
                                <div><span><i class="fas fa-memory" aria-hidden="true"></i> Memory</span><b data-memory>—</b></div>
                                <div><span><i class="fas fa-hdd" aria-hidden="true"></i> Disk</span><b data-disk>—</b></div>
                            </div>

                            <div class="client-server-actions">
                                <div class="power-actions" aria-label="Server power controls">
                                    <button type="button" class="power-btn power-start" data-power="start" <?=!$canPower?'disabled title="Server connection required for power controls"':''?>><i class="fas fa-play" aria-hidden="true"></i><span>Start</span></button>
                                    <button type="button" class="power-btn" data-power="restart" <?=!$canPower?'disabled title="Server connection required for power controls"':''?>><i class="fas fa-redo" aria-hidden="true"></i><span>Restart</span></button>
                                    <button type="button" class="power-btn power-stop" data-power="stop" <?=!$canPower?'disabled title="Server connection required for power controls"':''?>><i class="fas fa-stop" aria-hidden="true"></i><span>Stop</span></button>
                                </div>
                                <div class="manage-actions">
                                    <?php if($serviceId>0): ?><a href="/upgrades.php?service=<?=$serviceId?>" aria-label="Upgrade <?=e($a['name'] ?? 'server')?>"><i class="fas fa-level-up-alt" aria-hidden="true"></i> Upgrade</a><?php endif ?>
                                    <a class="manage-link" href="<?=$serviceProvider==='linode'?('/vps.php?id='.$serviceId):('/server.php?id='.rawurlencode($id))?>">Manage <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                                </div>
                            </div>
                        </article>
                        <?php endforeach ?>
                    </div>
                </section>

                <aside class="client-dashboard-rail">
                    <section class="rail-card">
                        <div class="rail-head"><div><span class="section-kicker">Shortcuts</span><h2>Quick actions</h2></div><i class="fas fa-bolt" aria-hidden="true"></i></div>
                        <div class="quick-actions">
                            <a href="/store.php"><span class="quick-icon"><i class="fas fa-plus" aria-hidden="true"></i></span><span><b>New service</b><small>Browse hosting plans</small></span><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                            <a href="/billing.php"><span class="quick-icon"><i class="fas fa-credit-card" aria-hidden="true"></i></span><span><b>Billing</b><small>Invoices and payments</small></span><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                            <a href="/support.php"><span class="quick-icon"><i class="far fa-comment-alt" aria-hidden="true"></i></span><span><b>Open a ticket</b><small>Talk to our support team</small></span><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                            <a href="/settings-profile.php"><span class="quick-icon"><i class="fas fa-user-cog" aria-hidden="true"></i></span><span><b>Account</b><small>Profile, security and sessions</small></span><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                        </div>
                    </section>

                    <section class="rail-card connection-card">
                        <div class="connection-status <?=$hasPteroLink?'is-connected':'needs-setup'?>"><i class="fas <?=$hasPteroLink?'fa-check':'fa-link'?>" aria-hidden="true"></i></div>
                        <div><span class="section-kicker">Server connection</span><h3><?=$hasPteroLink?'Everything is connected':'Automatic setup pending'?></h3><p><?=$hasClientKey?'Live metrics and power controls are ready.':'FoxNetwork is configuring the secure server connection automatically.'?></p></div>
                        <a href="<?=$hasClientKey?'/services.php':'/support.php'?>"><?=$hasClientKey?'View services':'Get support'?> <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                    </section>

                    <section class="rail-card renewal-card">
                        <div class="rail-head"><div><span class="section-kicker"><?=!empty($nextRenewal['is_trial'])?'Trial expiration':'Next renewal'?></span><h2><?=e($nextRenewal['next_due_at'] ?? '')?date('d M Y', strtotime((string)$nextRenewal['next_due_at'])):'Nothing scheduled'?></h2></div><span class="calendar-icon"><i class="far fa-calendar-alt" aria-hidden="true"></i></span></div>
                        <?php if($nextRenewal): ?><p><?=e($nextRenewal['name'])?> · <?=e(number_format((float)$nextRenewal['price_monthly'], 2))?> <?=e($nextRenewal['currency'])?><?=!empty($nextRenewal['is_trial'])?' after trial':''?></p><?php else: ?><p>Your next renewal date will appear here.</p><?php endif ?>
                        <a href="/billing.php">View billing <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                    </section>
                </aside>
            </div>
        </div>
    </main>
</div>

<div class="client-toast" id="client-toast" role="status" aria-live="polite"></div>

<script>
const CSRF=<?=json_encode(csrf())?>;
const HAS_CLIENT_KEY=<?=json_encode($hasClientKey)?>;

function escapeHtml(value){return String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));}
function megabytes(bytes){return (Number(bytes||0)/1024/1024).toFixed(1)+' MB';}
function notify(message,type='info'){
    const toast=document.getElementById('client-toast');
    toast.textContent=message;
    toast.className='client-toast is-visible '+(type==='error'?'is-error':'');
    clearTimeout(notify.timer);
    notify.timer=setTimeout(()=>toast.classList.remove('is-visible'),3200);
}
function localStatusLabel(status){
    const value=String(status||'').toLowerCase();
    if(value==='active')return 'Running';
    if(value==='suspended')return 'Suspended';
    if(value==='failed')return 'Failed';
    if(value==='pending'||value==='provisioning')return 'Provisioning';
    if(value==='cancelled')return 'Cancelled';
    return 'Unavailable';
}
function localStatusClass(status){
    const value=String(status||'').toLowerCase();
    if(value==='active')return 'is-online';
    if(value==='pending'||value==='provisioning')return 'is-starting';
    if(value==='failed')return 'is-error';
    return 'is-offline';
}
function setServerStatus(card,label,state){
    const status=card.querySelector('[data-status]');
    status.innerHTML='<i></i>'+escapeHtml(label);
    status.className='client-server-status '+state;
    card.dataset.state=state;
}
async function refreshServer(card){
    if(!HAS_CLIENT_KEY&&card.dataset.provider!=='linode'){
        const local=card.dataset.localStatus||'unknown';
        setServerStatus(card,localStatusLabel(local),localStatusClass(local));
        return;
    }
    try{
        const response=await fetch('/api/resources.php?id='+encodeURIComponent(card.dataset.server),{headers:{'Accept':'application/json'}});
        const result=await response.json();
        if(!result.ok)throw new Error(result.error||'Server data unavailable');
        const attributes=result.data.attributes;
        const state=String(attributes.current_state||'offline');
        setServerStatus(card,state.charAt(0).toUpperCase()+state.slice(1),state==='running'?'is-online':(state==='starting'?'is-starting':'is-offline'));
        card.querySelector('[data-cpu]').textContent=Number(attributes.resources.cpu_absolute||0).toFixed(1)+'%';
        card.querySelector('[data-memory]').textContent=megabytes(attributes.resources.memory_bytes);
        card.querySelector('[data-disk]').textContent=megabytes(attributes.resources.disk_bytes);
    }catch(error){
        setServerStatus(card,'Unavailable','is-error');
    }
}
async function powerServer(card,signal){
    const buttons=[...card.querySelectorAll('[data-power]')];
    buttons.forEach(button=>button.disabled=true);
    card.setAttribute('aria-busy','true');
    try{
        const response=await fetch('/api/power.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id:card.dataset.server,signal})});
        const result=await response.json();
        if(!result.ok)throw new Error(result.error||'Power action failed');
        notify(signal.charAt(0).toUpperCase()+signal.slice(1)+' command sent.');
        setTimeout(()=>refreshServer(card),800);
        setTimeout(()=>refreshServer(card),2600);
    }catch(error){
        notify(error.message||String(error),'error');
    }finally{
        card.removeAttribute('aria-busy');
        setTimeout(()=>buttons.forEach(button=>button.disabled=card.dataset.provider==='linode'?card.dataset.canPower!=='1':!HAS_CLIENT_KEY),900);
    }
}
function renderProvisioning(items,stats={}){
    const section=document.getElementById('prov-section');
    const grid=document.getElementById('prov-grid');
    const summary=document.getElementById('prov-summary');
    if(!Array.isArray(items)||!items.length){section.hidden=true;grid.innerHTML='';summary.textContent='0 jobs';return;}
    section.hidden=false;
    let active=0;
    grid.innerHTML=items.map(item=>{
        const status=String(item.status||'pending').toLowerCase();
        const queueStatus=String(item.queue?.status||status).toLowerCase();
        const progress=Math.max(0,Math.min(100,Number(item.progress||0)));
        if(status==='provisioning'||status==='pending')active++;
        const logs=(item.logs||[]).slice(0,4).map(log=>`<div><time>${escapeHtml(log.created_at||'')}</time><span>${escapeHtml(String(log.event_name||'Update').replace(/linode/ig,'cloud'))}${log.message?' · '+escapeHtml(String(log.message).replace(/linode/ig,'cloud provider')):''}</span></div>`).join('');
        return `<article class="client-provision-card">
            <div class="provision-card-head"><div><small>${escapeHtml(item.step||'Queued')}</small><h3>${escapeHtml(item.name||'New service')}</h3></div><span class="provision-chip ${escapeHtml(queueStatus)}">${item.queue?.position?'QUEUE #'+Number(item.queue.position):escapeHtml(queueStatus)}</span></div>
            <div class="provision-progress"><span style="width:${progress}%"></span></div>
            <div class="provision-meta"><span>${progress}% complete</span><span>${item.queue?.position?'Position '+Number(item.queue.position)+' of '+Number(item.queue.waiting_total||item.queue.position):'ETA '+(item.eta?escapeHtml(new Date(item.eta.replace(' ','T')+'Z').toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})):'—')}</span></div>
            ${item.last_error?`<div class="provision-error">${escapeHtml(String(item.last_error).replace(/linode/ig,'cloud provider'))}</div>`:''}
            <div class="provision-log">${logs||'<p>No activity recorded yet.</p>'}</div>
        </article>`;
    }).join('');
    summary.textContent=active+' active · '+Number(stats.waiting||0)+' waiting'+(Number(stats.worker_online||0)>0?' · worker online':'');
}
async function refreshProvisioning(){
    try{
        const response=await fetch('/api/provisioning-status.php',{headers:{'Accept':'application/json'}});
        const result=await response.json();
        if(!result.ok)throw new Error(result.error||'Provisioning status unavailable');
        renderProvisioning(result.items||[],result.stats||{});
    }catch(error){
        const section=document.getElementById('prov-section');
        section.hidden=false;
        document.getElementById('prov-grid').innerHTML='<div class="client-alert client-alert-error"><span class="alert-icon"><i class="fas fa-exclamation-triangle"></i></span><div><b>Provisioning status unavailable</b><span>'+escapeHtml(error.message||String(error))+'</span></div></div>';
        document.getElementById('prov-summary').textContent='Unavailable';
    }
}
async function refreshAllServers(){
    if(document.hidden)return;
    await Promise.all([...document.querySelectorAll('.client-server-card')].map(refreshServer));
}
document.querySelectorAll('.client-server-card').forEach(card=>card.querySelectorAll('[data-power]').forEach(button=>button.addEventListener('click',()=>powerServer(card,button.dataset.power))));
refreshAllServers();
refreshProvisioning();
if(HAS_CLIENT_KEY)setInterval(refreshAllServers,10000);
setInterval(()=>{if(!document.hidden)refreshProvisioning();},5000);
</script>
</body>
</html>

