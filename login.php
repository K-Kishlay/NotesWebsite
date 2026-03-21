<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!isset($_SESSION['login_failures']) || !is_array($_SESSION['login_failures'])) {
        $_SESSION['login_failures'] = [];
    }
    $windowStart = time() - 600;
    $_SESSION['login_failures'] = array_values(array_filter($_SESSION['login_failures'], static function ($ts) use ($windowStart) {
        return (int) $ts >= $windowStart;
    }));
    if (count($_SESSION['login_failures']) >= 5) {
        set_flash('danger', 'Too many failed logins. Please wait 10 minutes and try again.');
        redirect('login.php');
    }

    if ($email === '' || $password === '') {
        set_flash('danger', 'Email and password are required.');
        redirect('login.php');
    }

    $stmt = db()->prepare('SELECT id, name, email, avatar, password, role, created_at FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower($email)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        $_SESSION['login_failures'][] = time();
        set_flash('danger', 'Invalid credentials.');
        redirect('login.php');
    }

    $_SESSION['login_failures'] = [];
    session_regenerate_id(true);
    unset($user['password']);
    $_SESSION['user'] = $user;

    set_flash('success', 'Logged in successfully.');
    redirect('index.php');
}

$pageTitle = 'NotesPro | Login';
$authPage = true;
require __DIR__ . '/includes/header.php';
?>
<main class="container min-vh-100 d-flex align-items-center py-5">
  <div class="row g-4 w-100 align-items-center">
    <div class="col-lg-6">
      <div class="brand-panel p-4 p-md-5">
        <span class="badge text-bg-light mb-3">Productive Notes</span>
        <h1 class="display-6 fw-bold mb-3">Capture ideas. Organize work. Move faster.</h1>
        <p class="lead mb-4">A clean, professional workspace for notes, prep material, and premium study content.</p>
        <div class="d-flex flex-wrap gap-3 stat-grid">
          <div class="stat-card">
            <h2>1.2k+</h2>
            <p>Notes sold this month</p>
          </div>
          <div class="stat-card">
            <h2>98%</h2>
            <p>User satisfaction score</p>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card auth-card border-0 shadow-sm">
        <div class="card-body p-4 p-md-5">
          <h2 class="h3 fw-bold mb-1">Welcome back</h2>
          <p class="text-secondary mb-4">Sign in to continue.</p>
          <form method="post" action="login.php">
            <?= csrf_field() ?>
            <div class="mb-3">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" placeholder="name@company.com" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Password</label>
              <input type="password" name="password" class="form-control" placeholder="Enter password" required>
            </div>
            <button type="submit" class="btn btn-brand w-100 py-2">Sign in</button>
          </form>
          <p class="text-center text-secondary mt-4 mb-0">New here? <a class="text-decoration-none" href="register.php">Create an account</a></p>
        </div>
      </div>
    </div>
  </div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
