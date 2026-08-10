<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data): never {
    // Always return 200 to the browser so reverse proxies do not replace
    // useful Pterodactyl errors with their own HTML 502 page.
    http_response_code(200);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function power_broken_panel_installation_error(string $message): bool {
    $message = strtolower($message);
    return str_contains($message, 'pterodactyl\\models\\task::permissionforaction');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['ok'=>false,'error'=>'Method not allowed']);
}

$u = user();
if (!$u) out(['ok'=>false,'error'=>'Your session expired. Sign in again.']);

$input = file_get_contents('php://input') ?: '';
$data = json_decode($input, true);
if (!is_array($data)) out(['ok'=>false,'error'=>'Invalid request body']);

// Mobile sessions authenticated via session token bypass the web CSRF token.
if (empty($_SESSION['mobile_api_session'])) {
    $sessionToken = (string)($_SESSION['csrf'] ?? '');
    $requestToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        out(['ok'=>false,'error'=>'Security token expired. Refresh the page and try again.']);
    }
}

$id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($data['id'] ?? ''));
$signal = strtolower((string)($data['signal'] ?? ''));
if ($id === '' || !in_array($signal, ['start','stop','restart','kill'], true)) {
    out(['ok'=>false,'error'=>'Invalid server or power action']);
}

$localServiceId = ctype_digit($id) ? (int)$id : 0;
if ($localServiceId > 0) {
    $q = db()->prepare('SELECT id,status,provisioning_provider,linode_instance_id,ptero_identifier,ptero_server_id FROM services WHERE id=? AND user_id=? LIMIT 1');
    $q->execute([$localServiceId, (int)$u['id']]);
    $service = $q->fetch();
    if (!$service) out(['ok'=>false,'error'=>'Service not found']);
    if (linode_service_provider($service) === 'linode') {
        if (!in_array((string)$service['status'], ['active','provisioning'], true)) out(['ok'=>false,'error'=>'Power controls are unavailable while this service is '.(string)$service['status'].'.']);
        try {
            $instanceId=linode_service_instance($service);
            linode_power_action($instanceId,$signal);
            db()->prepare('INSERT INTO service_activity(service_id,admin_user_id,action,details) VALUES(?,?,?,?)')->execute([(int)$service['id'],null,'power_'.$signal,'Linode power action from customer portal']);
            out(['ok'=>true,'signal'=>$signal,'provider'=>'linode']);
        } catch(Throwable $e) {
            out(['ok'=>false,'error'=>$e->getMessage()]);
        }
    }
    $id = (string)($service['ptero_identifier'] ?? '');
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);

    if ($id === '' && (int)($service['ptero_server_id'] ?? 0) > 0) {
        try {
            $server = app_ptero('/servers/' . (int)$service['ptero_server_id']);
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($server['attributes']['identifier'] ?? ''));
            if ($id !== '') {
                db()->prepare('UPDATE services SET ptero_identifier=? WHERE id=? AND user_id=?')
                    ->execute([$id, (int)$service['id'], (int)$u['id']]);
            }
        } catch (Throwable $e) {
            if (ptero_deleted_server_error($e->getMessage())) {
                $cleared=clear_deleted_ptero_service_link((int)$service['id'], (int)$u['id']);
                out(['ok'=>false,'error'=>$cleared?deleted_ptero_service_message():inaccessible_ptero_service_message()]);
            }
            out(['ok'=>false,'error'=>$e->getMessage()]);
        }
    }

    if ($id === '') out(['ok'=>false,'error'=>'This service is not linked to a panel server yet.']);
}

$token = dec($u['ptero_client_key'] ?? null);
if (!$token) out(['ok'=>false,'error'=>'Pterodactyl Client API key is not configured.']);

$url = rtrim((string)cfg('pterodactyl.url'), '/') . '/api/client/servers/' . rawurlencode($id) . '/power';
$payload = json_encode(['signal'=>$signal], JSON_UNESCAPED_SLASHES);

$ch = curl_init($url);
if ($ch === false) out(['ok'=>false,'error'=>'Could not initialize API request']);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Accept: Application/vnd.pterodactyl.v1+json',
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
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
    out(['ok'=>false,'error'=>'Could not contact Pterodactyl: ' . ($curlError ?: 'cURL error ' . $errno)]);
}

if ($code >= 200 && $code < 300) {
    try {
        $sid = $localServiceId;
        if ($sid <= 0) {
            $q = db()->prepare('SELECT id FROM services WHERE user_id=? AND (ptero_identifier=? OR ptero_server_id=?) LIMIT 1');
            $q->execute([(int)$u['id'], $id, $id]);
            $sid = (int)$q->fetchColumn();
        }
        if ($sid > 0) {
            db()->prepare('INSERT INTO service_activity(service_id,admin_user_id,action,details) VALUES(?,?,?,?)')
                ->execute([$sid, null, 'power_'.$signal, 'Power action from customer portal']);
        }
    } catch (Throwable $e) {
    }
    out(['ok'=>true,'signal'=>$signal,'http'=>$code]);
}

$decoded = json_decode((string)$raw, true);
$detail = null;
if (is_array($decoded)) {
    $detail = $decoded['errors'][0]['detail'] ?? $decoded['message'] ?? null;
}
if (!$detail) {
    $plain = trim(strip_tags((string)$raw));
    $detail = $plain !== '' ? mb_substr($plain, 0, 300) : 'No response body';
}
if (power_broken_panel_installation_error((string)$detail)) {
    out([
        'ok'=>false,
        'error'=>'Power controls are temporarily unavailable because the Pterodactyl panel update is incomplete. Please contact info@foxnetwork.be.',
        'http'=>$code,
    ]);
}
if ($localServiceId > 0 && ptero_deleted_server_error((string)$detail)) {
    $cleared=clear_deleted_ptero_service_link($localServiceId, (int)$u['id']);
    out(['ok'=>false,'error'=>$cleared?deleted_ptero_service_message():inaccessible_ptero_service_message(),'http'=>$code]);
}
out(['ok'=>false,'error'=>'Pterodactyl HTTP ' . $code . ': ' . $detail,'http'=>$code]);
