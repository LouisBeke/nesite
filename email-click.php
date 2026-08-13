<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';

header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow, noarchive');
$token=strtolower(trim((string)($_GET['t']??'')));$encoded=(string)($_GET['u']??'');$signature=strtolower(trim((string)($_GET['s']??'')));
$target=email_tracking_decode($encoded);
$valid=preg_match('/^[a-f0-9]{64}$/',$token)&&preg_match('/^[a-f0-9]{64}$/',$signature)&&hash_equals(email_tracking_signature($token,$encoded),$signature)&&is_string($target)&&filter_var($target,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($target,PHP_URL_SCHEME)),['http','https'],true);
if(!$valid){http_response_code(400);header('Content-Type: text/plain; charset=utf-8');echo 'Invalid email link.';exit;}
try{
    $q=db()->prepare("SELECT id FROM email_log WHERE tracking_token=? AND status='sent' LIMIT 1");$q->execute([$token]);$id=(int)$q->fetchColumn();
    if($id<=0){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo 'Email link not found.';exit;}
    $fingerprint=email_tracking_request_fingerprint();
    db()->prepare('UPDATE email_log SET first_clicked_at=COALESCE(first_clicked_at,NOW()),last_clicked_at=NOW(),click_count=click_count+1,last_clicked_url=? WHERE id=?')->execute([$target,$id]);
    db()->prepare("INSERT INTO email_tracking_events(email_log_id,event_type,target_url,ip_hash,user_agent) VALUES(?,'click',?,?,?)")->execute([$id,$target,$fingerprint['ip_hash'],$fingerprint['user_agent']]);
}catch(Throwable $e){error_log('Email click tracking failed: '.$e->getMessage());}
header('Location: '.$target,true,302);
exit;
