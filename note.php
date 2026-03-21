<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$noteId = (int) ($_GET['id'] ?? 0);
if ($noteId <= 0) {
    set_flash('danger', 'Invalid note.');
    redirect('notes.php');
}

$noteStmt = $pdo->prepare('SELECT id, title, description, subject, price, created_at FROM notes WHERE id = ? LIMIT 1');
$noteStmt->execute([$noteId]);
$note = $noteStmt->fetch();
if (!$note) {
    set_flash('danger', 'Note not found.');
    redirect('notes.php');
}

$discussionSql = 'SELECT d.id, d.note_id, d.user_id, d.parent_id, d.message, d.status, d.created_at, u.name AS user_name,
                  EXISTS(
                    SELECT 1 FROM purchases p
                    WHERE p.user_id = d.user_id
                      AND p.note_id = d.note_id
                      AND p.payment_status = "paid"
                  ) AS has_bought
                  FROM note_discussions d
                  JOIN users u ON u.id = d.user_id
                  WHERE d.note_id = ?';
if (!is_admin()) {
    $discussionSql .= ' AND d.status = "visible"';
}
$discussionSql .= ' ORDER BY d.created_at ASC';

$discStmt = $pdo->prepare($discussionSql);
$discStmt->execute([$noteId]);
$rows = $discStmt->fetchAll();

$byParent = [];
foreach ($rows as $r) {
    $pid = $r['parent_id'] === null ? 0 : (int) $r['parent_id'];
    if (!isset($byParent[$pid])) {
        $byParent[$pid] = [];
    }
    $byParent[$pid][] = $r;
}

function render_discussion_tree(array $byParent, int $parentId, int $noteId, bool $canModerate, int $level = 0): void {
    if (!isset($byParent[$parentId])) {
        return;
    }
    foreach ($byParent[$parentId] as $item) {
        $id = (int) $item['id'];
        $status = (string) $item['status'];
        $indent = min($level, 4) * 20;
        echo '<div class="discussion-item mb-3" style="margin-left:' . $indent . 'px">';
        echo '<div class="d-flex justify-content-between gap-2">';
        echo '<div><strong>' . e($item['user_name']) . '</strong> ';
        if ((int)($item['has_bought'] ?? 0) === 1) {
            echo '<span class="badge text-bg-success ms-1">Bought</span>';
        } else {
            echo '<span class="badge text-bg-secondary ms-1">Not Bought</span>';
        }
        echo ' <small class="text-secondary ms-1">' . e(date('M d, Y h:i A', strtotime($item['created_at']))) . '</small>';
        if ($status === 'hidden') {
            echo ' <span class="badge text-bg-warning ms-1">Hidden</span>';
        }
        echo '</div>';
        echo '</div>';
        echo '<p class="mb-2 mt-1">' . nl2br(e($item['message'])) . '</p>';

        if ($status === 'visible') {
            echo '<form method="post" action="discussion_action.php" class="row g-2 discussion-reply-form mb-2">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="add_reply">';
            echo '<input type="hidden" name="note_id" value="' . $noteId . '">';
            echo '<input type="hidden" name="parent_id" value="' . $id . '">';
            echo '<input type="hidden" name="redirect_to" value="note.php?id=' . $noteId . '">';
            echo '<div class="col-12 col-md-9"><input class="form-control form-control-sm" name="message" placeholder="Write a reply..." maxlength="1000" required></div>';
            echo '<div class="col-12 col-md-3 d-grid"><button class="btn btn-sm btn-outline-secondary" type="submit">Reply</button></div>';
            echo '</form>';
        }

        if ($canModerate) {
            echo '<div class="d-flex gap-2 mb-2">';
            echo '<form method="post" action="discussion_action.php" class="m-0">';
            echo csrf_field();
            echo '<input type="hidden" name="discussion_id" value="' . $id . '">';
            echo '<input type="hidden" name="redirect_to" value="note.php?id=' . $noteId . '">';
            if ($status === 'visible') {
                echo '<input type="hidden" name="action" value="hide">';
                echo '<button class="btn btn-sm btn-outline-warning" type="submit">Hide</button>';
            } else {
                echo '<input type="hidden" name="action" value="unhide">';
                echo '<button class="btn btn-sm btn-outline-success" type="submit">Unhide</button>';
            }
            echo '</form>';
            echo '<form method="post" action="discussion_action.php" class="m-0" onsubmit="return confirm(\'Delete this discussion?\')">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="delete">';
            echo '<input type="hidden" name="discussion_id" value="' . $id . '">';
            echo '<input type="hidden" name="redirect_to" value="note.php?id=' . $noteId . '">';
            echo '<button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>';
            echo '</form>';
            echo '</div>';
        }

        render_discussion_tree($byParent, $id, $noteId, $canModerate, $level + 1);
        echo '</div>';
    }
}

$pageTitle = 'NotesPro | Note Discussion';
$activePage = 'notes';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar mb-4">
  <h1 class="h3 fw-bold mb-1"><?= e($note['title']) ?></h1>
  <p class="text-secondary mb-0"><?= e($note['subject'] ?: 'General') ?> • <?= ((float)$note['price'] > 0) ? '$' . number_format((float)$note['price'], 2) : 'Free' ?></p>
</header>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-2">Description</h2>
    <p class="text-secondary mb-0"><?= e($note['description'] ?: 'No description available.') ?></p>
  </div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Ask Doubt / Q&A</h2>
    <form method="post" action="discussion_action.php" class="row g-2">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_comment">
      <input type="hidden" name="note_id" value="<?= (int)$noteId ?>">
      <input type="hidden" name="redirect_to" value="note.php?id=<?= (int)$noteId ?>">
      <div class="col-12 col-md-10">
        <textarea class="form-control" name="message" rows="2" placeholder="Post your doubt/question..." maxlength="1000" required></textarea>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-brand" type="submit">Post</button>
      </div>
    </form>
  </div>
</section>

<section class="card border-0 shadow-sm">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Discussion Thread</h2>
    <?php if (empty($rows)): ?>
      <p class="text-secondary mb-0">No discussion yet. Start the first question.</p>
    <?php else: ?>
      <?php render_discussion_tree($byParent, 0, $noteId, is_admin(), 0); ?>
    <?php endif; ?>
  </div>
</section>
<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
