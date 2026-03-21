<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $action = $_POST['action'] ?? 'update_profile';

    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            set_flash('danger', 'All password fields are required.');
            redirect('profile.php');
        }

        if (strlen($newPassword) < 8) {
            set_flash('danger', 'New password must be at least 8 characters.');
            redirect('profile.php');
        }

        if (!password_is_strong($newPassword)) {
            set_flash('danger', 'Password must include uppercase, lowercase, number, and special character.');
            redirect('profile.php');
        }

        if ($newPassword !== $confirmPassword) {
            set_flash('danger', 'New password and confirm password do not match.');
            redirect('profile.php');
        }

        $userStmt = db()->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([(int) current_user()['id']]);
        $userRow = $userStmt->fetch();
        if (!$userRow || !password_verify($currentPassword, $userRow['password'])) {
            set_flash('danger', 'Current password is incorrect.');
            redirect('profile.php');
        }

        $updatePass = db()->prepare('UPDATE users SET password = ? WHERE id = ?');
        $updatePass->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) current_user()['id']]);
        set_flash('success', 'Password changed successfully.');
        redirect('profile.php');
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $avatarPath = current_user()['avatar'] ?? null;

    if ($name === '' || $email === '') {
        set_flash('danger', 'Name and email are required.');
        redirect('profile.php');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        set_flash('danger', 'Invalid email format.');
        redirect('profile.php');
    }

    if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $tmpPath = $_FILES['avatar']['tmp_name'];
        if ((int)($_FILES['avatar']['size'] ?? 0) > 2 * 1024 * 1024) {
            set_flash('danger', 'Avatar file is too large. Max 2MB.');
            redirect('profile.php');
        }
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        $mime = mime_content_type($tmpPath) ?: '';
        if (!isset($allowed[$mime])) {
            set_flash('danger', 'Only JPG, PNG, and WEBP images are allowed.');
            redirect('profile.php');
        }
        if (@getimagesize($tmpPath) === false) {
            set_flash('danger', 'Invalid image file.');
            redirect('profile.php');
        }

        $avatarDir = __DIR__ . '/uploads/avatars';
        if (!is_dir($avatarDir)) {
            mkdir($avatarDir, 0775, true);
        }

        $filename = 'avatar-' . (int) current_user()['id'] . '-' . time() . '.' . $allowed[$mime];
        $targetPath = $avatarDir . '/' . $filename;
        if (!move_uploaded_file($tmpPath, $targetPath)) {
            set_flash('danger', 'Unable to upload profile picture.');
            redirect('profile.php');
        }
        $avatarPath = 'uploads/avatars/' . $filename;
    }

    try {
        $stmt = db()->prepare('UPDATE users SET name = ?, email = ?, avatar = ? WHERE id = ?');
        $stmt->execute([$name, strtolower($email), $avatarPath, (int) current_user()['id']]);
        refresh_session_user(db());
        set_flash('success', 'Profile updated.');
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            set_flash('danger', 'Email already in use.');
        } else {
            set_flash('danger', 'Unable to update profile.');
        }
    }

    redirect('profile.php');
}

$user = current_user();
$pageTitle = 'NotesPro | Profile';
$activePage = 'profile';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar mb-4">
  <h1 class="h3 fw-bold mb-1">Profile Settings</h1>
  <p class="text-secondary mb-0">Manage your account and purchase preferences.</p>
</header>

<div class="row g-3">
  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body text-center p-4">
        <?php if (!empty($user['avatar'])): ?>
          <img src="<?= e($user['avatar']) ?>" alt="Profile picture" class="avatar avatar-image mb-3">
        <?php else: ?>
          <div class="avatar mb-3"><?= e(strtoupper(substr($user['name'], 0, 2))) ?></div>
        <?php endif; ?>
        <h2 class="h5 fw-bold mb-1"><?= e($user['name']) ?></h2>
        <p class="text-secondary mb-3"><?= e(ucfirst($user['role'])) ?> Plan</p>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-body p-4">
        <form method="post" action="profile.php" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_profile">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Display Picture (DP)</label>
              <input class="form-control" type="file" name="avatar" accept="image/jpeg,image/png,image/webp">
              <small class="text-secondary">Allowed: JPG, PNG, WEBP</small>
            </div>
            <div class="col-12">
              <label class="form-label">Full Name</label>
              <input class="form-control" name="name" value="<?= e($user['name']) ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label">Email</label>
              <input class="form-control" type="email" name="email" value="<?= e($user['email']) ?>" required>
            </div>
          </div>
          <div class="mt-4 d-flex gap-2">
            <button class="btn btn-brand" type="submit">Save changes</button>
          </div>
        </form>
      </div>
    </div>
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold mb-3">Change Password</h2>
        <form method="post" action="profile.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Current Password</label>
              <input class="form-control" type="password" name="current_password" required>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">New Password</label>
              <input class="form-control" type="password" name="new_password" minlength="8" required>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Confirm New Password</label>
              <input class="form-control" type="password" name="confirm_password" minlength="8" required>
            </div>
          </div>
          <div class="mt-4 d-flex gap-2">
            <button class="btn btn-brand" type="submit">Update Password</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
