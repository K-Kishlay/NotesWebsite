<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$noteId = (int) ($_GET['note_id'] ?? 0);
if ($noteId <= 0) {
    http_response_code(400);
    exit('Invalid note.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, title, file_path, price FROM notes WHERE id = ? LIMIT 1');
$stmt->execute([$noteId]);
$note = $stmt->fetch();

if (!$note || empty($note['file_path'])) {
    http_response_code(404);
    exit('File not found.');
}

$userId = (int) current_user()['id'];
$isOwner = false;

if (is_admin()) {
    $isOwner = true;
} else {
    $ownStmt = $pdo->prepare('SELECT 1 FROM purchases WHERE user_id = ? AND note_id = ? AND payment_status = "paid" LIMIT 1');
    $ownStmt->execute([$userId, $noteId]);
    $isOwner = (bool) $ownStmt->fetch();
}

if (!$isOwner) {
    http_response_code(403);
    exit('Access denied.');
}

$stored = trim((string) $note['file_path']);
$basename = basename($stored);
$privatePath = rtrim(NOTE_PRIVATE_DIR, '/\\') . DIRECTORY_SEPARATOR . $basename;
$legacyPath = __DIR__ . DIRECTORY_SEPARATOR . ltrim($stored, '/\\');

$realPath = null;
if ($basename !== '' && is_file($privatePath)) {
    $realPath = $privatePath;
} elseif (strpos($stored, 'uploads/') === 0 && is_file($legacyPath)) {
    $realPath = $legacyPath;
}

if ($realPath === null) {
    http_response_code(404);
    exit('File unavailable.');
}

$downloadName = preg_replace('/[^A-Za-z0-9._-]/', '-', $note['title']) . '-' . $noteId . '.pdf';
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($realPath);
exit;
