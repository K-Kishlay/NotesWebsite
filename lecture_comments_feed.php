<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lectures_helper.php';
require_login();

$lectureId = (int) ($_GET['lecture_id'] ?? 0);
if ($lectureId <= 0) {
    http_response_code(400);
    exit('Invalid lecture.');
}

$pdo = db();
ensure_lecture_system_tables($pdo);

header('Content-Type: text/html; charset=UTF-8');
render_lecture_comments(fetch_lecture_comments($pdo, $lectureId));
