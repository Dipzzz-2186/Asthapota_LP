<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
ensure_session();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $instagram = trim($_POST['instagram'] ?? '');

    if ($full_name === '') $errors[] = 'Full name is required.';
    if ($phone === '') $errors[] = 'Phone number is required.';
    if ($email === '') $errors[] = 'Email is required.';

    if (!$errors) {
        $db = get_db();
        $stmt = $db->prepare('INSERT INTO users (full_name, phone, email, instagram, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$full_name, $phone, $email, $instagram, date('c')]);
        $_SESSION['user_id'] = (int)$db->lastInsertId();
        redirect('/packages.php');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register - Temu Padel</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="page">
  <header class="page-header">
    <div class="container">
      <div class="topbar">
        <div class="brand">
          <div class="brand-badge">TP</div>
          <div>
            <div>Temu Padel</div>
            <small style="color:var(--muted);">Register to unlock packages</small>
          </div>
        </div>
        <div class="topbar-actions">
          <a class="btn ghost" href="/"><i class="bi bi-arrow-left"></i> Back</a>
          <a class="btn primary" href="/packages.php">Packages <i class="bi bi-bag"></i></a>
        </div>
      </div>
    </div>
  </header>

  <section class="section">
    <div class="container grid-2">
      <div class="hero-card fade-up">
        <div class="pill"><i class="bi bi-person-plus"></i> Registration</div>
        <h1>Register Yourself</h1>
        <p>Fill in your details to continue. After registration you can select your preferred packages.</p>
        <div class="hero-meta">
          <div class="meta-card">
            <strong><i class="bi bi-check-circle"></i> Required</strong>
            Full name, phone number, and email.
          </div>
          <div class="meta-card">
            <strong><i class="bi bi-instagram"></i> Optional</strong>
            Instagram handle for updates.
          </div>
          <div class="meta-card">
            <strong><i class="bi bi-shield-check"></i> Secure</strong>
            We keep your data private.
          </div>
        </div>
      </div>
      <div class="form-wrap fade-up delay-1">
        <div class="section-title">Registration Form</div>

        <?php if (!empty($_GET['notice']) && $_GET['notice'] === 'register_required'): ?>
          <div class="alert">Please register first to continue to package selection.</div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="alert">
            <?php foreach ($errors as $e): ?>
              <div><?= h($e) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form class="form" method="post" action="">
          <label>
            Full Name*
            <input type="text" name="full_name" required>
          </label>
          <label>
            Phone Number*
            <input type="text" name="phone" required>
          </label>
          <label>
            E-mail*
            <input type="email" name="email" required>
          </label>
          <label>
            Instagram
            <input type="text" name="instagram">
          </label>
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <button class="btn primary" type="submit">Continue <i class="bi bi-arrow-right"></i></button>
            <a class="btn ghost" href="/">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </section>
</body>
</html>
