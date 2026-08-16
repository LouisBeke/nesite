<?php
require __DIR__.'/lib/bootstrap.php'; need(); require __DIR__.'/lib/header.php';
$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try { check_csrf(); add('templates',['name'=>trim((string)$_POST['name']),'body'=>trim((string)$_POST['body']),'created_at'=>date('c')]); header('Location: '.app_url('templates.php')); exit; }
    catch (Throwable $e) { $err=$e->getMessage(); }
}
$r=array_reverse(rows('templates')); top('Templates');
?><div class="grid"><div class="panel"><h3>New template</h3><?php if($err):?><p class="bad"><?=htmlspecialchars($err)?></p><?php endif?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input name="name" placeholder="Name" required><textarea name="body" rows="6" required></textarea><button>Save</button></form></div><div class="panel"><h3>Templates</h3><?php foreach($r as $x):?><p><b><?=htmlspecialchars($x['name'])?></b><br><span class="muted"><?=nl2br(htmlspecialchars($x['body']))?></span></p><?php endforeach?></div></div><?php bottom();
