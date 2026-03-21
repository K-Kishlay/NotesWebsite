<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lectures_helper.php';
require_login();

$pdo = db();
ensure_lecture_system_tables($pdo);

$userId = (int) current_user()['id'];
$search = trim((string) ($_GET['q'] ?? ''));
$selectedId = (int) ($_GET['id'] ?? 0);

$params = [$userId];
$whereSql = '';
if ($search !== '') {
    $whereSql = 'WHERE (
        l.title LIKE ?
        OR COALESCE(l.description, "") LIKE ?
        OR COALESCE(l.subject, "") LIKE ?
        OR COALESCE(p.title, "") LIKE ?
        OR COALESCE(u.name, "") LIKE ?
    )';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$listSql = 'SELECT l.id, l.title, l.description, l.subject, l.source_type, l.video_path, l.youtube_url, l.youtube_video_id, l.thumbnail_path, l.created_at,
    u.name AS educator_name,
    p.title AS playlist_title,
    COALESCE(lk.like_count, 0) AS like_count,
    COALESCE(cm.comment_count, 0) AS comment_count,
    EXISTS(
        SELECT 1
        FROM lecture_likes ll2
        WHERE ll2.lecture_id = l.id AND ll2.user_id = ?
    ) AS liked_by_user
FROM lectures l
JOIN users u ON u.id = l.uploaded_by
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
' . $whereSql . '
ORDER BY l.created_at DESC';

$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$lectures = $listStmt->fetchAll();

$groupedLectures = [];
foreach ($lectures as $lecture) {
    $playlistLabel = trim((string) ($lecture['playlist_title'] ?? ''));
    $groupKey = $playlistLabel !== '' ? 'playlist_' . mb_strtolower($playlistLabel) : 'standalone';
    if (!isset($groupedLectures[$groupKey])) {
        $groupedLectures[$groupKey] = [
            'title' => $playlistLabel !== '' ? $playlistLabel : 'Standalone Lectures',
            'is_playlist' => $playlistLabel !== '',
            'items' => [],
        ];
    }
    $groupedLectures[$groupKey]['items'][] = $lecture;
}

if ($selectedId <= 0 && $lectures) {
    $selectedId = (int) $lectures[0]['id'];
}

$selectedLecture = null;
foreach ($lectures as $lecture) {
    if ((int) $lecture['id'] === $selectedId) {
        $selectedLecture = $lecture;
        break;
    }
}

if ($selectedLecture === null && $selectedId > 0) {
    $detailStmt = $pdo->prepare('SELECT l.id, l.title, l.description, l.subject, l.source_type, l.video_path, l.youtube_url, l.youtube_video_id, l.thumbnail_path, l.created_at,
        u.name AS educator_name,
        p.title AS playlist_title,
        COALESCE(lk.like_count, 0) AS like_count,
        COALESCE(cm.comment_count, 0) AS comment_count,
        EXISTS(
            SELECT 1
            FROM lecture_likes ll2
            WHERE ll2.lecture_id = l.id AND ll2.user_id = ?
        ) AS liked_by_user
    FROM lectures l
    JOIN users u ON u.id = l.uploaded_by
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
    WHERE l.id = ?
    LIMIT 1');
    $detailStmt->execute([$userId, $selectedId]);
    $selectedLecture = $detailStmt->fetch() ?: null;
}

$comments = $selectedLecture ? fetch_lecture_comments($pdo, (int) $selectedLecture['id']) : [];
$selectedLectureUrl = $selectedLecture
    ? 'lectures.php?id=' . (int) $selectedLecture['id'] . ($search !== '' ? '&q=' . urlencode($search) : '')
    : 'lectures.php' . ($search !== '' ? '?q=' . urlencode($search) : '');

$playlistItems = [];
if ($selectedLecture && !empty($selectedLecture['playlist_title'])) {
    $playlistStmt = $pdo->prepare('SELECT l.id, l.title
    FROM lectures l
    WHERE l.playlist_id = (SELECT playlist_id FROM lectures WHERE id = ? LIMIT 1)
    ORDER BY l.created_at ASC, l.id ASC');
    $playlistStmt->execute([(int) $selectedLecture['id']]);
    $playlistItems = $playlistStmt->fetchAll();
}

$pageTitle = 'NotesPro | Lectures';
$activePage = 'lectures';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar mb-4">
  <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
    <div>
      <h1 class="h3 fw-bold mb-1">Lectures</h1>
      <p class="text-secondary mb-0">Watch educator videos, search by topic, and join the live comment flow.</p>
    </div>
    <form method="get" action="lectures.php" class="search-wrap">
      <i class="bi bi-search"></i>
      <input class="form-control" type="search" name="q" value="<?= e($search) ?>" placeholder="Search lecture, subject, playlist, educator">
    </form>
  </div>
</header>

<section class="row g-4">
  <div class="col-12 col-xl-8">
    <?php if (!$selectedLecture): ?>
      <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
          <p class="text-secondary mb-0">No lectures found yet. Educators can add a YouTube lecture from the creator dashboard.</p>
        </div>
      </div>
    <?php else: ?>
      <section class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 p-lg-4">
          <div class="lecture-player-wrap mb-3">
            <?php if (($selectedLecture['source_type'] ?? 'youtube') === 'youtube' && !empty($selectedLecture['youtube_video_id'])): ?>
              <iframe
                class="lecture-player lecture-iframe"
                src="<?= e(youtube_embed_url((string) $selectedLecture['youtube_video_id'])) ?>"
                title="<?= e($selectedLecture['title']) ?>"
                loading="lazy"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                allowfullscreen
                referrerpolicy="strict-origin-when-cross-origin"></iframe>
            <?php else: ?>
              <video class="lecture-player" controls preload="metadata" poster="<?= e(lecture_poster_path($selectedLecture)) ?>">
                <source src="<?= e((string) ($selectedLecture['video_path'] ?? '')) ?>">
                Your browser does not support the video tag.
              </video>
            <?php endif; ?>
          </div>
          <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
            <div>
              <h2 class="h4 fw-bold mb-2"><?= e($selectedLecture['title']) ?></h2>
              <div class="d-flex flex-wrap gap-2 mb-2">
                <span class="lecture-chip"><i class="bi bi-person-video3"></i><?= e($selectedLecture['educator_name']) ?></span>
                <span class="lecture-chip"><i class="bi bi-book"></i><?= e($selectedLecture['subject'] ?: 'General') ?></span>
                <?php if (!empty($selectedLecture['playlist_title'])): ?>
                  <span class="lecture-chip"><i class="bi bi-collection"></i><?= e($selectedLecture['playlist_title']) ?></span>
                <?php endif; ?>
              </div>
              <p class="text-secondary mb-0"><?= e($selectedLecture['description'] ?: 'No description added for this lecture yet.') ?></p>
            </div>
            <div class="lecture-actions">
              <form method="post" action="lecture_action.php" class="m-0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_like">
                <input type="hidden" name="lecture_id" value="<?= (int) $selectedLecture['id'] ?>">
                <input type="hidden" name="redirect_to" value="<?= e($selectedLectureUrl) ?>">
                <button class="btn <?= (int) $selectedLecture['liked_by_user'] === 1 ? 'btn-danger' : 'btn-outline-secondary' ?>" type="submit">
                  <i class="bi bi-heart<?= (int) $selectedLecture['liked_by_user'] === 1 ? '-fill' : '' ?>"></i>
                  <?= (int) $selectedLecture['like_count'] ?> Likes
                </button>
              </form>
              <div class="lecture-stat">
                <i class="bi bi-chat-dots"></i>
                <span><?= (int) $selectedLecture['comment_count'] ?> Comments</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <?php if ($playlistItems): ?>
        <section class="card border-0 shadow-sm mb-4">
          <div class="card-body p-4">
            <h2 class="h5 fw-bold mb-3">Playlist Queue</h2>
            <div class="playlist-strip">
              <?php foreach ($playlistItems as $item): ?>
                <a class="playlist-item <?= (int) $item['id'] === (int) $selectedLecture['id'] ? 'active' : '' ?>" href="lectures.php?id=<?= (int) $item['id'] ?>&q=<?= urlencode($search) ?>">
                  <span class="playlist-index"><i class="bi bi-play-circle"></i></span>
                  <span><?= e($item['title']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      <?php endif; ?>

      <section class="card border-0 shadow-sm" id="lecture-comments">
        <div class="card-body p-4">
          <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
            <div>
              <h2 class="h5 fw-bold mb-1">Live Comments</h2>
              <p class="text-secondary mb-0">Comments refresh automatically every 10 seconds while you watch.</p>
            </div>
          </div>
          <form method="post" action="lecture_action.php" class="row g-2 mb-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_comment">
            <input type="hidden" name="lecture_id" value="<?= (int) $selectedLecture['id'] ?>">
            <input type="hidden" name="redirect_to" value="<?= e($selectedLectureUrl) ?>">
            <div class="col-12 col-md-9">
              <textarea class="form-control" name="message" rows="2" placeholder="Share a question or reaction..." maxlength="1000" required></textarea>
            </div>
            <div class="col-12 col-md-3 d-grid">
              <button class="btn btn-brand" type="submit">Post Comment</button>
            </div>
          </form>
          <div id="lectureCommentsFeed" data-lecture-id="<?= (int) $selectedLecture['id'] ?>">
            <?php render_lecture_comments($comments); ?>
          </div>
        </div>
      </section>
    <?php endif; ?>
  </div>

  <div class="col-12 col-xl-4">
    <section class="card border-0 shadow-sm">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h5 fw-bold mb-0">Organized Videos</h2>
          <span class="text-secondary small"><?= count($lectures) ?> found</span>
        </div>
        <?php if (!$lectures): ?>
          <p class="text-secondary mb-0">Try a different search term.</p>
        <?php else: ?>
          <div class="playlist-browser">
            <?php foreach ($groupedLectures as $group): ?>
              <section class="playlist-group">
                <div class="playlist-group-head">
                  <div>
                    <h3 class="playlist-group-title mb-1"><?= e($group['title']) ?></h3>
                    <p class="playlist-group-meta mb-0"><?= count($group['items']) ?> video<?= count($group['items']) === 1 ? '' : 's' ?><?= $group['is_playlist'] ? ' in this playlist' : ' without a playlist' ?></p>
                  </div>
                  <span class="playlist-group-badge"><?= $group['is_playlist'] ? 'Playlist' : 'Single' ?></span>
                </div>
                <div class="lecture-list">
                  <?php foreach ($group['items'] as $lecture): ?>
                    <a class="lecture-card-link <?= (int) $lecture['id'] === $selectedId ? 'active' : '' ?>" href="lectures.php?id=<?= (int) $lecture['id'] ?>&q=<?= urlencode($search) ?>">
                      <article class="lecture-card-item">
                        <div class="lecture-card-thumb">
                          <?php $thumb = lecture_poster_path($lecture); ?>
                          <?php if ($thumb !== ''): ?>
                            <img src="<?= e($thumb) ?>" alt="Lecture thumbnail">
                          <?php else: ?>
                            <div class="lecture-card-thumb-fallback"><i class="bi bi-play-btn"></i></div>
                          <?php endif; ?>
                        </div>
                        <div class="lecture-card-body">
                          <h3 class="h6 fw-bold mb-1"><?= e($lecture['title']) ?></h3>
                          <p class="small text-secondary mb-2"><?= e($lecture['educator_name']) ?> · <?= e($lecture['subject'] ?: 'General') ?></p>
                          <div class="d-flex flex-wrap gap-2 small text-secondary">
                            <span><i class="bi bi-heart"></i> <?= (int) $lecture['like_count'] ?></span>
                            <span><i class="bi bi-chat-dots"></i> <?= (int) $lecture['comment_count'] ?></span>
                          </div>
                        </div>
                      </article>
                    </a>
                  <?php endforeach; ?>
                </div>
              </section>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</section>

<?php if ($selectedLecture): ?>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var feed = document.getElementById('lectureCommentsFeed');
      if (!feed) return;

      var lectureId = feed.getAttribute('data-lecture-id');
      var reloadComments = function () {
        fetch('lecture_comments_feed.php?lecture_id=' + encodeURIComponent(lectureId), {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
          .then(function (response) { return response.text(); })
          .then(function (html) { feed.innerHTML = html; })
          .catch(function () {});
      };

      window.setInterval(reloadComments, 10000);
    });
  </script>
<?php endif; ?>
<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
