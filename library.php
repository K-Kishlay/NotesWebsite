<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$userId = (int) current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_review') {
    verify_csrf_or_abort();
    $noteId = (int) ($_POST['note_id'] ?? 0);
    $rating = (int) ($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($noteId <= 0 || $rating < 1 || $rating > 5) {
        set_flash('danger', 'Please provide a valid rating (1-5).');
        redirect('library.php');
    }

    $ownedStmt = $pdo->prepare('SELECT 1 FROM purchases WHERE user_id = ? AND note_id = ? AND payment_status = "paid" LIMIT 1');
    $ownedStmt->execute([$userId, $noteId]);
    if (!$ownedStmt->fetch()) {
        set_flash('danger', 'You can only review notes from your library.');
        redirect('library.php');
    }

    $existingStmt = $pdo->prepare('SELECT id FROM reviews WHERE user_id = ? AND note_id = ? ORDER BY created_at DESC LIMIT 1');
    $existingStmt->execute([$userId, $noteId]);
    $existing = $existingStmt->fetch();

    if ($existing) {
        $update = $pdo->prepare('UPDATE reviews SET rating = ?, comment = ? WHERE id = ?');
        $update->execute([$rating, $comment, (int)$existing['id']]);
        set_flash('success', 'Review updated.');
    } else {
        $insert = $pdo->prepare('INSERT INTO reviews (user_id, note_id, rating, comment) VALUES (?, ?, ?, ?)');
        $insert->execute([$userId, $noteId, $rating, $comment]);
        set_flash('success', 'Review submitted.');
    }

    redirect('library.php');
}

$stmt = $pdo->prepare('SELECT n.id, n.title, n.subject, n.file_path, p.amount, p.purchased_at,
       r.rating AS my_rating, r.comment AS my_comment
FROM purchases p
JOIN notes n ON n.id = p.note_id
LEFT JOIN reviews r ON r.id = (
    SELECT r2.id
    FROM reviews r2
    WHERE r2.user_id = ? AND r2.note_id = n.id
    ORDER BY r2.created_at DESC
    LIMIT 1
)
WHERE p.user_id = ? AND p.payment_status = "paid"
ORDER BY p.purchased_at DESC');
$stmt->execute([$userId, $userId]);
$items = $stmt->fetchAll();

$totalOwned = count($items);
$totalSpent = 0.0;
foreach ($items as $it) {
    $totalSpent += (float) $it['amount'];
}

$pageTitle = 'NotesPro | My Library';
$activePage = 'library';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar mb-4">
  <h1 class="h3 fw-bold mb-1">My Library</h1>
  <p class="text-secondary mb-0">Notes you purchased or accessed for free.</p>
</header>

<section class="row g-3 mb-4">
  <div class="col-12 col-md-6"><div class="metric-card"><p>Total Notes Owned</p><h2><?= (int)$totalOwned ?></h2></div></div>
  <div class="col-12 col-md-6"><div class="metric-card"><p>Total Spent</p><h2>$<?= number_format($totalSpent, 2) ?></h2></div></div>
</section>

<div class="card border-0 shadow-sm">
  <div class="card-body p-4">
    <?php if (!$items): ?>
      <p class="text-secondary mb-0">No notes in your library yet. <a href="notes.php">Browse notes</a>.</p>
    <?php else: ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($items as $item): ?>
          <li class="list-group-item px-0">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
              <div class="flex-grow-1">
                <h2 class="h6 fw-bold mb-1"><?= e($item['title']) ?></h2>
                <p class="text-secondary mb-2"><?= ((float)$item['amount'] > 0) ? 'Purchased' : 'Free access' ?> on <?= e(date('M d, Y', strtotime($item['purchased_at']))) ?></p>
                <?php if (!empty($item['my_rating'])): ?>
                  <p class="small mb-2">Your Rating: <strong><?= (int)$item['my_rating'] ?>/5</strong></p>
                <?php endif; ?>
              </div>
              <div class="d-flex gap-2">
                <?php if (!empty($item['file_path'])): ?>
                  <a href="download.php?note_id=<?= (int)$item['id'] ?>" class="btn btn-sm btn-outline-secondary">Download</a>
                <?php else: ?>
                  <button class="btn btn-sm btn-outline-secondary" disabled>No File</button>
                <?php endif; ?>
              </div>
            </div>

            <form method="post" action="library.php" class="review-box mt-2">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_review">
              <input type="hidden" name="note_id" value="<?= (int)$item['id'] ?>">
              <div class="row g-2 align-items-end">
                <div class="col-12 col-md-2">
                  <label class="form-label small mb-1">Rating</label>
                  <select class="form-select form-select-sm" name="rating" required>
                    <option value="">Select</option>
                    <?php for ($r = 1; $r <= 5; $r++): ?>
                      <option value="<?= $r ?>" <?= (int)$item['my_rating'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                    <?php endfor; ?>
                  </select>
                </div>
                <div class="col-12 col-md-8">
                  <label class="form-label small mb-1">Comment</label>
                  <input class="form-control form-control-sm" name="comment" value="<?= e($item['my_comment'] ?? '') ?>" placeholder="Share a quick review (optional)">
                </div>
                <div class="col-12 col-md-2 d-grid">
                  <button class="btn btn-sm btn-brand" type="submit"><?= !empty($item['my_rating']) ? 'Update' : 'Submit' ?></button>
                </div>
              </div>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
