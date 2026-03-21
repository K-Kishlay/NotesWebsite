<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
verify_csrf_or_abort();

$user = current_user();
$userId = (int) $user['id'];

if (($user['role'] ?? '') !== 'student') {
    set_flash('danger', 'Only students can rate educators.');
    redirect('educators.php');
}

$educatorId = (int) ($_POST['educator_id'] ?? 0);
$rating = (int) ($_POST['rating'] ?? 0);
$comment = trim((string) ($_POST['comment'] ?? ''));

if ($educatorId <= 0 || $rating < 1 || $rating > 5) {
    set_flash('danger', 'Invalid educator rating request.');
    redirect('educators.php');
}

if ($educatorId === $userId) {
    set_flash('danger', 'You cannot rate your own profile.');
    redirect('educators.php');
}

if (strlen($comment) > 500) {
    $comment = substr($comment, 0, 500);
}

$pdo = db();

$pdo->exec('CREATE TABLE IF NOT EXISTS educator_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    educator_id INT NOT NULL,
    rating INT NOT NULL,
    comment VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_educator_rating_range CHECK (rating >= 1 AND rating <= 5),
    UNIQUE KEY uniq_user_educator_rating (user_id, educator_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (educator_id) REFERENCES users(id) ON DELETE CASCADE
)');

$check = $pdo->prepare('SELECT id FROM users WHERE id = ? AND role = "educator" LIMIT 1');
$check->execute([$educatorId]);
if (!$check->fetch()) {
    set_flash('danger', 'Educator not found.');
    redirect('educators.php');
}

$stmt = $pdo->prepare('INSERT INTO educator_ratings (user_id, educator_id, rating, comment)
VALUES (?, ?, ?, ?)
ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), updated_at = CURRENT_TIMESTAMP');
$stmt->execute([$userId, $educatorId, $rating, $comment !== '' ? $comment : null]);

set_flash('success', 'Educator rating saved.');
redirect('educators.php');
