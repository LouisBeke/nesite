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
 </style>
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
   <div class="services-page-head"><div><span class="section-kicker">Infrastructure</span><h1>My services</h1><p>Open and manage every server connected to your account.</p></div><a class="client-btn client-btn-primary" href="/store.php"><i class="fas fa-plus"></i> Add a service</a></div>
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
     <div class="service-tile-head"><div class="service-identity"><span><i class="fas <?=$provider==='linode'?'fa-cloud':'fa-cube'?>"></i></span><div><small><?=$provider==='linode'?'LINODE VPS':'GAME SERVER'?></small><h2><?=e((string)$service['name'])?></h2><p><?=e((string)($service['product_name']??'FoxNetwork service'))?></p></div></div><span class="service-state is-<?=e($status)?>"><?=e($status)?></span></div>
     <div class="service-facts"><div class="service-fact"><span>Address</span><b><?=e($address)?></b></div><div class="service-fact"><span>Next due</span><b><?=e($due)?></b></div><div class="service-fact"><span>Provider</span><b><?=$provider==='linode'?'Linode':'Pterodactyl'?></b></div></div>
     <div class="service-actions"><div class="service-price"><b><?=$isFree?'Free':e(number_format($price,2).' '.(string)($service['currency']??'EUR'))?></b><small><?=$isFree?'No renewal charge':'per month'?></small></div><a class="service-open<?=$manageUrl===''?' disabled':''?>" href="<?=e($manageUrl?:'#')?>"><?=$provider==='linode'?'Open VPS dashboard':'Manage server'?> <i class="fas fa-arrow-right"></i></a></div>
    </article>
    <?php endforeach?>
   </section>
  </div>
 </main>
</div>
</body>
</html>
/html>
