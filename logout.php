<?php require __DIR__.'/app/bootstrap.php';security_logout_session();session_destroy();header('Location:/login.php');
