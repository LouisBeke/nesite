<?php
declare(strict_types=1);

$localConfig = __DIR__.'/config.local.php';
$env = static function (string $key, string $default = ''): string {
    $value = getenv($key);
    return $value === false || $value === '' ? $default : (string)$value;
};

$config = [
    'app_name' => $env('FOX_APP_NAME', 'FoxNetwork'),
    'app_url' => $env('FOX_APP_URL', 'https://foxnetwork.be'),
    'db' => [
        'host' => $env('FOX_DB_HOST', '127.0.0.1'),
        'port' => (int)$env('FOX_DB_PORT', '3306'),
        'name' => $env('FOX_DB_NAME', 'portal'),
        'user' => $env('FOX_DB_USER', 'portal'),
        'pass' => $env('FOX_DB_PASSWORD'),
    ],
    'pterodactyl' => [
        'url' => $env('FOX_PTERODACTYL_URL'),
        'application_key' => $env('FOX_PTERODACTYL_APPLICATION_KEY'),
    ],
    'mollie' => [
        'api_key' => $env('FOX_MOLLIE_API_KEY'),
        'webhook_url' => $env('FOX_MOLLIE_WEBHOOK_URL', rtrim($env('FOX_APP_URL', 'https://foxnetwork.be'), '/').'/mollie-webhook.php'),
    ],
    'microsoft' => [
        'tenant_id' => 'f45fc1d0-4f55-4abc-9517-72916e085bc3',
        'client_id' => '8e61ac8e-851e-4ba6-b70c-a21f34803284',
        'client_secret' => $env('MICROSOFT_CLIENT_SECRET'),
        'mail_from' => 'noreply@foxnetwork.be',
    ],
];

if (is_file($localConfig)) {
    $local = require $localConfig;
    if (!is_array($local)) {
        throw new RuntimeException('config.local.php must return a configuration array.');
    }
    $config = array_replace_recursive($config, $local);
}

$secretConfig = __DIR__.'/config.secrets.php';
if (is_file($secretConfig)) {
    $secrets = require $secretConfig;
    if (!is_array($secrets)) {
        throw new RuntimeException('config.secrets.php must return a configuration array.');
    }
    if (isset($secrets['microsoft']['client_secret']) && isset($config['microsoft']) && is_array($config['microsoft'])) {
        $config['microsoft']['client_secret'] = (string)$secrets['microsoft']['client_secret'];
    }
}

return $config;
