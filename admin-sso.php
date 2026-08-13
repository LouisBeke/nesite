<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
try{header('Location: '.zoho_sso_start_url());exit;}catch(Throwable $e){error_log('Zoho admin SSO start failed: '.$e->getMessage());header('Location: /login.php?sso_error='.rawurlencode($e->getMessage()));exit;}
