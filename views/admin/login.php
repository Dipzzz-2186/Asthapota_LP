<?php
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
ensure_session();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM admins WHERE email = ?');
    $stmt->execute([$email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        $errors[] = 'Invalid credentials.';
    } else {
        $_SESSION['admin_id'] = (int)$admin['id'];
        redirect('/admin/dashboard');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="page">
  <header class="page-header">
    <div class="container">
      <div class="topbar">
        <div class="brand">
          <img class="brand-badge" src="/assets/img/lopad.jpg" alt="Lopad logo">
          <div>
            <div>Temu Padel</div>
            <small style="color:var(--muted);">Admin Access</small>
          </div>
        </div>
        <div class="topbar-actions">
          <a class="btn ghost" href="/"><i class="bi bi-arrow-left"></i> Back</a>
        </div>
      </div>
    </div>
  </header>

  <section class="section admin-hero">
    <div class="container admin-wrap">
      <div class="admin-card fade-up">
        <div class="pill"><i class="bi bi-shield-lock"></i> Admin Portal</div>
        <h1 class="admin-title">Sign in to continue</h1>
        <p class="admin-sub">Masuk untuk mengelola data pendaftaran, paket, dan pembayaran. Akses ini hanya untuk admin yang terdaftar.</p>
        <div class="admin-meta">
          <div><strong>Secure</strong> session-based authentication.</div>
          <div><strong>Private</strong> admin data only.</div>
          <div><strong>Support</strong> contact organizer if locked out.</div>
        </div>
      </div>

      <div class="admin-card fade-up delay-1">
        <div class="section-title">Admin Login</div>

        <?php if ($errors): ?>
          <div class="alert">
            <?php foreach ($errors as $e): ?>
              <div><?= h($e) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form class="form admin-form" method="post" action="">
          <label>
            Email
            <input type="email" name="email" required>
          </label>
          <label>
            Password
            <input type="password" name="password" required>
          </label>
          <button class="btn primary" type="submit">Login</button>
        </form>
      </div>
    </div>
  </section>
</body>
</html>

