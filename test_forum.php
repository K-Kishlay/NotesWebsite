<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tests_helper.php';
require_login();

$pdo = db();
ensure_test_system_tables($pdo);

$testId = (int) ($_GET['id'] ?? 0);
if ($testId <= 0) {
    set_flash('danger', 'Invalid test.');
    redirect('tests.php');
}

$testStmt = $pdo->prepare('SELECT t.id, t.title, t.subject, t.total_questions, t.total_marks, u.name AS creator_name,
COALESCE(rv.avg_rating, 0) AS avg_rating,
COALESCE(rv.rating_count, 0) AS rating_count
FROM test_series t
JOIN users u ON u.id = t.created_by
LEFT JOIN (
  SELECT test_id, AVG(rating) AS avg_rating, COUNT(*) AS rating_count
  FROM test_reviews
  GROUP BY test_id
) rv ON rv.test_id = t.id
WHERE t.id = ? AND t.status = "published"
LIMIT 1');
$testStmt->execute([$testId]);
$test = $testStmt->fetch();
if (!$test) {
    set_flash('danger', 'Test not found.');
    redirect('tests.php');
}

$userId = (int) current_user()['id'];
$myRatingStmt = $pdo->prepare('SELECT rating, comment FROM test_reviews WHERE test_id = ? AND user_id = ? LIMIT 1');
$myRatingStmt->execute([$testId, $userId]);
$myRating = $myRatingStmt->fetch() ?: ['rating' => 5, 'comment' => ''];

$discStmt = $pdo->prepare('SELECT d.id, d.message, d.created_at, u.id AS uid, u.name, u.avatar,
EXISTS(SELECT 1 FROM test_attempts a WHERE a.test_id = d.test_id AND a.user_id = d.user_id) AS attempted
FROM test_discussions d
JOIN users u ON u.id = d.user_id
WHERE d.test_id = ?
ORDER BY d.created_at DESC');
$discStmt->execute([$testId]);
$discussions = $discStmt->fetchAll();

$leaderStmt = $pdo->prepare('SELECT ranked.user_id, ranked.name, ranked.avatar, ranked.percentage, ranked.correct_answers, ranked.total_questions, ranked.time_taken_seconds, ranked.submitted_at
FROM (
  SELECT a.user_id, u.name, u.avatar, a.percentage, a.correct_answers, a.total_questions, a.time_taken_seconds, a.submitted_at,
         ROW_NUMBER() OVER (
           PARTITION BY a.user_id
           ORDER BY a.percentage DESC, a.correct_answers DESC, COALESCE(NULLIF(a.time_taken_seconds, 0), 99999999) ASC, a.submitted_at ASC, a.id ASC
         ) AS rn
  FROM test_attempts a
  JOIN users u ON u.id = a.user_id
  WHERE a.test_id = ?
) ranked
WHERE ranked.rn = 1
ORDER BY ranked.percentage DESC, ranked.correct_answers DESC, COALESCE(NULLIF(ranked.time_taken_seconds, 0), 99999999) ASC, ranked.submitted_at ASC
LIMIT 10');
$leaderStmt->execute([$testId]);
$leaders = $leaderStmt->fetchAll();

$pageTitle = 'NotesPro | Test Forum';
$activePage = 'tests';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar mb-4">
  <h1 class="h4 fw-bold mb-1"><?= e($test['title']) ?> Forum</h1>
  <p class="text-secondary mb-0"><?= e($test['subject'] ?: 'General') ?> • By <?= e($test['creator_name']) ?></p>
</header>

<section class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="metric-card"><p>Questions</p><h2><?= (int)$test['total_questions'] ?></h2></div></div>
  <div class="col-6 col-lg-3"><div class="metric-card"><p>Total Marks</p><h2><?= number_format((float)$test['total_marks'], 2) ?></h2></div></div>
  <div class="col-6 col-lg-3"><div class="metric-card"><p>Avg Rating</p><h2><?= number_format((float)$test['avg_rating'], 1) ?>/5</h2></div></div>
  <div class="col-6 col-lg-3"><div class="metric-card"><p>Total Ratings</p><h2><?= (int)$test['rating_count'] ?></h2></div></div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-3 p-md-4">
    <h2 class="h5 fw-bold mb-3">Top 10 Leaderboard</h2>
    <p class="small text-secondary mb-3">Ranking is based on higher accuracy first, then lower completion time.</p>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>Student</th>
            <th>Accuracy</th>
            <th>Correct</th>
            <th>Time</th>
            <th>Submitted</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$leaders): ?>
            <tr><td colspan="6" class="text-secondary">No attempts yet.</td></tr>
          <?php else: ?>
            <?php foreach ($leaders as $idx => $l): ?>
              <?php $secs = (int) ($l['time_taken_seconds'] ?? 0); ?>
              <tr>
                <td><?= $idx + 1 ?></td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <?php if (!empty($l['avatar'])): ?>
                      <img src="<?= e($l['avatar']) ?>" alt="Avatar" class="uploader-avatar">
                    <?php else: ?>
                      <span class="uploader-avatar uploader-avatar-fallback"><?= e(strtoupper(substr(trim((string)$l['name']), 0, 1))) ?></span>
                    <?php endif; ?>
                    <span><?= e($l['name']) ?></span>
                  </div>
                </td>
                <td><?= number_format((float)$l['percentage'], 2) ?>%</td>
                <td><?= (int)$l['correct_answers'] ?>/<?= (int)$l['total_questions'] ?></td>
                <td><?= e(sprintf('%02d:%02d', (int) floor($secs / 60), $secs % 60)) ?></td>
                <td><?= e(date('M d, Y h:i A', strtotime($l['submitted_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-3 p-md-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="h5 fw-bold mb-0">Rate This Test</h2>
      <a href="take_test.php?id=<?= (int)$testId ?>" class="btn btn-sm btn-brand">Start Test</a>
    </div>
    <form method="post" action="test_forum_action.php" class="row g-2">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="rate">
      <input type="hidden" name="test_id" value="<?= (int)$testId ?>">
      <div class="col-12 col-md-3">
        <label class="form-label small mb-1">Stars</label>
        <select class="form-select" name="rating" required>
          <option value="5" <?= ((int)$myRating['rating'] === 5) ? 'selected' : '' ?>>5 - Excellent</option>
          <option value="4" <?= ((int)$myRating['rating'] === 4) ? 'selected' : '' ?>>4 - Very Good</option>
          <option value="3" <?= ((int)$myRating['rating'] === 3) ? 'selected' : '' ?>>3 - Good</option>
          <option value="2" <?= ((int)$myRating['rating'] === 2) ? 'selected' : '' ?>>2 - Fair</option>
          <option value="1" <?= ((int)$myRating['rating'] === 1) ? 'selected' : '' ?>>1 - Poor</option>
        </select>
      </div>
      <div class="col-12 col-md-7">
        <label class="form-label small mb-1">Comment (Optional)</label>
        <input class="form-control" name="comment" maxlength="500" value="<?= e((string)$myRating['comment']) ?>" placeholder="How was this test?">
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-outline-secondary" type="submit">Save</button>
      </div>
    </form>
  </div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-3 p-md-4">
    <h2 class="h5 fw-bold mb-3">Discussion Forum</h2>
    <form method="post" action="test_forum_action.php" class="row g-2 mb-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="comment">
      <input type="hidden" name="test_id" value="<?= (int)$testId ?>">
      <div class="col-12 col-md-10">
        <textarea class="form-control" rows="2" name="message" maxlength="2000" placeholder="Ask doubts or discuss this test..." required></textarea>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-brand" type="submit">Post</button>
      </div>
    </form>

    <?php if (!$discussions): ?>
      <p class="text-secondary mb-0">No discussion yet.</p>
    <?php else: ?>
      <?php foreach ($discussions as $d): ?>
        <article class="discussion-item mb-3">
          <div class="d-flex justify-content-between gap-2 align-items-start">
            <div class="d-flex align-items-center gap-2">
              <?php if (!empty($d['avatar'])): ?>
                <img src="<?= e($d['avatar']) ?>" alt="Avatar" class="uploader-avatar">
              <?php else: ?>
                <span class="uploader-avatar uploader-avatar-fallback"><?= e(strtoupper(substr(trim((string)$d['name']), 0, 1))) ?></span>
              <?php endif; ?>
              <div>
                <strong><?= e($d['name']) ?></strong>
                <?php if ((int)$d['attempted'] === 1): ?>
                  <span class="badge text-bg-success ms-1">Attempted</span>
                <?php endif; ?>
                <div class="small text-secondary"><?= e(date('M d, Y h:i A', strtotime($d['created_at']))) ?></div>
              </div>
            </div>
          </div>
          <p class="mb-0 mt-2"><?= nl2br(e($d['message'])) ?></p>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
