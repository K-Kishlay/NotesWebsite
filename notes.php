<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$userId = (int) current_user()['id'];

$q = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? 'all';
$subject = trim($_GET['subject'] ?? '');
$sort = $_GET['sort'] ?? 'newest';

$sortMap = [
    'newest' => 'n.created_at DESC',
    'price_low' => 'n.price ASC, n.created_at DESC',
    'price_high' => 'n.price DESC, n.created_at DESC',
    'rating' => 'COALESCE(rv.avg_rating, 0) DESC, rv.review_count DESC, n.created_at DESC',
    'likes' => 'COALESCE(lk.like_count, 0) DESC, n.created_at DESC',
];
$orderBy = $sortMap[$sort] ?? $sortMap['newest'];

$where = [];
$params = [$userId, $userId];

if ($q !== '') {
    $where[] = '(n.title LIKE ? OR n.subject LIKE ? OR n.description LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if ($type === 'free') {
    $where[] = 'n.price = 0';
} elseif ($type === 'premium') {
    $where[] = 'n.price > 0';
}

if ($subject !== '') {
    $where[] = 'n.subject = ?';
    $params[] = $subject;
}

$sql = "SELECT n.id, n.title, n.description, n.subject, n.price, n.created_at,
        u.name AS uploader, u.avatar AS uploader_avatar,
        EXISTS(SELECT 1 FROM purchases p WHERE p.note_id = n.id AND p.user_id = ?) AS owned,
        EXISTS(SELECT 1 FROM note_likes nl2 WHERE nl2.note_id = n.id AND nl2.user_id = ?) AS liked_by_user,
        COALESCE(rv.avg_rating, 0) AS avg_rating,
        COALESCE(rv.review_count, 0) AS review_count,
        COALESCE(lk.like_count, 0) AS like_count
        FROM notes n
        LEFT JOIN (
            SELECT note_id, AVG(rating) AS avg_rating, COUNT(*) AS review_count
            FROM reviews
            GROUP BY note_id
        ) rv ON rv.note_id = n.id
        LEFT JOIN (
            SELECT note_id, COUNT(*) AS like_count
            FROM note_likes
            GROUP BY note_id
        ) lk ON lk.note_id = n.id
        LEFT JOIN users u ON u.id = n.uploaded_by";

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY ' . $orderBy;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notes = $stmt->fetchAll();

$subjects = $pdo->query('SELECT DISTINCT subject FROM notes WHERE subject IS NOT NULL AND subject <> "" ORDER BY subject ASC')->fetchAll();

$pageTitle = 'NotesPro | All Notes';
$activePage = 'notes';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar d-flex flex-wrap gap-3 justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 fw-bold mb-1">All Notes</h1>
    <p class="text-secondary mb-0">Free and paid notes available for access or purchase.</p>
  </div>
</header>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-3">
    <form method="get" action="notes.php" class="row g-2 align-items-end filter-toolbar">
      <div class="col-12 col-md-4">
        <label class="form-label small mb-1">Search</label>
        <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search notes...">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Type</label>
        <select class="form-select" name="type">
          <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All</option>
          <option value="free" <?= $type === 'free' ? 'selected' : '' ?>>Free</option>
          <option value="premium" <?= $type === 'premium' ? 'selected' : '' ?>>Premium</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Subject</label>
        <select class="form-select" name="subject">
          <option value="">All</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?= e($s['subject']) ?>" <?= $subject === $s['subject'] ? 'selected' : '' ?>><?= e($s['subject']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Sort</label>
        <select class="form-select" name="sort">
          <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
          <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Top Rated</option>
          <option value="likes" <?= $sort === 'likes' ? 'selected' : '' ?>>Most Liked</option>
          <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price Low</option>
          <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price High</option>
        </select>
      </div>
      <div class="col-6 col-md-2 d-grid">
        <button class="btn btn-brand" type="submit">Apply</button>
      </div>
    </form>
  </div>
</section>

<?php if (!$notes): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-body p-4 text-center text-secondary">No notes found for your current filters.</div>
  </div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($notes as $note): ?>
    <?php $isNew = (time() - strtotime($note['created_at'])) <= (7 * 86400); ?>
    <div class="col-12 col-md-6 col-xl-4">
      <article class="note-card">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="tag <?= ((float)$note['price'] > 0) ? 'tag-paid' : 'tag-free' ?>"><?= ((float)$note['price'] > 0) ? 'Premium' : 'Free' ?></span>
          <?php if ($isNew): ?><span class="badge-new">New</span><?php endif; ?>
        </div>
        <h3 class="h6 fw-bold mb-2 mt-2"><?= e($note['title']) ?></h3>
        <p class="text-secondary mb-2"><?= e($note['description'] ?: 'No description.') ?></p>
        <div class="uploader-meta mb-2">
          <?php if (!empty($note['uploader_avatar'])): ?>
            <img src="<?= e($note['uploader_avatar']) ?>" alt="Uploader" class="uploader-avatar">
          <?php else: ?>
            <span class="uploader-avatar uploader-avatar-fallback">
              <?= e(strtoupper(substr(trim((string)($note['uploader'] ?? 'U')), 0, 1))) ?>
            </span>
          <?php endif; ?>
          <span>Uploaded by <?= e($note['uploader'] ?: 'Unknown') ?></span>
        </div>
        <div class="rating-meta mb-3">
          <span class="rating-stars">
            <?php $rounded = (int) round((float) $note['avg_rating']); for ($i = 1; $i <= 5; $i++): ?>
              <span class="<?= $i <= $rounded ? 'star-on' : 'star-off' ?>">★</span>
            <?php endfor; ?>
          </span>
          <span class="small text-secondary"><?= number_format((float)$note['avg_rating'], 1) ?> (<?= (int)$note['review_count'] ?>)</span>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <form method="post" action="like_action.php" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
            <button class="btn btn-sm <?= (int)$note['liked_by_user'] === 1 ? 'btn-danger' : 'btn-outline-secondary' ?>" type="submit">
              <i class="bi bi-heart<?= (int)$note['liked_by_user'] === 1 ? '-fill' : '' ?>"></i>
              <?= (int)$note['like_count'] ?>
            </button>
          </form>
        </div>
        <div class="d-flex justify-content-between align-items-center gap-2">
          <span class="price-pill"><?= ((float)$note['price'] > 0) ? '$' . number_format((float)$note['price'], 2) : 'Free' ?></span>
          <div class="d-flex gap-2">
            <?php if ((int)$note['owned'] === 1): ?>
              <span class="badge text-bg-success">Owned</span>
            <?php else: ?>
              <form method="post" action="cart_action.php" class="m-0">
                <?= csrf_field() ?>
                <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
                <input type="hidden" name="action" value="add">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Add Cart</button>
              </form>
              <form method="get" action="payment.php" class="m-0">
                <input type="hidden" name="scope" value="single">
                <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
                <button class="btn btn-sm <?= ((float)$note['price'] > 0) ? 'btn-brand' : 'btn-outline-success' ?>" type="submit"><?= ((float)$note['price'] > 0) ? 'Buy Now' : 'Access Free' ?></button>
              </form>
            <?php endif; ?>
          </div>
        </div>
        <div class="mt-2">
          <a href="note.php?id=<?= (int)$note['id'] ?>" class="small text-decoration-none">Open Q&A</a>
        </div>
        <p class="small text-secondary mt-2 mb-0"><?= e($note['subject'] ?: 'General') ?> • <?= e(date('M d, Y', strtotime($note['created_at']))) ?></p>
      </article>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
