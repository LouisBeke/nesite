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
