<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
ensure_session();

if (empty($_SESSION['user_id'])) {
    redirect('/register?from=packages&notice=register_required');
}

$draft = $_SESSION['order_draft'] ?? null;
if (
    !$draft
    || !is_array($draft)
    || (int)($draft['user_id'] ?? 0) !== (int)$_SESSION['user_id']
    || empty($draft['items'])
) {
    redirect('/packages');
}

$db = get_db();
$userStmt = $db->prepare('SELECT id, full_name, phone, email, instagram FROM users WHERE id = ?');
$userStmt->execute([(int)$_SESSION['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    unset($_SESSION['user_id'], $_SESSION['order_draft'], $_SESSION['order_id']);
    redirect('/register?from=packages');
}

$items = [];
$total = 0;
$totalTickets = 0;
foreach ((array)$draft['items'] as $it) {
    $packageId = (int)($it['package_id'] ?? 0);
    $qty = max(0, (int)($it['qty'] ?? 0));
    $price = max(0, (int)($it['price'] ?? 0));
    $name = trim((string)($it['name'] ?? ''));
    if ($packageId <= 0 || $qty <= 0 || $price <= 0 || $name === '') {
        continue;
    }
    $items[] = [
        'package_id' => $packageId,
        'qty' => $qty,
        'price' => $price,
        'name' => $name,
    ];
    $total += $qty * $price;
    $totalTickets += $qty;
}

if (!$items || $total <= 0 || $totalTickets <= 0) {
    unset($_SESSION['order_draft']);
    redirect('/packages');
}

$instagramLabel = '';
if (!empty($user['instagram'])) {
    $instagramLabel = '@' . ltrim((string)$user['instagram'], '@');
}

$errors = [];
$additionalAttendeeCount = max(0, $totalTickets - 1);
$attendeeNames = array_fill(0, $additionalAttendeeCount, '');

if ($additionalAttendeeCount > 0 && isset($_SESSION['order_draft']['attendee_names']) && is_array($_SESSION['order_draft']['attendee_names'])) {
    for ($i = 0; $i < $additionalAttendeeCount; $i++) {
        $attendeeNames[$i] = trim((string)($_SESSION['order_draft']['attendee_names'][$i] ?? ''));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($additionalAttendeeCount > 0) {
        $rawNames = $_POST['attendee_names'] ?? [];
        if (!is_array($rawNames)) {
            $rawNames = [];
        }
        for ($i = 0; $i < $additionalAttendeeCount; $i++) {
            $nameInput = trim((string)($rawNames[$i] ?? ''));
            $attendeeNames[$i] = $nameInput;
            if ($nameInput === '') {
                $errors[] = 'Please fill in attendee name #' . ($i + 2) . '.';
            } elseif (strlen($nameInput) > 120) {
                $errors[] = 'Attendee name #' . ($i + 2) . ' is too long (max 120 characters).';
            }
        }
    }

    $_SESSION['order_draft']['attendee_names'] = $attendeeNames;

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
                $name = 'proof_u' . (int)$_SESSION['user_id'] . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
                $uploadDir = $CONFIG['upload_dir'];
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }
                $target = $uploadDir . '/' . $name;
                if (!move_uploaded_file($file['tmp_name'], $target)) {
                    $errors[] = 'Failed to upload file.';
                } else {
                    $orderId = 0;
                    try {
                        $db->beginTransaction();
                        $stmt = $db->prepare('INSERT INTO orders (user_id, total, status, payment_proof, created_at) VALUES (?, ?, ?, ?, ?)');
                        $stmt->execute([(int)$_SESSION['user_id'], (int)$total, 'paid', $name, date('c')]);
                        $orderId = (int)$db->lastInsertId();

                        $itemStmt = $db->prepare('INSERT INTO order_items (order_id, package_id, qty, price) VALUES (?, ?, ?, ?)');
                        foreach ($items as $it) {
                            $itemStmt->execute([$orderId, $it['package_id'], $it['qty'], $it['price']]);
                        }

                        $db->commit();
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        if (is_file($target)) {
                            @unlink($target);
                        }
                        $errors[] = 'Failed to save your order. Please try again.';
                    }

                    if ($orderId > 0) {
                        try {
                            $attendeeStmt = $db->prepare('INSERT INTO order_attendees (order_id, attendee_name, position_no, created_at) VALUES (?, ?, ?, ?)');
                            $attendeeStmt->execute([$orderId, (string)$user['full_name'], 1, date('Y-m-d H:i:s')]);
                            foreach ($attendeeNames as $idx => $attendeeName) {
                                $attendeeStmt->execute([$orderId, $attendeeName, $idx + 2, date('Y-m-d H:i:s')]);
                            }
                        } catch (Throwable $e) {
                            // Keep order success even when attendee table does not exist.
                        }

                        unset($_SESSION['order_draft']);
                        send_invoice_email([
                            'id' => $orderId,
                            'full_name' => $user['full_name'],
                            'status' => 'paid',
                            'total' => $total,
                        ], $items, (string)$user['email']);
                        redirect('/thankyou?order=' . $orderId);
                    }
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
      background: url('/assets/img/wallpapeh.jpg') center/cover no-repeat fixed;
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

    .attendee-grid {
      display: grid;
      gap: 10px;
      margin: 14px 0 18px;
    }

    .attendee-grid label {
      display: grid;
      gap: 6px;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.3px;
      color: #e9f1ff;
    }

    .attendee-grid input[type="text"] {
      width: 100%;
      border: 1px solid rgba(255, 255, 255, 0.4);
      border-radius: 10px;
      padding: 10px 12px;
      background: rgba(255, 255, 255, 0.95);
      color: #1f2d40;
      font: inherit;
      font-size: 14px;
    }

    .attendee-hint {
      margin-top: 8px;
      font-size: 13px;
      color: #dbe7ff;
      opacity: 0.95;
    }

    .proof-preview {
      margin-top: 12px;
      display: none;
      gap: 10px;
    }

    .proof-preview.is-visible {
      display: grid;
    }

    .proof-preview-label {
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.4px;
      color: #dbe7ff;
    }

    .proof-preview img {
      width: min(100%, 360px);
      max-height: 280px;
      object-fit: contain;
      border-radius: 10px;
      border: 1px solid rgba(255, 255, 255, 0.4);
      background: rgba(255, 255, 255, 0.08);
      box-shadow: 0 8px 22px rgba(0, 0, 0, 0.24);
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
          <div><i class="bi bi-person-badge"></i> <strong>Full Name</strong> : <?= h($user['full_name']) ?></div>
          <div><i class="bi bi-telephone"></i> <strong>Phone Number</strong> : <?= h($user['phone']) ?></div>
          <div><i class="bi bi-envelope"></i> <strong>E-mail</strong> : <?= h($user['email']) ?></div>
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
          <div><?= h(rupiah((int)$total)) ?>,-</div>
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
          <?php if ($additionalAttendeeCount > 0): ?>
            <div class="payment-card">
              <div><i class="bi bi-people"></i> <strong>Additional Attendees</strong></div>
              <div class="attendee-hint">Total tickets: <?= (int)$totalTickets ?>. Please fill names for attendee #2 until #<?= (int)$totalTickets ?>.</div>
              <div class="attendee-grid">
                <?php for ($i = 0; $i < $additionalAttendeeCount; $i++): ?>
                  <label for="attendee_name_<?= (int)$i ?>">
                    Attendee #<?= (int)($i + 2) ?>
                    <input
                      type="text"
                      id="attendee_name_<?= (int)$i ?>"
                      name="attendee_names[]"
                      maxlength="120"
                      value="<?= h($attendeeNames[$i] ?? '') ?>"
                      required
                    >
                  </label>
                <?php endfor; ?>
              </div>
            </div>
          <?php endif; ?>
          <div class="upload-box">
            <input type="file" name="payment_proof" id="paymentProofInput" accept="image/*" required>
            <div class="proof-preview" id="proofPreviewWrap" aria-live="polite">
              <div class="proof-preview-label"><i class="bi bi-image"></i> Live Preview</div>
              <img id="proofPreviewImage" src="" alt="Payment proof preview">
            </div>
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
      window.addEventListener('pageshow', function () {
        if (!body) return;
        body.classList.remove('page-leaving');
        body.classList.add('page-ready');
      });
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

      var proofInput = document.getElementById('paymentProofInput');
      var proofPreviewWrap = document.getElementById('proofPreviewWrap');
      var proofPreviewImage = document.getElementById('proofPreviewImage');

      if (proofInput && proofPreviewWrap && proofPreviewImage) {
        proofInput.addEventListener('change', function () {
          var file = proofInput.files && proofInput.files[0] ? proofInput.files[0] : null;

          if (!file || !file.type || file.type.indexOf('image/') !== 0) {
            proofPreviewImage.removeAttribute('src');
            proofPreviewWrap.classList.remove('is-visible');
            return;
          }

          var reader = new FileReader();
          reader.onload = function (event) {
            proofPreviewImage.src = event.target && event.target.result ? event.target.result : '';
            proofPreviewWrap.classList.toggle('is-visible', !!proofPreviewImage.src);
          };
          reader.onerror = function () {
            proofPreviewImage.removeAttribute('src');
            proofPreviewWrap.classList.remove('is-visible');
          };
          reader.readAsDataURL(file);
        });
      }
    })();
  </script>
</body>
</html>
