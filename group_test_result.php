<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/study_groups_helper.php';
require_login();

$pdo = db();
ensure_study_group_tables($pdo);
$userId = (int) current_user()['id'];
$attemptId = (int) ($_GET['attempt'] ?? 0);
if ($attemptId <= 0) {
    set_flash('danger', 'Invalid result request.');
    redirect('study_groups.php');
}

$stmt = $pdo->prepare('SELECT a.id, a.user_id, a.test_id, a.total_questions, a.correct_answers, a.score, a.total_marks, a.percentage, a.submitted_at,
t.group_id, t.title, g.name AS group_name
FROM study_group_test_attempts a
JOIN study_group_tests t ON t.id = a.test_id
JOIN study_groups g ON g.id = t.group_id
WHERE a.id = ? LIMIT 1');
$stmt->execute([$attemptId]);
$attempt = $stmt->fetch();
if (!$attempt) {
    set_flash('danger', 'Result not found.');
    redirect('study_groups.php');
}
if ((int)$attempt['user_id'] !== $userId && !is_group_member($pdo, (int)$attempt['group_id'], $userId)) {
    set_flash('danger', 'Access denied.');
    redirect('study_groups.php');
}

$leaderStmt = $pdo->prepare('SELECT a.user_id, u.name, u.avatar, a.percentage, a.correct_answers, a.total_questions, a.submitted_at
FROM study_group_test_attempts a
JOIN users u ON u.id = a.user_id
WHERE a.test_id = ?
ORDER BY a.percentage DESC, a.correct_answers DESC, a.submitted_at ASC
LIMIT 10');
$leaderStmt->execute([(int) $attempt['test_id']]);
$leaders = $leaderStmt->fetchAll();

$pageTitle = 'NotesPro | Group Test Result';
$activePage = 'study_groups';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar mb-4">
  <h1 class="h4 fw-bold mb-1"><?= e($attempt['title']) ?> Result</h1>
  <p class="text-secondary mb-0"><?= e($attempt['group_name']) ?></p>
</header>

<section class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="metric-card"><p>Score</p><h2><?= number_format((float)$attempt['score'], 2) ?></h2></div></div>
  <div class="col-6 col-lg-3"><div class="metric-card"><p>Total</p><h2><?= number_format((float)$attempt['total_marks'], 2) ?></h2></div></div>
  <div class="col-6 col-lg-3"><div class="metric-card"><p>Correct</p><h2><?= (int)$attempt['correct_answers'] ?>/<?= (int)$attempt['total_questions'] ?></h2></div></div>
  <div class="col-6 col-lg-3"><div class="metric-card"><p>Percentage</p><h2><?= number_format((float)$attempt['percentage'], 2) ?>%</h2></div></div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-3 p-md-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="text-secondary">Submitted: <?= e(date('M d, Y h:i A', strtotime($attempt['submitted_at']))) ?></span>
    <a class="btn btn-sm btn-outline-secondary" href="group.php?id=<?= (int)$attempt['group_id'] ?>">Back to Group</a>
  </div>
</section>

<section class="card border-0 shadow-sm">
  <div class="card-body p-3 p-md-4">
    <h2 class="h5 fw-bold mb-3">Top 10 in this Group Test</h2>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>#</th><th>Student</th><th>Score %</th><th>Correct</th><th>Date</th></tr></thead>
        <tbody>
          <?php if (!$leaders): ?>
            <tr><td colspan="5" class="text-secondary">No attempts yet.</td></tr>
          <?php else: foreach ($leaders as $idx => $l): ?>
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
              <td><?= e(date('M d, Y', strtotime($l['submitted_at']))) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
