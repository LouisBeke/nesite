<?php
require __DIR__.'/lib/bootstrap.php'; need(); require __DIR__.'/lib/header.php';

$ok=''; $err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        check_csrf();
        if(($_POST['action']??'')!=='resend')throw new RuntimeException('Invalid action.');
        $sourceId=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$sourceId)throw new RuntimeException('Invalid message.');
        $source=null;foreach(rows('messages') as $candidate)if((int)($candidate['id']??0)===$sourceId){$source=$candidate;break;}
        if(!$source)throw new RuntimeException('The original message was not found.');
        $body=trim((string)($source['body']??''));if($body==='')throw new RuntimeException('The original message is empty.');
        $now=date('c');
        if(trim((string)($source['to']??''))!==''){
            $newId=add('messages',['to'=>$source['to'],'body'=>$body,'status'=>'queued','created_at'=>$now,'updated_at'=>$now,'resent_from'=>$sourceId]);
            $ok='Message #'.$sourceId.' was queued again as #'.$newId.'.';
        }elseif(trim((string)($source['iccid']??''))!==''){
            try{
                $response=send_sms((string)$source['iccid'],$body);
                $newId=add('messages',['iccid'=>$source['iccid'],'body'=>$body,'status'=>'sent','response'=>$response,'created_at'=>$now,'updated_at'=>$now,'resent_from'=>$sourceId]);
                $ok='Message #'.$sourceId.' was sent again as #'.$newId.'.';
            }catch(Throwable $sendError){
                add('messages',['iccid'=>$source['iccid'],'body'=>$body,'status'=>'failed','detail'=>$sendError->getMessage(),'created_at'=>$now,'updated_at'=>$now,'resent_from'=>$sourceId]);
                throw $sendError;
            }
        }else throw new RuntimeException('The original message has no destination.');
    }catch(Throwable $e){$err='Could not resend SMS: '.$e->getMessage();}
}

$messages=array_reverse(rows('messages')); top('Messages');
?><?php if($ok):?><p class="ok"><?=htmlspecialchars($ok)?></p><?php endif?><?php if($err):?><p class="bad"><?=htmlspecialchars($err)?></p><?php endif?><div class="panel"><table><tr><th>ID</th><th>To / ICCID</th><th>Message</th><th>Status</th><th>Updated</th><th>Action</th></tr><?php foreach($messages as $message):?><tr><td><?=htmlspecialchars((string)($message['id']??''))?></td><td><?=htmlspecialchars((string)($message['to']??$message['iccid']??''))?></td><td><?=htmlspecialchars((string)($message['body']??''))?></td><td><?=htmlspecialchars((string)($message['status']??''))?></td><td><?=htmlspecialchars((string)($message['updated_at']??$message['created_at']??''))?></td><td><form method="post" onsubmit="return confirm('Send this SMS again as a new message?')"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="resend"><input type="hidden" name="id" value="<?=htmlspecialchars((string)($message['id']??''))?>"><button type="submit">Resend</button></form></td></tr><?php endforeach?></table></div><?php bottom();
