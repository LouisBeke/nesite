<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';
$u=require_admin();
$msg='';
$err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $a=$_POST['action']??'';
        if($a==='zoho_test'){
            zoho_test_connection();
            $msg='Zoho Mail connection successful.';
        }elseif($a==='test'){
            send_custom_email($u['id'],trim($_POST['to']),'FoxNetwork email test','<h2>Email works</h2><p>This test email was sent successfully from your FoxNetwork Admin Center using Zoho Mail.</p>');
            $msg='Test email sent.';
        }elseif($a==='custom'){
            $uid=(int)$_POST['user_id'];
            $q=db()->prepare('SELECT * FROM users WHERE id=?');
            $q->execute([$uid]);
            $c=$q->fetch();
            if(!$c)throw new RuntimeException('Customer not found.');
            send_custom_email($uid,$c['email'],trim($_POST['subject']),nl2br(e($_POST['message'])));
            $msg='Email sent to '.$c['email'].'.';
        }elseif($a==='template'){
            $q=db()->prepare('UPDATE email_templates SET subject=?,body_html=?,enabled=? WHERE template_key=?');
            $q->execute([$_POST['subject'],$_POST['body_html'],isset($_POST['enabled'])?1:0,$_POST['template_key']]);
            $msg='Template saved.';
        }
    }catch(Throwable $e){
        $err=$e->getMessage();
    }
}

$users=db()->query('SELECT id,name,email FROM users ORDER BY name')->fetchAll();
$tpls=db()->query('SELECT * FROM email_templates ORDER BY template_key')->fetchAll();
$logs=db()->query('SELECT * FROM email_log ORDER BY id DESC LIMIT 100')->fetchAll();
admin_head($u,'Email & Notifications','email');
?>
<?php if($msg):?><div class="notice"><?=e($msg)?></div><?php endif?>
<?php if($err):?><div class="error"><?=e($err)?></div><?php endif?>

<div class="admin-grid">
    <section class="card">
        <div class="cardhead"><b>SEND EMAIL</b></div>
        <form method="post" class="admin-form-grid">
            <input type="hidden" name="csrf" value="<?=e(csrf())?>">
            <input type="hidden" name="action" value="custom">
            <label>Customer<select name="user_id"><?php foreach($users as $c):?><option value="<?=$c['id']?>"><?=e($c['name'].' — '.$c['email'])?></option><?php endforeach?></select></label>
            <label>Subject<input name="subject" required></label>
            <label class="fullfield">Message<textarea name="message" rows="7" required></textarea></label>
            <button class="btn primary">Send email</button>
        </form>
    </section>
    <section class="card">
        <div class="cardhead"><b>EMAIL TEST</b><span class="muted">ZOHO</span></div>
        <form method="post" style="margin-bottom:12px">
            <input type="hidden" name="csrf" value="<?=e(csrf())?>">
            <input type="hidden" name="action" value="zoho_test">
            <button class="btn" type="submit">Test Zoho Mail connection</button>
        </form>
        <form method="post" class="admin-form-grid">
            <input type="hidden" name="csrf" value="<?=e(csrf())?>">
            <input type="hidden" name="action" value="test">
            <label class="fullfield">Send test to<input type="email" name="to" value="<?=e($u['email'])?>" required></label>
            <button class="btn primary">Send test</button>
        </form>
    </section>
</div>

<h2 class="admin-section-title">Templates</h2>
<div class="template-grid">
<?php foreach($tpls as $t):?>
    <form method="post" class="card template-card">
        <input type="hidden" name="csrf" value="<?=e(csrf())?>">
        <input type="hidden" name="action" value="template">
        <input type="hidden" name="template_key" value="<?=e($t['template_key'])?>">
        <div class="cardhead"><b><?=e(strtoupper(str_replace('_',' ',$t['template_key'])))?></b><label><input type="checkbox" name="enabled" <?=$t['enabled']?'checked':''?>> Enabled</label></div>
        <div class="template-body">
            <label>Subject<input name="subject" value="<?=e($t['subject'])?>"></label>
            <label>HTML body<textarea name="body_html" rows="7"><?=e($t['body_html'])?></textarea></label>
            <button class="btn">Save template</button>
        </div>
    </form>
<?php endforeach?>
</div>

<section class="card" style="margin-top:24px">
    <div class="cardhead"><b>EMAIL HISTORY</b><span class="muted">Latest 100</span></div>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Time</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Error</th></tr></thead><tbody>
    <?php foreach($logs as $l):?><tr><td><?=e($l['created_at'])?></td><td><?=e($l['recipient'])?></td><td><?=e($l['subject'])?></td><td><?=admin_badge($l['status'])?></td><td class="small redtext"><?=e($l['error_message']??'')?></td></tr><?php endforeach?>
    </tbody></table></div>
</section>
<?php admin_foot(); ?>
