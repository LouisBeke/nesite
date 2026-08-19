<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$user=require_user();
$q=db()->prepare("SELECT s.*,p.name product_name FROM services s LEFT JOIN store_products p ON p.id=s.product_id WHERE s.user_id=? AND s.status<>'terminated' ORDER BY FIELD(s.status,'active','provisioning','pending','suspended','failed','cancelled'),s.id DESC");
$q->execute([(int)$user['id']]);
$services=$q->fetchAll();
$openTickets=0;
try{$ticketQuery=db()->prepare("SELECT COUNT(*) FROM support_tickets WHERE user_id=? AND status<>'closed'");$ticketQuery->execute([(int)$user['id']]);$openTickets=(int)$ticketQuery->fetchColumn();}catch(Throwable $ignore){}
$initial=mb_strtoupper(mb_substr(trim((string)$user['name']),0,1));
?>
<!doctype html>
<html lang="en">
<head>
 <meta charset="utf-8">
 <meta name="viewport" content="width=device-width,initial-scale=1">
 <meta name="theme-color" content="#0b0d10">
 <title>My services | FoxNetwork</title>
 <link rel="stylesheet" href="/css/fontawesome-all.min.css">
 <link rel="stylesheet" href="/assets/portal.css?v=<?=rawurlencode((string)@filemtime(__DIR__.'/assets/portal.css'))?>">
 <style>
 .services-page-head{display:flex;align-items:end;justify-content:space-between;gap:24px;margin-bottom:22px}.services-page-head h1{margin:5px 0 7px;font-size:32px;letter-spacing:-.04em}.services-page-head p{margin:0;color:#7f8997;font-size:13px}.services-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.service-tile{position:relative;overflow:hidden;padding:22px;background:linear-gradient(145deg,#14181e,#0f1217);border:1px solid var(--client-line);border-radius:17px}.service-tile:before{content:"";position:absolute;inset:0 auto 0 0;width:3px;background:#677180}.service-tile.is-active:before{background:#35cf83}.service-tile.is-provisioning:before,.service-tile.is-pending:before{background:#ffad55}.service-tile.is-failed:before,.service-tile.is-cancelled:before{background:#ff6570}.service-tile-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.service-identity{display:flex;gap:13px;min-width:0}.service-identity>span{width:43px;height:43px;display:grid;place-items:center;flex:0 0 auto;border-radius:12px;background:rgba(255,116,23,.1);color:#ff9653}.service-identity small{color:#747f8e;font-size:9px;font-weight:850;letter-spacing:.12em}.service-identity h2{margin:5px 0 3px;font-size:18px}.service-identity p{margin:0;color:#687381;font-size:11px}.service-state{padding:6px 8px;border-radius:7px;background:rgba(255,255,255,.05);color:#9ba5b3;font-size:9px;font-weight:850;text-transform:uppercase}.service-state.is-active{background:rgba(53,207,131,.1);color:#60dda0}.service-state.is-provisioning,.service-state.is-pending{background:rgba(255,173,85,.1);color:#ffb96c}.service-facts{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:19px 0}.service-fact{min-width:0;padding:11px;background:#0b0e12;border:1px solid rgba(255,255,255,.05);border-radius:9px}.service-fact span{display:block;color:#606b79;font-size:8px;text-transform:uppercase}.service-fact b{display:block;margin-top:5px;overflow:hidden;color:#dfe5ec;font-size:11px;text-overflow:ellipsis;white-space:nowrap}.service-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-top:16px;border-top:1px solid rgba(255,255,255,.06)}.service-price{display:grid;gap:3px}.service-price b{font-size:14px}.service-price small{color:#697381;font-size:9px}.service-open{display:inline-flex;align-items:center;gap:8px;padding:10px 13px;border-radius:9px;background:linear-gradient(135deg,var(--client-orange),var(--client-orange-2));color:#17100b;text-decoration:none;font-size:11px;font-weight:850}.service-open.disabled{pointer-events:none;background:#20252c;color:#697381}.services-empty{grid-column:1/-1;padding:70px 25px;text-align:center;background:#11151a;border:1px solid var(--client-line);border-radius:17px}.services-empty i{color:#ff9250;font-size:28px}.services-empty h2{margin:15px 0 7px}.services-empty p{margin:0 0 18px;color:#788290}@media(max-width:800px){.services-grid{grid-template-columns:1fr}.services-page-head{align-items:flex-start;flex-direction:column}.service-facts{grid-template-columns:1fr 1fr}}
 .client-live-queue{display:none;margin-bottom:18px;padding:20px;border:1px solid #343941;border-radius:16px;background:#11151a}.client-live-queue.visible{display:block}.client-live-head{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:14px}.client-live-head h2{margin:4px 0 0;font-size:18px}.client-live-state{display:flex;align-items:center;gap:7px;color:#8994a2;font-size:10px}.client-live-state i{width:8px;height:8px;border-radius:50%;background:#ffad55;box-shadow:0 0 12px rgba(255,173,85,.55)}.client-queue-items{display:grid;gap:10px}.client-queue-item{padding:14px;border:1px solid #292f37;border-radius:11px;background:#0c1014}.client-queue-top,.client-queue-meta{display:flex;align-items:center;justify-content:space-between;gap:12px}.client-queue-meta{margin-top:8px;color:#7f8996;font-size:10px}.client-progress{height:7px;margin-top:11px;overflow:hidden;border-radius:99px;background:#242a31}.client-progress span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#ff7417,#ffb15e);transition:width .45s ease}.client-queue-error{margin:9px 0 0;color:#ff7c86;font-size:10px}.client-queue-timeline{margin:8px 0 0;color:#8c96a3;font-size:10px}@media(max-width:800px){.client-live-head,.client-queue-top,.client-queue-meta{align-items:flex-start;flex-direction:column}}
 </style>
<?= opinly_head() ?>
</head>
<body class="client-body">
<div class="client-shell">
 <?php render_client_sidebar($user,'services',$openTickets,'client');?>
 <main class="client-main">
  <header class="client-topbar">
   <div class="client-page-title"><span>Control Center</span><small>Services</small></div>
   <div class="client-top-actions"><a class="topbar-action" href="/support.php" aria-label="Open support"><i class="far fa-question-circle"></i></a><a class="client-profile" href="/settings.php"><span class="client-profile-copy"><b><?=e($user['name'])?></b><small>Customer account</small></span><span class="client-avatar"><?=e($initial)?></span></a></div>
  </header>
  <div class="client-content">
   <div class="services-page-head"><div><span class="section-kicker">Infrastructure</span><h1>My services</h1><p>Open and manage every server connected to your account.</p></div><div class="client-top-actions" style="display:flex;gap:10px;flex-wrap:wrap"><a class="client-btn client-btn-ghost" href="/service-request.php"><i class="fas fa-file-signature"></i> Request a service</a><a class="client-btn client-btn-primary" href="/store.php"><i class="fas fa-plus"></i> Add a service</a></div></div>
   <section class="client-live-queue" id="client-live-queue" aria-live="polite"><div class="client-live-head"><div><span class="section-kicker">LIVE PROVISIONING</span><h2>Your new service is being prepared</h2></div><span class="client-live-state"><i></i><span id="client-queue-connection">Connecting…</span></span></div><div class="client-queue-items" id="client-queue-items"></div></section>
   <section class="services-grid">
    <?php if(!$services):?><div class="services-empty"><i class="fas fa-server"></i><h2>No services yet</h2><p>Your services will appear here automatically after checkout.</p><a class="client-btn client-btn-primary" href="/store.php">Browse services</a></div><?php endif?>
    <?php foreach($services as $service):
     $provider=linode_service_provider($service);
     $status=(string)($service['status']??'pending');
     $identifier=$provider==='linode'?(int)$service['id']:(string)($service['ptero_identifier']??'');
     $manageUrl=$provider==='linode'?('/vps.php?id='.(int)$service['id']):($identifier!==''?('/server.php?id='.rawurlencode($identifier)):'');
     $price=(float)($service['price_monthly']??0);
     $isFree=$price<=0 && empty($service['is_trial']);
     $address=$provider==='linode'?((string)($service['linode_ipv4']??'')?:'IP pending'):((string)($service['ptero_identifier']??'')?:'Provisioning');
     $due=$isFree?'Never expires':(!empty($service['next_due_at'])?date('d M Y',strtotime((string)$service['next_due_at'])):'Not scheduled');
    ?>
    <article class="service-tile is-<?=e($status)?>">
     <div class="service-tile-head"><div class="service-identity"><span><i class="fas <?=$provider==='linode'?'fa-cloud':'fa-cube'?>"></i></span><div><small><?=$provider==='linode'?'CLOUD VPS':'GAME SERVER'?></small><h2><?=e((string)$service['name'])?></h2><p><?=e((string)($service['product_name']??'FoxNetwork service'))?></p></div></div><span class="service-state is-<?=e($status)?>"><?=e($status)?></span></div>
     <div class="service-facts"><div class="service-fact"><span>Address</span><b><?=e($address)?></b></div><div class="service-fact"><span>Next due</span><b><?=e($due)?></b></div><div class="service-fact"><span>Platform</span><b><?=$provider==='linode'?'FoxNetwork Cloud':'Game panel'?></b></div></div>
     <div class="service-actions"><div class="service-price"><b><?=$isFree?'Free':e(number_format($price,2).' '.(string)($service['currency']??'EUR'))?></b><small><?=$isFree?'No renewal charge':'per month'?></small></div><a class="service-open<?=$manageUrl===''?' disabled':''?>" href="<?=e($manageUrl?:'#')?>"><?=$provider==='linode'?'Open VPS dashboard':'Manage server'?> <i class="fas fa-arrow-right"></i></a></div>
    </article>
    <?php endforeach?>
   </section>
  </div>
 </main>
</div>
<script>
(()=>{const panel=document.getElementById('client-live-queue'),list=document.getElementById('client-queue-items'),connection=document.getElementById('client-queue-connection');let timer=null,busy=false,failures=0;const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
function render(data){const items=data.items||[];panel.classList.toggle('visible',items.length>0);if(!items.length){list.innerHTML='';return;}list.innerHTML=items.map(item=>{const q=item.queue||{},position=q.position?`Position ${Number(q.position)} of ${Number(q.waiting_total||q.position)}`:(q.status==='running'?'Worker is processing':'Preparing queue'),timeline=item.timeline||[],latest=timeline.length?timeline[timeline.length-1]:null;return `<article class="client-queue-item"><div class="client-queue-top"><b>${esc(item.name)}</b><span class="service-state is-${esc(item.status)}">${esc(item.step)}</span></div><div class="client-progress"><span style="width:${Math.max(0,Math.min(100,Number(item.progress||0)))}%"></span></div><div class="client-queue-meta"><span>${position}</span><span>${Number(item.progress||0)}% · Attempt ${Number(q.attempts||0)} / ${Number(q.max_attempts||0)}</span></div>${latest?`<p class="client-queue-timeline">${esc(latest.message||latest.event)}</p>`:''}${item.last_error?`<p class="client-queue-error">${esc(item.last_error)}</p>`:''}</article>`}).join('');connection.textContent=`Live · ${Number(data.stats?.waiting||0)} waiting · ${Number(data.stats?.worker_online||0)} worker(s) online`;}
function schedule(ms){clearTimeout(timer);timer=setTimeout(refresh,ms)}async function refresh(){if(document.hidden||busy){schedule(1500);return;}busy=true;const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),12000);try{const response=await fetch('/api/provisioning-status.php',{headers:{Accept:'application/json'},cache:'no-store',signal:controller.signal}),data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Status unavailable');failures=0;render(data);schedule((data.items||[]).length?3000:12000)}catch(error){failures++;connection.textContent='Reconnecting…';panel.classList.add('visible');schedule(Math.min(30000,3000*Math.pow(2,Math.min(failures,3))))}finally{clearTimeout(timeout);busy=false}}document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh()});refresh();})();
</script>
</body>
</html>
