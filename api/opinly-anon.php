<?php
declare(strict_types=1);

/** Stores the browser's Opinly anon id in the session for server-side attribution. */

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/opinly.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = (string)file_get_contents('php://input');
$body = json_decode($raw !== '' ? $raw : '[]', true);
$anonId = is_array($body) ? trim((string)($body['anonId'] ?? '')) : '';

if ($anonId === '' || !preg_match('/^[A-Za-z0-9._-]{8,120}$/', $anonId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid anon id']);
    exit;
}

opinly_remember_anon_id($anonId);
echo json_encode(['ok' => true]);
