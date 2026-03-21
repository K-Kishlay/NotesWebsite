<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/study_groups_helper.php';
require_login();

$pdo = db();
ensure_study_group_tables($pdo);
$userId = (int) current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_group') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($name === '') {
            set_flash('danger', 'Group name is required.');
            redirect('study_groups.php');
        }

        $joinCode = '';
        for ($i = 0; $i < 6; $i++) {
            $joinCode = generate_group_join_code(8);
            $chk = $pdo->prepare('SELECT id FROM study_groups WHERE join_code = ? LIMIT 1');
            $chk->execute([$joinCode]);
            if (!$chk->fetch()) {
                break;
            }
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT INTO study_groups (name, description, join_code, created_by) VALUES (?, ?, ?, ?)');
            $ins->execute([$name, $description !== '' ? $description : null, $joinCode, $userId]);
            $groupId = (int) $pdo->lastInsertId();

            $memberIns = $pdo->prepare('INSERT INTO study_group_members (group_id, user_id, role) VALUES (?, ?, "owner")');
            $memberIns->execute([$groupId, $userId]);
            $pdo->commit();
            set_flash('success', 'Study group created.');
            redirect('group.php?id=' . $groupId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('danger', 'Unable to create group.');
            redirect('study_groups.php');
        }
    }

    if ($action === 'join_group') {
        $code = strtoupper(trim((string) ($_POST['join_code'] ?? '')));
        if ($code === '') {
            set_flash('danger', 'Enter join code.');
            redirect('study_groups.php');
        }
        $groupStmt = $pdo->prepare('SELECT id FROM study_groups WHERE join_code = ? LIMIT 1');
        $groupStmt->execute([$code]);
        $group = $groupStmt->fetch();
        if (!$group) {
            set_flash('danger', 'Invalid join code.');
            redirect('study_groups.php');
        }
        $groupId = (int) $group['id'];
        if (!is_group_member($pdo, $groupId, $userId)) {
            $ins = $pdo->prepare('INSERT INTO study_group_members (group_id, user_id, role) VALUES (?, ?, "member")');
            $ins->execute([$groupId, $userId]);
        }
        set_flash('success', 'Joined group successfully.');
        redirect('group.php?id=' . $groupId);
    }
}

$prefillCode = strtoupper(trim((string) ($_GET['join'] ?? '')));
$groupsStmt = $pdo->prepare('SELECT g.id, g.name, g.description, g.join_code, g.created_at, u.name AS owner_name,
COALESCE(m.member_count, 0) AS member_count
FROM study_group_members gm
JOIN study_groups g ON g.id = gm.group_id
JOIN users u ON u.id = g.created_by
LEFT JOIN (
  SELECT group_id, COUNT(*) AS member_count
  FROM study_group_members
  GROUP BY group_id
) m ON m.group_id = g.id
WHERE gm.user_id = ?
ORDER BY g.created_at DESC');
$groupsStmt->execute([$userId]);
$groups = $groupsStmt->fetchAll();

$pageTitle = 'NotesPro | Study Groups';
$activePage = 'study_groups';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar mb-4 group-lobby-hero">
  <h1 class="h3 fw-bold mb-1">Study Groups</h1>
  <p class="text-secondary mb-0">Create focused communities, collaborate in chat, and compete in group tests.</p>
</header>

<section class="row g-3 mb-4">
  <div class="col-12 col-lg-6">
    <div class="card border-0 shadow-sm h-100 group-lobby-card">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold mb-3">Create Group</h2>
        <form method="post" action="study_groups.php" class="row g-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_group">
          <div class="col-12">
            <label class="form-label">Group Name</label>
            <input class="form-control" name="name" maxlength="160" placeholder="Semester 3 Physics Squad" required>
          </div>
          <div class="col-12">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" rows="3" placeholder="Short group purpose"></textarea>
          </div>
          <div class="col-12 d-grid d-md-flex justify-content-md-end">
            <button class="btn btn-brand" type="submit">Create Study Group</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card border-0 shadow-sm h-100 group-lobby-card">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold mb-3">Join Group</h2>
        <form method="post" action="study_groups.php" class="row g-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="join_group">
          <div class="col-12">
            <label class="form-label">Join Code</label>
            <input class="form-control text-uppercase" name="join_code" maxlength="20" value="<?= e($prefillCode) ?>" placeholder="ABCD1234" required>
          </div>
          <div class="col-12 d-grid d-md-flex justify-content-md-end">
            <button class="btn btn-outline-secondary" type="submit">Join Now</button>
          </div>
        </form>
        <p class="small text-secondary mt-3 mb-0">Share format: <code>study_groups.php?join=GROUPCODE</code></p>
      </div>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm group-lobby-list">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Your Groups</h2>
    <?php if (!$groups): ?>
      <p class="text-secondary mb-0">You are not in any group yet.</p>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($groups as $g): ?>
          <div class="col-12 col-md-6 col-xl-4">
            <article class="group-list-card h-100">
              <h3 class="h6 fw-bold mb-1"><?= e($g['name']) ?></h3>
              <p class="small text-secondary mb-2"><?= e($g['description'] ?: 'No description.') ?></p>
              <p class="small mb-1"><strong>Owner:</strong> <?= e($g['owner_name']) ?></p>
              <p class="small mb-1"><strong>Members:</strong> <?= (int)$g['member_count'] ?></p>
              <p class="small mb-3"><strong>Join Code:</strong> <code><?= e($g['join_code']) ?></code></p>
              <a href="group.php?id=<?= (int)$g['id'] ?>" class="btn btn-sm btn-brand">Open Group</a>
            </article>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
