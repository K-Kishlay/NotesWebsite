<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/site_settings.php';
require_admin();

$pdo = db();
ensure_site_settings_table($pdo);

function ensure_private_note_dir(): string {
    $dir = rtrim(NOTE_PRIVATE_DIR, '/\\');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function smtp_expect($fp, array $allowedCodes): array {
    $response = '';
    while (($line = fgets($fp, 512)) !== false) {
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }
    $code = (int) substr($response, 0, 3);
    return [in_array($code, $allowedCodes, true), $response];
}

function smtp_send_mail(string $toEmail, string $subject, string $message, ?string &$error = null): bool {
    if (SMTP_HOST === '' || SMTP_USER === '' || SMTP_PASS === '') {
        $error = 'SMTP credentials are missing in config.php';
        return false;
    }

    $secure = strtolower((string) SMTP_SECURE);
    $host = SMTP_HOST;
    $port = (int) SMTP_PORT;
    $remoteHost = ($secure === 'ssl' ? 'ssl://' : '') . $host;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);
    $fp = @stream_socket_client($remoteHost . ':' . $port, $errno, $errstr, (int) SMTP_TIMEOUT, STREAM_CLIENT_CONNECT, $context);
    if (!$fp) {
        $error = 'SMTP connection failed: ' . $errstr;
        return false;
    }
    stream_set_timeout($fp, (int) SMTP_TIMEOUT);

    [$ok, $resp] = smtp_expect($fp, [220]);
    if (!$ok) {
        fclose($fp);
        $error = 'SMTP greeting failed: ' . trim($resp);
        return false;
    }

    fwrite($fp, "EHLO localhost\r\n");
    [$ok, $resp] = smtp_expect($fp, [250]);
    if (!$ok) {
        fclose($fp);
        $error = 'SMTP EHLO failed: ' . trim($resp);
        return false;
    }

    if ($secure === 'tls') {
        fwrite($fp, "STARTTLS\r\n");
        [$ok, $resp] = smtp_expect($fp, [220]);
        if (!$ok || !stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            $error = 'SMTP STARTTLS failed.';
            return false;
        }
        fwrite($fp, "EHLO localhost\r\n");
        [$ok, $resp] = smtp_expect($fp, [250]);
        if (!$ok) {
            fclose($fp);
            $error = 'SMTP EHLO after TLS failed: ' . trim($resp);
            return false;
        }
    }

    fwrite($fp, "AUTH LOGIN\r\n");
    [$ok, $resp] = smtp_expect($fp, [334]);
    if (!$ok) {
        fclose($fp);
        $error = 'SMTP AUTH not accepted: ' . trim($resp);
        return false;
    }
    fwrite($fp, base64_encode(SMTP_USER) . "\r\n");
    [$ok, $resp] = smtp_expect($fp, [334]);
    if (!$ok) {
        fclose($fp);
        $error = 'SMTP username rejected: ' . trim($resp);
        return false;
    }
    fwrite($fp, base64_encode(SMTP_PASS) . "\r\n");
    [$ok, $resp] = smtp_expect($fp, [235]);
    if (!$ok) {
        fclose($fp);
        $error = 'SMTP password rejected: ' . trim($resp);
        return false;
    }

    fwrite($fp, "MAIL FROM:<" . MAIL_FROM . ">\r\n");
    [$ok, $resp] = smtp_expect($fp, [250]);
    if (!$ok) {
        fclose($fp);
        $error = 'MAIL FROM failed: ' . trim($resp);
        return false;
    }
    fwrite($fp, "RCPT TO:<" . $toEmail . ">\r\n");
    [$ok, $resp] = smtp_expect($fp, [250, 251]);
    if (!$ok) {
        fclose($fp);
        $error = 'RCPT TO failed: ' . trim($resp);
        return false;
    }
    fwrite($fp, "DATA\r\n");
    [$ok, $resp] = smtp_expect($fp, [354]);
    if (!$ok) {
        fclose($fp);
        $error = 'DATA command failed: ' . trim($resp);
        return false;
    }

    $headers = [];
    $headers[] = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';
    $headers[] = 'To: <' . $toEmail . '>';
    $headers[] = 'Subject: ' . $subject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $body = str_replace(["\r\n.\r\n", "\n.\n"], ["\r\n..\r\n", "\n..\n"], $message);
    $data = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
    fwrite($fp, $data);
    [$ok, $resp] = smtp_expect($fp, [250]);

    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    if (!$ok) {
        $error = 'SMTP send failed: ' . trim($resp);
        return false;
    }
    return true;
}

function send_broadcast_mail(string $toEmail, string $subject, string $message, ?string &$error = null): bool {
    if (SMTP_HOST === '' || SMTP_USER === '' || SMTP_PASS === '') {
        $error = 'SMTP is not configured. Set SMTP_HOST, SMTP_USER, SMTP_PASS in config.php';
        return false;
    }
    return smtp_send_mail($toEmail, $subject, $message, $error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'set_theme') {
        $theme = normalize_site_theme((string) ($_POST['site_theme'] ?? 'default'));
        set_site_theme($pdo, $theme);
        set_flash('success', 'Website theme updated to ' . ucfirst($theme) . '.');
        redirect('admin.php');
    }

    if ($action === 'send_inapp') {
        $subject = trim($_POST['notice_subject'] ?? '');
        $message = trim($_POST['notice_message'] ?? '');
        $audience = $_POST['notice_audience'] ?? 'india';

        if ($subject === '' || $message === '') {
            set_flash('danger', 'Notification subject and message are required.');
            redirect('admin.php');
        }

        if ($audience === 'all') {
            $userStmt = $pdo->query('SELECT id FROM users');
        } else {
            $userStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) LIKE ?');
            $userStmt->execute(['%.in']);
        }
        $targets = $userStmt->fetchAll();

        if (!$targets) {
            set_flash('warning', 'No recipients found for selected audience.');
            redirect('admin.php');
        }

        $insertNotice = $pdo->prepare('INSERT INTO user_notifications (user_id, title, message) VALUES (?, ?, ?)');
        $count = 0;
        foreach ($targets as $t) {
            $insertNotice->execute([(int)$t['id'], $subject, $message]);
            $count++;
        }

        set_flash('success', 'In-app notification sent to ' . $count . ' users.');
        redirect('admin.php');
    }

    if ($action === 'send_email') {
        $subject = trim($_POST['mail_subject'] ?? '');
        $message = trim($_POST['mail_message'] ?? '');
        $audience = $_POST['mail_audience'] ?? 'india';

        if ($subject === '' || $message === '') {
            set_flash('danger', 'Email subject and message are required.');
            redirect('admin.php');
        }

        if ($audience === 'all') {
            $userStmt = $pdo->query('SELECT email FROM users');
        } else {
            $userStmt = $pdo->prepare('SELECT email FROM users WHERE LOWER(email) LIKE ?');
            $userStmt->execute(['%.in']);
        }

        $recipients = $userStmt->fetchAll();
        if (!$recipients) {
            set_flash('warning', 'No recipients found for selected audience.');
            redirect('admin.php');
        }

        $sent = 0;
        $failed = 0;
        $firstError = '';
        foreach ($recipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed++;
                continue;
            }
            $error = null;
            if (send_broadcast_mail($email, $subject, $message, $error)) {
                $sent++;
            } else {
                $failed++;
                if ($firstError === '' && $error) {
                    $firstError = $error;
                }
            }
        }

        $msg = 'Email broadcast done. Sent: ' . $sent . ', Failed: ' . $failed . '.';
        if ($firstError !== '') {
            $msg .= ' First error: ' . $firstError;
        }
        set_flash('info', $msg);
        redirect('admin.php');
    }

    if ($action === 'update') {
        $noteId = (int) ($_POST['note_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $subject = trim($_POST['subject'] ?? 'General');
        $price = max(0, (float) ($_POST['price'] ?? 0));
        $filePath = basename(trim($_POST['file_path'] ?? ''));

        if ($noteId <= 0 || $title === '') {
            set_flash('danger', 'Invalid note update request.');
            redirect('admin.php');
        }

        $stmt = $pdo->prepare('UPDATE notes SET title = ?, description = ?, subject = ?, price = ?, file_path = ? WHERE id = ?');
        $stmt->execute([$title, $description, $subject, $price, $filePath, $noteId]);

        set_flash('success', 'Note details updated successfully.');
        redirect('admin.php?edit=' . $noteId);
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $subject = trim($_POST['subject'] ?? 'General');
    $type = $_POST['type'] ?? 'free';
    $price = ($type === 'paid') ? max(0, (float) ($_POST['price'] ?? 0)) : 0.00;
    $filePath = '';

    if ($title === '') {
        set_flash('danger', 'Title is required.');
        redirect('admin.php');
    }

    if (!empty($_FILES['note_file']['name']) && $_FILES['note_file']['error'] === UPLOAD_ERR_OK) {
        $tmpPath = $_FILES['note_file']['tmp_name'];
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
            redirect('admin.php');
        }

        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '-', basename($_FILES['note_file']['name']));
        $storedName = 'note-' . time() . '-' . bin2hex(random_bytes(4)) . '-' . $safeBase;
        $privateTarget = ensure_private_note_dir() . DIRECTORY_SEPARATOR . $storedName;

        if (!move_uploaded_file($tmpPath, $privateTarget)) {
            set_flash('danger', 'Unable to store note file securely.');
            redirect('admin.php');
        }

        $filePath = $storedName;
    }

    $stmt = $pdo->prepare('INSERT INTO notes (title, description, subject, price, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $title,
        $description,
        $subject,
        $price,
        $filePath,
        (int) current_user()['id']
    ]);

    set_flash('success', 'Note published successfully with protected file storage.');
    redirect('admin.php');
}

$editId = (int) ($_GET['edit'] ?? 0);
$editNote = null;
if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT id, title, description, subject, price, file_path FROM notes WHERE id = ?');
    $editStmt->execute([$editId]);
    $editNote = $editStmt->fetch();
}

$sales = $pdo->query('SELECT COALESCE(SUM(amount),0) AS total_sales, COUNT(*) AS total_orders FROM purchases WHERE payment_status = "paid"')->fetch();
$users = $pdo->query('SELECT COUNT(*) AS total_users FROM users')->fetch();
$notesCount = $pdo->query('SELECT COUNT(*) AS total_notes FROM notes')->fetch();

$recentSales = $pdo->query('SELECT n.title, u.name, p.amount, p.purchased_at
FROM purchases p
JOIN notes n ON n.id = p.note_id
JOIN users u ON u.id = p.user_id
WHERE p.payment_status = "paid"
ORDER BY p.purchased_at DESC
LIMIT 10')->fetchAll();

$recentUsers = $pdo->query('SELECT name, created_at FROM users ORDER BY created_at DESC LIMIT 10')->fetchAll();
$currentTheme = get_site_theme($pdo);

$managedNotes = $pdo->query('SELECT n.id, n.title, n.subject, n.price, n.file_path, n.created_at, u.name AS uploader
FROM notes n
LEFT JOIN users u ON u.id = n.uploaded_by
ORDER BY n.created_at DESC')->fetchAll();

$pageTitle = 'NotesPro | Admin Panel';
$activePage = 'admin';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar d-flex flex-wrap gap-3 justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 fw-bold mb-1">Admin Dashboard</h1>
    <p class="text-secondary mb-0">Upload notes securely, edit details, send notifications, and track growth.</p>
  </div>
</header>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Festival Theme Control</h2>
    <form class="row g-3 align-items-end" method="post" action="admin.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="set_theme">
      <div class="col-md-6">
        <label class="form-label">Select Website Theme</label>
        <select class="form-select" name="site_theme">
          <option value="default" <?= $currentTheme === 'default' ? 'selected' : '' ?>>Default</option>
          <option value="diwali" <?= $currentTheme === 'diwali' ? 'selected' : '' ?>>Diwali</option>
          <option value="christmas" <?= $currentTheme === 'christmas' ? 'selected' : '' ?>>Christmas</option>
          <option value="holi" <?= $currentTheme === 'holi' ? 'selected' : '' ?>>Holi</option>
          <option value="eid" <?= $currentTheme === 'eid' ? 'selected' : '' ?>>Eid</option>
          <option value="chhath" <?= $currentTheme === 'chhath' ? 'selected' : '' ?>>Chhat / Chhath</option>
        </select>
      </div>
      <div class="col-md-6 d-grid d-md-flex justify-content-md-end">
        <button class="btn btn-brand" type="submit">Apply Theme</button>
      </div>
    </form>
  </div>
</section>

<section class="row g-3 mb-4">
  <div class="col-12 col-md-4"><div class="metric-card"><p>Total Sales</p><h2>$<?= number_format((float)$sales['total_sales'], 2) ?></h2></div></div>
  <div class="col-12 col-md-4"><div class="metric-card"><p>Active Users</p><h2><?= (int)$users['total_users'] ?></h2></div></div>
  <div class="col-12 col-md-4"><div class="metric-card"><p>Published Notes</p><h2><?= (int)$notesCount['total_notes'] ?></h2></div></div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Simple User Notification (Recommended)</h2>
    <form class="row g-3 mb-4" method="post" action="admin.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_inapp">
      <div class="col-md-3">
        <label class="form-label">Audience</label>
        <select class="form-select" name="notice_audience">
          <option value="india">India users (.in)</option>
          <option value="all">All users</option>
        </select>
      </div>
      <div class="col-md-9">
        <label class="form-label">Title</label>
        <input class="form-control" name="notice_subject" placeholder="New notes uploaded" required>
      </div>
      <div class="col-12">
        <label class="form-label">Message</label>
        <textarea class="form-control" name="notice_message" rows="3" placeholder="Write a short in-app message..." required></textarea>
      </div>
      <div class="col-12"><button class="btn btn-brand" type="submit">Send In-App Notification</button></div>
    </form>

    <hr class="my-4">
    <h2 class="h5 fw-bold mb-3">One-Click Email Notification</h2>
    <form class="row g-3" method="post" action="admin.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_email">
      <div class="col-md-3">
        <label class="form-label">Audience</label>
        <select class="form-select" name="mail_audience">
          <option value="india">India emails (.in)</option>
          <option value="all">All users</option>
        </select>
      </div>
      <div class="col-md-9">
        <label class="form-label">Subject</label>
        <input class="form-control" name="mail_subject" placeholder="Important update from institute" required>
      </div>
      <div class="col-12">
        <label class="form-label">Message</label>
        <textarea class="form-control" name="mail_message" rows="3" placeholder="Write your message for users..." required></textarea>
      </div>
      <div class="col-12">
        <small class="text-secondary">For reliable delivery, set `SMTP_HOST`, `SMTP_USER`, and `SMTP_PASS` in `config.php`.</small>
      </div>
      <div class="col-12"><button class="btn btn-brand" type="submit">Send Notification</button></div>
    </form>
  </div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Upload New Note</h2>
    <form class="row g-3" method="post" action="admin.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-md-6">
        <label class="form-label">Note Title</label>
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
        <input class="form-control" name="subject" placeholder="Web Development">
      </div>
      <div class="col-md-6">
        <label class="form-label">File (pdf/doc/docx/txt/zip)</label>
        <input class="form-control" type="file" name="note_file">
      </div>
      <div class="col-12">
        <label class="form-label">Description</label>
        <textarea class="form-control" name="description" rows="3"></textarea>
      </div>
      <div class="col-12"><button class="btn btn-brand" type="submit">Publish Note</button></div>
    </form>
  </div>
</section>

<?php if ($editNote): ?>
<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="h5 fw-bold mb-0">Edit Note #<?= (int)$editNote['id'] ?></h2>
      <a href="admin.php" class="btn btn-sm btn-outline-secondary">Close</a>
    </div>
    <form class="row g-3" method="post" action="admin.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="note_id" value="<?= (int)$editNote['id'] ?>">
      <div class="col-md-6">
        <label class="form-label">Note Title</label>
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
        <label class="form-label">Stored File Key</label>
        <input class="form-control" name="file_path" value="<?= e($editNote['file_path']) ?>" placeholder="note-...-file.pdf">
      </div>
      <div class="col-12">
        <label class="form-label">Description</label>
        <textarea class="form-control" name="description" rows="3"><?= e($editNote['description']) ?></textarea>
      </div>
      <div class="col-12"><button class="btn btn-brand" type="submit">Save Changes</button></div>
    </form>
  </div>
</section>
<?php endif; ?>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Manage Notes</h2>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Title</th><th>Subject</th><th>Price</th><th>Uploader</th><th>Date</th><th></th></tr></thead>
        <tbody>
          <?php if (!$managedNotes): ?>
            <tr><td colspan="6" class="text-secondary">No notes available.</td></tr>
          <?php else: foreach ($managedNotes as $n): ?>
            <tr>
              <td><?= e($n['title']) ?></td>
              <td><?= e($n['subject'] ?: 'General') ?></td>
              <td><?= ((float)$n['price'] > 0) ? '$' . number_format((float)$n['price'], 2) : 'Free' ?></td>
              <td><?= e($n['uploader'] ?: 'Unknown') ?></td>
              <td><?= e(date('M d, Y', strtotime($n['created_at']))) ?></td>
              <td class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="admin.php?edit=<?= (int)$n['id'] ?>">Edit</a>
                <?php if (!empty($n['file_path'])): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="download.php?note_id=<?= (int)$n['id'] ?>">Test File</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Recent Sales</h2>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Note</th><th>User</th><th>Amount</th><th>Date</th></tr></thead>
        <tbody>
          <?php if (!$recentSales): ?>
            <tr><td colspan="4" class="text-secondary">No sales yet.</td></tr>
          <?php else: foreach ($recentSales as $sale): ?>
            <tr>
              <td><?= e($sale['title']) ?></td>
              <td><?= e($sale['name']) ?></td>
              <td>$<?= number_format((float)$sale['amount'], 2) ?></td>
              <td><?= e(date('M d, Y', strtotime($sale['purchased_at']))) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="card border-0 shadow-sm">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-3">Recent Users</h2>
    <ul class="list-group list-group-flush">
      <?php foreach ($recentUsers as $u): ?>
        <li class="list-group-item px-0 d-flex justify-content-between"><span><?= e($u['name']) ?></span><span class="text-secondary">Joined <?= e(date('M d, Y', strtotime($u['created_at']))) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
