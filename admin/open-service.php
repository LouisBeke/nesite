<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$admin = require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/services.php');
    exit;
}
verify_csrf();

$serviceId = (int)($_POST['service_id'] ?? 0);
$q = db()->prepare("SELECT s.id service_id,s.user_id,s.name service_name,s.status,s.ptero_server_id,s.ptero_identifier,u.* FROM services s JOIN users u ON u.id=s.user_id WHERE s.id=? LIMIT 1");
$q->execute([$serviceId]);
$service = $q->fetch();
if (!$service) {
    http_response_code(404);
    die('Service not found.');
}
if (($service['account_status'] ?? 'active') === 'disabled') {
    http_response_code(409);
    die('Enable this customer account before opening the service.');
}
if (($service['status'] ?? '') === 'terminated') {
    http_response_code(409);
    die('A terminated service cannot be opened.');
}

$identifier = trim((string)($service['ptero_identifier'] ?? ''));
if ($identifier === '' && !empty($service['ptero_server_id'])) {
    try {
        $remote = app_ptero('/servers/' . (int)$service['ptero_server_id']);
        $identifier = trim((string)($remote['attributes']['identifier'] ?? ''));
        if ($identifier !== '') {
            db()->prepare('UPDATE services SET ptero_identifier=? WHERE id=?')->execute([$identifier, $serviceId]);
        }
    } catch (Throwable $e) {
        http_response_code(502);
        die('Could not load this service from Pterodactyl: ' . e($e->getMessage()));
    }
}
if ($identifier === '') {
    http_response_code(409);
    die('This service is not linked to a Pterodactyl server yet.');
}

try {
    auto_setup_ptero_client_key_for_local_user($service);
} catch (Throwable $e) {
    // The service page still opens with application-backed information when no client key can be provisioned.
}

try {
    audit_log('service.open_as_customer', 'service', $serviceId, 'Opened ' . (string)$service['service_name'] . ' as customer #' . (int)$service['user_id']);
} catch (Throwable $e) {
}
$_SESSION['admin_return_uid'] = (int)$admin['id'];
$_SESSION['uid'] = (int)$service['user_id'];

header('Location: /server.php?id=' . rawurlencode($identifier));
exit;
