<?php
require_once __DIR__ . '/includes/auth.php';

// Clear session data
$_SESSION = [];

// Clear session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
}

session_destroy();

header('Location: ' . SITE_URL . '/');
exit;
