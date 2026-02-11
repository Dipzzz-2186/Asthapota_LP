<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
ensure_session();

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
  <style>
    body {
      margin: 0;
      min-height: 100%;
      color: #eef4ff;
      font-family: "Segoe UI", Tahoma, sans-serif;
      background: url('/assets/img/wallpaper.avif') center/cover no-repeat fixed;
      overflow-x: hidden;
    }

    .order-shell {
      min-height: 100vh;
      width: min(1260px, 95vw);
      margin: 0 auto;
      padding: 42px 0 56px;
      display: flex;
      align-items: center;
    }

    .order-full {
      width: 100%;
      padding: clamp(24px, 3.1vw, 42px);
      display: grid;
      grid-template-columns: 1.1fr 0.9fr;
      gap: 22px;
      background: rgba(23, 45, 79, 0.58);
      border: 1px solid rgba(255, 255, 255, 0.4);
      border-radius: 20px;
      backdrop-filter: blur(7px);
      box-shadow: 0 14px 34px rgba(0, 0, 0, 0.32);
    }

    .order-panel {
      background: rgba(25, 52, 91, 0.62);
      border: 1px solid rgba(255, 255, 255, 0.42);
      border-radius: 16px;
      padding: clamp(20px, 2.4vw, 34px);
      backdrop-filter: blur(3px);
    }

    .section-title {
      margin-bottom: 16px;
      color: #fff;
      font-size: clamp(24px, 2.8vw, 36px);
    }

    .order-meta {
      display: grid;
      gap: 10px;
      color: #d6e3ff;
      line-height: 1.55;
    }

    .order-list {
      margin-top: 14px;
      display: grid;
      gap: 8px;
      color: #f7faff;
    }

    .total {
      margin-top: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 14px;
      padding-top: 12px;
      border-top: 1px solid rgba(255, 255, 255, 0.25);
      font-weight: 800;
      font-size: clamp(24px, 2.6vw, 34px);
      color: #fff;
    }

    .payment-card {
      background: rgba(255, 255, 255, 0.14);
      border: 1px solid rgba(255, 255, 255, 0.3);
      border-radius: 14px;
      padding: 16px;
      color: #eef4ff;
    }

    .upload-box {
      border: 2px dashed rgba(255, 255, 255, 0.45);
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.12);
      padding: 16px;
    }

    .upload-box input[type="file"] {
      width: 100%;
      color: #fff;
    }

    .upload-box input[type="file"]::file-selector-button {
      border: 0;
      border-radius: 8px;
      padding: 10px 14px;
      margin-right: 10px;
      background: #fff;
      color: #0b2d61;
      font-weight: 700;
      cursor: pointer;
    }

    .btn.primary {
      background: #ffffff;
      color: #0b2d61;
      box-shadow: none;
    }

    .btn.primary::before {
      display: none;
    }

    .btn.primary:hover {
      background: #dbe9ff;
      transform: translateY(-1px);
      box-shadow: none;
    }

    .alert {
      background: rgba(255, 99, 99, 0.2);
      border-color: rgba(255, 140, 140, 0.45);
      color: #ffe7e7;
    }

    @media (max-width: 960px) {
      .order-full {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <main class="order-shell">
    <div class="order-full">
      <section class="order-panel order-summary fade-up">
        <div class="section-title">Order Details</div>
        <div class="order-meta">
          <div><strong>Full Name</strong> : <?= h($order['full_name']) ?></div>
          <div><strong>Phone Number</strong> : <?= h($order['phone']) ?></div>
          <div><strong>E-mail</strong> : <?= h($order['email']) ?></div>
          <div><strong>Instagram</strong> : <?= $instagramLabel ? h($instagramLabel) : '-' ?></div>
        </div>
        <div style="margin-top:16px;"><strong>Order</strong></div>
        <div class="order-list">
        <?php foreach ($items as $it): ?>
          <div><?= (int)$it['qty'] ?> x <?= h($it['name']) ?> @ <?= h(rupiah((int)$it['price'])) ?></div>
        <?php endforeach; ?>
        </div>

        <div class="total">
          <div>Total to Pay:</div>
          <div><?= h(rupiah((int)$order['total'])) ?>,-</div>
        </div>
      </section>

      <section class="order-panel form-wrap fade-up delay-1">
        <div class="section-title">Payment Info</div>
        <div class="payment-card" style="margin-bottom:16px;">
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
      </section>
    </div>
  </main>
</body>
</html>
