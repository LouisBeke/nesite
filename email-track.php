<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
$token=strtolower(trim((string)($_GET['t']??'')));
if(preg_match('/^[a-f0-9]{64}$/',$token)){
    try{
        $q=db()->prepare("SELECT id FROM email_log WHERE tracking_token=? AND status='sent' LIMIT 1");$q->execute([$token]);$id=(int)$q->fetchColumn();
        if($id>0){
            $fingerprint=email_tracking_request_fingerprint();
            db()->prepare('UPDATE email_log SET first_opened_at=COALESCE(first_opened_at,NOW()),last_opened_at=NOW(),open_count=open_count+1 WHERE id=?')->execute([$id]);
            db()->prepare("INSERT INTO email_tracking_events(email_log_id,event_type,ip_hash,user_agent) VALUES(?,'open',?,?)")->execute([$id,$fingerprint['ip_hash'],$fingerprint['user_agent']]);
        }
    }catch(Throwable $e){error_log('Email open tracking failed: '.$e->getMessage());}
}
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
