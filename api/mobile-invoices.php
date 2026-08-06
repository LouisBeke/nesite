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

$columns = [];
foreach (db()->query('SHOW COLUMNS FROM invoices')->fetchAll() as $column) {
    $columns[(string)$column['Field']] = true;
}

$select = ['id', 'status'];
foreach (['invoice_number', 'number', 'total', 'amount', 'created_at', 'issued_at', 'due_at', 'due_date', 'currency_code', 'currency'] as $column) {
    if (isset($columns[$column])) $select[] = $column;
}

$rows = [];
$q = db()->prepare('SELECT ' . implode(', ', array_unique($select)) . ' FROM invoices WHERE user_id=? ORDER BY id DESC');
$q->execute([(int)$u['id']]);
foreach ($q->fetchAll() as $row) {
    $id = (int)$row['id'];
    $rows[] = [
        'id' => $id,
        'number' => (string)($row['invoice_number'] ?? $row['number'] ?? ('Invoice #' . $id)),
        'amount' => (float)($row['total'] ?? $row['amount'] ?? 0),
        'status' => (string)($row['status'] ?? 'Unknown'),
        'issued_at' => (string)($row['created_at'] ?? $row['issued_at'] ?? $row['due_at'] ?? $row['due_date'] ?? ''),
        'currency' => (string)($row['currency_code'] ?? $row['currency'] ?? 'EUR'),
    ];
}

echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_SLASHES);
