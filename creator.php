<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tests_helper.php';
require_once __DIR__ . '/includes/lectures_helper.php';
require_creator();

$pdo = db();
$userId = (int) current_user()['id'];

function creator_private_note_dir(): string {
    $dir = rtrim(NOTE_PRIVATE_DIR, '/\\');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function pdf_unescape_text(string $text): string {
    $text = str_replace(['\\\\', '\(', '\)'], ['\\', '(', ')'], $text);
    $text = preg_replace_callback('/\\\\([0-7]{1,3})/', static function (array $m): string {
        return chr(octdec($m[1]));
    }, $text) ?? $text;
    return $text;
}

function extract_text_from_pdf_streams(string $pdfPath): string {
    $content = @file_get_contents($pdfPath);
    if (!is_string($content) || $content === '') {
        return '';
    }

    $textOut = [];
    if (!preg_match_all('/<<(.*?)>>\s*stream\s*(.*?)\s*endstream/s', $content, $matches, PREG_SET_ORDER)) {
        return '';
    }

    foreach ($matches as $m) {
        $dict = (string) ($m[1] ?? '');
        $stream = (string) ($m[2] ?? '');
        $decoded = $stream;

        if (stripos($dict, 'FlateDecode') !== false) {
            $decodedTry = @gzuncompress($stream);
            if (!is_string($decodedTry)) {
                $decodedTry = @gzinflate($stream);
            }
            if (!is_string($decodedTry) && strlen($stream) > 2) {
                $decodedTry = @gzinflate(substr($stream, 2));
            }
            if (is_string($decodedTry)) {
                $decoded = $decodedTry;
            }
        }

        if (strpos($decoded, 'Tj') === false && strpos($decoded, 'TJ') === false) {
            continue;
        }

        if (preg_match_all('/\(([^()]*(?:\\\\.[^()]*)*)\)\s*Tj/s', $decoded, $tjs)) {
            foreach ($tjs[1] as $piece) {
                $textOut[] = pdf_unescape_text((string) $piece);
            }
        }

        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $decoded, $tja)) {
            foreach ($tja[1] as $arr) {
                if (preg_match_all('/\(([^()]*(?:\\\\.[^()]*)*)\)/s', (string) $arr, $arrTxt)) {
                    foreach ($arrTxt[1] as $piece) {
                        $textOut[] = pdf_unescape_text((string) $piece);
                    }
                }
            }
        }
    }

    return trim(implode("\n", $textOut));
}

function extract_text_from_pdf(string $pdfPath, ?string &$error = null): string {
    $candidates = [];
    if (defined('PDFTOTEXT_BIN') && (string) PDFTOTEXT_BIN !== '') {
        $candidates[] = (string) PDFTOTEXT_BIN;
    }
    $candidates[] = 'pdftotext';
    $candidates[] = 'C:\\Program Files\\poppler\\Library\\bin\\pdftotext.exe';
    $candidates[] = 'C:\\Program Files (x86)\\poppler\\Library\\bin\\pdftotext.exe';
    $candidates[] = 'C:\\poppler\\Library\\bin\\pdftotext.exe';

    $tried = [];
    if (function_exists('shell_exec')) {
        foreach ($candidates as $bin) {
            $tried[] = $bin;
            $cmd = escapeshellarg($bin) . ' -layout ' . escapeshellarg($pdfPath) . ' -';
            $out = @shell_exec($cmd . ' 2>&1');
            if (is_string($out) && trim($out) !== '' && stripos($out, 'not recognized') === false && stripos($out, 'No such file') === false) {
                return $out;
            }
        }
    }

    $fallback = extract_text_from_pdf_streams($pdfPath);
    if ($fallback !== '') {
        return $fallback;
    }

    $error = 'Unable to extract text from PDF. Tried pdftotext and internal fallback parser. Set PDFTOTEXT_BIN in config.php to a valid pdftotext.exe path.';
    return '';
}

function parse_mcqs_from_text(string $rawText): array {
    $text = str_replace(["\r\n", "\r"], "\n", $rawText);
    $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;

    preg_match_all('/(?ms)^\s*(\d+)[\).\s-]+(.*?)(?=^\s*\d+[\).\s-]+|\z)/', $text, $qMatches, PREG_SET_ORDER);
    $items = [];

    foreach ($qMatches as $m) {
        $block = trim((string) $m[2]);
        if ($block === '') {
            continue;
        }

        preg_match_all('/(?ms)^\s*([A-D])[)\].:-]\s*(.*?)(?=^\s*[A-D][)\].:-]|\n\s*(?:Answer|Ans)\s*[:\-]|\z)/i', $block, $optMatches, PREG_SET_ORDER);
        if (count($optMatches) < 4) {
            continue;
        }

        $firstOptPos = null;
        if (preg_match('/(?m)^\s*[A-D][)\].:-]\s*/', $block, $firstOpt, PREG_OFFSET_CAPTURE)) {
            $firstOptPos = (int) $firstOpt[0][1];
        }
        if ($firstOptPos === null) {
            continue;
        }

        $questionText = trim(substr($block, 0, $firstOptPos));
        if ($questionText === '') {
            continue;
        }

        $opts = ['A' => '', 'B' => '', 'C' => '', 'D' => ''];
        foreach ($optMatches as $om) {
            $label = strtoupper(trim((string) $om[1]));
            if (!isset($opts[$label])) {
                continue;
            }
            $opts[$label] = trim((string) $om[2]);
        }

        if ($opts['A'] === '' || $opts['B'] === '' || $opts['C'] === '' || $opts['D'] === '') {
            continue;
        }

        $ans = '';
        if (preg_match('/(?mi)^\s*(?:Answer|Ans)\s*[:\-]?\s*([A-D])\b/', $block, $ansMatch)) {
            $ans = strtoupper((string) $ansMatch[1]);
        } elseif (preg_match('/(?mi)^\s*(?:Answer|Ans)\s*[:\-]?\s*([1-4])\b/', $block, $ansNumMatch)) {
            $map = ['1' => 'A', '2' => 'B', '3' => 'C', '4' => 'D'];
            $ans = $map[(string) $ansNumMatch[1]] ?? '';
        }

        if (!in_array($ans, ['A', 'B', 'C', 'D'], true)) {
            continue;
        }

        $items[] = [
            'q' => substr($questionText, 0, 4000),
            'a' => substr($opts['A'], 0, 500),
            'b' => substr($opts['B'], 0, 500),
            'c' => substr($opts['C'], 0, 500),
            'd' => substr($opts['D'], 0, 500),
            'ans' => $ans,
            'exp' => null,
        ];
    }

    return $items;
}

ensure_test_system_tables($pdo);
ensure_lecture_system_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'update') {
        $noteId = (int) ($_POST['note_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $subject = trim($_POST['subject'] ?? 'General');
        $price = max(0, (float) ($_POST['price'] ?? 0));

        if ($noteId <= 0 || $title === '') {
            set_flash('danger', 'Invalid note details.');
            redirect('creator.php');
        }

        $ownStmt = $pdo->prepare('SELECT id FROM notes WHERE id = ? AND uploaded_by = ? LIMIT 1');
        $ownStmt->execute([$noteId, $userId]);
        if (!$ownStmt->fetch()) {
            set_flash('danger', 'You can only edit your own notes.');
            redirect('creator.php');
        }

        $upd = $pdo->prepare('UPDATE notes SET title = ?, description = ?, subject = ?, price = ? WHERE id = ?');
        $upd->execute([$title, $description, $subject, $price, $noteId]);
        set_flash('success', 'Note updated successfully.');
        redirect('creator.php?edit=' . $noteId);
    }

    if ($action === 'create_playlist') {
        $playlistTitle = trim((string) ($_POST['playlist_title'] ?? ''));
        $playlistDescription = trim((string) ($_POST['playlist_description'] ?? ''));

        if ($playlistTitle === '') {
            set_flash('danger', 'Playlist title is required.');
            redirect('creator.php#lecture-tools');
        }

        $stmt = $pdo->prepare('INSERT INTO lecture_playlists (title, description, created_by) VALUES (?, ?, ?)');
        $stmt->execute([$playlistTitle, $playlistDescription !== '' ? $playlistDescription : null, $userId]);

        set_flash('success', 'Playlist created successfully.');
        redirect('creator.php#lecture-tools');
    }

    if ($action === 'create_lecture') {
        $lectureTitle = trim((string) ($_POST['lecture_title'] ?? ''));
        $lectureDescription = trim((string) ($_POST['lecture_description'] ?? ''));
        $lectureSubject = trim((string) ($_POST['lecture_subject'] ?? 'General'));
        $youtubeUrl = trim((string) ($_POST['youtube_url'] ?? ''));
        $playlistId = (int) ($_POST['playlist_id'] ?? 0);

        if ($lectureTitle === '') {
            set_flash('danger', 'Lecture title is required.');
            redirect('creator.php#lecture-tools');
        }

        $youtubeVideoId = extract_youtube_video_id($youtubeUrl);
        if ($youtubeVideoId === null) {
            set_flash('danger', 'Please enter a valid YouTube video link.');
            redirect('creator.php#lecture-tools');
        }

        $playlistValue = null;
        if ($playlistId > 0) {
            $playlistStmt = $pdo->prepare('SELECT id FROM lecture_playlists WHERE id = ? AND created_by = ? LIMIT 1');
            $playlistStmt->execute([$playlistId, $userId]);
            if (!$playlistStmt->fetch()) {
                set_flash('danger', 'Invalid playlist selected.');
                redirect('creator.php#lecture-tools');
            }
            $playlistValue = $playlistId;
        }

        $stmt = $pdo->prepare('INSERT INTO lectures (playlist_id, title, description, subject, source_type, video_path, youtube_url, youtube_video_id, thumbnail_path, uploaded_by)
            VALUES (?, ?, ?, ?, "youtube", NULL, ?, ?, ?, ?)');
        $stmt->execute([
            $playlistValue,
            $lectureTitle,
            $lectureDescription !== '' ? $lectureDescription : null,
            $lectureSubject !== '' ? $lectureSubject : 'General',
            $youtubeUrl,
            $youtubeVideoId,
            youtube_thumbnail_url($youtubeVideoId),
            $userId,
        ]);

        set_flash('success', 'YouTube lecture added successfully.');
        redirect('creator.php#lecture-tools');
    }

    if ($action === 'import_test_pdf') {
        $testTitle = trim((string) ($_POST['test_title_pdf'] ?? ''));
        $testSubject = trim((string) ($_POST['test_subject_pdf'] ?? 'General'));
        $marksPerQuestion = (float) ($_POST['marks_per_question_pdf'] ?? 1);

        if ($testTitle === '') {
            set_flash('danger', 'Test title is required for PDF import.');
            redirect('creator.php');
        }

        if ($marksPerQuestion <= 0) {
            set_flash('danger', 'Marks per question must be greater than 0.');
            redirect('creator.php');
        }

        if (empty($_FILES['mcq_pdf']['name']) || (int)($_FILES['mcq_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            set_flash('danger', 'Please upload a valid MCQ PDF file.');
            redirect('creator.php');
        }

        $tmpPath = (string) $_FILES['mcq_pdf']['tmp_name'];
        $mime = mime_content_type($tmpPath) ?: '';
        if ($mime !== 'application/pdf') {
            set_flash('danger', 'Only PDF file is allowed for MCQ import.');
            redirect('creator.php');
        }

        if ((int)($_FILES['mcq_pdf']['size'] ?? 0) > 8 * 1024 * 1024) {
            set_flash('danger', 'PDF file is too large. Max allowed size is 8MB.');
            redirect('creator.php');
        }

        $pdfTmpName = tempnam(sys_get_temp_dir(), 'mcqpdf_');
        if (!$pdfTmpName || !move_uploaded_file($tmpPath, $pdfTmpName)) {
            set_flash('danger', 'Unable to process uploaded PDF.');
            redirect('creator.php');
        }

        $extractError = null;
        $text = extract_text_from_pdf($pdfTmpName, $extractError);
        @unlink($pdfTmpName);

        if ($text === '') {
            set_flash('danger', $extractError ?: 'Unable to read PDF content.');
            redirect('creator.php');
        }

        $rows = parse_mcqs_from_text($text);
        if (count($rows) < 1) {
            set_flash('danger', 'No valid MCQ pattern found in PDF. Use format: 1. Question, A) B) C) D), Answer: A');
            redirect('creator.php');
        }

        $questionCount = count($rows);
        $totalMarks = round($questionCount * $marksPerQuestion, 2);

        $pdo->beginTransaction();
        try {
            $testIns = $pdo->prepare('INSERT INTO test_series (created_by, title, subject, total_questions, marks_per_question, total_marks, status) VALUES (?, ?, ?, ?, ?, ?, "published")');
            $testIns->execute([$userId, $testTitle, ($testSubject !== '' ? $testSubject : 'General'), $questionCount, $marksPerQuestion, $totalMarks]);
            $testId = (int) $pdo->lastInsertId();

            $qIns = $pdo->prepare('INSERT INTO test_questions (test_id, question_order, question_text, option_a, option_b, option_c, option_d, correct_option, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($rows as $idx => $row) {
                $qIns->execute([
                    $testId,
                    $idx + 1,
                    $row['q'],
                    $row['a'],
                    $row['b'],
                    $row['c'],
                    $row['d'],
                    $row['ans'],
                    $row['exp'],
                ]);
            }

            $pdo->commit();
            set_flash('success', 'PDF imported successfully. ' . $questionCount . ' questions added.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('danger', 'Unable to import PDF test. Please try again.');
        }

        redirect('creator.php');
    }

    if ($action === 'import_test_text') {
        $testTitle = trim((string) ($_POST['test_title_text'] ?? ''));
        $testSubject = trim((string) ($_POST['test_subject_text'] ?? 'General'));
        $marksPerQuestion = (float) ($_POST['marks_per_question_text'] ?? 1);
        $mcqText = trim((string) ($_POST['mcq_text'] ?? ''));

        if ($testTitle === '') {
            set_flash('danger', 'Test title is required for text import.');
            redirect('creator.php');
        }

        if ($marksPerQuestion <= 0) {
            set_flash('danger', 'Marks per question must be greater than 0.');
            redirect('creator.php');
        }

        if ($mcqText === '') {
            set_flash('danger', 'Paste MCQ text to import.');
            redirect('creator.php');
        }

        $rows = parse_mcqs_from_text($mcqText);
        if (count($rows) < 1) {
            set_flash('danger', 'No valid MCQ pattern found in pasted text. Use format: 1. Question ... A) ... B) ... C) ... D) ... Answer: A');
            redirect('creator.php');
        }

        $questionCount = count($rows);
        $totalMarks = round($questionCount * $marksPerQuestion, 2);

        $pdo->beginTransaction();
        try {
            $testIns = $pdo->prepare('INSERT INTO test_series (created_by, title, subject, total_questions, marks_per_question, total_marks, status) VALUES (?, ?, ?, ?, ?, ?, "published")');
            $testIns->execute([$userId, $testTitle, ($testSubject !== '' ? $testSubject : 'General'), $questionCount, $marksPerQuestion, $totalMarks]);
            $testId = (int) $pdo->lastInsertId();

            $qIns = $pdo->prepare('INSERT INTO test_questions (test_id, question_order, question_text, option_a, option_b, option_c, option_d, correct_option, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($rows as $idx => $row) {
                $qIns->execute([
                    $testId,
                    $idx + 1,
                    $row['q'],
                    $row['a'],
                    $row['b'],
                    $row['c'],
                    $row['d'],
                    $row['ans'],
                    $row['exp'],
                ]);
            }

            $pdo->commit();
            set_flash('success', 'Text imported successfully. ' . $questionCount . ' questions added.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('danger', 'Unable to import text test. Please try again.');
        }

        redirect('creator.php');
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $subject = trim($_POST['subject'] ?? 'General');
    $type = $_POST['type'] ?? 'free';
    $price = ($type === 'paid') ? max(0, (float) ($_POST['price'] ?? 0)) : 0.00;
    $filePath = '';

    if ($title === '') {
        set_flash('danger', 'Title is required.');
        redirect('creator.php');
    }

    if (!empty($_FILES['note_file']['name']) && $_FILES['note_file']['error'] === UPLOAD_ERR_OK) {
        $tmpPath = $_FILES['note_file']['tmp_name'];
        if ((int)($_FILES['note_file']['size'] ?? 0) > 25 * 1024 * 1024) {
            set_flash('danger', 'Note file is too large. Max 25MB.');
            redirect('creator.php');
        }
        $mime = mime_content_type($tmpPath) ?: '';
        $allowed = [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt',
            'application/zip' => 'zip',
            'application/x-zip-compressed' => 'zip',
        ];

        if (!isset($allowed[$mime])) {
            set_flash('danger', 'Unsupported file type.');
            redirect('creator.php');
        }
        $ext = strtolower(pathinfo((string)($_FILES['note_file']['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext !== '' && $ext !== $allowed[$mime]) {
            set_flash('danger', 'File extension does not match actual file type.');
            redirect('creator.php');
        }

        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '-', basename($_FILES['note_file']['name']));
        $storedName = 'note-' . time() . '-' . bin2hex(random_bytes(4)) . '-' . $safeBase;
        $privateTarget = creator_private_note_dir() . DIRECTORY_SEPARATOR . $storedName;

        if (!move_uploaded_file($tmpPath, $privateTarget)) {
            set_flash('danger', 'Unable to store file securely.');
            redirect('creator.php');
        }
        $filePath = $storedName;
    }

    $ins = $pdo->prepare('INSERT INTO notes (title, description, subject, price, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
    $ins->execute([$title, $description, $subject, $price, $filePath, $userId]);

    set_flash('success', 'Note uploaded successfully.');
    redirect('creator.php');
}

$editId = (int) ($_GET['edit'] ?? 0);
$editNote = null;
if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT id, title, description, subject, price FROM notes WHERE id = ? AND uploaded_by = ?');
    $editStmt->execute([$editId, $userId]);
    $editNote = $editStmt->fetch();
}

$statsStmt = $pdo->prepare('SELECT
    COUNT(DISTINCT n.id) AS total_notes,
    COALESCE(SUM(CASE WHEN p.payment_status = "paid" THEN p.amount ELSE 0 END), 0) AS total_earnings,
    COUNT(CASE WHEN p.payment_status = "paid" THEN 1 END) AS total_sales,
    COUNT(d.id) AS total_downloads
FROM notes n
LEFT JOIN purchases p ON p.note_id = n.id
LEFT JOIN downloads d ON d.note_id = n.id
WHERE n.uploaded_by = ?');
$statsStmt->execute([$userId]);
$stats = $statsStmt->fetch() ?: ['total_notes' => 0, 'total_earnings' => 0, 'total_sales' => 0, 'total_downloads' => 0];

$notesStmt = $pdo->prepare('SELECT n.id, n.title, n.subject, n.price, n.created_at,
       COALESCE(s.sales_count, 0) AS sales_count,
       COALESCE(s.earnings, 0) AS earnings,
       COALESCE(dl.download_count, 0) AS download_count
FROM notes n
LEFT JOIN (
  SELECT note_id, COUNT(*) AS sales_count, SUM(amount) AS earnings
  FROM purchases
  WHERE payment_status = "paid"
  GROUP BY note_id
) s ON s.note_id = n.id
LEFT JOIN (
  SELECT note_id, COUNT(*) AS download_count
  FROM downloads
  GROUP BY note_id
) dl ON dl.note_id = n.id
WHERE n.uploaded_by = ?
ORDER BY n.created_at DESC');
$notesStmt->execute([$userId]);
$myNotes = $notesStmt->fetchAll();

$recentSalesStmt = $pdo->prepare('SELECT n.title, u.name AS buyer, p.amount, p.purchased_at
FROM purchases p
JOIN notes n ON n.id = p.note_id
JOIN users u ON u.id = p.user_id
WHERE n.uploaded_by = ? AND p.payment_status = "paid"
ORDER BY p.purchased_at DESC
LIMIT 8');
$recentSalesStmt->execute([$userId]);
$recentSales = $recentSalesStmt->fetchAll();

$testsStmt = $pdo->prepare('SELECT t.id, t.title, t.subject, t.total_questions, t.marks_per_question, t.total_marks, t.status, t.created_at,
COALESCE(a.attempt_count, 0) AS attempt_count,
COALESCE(a.avg_score, 0) AS avg_score
FROM test_series t
LEFT JOIN (
  SELECT test_id, COUNT(*) AS attempt_count, AVG(score) AS avg_score
  FROM test_attempts
  GROUP BY test_id
) a ON a.test_id = t.id
WHERE t.created_by = ?
ORDER BY t.created_at DESC');
$testsStmt->execute([$userId]);
$myTests = $testsStmt->fetchAll();

$playlistStmt = $pdo->prepare('SELECT id, title, description, created_at
FROM lecture_playlists
WHERE created_by = ?
ORDER BY created_at DESC');
$playlistStmt->execute([$userId]);
$myPlaylists = $playlistStmt->fetchAll();

$lectureStatsStmt = $pdo->prepare('SELECT
    COUNT(*) AS total_lectures,
    COUNT(DISTINCT playlist_id) AS playlist_count
FROM lectures
WHERE uploaded_by = ?');
$lectureStatsStmt->execute([$userId]);
$lectureStats = $lectureStatsStmt->fetch() ?: ['total_lectures' => 0, 'playlist_count' => 0];

$myLecturesStmt = $pdo->prepare('SELECT l.id, l.title, l.subject, l.created_at, l.thumbnail_path, l.source_type, l.youtube_url, l.youtube_video_id,
    p.title AS playlist_title,
    COALESCE(lk.like_count, 0) AS like_count,
    COALESCE(cm.comment_count, 0) AS comment_count
FROM lectures l
LEFT JOIN lecture_playlists p ON p.id = l.playlist_id
LEFT JOIN (
    SELECT lecture_id, COUNT(*) AS like_count
    FROM lecture_likes
    GROUP BY lecture_id
) lk ON lk.lecture_id = l.id
LEFT JOIN (
    SELECT lecture_id, COUNT(*) AS comment_count
    FROM lecture_comments
    GROUP BY lecture_id
) cm ON cm.lecture_id = l.id
WHERE l.uploaded_by = ?
ORDER BY l.created_at DESC');
$myLecturesStmt->execute([$userId]);
$myLectures = $myLecturesStmt->fetchAll();

$pageTitle = 'NotesPro | Creator Dashboard';
$activePage = 'creator';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar mb-4">
  <h1 class="h3 fw-bold mb-1">Creator Dashboard</h1>
  <p class="text-secondary mb-0">Upload and manage your notes, and track your performance.</p>
</header>

<section class="row g-3 mb-4">
  <div class="col-12 col-md-3"><div class="metric-card"><p>Your Notes</p><h2><?= (int)$stats['total_notes'] ?></h2></div></div>
  <div class="col-12 col-md-3"><div class="metric-card"><p>Total Sales</p><h2><?= (int)$stats['total_sales'] ?></h2></div></div>
  <div class="col-12 col-md-3"><div class="metric-card"><p>Total Downloads</p><h2><?= (int)$stats['total_downloads'] ?></h2></div></div>
  <div class="col-12 col-md-3"><div class="metric-card"><p>Earnings</p><h2>$<?= number_format((float)$stats['total_earnings'], 2) ?></h2></div></div>
</section>

<section class="row g-3 mb-4">
  <div class="col-12 col-md-6"><div class="metric-card"><p>Your Lectures</p><h2><?= (int)$lectureStats['total_lectures'] ?></h2></div></div>
  <div class="col-12 col-md-6"><div class="metric-card"><p>Your Playlists</p><h2><?= (int)$lectureStats['playlist_count'] ?></h2></div></div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Upload New Note</h2>
    <form class="row g-3" method="post" action="creator.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-md-6">
        <label class="form-label">Title</label>
        <input class="form-control" name="title" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Type</label>
        <select class="form-select" name="type">
          <option value="free">Free</option>
          <option value="paid">Paid</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Price ($)</label>
        <input class="form-control" name="price" type="number" min="0" step="0.01" value="0">
      </div>
      <div class="col-md-6">
        <label class="form-label">Subject</label>
        <input class="form-control" name="subject" placeholder="Computer Science">
      </div>
      <div class="col-md-6">
        <label class="form-label">File</label>
        <input class="form-control" type="file" name="note_file">
      </div>
      <div class="col-12">
        <label class="form-label">Description</label>
        <textarea class="form-control" name="description" rows="3"></textarea>
      </div>
      <div class="col-12"><button class="btn btn-brand" type="submit">Upload Note</button></div>
    </form>
  </div>
</section>

<section class="card border-0 shadow-sm mb-4" id="lecture-tools">
  <div class="card-body p-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
      <div>
        <h2 class="h5 fw-bold mb-1">Lecture Studio</h2>
        <p class="text-secondary mb-0">Create playlists and add YouTube lecture links.</p>
      </div>
      <a class="btn btn-outline-secondary" href="lectures.php">Open Lectures</a>
    </div>
    <div class="row g-4">
      <div class="col-12 col-xl-4">
        <div class="lecture-panel h-100">
          <h3 class="h6 fw-bold mb-3">Create Playlist</h3>
          <form method="post" action="creator.php" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_playlist">
            <div class="col-12">
              <label class="form-label">Playlist Title</label>
              <input class="form-control" name="playlist_title" placeholder="Full Stack Bootcamp" required>
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea class="form-control" name="playlist_description" rows="4" placeholder="What this playlist covers"></textarea>
            </div>
            <div class="col-12">
              <button class="btn btn-brand w-100" type="submit">Create Playlist</button>
            </div>
          </form>
        </div>
      </div>
      <div class="col-12 col-xl-8">
        <div class="lecture-panel h-100">
          <h3 class="h6 fw-bold mb-3">Add YouTube Lecture</h3>
          <form method="post" action="creator.php" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_lecture">
            <div class="col-md-6">
              <label class="form-label">Title</label>
              <input class="form-control" name="lecture_title" placeholder="Introduction to Arrays" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Subject</label>
              <input class="form-control" name="lecture_subject" placeholder="Computer Science">
            </div>
            <div class="col-md-6">
              <label class="form-label">Playlist</label>
              <select class="form-select" name="playlist_id">
                <option value="0">No playlist</option>
                <?php foreach ($myPlaylists as $playlist): ?>
                  <option value="<?= (int) $playlist['id'] ?>"><?= e($playlist['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">YouTube Link</label>
              <input class="form-control" name="youtube_url" placeholder="https://www.youtube.com/watch?v=..." required>
            </div>
            <div class="col-12">
              <div class="lecture-hint">
                Add any standard YouTube watch, share, embed, or shorts link. The thumbnail will be pulled from YouTube automatically.
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea class="form-control" name="lecture_description" rows="4" placeholder="Add a short overview for students"></textarea>
            </div>
            <div class="col-12 d-flex justify-content-end">
              <button class="btn btn-brand" type="submit">Add Lecture</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Import Test From Text</h2>
    <p class="small text-secondary mb-3">Paste MCQs exactly like: <code>1. Question</code>, <code>A)</code>, <code>B)</code>, <code>C)</code>, <code>D)</code>, <code>Answer: A</code>.</p>
    <form class="row g-3" method="post" action="creator.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import_test_text">
      <div class="col-md-5">
        <label class="form-label">Test Title</label>
        <input class="form-control" name="test_title_text" placeholder="Science Revision Test" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Subject</label>
        <input class="form-control" name="test_subject_text" placeholder="Science">
      </div>
      <div class="col-md-2">
        <label class="form-label">Marks / Q</label>
        <input class="form-control" type="number" name="marks_per_question_text" min="0.25" step="0.25" value="1" required>
      </div>
      <div class="col-12">
        <label class="form-label">MCQ Text</label>
        <textarea class="form-control" name="mcq_text" rows="10" placeholder="1. Question text&#10;A) Option A&#10;B) Option B&#10;C) Option C&#10;D) Option D&#10;Answer: A" required></textarea>
      </div>
      <div class="col-12 d-flex justify-content-end">
        <button class="btn btn-brand" type="submit">Import Text and Create Test</button>
      </div>
    </form>
  </div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Your Playlists</h2>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Title</th><th>Description</th><th>Created</th></tr></thead>
        <tbody>
          <?php if (!$myPlaylists): ?>
            <tr><td colspan="3" class="text-secondary">No playlists created yet.</td></tr>
          <?php else: foreach ($myPlaylists as $playlist): ?>
            <tr>
              <td><?= e($playlist['title']) ?></td>
              <td><?= e($playlist['description'] ?: 'No description') ?></td>
              <td><?= e(date('M d, Y', strtotime($playlist['created_at']))) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Your Lectures</h2>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Lecture</th><th>Playlist</th><th>Engagement</th><th>Created</th><th></th></tr></thead>
        <tbody>
          <?php if (!$myLectures): ?>
            <tr><td colspan="5" class="text-secondary">No lectures uploaded yet.</td></tr>
          <?php else: foreach ($myLectures as $lecture): ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-3">
                  <?php if (!empty($lecture['thumbnail_path'])): ?>
                    <img src="<?= e($lecture['thumbnail_path']) ?>" alt="Lecture thumbnail" class="lecture-table-thumb">
                  <?php else: ?>
                    <div class="lecture-table-thumb lecture-table-thumb-fallback"><i class="bi bi-play-btn"></i></div>
                  <?php endif; ?>
                  <div>
                    <div class="fw-semibold"><?= e($lecture['title']) ?></div>
                    <small class="text-secondary"><?= e($lecture['subject'] ?: 'General') ?> · <?= e(($lecture['source_type'] ?? 'youtube') === 'youtube' ? 'YouTube' : 'Upload') ?></small>
                  </div>
                </div>
              </td>
              <td><?= e($lecture['playlist_title'] ?: 'Standalone') ?></td>
              <td><?= (int) $lecture['like_count'] ?> likes · <?= (int) $lecture['comment_count'] ?> comments</td>
              <td><?= e(date('M d, Y', strtotime($lecture['created_at']))) ?></td>
              <td><a class="btn btn-sm btn-outline-secondary" href="lectures.php?id=<?= (int) $lecture['id'] ?>">Watch</a></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Import Test From PDF</h2>
    <p class="small text-secondary mb-3">PDF should contain pattern like: <code>1. Question</code>, <code>A)</code> <code>B)</code> <code>C)</code> <code>D)</code>, <code>Answer: A</code>.</p>
    <form class="row g-3" method="post" action="creator.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import_test_pdf">
      <div class="col-md-5">
        <label class="form-label">Test Title</label>
        <input class="form-control" name="test_title_pdf" placeholder="Chemistry PDF Test" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Subject</label>
        <input class="form-control" name="test_subject_pdf" placeholder="Chemistry">
      </div>
      <div class="col-md-2">
        <label class="form-label">Marks / Q</label>
        <input class="form-control" type="number" name="marks_per_question_pdf" min="0.25" step="0.25" value="1" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">MCQ PDF</label>
        <input class="form-control" type="file" name="mcq_pdf" accept="application/pdf" required>
      </div>
      <div class="col-12 d-flex justify-content-end">
        <button class="btn btn-brand" type="submit">Import PDF and Create Test</button>
      </div>
    </form>
  </div>
</section>

<?php if ($editNote): ?>
<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="h5 fw-bold mb-0">Edit Your Note</h2>
      <a href="creator.php" class="btn btn-sm btn-outline-secondary">Close</a>
    </div>
    <form class="row g-3" method="post" action="creator.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="note_id" value="<?= (int)$editNote['id'] ?>">
      <div class="col-md-6">
        <label class="form-label">Title</label>
        <input class="form-control" name="title" value="<?= e($editNote['title']) ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Subject</label>
        <input class="form-control" name="subject" value="<?= e($editNote['subject']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Price ($)</label>
        <input class="form-control" name="price" type="number" min="0" step="0.01" value="<?= e((string)$editNote['price']) ?>">
      </div>
      <div class="col-12">
        <label class="form-label">Description</label>
        <textarea class="form-control" name="description" rows="3"><?= e($editNote['description']) ?></textarea>
      </div>
      <div class="col-12"><button class="btn btn-brand" type="submit">Save</button></div>
    </form>
  </div>
</section>
<?php endif; ?>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Your Test Series</h2>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Title</th><th>Questions</th><th>Total Marks</th><th>Attempts</th><th>Avg Score</th><th>Status</th><th>Created</th></tr></thead>
        <tbody>
          <?php if (!$myTests): ?>
            <tr><td colspan="7" class="text-secondary">No test series created yet.</td></tr>
          <?php else: foreach ($myTests as $t): ?>
            <tr>
              <td><?= e($t['title']) ?><br><small class="text-secondary"><?= e($t['subject'] ?: 'General') ?></small></td>
              <td><?= (int)$t['total_questions'] ?></td>
              <td><?= number_format((float)$t['total_marks'], 2) ?></td>
              <td><?= (int)$t['attempt_count'] ?></td>
              <td><?= number_format((float)$t['avg_score'], 2) ?></td>
              <td><span class="badge text-bg-success"><?= e(ucfirst($t['status'])) ?></span></td>
              <td><?= e(date('M d, Y', strtotime($t['created_at']))) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Your Notes</h2>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Title</th><th>Price</th><th>Sales</th><th>Downloads</th><th>Earnings</th><th></th></tr></thead>
        <tbody>
          <?php if (!$myNotes): ?>
            <tr><td colspan="6" class="text-secondary">No notes uploaded yet.</td></tr>
          <?php else: foreach ($myNotes as $n): ?>
            <tr>
              <td><?= e($n['title']) ?><br><small class="text-secondary"><?= e($n['subject'] ?: 'General') ?></small></td>
              <td><?= ((float)$n['price'] > 0) ? '$' . number_format((float)$n['price'], 2) : 'Free' ?></td>
              <td><?= (int)$n['sales_count'] ?></td>
              <td><?= (int)$n['download_count'] ?></td>
              <td>$<?= number_format((float)$n['earnings'], 2) ?></td>
              <td><a class="btn btn-sm btn-outline-secondary" href="creator.php?edit=<?= (int)$n['id'] ?>">Edit</a></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Recent Sales</h2>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Note</th><th>Buyer</th><th>Amount</th><th>Date</th></tr></thead>
        <tbody>
          <?php if (!$recentSales): ?>
            <tr><td colspan="4" class="text-secondary">No sales yet.</td></tr>
          <?php else: foreach ($recentSales as $sale): ?>
            <tr>
              <td><?= e($sale['title']) ?></td>
              <td><?= e($sale['buyer']) ?></td>
              <td>$<?= number_format((float)$sale['amount'], 2) ?></td>
              <td><?= e(date('M d, Y', strtotime($sale['purchased_at']))) ?></td>
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
