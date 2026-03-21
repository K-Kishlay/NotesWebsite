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

$testStmt = $pdo->prepare('SELECT t.id, t.title, t.subject, t.total_questions, t.marks_per_question, t.total_marks, u.name AS creator_name
FROM test_series t
JOIN users u ON u.id = t.created_by
WHERE t.id = ? AND t.status = "published"
LIMIT 1');
$testStmt->execute([$testId]);
$test = $testStmt->fetch();
if (!$test) {
    set_flash('danger', 'Test not found.');
    redirect('tests.php');
}

$qStmt = $pdo->prepare('SELECT id, question_order, question_text, option_a, option_b, option_c, option_d
FROM test_questions
WHERE test_id = ?
ORDER BY question_order ASC, id ASC');
$qStmt->execute([$testId]);
$questions = $qStmt->fetchAll();

if (!$questions) {
    set_flash('warning', 'This test has no questions yet.');
    redirect('tests.php');
}

if (!isset($_SESSION['test_start_times']) || !is_array($_SESSION['test_start_times'])) {
    $_SESSION['test_start_times'] = [];
}
$_SESSION['test_start_times'][$testId] = time();

$pageTitle = 'NotesPro | Attempt Test';
$activePage = 'tests';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar mb-4">
  <h1 class="h4 fw-bold mb-1"><?= e($test['title']) ?></h1>
  <p class="text-secondary mb-0"><?= e($test['subject'] ?: 'General') ?> • By <?= e($test['creator_name']) ?></p>
</header>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-3 p-md-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div class="small text-secondary">Time running: <strong id="testTimer">00:00</strong></div>
      <a href="test_forum.php?id=<?= (int)$test['id'] ?>" class="btn btn-sm btn-outline-secondary">Forum & Rating</a>
    </div>
    <div class="test-meta-grid">
      <div><span>Questions</span><strong><?= count($questions) ?></strong></div>
      <div><span>Marks/Q</span><strong><?= number_format((float)$test['marks_per_question'], 2) ?></strong></div>
      <div><span>Total Marks</span><strong><?= number_format((float)$test['total_marks'], 2) ?></strong></div>
      <div><span>Type</span><strong>MCQ</strong></div>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm">
  <div class="card-body p-3 p-md-4">
    <form method="post" action="submit_test.php">
      <?= csrf_field() ?>
      <input type="hidden" name="test_id" value="<?= (int)$test['id'] ?>">
      <input type="hidden" name="started_at_epoch" value="<?= (int) time() ?>">
      <?php foreach ($questions as $idx => $q): ?>
        <article class="test-question mb-4">
          <h2 class="h6 fw-bold mb-3">Q<?= $idx + 1 ?>. <?= e($q['question_text']) ?></h2>
          <?php
          $options = [
            'A' => $q['option_a'],
            'B' => $q['option_b'],
            'C' => $q['option_c'],
            'D' => $q['option_d'],
          ];
          ?>
          <div class="row g-2">
            <?php foreach ($options as $key => $value): ?>
              <div class="col-12 col-md-6">
                <label class="test-option w-100">
                  <input type="radio" name="answers[<?= (int)$q['id'] ?>]" value="<?= e($key) ?>">
                  <span><strong><?= e($key) ?>.</strong> <?= e($value) ?></span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        </article>
      <?php endforeach; ?>
      <button class="btn btn-brand" type="submit">Submit Test</button>
    </form>
  </div>
</section>

<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var start = Date.now();
  var timer = document.getElementById('testTimer');
  if (!timer) return;
  setInterval(function () {
    var sec = Math.floor((Date.now() - start) / 1000);
    var mm = String(Math.floor(sec / 60)).padStart(2, '0');
    var ss = String(sec % 60).padStart(2, '0');
    timer.textContent = mm + ':' + ss;
  }, 1000);
});
</script>
<?php
