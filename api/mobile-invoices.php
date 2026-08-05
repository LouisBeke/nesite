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
$q = db()->prepare("SELECT i.id, i.invoice_number, i.total, i.status, i.created_at, i.due_at, i.currency_code FROM invoices i WHERE i.user_id=? ORDER BY i.id DESC");
$q->execute([(int)$u['id']]);
foreach ($q->fetchAll() as $row) {
    $rows[] = [
        'id' => (int)$row['id'],
        'number' => (string)$row['invoice_number'],
        'amount' => (float)$row['total'],
        'status' => (string)$row['status'],
        'issued_at' => (string)($row['created_at'] ?? ''),
        'currency' => (string)($row['currency_code'] ?? 'EUR'),
    ];
}

echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_SLASHES);
