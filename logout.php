<?php
session_name('CREATOR_SESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => (file_exists(__DIR__ . '/config.local.php') ? (require __DIR__ . '/config.local.php')['session_path'] ?? '/' : '/'),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
session_unset();
session_destroy();

header("Location: auth.php");
exit;