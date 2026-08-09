<?php
// Copy this file to config.local.php for local/shared-hosting deployments.
// config.local.php is ignored by Git. Production may instead use the FOX_* environment
// variables documented in config.php.
return [
    'app_name' => 'FoxNetwork',
    'app_url' => 'https://foxnetwork.be',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'portal',
        'user' => 'portal',
        'pass' => 'CHANGE_ME',
    ],
    'pterodactyl' => [
        'url' => 'https://panel.example.com',
        // Admin > Application API: Read & Write Users + Servers.
        'application_key' => 'CHANGE_ME',
    ],
    'mollie' => [
        'api_key' => 'test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'webhook_url' => 'https://foxnetwork.be/mollie-webhook.php',
    ],
];
