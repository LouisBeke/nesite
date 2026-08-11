<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';
$u=require_admin();$msg='';$err='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'');
        if($action==='run_provisioning'){
            @set_time_limit(180);$result=provisioning_run_worker(max(1,min(20,(int)setting('provisioning_batch_size','5'))));
            $msg=$result['processed'].' provisioning job(s) processed; '.$result['queued'].' remain queued.';
        }elseif($action==='run_automation'){
            @set_time_limit(180);$result=automation_run_worker(max(1,min(20,(int)setting('automation_batch_size','20'))));
            $msg=$result['processed'].' automation job(s) processed; '.$result['queued'].' remain queued.';
        }elseif($action==='retry_provisioning'){
            $id=(int)($_POST['job_id']??0);$q=db()->prepare('SELECT job_key FROM provisioning_queue WHERE id=?');$q->execute([$id]);$key=(string)$q->fetchColumn();
            if(!preg_match('/^service-provision-(\d+)$/',$key,$match))throw new RuntimeException('Provisioning job cannot be retried automatically.');
            $newId=provisioning_queue_service((int)$match[1],['source'=>'admin_live_queue','admin_id'=>(int)$u['id']],95,true);
            $msg='Provisioning job #'.$newId.' queued for immediate retry.';
        }elseif($action==='retry_automation'){
            $id=(int)($_POST['job_id']??0);if($id<=0)throw new RuntimeException('Automation job not found.');automation_retry_job($id);$msg='Automation job queued for retry.';
        }else throw new RuntimeException('Unknown queue action.');
    }catch(Throwable $e){$err=$e->getMessage();}
}
admin_head($u,'Live Queue','queue');
?>
<?php if($msg):?><div class="notice"><?=e($msg)?></div><?php endif?><?php if($err):?><div class="error"><?=e($err)?></div><?php endif?>
<section class="queue-hero card"><div><span class="admin-kicker">REAL-TIME OPERATIONS</span><h2>Live queue</h2><p>Provisioning and integrations update automatically every three seconds.</p></div><div class="queue-live-state"><i></i><span><b id="queue-connection">Connecting</b><small id="queue-updated">Waiting for first update</small></span></div></section>
<section class="queue-stats" aria-label="Queue status"><article><span>Waiting</span><b id="queue-waiting">—</b><small>Provisioning jobs</small></article><article><span>Running</span><b id="queue-running">—</b><small>Across both queues</small></article><article><span>Failed</span><b id="queue-failed">—</b><small>Needs attention</small></article><article><span>Workers online</span><b id="queue-workers">—</b><small>Heartbeat within 2 min</small></article></section>
<div class="queue-toolbar"><div class="queue-tabs" role="tablist"><button class="active" type="button" data-queue-tab="provisioning">Provisioning <span id="tab-provisioning-count">0</span></button><button type="button" data-queue-tab="automation">Automation <span id="tab-automation-count">0</span></button><button type="button" data-queue-tab="workers">Workers <span id="tab-worker-count">0</span></button></div><div class="buttons"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><button class="btn" name="action" value="run_automation">Run automation</button></form><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><button class="btn primary" name="action" value="run_provisioning">Run provisioning</button></form></div></div>
<section class="card queue-panel active" data-queue-panel="provisioning"><div class="cardhead"><div><b>PROVISIONING QUEUE</b><span class="muted">Highest priority first</span></div><select id="queue-filter" aria-label="Filter provisioning queue"><option value="active">Active only</option><option value="all">All recent</option><option value="failed">Failed</option><option value="completed">Completed</option></select></div><div id="provisioning-live-list" class="queue-live-list"><div class="queue-loading"><span></span>Loading provisioning queue…</div></div></section>
<section class="card queue-panel" data-queue-panel="automation"><div class="cardhead"><div><b>AUTOMATION QUEUE</b><span class="muted">Zoho and background integrations</span></div></div><div id="automation-live-list" class="queue-live-list"><div class="queue-loading"><span></span>Loading automation queue…</div></div></section>
<section class="card queue-panel" data-queue-panel="workers"><div class="cardhead"><div><b>WORKERS</b><span class="muted">Processing heartbeat and totals</span></div></div><div id="worker-live-list" class="queue-worker-grid"><div class="queue-loading"><span></span>Loading workers…</div></div></section>
<script>
const QUEUE_CSRF=<?=json_encode(csrf())?>;let queueData=null;let queueFilter='active';
const qe=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
const label=v=>String(v||'unknown').replaceAll('_',' ');
const activeStatus=s=>['pending','retry_wait','running'].includes(String(s));
function queueAction(action,id){return `<form method="post"><input type="hidden" name="csrf" value="${qe(QUEUE_CSRF)}"><input type="hidden" name="job_id" value="${Number(id)}"><button class="btn" name="action" value="${action}">Retry</button></form>`}
function renderQueue(data){
 queueData=data;const ps=data.stats?.provisioning||{},as=data.stats?.automation||{};
 document.getElementById('queue-waiting').textContent=Number(data.stats?.waiting||0);document.getElementById('queue-running').textContent=Number(ps.running||0)+Number(as.running||0);document.getElementById('queue-failed').textContent=Number(ps.failed||0)+Number(as.failed||0);document.getElementById('queue-workers').textContent=(data.workers||[]).filter(w=>w.online).length;
 document.getElementById('tab-provisioning-count').textContent=(data.provisioning||[]).filter(j=>activeStatus(j.status)).length;document.getElementById('tab-automation-count').textContent=(data.automation||[]).filter(j=>activeStatus(j.status)).length;document.getElementById('tab-worker-count').textContent=(data.workers||[]).filter(w=>w.online).length;
 let provisioning=(data.provisioning||[]).filter(j=>queueFilter==='all'||(queueFilter==='active'&&activeStatus(j.status))||j.status===queueFilter);
 document.getElementById('provisioning-live-list').innerHTML=provisioning.length?provisioning.map(j=>`<article class="queue-row"><div class="queue-position">${j.position?'#'+j.position:'<i class="fas fa-server"></i>'}</div><div class="queue-primary"><div><b>${qe(j.service_name||j.job_key||('Job #'+j.id))}</b><span class="admin-badge status-${qe(j.status)}">${qe(label(j.status))}</span></div><small>${qe(j.customer_name||'System')} · ${qe(j.product_name||j.job_type)}${j.service_id?' · Service #'+Number(j.service_id):''}</small>${(j.logs||[])[0]?`<p>${qe((j.logs||[])[0].message||(j.logs||[])[0].event_name)}</p>`:''}${j.last_error?`<p class="queue-error">${qe(j.last_error)}</p>`:''}</div><div class="queue-attempt"><span>Attempts</span><b>${Number(j.attempts)} / ${Number(j.max_attempts)}</b></div><div class="queue-time"><span>${j.status==='retry_wait'?'Next retry':'Updated'}</span><b>${qe(j.status==='retry_wait'?j.run_at:j.updated_at)}</b></div><div class="queue-actions">${['failed','retry_wait'].includes(j.status)?queueAction('retry_provisioning',j.id):''}${j.service_id?`<a class="btn" href="/admin/services.php#service-${Number(j.service_id)}">Service</a>`:''}</div></article>`).join(''):'<div class="empty muted">No provisioning jobs match this view.</div>';
 const automation=data.automation||[];document.getElementById('automation-live-list').innerHTML=automation.length?automation.map(j=>`<article class="queue-row"><div class="queue-position"><i class="fas fa-bolt"></i></div><div class="queue-primary"><div><b>${qe(String(j.provider).toUpperCase())} · ${qe(label(j.job_type))}</b><span class="admin-badge status-${qe(j.status)}">${qe(label(j.status))}</span></div><small>${qe(j.entity_type)} #${Number(j.entity_id)}</small>${j.last_error?`<p class="queue-error">${qe(j.last_error)}</p>`:''}</div><div class="queue-attempt"><span>Attempts</span><b>${Number(j.attempts)} / ${Number(j.max_attempts)}</b></div><div class="queue-time"><span>Next run</span><b>${qe(j.run_at)}</b></div><div class="queue-actions">${['failed','retry_wait'].includes(j.status)?queueAction('retry_automation',j.id):''}</div></article>`).join(''):'<div class="empty muted">No automation jobs recorded.</div>';
 const workers=data.workers||[];document.getElementById('worker-live-list').innerHTML=workers.length?workers.map(w=>`<article><span class="queue-worker-dot ${w.online?'online':'offline'}"></span><div><b>${qe(w.worker_id)}</b><small>${qe(w.hostname||'Unknown host')}</small></div><span class="admin-badge ${w.online?'status-active':'status-failed'}">${w.online?'online':'offline'}</span><dl><div><dt>Status</dt><dd>${qe(w.status)}</dd></div><div><dt>Processed</dt><dd>${Number(w.processed_jobs)}</dd></div><div><dt>Failed</dt><dd>${Number(w.failed_jobs)}</dd></div><div><dt>Heartbeat</dt><dd>${Number(w.heartbeat_age)}s ago</dd></div></dl></article>`).join(''):'<div class="empty muted">No provisioning workers have reported yet.</div>';
 document.getElementById('queue-connection').textContent='Live';document.querySelector('.queue-live-state').classList.add('online');document.getElementById('queue-updated').textContent='Updated '+new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
async function refreshQueue(){if(document.hidden)return;try{const r=await fetch('/api/admin-queue-status.php',{headers:{Accept:'application/json'},cache:'no-store'});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Queue unavailable');renderQueue(d)}catch(e){document.getElementById('queue-connection').textContent='Disconnected';document.querySelector('.queue-live-state').classList.remove('online');document.getElementById('queue-updated').textContent=e.message||String(e)}}
document.querySelectorAll('[data-queue-tab]').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('[data-queue-tab]').forEach(x=>x.classList.toggle('active',x===b));document.querySelectorAll('[data-queue-panel]').forEach(x=>x.classList.toggle('active',x.dataset.queuePanel===b.dataset.queueTab))}));document.getElementById('queue-filter').addEventListener('change',e=>{queueFilter=e.target.value;if(queueData)renderQueue(queueData)});refreshQueue();setInterval(refreshQueue,3000);
</script>
<?php admin_foot(); ?>
