<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
refresh_session_user(db());

$pdo = db();

$statsStmt = $pdo->query("SELECT
  SUM(CASE WHEN price = 0 THEN 1 ELSE 0 END) AS free_count,
  SUM(CASE WHEN price > 0 THEN 1 ELSE 0 END) AS paid_count
FROM notes");
$stats = $statsStmt->fetch() ?: ['free_count' => 0, 'paid_count' => 0];

$topSubjectStmt = $pdo->query("SELECT subject, COUNT(*) AS cnt FROM notes GROUP BY subject ORDER BY cnt DESC, subject ASC LIMIT 1");
$topSubjectRow = $topSubjectStmt->fetch();
$topSubject = $topSubjectRow ? $topSubjectRow['subject'] : 'N/A';

$userId = (int) current_user()['id'];
$notesStmt = $pdo->prepare("SELECT n.id, n.title, n.description, n.subject, n.price, n.created_at,
u.name AS uploader, u.avatar AS uploader_avatar,
COALESCE(rv.avg_rating, 0) AS avg_rating,
COALESCE(rv.review_count, 0) AS review_count,
COALESCE(lk.like_count, 0) AS like_count,
EXISTS(SELECT 1 FROM note_likes nl2 WHERE nl2.note_id = n.id AND nl2.user_id = ?) AS liked_by_user
FROM notes n
LEFT JOIN users u ON u.id = n.uploaded_by
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
ORDER BY n.created_at DESC
LIMIT 6");
$notesStmt->execute([$userId]);
$featuredNotes = $notesStmt->fetchAll();

$pageTitle = 'NotesPro | Marketplace';
$activePage = 'marketplace';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar d-flex flex-wrap gap-3 justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 fw-bold mb-1">Notes Marketplace</h1>
    <p class="text-secondary mb-0">Discover free and premium notes by top contributors.</p>
  </div>
  <div class="d-flex gap-2">
    <a href="notes.php?sort=rating" class="btn btn-outline-secondary btn-sm">Top Rated</a>
    <?php if (is_admin()): ?>
      <a href="admin.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-shield-lock"></i> Admin</a>
    <?php endif; ?>
  </div>
</header>

<section class="institute-brand mb-4">
  <div class="institute-brand-inner">
    <div class="d-flex align-items-center gap-3 mb-3">
      <div class="institute-logo">NI</div>
      <div>
        <p class="institute-kicker mb-1">Official Knowledge Hub</p>
        <h2 class="h4 fw-bold mb-0">Nexus Institute of Technology</h2>
      </div>
    </div>
    <p class="mb-3 text-light-emphasis">Curated by faculty and toppers for semester exams, placements, and skill tracks.</p>
    <div class="d-flex flex-wrap gap-2">
      <span class="brand-chip">NAAC A+</span>
      <span class="brand-chip">ISO Certified</span>
      <span class="brand-chip">Campus Verified Notes</span>
    </div>
  </div>
</section>

<section class="row g-3 mb-4">
  <div class="col-12 col-md-4"><div class="metric-card"><p>Free Notes</p><h2><?= e((string)$stats['free_count']) ?></h2></div></div>
  <div class="col-12 col-md-4"><div class="metric-card"><p>Premium Notes</p><h2><?= e((string)$stats['paid_count']) ?></h2></div></div>
  <div class="col-12 col-md-4"><div class="metric-card"><p>Top Category</p><h2><?= e($topSubject ?: 'N/A') ?></h2></div></div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-3 p-md-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="h5 fw-bold mb-0">Featured Notes</h2>
      <a href="notes.php" class="text-decoration-none">Browse all</a>
    </div>
    <div class="row g-3">
      <?php foreach ($featuredNotes as $note): ?>
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
            <div class="d-flex justify-content-between align-items-center">
              <span class="price-pill"><?= ((float)$note['price'] > 0) ? '$' . number_format((float)$note['price'], 2) : 'Free' ?></span>
              <form method="get" action="payment.php" class="m-0">
                <input type="hidden" name="scope" value="single">
                <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
                <button class="btn btn-sm <?= ((float)$note['price'] > 0) ? 'btn-brand' : 'btn-outline-success' ?>" type="submit"><?= ((float)$note['price'] > 0) ? 'Buy Now' : 'Access Free' ?></button>
              </form>
            </div>
            <div class="mt-2">
              <a href="note.php?id=<?= (int)$note['id'] ?>" class="small text-decoration-none">Open Q&A</a>
            </div>
            <p class="small text-secondary mt-2 mb-0"><?= e($note['subject'] ?: 'General') ?> • <?= e(date('M d, Y', strtotime($note['created_at']))) ?></p>
          </article>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm">
  <div class="card-body p-3 p-md-4">
    <h2 class="h5 fw-bold mb-3">What’s New</h2>
    <div class="alert alert-info mb-0">You can now filter and sort notes, view ratings, and leave reviews from your library.</div>
  </div>
</section>
<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
