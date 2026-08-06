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

$q = db()->prepare('SELECT ptero_identifier, ptero_server_id FROM services WHERE id=? AND user_id=? LIMIT 1');
$q->execute([$serviceId, (int)$u['id']]);
$service = $q->fetch();
if (!$service) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Service not found']);
    exit;
}

$identifier = (string)($service['ptero_identifier'] ?? '');
if ($identifier === '') {
    $identifier = (string)($service['ptero_server_id'] ?? '');
}

if ($identifier === '') {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Server console is not linked yet']);
    exit;
}

$panel = rtrim((string)cfg('pterodactyl.url'), '/');
echo json_encode([
    'ok' => true,
    'data' => [
        'url' => $panel . '/server/' . rawurlencode($identifier),
    ],
], JSON_UNESCAPED_SLASHES);
