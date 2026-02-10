<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/layout/app.php';
ensure_session();

$isAdmin = is_admin_logged_in();
if (empty($_SESSION['user_id'])) {
    redirect('/register?notice=register_required');
}

if (empty($_SESSION['order_id'])) {
    redirect('/packages');
}

$db = get_db();
$order_id = (int)$_SESSION['order_id'];

$order = $db->prepare('SELECT o.*, u.full_name, u.phone, u.email, u.instagram FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?');
$order->execute([$order_id]);
$order = $order->fetch(PDO::FETCH_ASSOC);

if (!$order || (int)$order['user_id'] !== (int)$_SESSION['user_id']) {
    unset($_SESSION['order_id']);
    redirect('/packages');
}

$itemsStmt = $db->prepare('SELECT oi.qty, oi.price, p.name FROM order_items oi JOIN packages p ON p.id = oi.package_id WHERE oi.order_id = ?');
$itemsStmt->execute([$order_id]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$instagramLabel = '';
if (!empty($order['instagram'])) {
    $instagramLabel = '@' . ltrim($order['instagram'], '@');
}

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
                    $order['status'] = 'paid';
                    send_invoice_email($order, $items, $order['email']);
                    redirect('/thankyou');
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
  <title>Order Details - Asthapora</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="page">
<?php render_navbar(['isAdmin' => $isAdmin]); ?>

  <section class="section">
    <div class="container grid-2">
      <div class="order-summary fade-up">
        <div class="section-title">Order Details</div>
        <div><strong>Full Name</strong> : <?= h($order['full_name']) ?></div>
        <div><strong>Phone Number</strong> : <?= h($order['phone']) ?></div>
        <div><strong>E-mail</strong> : <?= h($order['email']) ?></div>
        <div><strong>Instagram</strong> : <?= $instagramLabel ? h($instagramLabel) : '-' ?></div>
        <div style="margin-top:12px;"><strong>Order</strong></div>
        <?php foreach ($items as $it): ?>
          <div><?= (int)$it['qty'] ?> x <?= h($it['name']) ?> @ <?= h(rupiah((int)$it['price'])) ?></div>
        <?php endforeach; ?>

        <div class="total">
          <div>Total to Pay:</div>
          <div><?= h(rupiah((int)$order['total'])) ?>,-</div>
        </div>
      </div>

      <div class="form-wrap fade-up delay-1">
        <div class="section-title">Payment Info</div>
        <div class="card soft" style="margin-bottom:16px;">
          <div><strong>Payment to BCA Account</strong></div>
          <div>Account Number: 1234567890</div>
          <div>Account Name: PT Manifestasi Kehidupan Berlimpah</div>
        </div>

        <div class="section-title">Upload Your Payment Proof</div>

        <?php if ($errors): ?>
          <div class="alert">
            <?php foreach ($errors as $e): ?>
              <div><?= h($e) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
          <div class="upload-box">
            <input type="file" name="payment_proof" accept="image/*" required>
          </div>
          <div style="margin-top:16px;">
            <button class="btn primary" type="submit">Upload Proof <i class="bi bi-upload"></i></button>
          </div>
        </form>
      </div>
    </div>
  </section>
</body>
</html>

