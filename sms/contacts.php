<?php
require __DIR__.'/lib/bootstrap.php'; need(); require __DIR__.'/lib/header.php';
$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try { check_csrf(); add('contacts',['name'=>trim((string)$_POST['name']),'phone'=>trim((string)$_POST['phone']),'notes'=>trim((string)($_POST['notes']??'')),'created_at'=>date('c')]); header('Location: '.app_url('contacts.php')); exit; }
    catch (Throwable $e) { $err=$e->getMessage(); }
}
$r=rows('contacts'); top('Contacts');
?><div class="grid"><div class="panel"><h3>Add contact</h3><?php if($err):?><p class="bad"><?=htmlspecialchars($err)?></p><?php endif?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input name="name" placeholder="Name" required><input name="phone" placeholder="+32..." required><textarea name="notes" placeholder="Notes"></textarea><button>Add</button></form></div><div class="panel"><h3>Saved contacts</h3><table><?php foreach($r as $x):?><tr><td><?=htmlspecialchars($x['name'])?></td><td><?=htmlspecialchars($x['phone'])?></td></tr><?php endforeach?></table></div></div><?php bottom();
