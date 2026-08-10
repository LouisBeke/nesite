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
$q = db()->prepare("SELECT s.id,s.name,s.status,s.provisioning_provider,s.linode_instance_id,s.linode_ipv4,s.linode_ipv6,s.ptero_identifier,s.ptero_server_id,s.next_due_at,s.price_monthly,s.is_trial,p.name AS product_name FROM services s LEFT JOIN store_products p ON p.id=s.product_id WHERE s.user_id=? AND s.status<>'terminated' ORDER BY s.id DESC");
$q->execute([(int)$u['id']]);
foreach ($q->fetchAll() as $row) {
    $rows[] = [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'product' => (string)($row['product_name'] ?? ''),
        'status' => (string)$row['status'],
        'provider' => linode_service_provider($row),
        'hostname' => linode_service_provider($row)==='linode'?(string)($row['linode_ipv4']??''):(string)($row['ptero_identifier'] ?? ''),
        'ipv4' => (string)($row['linode_ipv4']??''),
        'ipv6' => (string)($row['linode_ipv6']??''),
        'renewal_date' => (string)($row['next_due_at'] ?? ''),
        'price' => (float)($row['price_monthly'] ?? 0),
        'is_trial' => !empty($row['is_trial']),
    ];
}

echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_SLASHES);
