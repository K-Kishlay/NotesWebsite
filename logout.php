<?php
require_once __DIR__ . '/includes/auth.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}
session_destroy();
session_start();
session_regenerate_id(true);
set_flash('success', 'Logged out successfully.');
redirect('login.php');
