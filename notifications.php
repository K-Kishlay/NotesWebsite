<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$userId = (int) current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare('UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        set_flash('success', 'All notifications marked as read.');
        redirect('notifications.php');
    }

    if ($action === 'mark_read') {
        $id = (int) ($_POST['notification_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE user_notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, $userId]);
            set_flash('success', 'Notification marked as read.');
        }
        redirect('notifications.php');
    }
}

$stmt = $pdo->prepare('SELECT id, title, message, is_read, created_at FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

$unreadStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM user_notifications WHERE user_id = ? AND is_read = 0');
$unreadStmt->execute([$userId]);
$unread = (int) ($unreadStmt->fetch()['cnt'] ?? 0);

$pageTitle = 'NotesPro | Notifications';
$activePage = 'notifications';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar d-flex flex-wrap justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 fw-bold mb-1">Notifications</h1>
    <p class="text-secondary mb-0">Updates from admin and platform activities.</p>
  </div>
  <?php if ($unread > 0): ?>
    <form method="post" action="notifications.php" class="m-0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="mark_all_read">
      <button class="btn btn-outline-secondary btn-sm" type="submit">Mark all as read</button>
    </form>
  <?php endif; ?>
</header>

<section class="card border-0 shadow-sm">
  <div class="card-body p-4">
    <?php if (!$notifications): ?>
      <p class="text-secondary mb-0">No notifications yet.</p>
    <?php else: ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($notifications as $n): ?>
          <li class="list-group-item px-0 py-3">
            <div class="d-flex justify-content-between align-items-start gap-3">
              <div>
                <h2 class="h6 fw-bold mb-1">
                  <?= e($n['title']) ?>
                  <?php if (!(int)$n['is_read']): ?><span class="badge text-bg-primary ms-2">New</span><?php endif; ?>
                </h2>
                <p class="mb-1 text-secondary"><?= nl2br(e($n['message'])) ?></p>
                <small class="text-secondary"><?= e(date('M d, Y h:i A', strtotime($n['created_at']))) ?></small>
              </div>
              <?php if (!(int)$n['is_read']): ?>
                <form method="post" action="notifications.php" class="m-0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mark_read">
                  <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary" type="submit">Mark read</button>
                </form>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
