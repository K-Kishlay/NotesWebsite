<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

$pdo = db();
$status = $_GET['status'] ?? 'all';
$validStatuses = ['all', 'visible', 'hidden'];
if (!in_array($status, $validStatuses, true)) {
    $status = 'all';
}

$sql = 'SELECT d.id, d.note_id, d.parent_id, d.message, d.status, d.created_at, u.name AS user_name, n.title AS note_title
        FROM note_discussions d
        JOIN users u ON u.id = d.user_id
        JOIN notes n ON n.id = d.note_id';
$params = [];
if ($status !== 'all') {
    $sql .= ' WHERE d.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY d.created_at DESC LIMIT 300';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'NotesPro | Q&A Moderation';
$activePage = 'moderation';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar d-flex flex-wrap justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 fw-bold mb-1">Q&A Moderation</h1>
    <p class="text-secondary mb-0">Review comments and replies from note discussions.</p>
  </div>
  <form method="get" action="discussion_admin.php" class="d-flex gap-2 align-items-center">
    <label class="small text-secondary mb-0">Status</label>
    <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
      <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
      <option value="visible" <?= $status === 'visible' ? 'selected' : '' ?>>Visible</option>
      <option value="hidden" <?= $status === 'hidden' ? 'selected' : '' ?>>Hidden</option>
    </select>
  </form>
</header>

<section class="card border-0 shadow-sm">
  <div class="card-body p-4">
    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Note</th>
            <th>User</th>
            <th>Message</th>
            <th>Status</th>
            <th>Date</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-secondary">No discussions found.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td>
                <div><?= e($r['note_title']) ?></div>
                <a href="note.php?id=<?= (int)$r['note_id'] ?>" class="small text-decoration-none">Open thread</a>
              </td>
              <td><?= e($r['user_name']) ?></td>
              <td><?= e(mb_strimwidth($r['message'], 0, 120, '...')) ?></td>
              <td>
                <span class="badge <?= $r['status'] === 'visible' ? 'text-bg-success' : 'text-bg-warning' ?>">
                  <?= e(ucfirst($r['status'])) ?>
                </span>
              </td>
              <td><?= e(date('M d, Y h:i A', strtotime($r['created_at']))) ?></td>
              <td>
                <div class="d-flex gap-2">
                  <form method="post" action="discussion_action.php" class="m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="discussion_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="redirect_to" value="discussion_admin.php?status=<?= e($status) ?>">
                    <?php if ($r['status'] === 'visible'): ?>
                      <input type="hidden" name="action" value="hide">
                      <button class="btn btn-sm btn-outline-warning" type="submit">Hide</button>
                    <?php else: ?>
                      <input type="hidden" name="action" value="unhide">
                      <button class="btn btn-sm btn-outline-success" type="submit">Unhide</button>
                    <?php endif; ?>
                  </form>
                  <form method="post" action="discussion_action.php" class="m-0" onsubmit="return confirm('Delete this discussion?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="discussion_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="redirect_to" value="discussion_admin.php?status=<?= e($status) ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                  </form>
                </div>
              </td>
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
