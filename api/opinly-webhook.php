<?php
declare(strict_types=1);

/**
 * Opinly publish webhook (Svix-signed) for cache invalidation.
 * Register this URL in Opinly → Settings → Developers and subscribe to
 * content.routes-changed. Secret comes from OPINLY_WEBHOOK_SIGNING_SECRET.
 */

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/opinly.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

function opinly_webhook_fail(int $status, string $message): never
{
    http_response_code($status);
    error_log('Opinly webhook rejected: ' . $message);
    echo $status === 400 ? 'Invalid signature' : $message;
    exit;
}

function opinly_webhook_header(string ...$names): string
{
    foreach ($names as $name) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!empty($_SERVER[$key])) return trim((string)$_SERVER[$key]);
    }
    return '';
}

/** Standard-Webhooks / Svix signature check lives in app/opinly.php so it stays testable. */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

$secret = opinly_env('OPINLY_WEBHOOK_SIGNING_SECRET');
if ($secret === '') {
    opinly_webhook_fail(500, 'OPINLY_WEBHOOK_SIGNING_SECRET is not configured.');
}

$payload = (string)file_get_contents('php://input');
if ($payload === '' || strlen($payload) > 1048576) {
    opinly_webhook_fail(400, 'empty or oversized body');
}

$signatureValid = opinly_verify_svix(
    $secret,
    $payload,
    opinly_webhook_header('svix-id', 'webhook-id'),
    opinly_webhook_header('svix-timestamp', 'webhook-timestamp'),
    opinly_webhook_header('svix-signature', 'webhook-signature')
);

if (!$signatureValid) {
    opinly_webhook_fail(400, 'signature verification failed');
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    opinly_webhook_fail(400, 'payload is not valid JSON');
}

if ((string)($event['type'] ?? '') !== 'content.routes-changed') {
    http_response_code(200);
    exit('ok');
}

// This app renders every blog page per request, so the only cache to bust is
// the stored API responses. Dropping them makes the next request refetch.
$purged = opinly_purge_cache();

$changedPaths = [];
foreach ((array)($event['data']['changed'] ?? []) as $change) {
    if (!is_array($change)) continue;
    $type = (string)($change['type'] ?? '');
    $slug = trim((string)($change['slug'] ?? ''));

    $path = match ($type) {
        'post' => $slug !== '' ? opinly_post_path($slug) : null,
        'category' => $slug !== '' ? opinly_category_path($slug) : null,
        'author' => $slug !== '' ? opinly_author_path($slug) : null,
        'tag' => opinly_blog_path(),
        'home' => opinly_blog_path(),
        default => null,
    };
    if ($path !== null) $changedPaths[$path] = true;
    if ($type === 'home') {
        $changedPaths['/blog-sitemap.php'] = true;
        $changedPaths['/blog/rss.xml'] = true;
    }
}

error_log(sprintf(
    'Opinly webhook: purged %d cached responses for %d changed route(s): %s',
    $purged,
    count($changedPaths),
    implode(', ', array_keys($changedPaths)) ?: 'none'
));

http_response_code(200);
echo 'ok';
