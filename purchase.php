<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$noteId = (int) ($_REQUEST['note_id'] ?? 0);
if ($noteId <= 0) {
    set_flash('danger', 'Invalid note selection.');
    redirect('notes.php');
}

redirect('payment.php?scope=single&note_id=' . $noteId);
