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

$users=db()->query('SELECT id,name,email,role,account_status FROM users ORDER BY name,email')->fetchAll();
$tpls=db()->query('SELECT * FROM email_templates ORDER BY template_key')->fetchAll();
$logs=db()->query('SELECT * FROM email_log ORDER BY id DESC LIMIT 100')->fetchAll();
$tracking=db()->query("SELECT COUNT(*) total,SUM(status='sent') sent,SUM(status='failed') failed,SUM(first_opened_at IS NOT NULL) opened,SUM(first_clicked_at IS NOT NULL) clicked FROM email_log")->fetch()?:[];
$sent=max(0,(int)($tracking['sent']??0));$opened=max(0,(int)($tracking['opened']??0));$clicked=max(0,(int)($tracking['clicked']??0));
admin_head($u,'Email & Notifications','email');
?>
<?php if($msg):?><div class="notice"><?=e($msg)?></div><?php endif?>
<?php if($err):?><div class="error"><?=e($err)?></div><?php endif?>

<section class="email-tracking-hero card"><div><span class="admin-kicker">DELIVERY INSIGHTS</span><h2>Email tracking</h2><p>Opens and link clicks for newly sent FoxNetwork emails.</p></div><span class="admin-badge <?=email_tracking_enabled()?'status-active':'status-disabled'?>"><?=email_tracking_enabled()?'TRACKING ON':'TRACKING OFF'?></span></section>
<section class="email-tracking-stats"><article><span>Sent</span><b><?=$sent?></b><small>Successfully handed to SMTP</small></article><article><span>Opened</span><b><?=$opened?></b><small><?=$sent?number_format(($opened/$sent)*100,1):'0.0'?>% unique open rate</small></article><article><span>Clicked</span><b><?=$clicked?></b><small><?=$sent?number_format(($clicked/$sent)*100,1):'0.0'?>% unique click rate</small></article><article><span>Failed</span><b><?=max(0,(int)($tracking['failed']??0))?></b><small>Delivery attempts with errors</small></article></section>

<div class="admin-grid">
    <section class="card">
        <div class="cardhead"><b>SEND EMAIL</b></div>
        <form method="post" class="admin-form-grid">
            <input type="hidden" name="csrf" value="<?=e(csrf())?>">
            <input type="hidden" name="action" value="custom">
            <div class="fullfield email-recipient-picker"><label for="email-recipient-search">Find recipient</label><div class="email-recipient-search"><span aria-hidden="true">⌕</span><input id="email-recipient-search" type="search" placeholder="Search name or email…" autocomplete="off"></div><select id="email-recipient-select" name="user_id" size="7" required><?php foreach($users as $c):?><option value="<?=(int)$c['id']?>" data-search="<?=e(strtolower($c['name'].' '.$c['email'].' '.$c['role'].' '.$c['account_status']))?>"><?=e($c['name'])?> — <?=e($c['email'])?> · <?=e(ucfirst($c['role']))?> · <?=e(ucfirst($c['account_status']??'active'))?></option><?php endforeach?></select><div class="email-recipient-selected" id="email-recipient-selected"><?=count($users)?> available recipient<?=count($users)===1?'':'s'?></div></div>
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
    <div class="admin-table-wrap"><table class="admin-table email-history-table"><thead><tr><th>Sent</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Opened</th><th>Clicked</th><th>Error</th></tr></thead><tbody>
    <?php if(!$logs):?><tr><td colspan="7" class="muted">No emails have been sent yet.</td></tr><?php endif?>
    <?php foreach($logs as $l):?><tr><td><?=e($l['sent_at']?:$l['created_at'])?></td><td><?=e($l['recipient'])?></td><td><b><?=e($l['subject'])?></b><?php if($l['template_key']):?><small><?=e(str_replace('_',' ',$l['template_key']))?></small><?php endif?></td><td><?=admin_badge($l['status'])?></td><td><?php if((int)$l['open_count']>0):?><span class="email-event is-open"><i class="fas fa-eye"></i><b><?=(int)$l['open_count']?></b></span><small><?=e($l['first_opened_at'])?></small><?php else:?><span class="email-event is-empty">Not opened</span><?php endif?></td><td><?php if((int)$l['click_count']>0):?><span class="email-event is-click"><i class="fas fa-mouse-pointer"></i><b><?=(int)$l['click_count']?></b></span><small><?=e($l['first_clicked_at'])?></small><?php else:?><span class="email-event is-empty">No clicks</span><?php endif?></td><td class="small redtext"><?=e($l['error_message']??'')?></td></tr><?php endforeach?>
    </tbody></table></div>
</section>
<script>(()=>{const search=document.getElementById('email-recipient-search'),select=document.getElementById('email-recipient-select'),selected=document.getElementById('email-recipient-selected');if(!search||!select)return;const options=[...select.options];function updateSelected(){const option=select.selectedOptions[0];selected.textContent=option?'Selected: '+option.textContent.trim():options.filter(o=>!o.hidden).length+' matching recipients';}search.addEventListener('input',()=>{const term=search.value.trim().toLowerCase();let visible=0;options.forEach(option=>{const show=!term||(option.dataset.search||'').includes(term);option.hidden=!show;option.style.display=show?'':'none';if(show)visible++;});const current=select.selectedOptions[0];if(current?.hidden)select.selectedIndex=-1;selected.textContent=visible+' matching recipient'+(visible===1?'':'s');});select.addEventListener('change',updateSelected);select.addEventListener('dblclick',()=>select.form?.querySelector('input[name="subject"]')?.focus());})();</script>
<?php admin_foot(); ?>
