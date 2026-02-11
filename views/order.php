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
  <title>Order Details - Temu Padel 2026</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Anton&family=Manrope:wght@400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,600;0,700;1,500&display=swap');
    @import url('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css');

    :root {
      --font-body: "Manrope", "Segoe UI", Tahoma, sans-serif;
      --font-display: "Anton", "Arial Narrow", Impact, sans-serif;
      --font-accent: "Playfair Display", Georgia, serif;
    }

    body {
      margin: 0;
      min-height: 100%;
      color: #eef4ff;
      font-family: var(--font-body);
      font-weight: 500;
      letter-spacing: 0.2px;
      background: url('/assets/img/wallpaper2.jpg') center/cover no-repeat fixed;
      overflow-x: hidden;
      opacity: 0;
      transform: translateY(14px) scale(0.99);
      filter: blur(8px);
      transition: opacity 0.55s ease, transform 0.55s ease, filter 0.55s ease;
    }

    body.page-ready {
      opacity: 1;
      transform: none;
      filter: none;
    }

    body.page-leaving {
      opacity: 0;
      transform: translateY(-10px) scale(0.99);
      filter: blur(8px);
      pointer-events: none;
      transition: opacity 0.28s ease, transform 0.28s ease, filter 0.28s ease;
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
      transition: transform 0.18s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    }

    .order-full:hover {
      transform: translateY(-3px);
      box-shadow: 0 18px 42px rgba(0, 0, 0, 0.35);
      border-color: rgba(255, 255, 255, 0.56);
    }

    .order-panel {
      background: rgba(25, 52, 91, 0.62);
      border: 1px solid rgba(255, 255, 255, 0.42);
      border-radius: 16px;
      padding: clamp(20px, 2.4vw, 34px);
      backdrop-filter: blur(3px);
      transition: transform 0.18s ease, border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .order-panel:hover {
      transform: translateY(-2px);
      border-color: rgba(255, 255, 255, 0.62);
      box-shadow: 0 14px 26px rgba(0, 0, 0, 0.24);
    }

    .section-title {
      margin-bottom: 16px;
      color: #fff;
      font-family: var(--font-accent);
      font-size: clamp(28px, 3.1vw, 40px);
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 10px;
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
      font-family: var(--font-display);
      letter-spacing: 0.8px;
      color: #fff;
    }

    .payment-card {
      background: rgba(255, 255, 255, 0.14);
      border: 1px solid rgba(255, 255, 255, 0.3);
      border-radius: 14px;
      padding: 16px;
      color: #eef4ff;
      transition: border-color 0.2s ease, background 0.2s ease;
    }

    .payment-card:hover {
      border-color: rgba(255, 255, 255, 0.55);
      background: rgba(255, 255, 255, 0.2);
    }

    .upload-box {
      border: 2px dashed rgba(255, 255, 255, 0.45);
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.12);
      padding: 16px;
      transition: border-color 0.2s ease, background 0.2s ease;
    }

    .upload-box:hover {
      border-color: rgba(255, 255, 255, 0.7);
      background: rgba(255, 255, 255, 0.18);
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
      transition: transform 0.15s ease, background 0.2s ease;
    }

    .upload-box input[type="file"]::file-selector-button:hover {
      transform: translateY(-1px);
      background: #dbe9ff;
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
      transform: translateY(-2px);
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

    @media (prefers-reduced-motion: reduce) {
      body,
      body.page-ready,
      body.page-leaving {
        opacity: 1;
        transform: none;
        filter: none;
        transition: none;
      }
    }
  </style>
</head>
<body>
  <main class="order-shell">
    <div class="order-full">
      <section class="order-panel order-summary fade-up">
        <div class="section-title"><i class="bi bi-receipt-cutoff"></i> Order Details</div>
        <div class="order-meta">
          <div><i class="bi bi-person-badge"></i> <strong>Full Name</strong> : <?= h($order['full_name']) ?></div>
          <div><i class="bi bi-telephone"></i> <strong>Phone Number</strong> : <?= h($order['phone']) ?></div>
          <div><i class="bi bi-envelope"></i> <strong>E-mail</strong> : <?= h($order['email']) ?></div>
          <div><i class="bi bi-instagram"></i> <strong>Instagram</strong> : <?= $instagramLabel ? h($instagramLabel) : '-' ?></div>
        </div>
        <div style="margin-top:16px;"><i class="bi bi-box-seam"></i> <strong>Order</strong></div>
        <div class="order-list">
        <?php foreach ($items as $it): ?>
          <div><i class="bi bi-check2-circle"></i> <?= (int)$it['qty'] ?> x <?= h($it['name']) ?> @ <?= h(rupiah((int)$it['price'])) ?></div>
        <?php endforeach; ?>
        </div>

        <div class="total">
          <div><i class="bi bi-wallet2"></i> Total to Pay:</div>
          <div><?= h(rupiah((int)$order['total'])) ?>,-</div>
        </div>
      </section>

      <section class="order-panel form-wrap fade-up delay-1">
        <div class="section-title"><i class="bi bi-credit-card-2-front"></i> Payment Info</div>
        <div class="payment-card" style="margin-bottom:16px;">
          <div><i class="bi bi-bank"></i> <strong>Payment to BCA Account</strong></div>
          <div><i class="bi bi-123"></i> Account Number: 1234567890</div>
          <div><i class="bi bi-building"></i> Account Name: PT Manifestasi Kehidupan Berlimpah</div>
        </div>

        <div class="section-title"><i class="bi bi-cloud-arrow-up"></i> Upload Your Payment Proof</div>

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
            <button class="btn primary" type="submit"><i class="bi bi-upload"></i> Upload Proof</button>
          </div>
        </form>
      </section>
    </div>
  </main>
  <script>
    (function () {
      var body = document.body;
      var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (body && !reduceMotion) {
        requestAnimationFrame(function () {
          body.classList.add('page-ready');
        });
      } else if (body) {
        body.classList.add('page-ready');
      }

      function canAnimateLink(a) {
        if (!a) return false;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#') return false;
        if (href.indexOf('javascript:') === 0 || href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0) return false;
        if (a.target && a.target !== '_self') return false;
        try {
          var next = new URL(a.href, window.location.href);
          return next.origin === window.location.origin;
        } catch (err) {
          return false;
        }
      }

      document.querySelectorAll('a[href]').forEach(function (a) {
        a.addEventListener('click', function (e) {
          if (reduceMotion || !body || !canAnimateLink(a) || e.defaultPrevented) return;
          e.preventDefault();
          if (body.classList.contains('page-leaving')) return;
          body.classList.add('page-leaving');
          window.setTimeout(function () {
            window.location.href = a.href;
          }, 260);
        });
      });
    })();
  </script>
</body>
</html>
