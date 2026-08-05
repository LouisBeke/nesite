<?php
require __DIR__.'/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$u = user();
if (!$u) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$serviceId = (int)($_GET['service_id'] ?? 0);
if ($serviceId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid service id']);
    exit;
}

$service = db()->prepare('SELECT ptero_identifier FROM services WHERE id=? AND user_id=? LIMIT 1');
$service->execute([$serviceId, (int)$u['id']]);
$serviceRow = $service->fetch();
if (!$serviceRow || empty($serviceRow['ptero_identifier'])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Service not found']);
    exit;
}

try {
    $data = ptero('/servers/' . rawurlencode((string)$serviceRow['ptero_identifier']) . '/resources');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
