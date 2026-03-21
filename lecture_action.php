<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lectures_helper.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('lectures.php');
}

verify_csrf_or_abort();

$pdo = db();
ensure_lecture_system_tables($pdo);

$action = (string) ($_POST['action'] ?? '');
$lectureId = (int) ($_POST['lecture_id'] ?? 0);
$redirectTo = trim((string) ($_POST['redirect_to'] ?? 'lectures.php'));
$userId = (int) current_user()['id'];

if ($lectureId <= 0) {
    set_flash('danger', 'Invalid lecture selected.');
    redirect($redirectTo);
}

$checkStmt = $pdo->prepare('SELECT id FROM lectures WHERE id = ? LIMIT 1');
$checkStmt->execute([$lectureId]);
if (!$checkStmt->fetch()) {
    set_flash('danger', 'Lecture not found.');
    redirect($redirectTo);
}

if ($action === 'toggle_like') {
    $stmt = $pdo->prepare('SELECT id FROM lecture_likes WHERE lecture_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$lectureId, $userId]);
    $liked = $stmt->fetch();

    if ($liked) {
        $deleteStmt = $pdo->prepare('DELETE FROM lecture_likes WHERE lecture_id = ? AND user_id = ?');
        $deleteStmt->execute([$lectureId, $userId]);
        set_flash('info', 'Like removed from lecture.');
    } else {
        $insertStmt = $pdo->prepare('INSERT INTO lecture_likes (lecture_id, user_id) VALUES (?, ?)');
        $insertStmt->execute([$lectureId, $userId]);
        set_flash('success', 'Lecture liked.');
    }

    redirect($redirectTo);
}

if ($action === 'add_comment') {
    $message = trim((string) ($_POST['message'] ?? ''));
    if ($message === '') {
        set_flash('danger', 'Comment cannot be empty.');
        redirect($redirectTo);
    }

    if (mb_strlen($message) > 1000) {
        set_flash('danger', 'Comment is too long. Max 1000 characters.');
        redirect($redirectTo);
    }

    $stmt = $pdo->prepare('INSERT INTO lecture_comments (lecture_id, user_id, message) VALUES (?, ?, ?)');
    $stmt->execute([$lectureId, $userId, $message]);
    set_flash('success', 'Comment posted.');
    redirect($redirectTo . '#lecture-comments');
}

set_flash('danger', 'Invalid lecture action.');
redirect($redirectTo);
