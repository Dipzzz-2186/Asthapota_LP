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
<body>
  <section class="section">
    <div class="container center">
      <h2 style="font-family:'Playfair Display', serif; font-style: italic;">Please Register Yourself</h2>

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
        <div class="center">
          <button class="btn" type="submit">Next</button>
        </div>
      </form>
    </div>
  </section>
</body>
</html>
