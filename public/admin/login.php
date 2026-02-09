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
        redirect('/admin/dashboard.php');
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
<body>
  <section class="section">
    <div class="container center">
      <h2 style="font-family:'Playfair Display', serif;">Admin Login</h2>

      <?php if ($errors): ?>
        <div class="alert">
          <?php foreach ($errors as $e): ?>
            <div><?= h($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form class="form" method="post" action="">
        <label>
          Email
          <input type="email" name="email" required>
        </label>
        <label>
          Password
          <input type="password" name="password" required>
        </label>
        <div class="center">
          <button class="btn" type="submit">Login</button>
        </div>
      </form>
    </div>
  </section>
</body>
</html>
