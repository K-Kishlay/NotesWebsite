<?php
require_once __DIR__ . '/includes/auth.php';
if (is_logged_in()) {
    redirect('index.php');
}
redirect('login.php');
