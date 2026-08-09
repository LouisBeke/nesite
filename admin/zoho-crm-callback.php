<?php
declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';
require_admin();

try {
    $expectedState = (string)($_SESSION['zoho_crm_oauth_state'] ?? '');
    $startedAt = (int)($_SESSION['zoho_crm_oauth_started_at'] ?? 0);
    unset($_SESSION['zoho_crm_oauth_state'], $_SESSION['zoho_crm_oauth_started_at']);

    $state = (string)($_GET['state'] ?? '');
    if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state) || $startedAt < time() - 600) {
        throw new RuntimeException('The Zoho connection request expired. Please try Connect Zoho CRM again.');
    }
    if (!empty($_GET['error'])) {
        throw new RuntimeException('Zoho authorization was not completed: '.(string)$_GET['error']);
    }
    $location = strtolower(trim((string)($_GET['location'] ?? 'eu')));
    if ($location !== '' && $location !== 'eu') {
        throw new RuntimeException('This integration is configured for a Zoho EU account.');
    }
    $code = trim((string)($_GET['code'] ?? ''));
    if ($code === '') throw new RuntimeException('Zoho did not return an authorization code.');

    zoho_crm_exchange_authorization_code($code, zoho_crm_callback_url());
    save_setting('zoho_crm_enabled', '1');
    $_SESSION['zoho_crm_flash_message'] = 'Zoho CRM connected successfully. Automatic CRM sync is enabled.';
} catch (Throwable $e) {
    $_SESSION['zoho_crm_flash_error'] = $e->getMessage();
}

header('Location: /admin/settings.php');
exit;
