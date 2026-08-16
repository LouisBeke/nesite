<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
function inbound_out(bool $ok,array $data=[],int $status=200):never{http_response_code($status);echo json_encode($ok?['ok'=>true]+$data:['ok'=>false]+$data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function inbound_setting_secret():string{$v=(string)setting('inbound_email_secret','');if(str_starts_with($v,'enc:'))$v=(string)(dec(substr($v,4))??'');return trim($v);}
function inbound_email_address(string $value):string{if(preg_match('/<([^>]+)>/',$value,$m))$value=$m[1];$value=strtolower(trim($value));return filter_var($value,FILTER_VALIDATE_EMAIL)?$value:'';}
function inbound_clean_body(string $body):string{$body=str_replace(["\r\n","\r"],"\n",$body);$body=preg_split('/\n(?:On .+ wrote:|From:\s.+|-{2,}\s*Original Message\s*-{2,})/i',$body,2)[0]??$body;$lines=array_filter(explode("\n",$body),static fn($line)=>!str_starts_with(ltrim($line),'>'));return trim(implode("\n",$lines));}
try{
    if($_SERVER['REQUEST_METHOD']!=='POST')inbound_out(false,['error'=>'POST required.'],405);
    if(setting('inbound_email_enabled','0')!=='1')inbound_out(false,['error'=>'Inbound email is disabled.'],403);
    $raw=(string)file_get_contents('php://input');$input=json_decode($raw,true);if(!is_array($input))$input=$_POST;
    $configured=inbound_setting_secret();$provided=trim((string)($_SERVER['HTTP_X_INBOUND_EMAIL_SECRET']??$input['secret']??''));
    if($configured===''||$provided===''||!hash_equals($configured,$provided))inbound_out(false,['error'=>'Unauthorized.'],401);
    $from=inbound_email_address((string)($input['from']??$input['sender']??$input['from_email']??''));
    $subject=trim((string)($input['subject']??''));
    if(!empty($input['auto_submitted'])||!empty($input['is_auto_reply']))throw new RuntimeException('Automatic email responses are ignored.');
    $body=inbound_clean_body((string)($input['text']??$input['text_body']??$input['body']??$input['plain']??''));
    if($from===''||$body==='')throw new RuntimeException('A valid sender and plain-text message body are required.');
    $body=mb_substr($body,0,50000);$subject=mb_substr($subject?:'Email support request',0,190);
    $q=db()->prepare("SELECT * FROM users WHERE LOWER(email)=? AND account_status='active' LIMIT 1");$q->execute([$from]);$user=$q->fetch();if(!$user)throw new RuntimeException('No active customer account matches the sender email.');
    $providerId=trim((string)($input['message_id']??$input['Message-Id']??$input['id']??''));$messageKey=hash('sha256',$providerId!==''?$providerId:bin2hex(random_bytes(32)));
    $q=db()->prepare('SELECT ticket_id FROM inbound_email_messages WHERE message_key=?');$q->execute([$messageKey]);if($existing=$q->fetchColumn())inbound_out(true,['duplicate'=>true,'ticket_id'=>(int)$existing]);
    $ticketId=0;if(preg_match('/#(\d{1,20})\b/',$subject,$match)){$candidate=(int)$match[1];$q=db()->prepare('SELECT id FROM support_tickets WHERE id=? AND user_id=?');$q->execute([$candidate,(int)$user['id']]);$ticketId=(int)$q->fetchColumn();}$created=$ticketId<=0;
    db()->beginTransaction();
    try{
        if($ticketId<=0){$cleanSubject=trim(preg_replace('/^(re|fw|fwd)\s*:\s*/i','',$subject))?:'Email support request';db()->prepare("INSERT INTO support_tickets(user_id,service_id,subject,category,priority,status) VALUES(?,NULL,?,'other','normal','awaiting_staff')")->execute([(int)$user['id'],$cleanSubject]);$ticketId=(int)db()->lastInsertId();}
        else db()->prepare("UPDATE support_tickets SET status='awaiting_staff',updated_at=NOW() WHERE id=?")->execute([$ticketId]);
        db()->prepare('INSERT INTO support_messages(ticket_id,user_id,is_staff,is_internal,message) VALUES(?,?,0,0,?)')->execute([$ticketId,(int)$user['id'],$body]);
        db()->prepare('INSERT INTO inbound_email_messages(message_key,sender_email,ticket_id) VALUES(?,?,?)')->execute([$messageKey,$from,$ticketId]);
        db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    try{zoho_crm_try_sync_ticket($ticketId);}catch(Throwable $ignore){}ticket_notify_staff($ticketId,$created?'new':'reply');
    inbound_out(true,['ticket_id'=>$ticketId,'created'=>$created]);
}catch(Throwable $e){inbound_out(false,['error'=>$e->getMessage()],422);}
