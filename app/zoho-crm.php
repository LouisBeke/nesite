<?php
declare(strict_types=1);

function zoho_crm_enabled(): bool {
    return setting('zoho_crm_enabled', '0') === '1';
}

function zoho_crm_full_sync_enabled(): bool {
    return zoho_crm_enabled() && (int)setting('zoho_crm_scope_version', '0') >= 2;
}

function zoho_crm_secret(string $key): string {
    $value = trim((string)setting($key, ''));
    if (str_starts_with($value, 'enc:')) {
        return trim((string)(dec(substr($value, 4)) ?? ''));
    }
    return $value;
}

function zoho_crm_credentials(): array {
    return [
        'client_id' => trim((string)setting('zoho_crm_client_id', '')),
        'client_secret' => zoho_crm_secret('zoho_crm_client_secret'),
        'refresh_token' => zoho_crm_secret('zoho_crm_refresh_token'),
    ];
}

function zoho_crm_callback_url(): string {
    return site_url('/admin/zoho-crm-callback.php');
}

function zoho_crm_authorization_url(string $state): string {
    $credentials = zoho_crm_credentials();
    if ($credentials['client_id'] === '' || $credentials['client_secret'] === '') {
        throw new RuntimeException('Enter and save the Zoho CRM client ID and client secret first.');
    }
    return 'https://accounts.zoho.com/oauth/v2/auth?'.http_build_query([
        'scope' => 'ZohoCRM.modules.contacts.ALL,ZohoCRM.modules.leads.ALL,ZohoCRM.modules.deals.ALL,ZohoCRM.modules.cases.ALL',
        'client_id' => $credentials['client_id'],
        'response_type' => 'code',
        'access_type' => 'offline',
        'prompt' => 'consent',
        'redirect_uri' => zoho_crm_callback_url(),
        'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);
}

function zoho_crm_exchange_authorization_code(string $grantCode, ?string $redirectUri = null): array {
    $clientId = trim((string)setting('zoho_crm_client_id', ''));
    $clientSecret = zoho_crm_secret('zoho_crm_client_secret');
    $grantCode = trim($grantCode);
    if ($clientId === '' || $clientSecret === '' || $grantCode === '') {
        throw new RuntimeException('Zoho CRM client ID, client secret, and one-time grant code are required.');
    }

    $form = [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $grantCode,
    ];
    if ($redirectUri !== null && $redirectUri !== '') $form['redirect_uri'] = $redirectUri;
    $response = zoho_crm_request('https://accounts.zoho.eu/oauth/v2/token', [
        'headers' => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        'post_fields' => http_build_query($form, '', '&', PHP_QUERY_RFC3986),
    ]);
    $json = $response['json'];
    $accessToken = trim((string)($json['access_token'] ?? ''));
    $refreshToken = trim((string)($json['refresh_token'] ?? ''));
    if ($response['status'] < 200 || $response['status'] >= 300 || $accessToken === '' || $refreshToken === '') {
        $error = (string)($json['error'] ?? '');
        if ($error === 'invalid_code') {
            throw new RuntimeException('Zoho rejected the one-time grant code. Generate a new code and submit it here before it expires; each code can only be used once.');
        }
        $reason = (string)($json['error_description'] ?? $error ?? ('HTTP '.$response['status']));
        throw new RuntimeException('Zoho CRM authorization failed: '.$reason);
    }

    $expiresIn = max(300, (int)($json['expires_in'] ?? 3600));
    save_setting('zoho_crm_refresh_token', 'enc:'.enc($refreshToken));
    save_setting('zoho_crm_access_token', 'enc:'.enc($accessToken));
    save_setting('zoho_crm_access_token_expires_at', (string)(time() + $expiresIn));
    return $json;
}

function zoho_crm_exchange_grant_code(string $grantCode): array {
    return zoho_crm_exchange_authorization_code($grantCode);
}

function zoho_crm_request(string $url, array $options = []): array {
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Could not initialize the Zoho CRM connection.');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $options['headers'] ?? ['Accept: application/json'],
    ]);
    if (isset($options['post_fields'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['post_fields']);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Zoho CRM connection failed: '.$curlError);
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) throw new RuntimeException('Zoho CRM returned an invalid response (HTTP '.$status.').');
    return ['status' => $status, 'json' => $json];
}

function zoho_crm_access_token(bool $forceRefresh = false): string {
    static $runtimeToken = null;
    static $runtimeExpiresAt = 0;
    if (!$forceRefresh && is_string($runtimeToken) && $runtimeToken !== '' && $runtimeExpiresAt > time() + 90) {
        return $runtimeToken;
    }
    if (!$forceRefresh) {
        $cached = zoho_crm_secret('zoho_crm_access_token');
        $expiresAt = (int)setting('zoho_crm_access_token_expires_at', '0');
        if ($cached !== '' && $expiresAt > time() + 90) {
            $runtimeToken = $cached;
            $runtimeExpiresAt = $expiresAt;
            return $cached;
        }
    }

    $credentials = zoho_crm_credentials();
    if ($credentials['client_id'] === '' || $credentials['client_secret'] === '' || $credentials['refresh_token'] === '') {
        throw new RuntimeException('Zoho CRM OAuth is not configured in Admin > Settings.');
    }

    $response = zoho_crm_request('https://accounts.zoho.eu/oauth/v2/token', [
        'headers' => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        'post_fields' => http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'refresh_token' => $credentials['refresh_token'],
        ], '', '&', PHP_QUERY_RFC3986),
    ]);
    $json = $response['json'];
    $token = trim((string)($json['access_token'] ?? ''));
    if ($response['status'] < 200 || $response['status'] >= 300 || $token === '') {
        $error = (string)($json['error'] ?? '');
        if ($error === 'invalid_code') {
            save_setting('zoho_crm_enabled', '0');
            save_setting('zoho_crm_refresh_token', '');
            save_setting('zoho_crm_access_token', '');
            save_setting('zoho_crm_access_token_expires_at', '0');
            throw new RuntimeException('The old Zoho CRM connection was cleared. Click Connect Zoho CRM to reconnect.');
        }
        $reason = (string)($json['error_description'] ?? $error ?? ('HTTP '.$response['status']));
        throw new RuntimeException('Zoho CRM OAuth failed: '.$reason);
    }

    $expiresIn = max(300, (int)($json['expires_in'] ?? 3600));
    save_setting('zoho_crm_access_token', 'enc:'.enc($token));
    save_setting('zoho_crm_access_token_expires_at', (string)(time() + $expiresIn));
    $runtimeToken = $token;
    $runtimeExpiresAt = time() + $expiresIn;
    return $token;
}

function zoho_crm_upsert(string $module, array $record, bool $retry = true, ?string $accessToken = null): array {
    $duplicateFields = [
        'Contacts' => ['Email'],
        'Leads' => ['Email'],
        'Deals' => ['Deal_Name'],
        'Cases' => ['Subject'],
    ];
    if (!isset($duplicateFields[$module])) throw new InvalidArgumentException('Unsupported Zoho CRM module.');
    $token = $accessToken ?? zoho_crm_access_token();
    $body = json_encode([
        'data' => [$record],
        'duplicate_check_fields' => $duplicateFields[$module],
        'trigger' => ['workflow'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) throw new RuntimeException('Could not encode Zoho CRM data.');

    $response = zoho_crm_request('https://www.zohoapis.eu/crm/v8/'.$module.'/upsert', [
        'headers' => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Zoho-oauthtoken '.$token,
        ],
        'post_fields' => $body,
    ]);
    if ($response['status'] === 401 && $retry) {
        $freshToken = zoho_crm_access_token(true);
        return zoho_crm_upsert($module, $record, false, $freshToken);
    }

    $item = $response['json']['data'][0] ?? null;
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($item) || ($item['status'] ?? '') !== 'success') {
        $reason = is_array($item)
            ? (string)($item['message'] ?? $item['code'] ?? ('HTTP '.$response['status']))
            : (string)($response['json']['message'] ?? $response['json']['code'] ?? ('HTTP '.$response['status']));
        throw new RuntimeException('Zoho CRM sync failed: '.$reason);
    }
    return $item;
}

function zoho_crm_name_fields(string $name): array {
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    if ($name === '') return ['First_Name' => '', 'Last_Name' => 'Customer'];
    $parts = explode(' ', $name);
    if (count($parts) === 1) return ['First_Name' => '', 'Last_Name' => mb_substr($name, 0, 80)];
    $last = (string)array_pop($parts);
    return ['First_Name' => mb_substr(implode(' ', $parts), 0, 80), 'Last_Name' => mb_substr($last, 0, 80)];
}

function zoho_crm_sync_customer(array $customer, bool $direct = false): ?array {
    if (!zoho_crm_enabled()) return null;
    $email = strtolower(trim((string)($customer['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid customer email is required for Zoho CRM sync.');
    $customerId = (int)($customer['id'] ?? 0);
    if (!$direct && $customerId > 0 && function_exists('automation_enqueue')) {
        automation_enqueue('zoho', 'sync_customer', 'customer', $customerId);
        return ['status' => 'queued', 'details' => []];
    }
    $record = zoho_crm_name_fields((string)($customer['name'] ?? 'Customer')) + [
        'Email' => $email,
        'Description' => 'FoxNetwork portal customer #'.(int)($customer['id'] ?? 0),
    ];
    return zoho_crm_upsert('Contacts', array_filter($record, static fn($value) => $value !== ''));
}

function zoho_crm_sync_lead(string $name, string $email, string $subject, string $message, bool $direct = false): ?array {
    if (!zoho_crm_enabled()) return null;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid email is required for Zoho CRM lead sync.');
    if (!$direct && function_exists('automation_enqueue')) {
        $entityId = random_int(1, PHP_INT_MAX);
        automation_enqueue('zoho', 'sync_lead', 'lead', $entityId, compact('name', 'email', 'subject', 'message'));
        return ['status' => 'queued', 'details' => []];
    }
    $record = zoho_crm_name_fields($name) + [
        'Company' => 'Website inquiry',
        'Email' => strtolower(trim($email)),
        'Description' => mb_substr("Subject: ".$subject."\n\n".$message, 0, 32000),
    ];
    return zoho_crm_upsert('Leads', array_filter($record, static fn($value) => $value !== ''));
}

function zoho_crm_result_id(?array $result): string {
    return trim((string)($result['details']['id'] ?? ''));
}

function zoho_crm_contact_for_customer(array $customer): array {
    static $cache = [];
    $cacheKey = strtolower(trim((string)($customer['email'] ?? '')));
    if ($cacheKey !== '' && isset($cache[$cacheKey])) return $cache[$cacheKey];
    $result = zoho_crm_sync_customer($customer, true);
    $id = zoho_crm_result_id($result);
    $lookup = $id === '' ? [] : ['id' => $id];
    if ($cacheKey !== '' && $lookup) $cache[$cacheKey] = $lookup;
    return $lookup;
}

function zoho_crm_deal_stage(string $status): string {
    if (in_array($status, ['paid', 'provisioning', 'active', 'suspended'], true)) {
        return trim((string)setting('zoho_crm_deal_stage_won', 'Closed Won')) ?: 'Closed Won';
    }
    if (in_array($status, ['cancelled', 'terminated', 'failed', 'refunded'], true)) {
        return trim((string)setting('zoho_crm_deal_stage_lost', 'Closed Lost')) ?: 'Closed Lost';
    }
    return trim((string)setting('zoho_crm_deal_stage_open', 'Qualification')) ?: 'Qualification';
}

function zoho_crm_deal_record(string $name, string $status, float $amount, array $customer, string $description, ?string $closedAt = null): array {
    $record = [
        'Deal_Name' => mb_substr($name, 0, 255),
        'Stage' => zoho_crm_deal_stage($status),
        'Amount' => round($amount, 2),
        'Description' => mb_substr($description, 0, 32000),
    ];
    $pipeline = trim((string)setting('zoho_crm_pipeline', ''));
    if ($pipeline !== '') $record['Pipeline'] = $pipeline;
    $contact = zoho_crm_contact_for_customer($customer);
    if ($contact) $record['Contact_Name'] = $contact;
    if ($closedAt !== null && $closedAt !== '' && in_array($status, ['paid', 'active'], true)) {
        $timestamp = strtotime($closedAt);
        if ($timestamp !== false) $record['Closing_Date'] = date('Y-m-d', $timestamp);
    }
    return $record;
}

function zoho_crm_sync_order(int $orderId): ?array {
    if (!zoho_crm_full_sync_enabled() || $orderId <= 0) return null;
    $q = db()->prepare('SELECT o.*,u.name customer_name,u.email FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=? LIMIT 1');
    $q->execute([$orderId]);
    $order = $q->fetch();
    if (!$order) throw new RuntimeException('Order not found for Zoho CRM sync.');

    $itemsQ = db()->prepare('SELECT product_name,unit_price,quantity,config_json FROM order_items WHERE order_id=? ORDER BY id');
    $itemsQ->execute([$orderId]);
    $items = $itemsQ->fetchAll();
    $serviceQ = db()->prepare('SELECT id,name,status,price_monthly,currency,next_due_at,ptero_identifier FROM services WHERE order_id=? ORDER BY id LIMIT 1');
    $serviceQ->execute([$orderId]);
    $service = $serviceQ->fetch() ?: null;
    $invoiceQ = db()->prepare('SELECT invoice_number,status,paid_at,due_at FROM invoices WHERE order_id=? ORDER BY id DESC LIMIT 1');
    $invoiceQ->execute([$orderId]);
    $invoice = $invoiceQ->fetch() ?: null;

    $lines = [
        'Portal order: '.$order['order_number'],
        'Status: '.$order['status'],
        'Customer: '.$order['customer_name'].' <'.$order['email'].'>',
        'Total: '.number_format((float)$order['total'], 2, '.', '').' '.$order['currency'],
    ];
    foreach ($items as $item) {
        $lines[] = 'Item: '.$item['product_name'].' x'.(int)$item['quantity'].' @ '.number_format((float)$item['unit_price'], 2, '.', '').' '.$order['currency'];
    }
    if ($invoice) {
        $lines[] = 'Invoice: '.$invoice['invoice_number'].' ('.$invoice['status'].')';
    }
    if ($service) {
        $lines[] = 'Service #'.$service['id'].': '.$service['name'].' ('.$service['status'].')';
        $lines[] = 'Monthly subscription: '.number_format((float)$service['price_monthly'], 2, '.', '').' '.$service['currency'];
        if (!empty($service['next_due_at'])) $lines[] = 'Next due: '.$service['next_due_at'];
        if (!empty($service['ptero_identifier'])) $lines[] = 'Server identifier: '.$service['ptero_identifier'];
    }
    $status = (string)$order['status'];
    if ($invoice && $invoice['status'] === 'paid') $status = in_array($status, ['active', 'provisioning'], true) ? $status : 'paid';
    $record = zoho_crm_deal_record(
        'FoxNetwork '.$order['order_number'],
        $status,
        (float)$order['total'],
        ['id' => $order['user_id'], 'name' => $order['customer_name'], 'email' => $order['email']],
        implode("\n", $lines),
        (string)($invoice['paid_at'] ?? $order['updated_at'] ?? '')
    );
    return zoho_crm_upsert('Deals', $record);
}

function zoho_crm_sync_service(int $serviceId): ?array {
    if (!zoho_crm_full_sync_enabled() || $serviceId <= 0) return null;
    $q = db()->prepare('SELECT s.*,u.name customer_name,u.email,p.name product_name FROM services s JOIN users u ON u.id=s.user_id LEFT JOIN store_products p ON p.id=s.product_id WHERE s.id=? LIMIT 1');
    $q->execute([$serviceId]);
    $service = $q->fetch();
    if (!$service) throw new RuntimeException('Service not found for Zoho CRM sync.');
    if (!empty($service['order_id'])) return zoho_crm_sync_order((int)$service['order_id']);

    $lines = [
        'Portal service #'.$service['id'],
        'Service: '.$service['name'],
        'Product: '.($service['product_name'] ?: 'Imported/manual service'),
        'Status: '.$service['status'],
        'Monthly subscription: '.number_format((float)$service['price_monthly'], 2, '.', '').' '.$service['currency'],
    ];
    if (!empty($service['next_due_at'])) $lines[] = 'Next due: '.$service['next_due_at'];
    if (!empty($service['ptero_identifier'])) $lines[] = 'Server identifier: '.$service['ptero_identifier'];
    $record = zoho_crm_deal_record(
        'FoxNetwork Service #'.$service['id'],
        (string)$service['status'],
        0.0,
        ['id' => $service['user_id'], 'name' => $service['customer_name'], 'email' => $service['email']],
        implode("\n", $lines),
        (string)($service['created_at'] ?? '')
    );
    return zoho_crm_upsert('Deals', $record);
}

function zoho_crm_sync_invoice(int $invoiceId): ?array {
    if (!zoho_crm_full_sync_enabled() || $invoiceId <= 0) return null;
    $q = db()->prepare('SELECT i.*,u.name customer_name,u.email FROM invoices i JOIN users u ON u.id=i.user_id WHERE i.id=? LIMIT 1');
    $q->execute([$invoiceId]);
    $invoice = $q->fetch();
    if (!$invoice || $invoice['status'] !== 'paid') return null;
    if (!empty($invoice['order_id'])) return zoho_crm_sync_order((int)$invoice['order_id']);

    $lines = [
        'Paid invoice: '.$invoice['invoice_number'],
        'Customer: '.$invoice['customer_name'].' <'.$invoice['email'].'>',
        'Revenue: '.number_format((float)$invoice['total'], 2, '.', '').' '.$invoice['currency'],
        'Paid at: '.($invoice['paid_at'] ?: 'unknown'),
    ];
    if (!empty($invoice['service_id'])) {
        $serviceQ = db()->prepare('SELECT id,name,status,next_due_at FROM services WHERE id=? LIMIT 1');
        $serviceQ->execute([(int)$invoice['service_id']]);
        $service = $serviceQ->fetch();
        if ($service) {
            $lines[] = 'Service #'.$service['id'].': '.$service['name'].' ('.$service['status'].')';
            if (!empty($service['next_due_at'])) $lines[] = 'Next due: '.$service['next_due_at'];
        }
    }
    $record = zoho_crm_deal_record(
        'FoxNetwork Invoice '.$invoice['invoice_number'],
        'paid',
        (float)$invoice['total'],
        ['id' => $invoice['user_id'], 'name' => $invoice['customer_name'], 'email' => $invoice['email']],
        implode("\n", $lines),
        (string)($invoice['paid_at'] ?? '')
    );
    return zoho_crm_upsert('Deals', $record);
}

function zoho_crm_case_status(string $status): string {
    if ($status === 'closed') return trim((string)setting('zoho_crm_case_status_closed', 'Closed')) ?: 'Closed';
    if (in_array($status, ['awaiting_customer', 'answered'], true)) return trim((string)setting('zoho_crm_case_status_hold', 'On Hold')) ?: 'On Hold';
    return trim((string)setting('zoho_crm_case_status_open', 'New')) ?: 'New';
}

function zoho_crm_sync_ticket(int $ticketId): ?array {
    if (!zoho_crm_full_sync_enabled() || $ticketId <= 0) return null;
    $q = db()->prepare('SELECT t.*,u.name customer_name,u.email,s.name service_name FROM support_tickets t JOIN users u ON u.id=t.user_id LEFT JOIN services s ON s.id=t.service_id WHERE t.id=? LIMIT 1');
    $q->execute([$ticketId]);
    $ticket = $q->fetch();
    if (!$ticket) throw new RuntimeException('Support ticket not found for Zoho CRM sync.');
    $messagesQ = db()->prepare('SELECT m.message,m.is_staff,m.created_at FROM support_messages m WHERE m.ticket_id=? AND m.is_internal=0 ORDER BY m.id');
    $messagesQ->execute([$ticketId]);
    $lines = [
        'Portal ticket #'.$ticket['id'],
        'Customer: '.$ticket['customer_name'].' <'.$ticket['email'].'>',
        'Category: '.$ticket['category'],
        'Priority: '.$ticket['priority'],
    ];
    if (!empty($ticket['service_name'])) $lines[] = 'Service: '.$ticket['service_name'];
    foreach ($messagesQ->fetchAll() as $message) {
        $lines[] = '';
        $lines[] = ($message['is_staff'] ? 'Staff' : 'Customer').' @ '.$message['created_at'].':';
        $lines[] = (string)$message['message'];
    }
    $priority = in_array($ticket['priority'], ['urgent', 'high'], true) ? 'High' : ($ticket['priority'] === 'low' ? 'Low' : 'Medium');
    $record = [
        'Subject' => mb_substr('[FoxNetwork #'.$ticket['id'].'] '.$ticket['subject'], 0, 255),
        'Case_Origin' => trim((string)setting('zoho_crm_case_origin', 'Web')) ?: 'Web',
        'Status' => zoho_crm_case_status((string)$ticket['status']),
        'Priority' => $priority,
        'Description' => mb_substr(implode("\n", $lines), 0, 32000),
    ];
    $contact = zoho_crm_contact_for_customer(['id' => $ticket['user_id'], 'name' => $ticket['customer_name'], 'email' => $ticket['email']]);
    if ($contact) $record['Contact_Name'] = $contact;
    return zoho_crm_upsert('Cases', $record);
}

function zoho_crm_try_sync_order(int $orderId): void {
    if (!zoho_crm_full_sync_enabled()) return;
    try { automation_enqueue('zoho', 'sync_order', 'order', $orderId); } catch (Throwable $e) { error_log('FoxNetwork Zoho CRM order queue failed for order '.$orderId.': '.$e->getMessage()); }
}

function zoho_crm_try_sync_service(int $serviceId): void {
    if (!zoho_crm_full_sync_enabled()) return;
    try { automation_enqueue('zoho', 'sync_service', 'service', $serviceId); } catch (Throwable $e) { error_log('FoxNetwork Zoho CRM service queue failed for service '.$serviceId.': '.$e->getMessage()); }
}

function zoho_crm_try_sync_invoice(int $invoiceId): void {
    if (!zoho_crm_full_sync_enabled()) return;
    try { automation_enqueue('zoho', 'sync_invoice', 'invoice', $invoiceId); } catch (Throwable $e) { error_log('FoxNetwork Zoho CRM invoice queue failed for invoice '.$invoiceId.': '.$e->getMessage()); }
}

function zoho_crm_try_sync_ticket(int $ticketId): void {
    if (!zoho_crm_full_sync_enabled()) return;
    try { automation_enqueue('zoho', 'sync_ticket', 'ticket', $ticketId); } catch (Throwable $e) { error_log('FoxNetwork Zoho CRM ticket queue failed for ticket '.$ticketId.': '.$e->getMessage()); }
}
