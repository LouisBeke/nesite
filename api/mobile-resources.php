<?php
require __DIR__.'/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function mobile_resources_deleted_server_error(string $message): bool {
    $message = strtolower($message);
    return str_contains($message, 'no query results for model')
        || str_contains($message, 'pterodactyl\\models\\server')
        || str_contains($message, 'pterodactyl\models\server')
        || str_contains($message, 'server not found');
}

function mobile_resources_clear_ptero_link(int $serviceId, int $userId): void {
    try {
        db()->prepare('UPDATE services SET ptero_identifier=NULL, ptero_server_id=NULL WHERE id=? AND user_id=?')
            ->execute([$serviceId, $userId]);
    } catch (Throwable $e) {
    }
}

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
    $pteroData = ptero('/servers/' . rawurlencode((string)$serviceRow['ptero_identifier']) . '/resources');
    $attrs = $pteroData['attributes'] ?? [];
    echo json_encode(['ok' => true, 'data' => [
        'current_state' => $attrs['current_state'] ?? 'unknown',
        'is_suspended' => (bool)($attrs['is_suspended'] ?? false),
        'resources' => $attrs['resources'] ?? [],
    ]], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (mobile_resources_deleted_server_error($e->getMessage())) {
        mobile_resources_clear_ptero_link($serviceId, (int)$u['id']);
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'This server was deleted in Pterodactyl and is no longer linked. Recreate or relink the service in the panel.']);
        exit;
    }

    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
