<?php
require __DIR__.'/app/bootstrap.php';
$token=(string)($_GET['token']??($_SERVER['HTTP_X_CRON_TOKEN']??''));if($token===''||!hash_equals((string)setting('cron_token',''),$token)){http_response_code(403);die('Forbidden');}
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
ignore_user_abort(true);
@set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');$out=[];
// Create renewal invoices once a service reaches the configured pre-renewal window.
$days=max(1,(int)setting('renewal_days_before','7'));$q=db()->prepare("SELECT s.*,u.name customer_name,u.email,u.email_notifications FROM services s JOIN users u ON u.id=s.user_id WHERE s.status IN ('active','suspended') AND s.price_monthly>0 AND COALESCE(s.cancel_at_period_end,0)=0 AND s.next_due_at IS NOT NULL AND s.next_due_at<=DATE_ADD(NOW(),INTERVAL ? DAY)");$q->execute([$days]);
foreach($q as $s){try{$exists=db()->prepare("SELECT id FROM invoices WHERE user_id=? AND status IN ('unpaid','overdue') AND order_id IS NULL AND due_at>=DATE_SUB(?,INTERVAL 1 DAY) LIMIT 1");$exists->execute([$s['user_id'],$s['next_due_at']]);if($exists->fetchColumn())continue;$num=(string)setting('invoice_prefix','INV').'-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));$due=$s['next_due_at'];$ins=db()->prepare("INSERT INTO invoices(user_id,order_id,service_id,invoice_number,status,subtotal,total,currency,due_at) VALUES(?,NULL,?,?,'unpaid',?,?,?,?)");$ins->execute([$s['user_id'],$s['id'],$num,$s['price_monthly'],$s['price_monthly'],$s['currency'],$due]);$iid=(int)db()->lastInsertId();db()->prepare('INSERT INTO invoice_items(invoice_id,description,amount,quantity) VALUES(?,?,?,1)')->execute([$iid,$s['name'].' — monthly renewal',$s['price_monthly']]);send_template('renewal_due',$s,['service_name'=>$s['name'],'invoice_number'=>$num,'total'=>number_format((float)$s['price_monthly'],2),'currency'=>$s['currency'],'due_date'=>date('d M Y',strtotime($due))]);$out[]='Renewal invoice '.$num.' created.';}catch(Throwable $e){$out[]='Renewal error service '.$s['id'].': '.$e->getMessage();}}
// A priced trial becomes a normal recurring service when its trial date arrives.
db()->exec("UPDATE services SET is_trial=0 WHERE is_trial=1 AND price_monthly>0 AND next_due_at IS NOT NULL AND next_due_at<=NOW()");
// A no-charge temporary trial expires and is suspended; permanent free services remain untouched.
$expiredTrials=db()->query("SELECT * FROM services WHERE is_trial=1 AND price_monthly<=0 AND next_due_at IS NOT NULL AND next_due_at<=NOW() AND status='active'")->fetchAll();
foreach($expiredTrials as $s){try{if(linode_service_provider($s)==='linode'&&!empty($s['linode_instance_id']))linode_power_action((int)$s['linode_instance_id'],'stop');elseif(!empty($s['ptero_server_id']))app_ptero('/servers/'.(int)$s['ptero_server_id'].'/suspend','POST');db()->prepare("UPDATE services SET status='suspended' WHERE id=?")->execute([$s['id']]);service_log((int)$s['id'],0,'trial_expired','Temporary trial expired; server suspended.');zoho_crm_try_sync_service((int)$s['id']);$out[]='Trial expired: '.$s['name'];}catch(Throwable $e){$out[]='Trial expiry error service '.$s['id'].': '.$e->getMessage();}}
// Mark overdue and email once.
$rows=db()->query("SELECT i.*,u.name,u.email,u.email_notifications FROM invoices i JOIN users u ON u.id=i.user_id WHERE i.status='unpaid' AND i.total>0 AND i.due_at<NOW()")->fetchAll();foreach($rows as $i){db()->prepare("UPDATE invoices SET status='overdue',overdue_sent_at=COALESCE(overdue_sent_at,NOW()) WHERE id=?")->execute([$i['id']]);if(empty($i['overdue_sent_at']))send_template('invoice_overdue',$i,['invoice_number'=>$i['invoice_number'],'total'=>number_format((float)$i['total'],2),'currency'=>$i['currency'],'due_date'=>date('d M Y',strtotime($i['due_at']))]);}
// Suspend services after grace period when that exact service has an overdue renewal invoice.
if(setting('auto_suspend','1')==='1'){
	$grace=max(0,(int)setting('grace_days','3'));
	$sql="SELECT DISTINCT s.*,u.name,u.email,u.email_notifications
		  FROM services s
		  JOIN users u ON u.id=s.user_id
		  JOIN invoices i ON i.service_id=s.id
		  WHERE s.status='active'
			AND s.price_monthly>0
			AND s.next_due_at<NOW()
			AND i.status='overdue'
			AND i.due_at<DATE_SUB(NOW(),INTERVAL $grace DAY)";
	foreach(db()->query($sql) as $s){
		try{
			if(linode_service_provider($s)==='linode'&&!empty($s['linode_instance_id']))linode_power_action((int)$s['linode_instance_id'],'stop');elseif(!empty($s['ptero_server_id']))app_ptero('/servers/'.(int)$s['ptero_server_id'].'/suspend','POST');
			db()->prepare("UPDATE services SET status='suspended' WHERE id=?")->execute([$s['id']]);
			zoho_crm_try_sync_service((int)$s['id']);
			send_template('service_suspended',$s,['service_name'=>$s['name']]);
			$out[]='Suspended '.$s['name'];
		}catch(Throwable $e){
			$out[]='Suspend error '.$s['id'].': '.$e->getMessage();
		}
	}
}
// End-of-period cancellations: stop billing and suspend, but never delete a Pterodactyl server automatically.
$cancelRows=db()->query("SELECT * FROM services WHERE cancel_at_period_end=1 AND cancel_at IS NOT NULL AND cancel_at<=NOW() AND status NOT IN ('cancelled','terminated')")->fetchAll();
foreach($cancelRows as $s){try{if(linode_service_provider($s)==='linode'&&!empty($s['linode_instance_id'])){try{linode_power_action((int)$s['linode_instance_id'],'stop');}catch(Throwable $ignore){}}elseif(!empty($s['ptero_server_id'])){try{app_ptero('/servers/'.(int)$s['ptero_server_id'].'/suspend','POST');}catch(Throwable $ignore){}}db()->prepare("UPDATE services SET status='cancelled',auto_renew=0 WHERE id=?")->execute([$s['id']]);db()->prepare("INSERT INTO service_lifecycle(service_id,actor_user_id,event,details) VALUES(?,NULL,'cancelled_at_period_end','Billing ended and provider instance was suspended but not deleted.')")->execute([$s['id']]);zoho_crm_try_sync_service((int)$s['id']);$out[]='Cancelled at period end: '.$s['name'];}catch(Throwable $e){$out[]='Cancellation error '.$s['id'].': '.$e->getMessage();}}

// Stage 14 provisioning engine worker.
try{$nodeCount=provisioning_refresh_node_cache(30);if($nodeCount)$out[]='Node cache refreshed: '.$nodeCount.' node(s).';}catch(Throwable $e){$out[]='Node cache refresh error: '.$e->getMessage();}
try{$batch=max(1,(int)setting('provisioning_batch_size','5'));$w=provisioning_run_worker($batch);if(!empty($w['enabled']))$out[]='Provisioning worker: '.$w['processed'].' processed, '.$w['failed'].' failed, '.$w['queued'].' queued.';}catch(Throwable $e){$out[]='Provisioning worker error: '.$e->getMessage();}
try{$automationBatch=max(1,(int)setting('automation_batch_size','20'));$aw=automation_run_worker($automationBatch);$out[]='Automation worker: '.$aw['processed'].' processed, '.$aw['skipped'].' skipped, '.$aw['failed'].' failed, '.$aw['queued'].' queued.';}catch(Throwable $e){$out[]='Automation worker error: '.$e->getMessage();}
echo implode("\n",$out)?:'OK - nothing to do';
