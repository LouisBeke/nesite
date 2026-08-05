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

echo json_encode([
    'ok' => true,
    'user' => [
        'id' => (int)$u['id'],
        'name' => (string)($u['name'] ?? ''),
        'email' => (string)($u['email'] ?? ''),
        'role' => (string)($u['role'] ?? 'customer'),
    ],
], JSON_UNESCAPED_SLASHES);
