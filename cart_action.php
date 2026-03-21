<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('notes.php');
}
verify_csrf_or_abort();

$noteId = (int) ($_POST['note_id'] ?? 0);
$action = $_POST['action'] ?? '';
$userId = (int) current_user()['id'];

if ($noteId <= 0) {
    set_flash('danger', 'Invalid note.');
    redirect('notes.php');
}

if ($action === 'add') {
    $stmt = db()->prepare('INSERT IGNORE INTO cart (user_id, note_id) VALUES (?, ?)');
    $stmt->execute([$userId, $noteId]);
    set_flash('success', 'Added to cart.');
} elseif ($action === 'remove') {
    $stmt = db()->prepare('DELETE FROM cart WHERE user_id = ? AND note_id = ?');
    $stmt->execute([$userId, $noteId]);
    set_flash('info', 'Removed from cart.');
} else {
    set_flash('danger', 'Invalid cart action.');
}

redirect($_SERVER['HTTP_REFERER'] ?? 'cart.php');
