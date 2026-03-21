<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('notes.php');
}
verify_csrf_or_abort();

$pdo = db();
$userId = (int) current_user()['id'];
$noteId = (int) ($_POST['note_id'] ?? 0);

if ($noteId <= 0) {
    set_flash('danger', 'Invalid note.');
    redirect($_SERVER['HTTP_REFERER'] ?? 'notes.php');
}

$existsStmt = $pdo->prepare('SELECT 1 FROM notes WHERE id = ? LIMIT 1');
$existsStmt->execute([$noteId]);
if (!$existsStmt->fetch()) {
    set_flash('danger', 'Note not found.');
    redirect($_SERVER['HTTP_REFERER'] ?? 'notes.php');
}

$checkStmt = $pdo->prepare('SELECT id FROM note_likes WHERE user_id = ? AND note_id = ? LIMIT 1');
$checkStmt->execute([$userId, $noteId]);
$liked = $checkStmt->fetch();

if ($liked) {
    $del = $pdo->prepare('DELETE FROM note_likes WHERE user_id = ? AND note_id = ?');
    $del->execute([$userId, $noteId]);
    set_flash('info', 'Like removed.');
} else {
    $ins = $pdo->prepare('INSERT INTO note_likes (user_id, note_id) VALUES (?, ?)');
    $ins->execute([$userId, $noteId]);
    set_flash('success', 'You liked this note.');
}

redirect($_SERVER['HTTP_REFERER'] ?? 'notes.php');
