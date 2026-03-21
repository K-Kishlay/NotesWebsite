<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        set_flash('danger', 'All fields are required.');
        redirect('register.php');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        set_flash('danger', 'Invalid email format.');
        redirect('register.php');
    }

    if (!password_is_strong($password)) {
        set_flash('danger', 'Password must be 8+ chars and include uppercase, lowercase, number, and special character.');
        redirect('register.php');
    }

    try {
        $stmt = db()->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT), 'student']);

        $userId = (int) db()->lastInsertId();
        $fetch = db()->prepare('SELECT id, name, email, avatar, role, created_at FROM users WHERE id = ?');
        $fetch->execute([$userId]);
        session_regenerate_id(true);
        $_SESSION['user'] = $fetch->fetch();

        set_flash('success', 'Registration successful.');
        redirect('index.php');
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            set_flash('danger', 'Email already exists.');
            redirect('register.php');
        }
        set_flash('danger', 'Registration failed.');
        redirect('register.php');
    }
}

$pageTitle = 'NotesPro | Register';
$authPage = true;
require __DIR__ . '/includes/header.php';
?>
<main class="container min-vh-100 d-flex align-items-center py-5">
  <div class="row g-4 w-100 align-items-center">
    <div class="col-lg-6 order-lg-2">
      <div class="brand-panel p-4 p-md-5">
        <span class="badge text-bg-light mb-3">Free to Start</span>
        <h1 class="display-6 fw-bold mb-3">Create your notes workspace in seconds.</h1>
        <p class="lead mb-4">Plan tasks, document progress, and keep your team aligned.</p>
        <ul class="list-unstyled feature-list mb-0">
          <li>Structured notebooks and tags</li>
          <li>Fast search and pinned notes</li>
          <li>Responsive experience on all devices</li>
        </ul>
      </div>
    </div>
    <div class="col-lg-6 order-lg-1">
      <div class="card auth-card border-0 shadow-sm">
        <div class="card-body p-4 p-md-5">
          <h2 class="h3 fw-bold mb-1">Create account</h2>
          <p class="text-secondary mb-4">Start organizing your work with NotesPro.</p>
          <form method="post" action="register.php">
            <?= csrf_field() ?>
            <div class="mb-3">
              <label class="form-label">Full name</label>
              <input type="text" name="name" class="form-control" placeholder="Alex Morgan" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" placeholder="name@company.com" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Password</label>
              <input type="password" name="password" class="form-control" placeholder="Create password" required>
              <small class="text-secondary">Use 8+ chars with uppercase, lowercase, number, and symbol.</small>
            </div>
            <button type="submit" class="btn btn-brand w-100 py-2">Create account</button>
          </form>
          <p class="text-center text-secondary mt-4 mb-0">Already have an account? <a class="text-decoration-none" href="login.php">Sign in</a></p>
        </div>
      </div>
    </div>
  </div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
