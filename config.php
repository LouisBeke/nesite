<?php
declare(strict_types=1);

$localConfig = __DIR__.'/config.local.php';
if (is_file($localConfig)) {
    $config = require $localConfig;
    if (!is_array($config)) {
        throw new RuntimeException('config.local.php must return a configuration array.');
    }
    return $config;
}

$env = static function (string $key, string $default = ''): string {
    $value = getenv($key);
    return $value === false || $value === '' ? $default : (string)$value;
};

return [
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
];
