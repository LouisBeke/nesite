<?php
declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function contact_out(bool $ok, string $message, array $extra = []): never {
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    contact_out(false, 'Method not allowed.');
}

// Honeypot field helps drop basic bot submissions.
$honeypot = trim((string)($_POST['website'] ?? ''));
if ($honeypot !== '') {
    contact_out(true, 'Thanks, your message is queued.');
}

$name = trim((string)($_POST['name'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$subject = trim((string)($_POST['subject'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if ($name === '' || mb_strlen($name) < 2) {
    contact_out(false, 'Please enter your name.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    contact_out(false, 'Please enter a valid email address.');
}
if ($subject === '' || mb_strlen($subject) < 3) {
    contact_out(false, 'Please enter a subject.');
}
if ($message === '' || mb_strlen($message) < 10) {
    contact_out(false, 'Please provide more details in your message.');
}

$subject = mb_substr($subject, 0, 190);
$message = mb_substr($message, 0, 8000);

try {
    $u = user();
    if ($u) {
        $ticketSubject = '[Contact] '.$subject;
        db()->prepare("INSERT INTO support_tickets(user_id,service_id,subject,category,priority,status) VALUES(?,?,?,?,?,'awaiting_staff')")
            ->execute([(int)$u['id'], null, $ticketSubject, 'other', 'normal']);
        $ticketId = (int)db()->lastInsertId();

        $fullMessage = "From: {$name} <{$email}>\n\n{$message}";
        db()->prepare('INSERT INTO support_messages(ticket_id,user_id,message) VALUES(?,?,?)')
            ->execute([$ticketId, (int)$u['id'], $fullMessage]);

        zoho_crm_try_sync_ticket($ticketId);

        try {
            zoho_crm_sync_customer($u);
        } catch (Throwable $crmError) {
            error_log('FoxNetwork contact Zoho CRM customer sync failed: '.$crmError->getMessage());
        }

        contact_out(true, 'Ticket created. Our team will reply shortly.', ['mode' => 'ticket', 'ticket_id' => $ticketId]);
    }

    $supportTo = (string)setting('support_email', 'info@foxnetwork.be');
    $emailSubject = '[Contact Form] '.$subject;
    $body = '<h3>New contact form message</h3>'
        .'<p><b>Name:</b> '.e($name).'</p>'
        .'<p><b>Email:</b> '.e($email).'</p>'
        .'<p><b>Subject:</b> '.e($subject).'</p>'
        .'<p><b>Message:</b><br>'.nl2br(e($message)).'</p>';
    try {
        zoho_crm_sync_lead($name, $email, $subject, $message);
    } catch (Throwable $crmError) {
        error_log('FoxNetwork contact Zoho CRM lead sync failed: '.$crmError->getMessage());
    }
    send_custom_email(null, $supportTo, $emailSubject, $body);
    contact_out(true, 'Thanks, your message has been sent by email.', ['mode' => 'email']);
} catch (Throwable $e) {
    http_response_code(500);
    contact_out(false, 'Could not submit your message right now. Please open a support ticket.');
}
