<?php
declare(strict_types=1);

/**
 * Moneybird sales invoice integration for revenue reporting and invoice sync.
 * Reads sales invoices for reporting and can push portal invoices as sales invoices.
 */

const MONEYBIRD_API_BASE = 'https://moneybird.com/api/v2';
const MONEYBIRD_CACHE_TTL = 600;
const MONEYBIRD_STALE_TTL = 86400;
const MONEYBIRD_MAX_PAGES = 10;
const MONEYBIRD_PER_PAGE = 100;

/** States Moneybird treats as issued documents; drafts are not revenue. */
const MONEYBIRD_ISSUED_STATES = ['open', 'scheduled', 'pending_payment', 'reminded', 'late', 'paid'];

function moneybird_setting(string $key, string $default = ''): string
{
    $stored = trim((string)setting('moneybird_' . $key, ''));
    if ($stored === '' || $stored === '__EMPTY__') {
        $env = getenv('MONEYBIRD_' . strtoupper($key));
        return is_string($env) && trim($env) !== '' ? trim($env) : $default;
    }
    if (str_starts_with($stored, 'enc:')) {
        $plain = (string)(dec(substr($stored, 4)) ?? '');
        return $plain !== '' ? $plain : $default;
    }
    return $stored;
}

function moneybird_api_token(): string
{
    return moneybird_setting('api_token');
}

function moneybird_administration_id(): string
{
    return preg_replace('/\D+/', '', moneybird_setting('administration_id')) ?? '';
}

function moneybird_enabled(): bool
{
    return moneybird_setting('enabled', '0') === '1' && moneybird_api_token() !== '';
}

function moneybird_configured(): bool
{
    return moneybird_api_token() !== '' && moneybird_administration_id() !== '';
}

/* ---------------------------------------------------------------- cache --- */

function moneybird_cache_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'foxnetwork-moneybird-cache';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    return $dir;
}

function moneybird_cache_get(string $key, int $ttl): ?array
{
    $file = moneybird_cache_dir() . DIRECTORY_SEPARATOR . $key . '.json';
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return null;
    $entry = json_decode($raw, true);
    if (!is_array($entry) || !isset($entry['stored_at'])) return null;
    $age = time() - (int)$entry['stored_at'];
    if ($age > MONEYBIRD_STALE_TTL) return null;
    $entry['stale'] = $age > $ttl;
    return $entry;
}

function moneybird_cache_set(string $key, array $payload): void
{
    @file_put_contents(
        moneybird_cache_dir() . DIRECTORY_SEPARATOR . $key . '.json',
        json_encode(['stored_at' => time(), 'payload' => $payload], JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function moneybird_purge_cache(): int
{
    $removed = 0;
    foreach ((array)glob(moneybird_cache_dir() . DIRECTORY_SEPARATOR . '*.json') as $file) {
        if (is_string($file) && @unlink($file)) $removed++;
    }
    return $removed;
}

/* --------------------------------------------------------------- client --- */

/**
 * @param bool $scoped Prefix the path with the configured administration id.
 * @throws RuntimeException on transport or non-2xx responses.
 */
function moneybird_api(string $path, array $query = [], bool $scoped = true): array
{
    return moneybird_request($path, 'GET', null, $query, $scoped);
}

/**
 * @throws RuntimeException on transport or non-2xx responses.
 */
function moneybird_request(string $path, string $method = 'GET', ?array $body = null, array $query = [], bool $scoped = true): array
{
    $token = moneybird_api_token();
    if ($token === '') throw new RuntimeException('Moneybird API token is not configured.');

    $prefix = '';
    if ($scoped) {
        $administrationId = moneybird_administration_id();
        if ($administrationId === '') throw new RuntimeException('Moneybird administration ID is not configured.');
        $prefix = '/' . $administrationId;
    }

    $url = MONEYBIRD_API_BASE . $prefix . '/' . ltrim($path, '/');
    if ($query) $url .= '?' . http_build_query($query);

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'User-Agent: FoxNetwork-Portal/1.0',
    ];
    if ($body !== null) $headers[] = 'Content-Type: application/json';

    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Could not initialize the Moneybird request.');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        throw new RuntimeException('Could not contact Moneybird: ' . ($error ?: 'cURL error ' . $errno));
    }

    $json = trim((string)$raw) === '' ? [] : json_decode((string)$raw, true);

    if ($code === 401) throw new RuntimeException('Moneybird rejected the API token (401). Check the token and its administration access.');
    if ($code === 404) throw new RuntimeException('Moneybird returned 404. Check the administration ID.');
    if ($code === 429) throw new RuntimeException('Moneybird rate limit reached (429). Try again in a few minutes.');
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Moneybird API HTTP ' . $code . moneybird_error_detail($json));
    }

    return is_array($json) ? $json : [];
}

/** Moneybird returns validation errors as a field => messages map. */
function moneybird_error_detail($json): string
{
    if (!is_array($json)) return '';
    $error = $json['error'] ?? $json['message'] ?? null;
    if (is_string($error) && trim($error) !== '') return ': ' . trim($error);

    $parts = [];
    foreach ((is_array($error) ? $error : $json) as $field => $messages) {
        if (is_array($messages)) {
            $parts[] = $field . ': ' . implode(', ', array_map('strval', $messages));
        } elseif (is_string($messages) && !is_numeric($field)) {
            $parts[] = $field . ': ' . $messages;
        }
    }
    return $parts ? ': ' . implode('; ', array_slice($parts, 0, 5)) : '';
}

/** Administrations the token can access; used to discover the administration ID. */
function moneybird_administrations(): array
{
    $result = moneybird_api('administrations.json', [], false);
    return array_values(array_filter($result, 'is_array'));
}

function moneybird_valid_period(string $period): string
{
    $allowed = ['this_month', 'prev_month', 'this_quarter', 'prev_quarter', 'this_year', 'prev_year'];
    return in_array($period, $allowed, true) ? $period : 'this_year';
}

/**
 * Fetches sales invoices for a period, following pagination up to MONEYBIRD_MAX_PAGES.
 *
 * @return array{ok:bool,invoices:array,error:string,stale:bool,truncated:bool}
 */
function moneybird_sales_invoices(string $period = 'this_year', bool $refresh = false): array
{
    $period = moneybird_valid_period($period);
    $cacheKey = sha1('sales_invoices|' . moneybird_administration_id() . '|' . $period);
    $cached = $refresh ? null : moneybird_cache_get($cacheKey, MONEYBIRD_CACHE_TTL);

    if ($cached !== null && empty($cached['stale'])) {
        return ['ok' => true, 'invoices' => (array)$cached['payload'], 'error' => '', 'stale' => false, 'truncated' => false];
    }

    $invoices = [];
    $truncated = false;

    try {
        for ($page = 1; $page <= MONEYBIRD_MAX_PAGES; $page++) {
            $batch = moneybird_api('sales_invoices.json', [
                'filter' => 'period:' . $period,
                'page' => $page,
                'per_page' => MONEYBIRD_PER_PAGE,
            ]);
            $batch = array_values(array_filter($batch, 'is_array'));
            $invoices = array_merge($invoices, $batch);
            if (count($batch) < MONEYBIRD_PER_PAGE) break;
            if ($page === MONEYBIRD_MAX_PAGES) $truncated = true;
        }
    } catch (Throwable $e) {
        if ($cached !== null) {
            return ['ok' => true, 'invoices' => (array)$cached['payload'], 'error' => '', 'stale' => true, 'truncated' => false];
        }
        return ['ok' => false, 'invoices' => [], 'error' => $e->getMessage(), 'stale' => false, 'truncated' => false];
    }

    moneybird_cache_set($cacheKey, $invoices);
    return ['ok' => true, 'invoices' => $invoices, 'error' => '', 'stale' => false, 'truncated' => $truncated];
}

function moneybird_invoice_contact_name(array $invoice): string
{
    $contact = is_array($invoice['contact'] ?? null) ? $invoice['contact'] : [];
    $company = trim((string)($contact['company_name'] ?? ''));
    if ($company !== '') return $company;
    $name = trim(trim((string)($contact['firstname'] ?? '')) . ' ' . trim((string)($contact['lastname'] ?? '')));
    return $name !== '' ? $name : 'Unknown contact';
}

/**
 * Aggregates invoices into the figures shown on the revenue page.
 * Amounts stay per currency because Moneybird does not convert them.
 */
function moneybird_revenue_summary(array $invoices): array
{
    $summary = [
        'currency' => '',
        'invoiced_excl' => 0.0,
        'invoiced_incl' => 0.0,
        'paid' => 0.0,
        'outstanding' => 0.0,
        'overdue' => 0.0,
        'draft_incl' => 0.0,
        'count' => 0,
        'draft_count' => 0,
        'states' => [],
        'months' => [],
        'currencies' => [],
    ];

    foreach ($invoices as $invoice) {
        if (!is_array($invoice)) continue;

        $state = strtolower(trim((string)($invoice['state'] ?? '')));
        $currency = strtoupper(trim((string)($invoice['currency'] ?? 'EUR'))) ?: 'EUR';
        $incl = (float)($invoice['total_price_incl_tax'] ?? 0);
        $excl = (float)($invoice['total_price_excl_tax'] ?? 0);
        $paid = (float)($invoice['total_paid'] ?? 0);
        $unpaid = (float)($invoice['total_unpaid'] ?? 0);

        $summary['states'][$state] = ($summary['states'][$state] ?? 0) + 1;
        $summary['currencies'][$currency] = ($summary['currencies'][$currency] ?? 0) + $incl;

        if ($state === 'draft') {
            $summary['draft_count']++;
            $summary['draft_incl'] += $incl;
            continue;
        }
        if (!in_array($state, MONEYBIRD_ISSUED_STATES, true)) continue;

        $summary['count']++;
        $summary['invoiced_excl'] += $excl;
        $summary['invoiced_incl'] += $incl;
        $summary['paid'] += $paid;

        if ($state !== 'paid') $summary['outstanding'] += $unpaid;
        if ($state === 'late') $summary['overdue'] += $unpaid;

        $date = trim((string)($invoice['invoice_date'] ?? ''));
        if ($date !== '') {
            $month = substr($date, 0, 7);
            if (!isset($summary['months'][$month])) $summary['months'][$month] = ['excl' => 0.0, 'incl' => 0.0, 'count' => 0];
            $summary['months'][$month]['excl'] += $excl;
            $summary['months'][$month]['incl'] += $incl;
            $summary['months'][$month]['count']++;
        }
    }

    if ($summary['currencies']) {
        arsort($summary['currencies']);
        $summary['currency'] = (string)array_key_first($summary['currencies']);
    } else {
        $summary['currency'] = 'EUR';
    }

    ksort($summary['months']);
    return $summary;
}

function moneybird_money(float $amount, string $currency = 'EUR'): string
{
    $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];
    $symbol = $symbols[strtoupper($currency)] ?? (strtoupper($currency) . ' ');
    return $symbol . number_format($amount, 2, ',', '.');
}

function moneybird_state_label(string $state): string
{
    return ucwords(str_replace('_', ' ', $state ?: 'unknown'));
}

function moneybird_period_label(string $period): string
{
    return [
        'this_month' => 'This month',
        'prev_month' => 'Previous month',
        'this_quarter' => 'This quarter',
        'prev_quarter' => 'Previous quarter',
        'this_year' => 'This year',
        'prev_year' => 'Previous year',
    ][$period] ?? 'This year';
}

/* ----------------------------------------------------- portal → moneybird --- */

/** off = never push, paid = push once paid, created = push as soon as the invoice exists. */
function moneybird_sync_mode(): string
{
    $mode = moneybird_setting('sync_mode', 'off');
    return in_array($mode, ['off', 'paid', 'created'], true) ? $mode : 'off';
}

function moneybird_send_method(): string
{
    $method = moneybird_setting('send_method', 'none');
    return in_array($method, ['none', 'Manual', 'Email'], true) ? $method : 'none';
}

function moneybird_prices_incl_tax(): bool
{
    return moneybird_setting('prices_incl_tax', '1') === '1';
}

function moneybird_tax_rates(bool $refresh = false): array
{
    $cacheKey = sha1('tax_rates|' . moneybird_administration_id());
    $cached = $refresh ? null : moneybird_cache_get($cacheKey, 86400);
    if ($cached !== null && empty($cached['stale'])) return (array)$cached['payload'];

    $rates = moneybird_api('tax_rates.json', ['filter' => 'tax_rate_type:sales_invoice']);
    $rates = array_values(array_filter($rates, 'is_array'));
    moneybird_cache_set($cacheKey, $rates);
    return $rates;
}

/** Configured rate, else the highest active sales rate, else none (Moneybird default). */
function moneybird_tax_rate_id(): string
{
    $configured = trim(moneybird_setting('tax_rate_id'));
    if ($configured !== '') return $configured;

    try {
        $best = null;
        foreach (moneybird_tax_rates() as $rate) {
            if (!empty($rate['active']) || !array_key_exists('active', $rate)) {
                $percentage = (float)($rate['percentage'] ?? 0);
                if ($best === null || $percentage > (float)($best['percentage'] ?? 0)) $best = $rate;
            }
        }
        return $best ? (string)($best['id'] ?? '') : '';
    } catch (Throwable $e) {
        error_log('Moneybird tax rate lookup failed: ' . $e->getMessage());
        return '';
    }
}

function moneybird_contact_payload(array $user): array
{
    $name = trim((string)($user['name'] ?? ''));
    $parts = preg_split('/\s+/', $name) ?: [];
    $firstName = (string)array_shift($parts);
    $lastName = trim(implode(' ', $parts));
    if ($lastName === '') $lastName = $firstName !== '' ? $firstName : 'Customer';

    $street = trim(trim((string)($user['street'] ?? '')) . ' ' . trim((string)($user['house_number'] ?? '')));
    $country = strtoupper(trim((string)($user['country_code'] ?? '')));

    return ['contact' => array_filter([
        'company_name' => trim((string)($user['company_name'] ?? '')),
        'firstname' => $firstName,
        'lastname' => $lastName,
        'address1' => $street,
        'zipcode' => trim((string)($user['postal_code'] ?? '')),
        'city' => trim((string)($user['city'] ?? '')),
        'country' => strlen($country) === 2 ? $country : '',
        'phone' => trim((string)($user['phone'] ?? '')),
        'email' => trim((string)($user['email'] ?? '')),
        'send_invoices_to_email' => trim((string)($user['email'] ?? '')),
    ], static fn($v) => $v !== '' && $v !== null)];
}

/** Reuses the stored contact id, then an email match, and only then creates one. */
function moneybird_find_or_create_contact(array $user): string
{
    $stored = trim((string)($user['moneybird_contact_id'] ?? ''));
    if ($stored !== '') return $stored;

    $email = trim((string)($user['email'] ?? ''));
    $contactId = '';

    if ($email !== '') {
        try {
            foreach (moneybird_api('contacts.json', ['query' => $email]) as $contact) {
                if (!is_array($contact)) continue;
                $candidate = strtolower(trim((string)($contact['email'] ?? '')));
                if ($candidate !== '' && $candidate === strtolower($email)) {
                    $contactId = (string)($contact['id'] ?? '');
                    break;
                }
            }
        } catch (Throwable $e) {
            error_log('Moneybird contact lookup failed: ' . $e->getMessage());
        }
    }

    if ($contactId === '') {
        $created = moneybird_request('contacts.json', 'POST', moneybird_contact_payload($user));
        $contactId = (string)($created['id'] ?? '');
        if ($contactId === '') throw new RuntimeException('Moneybird did not return a contact ID.');
    }

    if (!empty($user['id'])) {
        try {
            db()->prepare('UPDATE users SET moneybird_contact_id=? WHERE id=?')->execute([$contactId, (int)$user['id']]);
        } catch (Throwable $e) {
            error_log('Storing Moneybird contact id failed: ' . $e->getMessage());
        }
    }

    return $contactId;
}

/**
 * Builds the sales_invoice payload. Portal prices already include VAT, so the
 * Moneybird total matches what the customer was actually charged.
 */
function moneybird_invoice_payload(array $invoice, array $items, string $contactId, string $taxRateId, bool $pricesInclTax = true): array
{
    $lines = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $quantity = max(1, (int)($item['quantity'] ?? 1));
        $description = trim((string)($item['description'] ?? '')) ?: 'Service';
        $lines[] = array_filter([
            'description' => $description,
            'price' => number_format((float)($item['amount'] ?? 0), 2, '.', ''),
            'amount' => (string)$quantity,
            'tax_rate_id' => $taxRateId !== '' ? $taxRateId : null,
        ], static fn($v) => $v !== null);
    }

    if (!$lines) {
        $lines[] = array_filter([
            'description' => 'Invoice ' . (string)($invoice['invoice_number'] ?? ''),
            'price' => number_format((float)($invoice['total'] ?? 0), 2, '.', ''),
            'amount' => '1',
            'tax_rate_id' => $taxRateId !== '' ? $taxRateId : null,
        ], static fn($v) => $v !== null);
    }

    $invoiceDate = substr((string)($invoice['created_at'] ?? ''), 0, 10) ?: date('Y-m-d');
    $dueDate = substr((string)($invoice['due_at'] ?? ''), 0, 10) ?: $invoiceDate;

    return ['sales_invoice' => array_filter([
        'contact_id' => $contactId,
        'reference' => (string)($invoice['invoice_number'] ?? ''),
        'invoice_date' => $invoiceDate,
        'due_date' => $dueDate,
        'currency' => strtoupper((string)($invoice['currency'] ?? 'EUR')) ?: 'EUR',
        'prices_are_incl_tax' => $pricesInclTax,
        'details_attributes' => $lines,
    ], static fn($v) => $v !== '' && $v !== null)];
}

/**
 * Pushes one portal invoice to Moneybird. Idempotent: the row is claimed first so
 * concurrent callers cannot create the same sales invoice twice.
 *
 * @return array{ok:bool,status:string,moneybird_id:string,error:string}
 */
function moneybird_push_invoice(int $invoiceId, bool $force = false): array
{
    $out = static fn(bool $ok, string $status, string $id = '', string $error = ''): array
        => ['ok' => $ok, 'status' => $status, 'moneybird_id' => $id, 'error' => $error];

    if (!moneybird_enabled()) return $out(false, 'disabled', '', 'Moneybird is not enabled.');
    if (!moneybird_configured()) return $out(false, 'unconfigured', '', 'Moneybird is not configured.');

    $q = db()->prepare('SELECT * FROM invoices WHERE id=? LIMIT 1');
    $q->execute([$invoiceId]);
    $invoice = $q->fetch();
    if (!$invoice) return $out(false, 'missing', '', 'Invoice not found.');

    $existing = trim((string)($invoice['moneybird_invoice_id'] ?? ''));
    if ($existing !== '' && !str_starts_with($existing, 'pending:') && !$force) {
        return $out(true, 'already_synced', $existing);
    }
    if (in_array((string)($invoice['status'] ?? ''), ['cancelled'], true)) {
        return $out(false, 'skipped', '', 'Cancelled invoices are not sent to Moneybird.');
    }

    // Claim the row so a second request cannot start the same push.
    $claim = 'pending:' . bin2hex(random_bytes(6));
    $claimQuery = $force
        ? db()->prepare('UPDATE invoices SET moneybird_invoice_id=? WHERE id=?')
        : db()->prepare('UPDATE invoices SET moneybird_invoice_id=? WHERE id=? AND (moneybird_invoice_id IS NULL OR moneybird_invoice_id="" OR moneybird_invoice_id LIKE "pending:%")');
    $claimQuery->execute([$claim, $invoiceId]);
    if ($claimQuery->rowCount() !== 1) {
        return $out(true, 'already_syncing', $existing);
    }

    try {
        $userQuery = db()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $userQuery->execute([(int)$invoice['user_id']]);
        $user = $userQuery->fetch();
        if (!$user) throw new RuntimeException('Invoice customer not found.');

        $itemsQuery = db()->prepare('SELECT description,amount,quantity FROM invoice_items WHERE invoice_id=? ORDER BY id');
        $itemsQuery->execute([$invoiceId]);
        $items = $itemsQuery->fetchAll();

        $contactId = moneybird_find_or_create_contact($user);
        $payload = moneybird_invoice_payload($invoice, $items, $contactId, moneybird_tax_rate_id(), moneybird_prices_incl_tax());

        $created = moneybird_request('sales_invoices.json', 'POST', $payload);
        $moneybirdId = (string)($created['id'] ?? '');
        if ($moneybirdId === '') throw new RuntimeException('Moneybird did not return a sales invoice ID.');

        db()->prepare('UPDATE invoices SET moneybird_invoice_id=?, moneybird_synced_at=NOW(), moneybird_error=NULL WHERE id=?')
            ->execute([$moneybirdId, $invoiceId]);

        $sendMethod = moneybird_send_method();
        $needsSending = $sendMethod !== 'none' || (string)$invoice['status'] === 'paid';
        if ($needsSending) {
            try {
                moneybird_request('sales_invoices/' . rawurlencode($moneybirdId) . '/send_invoice.json', 'PATCH', [
                    'sales_invoice_sending' => ['delivery_method' => $sendMethod === 'none' ? 'Manual' : $sendMethod],
                ]);
            } catch (Throwable $e) {
                error_log('Moneybird send failed for invoice #' . $invoiceId . ': ' . $e->getMessage());
            }
        }

        if ((string)$invoice['status'] === 'paid') {
            moneybird_register_payment($moneybirdId, (float)$invoice['total'], (string)($invoice['paid_at'] ?? ''));
        }

        moneybird_purge_cache();
        return $out(true, 'created', $moneybirdId);
    } catch (Throwable $e) {
        db()->prepare('UPDATE invoices SET moneybird_invoice_id=?, moneybird_error=? WHERE id=?')
            ->execute([$existing !== '' && !str_starts_with($existing, 'pending:') ? $existing : null, substr($e->getMessage(), 0, 255), $invoiceId]);
        error_log('Moneybird push failed for invoice #' . $invoiceId . ': ' . $e->getMessage());
        return $out(false, 'failed', '', $e->getMessage());
    }
}

function moneybird_register_payment(string $moneybirdInvoiceId, float $amount, string $paidAt = ''): void
{
    if ($moneybirdInvoiceId === '' || $amount <= 0) return;
    $date = substr($paidAt, 0, 10) ?: date('Y-m-d');
    try {
        moneybird_request('sales_invoices/' . rawurlencode($moneybirdInvoiceId) . '/payments.json', 'POST', [
            'payment' => [
                'payment_date' => $date,
                'price' => number_format($amount, 2, '.', ''),
            ],
        ]);
    } catch (Throwable $e) {
        error_log('Moneybird payment registration failed for sales invoice ' . $moneybirdInvoiceId . ': ' . $e->getMessage());
    }
}

/** Called from the portal billing flow; never throws into the caller. */
function moneybird_try_sync_invoice(int $invoiceId, string $trigger): void
{
    $mode = moneybird_sync_mode();
    if ($mode === 'off') return;
    if ($mode === 'paid' && $trigger !== 'paid') return;

    try {
        $result = moneybird_push_invoice($invoiceId);
        if (!$result['ok'] && $result['status'] === 'failed') {
            error_log('Moneybird sync (' . $trigger . ') failed for invoice #' . $invoiceId . ': ' . $result['error']);
        }
    } catch (Throwable $e) {
        error_log('Moneybird sync (' . $trigger . ') crashed for invoice #' . $invoiceId . ': ' . $e->getMessage());
    }
}
