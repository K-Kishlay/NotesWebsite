<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tests_helper.php';
require_login();

$pdo = db();
ensure_test_system_tables($pdo);

$userId = (int) current_user()['id'];

$stmt = $pdo->prepare('SELECT
    t.id,
    t.title,
    t.subject,
    t.total_questions,
    t.marks_per_question,
    t.total_marks,
    t.created_at,
    u.name AS creator_name,
    COALESCE(att.total_attempts, 0) AS total_attempts,
    COALESCE(att.avg_percentage, 0) AS avg_percentage,
    COALESCE(rv.avg_rating, 0) AS avg_rating,
    COALESCE(rv.rating_count, 0) AS rating_count,
    COALESCE(dc.discussion_count, 0) AS discussion_count,
    COALESCE(my.my_attempts, 0) AS my_attempts,
    my.latest_attempt_id,
    my.latest_percentage
FROM test_series t
JOIN users u ON u.id = t.created_by
LEFT JOIN (
  SELECT test_id, COUNT(*) AS total_attempts, AVG(percentage) AS avg_percentage
  FROM test_attempts
  GROUP BY test_id
) att ON att.test_id = t.id
LEFT JOIN (
  SELECT test_id, AVG(rating) AS avg_rating, COUNT(*) AS rating_count
  FROM test_reviews
  GROUP BY test_id
) rv ON rv.test_id = t.id
LEFT JOIN (
  SELECT test_id, COUNT(*) AS discussion_count
  FROM test_discussions
  GROUP BY test_id
) dc ON dc.test_id = t.id
LEFT JOIN (
  SELECT x.test_id, COUNT(*) AS my_attempts,
         SUBSTRING_INDEX(GROUP_CONCAT(x.id ORDER BY x.submitted_at DESC), ",", 1) AS latest_attempt_id,
         SUBSTRING_INDEX(GROUP_CONCAT(x.percentage ORDER BY x.submitted_at DESC), ",", 1) AS latest_percentage
  FROM test_attempts x
  WHERE x.user_id = ?
  GROUP BY x.test_id
) my ON my.test_id = t.id
WHERE t.status = "published"
ORDER BY t.created_at DESC');
$stmt->execute([$userId]);
$tests = $stmt->fetchAll();

$pageTitle = 'NotesPro | Tests';
$activePage = 'tests';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 fw-bold mb-1">Test Series</h1>
    <p class="text-secondary mb-0">Attempt MCQ tests and track your score history.</p>
  </div>
</header>

<?php if (!$tests): ?>
  <section class="card border-0 shadow-sm">
    <div class="card-body p-4 text-secondary">No tests available right now.</div>
  </section>
<?php else: ?>
  <section class="row g-3">
    <?php foreach ($tests as $t): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <article class="note-card h-100">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="tag tag-free">MCQ Test</span>
            <span class="small text-secondary"><?= e(date('M d, Y', strtotime($t['created_at']))) ?></span>
          </div>
          <h2 class="h6 fw-bold mb-1"><?= e($t['title']) ?></h2>
          <p class="small text-secondary mb-3"><?= e($t['subject'] ?: 'General') ?> • By <?= e($t['creator_name']) ?></p>

          <div class="test-meta-grid mb-3">
            <div><span>Questions</span><strong><?= (int)$t['total_questions'] ?></strong></div>
            <div><span>Total Marks</span><strong><?= number_format((float)$t['total_marks'], 2) ?></strong></div>
            <div><span>Your Attempts</span><strong><?= (int)$t['my_attempts'] ?></strong></div>
            <div><span>Avg Score</span><strong><?= number_format((float)$t['avg_percentage'], 1) ?>%</strong></div>
            <div><span>Rating</span><strong><?= number_format((float)$t['avg_rating'], 1) ?>/5 (<?= (int)$t['rating_count'] ?>)</strong></div>
            <div><span>Forum</span><strong><?= (int)$t['discussion_count'] ?> posts</strong></div>
          </div>

          <?php if (!empty($t['latest_attempt_id'])): ?>
            <p class="small mb-3">
              Last score:
              <a href="test_result.php?attempt=<?= (int)$t['latest_attempt_id'] ?>" class="text-decoration-none fw-semibold">
                <?= number_format((float)$t['latest_percentage'], 1) ?>%
              </a>
            </p>
          <?php endif; ?>

          <div class="d-flex gap-2">
            <a class="btn btn-sm btn-brand" href="take_test.php?id=<?= (int)$t['id'] ?>">Start Test</a>
            <a class="btn btn-sm btn-outline-secondary" href="test_forum.php?id=<?= (int)$t['id'] ?>">Forum</a>
          </div>
        </article>
      </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
