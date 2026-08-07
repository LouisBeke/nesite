<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, no-transform, max-age=0');

function admin_sftp_out(bool $ok, $data = null, string $error = ''): never {
    echo json_encode($ok ? ['ok' => true, 'data' => $data] : ['ok' => false, 'error' => $error], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $admin = require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST required.');
    }
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        throw new RuntimeException('Security token expired.');
    }
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $serviceId = (int)($body['service_id'] ?? 0);
    $q = db()->prepare('SELECT s.id,s.name,s.ptero_server_id,s.ptero_identifier,u.ptero_user_id FROM services s JOIN users u ON u.id=s.user_id WHERE s.id=? LIMIT 1');
    $q->execute([$serviceId]);
    $service = $q->fetch();
    if (!$service) throw new RuntimeException('Service not found.');
    if (empty($service['ptero_server_id'])) throw new RuntimeException('This service is not provisioned in Pterodactyl.');

    $server = app_ptero('/servers/' . (int)$service['ptero_server_id']);
    $serverAttributes = $server['attributes'] ?? ($server['data']['attributes'] ?? []);
    $nodeId = (int)($serverAttributes['node'] ?? 0);
    $pteroUserId = (int)($serverAttributes['user'] ?? ($service['ptero_user_id'] ?? 0));
    $identifier = trim((string)($serverAttributes['identifier'] ?? $service['ptero_identifier'] ?? ''));
    if ($nodeId <= 0 || $pteroUserId <= 0 || $identifier === '') {
        throw new RuntimeException('Pterodactyl returned incomplete server details.');
    }

    $node = app_ptero('/nodes/' . $nodeId);
    $pteroUser = app_ptero('/users/' . $pteroUserId);
    $nodeAttributes = $node['attributes'] ?? ($node['data']['attributes'] ?? []);
    $userAttributes = $pteroUser['attributes'] ?? ($pteroUser['data']['attributes'] ?? []);
    $host = trim((string)($nodeAttributes['fqdn'] ?? ''));
    $port = (int)($nodeAttributes['daemon_sftp'] ?? 0);
    $accountUsername = trim((string)($userAttributes['username'] ?? ''));
    if ($host === '' || $port <= 0 || $accountUsername === '') {
        throw new RuntimeException('Pterodactyl returned incomplete SFTP details.');
    }

    $username = $accountUsername . '.' . $identifier;
    $uriHost = str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
    admin_sftp_out(true, [
        'service' => (string)$service['name'],
        'host' => $host,
        'port' => $port,
        'username' => $username,
        'uri' => 'sftp://' . rawurlencode($username) . '@' . $uriHost . ':' . $port,
        'command' => 'sftp -P ' . $port . ' ' . $username . '@' . $host,
    ]);
} catch (Throwable $e) {
    admin_sftp_out(false, null, $e->getMessage());
}
