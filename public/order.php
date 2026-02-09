<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/config.php';
ensure_session();

if (empty($_SESSION['order_id'])) {
    redirect('/packages.php');
}

$db = get_db();
$order_id = (int)$_SESSION['order_id'];

$order = $db->prepare('SELECT o.*, u.full_name, u.phone, u.email, u.instagram FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?');
$order->execute([$order_id]);
$order = $order->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    redirect('/packages.php');
}

$itemsStmt = $db->prepare('SELECT oi.qty, oi.price, p.name FROM order_items oi JOIN packages p ON p.id = oi.package_id WHERE oi.order_id = ?');
$itemsStmt->execute([$order_id]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please upload a valid payment proof.';
    } else {
        $file = $_FILES['payment_proof'];
        if ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'File is too large. Max 2MB.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
                $errors[] = 'Only JPG or PNG allowed.';
            } else {
                $name = 'proof_' . $order_id . '_' . time() . '.' . $ext;
                $uploadDir = $CONFIG['upload_dir'];
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }
                $target = $uploadDir . '/' . $name;
                if (!move_uploaded_file($file['tmp_name'], $target)) {
                    $errors[] = 'Failed to upload file.';
                } else {
                    $stmt = $db->prepare('UPDATE orders SET payment_proof = ?, status = ? WHERE id = ?');
                    $stmt->execute([$name, 'paid', $order_id]);
                    redirect('/thankyou.php');
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Order Details - Temu Padel</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
  <section class="section">
    <div class="container center">
      <h2 style="font-family:'Playfair Display', serif; font-style: italic;">Order Details</h2>

      <div class="order-summary">
        <div><strong>Full Name*</strong> : <?= h($order['full_name']) ?></div>
        <div><strong>Phone Number*</strong> : <?= h($order['phone']) ?></div>
        <div><strong>E-mail*</strong> : <?= h($order['email']) ?></div>
        <div><strong>Instagram</strong> : <?= h($order['instagram']) ?></div>
        <br>
        <div><strong>Order</strong></div>
        <?php foreach ($items as $it): ?>
          <div><?= (int)$it['qty'] ?> x <?= h($it['name']) ?> @ <?= h(rupiah((int)$it['price'])) ?></div>
        <?php endforeach; ?>
      </div>

      <div class="total container">
        <div>TOTAL TO PAY:</div>
        <div><?= h(rupiah((int)$order['total'])) ?>,-</div>
      </div>

      <div class="container" style="margin-top:16px;">
        <div>Payment to BCA Account</div>
        <div>Account Number : 1234567890</div>
        <div>Account Name : PT Manifestasi Kehidupan Berlimpah</div>
      </div>

      <h2 style="font-family:'Playfair Display', serif; font-style: italic; margin-top:30px;">Upload Your Payment Proof</h2>

      <?php if ($errors): ?>
        <div class="alert">
          <?php foreach ($errors as $e): ?>
            <div><?= h($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" style="margin-top:16px;">
        <input type="file" name="payment_proof" accept="image/*" required>
        <div style="margin-top:16px;">
          <button class="btn" type="submit">Click to Upload</button>
        </div>
      </form>
    </div>
  </section>
</body>
</html>
