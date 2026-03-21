<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
refresh_session_user(db());

$pdo = db();
$user = current_user();
$userId = (int) $user['id'];
$isStudent = (($user['role'] ?? '') === 'student');

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

$stmt = $pdo->prepare('SELECT
    u.id,
    u.name,
    u.email,
    u.avatar,
    u.created_at,
    COALESCE(nr.note_avg, 0) AS note_avg,
    COALESCE(nr.note_count, 0) AS note_count,
    COALESCE(er.educator_avg, 0) AS educator_avg,
    COALESCE(er.educator_count, 0) AS educator_count,
    COALESCE(my.rating, 0) AS my_rating,
    COALESCE(my.comment, "") AS my_comment,
    CASE
      WHEN COALESCE(nr.note_count, 0) > 0 AND COALESCE(er.educator_count, 0) > 0 THEN (nr.note_avg + er.educator_avg) / 2
      WHEN COALESCE(nr.note_count, 0) > 0 THEN nr.note_avg
      WHEN COALESCE(er.educator_count, 0) > 0 THEN er.educator_avg
      ELSE 0
    END AS rank_score
FROM users u
LEFT JOIN (
    SELECT n.uploaded_by AS educator_id, AVG(r.rating) AS note_avg, COUNT(r.id) AS note_count
    FROM notes n
    LEFT JOIN reviews r ON r.note_id = n.id
    WHERE n.uploaded_by IS NOT NULL
    GROUP BY n.uploaded_by
) nr ON nr.educator_id = u.id
LEFT JOIN (
    SELECT educator_id, AVG(rating) AS educator_avg, COUNT(*) AS educator_count
    FROM educator_ratings
    GROUP BY educator_id
) er ON er.educator_id = u.id
LEFT JOIN educator_ratings my
  ON my.educator_id = u.id AND my.user_id = ?
WHERE u.role = "educator"
ORDER BY rank_score DESC, er.educator_count DESC, nr.note_count DESC, u.name ASC');
$stmt->execute([$userId]);
$educators = $stmt->fetchAll();

$pageTitle = 'NotesPro | Educators';
$activePage = 'educators';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar d-flex flex-wrap gap-3 justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 fw-bold mb-1">Educators & Rankings</h1>
    <p class="text-secondary mb-0">Rank based on note ratings and direct student feedback.</p>
  </div>
  <?php if (!$isStudent): ?>
    <span class="badge text-bg-secondary">Only students can submit ratings</span>
  <?php endif; ?>
</header>

<?php if (empty($educators)): ?>
  <section class="card border-0 shadow-sm">
    <div class="card-body p-4 text-secondary">No educators found yet.</div>
  </section>
<?php else: ?>
  <section class="row g-3">
    <?php $rank = 0; foreach ($educators as $educator): $rank++; ?>
      <div class="col-12 col-lg-6">
        <article class="educator-card">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div class="d-flex align-items-center gap-2">
              <?php if (!empty($educator['avatar'])): ?>
                <img src="<?= e($educator['avatar']) ?>" alt="Educator avatar" class="educator-avatar">
              <?php else: ?>
                <span class="educator-avatar educator-avatar-fallback">
                  <?= e(strtoupper(substr(trim((string)($educator['name'] ?? 'E')), 0, 1))) ?>
                </span>
              <?php endif; ?>
              <div>
                <h2 class="h6 fw-bold mb-0"><?= e($educator['name']) ?></h2>
                <p class="small text-secondary mb-0"><?= e($educator['email']) ?></p>
              </div>
            </div>
            <span class="rank-chip">#<?= $rank ?></span>
          </div>

          <div class="educator-score mb-3">
            <div>
              <p class="small text-secondary mb-1">Combined Rank Score</p>
              <p class="fw-bold mb-0"><?= number_format((float)$educator['rank_score'], 2) ?>/5</p>
            </div>
            <div>
              <p class="small text-secondary mb-1">Note Ratings</p>
              <p class="mb-0"><?= number_format((float)$educator['note_avg'], 2) ?> (<?= (int)$educator['note_count'] ?> reviews)</p>
            </div>
            <div>
              <p class="small text-secondary mb-1">Student Ratings</p>
              <p class="mb-0"><?= number_format((float)$educator['educator_avg'], 2) ?> (<?= (int)$educator['educator_count'] ?> ratings)</p>
            </div>
          </div>

          <?php if ($isStudent): ?>
            <form method="post" action="educator_rating_action.php" class="row g-2">
              <?= csrf_field() ?>
              <input type="hidden" name="educator_id" value="<?= (int)$educator['id'] ?>">
              <div class="col-12 col-md-4">
                <label class="form-label small mb-1">Your Stars</label>
                <select class="form-select form-select-sm" name="rating" required>
                  <option value="5" <?= ((int)$educator['my_rating'] === 5) ? 'selected' : '' ?>>5 - Excellent</option>
                  <option value="4" <?= ((int)$educator['my_rating'] === 4) ? 'selected' : '' ?>>4 - Very Good</option>
                  <option value="3" <?= ((int)$educator['my_rating'] === 3) ? 'selected' : '' ?>>3 - Good</option>
                  <option value="2" <?= ((int)$educator['my_rating'] === 2) ? 'selected' : '' ?>>2 - Average</option>
                  <option value="1" <?= ((int)$educator['my_rating'] === 1) ? 'selected' : '' ?>>1 - Poor</option>
                </select>
              </div>
              <div class="col-12 col-md-8">
                <label class="form-label small mb-1">Comment (Optional)</label>
                <input class="form-control form-control-sm" name="comment" maxlength="500" value="<?= e($educator['my_comment']) ?>" placeholder="Share your feedback for this educator">
              </div>
              <div class="col-12 d-grid d-md-flex justify-content-md-end">
                <button class="btn btn-sm btn-brand" type="submit">Save Rating</button>
              </div>
            </form>
          <?php endif; ?>
        </article>
      </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>
<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
