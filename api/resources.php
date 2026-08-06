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
try {
	$q = db()->prepare('SELECT id FROM services WHERE user_id=? AND ptero_identifier=? LIMIT 1');
	$q->execute([(int)$u['id'], $id]);
	$serviceId = (int)$q->fetchColumn();
} catch (Throwable $e) {
}

try {
	echo json_encode(['ok' => true, 'data' => ptero('/servers/'.$id.'/resources')], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
	if ($serviceId > 0 && ptero_deleted_server_error($e->getMessage())) {
		clear_deleted_ptero_service_link($serviceId, (int)$u['id']);
		http_response_code(404);
		echo json_encode(['ok' => false, 'error' => deleted_ptero_service_message()], JSON_UNESCAPED_SLASHES);
		exit;
	}

	http_response_code(502);
	echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}