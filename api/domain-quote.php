<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    require_user();
    if(!oxxa_enabled())throw new RuntimeException('Domain ordering is disabled.');
    $domain=trim((string)($_GET['domain']??''));
    $parts=oxxa_domain_parts($domain);
    $available=oxxa_domain_available($parts['domain']);
    $quote=$available?oxxa_domain_quote($parts['domain']):null;
    echo json_encode(['ok'=>true,'domain'=>$parts['domain'],'available'=>$available,'quote'=>$quote],JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES);
}
