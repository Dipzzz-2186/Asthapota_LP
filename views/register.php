<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/auth.php';
ensure_session();

function register_source(?string $source): string {
    if ($source === 'home' || $source === 'events') {
        return 'events';
    }
    return 'packages';
}

$from = register_source($_GET['from'] ?? $_POST['from'] ?? ($_SESSION['register_from'] ?? 'packages'));
$_SESSION['register_from'] = $from;
$backHref = $from === 'events' ? '/events' : '/packages';
$backIcon = $from === 'events' ? 'bi-calendar-event' : 'bi-box-seam';
$backLabel = $from === 'events' ? 'Back to Events' : 'Back to Packages';

if (!empty($_GET['cancel_otp']) && $_GET['cancel_otp'] === '1') {
    unset($_SESSION['reg_pending']);
    redirect('/register?from=' . $from);
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
        $gender = trim($_POST['gender'] ?? '');
        $instagram = trim($_POST['instagram'] ?? '');
        $instagram = ltrim($instagram, '@');

        if ($full_name === '') $errors[] = 'Full name is required.';
        if ($phone === '') $errors[] = 'Phone number is required.';
        if ($email === '') $errors[] = 'Email is required.';
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email format is invalid.';
        if (!in_array($gender, ['Laki-laki', 'Perempuan'], true)) $errors[] = 'Gender is required.';

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
                    'gender' => $gender,
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
                $stmt = $db->prepare('UPDATE users SET full_name = ?, phone = ?, gender = ?, instagram = ? WHERE id = ? AND email = ?');
                $stmt->execute([
                    $pending['full_name'],
                    $pending['phone'],
                    $pending['gender'],
                    $pending['instagram'],
                    $reuseUserId,
                    $pending['email'],
                ]);
                $_SESSION['user_id'] = $reuseUserId;
            } else {
                $stmt = $db->prepare('INSERT INTO users (full_name, phone, email, gender, instagram, created_at) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $pending['full_name'],
                    $pending['phone'],
                    $pending['email'],
                    $pending['gender'],
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
    @import url('https://fonts.googleapis.com/css2?family=Anton&family=Manrope:wght@400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,600;0,700;1,500&display=swap');
    @import url('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css');

    :root {
      --blue: #1658ad;
      --white: #f6f7fb;
      --soft: rgba(10, 30, 66, 0.56);
      --line: rgba(255, 255, 255, 0.34);
      --font-body: "Manrope", "Segoe UI", Tahoma, sans-serif;
      --font-display: "Anton", "Arial Narrow", Impact, sans-serif;
      --font-accent: "Playfair Display", Georgia, serif;
    }

    * {
      box-sizing: border-box;
      -ms-overflow-style: none;
      scrollbar-width: none;
    }

    *::-webkit-scrollbar {
      width: 0;
      height: 0;
      display: none;
    }

    html, body {
      margin: 0;
      min-height: 100%;
      scroll-behavior: smooth;
    }

    body {
      color: var(--white);
      font-family: var(--font-body);
      font-weight: 500;
      letter-spacing: 0.2px;
      background: url('/assets/img/wallpapeh4.jpg') center/cover no-repeat fixed;
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

    .landing {
      min-height: 100vh;
      width: min(1180px, 94vw);
      margin: 0 auto;
      padding: 42px 0 56px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    #registerPanel {
      width: min(860px, 100%);
      background: rgba(23, 45, 79, 0.58);
      border: 1px solid rgba(255, 255, 255, 0.40);
      border-radius: 20px;
      backdrop-filter: blur(7px);
      padding: clamp(24px, 3.1vw, 42px);
      box-shadow: 0 14px 34px rgba(0, 0, 0, 0.32);
      transition: transform 0.18s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    }

    #registerPanel:hover {
      transform: translateY(-3px);
      box-shadow: 0 18px 38px rgba(0, 0, 0, 0.36);
      border-color: rgba(255, 255, 255, 0.58);
    }

    h1 {
      margin: 0 0 16px;
      font-family: var(--font-display);
      font-size: clamp(34px, 4vw, 54px);
      line-height: 0.95;
      font-weight: 400;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      text-shadow: 0 10px 20px rgba(0, 0, 0, 0.22);
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
      letter-spacing: 0.2px;
    }

    .label-text {
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    input,
    select {
      width: 100%;
      height: 44px;
      border-radius: 10px;
      border: 1px solid rgba(255, 255, 255, 0.32);
      padding: 0 12px;
      font-size: 15px;
      background: rgba(255, 255, 255, 0.92);
      color: #1f2d40;
      transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }

    input:focus,
    select:focus {
      outline: none;
      border-color: rgba(22, 88, 173, 0.75);
      box-shadow: 0 0 0 3px rgba(22, 88, 173, 0.22);
      background: #fff;
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
      gap: 8px;
      transition: transform 0.15s ease, filter 0.2s ease, background 0.2s ease, border-color 0.2s ease;
    }

    .btn:hover {
      transform: translateY(-2px);
      filter: brightness(1.04);
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

    .btn.ghost:hover {
      background: rgba(255, 255, 255, 0.24);
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
      font-family: var(--font-accent);
      font-size: 26px;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      gap: 8px;
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
      transition: transform 0.15s ease, background 0.18s ease, border-color 0.18s ease;
    }

    .icon-btn:hover {
      transform: translateY(-1px);
      background: rgba(255, 255, 255, 0.18);
      border-color: rgba(255, 255, 255, 0.6);
    }

    @media (max-width: 640px) {
      .landing { width: 94vw; }
      #registerPanel { padding: 18px; }
      .actions .btn,
      .modal-card .btn { width: 100%; }
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
  <main class="landing">
    <section id="registerPanel">
      <h1><i class="bi bi-person-vcard"></i> Register Yourself</h1>

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
        <input type="hidden" name="from" value="<?= h($from) ?>">

        <label>
          <span class="label-text"><i class="bi bi-person-badge"></i> Full Name*</span>
          <input type="text" name="full_name" required>
        </label>

        <label>
          <span class="label-text"><i class="bi bi-telephone"></i> Phone Number*</span>
          <input type="text" name="phone" required>
        </label>

        <label>
          <span class="label-text"><i class="bi bi-envelope"></i> E-mail*</span>
          <input type="email" name="email" required>
        </label>

        <label>
          <span class="label-text"><i class="bi bi-gender-ambiguous"></i> Gender*</span>
          <select name="gender" required>
            <option value="" selected disabled>Pilih gender</option>
            <option value="Laki-laki">Laki-laki</option>
            <option value="Perempuan">Perempuan</option>
          </select>
        </label>

        <label>
          <span class="label-text"><i class="bi bi-instagram"></i> Instagram</span>
          <div class="input-prefix">
            <span><i class="bi bi-at"></i></span>
            <input type="text" name="instagram" id="instagramInput" placeholder="username" autocomplete="off">
          </div>
        </label>

        <div class="actions">
          <button class="btn primary" type="submit"><i class="bi bi-send-check"></i> Continue</button>
          <a class="btn ghost" href="<?= h($backHref) ?>"><i class="bi <?= h($backIcon) ?>"></i> <?= h($backLabel) ?></a>
        </div>
      </form>
    </section>
  </main>

  <div class="modal <?= $pending ? 'show' : '' ?>" id="otpModal">
      <div class="modal-card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
          <div class="modal-title"><i class="bi bi-shield-lock"></i> OTP Verification</div>
          <a class="icon-btn" href="/register?from=<?= h($from) ?>&cancel_otp=1" aria-label="Close"><i class="bi bi-x-lg"></i> Close</a>
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
          <input type="hidden" name="from" value="<?= h($from) ?>">
          <label>
          <span class="label-text"><i class="bi bi-key"></i> OTP Code*</span>
          <input type="text" name="otp" inputmode="numeric" required>
          </label>
        <div class="help-text" id="otpTimer" data-exp="<?= $pending ? (int)$pending['otp_expires'] : 0 ?>">Valid for 10 minutes.</div>
        <button class="btn primary" type="submit"><i class="bi bi-check2-circle"></i> Verify</button>
      </form>

      <form method="post" action="">
        <input type="hidden" name="step" value="resend_otp">
        <input type="hidden" name="from" value="<?= h($from) ?>">
        <button class="btn ghost" type="submit"><i class="bi bi-arrow-repeat"></i> Resend OTP</button>
      </form>
    </div>
  </div>

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
