<?php
require __DIR__.'/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function mobile_out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mobile_out(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    mobile_out(['ok' => false, 'error' => 'Invalid JSON body'], 422);
}

$email = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');

if ($email === '' || $password === '') {
    mobile_out(['ok' => false, 'error' => 'Email and password are required'], 422);
}

$statement = db()->prepare('SELECT * FROM users WHERE lower(email)=? LIMIT 1');
$statement->execute([$email]);
$user = $statement->fetch();

if (!$user || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
    mobile_out(['ok' => false, 'error' => 'Invalid email or password'], 401);
}

if (($user['account_status'] ?? 'active') === 'disabled') {
    mobile_out(['ok' => false, 'error' => 'This account has been disabled.'], 403);
}

session_regenerate_id(true);
$_SESSION['uid'] = (int)$user['id'];
$_SESSION['mobile_api_session'] = true;
$sessionId = session_id();

try {
    link_existing_ptero_user_for_local_user($user);
    auto_setup_ptero_client_key_for_local_user($user);
} catch (Throwable $e) {
}

mobile_out([
    'ok' => true,
    'token' => $sessionId,
    'user' => [
        'id' => (int)$user['id'],
        'name' => (string)($user['name'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'role' => (string)($user['role'] ?? 'customer'),
    ],
]);
