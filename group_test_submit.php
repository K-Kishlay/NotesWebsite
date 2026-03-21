<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/study_groups_helper.php';
require_login();
verify_csrf_or_abort();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('study_groups.php');
}

$pdo = db();
ensure_study_group_tables($pdo);

$userId = (int) current_user()['id'];
$testId = (int) ($_POST['test_id'] ?? 0);
$answers = $_POST['answers'] ?? [];
if ($testId <= 0) {
    set_flash('danger', 'Invalid test submission.');
    redirect('study_groups.php');
}

$testStmt = $pdo->prepare('SELECT id, group_id, marks_per_question, total_marks FROM study_group_tests WHERE id = ? LIMIT 1');
$testStmt->execute([$testId]);
$test = $testStmt->fetch();
if (!$test) {
    set_flash('danger', 'Test not found.');
    redirect('study_groups.php');
}
if (!is_group_member($pdo, (int) $test['group_id'], $userId)) {
    set_flash('danger', 'You are not a member of this group.');
    redirect('study_groups.php');
}

$qStmt = $pdo->prepare('SELECT id, correct_option FROM study_group_test_questions WHERE test_id = ? ORDER BY question_order ASC, id ASC');
$qStmt->execute([$testId]);
$questions = $qStmt->fetchAll();
if (!$questions) {
    set_flash('danger', 'No questions in test.');
    redirect('group.php?id=' . (int)$test['group_id']);
}

$totalQuestions = count($questions);
$correct = 0;
$allowed = ['A', 'B', 'C', 'D'];
foreach ($questions as $q) {
    $qid = (int) $q['id'];
    $sel = strtoupper(trim((string) ($answers[$qid] ?? '')));
    if (in_array($sel, $allowed, true) && $sel === (string) $q['correct_option']) {
        $correct++;
    }
}

$mpq = (float) $test['marks_per_question'];
$score = round($correct * $mpq, 2);
$totalMarks = (float) $test['total_marks'] > 0 ? (float) $test['total_marks'] : round($totalQuestions * $mpq, 2);
$percentage = $totalMarks > 0 ? round(($score / $totalMarks) * 100, 2) : 0.0;

$ins = $pdo->prepare('INSERT INTO study_group_test_attempts (test_id, user_id, total_questions, correct_answers, score, total_marks, percentage) VALUES (?, ?, ?, ?, ?, ?, ?)');
$ins->execute([$testId, $userId, $totalQuestions, $correct, $score, $totalMarks, $percentage]);
$attemptId = (int) $pdo->lastInsertId();

set_flash('success', 'Group test submitted.');
redirect('group_test_result.php?attempt=' . $attemptId);
