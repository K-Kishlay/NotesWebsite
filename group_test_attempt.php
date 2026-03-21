<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/study_groups_helper.php';
require_login();

$pdo = db();
ensure_study_group_tables($pdo);
$userId = (int) current_user()['id'];
$testId = (int) ($_GET['test_id'] ?? 0);
if ($testId <= 0) {
    set_flash('danger', 'Invalid group test.');
    redirect('study_groups.php');
}

$testStmt = $pdo->prepare('SELECT t.id, t.group_id, t.title, t.instructions, t.total_questions, t.marks_per_question, t.total_marks, g.name AS group_name
FROM study_group_tests t
JOIN study_groups g ON g.id = t.group_id
WHERE t.id = ? LIMIT 1');
$testStmt->execute([$testId]);
$test = $testStmt->fetch();
if (!$test) {
    set_flash('danger', 'Group test not found.');
    redirect('study_groups.php');
}
if (!is_group_member($pdo, (int) $test['group_id'], $userId)) {
    set_flash('danger', 'You are not a member of this group.');
    redirect('study_groups.php');
}

$qStmt = $pdo->prepare('SELECT id, question_order, question_text, option_a, option_b, option_c, option_d
FROM study_group_test_questions
WHERE test_id = ?
ORDER BY question_order ASC, id ASC');
$qStmt->execute([$testId]);
$questions = $qStmt->fetchAll();
if (!$questions) {
    set_flash('warning', 'No questions available in this test.');
    redirect('group.php?id=' . (int)$test['group_id']);
}

$pageTitle = 'NotesPro | Group Test Attempt';
$activePage = 'study_groups';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar mb-4">
  <h1 class="h4 fw-bold mb-1"><?= e($test['title']) ?></h1>
  <p class="text-secondary mb-0"><?= e($test['group_name']) ?> • <?= e($test['instructions'] ?: 'Group test') ?></p>
</header>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-3 p-md-4">
    <div class="test-meta-grid">
      <div><span>Questions</span><strong><?= count($questions) ?></strong></div>
      <div><span>Marks/Q</span><strong><?= number_format((float)$test['marks_per_question'], 2) ?></strong></div>
      <div><span>Total Marks</span><strong><?= number_format((float)$test['total_marks'], 2) ?></strong></div>
      <div><span>Group</span><strong><?= e($test['group_name']) ?></strong></div>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm">
  <div class="card-body p-3 p-md-4">
    <form method="post" action="group_test_submit.php">
      <?= csrf_field() ?>
      <input type="hidden" name="test_id" value="<?= (int)$test['id'] ?>">
      <?php foreach ($questions as $idx => $q): ?>
        <article class="test-question mb-4">
          <h2 class="h6 fw-bold mb-3">Q<?= $idx + 1 ?>. <?= e($q['question_text']) ?></h2>
          <?php $opts = ['A' => $q['option_a'], 'B' => $q['option_b'], 'C' => $q['option_c'], 'D' => $q['option_d']]; ?>
          <div class="row g-2">
            <?php foreach ($opts as $key => $val): ?>
              <div class="col-12 col-md-6">
                <label class="test-option w-100">
                  <input type="radio" name="answers[<?= (int)$q['id'] ?>]" value="<?= e($key) ?>">
                  <span><strong><?= e($key) ?>.</strong> <?= e($val) ?></span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        </article>
      <?php endforeach; ?>
      <button class="btn btn-brand" type="submit">Submit Group Test</button>
    </form>
  </div>
</section>
<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
