<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/auth.php';
ensure_session();

if (!empty($_GET['cancel_otp']) && $_GET['cancel_otp'] === '1') {
    unset($_SESSION['reg_pending']);
    redirect('/register');
}

$errors = [];
$otp_errors = [];
$step = $_POST['step'] ?? '';
$pending = $_SESSION['reg_pending'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 'send_otp') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $instagram = trim($_POST['instagram'] ?? '');
        $instagram = ltrim($instagram, '@');

        if ($full_name === '') $errors[] = 'Full name is required.';
        if ($phone === '') $errors[] = 'Phone number is required.';
        if ($email === '') $errors[] = 'Email is required.';
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email format is invalid.';

        if (!$errors) {
            $db = get_db();
            $check = $db->prepare(
                'SELECT u.id, COUNT(o.id) AS total_orders, SUM(CASE WHEN o.status = ? THEN 1 ELSE 0 END) AS rejected_orders
                 FROM users u
                 LEFT JOIN orders o ON o.user_id = u.id
                 WHERE u.email = ?
                 GROUP BY u.id
                 ORDER BY u.id DESC'
            );
            $check->execute(['rejected', $email]);
            $existingUsers = $check->fetchAll(PDO::FETCH_ASSOC);

            $reuseUserId = 0;
            $emailCanReuse = true;
            if ($existingUsers) {
                $reuseUserId = (int)$existingUsers[0]['id'];
                foreach ($existingUsers as $row) {
                    $totalOrders = (int)$row['total_orders'];
                    $rejectedOrders = (int)($row['rejected_orders'] ?? 0);
                    if ($totalOrders === 0 || $rejectedOrders !== $totalOrders) {
                        $emailCanReuse = false;
                        break;
                    }
                }
            }

            if ($existingUsers && !$emailCanReuse) {
                $errors[] = 'Email already registered. Please use another email.';
            } else {
                $otp = (string)random_int(100000, 999999);
                $_SESSION['reg_pending'] = [
                    'full_name' => $full_name,
                    'phone' => $phone,
                    'email' => $email,
                    'instagram' => $instagram,
                    'reuse_user_id' => $reuseUserId,
                    'otp' => $otp,
                    'otp_expires' => time() + 600,
                ];
                $pending = $_SESSION['reg_pending'];
                if (!send_otp_email($email, $otp)) {
                    $errors[] = 'Failed to send OTP email. Please check SMTP config.';
                    unset($_SESSION['reg_pending']);
                    $pending = null;
                }
            }
        }
    }

    if ($step === 'verify_otp') {
        $otp = trim($_POST['otp'] ?? '');
        $pending = $_SESSION['reg_pending'] ?? null;

        if (!$pending) {
            $otp_errors[] = 'OTP session expired. Please resend OTP.';
        } elseif (($pending['otp_expires'] ?? 0) < time()) {
            $otp_errors[] = 'OTP expired. Please resend OTP.';
            unset($_SESSION['reg_pending']);
            $pending = null;
        } elseif ($otp !== ($pending['otp'] ?? '')) {
            $otp_errors[] = 'OTP is incorrect.';
        } else {
            $db = get_db();
            $reuseUserId = (int)($pending['reuse_user_id'] ?? 0);

            if ($reuseUserId > 0) {
                $stmt = $db->prepare('UPDATE users SET full_name = ?, phone = ?, instagram = ? WHERE id = ? AND email = ?');
                $stmt->execute([
                    $pending['full_name'],
                    $pending['phone'],
                    $pending['instagram'],
                    $reuseUserId,
                    $pending['email'],
                ]);
                $_SESSION['user_id'] = $reuseUserId;
            } else {
                $stmt = $db->prepare('INSERT INTO users (full_name, phone, email, instagram, created_at) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([
                    $pending['full_name'],
                    $pending['phone'],
                    $pending['email'],
                    $pending['instagram'],
                    date('c')
                ]);
                $_SESSION['user_id'] = (int)$db->lastInsertId();
            }

            unset($_SESSION['reg_pending']);
            redirect('/packages');
        }
    }

    if ($step === 'resend_otp') {
        $pending = $_SESSION['reg_pending'] ?? null;
        if ($pending && !empty($pending['email'])) {
            $otp = (string)random_int(100000, 999999);
            $_SESSION['reg_pending']['otp'] = $otp;
            $_SESSION['reg_pending']['otp_expires'] = time() + 600;
            $pending = $_SESSION['reg_pending'];
            if (!send_otp_email($pending['email'], $otp)) {
                $otp_errors[] = 'Failed to resend OTP. Please check SMTP config.';
            }
        } else {
            $otp_errors[] = 'OTP session expired. Please register again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register - Temu Padel 2026</title>
  <style>
    :root {
      --blue: #1658ad;
      --white: #f6f7fb;
      --soft: rgba(10, 30, 66, 0.56);
      --line: rgba(255, 255, 255, 0.34);
    }

    * { box-sizing: border-box; }

    html, body {
      margin: 0;
      min-height: 100%;
      scroll-behavior: smooth;
    }

    body {
      color: var(--white);
      font-family: "Segoe UI", Tahoma, sans-serif;
      background: url('/assets/img/wallpaper1.jpg') center/cover no-repeat fixed;
      overflow-x: hidden;
    }

    .landing {
      min-height: 100vh;
      width: min(980px, 92vw);
      margin: 0 auto;
      padding: 42px 0 56px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    #registerPanel {
      width: min(640px, 100%);
      background: rgba(255, 255, 255, 0.16);
      border: 1px solid rgba(255, 255, 255, 0.42);
      border-radius: 20px;
      backdrop-filter: blur(5px);
      padding: clamp(20px, 2.6vw, 34px);
      box-shadow: 0 14px 34px rgba(0, 0, 0, 0.32);
    }

    h1 {
      margin: 0 0 16px;
      font-size: clamp(26px, 3vw, 38px);
    }

    .alert {
      margin: 0 0 14px;
      background: rgba(255, 120, 120, 0.18);
      border: 1px solid rgba(255, 180, 180, 0.55);
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 14px;
    }

    .form {
      display: grid;
      gap: 12px;
    }

    label {
      display: grid;
      gap: 6px;
      font-size: 14px;
      font-weight: 600;
    }

    input {
      width: 100%;
      height: 44px;
      border-radius: 10px;
      border: 1px solid rgba(255, 255, 255, 0.32);
      padding: 0 12px;
      font-size: 15px;
      background: rgba(255, 255, 255, 0.92);
      color: #1f2d40;
    }

    .input-prefix {
      display: grid;
      grid-template-columns: 44px 1fr;
      gap: 8px;
      align-items: center;
    }

    .input-prefix span {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      height: 44px;
      border-radius: 10px;
      background: rgba(255, 255, 255, 0.2);
      border: 1px solid rgba(255, 255, 255, 0.4);
      font-weight: 700;
    }

    .actions {
      margin-top: 6px;
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .btn {
      text-decoration: none;
      border: 0;
      border-radius: 999px;
      padding: 11px 18px;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .btn.primary {
      background: #fff;
      color: var(--blue);
    }

    .btn.ghost {
      background: rgba(255, 255, 255, 0.16);
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.45);
    }

    .modal {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.62);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 16px;
      z-index: 30;
    }

    .modal.show {
      display: flex;
    }

    .modal-card {
      width: min(460px, 100%);
      background: rgba(7, 22, 45, 0.94);
      border: 1px solid rgba(255, 255, 255, 0.22);
      border-radius: 14px;
      padding: 18px;
      display: grid;
      gap: 12px;
    }

    .modal-title {
      font-size: 22px;
      font-weight: 800;
    }

    .help-text {
      font-size: 13px;
      opacity: 0.9;
    }

    .icon-btn {
      text-decoration: none;
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.42);
      border-radius: 999px;
      padding: 6px 11px;
      font-weight: 700;
    }

    @media (max-width: 640px) {
      .landing { width: 94vw; }
      #registerPanel { padding: 18px; }
      .actions .btn,
      .modal-card .btn { width: 100%; }
    }
  </style>
</head>
<body>
  <main class="landing">
    <section id="registerPanel">
      <h1>Register Yourself</h1>

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

      <form class="form" method="post" action="" id="registerForm">
        <input type="hidden" name="step" value="send_otp">

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
          <div class="input-prefix">
            <span>@</span>
            <input type="text" name="instagram" id="instagramInput" placeholder="username" autocomplete="off">
          </div>
        </label>

        <div class="actions">
          <button class="btn primary" type="submit">Continue</button>
          <a class="btn ghost" href="/">Back to Home</a>
        </div>
      </form>
    </section>
  </main>

  <div class="modal <?= $pending ? 'show' : '' ?>" id="otpModal">
    <div class="modal-card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <div class="modal-title">OTP Verification</div>
        <a class="icon-btn" href="/register?cancel_otp=1" aria-label="Close">Close</a>
      </div>
      <div class="help-text">Enter OTP sent to email: <?= $pending ? h($pending['email']) : '-' ?></div>

      <?php if ($otp_errors): ?>
        <div class="alert">
          <?php foreach ($otp_errors as $e): ?>
            <div><?= h($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form class="form" method="post" action="" id="otpVerifyForm">
        <input type="hidden" name="step" value="verify_otp">
        <label>
          OTP Code*
          <input type="text" name="otp" inputmode="numeric" required>
        </label>
        <div class="help-text" id="otpTimer" data-exp="<?= $pending ? (int)$pending['otp_expires'] : 0 ?>">Valid for 10 minutes.</div>
        <button class="btn primary" type="submit">Verify</button>
      </form>

      <form method="post" action="">
        <input type="hidden" name="step" value="resend_otp">
        <button class="btn ghost" type="submit">Resend OTP</button>
      </form>
    </div>
  </div>

  <script>
    (function () {
      var timer = document.getElementById('otpTimer');
      if (timer) {
        var exp = parseInt(timer.dataset.exp || '0', 10) * 1000;
        if (exp) {
          var tick = function () {
            var now = Date.now();
            var diff = Math.max(0, exp - now);
            var mins = Math.floor(diff / 60000);
            var secs = Math.floor((diff % 60000) / 1000);
            timer.textContent = diff > 0
              ? ('Time left: ' + mins + 'm ' + (secs < 10 ? '0' : '') + secs + 's')
              : 'OTP expired. Please resend OTP.';
          };
          tick();
          setInterval(tick, 1000);
        }
      }

      var form = document.getElementById('registerForm');
      var input = document.getElementById('instagramInput');
      if (form && input) {
        var normalize = function () {
          var val = (input.value || '').trim();
          input.value = val.replace(/^@+/, '');
        };

        input.addEventListener('blur', normalize);
        form.addEventListener('submit', normalize);
      }
    })();
  </script>
</body>
</html>
