<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/study_groups_helper.php';
require_login();

$pdo = db();
ensure_study_group_tables($pdo);

$userId = (int) current_user()['id'];
$groupId = (int) ($_GET['id'] ?? $_POST['group_id'] ?? 0);
if ($groupId <= 0) {
    set_flash('danger', 'Invalid group.');
    redirect('study_groups.php');
}

if (!is_group_member($pdo, $groupId, $userId)) {
    set_flash('danger', 'You are not a member of this group.');
    redirect('study_groups.php');
}

$myRoleStmt = $pdo->prepare('SELECT role FROM study_group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
$myRoleStmt->execute([$groupId, $userId]);
$myRole = (string) ($myRoleStmt->fetchColumn() ?: 'member');
$isSiteAdmin = ((string) (current_user()['role'] ?? '') === 'admin');
$canCustomizeGroup = ($myRole === 'owner' || $isSiteAdmin);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    if ($action === 'post_message') {
        $message = trim((string) ($_POST['message'] ?? ''));
        $imagePath = null;

        if (!empty($_FILES['chat_image']['name']) && (int)($_FILES['chat_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpPath = (string) $_FILES['chat_image']['tmp_name'];
            if ((int)($_FILES['chat_image']['size'] ?? 0) > 4 * 1024 * 1024) {
                set_flash('danger', 'Image too large. Max 4MB.');
                redirect('group.php?id=' . $groupId);
            }
            $mime = mime_content_type($tmpPath) ?: '';
            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
            if (!isset($allowed[$mime]) || @getimagesize($tmpPath) === false) {
                set_flash('danger', 'Only valid JPG/PNG/WEBP/GIF image allowed.');
                redirect('group.php?id=' . $groupId);
            }
            $dir = __DIR__ . '/uploads/group_chat';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $name = 'group-' . $groupId . '-' . $userId . '-' . time() . '.' . $allowed[$mime];
            $target = $dir . DIRECTORY_SEPARATOR . $name;
            if (!move_uploaded_file($tmpPath, $target)) {
                set_flash('danger', 'Unable to upload image.');
                redirect('group.php?id=' . $groupId);
            }
            $imagePath = 'uploads/group_chat/' . $name;
        }

        if ($message === '' && $imagePath === null) {
            set_flash('danger', 'Write a message or attach image.');
            redirect('group.php?id=' . $groupId);
        }

        $ins = $pdo->prepare('INSERT INTO study_group_messages (group_id, user_id, message, image_path) VALUES (?, ?, ?, ?)');
        $ins->execute([$groupId, $userId, $message !== '' ? $message : null, $imagePath]);
        redirect('group.php?id=' . $groupId);
    }

    if ($action === 'create_group_test') {
        $title = trim((string) ($_POST['test_title'] ?? ''));
        $instructions = trim((string) ($_POST['instructions'] ?? ''));
        $marksPerQuestion = (float) ($_POST['marks_per_question'] ?? 1);
        $mcqText = trim((string) ($_POST['mcq_text'] ?? ''));

        if ($title === '' || $mcqText === '') {
            set_flash('danger', 'Test title and MCQ text are required.');
            redirect('group.php?id=' . $groupId);
        }
        if ($marksPerQuestion <= 0) {
            set_flash('danger', 'Marks per question must be > 0.');
            redirect('group.php?id=' . $groupId);
        }

        $rows = parse_group_mcq_text($mcqText);
        if (!$rows) {
            set_flash('danger', 'No valid MCQ found. Use format with options A-D and Answer.');
            redirect('group.php?id=' . $groupId);
        }

        $questionCount = count($rows);
        $totalMarks = round($questionCount * $marksPerQuestion, 2);

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT INTO study_group_tests (group_id, title, instructions, total_questions, marks_per_question, total_marks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$groupId, $title, $instructions !== '' ? $instructions : null, $questionCount, $marksPerQuestion, $totalMarks, $userId]);
            $testId = (int) $pdo->lastInsertId();

            $qIns = $pdo->prepare('INSERT INTO study_group_test_questions (test_id, question_order, question_text, option_a, option_b, option_c, option_d, correct_option) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($rows as $idx => $r) {
                $qIns->execute([$testId, $idx + 1, $r['q'], $r['a'], $r['b'], $r['c'], $r['d'], $r['ans']]);
            }
            $pdo->commit();
            set_flash('success', 'Group test created.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('danger', 'Unable to create group test.');
        }
        redirect('group.php?id=' . $groupId);
    }

    if ($action === 'update_group_customization') {
        if (!$canCustomizeGroup) {
            set_flash('danger', 'Only group owner/admin can customize this group.');
            redirect('group.php?id=' . $groupId);
        }

        $name = trim((string) ($_POST['group_name'] ?? ''));
        $description = trim((string) ($_POST['group_description'] ?? ''));
        $accentColor = trim((string) ($_POST['accent_color'] ?? ''));

        if ($name === '') {
            set_flash('danger', 'Group name is required.');
            redirect('group.php?id=' . $groupId);
        }
        if ($accentColor !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $accentColor)) {
            set_flash('danger', 'Invalid accent color.');
            redirect('group.php?id=' . $groupId);
        }
        if (strlen($name) > 160) {
            $name = substr($name, 0, 160);
        }
        if (strlen($description) > 3000) {
            $description = substr($description, 0, 3000);
        }

        $coverImage = null;
        $chatWallpaper = null;

        if (!empty($_FILES['cover_image']['name']) && (int)($_FILES['cover_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpPath = (string) $_FILES['cover_image']['tmp_name'];
            if ((int)($_FILES['cover_image']['size'] ?? 0) > 5 * 1024 * 1024) {
                set_flash('danger', 'Cover image max size is 5MB.');
                redirect('group.php?id=' . $groupId);
            }
            $mime = mime_content_type($tmpPath) ?: '';
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime]) || @getimagesize($tmpPath) === false) {
                set_flash('danger', 'Cover image must be JPG/PNG/WEBP.');
                redirect('group.php?id=' . $groupId);
            }
            $dir = __DIR__ . '/uploads/group_custom';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $nameFile = 'cover-' . $groupId . '-' . time() . '.' . $allowed[$mime];
            $target = $dir . DIRECTORY_SEPARATOR . $nameFile;
            if (!move_uploaded_file($tmpPath, $target)) {
                set_flash('danger', 'Unable to upload cover image.');
                redirect('group.php?id=' . $groupId);
            }
            $coverImage = 'uploads/group_custom/' . $nameFile;
        }

        if (!empty($_FILES['chat_wallpaper']['name']) && (int)($_FILES['chat_wallpaper']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpPath = (string) $_FILES['chat_wallpaper']['tmp_name'];
            if ((int)($_FILES['chat_wallpaper']['size'] ?? 0) > 5 * 1024 * 1024) {
                set_flash('danger', 'Chat wallpaper max size is 5MB.');
                redirect('group.php?id=' . $groupId);
            }
            $mime = mime_content_type($tmpPath) ?: '';
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime]) || @getimagesize($tmpPath) === false) {
                set_flash('danger', 'Chat wallpaper must be JPG/PNG/WEBP.');
                redirect('group.php?id=' . $groupId);
            }
            $dir = __DIR__ . '/uploads/group_custom';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $nameFile = 'wall-' . $groupId . '-' . time() . '.' . $allowed[$mime];
            $target = $dir . DIRECTORY_SEPARATOR . $nameFile;
            if (!move_uploaded_file($tmpPath, $target)) {
                set_flash('danger', 'Unable to upload chat wallpaper.');
                redirect('group.php?id=' . $groupId);
            }
            $chatWallpaper = 'uploads/group_custom/' . $nameFile;
        }

        $updates = [
            'name = :name',
            'description = :description',
            'accent_color = :accent_color',
        ];
        $params = [
            ':name' => $name,
            ':description' => ($description !== '' ? $description : null),
            ':accent_color' => ($accentColor !== '' ? $accentColor : null),
            ':id' => $groupId,
        ];
        if ($coverImage !== null) {
            $updates[] = 'cover_image = :cover_image';
            $params[':cover_image'] = $coverImage;
        }
        if ($chatWallpaper !== null) {
            $updates[] = 'chat_wallpaper = :chat_wallpaper';
            $params[':chat_wallpaper'] = $chatWallpaper;
        }

        $sql = 'UPDATE study_groups SET ' . implode(', ', $updates) . ' WHERE id = :id LIMIT 1';
        $upd = $pdo->prepare($sql);
        $upd->execute($params);
        set_flash('success', 'Group customization updated.');
        redirect('group.php?id=' . $groupId);
    }
}

$groupStmt = $pdo->prepare('SELECT g.id, g.name, g.description, g.join_code, g.accent_color, g.cover_image, g.chat_wallpaper, g.created_at, u.name AS owner_name
FROM study_groups g
JOIN users u ON u.id = g.created_by
WHERE g.id = ? LIMIT 1');
$groupStmt->execute([$groupId]);
$group = $groupStmt->fetch();
if (!$group) {
    set_flash('danger', 'Group not found.');
    redirect('study_groups.php');
}

$membersStmt = $pdo->prepare('SELECT m.role, u.name, u.avatar
FROM study_group_members m
JOIN users u ON u.id = m.user_id
WHERE m.group_id = ?
ORDER BY CASE WHEN m.role = "owner" THEN 0 ELSE 1 END, m.joined_at ASC');
$membersStmt->execute([$groupId]);
$members = $membersStmt->fetchAll();

$msgStmt = $pdo->prepare('SELECT msg.id, msg.message, msg.image_path, msg.created_at, u.name, u.avatar, msg.user_id
FROM study_group_messages msg
JOIN users u ON u.id = msg.user_id
WHERE msg.group_id = ?
ORDER BY msg.created_at ASC
LIMIT 120');
$msgStmt->execute([$groupId]);
$messages = $msgStmt->fetchAll();

$testsStmt = $pdo->prepare('SELECT t.id, t.title, t.instructions, t.total_questions, t.marks_per_question, t.total_marks, t.created_at, u.name AS creator_name,
COALESCE(a.attempt_count, 0) AS attempt_count,
my.latest_attempt_id, my.latest_percentage
FROM study_group_tests t
JOIN users u ON u.id = t.created_by
LEFT JOIN (
  SELECT test_id, COUNT(*) AS attempt_count
  FROM study_group_test_attempts
  GROUP BY test_id
) a ON a.test_id = t.id
LEFT JOIN (
  SELECT x.test_id,
         SUBSTRING_INDEX(GROUP_CONCAT(x.id ORDER BY x.submitted_at DESC), ",", 1) AS latest_attempt_id,
         SUBSTRING_INDEX(GROUP_CONCAT(x.percentage ORDER BY x.submitted_at DESC), ",", 1) AS latest_percentage
  FROM study_group_test_attempts x
  WHERE x.user_id = ?
  GROUP BY x.test_id
) my ON my.test_id = t.id
WHERE t.group_id = ?
ORDER BY t.created_at DESC');
$testsStmt->execute([$userId, $groupId]);
$tests = $testsStmt->fetchAll();

$rankStmt = $pdo->prepare('SELECT a.user_id, u.name, u.avatar, a.test_id, a.percentage, a.correct_answers, a.total_questions, a.submitted_at, a.id
FROM study_group_test_attempts a
JOIN study_group_tests t ON t.id = a.test_id
JOIN users u ON u.id = a.user_id
WHERE t.group_id = ?
ORDER BY a.test_id ASC, a.user_id ASC, a.percentage DESC, a.correct_answers DESC, a.submitted_at ASC, a.id ASC');
$rankStmt->execute([$groupId]);
$rankRows = $rankStmt->fetchAll();

$bestByUserTest = [];
foreach ($rankRows as $row) {
    $key = (int) $row['user_id'] . ':' . (int) $row['test_id'];
    if (!isset($bestByUserTest[$key])) {
        $bestByUserTest[$key] = $row;
    }
}

$groupRankingMap = [];
foreach ($bestByUserTest as $row) {
    $uid = (int) $row['user_id'];
    if (!isset($groupRankingMap[$uid])) {
        $groupRankingMap[$uid] = [
            'user_id' => $uid,
            'name' => $row['name'],
            'avatar' => $row['avatar'],
            'tests_count' => 0,
            'sum_percentage' => 0.0,
            'total_correct' => 0,
            'total_questions' => 0,
        ];
    }
    $groupRankingMap[$uid]['tests_count']++;
    $groupRankingMap[$uid]['sum_percentage'] += (float) $row['percentage'];
    $groupRankingMap[$uid]['total_correct'] += (int) $row['correct_answers'];
    $groupRankingMap[$uid]['total_questions'] += (int) $row['total_questions'];
}

$groupRanking = [];
foreach ($groupRankingMap as $entry) {
    $testsCount = max(1, (int) $entry['tests_count']);
    $avgPercentage = $entry['sum_percentage'] / $testsCount;
    $accuracy = $entry['total_questions'] > 0 ? ($entry['total_correct'] / $entry['total_questions']) * 100 : 0;
    $groupRanking[] = [
        'user_id' => $entry['user_id'],
        'name' => $entry['name'],
        'avatar' => $entry['avatar'],
        'tests_count' => $testsCount,
        'avg_percentage' => $avgPercentage,
        'accuracy' => $accuracy,
        'total_correct' => $entry['total_correct'],
        'total_questions' => $entry['total_questions'],
    ];
}
usort($groupRanking, static function (array $a, array $b): int {
    if ((float) $a['avg_percentage'] !== (float) $b['avg_percentage']) {
        return ((float) $a['avg_percentage'] < (float) $b['avg_percentage']) ? 1 : -1;
    }
    if ((float) $a['accuracy'] !== (float) $b['accuracy']) {
        return ((float) $a['accuracy'] < (float) $b['accuracy']) ? 1 : -1;
    }
    return strcmp((string) $a['name'], (string) $b['name']);
});
$groupRanking = array_slice($groupRanking, 0, 10);

$shareLink = 'study_groups.php?join=' . urlencode((string) $group['join_code']);
$groupAccent = (string) ($group['accent_color'] ?: '#2d6cdf');
$chatWallpaper = (string) ($group['chat_wallpaper'] ?: '');
$groupCover = (string) ($group['cover_image'] ?: '');
$messageCount = count($messages);
$testCount = count($tests);
$rankingCount = count($groupRanking);

$pageTitle = 'NotesPro | Group';
$activePage = 'study_groups';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="group-hero-pro mb-4" style="--group-accent: <?= e($groupAccent) ?>;">
  <div class="group-hero-overlay" <?= $groupCover !== '' ? 'style="background-image:url(\'' . e($groupCover) . '\');"' : '' ?>></div>
  <div class="group-hero-content d-flex flex-wrap justify-content-between align-items-end gap-3">
    <div>
      <h1 class="h3 fw-bold mb-1"><?= e($group['name']) ?></h1>
      <p class="mb-2 text-secondary"><?= e($group['description'] ?: 'No description.') ?></p>
      <div class="small text-secondary">
        Owner: <strong><?= e($group['owner_name']) ?></strong>
      </div>
    </div>
    <div class="small text-secondary group-hero-code">
      Join Code: <code><?= e($group['join_code']) ?></code><br>
      Share: <code><?= e($shareLink) ?></code>
    </div>
  </div>
</header>

<section class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <article class="group-stat-card">
      <span class="small text-secondary">Members</span>
      <h2 class="h4 fw-bold mb-0"><?= count($members) ?></h2>
    </article>
  </div>
  <div class="col-6 col-xl-3">
    <article class="group-stat-card">
      <span class="small text-secondary">Messages</span>
      <h2 class="h4 fw-bold mb-0"><?= $messageCount ?></h2>
    </article>
  </div>
  <div class="col-6 col-xl-3">
    <article class="group-stat-card">
      <span class="small text-secondary">Tests</span>
      <h2 class="h4 fw-bold mb-0"><?= $testCount ?></h2>
    </article>
  </div>
  <div class="col-6 col-xl-3">
    <article class="group-stat-card">
      <span class="small text-secondary">Ranking Active</span>
      <h2 class="h4 fw-bold mb-0"><?= $rankingCount ?></h2>
    </article>
  </div>
</section>

<nav class="group-section-slider mb-4" aria-label="Group section navigation">
  <a class="group-slider-link active" href="#group-chat-section" data-target="group-chat-section">
    <i class="bi bi-chat-left-text"></i> Chat
  </a>
  <a class="group-slider-link" href="#group-quiz-section" data-target="group-quiz-section">
    <i class="bi bi-ui-checks-grid"></i> Quiz
  </a>
  <a class="group-slider-link" href="#group-info-section" data-target="group-info-section">
    <i class="bi bi-people"></i> Group Info
  </a>
</nav>

<section class="row g-3 mb-4" id="group-chat-section">
  <div class="col-12 col-xl-8">
    <div class="card border-0 shadow-sm whatsapp-window">
      <div class="card-header whatsapp-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
          <span class="uploader-avatar uploader-avatar-fallback"><?= e(strtoupper(substr(trim((string)$group['name']), 0, 1))) ?></span>
          <div>
            <div class="fw-semibold"><?= e($group['name']) ?></div>
            <div class="small text-secondary"><?= count($members) ?> members</div>
          </div>
        </div>
        <div class="small text-secondary">Owner: <?= e($group['owner_name']) ?></div>
      </div>
      <div class="card-body whatsapp-body" id="groupChatBody" <?= $chatWallpaper !== '' ? 'style="background-image:url(\'' . e($chatWallpaper) . '\');"' : '' ?>>
        <?php if (!$messages): ?>
          <p class="text-secondary mb-0">No chat yet.</p>
        <?php else: foreach ($messages as $msg): ?>
          <?php $isMine = ((int)$msg['user_id'] === $userId); ?>
          <div class="chat-row <?= $isMine ? 'mine' : 'other' ?>">
            <article class="chat-bubble">
              <div class="d-flex align-items-center gap-2 mb-1">
                <?php if (!$isMine): ?>
                  <?php if (!empty($msg['avatar'])): ?>
                    <img src="<?= e($msg['avatar']) ?>" alt="Avatar" class="uploader-avatar">
                  <?php else: ?>
                    <span class="uploader-avatar uploader-avatar-fallback"><?= e(strtoupper(substr(trim((string)$msg['name']), 0, 1))) ?></span>
                  <?php endif; ?>
                <?php endif; ?>
                <strong class="small"><?= e($isMine ? 'You' : $msg['name']) ?></strong>
              </div>
              <?php if (!empty($msg['message'])): ?>
                <p class="mb-2"><?= nl2br(e($msg['message'])) ?></p>
              <?php endif; ?>
              <?php if (!empty($msg['image_path'])): ?>
                <img src="<?= e($msg['image_path']) ?>" alt="Shared image" class="img-fluid rounded border mb-2">
              <?php endif; ?>
              <div class="small text-secondary"><?= e(date('M d, h:i A', strtotime($msg['created_at']))) ?></div>
            </article>
          </div>
        <?php endforeach; endif; ?>
      </div>
      <div class="card-footer whatsapp-footer">
        <form method="post" action="group.php?id=<?= (int)$groupId ?>" enctype="multipart/form-data" class="row g-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="post_message">
          <input type="hidden" name="group_id" value="<?= (int)$groupId ?>">
          <div class="col-12 col-md-7">
            <input class="form-control" name="message" maxlength="2000" placeholder="Type message...">
          </div>
          <div class="col-8 col-md-3">
            <input class="form-control" type="file" name="chat_image" accept="image/jpeg,image/png,image/webp,image/gif">
          </div>
          <div class="col-4 col-md-2 d-grid">
            <button class="btn btn-brand" type="submit">Send</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-4" id="group-info-section">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body p-3">
        <h2 class="h6 fw-bold mb-3">Group Ranking</h2>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>#</th><th>Student</th><th>Avg %</th></tr></thead>
            <tbody>
              <?php if (!$groupRanking): ?>
                <tr><td colspan="3" class="text-secondary">No test attempts yet.</td></tr>
              <?php else: foreach ($groupRanking as $idx => $r): ?>
                <tr>
                  <td><?= $idx + 1 ?></td>
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
                  <td><?= number_format((float)$r['avg_percentage'], 1) ?>%</td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <?php if ($canCustomizeGroup): ?>
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-3">
          <h2 class="h6 fw-bold mb-3">Group Customization</h2>
          <form method="post" action="group.php?id=<?= (int)$groupId ?>" enctype="multipart/form-data" class="row g-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_group_customization">
            <input type="hidden" name="group_id" value="<?= (int)$groupId ?>">
            <div class="col-12">
              <input class="form-control" name="group_name" value="<?= e($group['name']) ?>" maxlength="160" placeholder="Group name" required>
            </div>
            <div class="col-12">
              <textarea class="form-control" name="group_description" rows="3" placeholder="Group description"><?= e((string) ($group['description'] ?? '')) ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label small">Accent Color</label>
              <input class="form-control form-control-color w-100" name="accent_color" type="color" value="<?= e($groupAccent) ?>">
            </div>
            <div class="col-12">
              <label class="form-label small">Cover Image</label>
              <input class="form-control" type="file" name="cover_image" accept="image/jpeg,image/png,image/webp">
            </div>
            <div class="col-12">
              <label class="form-label small">Chat Wallpaper</label>
              <input class="form-control" type="file" name="chat_wallpaper" accept="image/jpeg,image/png,image/webp">
            </div>
            <div class="col-12 d-grid">
              <button class="btn btn-brand" type="submit">Save Customization</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
      <div class="card-body p-3">
        <h2 class="h6 fw-bold mb-3">Members</h2>
        <div class="d-flex flex-column gap-2">
          <?php foreach ($members as $m): ?>
            <div class="d-flex align-items-center gap-2">
              <?php if (!empty($m['avatar'])): ?>
                <img src="<?= e($m['avatar']) ?>" alt="Avatar" class="uploader-avatar">
              <?php else: ?>
                <span class="uploader-avatar uploader-avatar-fallback"><?= e(strtoupper(substr(trim((string)$m['name']), 0, 1))) ?></span>
              <?php endif; ?>
              <div>
                <div class="small fw-semibold"><?= e($m['name']) ?></div>
                <div class="small"><span class="group-member-chip"><?= e(ucfirst($m['role'])) ?></span></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm mb-4 test-window" id="group-quiz-section">
  <div class="card-body p-3 p-md-4">
    <h2 class="h5 fw-bold mb-3">Test Window</h2>
    <div class="row g-3">
      <div class="col-12 col-xl-5">
        <h3 class="h6 fw-bold mb-2">Conduct Group Test</h3>
        <form method="post" action="group.php?id=<?= (int)$groupId ?>" class="row g-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_group_test">
          <input type="hidden" name="group_id" value="<?= (int)$groupId ?>">
          <div class="col-12">
            <input class="form-control" name="test_title" placeholder="Test title" required>
          </div>
          <div class="col-12">
            <input class="form-control" name="instructions" placeholder="Instructions (optional)">
          </div>
          <div class="col-12">
            <input class="form-control" name="marks_per_question" type="number" min="0.25" step="0.25" value="1" required>
          </div>
          <div class="col-12">
            <textarea class="form-control" name="mcq_text" rows="7" placeholder="1. Question&#10;A) Option&#10;B) Option&#10;C) Option&#10;D) Option&#10;Answer: A" required></textarea>
          </div>
          <div class="col-12 d-grid">
            <button class="btn btn-brand" type="submit">Create Test</button>
          </div>
        </form>
      </div>
      <div class="col-12 col-xl-7">
        <h3 class="h6 fw-bold mb-2">Available Group Tests</h3>
        <?php if (!$tests): ?>
          <p class="text-secondary mb-0">No tests in this group yet.</p>
        <?php else: ?>
          <div class="row g-2">
            <?php foreach ($tests as $t): ?>
              <div class="col-12 col-md-6">
                <article class="group-test-card h-100">
                  <h4 class="h6 fw-bold mb-1"><?= e($t['title']) ?></h4>
                  <p class="small text-secondary mb-1"><?= e($t['instructions'] ?: 'No instructions') ?></p>
                  <p class="small mb-1"><strong>Q:</strong> <?= (int)$t['total_questions'] ?> &bull; <strong>Total:</strong> <?= number_format((float)$t['total_marks'], 2) ?></p>
                  <p class="small mb-2"><strong>Attempts:</strong> <?= (int)$t['attempt_count'] ?></p>
                  <?php if (!empty($t['latest_attempt_id'])): ?>
                    <p class="small mb-2">Your last: <a href="group_test_result.php?attempt=<?= (int)$t['latest_attempt_id'] ?>"><?= number_format((float)$t['latest_percentage'], 1) ?>%</a></p>
                  <?php endif; ?>
                  <a href="group_test_attempt.php?test_id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-brand">Attempt Test</a>
                </article>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var chatBody = document.getElementById('groupChatBody');
  if (chatBody) {
    chatBody.scrollTop = chatBody.scrollHeight;
  }

  var sliderLinks = document.querySelectorAll('.group-slider-link');
  var sectionIds = ['group-chat-section', 'group-quiz-section', 'group-info-section'];
  var sectionMap = {};
  sectionIds.forEach(function (id) {
    var el = document.getElementById(id);
    if (el) {
      sectionMap[id] = el;
    }
  });

  function setActive(id) {
    sliderLinks.forEach(function (link) {
      if (link.getAttribute('data-target') === id) {
        link.classList.add('active');
      } else {
        link.classList.remove('active');
      }
    });
  }

  sliderLinks.forEach(function (link) {
    link.addEventListener('click', function (event) {
      event.preventDefault();
      var targetId = link.getAttribute('data-target');
      var target = sectionMap[targetId];
      if (!target) {
        return;
      }
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      setActive(targetId);
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', '#' + targetId);
      }
    });
  });

  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          setActive(entry.target.id);
        }
      });
    }, { rootMargin: '-30% 0px -55% 0px', threshold: 0.01 });

    Object.keys(sectionMap).forEach(function (id) {
      observer.observe(sectionMap[id]);
    });
  }
});
</script>
<?php

