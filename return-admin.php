<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if (empty($_SESSION['admin_return_uid'])) {
    header('Location: /client');
    exit;
}

$adminId = (int)$_SESSION['admin_return_uid'];
$customerId = max(0, (int)($_SESSION['admin_return_customer_id'] ?? 0));

$q = db()->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND account_status='active' LIMIT 1");
$q->execute([$adminId]);
$adminExists = (bool)$q->fetchColumn();

unset($_SESSION['admin_return_uid'], $_SESSION['admin_return_customer_id']);

if (!$adminExists) {
    unset($_SESSION['uid']);
    header('Location: /login.php');
    exit;
}

session_regenerate_id(true);
$_SESSION['uid'] = $adminId;
$destination = $customerId > 0 ? '/admin/customer.php?id='.$customerId : '/admin/';
header('Location: '.$destination);
exit;
