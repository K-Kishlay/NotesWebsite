<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tests_helper.php';
require_login();
verify_csrf_or_abort();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('tests.php');
}

$pdo = db();
ensure_test_system_tables($pdo);

$userId = (int) current_user()['id'];
$testId = (int) ($_POST['test_id'] ?? 0);
$answers = $_POST['answers'] ?? [];
$startedAtEpochPost = (int) ($_POST['started_at_epoch'] ?? 0);

if ($testId <= 0) {
    set_flash('danger', 'Invalid test submission.');
    redirect('tests.php');
}

$testStmt = $pdo->prepare('SELECT id, marks_per_question, total_marks, status FROM test_series WHERE id = ? LIMIT 1');
$testStmt->execute([$testId]);
$test = $testStmt->fetch();
if (!$test || $test['status'] !== 'published') {
    set_flash('danger', 'Test is not available.');
    redirect('tests.php');
}

$qStmt = $pdo->prepare('SELECT id, correct_option FROM test_questions WHERE test_id = ? ORDER BY question_order ASC, id ASC');
$qStmt->execute([$testId]);
$questions = $qStmt->fetchAll();
if (!$questions) {
    set_flash('danger', 'No questions found in this test.');
    redirect('tests.php');
}

$totalQuestions = count($questions);
$attempted = 0;
$correct = 0;
$allowedOptions = ['A', 'B', 'C', 'D'];
$answerRows = [];

foreach ($questions as $q) {
    $qid = (int) $q['id'];
    $selected = strtoupper(trim((string) ($answers[$qid] ?? '')));
    if ($selected !== '' && in_array($selected, $allowedOptions, true)) {
        $attempted++;
    } else {
        $selected = null;
    }
    $isCorrect = ($selected !== null && $selected === (string) $q['correct_option']) ? 1 : 0;
    if ($isCorrect === 1) {
        $correct++;
    }
    $answerRows[] = [
        'question_id' => $qid,
        'selected' => $selected,
        'is_correct' => $isCorrect,
    ];
}

$marksPerQuestion = (float) $test['marks_per_question'];
$score = round($correct * $marksPerQuestion, 2);
$totalMarks = (float) $test['total_marks'];
if ($totalMarks <= 0) {
    $totalMarks = round($totalQuestions * $marksPerQuestion, 2);
}
$percentage = $totalMarks > 0 ? round(($score / $totalMarks) * 100, 2) : 0;

$startedAtTs = 0;
if (isset($_SESSION['test_start_times']) && is_array($_SESSION['test_start_times']) && !empty($_SESSION['test_start_times'][$testId])) {
    $startedAtTs = (int) $_SESSION['test_start_times'][$testId];
    unset($_SESSION['test_start_times'][$testId]);
} elseif ($startedAtEpochPost > 0) {
    $startedAtTs = $startedAtEpochPost;
}

$nowTs = time();
if ($startedAtTs <= 0 || $startedAtTs > $nowTs) {
    $startedAtTs = $nowTs;
}
$timeTaken = max(1, $nowTs - $startedAtTs);
$startedAtStr = date('Y-m-d H:i:s', $startedAtTs);

$pdo->beginTransaction();
try {
    $attemptStmt = $pdo->prepare('INSERT INTO test_attempts (test_id, user_id, total_questions, attempted_questions, correct_answers, score, total_marks, percentage, started_at, time_taken_seconds)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $attemptStmt->execute([
        $testId,
        $userId,
        $totalQuestions,
        $attempted,
        $correct,
        $score,
        $totalMarks,
        $percentage,
        $startedAtStr,
        $timeTaken,
    ]);
    $attemptId = (int) $pdo->lastInsertId();

    $ansStmt = $pdo->prepare('INSERT INTO test_attempt_answers (attempt_id, question_id, selected_option, is_correct) VALUES (?, ?, ?, ?)');
    foreach ($answerRows as $row) {
        $ansStmt->execute([$attemptId, $row['question_id'], $row['selected'], $row['is_correct']]);
    }

    $pdo->commit();
    set_flash('success', 'Test submitted. Score: ' . number_format($score, 2) . '/' . number_format($totalMarks, 2));
    redirect('test_result.php?attempt=' . $attemptId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('danger', 'Unable to submit test. Please try again.');
    redirect('take_test.php?id=' . $testId);
}
