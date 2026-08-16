<?php
require __DIR__.'/lib/bootstrap.php'; need(); require __DIR__.'/lib/header.php';
$ok=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        check_csrf(); $to=trim((string)($_POST['to']??'')); $body=trim((string)($_POST['body']??''));
        if ($to===''||$body==='') throw new RuntimeException('Phone number and message are required.');
        add('messages',['to'=>$to,'body'=>$body,'status'=>'queued','created_at'=>date('c'),'updated_at'=>date('c')]); $ok='SMS queued for Android gateway.';
    } catch (Throwable $e) { $err=$e->getMessage(); }
}
top('Send SMS');
?><div class="panel" style="max-width:700px"><?php if($ok):?><p class="ok"><?=htmlspecialchars($ok)?></p><?php endif?><?php if($err):?><p class="bad"><?=htmlspecialchars($err)?></p><?php endif?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><label>Phone number</label><input name="to" placeholder="+324..." required><label>Message</label><textarea name="body" rows="7" required></textarea><button>Queue SMS</button></form></div><?php bottom();
