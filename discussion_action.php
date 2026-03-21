<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('notes.php');
}
verify_csrf_or_abort();

$pdo = db();
$action = $_POST['action'] ?? '';
$userId = (int) current_user()['id'];
$redirectTo = trim($_POST['redirect_to'] ?? '');
if ($redirectTo === '') {
    $redirectTo = 'notes.php';
}

if ($action === 'add_comment' || $action === 'add_reply') {
    $noteId = (int) ($_POST['note_id'] ?? 0);
    $parentId = (int) ($_POST['parent_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    if ($noteId <= 0 || $message === '') {
        set_flash('danger', 'Message cannot be empty.');
        redirect($redirectTo);
    }
    if (mb_strlen($message) > 1000) {
        set_flash('danger', 'Message is too long (max 1000 chars).');
        redirect($redirectTo);
    }

    $noteStmt = $pdo->prepare('SELECT id FROM notes WHERE id = ? LIMIT 1');
    $noteStmt->execute([$noteId]);
    if (!$noteStmt->fetch()) {
        set_flash('danger', 'Note not found.');
        redirect($redirectTo);
    }

    if ($action === 'add_reply') {
        if ($parentId <= 0) {
            set_flash('danger', 'Invalid reply target.');
            redirect($redirectTo);
        }
        $parentStmt = $pdo->prepare('SELECT id, note_id FROM note_discussions WHERE id = ? AND status = "visible" LIMIT 1');
        $parentStmt->execute([$parentId]);
        $parent = $parentStmt->fetch();
        if (!$parent || (int) $parent['note_id'] !== $noteId) {
            set_flash('danger', 'Invalid reply target.');
            redirect($redirectTo);
        }
    } else {
        $parentId = null;
    }

    $insert = $pdo->prepare('INSERT INTO note_discussions (note_id, user_id, parent_id, message, status) VALUES (?, ?, ?, ?, "visible")');
    $insert->execute([$noteId, $userId, $parentId, $message]);
    set_flash('success', $action === 'add_reply' ? 'Reply posted.' : 'Comment posted.');
    redirect($redirectTo);
}

if (in_array($action, ['hide', 'unhide', 'delete'], true)) {
    if (!is_admin()) {
        set_flash('danger', 'Admin access required.');
        redirect($redirectTo);
    }
    $discussionId = (int) ($_POST['discussion_id'] ?? 0);
    if ($discussionId <= 0) {
        set_flash('danger', 'Invalid discussion id.');
        redirect($redirectTo);
    }

    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM note_discussions WHERE id = ?');
        $stmt->execute([$discussionId]);
        set_flash('info', 'Discussion deleted.');
    } else {
        $status = $action === 'hide' ? 'hidden' : 'visible';
        $stmt = $pdo->prepare('UPDATE note_discussions SET status = ? WHERE id = ?');
        $stmt->execute([$status, $discussionId]);
        set_flash('success', $action === 'hide' ? 'Discussion hidden.' : 'Discussion made visible.');
    }
    redirect($redirectTo);
}

set_flash('danger', 'Invalid discussion action.');
redirect($redirectTo);
