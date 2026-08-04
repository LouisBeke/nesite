<?php
require __DIR__.'/app/bootstrap.php';
$raw=file_get_contents('php://input')?:'';
$payload=json_decode($raw,true);
if(!is_array($payload)){http_response_code(400);echo 'Invalid JSON';exit;}
try{provisioning_handle_pterodactyl_webhook($payload,$_SERVER);http_response_code(200);echo 'OK';}catch(Throwable $e){provisioning_emit_event('webhook.reject',['error'=>$e->getMessage(),'payload'=>$payload],null,'warning');http_response_code(403);echo 'FORBIDDEN';}
