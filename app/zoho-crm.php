<?php
declare(strict_types=1);

function zoho_crm_enabled(): bool {
    return setting('zoho_crm_enabled', '0') === '1';
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
        'scope' => 'ZohoCRM.modules.contacts.ALL,ZohoCRM.modules.leads.ALL',
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
    if (!in_array($module, ['Contacts', 'Leads'], true)) throw new InvalidArgumentException('Unsupported Zoho CRM module.');
    $token = $accessToken ?? zoho_crm_access_token();
    $body = json_encode([
        'data' => [$record],
        'duplicate_check_fields' => ['Email'],
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

function zoho_crm_sync_customer(array $customer): ?array {
    if (!zoho_crm_enabled()) return null;
    $email = strtolower(trim((string)($customer['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid customer email is required for Zoho CRM sync.');
    $record = zoho_crm_name_fields((string)($customer['name'] ?? 'Customer')) + [
        'Email' => $email,
        'Description' => 'FoxNetwork portal customer #'.(int)($customer['id'] ?? 0),
    ];
    return zoho_crm_upsert('Contacts', array_filter($record, static fn($value) => $value !== ''));
}

function zoho_crm_sync_lead(string $name, string $email, string $subject, string $message): ?array {
    if (!zoho_crm_enabled()) return null;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid email is required for Zoho CRM lead sync.');
    $record = zoho_crm_name_fields($name) + [
        'Company' => 'Website inquiry',
        'Email' => strtolower(trim($email)),
        'Description' => mb_substr("Subject: ".$subject."\n\n".$message, 0, 32000),
    ];
    return zoho_crm_upsert('Leads', array_filter($record, static fn($value) => $value !== ''));
}
