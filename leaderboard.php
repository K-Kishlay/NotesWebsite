<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tests_helper.php';
require_login();

$pdo = db();
ensure_test_system_tables($pdo);

$testsStmt = $pdo->query('SELECT id, title, subject FROM test_series WHERE status = "published" ORDER BY created_at DESC');
$tests = $testsStmt->fetchAll();

$selectedTestId = (int) ($_GET['test_id'] ?? 0);
if ($selectedTestId <= 0 && !empty($tests)) {
    $selectedTestId = (int) $tests[0]['id'];
}

$testLeaderboard = [];
if ($selectedTestId > 0) {
    $attemptStmt = $pdo->prepare('SELECT a.id, a.user_id, u.name, u.avatar, a.percentage, a.correct_answers, a.total_questions, a.time_taken_seconds, a.submitted_at
    FROM test_attempts a
    JOIN users u ON u.id = a.user_id
    WHERE a.test_id = ?
    ORDER BY a.percentage DESC, a.correct_answers DESC, COALESCE(NULLIF(a.time_taken_seconds, 0), 99999999) ASC, a.submitted_at ASC, a.id ASC');
    $attemptStmt->execute([$selectedTestId]);
    $rows = $attemptStmt->fetchAll();

    $seenUsers = [];
    foreach ($rows as $r) {
        $uid = (int) $r['user_id'];
        if (isset($seenUsers[$uid])) {
            continue;
        }
        $seenUsers[$uid] = true;
        $testLeaderboard[] = $r;
        if (count($testLeaderboard) >= 10) {
            break;
        }
    }
}

$overallStmt = $pdo->query('SELECT a.user_id, u.name, u.avatar, a.test_id, a.percentage, a.correct_answers, a.total_questions, a.time_taken_seconds, a.submitted_at, a.id
FROM test_attempts a
JOIN users u ON u.id = a.user_id
ORDER BY a.test_id ASC, a.user_id ASC, a.percentage DESC, a.correct_answers DESC, COALESCE(NULLIF(a.time_taken_seconds, 0), 99999999) ASC, a.submitted_at ASC, a.id ASC');
$overallRows = $overallStmt->fetchAll();

$bestByUserTest = [];
foreach ($overallRows as $row) {
    $key = (int) $row['user_id'] . ':' . (int) $row['test_id'];
    if (!isset($bestByUserTest[$key])) {
        $bestByUserTest[$key] = $row;
    }
}

$overallMap = [];
foreach ($bestByUserTest as $row) {
    $uid = (int) $row['user_id'];
    if (!isset($overallMap[$uid])) {
        $overallMap[$uid] = [
            'user_id' => $uid,
            'name' => $row['name'],
            'avatar' => $row['avatar'],
            'tests_count' => 0,
            'sum_percentage' => 0.0,
            'sum_time' => 0,
            'total_correct' => 0,
            'total_questions' => 0,
        ];
    }
    $overallMap[$uid]['tests_count']++;
    $overallMap[$uid]['sum_percentage'] += (float) $row['percentage'];
    $overallMap[$uid]['sum_time'] += (int) $row['time_taken_seconds'];
    $overallMap[$uid]['total_correct'] += (int) $row['correct_answers'];
    $overallMap[$uid]['total_questions'] += (int) $row['total_questions'];
}

$overallLeaderboard = [];
foreach ($overallMap as $entry) {
    $testsCount = max(1, (int) $entry['tests_count']);
    $avgPercentage = $entry['sum_percentage'] / $testsCount;
    $avgTime = (int) round($entry['sum_time'] / $testsCount);
    $accuracy = $entry['total_questions'] > 0 ? ($entry['total_correct'] / $entry['total_questions']) * 100 : 0;

    $overallLeaderboard[] = [
        'user_id' => $entry['user_id'],
        'name' => $entry['name'],
        'avatar' => $entry['avatar'],
        'tests_count' => $testsCount,
        'avg_percentage' => $avgPercentage,
        'avg_time' => $avgTime,
        'accuracy' => $accuracy,
        'total_correct' => $entry['total_correct'],
        'total_questions' => $entry['total_questions'],
    ];
}

usort($overallLeaderboard, static function (array $a, array $b): int {
    if ((float) $a['avg_percentage'] !== (float) $b['avg_percentage']) {
        return ((float) $a['avg_percentage'] < (float) $b['avg_percentage']) ? 1 : -1;
    }
    if ((float) $a['accuracy'] !== (float) $b['accuracy']) {
        return ((float) $a['accuracy'] < (float) $b['accuracy']) ? 1 : -1;
    }
    if ((int) $a['avg_time'] !== (int) $b['avg_time']) {
        return ((int) $a['avg_time'] > (int) $b['avg_time']) ? 1 : -1;
    }
    return strcmp((string) $a['name'], (string) $b['name']);
});

$overallLeaderboard = array_slice($overallLeaderboard, 0, 10);

$pageTitle = 'NotesPro | Leaderboard';
$activePage = 'leaderboard';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar mb-4">
  <h1 class="h3 fw-bold mb-1">Student Leaderboard</h1>
  <p class="text-secondary mb-0">Top performers by test and overall performance.</p>
</header>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-3 p-md-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h2 class="h5 fw-bold mb-0">Test Leaderboard (Top 10)</h2>
      <form method="get" action="leaderboard.php" class="d-flex gap-2">
        <select class="form-select form-select-sm" name="test_id">
          <?php foreach ($tests as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $selectedTestId ? 'selected' : '' ?>><?= e($t['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-secondary" type="submit">Load</button>
      </form>
    </div>

    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>Student</th>
            <th>Accuracy</th>
            <th>Correct</th>
            <th>Time</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$testLeaderboard): ?>
            <tr><td colspan="6" class="text-secondary">No attempts for selected test.</td></tr>
          <?php else: foreach ($testLeaderboard as $i => $r): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?php if (!empty($r['avatar'])): ?>
                    <img src="<?= e($r['avatar']) ?>" alt="Avatar" class="uploader-avatar">
                  <?php else: ?>
                    <span class="uploader-avatar uploader-avatar-fallback"><?= e(strtoupper(substr(trim((string)$r['name']), 0, 1))) ?></span>
                  <?php endif; ?>
                  <span><?= e($r['name']) ?></span>
                </div>
              </td>
              <td><?= number_format((float)$r['percentage'], 2) ?>%</td>
              <td><?= (int)$r['correct_answers'] ?>/<?= (int)$r['total_questions'] ?></td>
              <td><?= e(sprintf('%02d:%02d', (int) floor(((int)$r['time_taken_seconds']) / 60), ((int)$r['time_taken_seconds']) % 60)) ?></td>
              <td><?= e(date('M d, Y', strtotime($r['submitted_at']))) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm">
  <div class="card-body p-3 p-md-4">
    <h2 class="h5 fw-bold mb-3">Overall Best Students (Top 10)</h2>
    <p class="small text-secondary mb-3">Ranking uses average percentage across each student's best attempt per test, then average completion time.</p>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>Student</th>
            <th>Avg Score</th>
            <th>Accuracy</th>
            <th>Tests</th>
            <th>Avg Time</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$overallLeaderboard): ?>
            <tr><td colspan="6" class="text-secondary">No attempts yet.</td></tr>
          <?php else: foreach ($overallLeaderboard as $i => $r): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?php if (!empty($r['avatar'])): ?>
                    <img src="<?= e($r['avatar']) ?>" alt="Avatar" class="uploader-avatar">
                  <?php else: ?>
                    <span class="uploader-avatar uploader-avatar-fallback"><?= e(strtoupper(substr(trim((string)$r['name']), 0, 1))) ?></span>
                  <?php endif; ?>
                  <span><?= e($r['name']) ?></span>
                </div>
              </td>
              <td><?= number_format((float)$r['avg_percentage'], 2) ?>%</td>
              <td><?= number_format((float)$r['accuracy'], 2) ?>% (<?= (int)$r['total_correct'] ?>/<?= (int)$r['total_questions'] ?>)</td>
              <td><?= (int)$r['tests_count'] ?></td>
              <td><?= e(sprintf('%02d:%02d', (int) floor(((int)$r['avg_time']) / 60), ((int)$r['avg_time']) % 60)) ?></td>
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
