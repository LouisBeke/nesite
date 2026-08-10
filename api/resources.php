<?php
require __DIR__.'/../app/bootstrap.php';
$u = require_user();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['id'] ?? ''));
if ($id === '') {
	http_response_code(422);
	echo json_encode(['ok' => false, 'error' => 'Invalid server.']);
	exit;
}

$serviceId = 0;
$linodeService = null;
try {
    if (ctype_digit($id)) {
        $linodeQ=db()->prepare("SELECT s.*,p.ram_mb,p.disk_mb FROM services s LEFT JOIN store_products p ON p.id=s.product_id WHERE s.id=? AND s.user_id=? LIMIT 1");
        $linodeQ->execute([(int)$id,(int)$u['id']]);
        $linodeService=$linodeQ->fetch();
        if($linodeService&&linode_service_provider($linodeService)==='linode'){
            $instance=linode_api('/linode/instances/'.linode_service_instance($linodeService));
            $state=(string)($instance['status']??'offline');
            $mapped=in_array($state,['running'],true)?'running':(in_array($state,['booting','provisioning','rebooting','busy'],true)?'starting':'offline');
            echo json_encode(['ok'=>true,'data'=>['attributes'=>['current_state'=>$mapped,'provider_state'=>$state,'resources'=>['cpu_absolute'=>0,'memory_bytes'=>(int)($linodeService['ram_mb']??0)*1024*1024,'disk_bytes'=>(int)($linodeService['disk_mb']??0)*1024*1024],'network'=>['ipv4'=>(array)($instance['ipv4']??[]),'ipv6'=>(string)($instance['ipv6']??'')]]]],JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
	$q = db()->prepare('SELECT id FROM services WHERE user_id=? AND ptero_identifier=? LIMIT 1');
	$q->execute([(int)$u['id'], $id]);
	$serviceId = (int)$q->fetchColumn();
} catch (Throwable $e) {
    if ($linodeService && linode_service_provider($linodeService)==='linode') {
        http_response_code(502);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES);
        exit;
    }
}

try {
	echo json_encode(['ok' => true, 'data' => ptero('/servers/'.$id.'/resources')], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
	if ($serviceId > 0 && ptero_deleted_server_error($e->getMessage())) {
		$cleared=clear_deleted_ptero_service_link($serviceId, (int)$u['id']);
		http_response_code($cleared?404:502);
		echo json_encode(['ok' => false, 'error' => $cleared?deleted_ptero_service_message():inaccessible_ptero_service_message()], JSON_UNESCAPED_SLASHES);
		exit;
	}

	http_response_code(502);
	echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
