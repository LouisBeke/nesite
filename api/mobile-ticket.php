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

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid ticket id']);
    exit;
}

$ticketQ = db()->prepare('SELECT id, subject, status, category, priority, created_at, updated_at FROM support_tickets WHERE id=? AND user_id=? LIMIT 1');
$ticketQ->execute([$id, (int)$u['id']]);
$ticket = $ticketQ->fetch();

if (!$ticket) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Ticket not found']);
    exit;
}

$messageQ = db()->prepare('SELECT m.id, m.message, m.created_at, u.name, u.role FROM support_messages m LEFT JOIN users u ON u.id=m.user_id WHERE m.ticket_id=? AND m.is_internal=0 ORDER BY m.id');
$messageQ->execute([$id]);
$messages = [];
foreach ($messageQ->fetchAll() as $row) {
    $messages[] = [
        'id' => (int)$row['id'],
        'message' => (string)$row['message'],
        'author' => (string)($row['name'] ?? 'Support'),
        'is_staff' => ((string)($row['role'] ?? 'customer') === 'admin'),
        'created_at' => (string)$row['created_at'],
    ];
}

echo json_encode([
    'ok' => true,
    'data' => [
        'id' => (int)$ticket['id'],
        'subject' => (string)$ticket['subject'],
        'status' => (string)$ticket['status'],
        'priority' => (string)($ticket['priority'] ?? ''),
        'department' => (string)($ticket['category'] ?? ''),
        'created_at' => (string)$ticket['created_at'],
        'updated_at' => (string)$ticket['updated_at'],
        'closed_at' => '',
        'web_url' => '',
        'messages' => $messages,
    ],
], JSON_UNESCAPED_SLASHES);
