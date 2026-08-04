<?php
declare(strict_types=1);
function mollie_request(string $path,string $method='GET',?array $body=null): array {
    $key=(string)(cfg('mollie.api_key')??'');
    if($key==='' || str_contains($key,'CHANGE_ME')) throw new RuntimeException('Mollie API key is not configured.');
    $ch=curl_init('https://api.mollie.com/v2'.$path);
    $headers=['Authorization: Bearer '.$key,'Accept: application/json','Content-Type: application/json'];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>20,CURLOPT_CUSTOMREQUEST=>$method]);
    if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_SLASHES));
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    if($raw===false){$e=curl_error($ch);curl_close($ch);throw new RuntimeException('Mollie connection failed: '.$e);}curl_close($ch);
    $j=$raw!==''?json_decode($raw,true):[];
    if($code<200||$code>=300){$detail=$j['detail']??$j['title']??('Mollie HTTP '.$code);throw new RuntimeException((string)$detail);}
    return is_array($j)?$j:[];
}
function mollie_payment_for_invoice(int $invoiceId): array {
    $q=db()->prepare('SELECT i.*,u.name,u.email FROM invoices i JOIN users u ON u.id=i.user_id WHERE i.id=?');$q->execute([$invoiceId]);$i=$q->fetch();
    if(!$i) throw new RuntimeException('Invoice not found.');
    if($i['status']==='paid') throw new RuntimeException('Invoice is already paid.');
    if(!empty($i['mollie_payment_id'])){
        try{$old=mollie_request('/payments/'.rawurlencode($i['mollie_payment_id']));if(!in_array($old['status']??'',['failed','canceled','expired'],true))return $old;}catch(Throwable $e){}
    }
    $base=rtrim((string)cfg('app_url'),'/');$webhook=(string)(cfg('mollie.webhook_url')??($base.'/mollie-webhook.php'));
    $payload=['amount'=>['currency'=>$i['currency'],'value'=>number_format((float)$i['total'],2,'.','')],'description'=>'FoxNetwork '.$i['invoice_number'],'redirectUrl'=>$base.'/payment-return.php?invoice='.$invoiceId,'webhookUrl'=>$webhook,'metadata'=>['invoice_id'=>$invoiceId,'invoice_number'=>$i['invoice_number']]];
    $p=mollie_request('/payments','POST',$payload);
    db()->prepare('UPDATE invoices SET mollie_payment_id=? WHERE id=?')->execute([$p['id']??null,$invoiceId]);
    $exists=db()->prepare("SELECT id FROM payments WHERE provider='mollie' AND provider_reference=? LIMIT 1");$exists->execute([$p['id']??'']);
    if(!$exists->fetchColumn())db()->prepare("INSERT INTO payments(user_id,invoice_id,provider,provider_reference,amount,currency,status) VALUES(?,?,?,?,?,?,'pending')")->execute([$i['user_id'],$invoiceId,'mollie',$p['id']??null,$i['total'],$i['currency']]);
    return $p;
}
function process_mollie_payment(string $paymentId): void {
    $p=mollie_request('/payments/'.rawurlencode($paymentId));$meta=$p['metadata']??[];$iid=(int)($meta['invoice_id']??0);
    if(!$iid){$q=db()->prepare('SELECT invoice_id FROM payments WHERE provider_reference=? LIMIT 1');$q->execute([$paymentId]);$iid=(int)$q->fetchColumn();}
    if(!$iid) return;
    $status=(string)($p['status']??'');
    if($status==='paid'){
        mark_invoice_paid($iid,'mollie',$paymentId);
        db()->prepare("UPDATE payments SET status='completed' WHERE provider='mollie' AND provider_reference=?")->execute([$paymentId]);
        $q=db()->prepare('SELECT i.order_id,i.service_id,i.invoice_number,i.total,i.currency,u.id,u.name,u.email,u.email_notifications FROM invoices i JOIN users u ON u.id=i.user_id WHERE i.id=?');$q->execute([$iid]);$iv=$q->fetch();$oid=(int)($iv['order_id']??0);$sid=(int)($iv['service_id']??0);
        send_template('payment_received',$iv,['invoice_number'=>$iv['invoice_number'],'total'=>number_format((float)$iv['total'],2),'currency'=>$iv['currency']]);
        apply_pending_service_change_for_invoice($iid);
        if($oid){$sid=ensure_service_for_order($oid);$sv=service_row($sid);if(empty($sv['ptero_server_id']) && !in_array($sv['status'],['provisioning','active','terminated'],true)){provisioning_dispatch_order($oid,['source'=>'mollie','invoice_id'=>$iid,'payment_id'=>$paymentId]);}elseif($sv['status']==='suspended'){unsuspend_service($sid,0);send_template('service_unsuspended',$iv,['service_name'=>$sv['name']]);}}
                elseif($sid){
                        $sv=service_row($sid);
                        db()->prepare("UPDATE services SET next_due_at=
                                CASE
                                    WHEN COALESCE(renewal_unit,'month')='day' THEN DATE_ADD(COALESCE(next_due_at,NOW()), INTERVAL GREATEST(1,COALESCE(renewal_interval,1)) DAY)
                                    WHEN COALESCE(renewal_unit,'month')='week' THEN DATE_ADD(COALESCE(next_due_at,NOW()), INTERVAL GREATEST(1,COALESCE(renewal_interval,1)) WEEK)
                                    WHEN COALESCE(renewal_unit,'month')='year' THEN DATE_ADD(COALESCE(next_due_at,NOW()), INTERVAL GREATEST(1,COALESCE(renewal_interval,1)) YEAR)
                                    ELSE DATE_ADD(COALESCE(next_due_at,NOW()), INTERVAL GREATEST(1,COALESCE(renewal_interval,1)) MONTH)
                                END
                                WHERE id=?")->execute([$sid]);
                        if($sv['status']==='suspended' && setting('auto_unsuspend','1')==='1'){unsuspend_service($sid,0);send_template('service_unsuspended',$iv,['service_name'=>$sv['name']]);}
                }
    } else {
        $map=['failed'=>'failed','canceled'=>'cancelled','expired'=>'expired','pending'=>'pending','open'=>'pending','authorized'=>'pending'];$local=$map[$status]??'pending';
        db()->prepare("UPDATE payments SET status=? WHERE provider='mollie' AND provider_reference=?")->execute([$local,$paymentId]);
    }
}
