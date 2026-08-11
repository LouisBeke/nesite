<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
$admin=require_admin();
$pdo=db();

function queue_status_counts(PDO $pdo,string $table): array {
    $allowed=['provisioning_queue','automation_jobs'];
    if(!in_array($table,$allowed,true))return [];
    $out=[];
    foreach($pdo->query("SELECT status,COUNT(*) total FROM `$table` GROUP BY status")->fetchAll() as $row)$out[(string)$row['status']]=(int)$row['total'];
    return $out;
}

$provisioning=$pdo->query("SELECT q.id,q.job_key,q.job_type,q.status,q.priority,q.attempts,q.max_attempts,q.run_at,q.started_at,q.finished_at,q.updated_at,q.worker_id,q.last_error,
    s.id service_id,s.name service_name,s.status service_status,u.name customer_name,u.email customer_email,p.name product_name
    FROM provisioning_queue q
    LEFT JOIN services s ON q.job_key=CONCAT('service-provision-',s.id)
    LEFT JOIN users u ON u.id=s.user_id
    LEFT JOIN store_products p ON p.id=s.product_id
    ORDER BY FIELD(q.status,'running','pending','retry_wait','failed','completed'),q.priority DESC,q.id ASC LIMIT 150")->fetchAll();
$waitingTotal=(int)$pdo->query("SELECT COUNT(*) FROM provisioning_queue WHERE status IN ('pending','retry_wait')")->fetchColumn();
$position=0;
foreach($provisioning as &$job){
    if(in_array((string)$job['status'],['pending','retry_wait'],true)){$position++;$job['position']=$position;$job['waiting_total']=$waitingTotal;}else{$job['position']=null;$job['waiting_total']=$waitingTotal;}
    $job['id']=(int)$job['id'];$job['priority']=(int)$job['priority'];$job['attempts']=(int)$job['attempts'];$job['max_attempts']=(int)$job['max_attempts'];$job['service_id']=$job['service_id']!==null?(int)$job['service_id']:null;
}
unset($job);

$jobLogs=[];
$activeIds=array_column(array_filter($provisioning,static fn(array $row): bool=>in_array((string)$row['status'],['running','pending','retry_wait','failed'],true)),'id');
if($activeIds){
    $activeIds=array_slice(array_map('intval',$activeIds),0,100);
    $logs=$pdo->query('SELECT queue_id,level,event_name,message,created_at FROM provisioning_logs WHERE queue_id IN ('.implode(',',$activeIds).') ORDER BY id DESC')->fetchAll();
    foreach($logs as $log){$qid=(int)$log['queue_id'];if(count($jobLogs[$qid]??[])<4)$jobLogs[$qid][]=$log;}
}
foreach($provisioning as &$job)$job['logs']=$jobLogs[(int)$job['id']]??[];
unset($job);

$automation=$pdo->query("SELECT id,provider,job_type,entity_type,entity_id,status,attempts,max_attempts,run_at,started_at,completed_at,updated_at,last_error
    FROM automation_jobs ORDER BY FIELD(status,'running','pending','retry_wait','failed','skipped','completed'),run_at,id LIMIT 150")->fetchAll();
foreach($automation as &$job){$job['id']=(int)$job['id'];$job['entity_id']=(int)$job['entity_id'];$job['attempts']=(int)$job['attempts'];$job['max_attempts']=(int)$job['max_attempts'];}
unset($job);

$workers=$pdo->query("SELECT worker_id,hostname,status,processed_jobs,failed_jobs,last_heartbeat,started_at,TIMESTAMPDIFF(SECOND,last_heartbeat,NOW()) heartbeat_age FROM provisioning_workers ORDER BY last_heartbeat DESC LIMIT 20")->fetchAll();
foreach($workers as &$worker){$worker['processed_jobs']=(int)$worker['processed_jobs'];$worker['failed_jobs']=(int)$worker['failed_jobs'];$worker['heartbeat_age']=(int)$worker['heartbeat_age'];$worker['online']=$worker['heartbeat_age']<=120;}
unset($worker);

echo json_encode(['ok'=>true,'server_time'=>(string)$pdo->query('SELECT NOW()')->fetchColumn(),'stats'=>['provisioning'=>queue_status_counts($pdo,'provisioning_queue'),'automation'=>queue_status_counts($pdo,'automation_jobs'),'waiting'=>$waitingTotal],'provisioning'=>$provisioning,'automation'=>$automation,'workers'=>$workers],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
