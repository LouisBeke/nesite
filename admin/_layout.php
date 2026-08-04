<?php
function admin_head(array $u, string $title, string $active='dashboard'): void {
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
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FoxNetwork Admin | <?=e($title)?></title><link rel="stylesheet" href="/assets/portal.css?v=<?=rawurlencode((string)@filemtime(__DIR__.'/../assets/portal.css'))?>"></head><body><div class="admin-app"><aside class="admin-side"><a class="admin-brand" href="/admin/"><img src="/images/logo.png"><div><b>FOX<span>NETWORK</span></b><small>ADMIN CENTER</small></div></a><nav class="admin-nav"><?php foreach($items as $key=>$it):?><a class="<?=$active===$key?'active':''?>" href="<?=e($it[0])?>"><i><?=e($it[2])?></i><span><?=e($it[1])?></span></a><?php endforeach?></nav><div class="admin-side-bottom"><a href="/">← Customer Portal</a><a href="/logout.php">Sign out</a></div></aside><main class="admin-main"><header class="admin-top"><div><span class="admin-kicker">FOXNETWORK ADMIN</span><h1><?=e($title)?></h1></div><div class="profile"><div><b><?=e($u['name'])?></b><div class="muted small">Administrator</div></div><div class="avatar"><?=e(strtoupper(substr($u['name'],0,1)))?></div></div></header><div class="admin-content"><?php }
function admin_foot(): void { echo '</div></main></div></body></html>'; }
function admin_badge(string $status): string { return '<span class="admin-badge status-'.e($status).'">'.e(strtoupper(str_replace('_',' ',$status))).'</span>'; }
