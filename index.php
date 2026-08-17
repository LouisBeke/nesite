<?php
declare(strict_types=1);

$homepage = __DIR__ . '/index.html';
if (!is_file($homepage)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Homepage file not found.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff', true);
header('X-Frame-Options: SAMEORIGIN', true);
header('X-XSS-Protection: 0', true);
header('Referrer-Policy: strict-origin-when-cross-origin', true);
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=(), interest-cohort=()', true);
header('Cross-Origin-Opener-Policy: same-origin', true);
header('Cross-Origin-Resource-Policy: same-origin', true);
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https: data:; style-src 'self' 'unsafe-inline' https: data:; img-src 'self' data: https:; font-src 'self' data: https:; connect-src 'self' https:; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self' https:; upgrade-insecure-requests", false);
$lastModified = filemtime($homepage) ?: time();
$etag = '"' . md5_file($homepage) . '"';

header('Cache-Control: public, max-age=300, must-revalidate');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
header('ETag: ' . $etag);

$clientEtag = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
$clientModified = strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
if ($clientEtag === $etag || $clientModified >= $lastModified) {
    http_response_code(304);
    exit;
}

readfile($homepage);
