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

$rows = [];
$q = db()->prepare("SELECT id, subject, status, updated_at FROM support_tickets WHERE user_id=? ORDER BY updated_at DESC");
$q->execute([(int)$u['id']]);
foreach ($q->fetchAll() as $row) {
    $rows[] = [
        'id' => (int)$row['id'],
        'subject' => (string)$row['subject'],
        'status' => (string)$row['status'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_SLASHES);
