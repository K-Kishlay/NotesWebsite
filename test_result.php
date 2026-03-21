<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tests_helper.php';
require_login();

$pdo = db();
ensure_test_system_tables($pdo);

$attemptId = (int) ($_GET['attempt'] ?? 0);
if ($attemptId <= 0) {
    set_flash('danger', 'Invalid result request.');
    redirect('tests.php');
}

$userId = (int) current_user()['id'];

$attemptStmt = $pdo->prepare('SELECT a.id, a.user_id, a.test_id, a.total_questions, a.attempted_questions, a.correct_answers, a.score, a.total_marks, a.percentage, a.started_at, a.time_taken_seconds, a.submitted_at,
t.title, t.subject, u.name AS creator_name
 ,t.created_by
FROM test_attempts a
JOIN test_series t ON t.id = a.test_id
JOIN users u ON u.id = t.created_by
WHERE a.id = ? LIMIT 1');
$attemptStmt->execute([$attemptId]);
$attempt = $attemptStmt->fetch();
if (!$attempt) {
    set_flash('danger', 'Result not found.');
    redirect('tests.php');
}

if ((int) $attempt['user_id'] !== $userId && !is_admin() && (int) $attempt['created_by'] !== $userId) {
    set_flash('danger', 'You are not allowed to view this result.');
    redirect('tests.php');
}

$detailStmt = $pdo->prepare('SELECT q.question_order, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d, q.correct_option, q.explanation,
ans.selected_option, ans.is_correct
FROM test_attempt_answers ans
JOIN test_questions q ON q.id = ans.question_id
WHERE ans.attempt_id = ?
ORDER BY q.question_order ASC, q.id ASC');
$detailStmt->execute([$attemptId]);
$details = $detailStmt->fetchAll();

$pageTitle = 'NotesPro | Test Result';
$activePage = 'tests';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar mb-4">
  <h1 class="h4 fw-bold mb-1">Result: <?= e($attempt['title']) ?></h1>
  <p class="text-secondary mb-0"><?= e($attempt['subject'] ?: 'General') ?> • By <?= e($attempt['creator_name']) ?></p>
</header>

<section class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="metric-card"><p>Score</p><h2><?= number_format((float)$attempt['score'], 2) ?></h2></div></div>
  <div class="col-6 col-lg-3"><div class="metric-card"><p>Total Marks</p><h2><?= number_format((float)$attempt['total_marks'], 2) ?></h2></div></div>
  <div class="col-6 col-lg-3"><div class="metric-card"><p>Correct</p><h2><?= (int)$attempt['correct_answers'] ?>/<?= (int)$attempt['total_questions'] ?></h2></div></div>
  <div class="col-6 col-lg-3"><div class="metric-card"><p>Percentage</p><h2><?= number_format((float)$attempt['percentage'], 2) ?>%</h2></div></div>
  <div class="col-6 col-lg-3"><div class="metric-card"><p>Time Taken</p><h2><?= e(sprintf('%02d:%02d', (int) floor(((int)$attempt['time_taken_seconds']) / 60), ((int)$attempt['time_taken_seconds']) % 60)) ?></h2></div></div>
</section>

<section class="card border-0 shadow-sm mb-3">
  <div class="card-body p-3 p-md-4 d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <p class="mb-0 text-secondary">Submitted: <?= e(date('M d, Y h:i A', strtotime($attempt['submitted_at']))) ?></p>
    <div class="d-flex gap-2">
      <a href="test_forum.php?id=<?= (int)$attempt['test_id'] ?>" class="btn btn-sm btn-outline-secondary">Forum & Rating</a>
      <a href="tests.php" class="btn btn-sm btn-outline-secondary">Back to Tests</a>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm">
  <div class="card-body p-3 p-md-4">
    <h2 class="h5 fw-bold mb-3">Answer Review</h2>
    <?php if (!$details): ?>
      <p class="text-secondary mb-0">No answer details found.</p>
    <?php else: ?>
      <?php foreach ($details as $d): ?>
        <?php
        $selected = $d['selected_option'];
        $correct = $d['correct_option'];
        $statusClass = ((int)$d['is_correct'] === 1) ? 'result-correct' : 'result-wrong';
        $statusLabel = ((int)$d['is_correct'] === 1) ? 'Correct' : 'Wrong';
        ?>
        <article class="test-question mb-4">
          <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
            <h3 class="h6 fw-bold mb-0">Q<?= (int)$d['question_order'] ?>. <?= e($d['question_text']) ?></h3>
            <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
          </div>
          <div class="small text-secondary mb-2">Your answer: <?= e($selected ?: 'Not Attempted') ?> • Correct answer: <?= e($correct) ?></div>
          <?php if (!empty($d['explanation'])): ?>
            <div class="review-box"><strong>Explanation:</strong> <?= e($d['explanation']) ?></div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
