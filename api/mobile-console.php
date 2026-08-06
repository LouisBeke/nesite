<?php
require __DIR__.'/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function mobile_console_deleted_server_error(string $message): bool {
    $message = strtolower($message);
    return str_contains($message, 'no query results for model')
        || str_contains($message, 'pterodactyl\\models\\server')
        || str_contains($message, 'pterodactyl\models\server')
        || str_contains($message, 'server not found');
}

function mobile_console_clear_ptero_link(array $service, array $user): void {
    try {
        db()->prepare('UPDATE services SET ptero_identifier=NULL, ptero_server_id=NULL WHERE id=? AND user_id=?')
            ->execute([(int)$service['id'], (int)$user['id']]);
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

$q = db()->prepare('SELECT id, ptero_identifier, ptero_server_id FROM services WHERE id=? AND user_id=? LIMIT 1');
$q->execute([$serviceId, (int)$u['id']]);
$service = $q->fetch();
if (!$service) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Service not found']);
    exit;
}

$identifier = (string)($service['ptero_identifier'] ?? '');
$pteroServerId = (int)($service['ptero_server_id'] ?? 0);
$identifier = preg_replace('/[^a-zA-Z0-9_-]/', '', $identifier);

if ($identifier === '' && $pteroServerId > 0) {
    try {
        $server = app_ptero('/servers/' . $pteroServerId);
        $identifier = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($server['attributes']['identifier'] ?? ''));
        if ($identifier !== '') {
            db()->prepare('UPDATE services SET ptero_identifier=? WHERE id=? AND user_id=?')
                ->execute([$identifier, (int)$service['id'], (int)$u['id']]);
        }
    } catch (Throwable $e) {
        if (mobile_console_deleted_server_error($e->getMessage())) {
            mobile_console_clear_ptero_link($service, $u);
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'This server was deleted in Pterodactyl and is no longer linked. Recreate or relink the service in the panel.']);
            exit;
        }
    }
}

if ($identifier === '') {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'This service is not linked to a Pterodactyl server yet.']);
    exit;
}

$clientToken = dec($u['ptero_client_key'] ?? null);
if (!$clientToken) {
    auto_setup_ptero_client_key_for_local_user($u);
    $uq = db()->prepare('SELECT ptero_client_key FROM users WHERE id=? LIMIT 1');
    $uq->execute([(int)$u['id']]);
    $clientToken = dec((string)($uq->fetchColumn() ?: ''));
}

if (!$clientToken) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Pterodactyl Client API key is not configured for this account. Sign out and sign in again, then try console again.']);
    exit;
}

try {
    $base = rtrim((string)cfg('pterodactyl.url'), '/');
    $ch = curl_init($base . '/api/client/servers/' . rawurlencode($identifier) . '/websocket');
    if ($ch === false) {
        throw new RuntimeException('Could not initialize Pterodactyl console request.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $clientToken,
            'Accept: Application/vnd.pterodactyl.v1+json',
            'Content-Type: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $curlError = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        throw new RuntimeException('Could not contact Pterodactyl: ' . ($curlError ?: 'cURL error ' . $errno));
    }

    $websocket = json_decode((string)$raw, true);
    if ($code < 200 || $code >= 300 || !is_array($websocket)) {
        $detail = is_array($websocket) ? ($websocket['errors'][0]['detail'] ?? $websocket['message'] ?? null) : null;
        if (!$detail) {
            $plain = trim(strip_tags((string)$raw));
            $detail = $plain !== '' ? mb_substr($plain, 0, 300) : 'No response body';
        }
        throw new RuntimeException('Pterodactyl HTTP ' . $code . ': ' . $detail);
    }

    $data = $websocket['data'] ?? [];
    $socket = (string)($data['socket'] ?? '');
    $token = (string)($data['token'] ?? '');

    if (str_starts_with($socket, 'https://')) {
        $socket = 'wss://' . substr($socket, 8);
    } elseif (str_starts_with($socket, 'http://')) {
        $socket = 'ws://' . substr($socket, 7);
    } elseif (str_starts_with($socket, '//')) {
        $socket = 'wss:' . $socket;
    }

    if ($socket === '' || $token === '') {
        throw new RuntimeException('Pterodactyl did not return console websocket access for this server.');
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'socket' => $socket,
            'token' => $token,
            'server' => $identifier,
        ],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (mobile_console_deleted_server_error($e->getMessage())) {
        mobile_console_clear_ptero_link($service, $u);
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'This server was deleted in Pterodactyl and is no longer linked. Recreate or relink the service in the panel.']);
        exit;
    }

    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'server' => $identifier,
        'service_id' => (int)$service['id'],
    ], JSON_UNESCAPED_SLASHES);
}
