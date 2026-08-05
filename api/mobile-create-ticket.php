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

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$subject = trim((string)($input['subject'] ?? ''));
$message = trim((string)($input['message'] ?? ''));

if ($subject === '' || $message === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Subject and message are required']);
    exit;
}

db()->prepare("INSERT INTO support_tickets(user_id,service_id,subject,category,priority,status) VALUES(?,?,?,?,?,'awaiting_staff')")
    ->execute([(int)$u['id'], null, $subject, 'technical', 'normal']);
$ticketId = (int)db()->lastInsertId();
db()->prepare('INSERT INTO support_messages(ticket_id,user_id,message) VALUES(?,?,?)')->execute([$ticketId, (int)$u['id'], $message]);

echo json_encode(['ok' => true, 'data' => [
    'id' => $ticketId,
    'subject' => $subject,
    'status' => 'awaiting_staff',
    'updated_at' => date('Y-m-d H:i:s'),
]], JSON_UNESCAPED_SLASHES);
