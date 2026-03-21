<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tests_helper.php';
require_login();
verify_csrf_or_abort();

$pdo = db();
ensure_test_system_tables($pdo);

$action = $_POST['action'] ?? '';
$testId = (int) ($_POST['test_id'] ?? 0);
$redirectTo = 'test_forum.php?id=' . $testId;

if ($testId <= 0) {
    set_flash('danger', 'Invalid test.');
    redirect('tests.php');
}

$testCheck = $pdo->prepare('SELECT id FROM test_series WHERE id = ? AND status = "published" LIMIT 1');
$testCheck->execute([$testId]);
if (!$testCheck->fetch()) {
    set_flash('danger', 'Test not found.');
    redirect('tests.php');
}

$userId = (int) current_user()['id'];

if ($action === 'rate') {
    $rating = (int) ($_POST['rating'] ?? 0);
    $comment = trim((string) ($_POST['comment'] ?? ''));
    if ($rating < 1 || $rating > 5) {
        set_flash('danger', 'Rating must be between 1 and 5.');
        redirect($redirectTo);
    }
    if (strlen($comment) > 500) {
        $comment = substr($comment, 0, 500);
    }

    $stmt = $pdo->prepare('INSERT INTO test_reviews (test_id, user_id, rating, comment)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), updated_at = CURRENT_TIMESTAMP');
    $stmt->execute([$testId, $userId, $rating, $comment !== '' ? $comment : null]);
    set_flash('success', 'Test rating saved.');
    redirect($redirectTo);
}

if ($action === 'comment') {
    $message = trim((string) ($_POST['message'] ?? ''));
    if ($message === '') {
        set_flash('danger', 'Comment cannot be empty.');
        redirect($redirectTo);
    }
    if (strlen($message) > 2000) {
        $message = substr($message, 0, 2000);
    }

    $stmt = $pdo->prepare('INSERT INTO test_discussions (test_id, user_id, message) VALUES (?, ?, ?)');
    $stmt->execute([$testId, $userId, $message]);
    set_flash('success', 'Comment posted.');
    redirect($redirectTo);
}

set_flash('danger', 'Unsupported action.');
redirect($redirectTo);
