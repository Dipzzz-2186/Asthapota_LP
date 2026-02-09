<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/layout/app.php';
ensure_session();

$isAdmin = is_admin_logged_in();
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

        if ($full_name === '') $errors[] = 'Full name is required.';
        if ($phone === '') $errors[] = 'Phone number is required.';
        if ($email === '') $errors[] = 'Email is required.';
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email format is invalid.';

        if (!$errors) {
            $db = get_db();
            $check = $db->prepare('SELECT id FROM users WHERE email = ?');
            $check->execute([$email]);
            if ($check->fetch()) {
                $errors[] = 'Email already registered. Please use another email.';
            } else {
                $otp = (string)random_int(100000, 999999);
                $_SESSION['reg_pending'] = [
                    'full_name' => $full_name,
                    'phone' => $phone,
                    'email' => $email,
                    'instagram' => $instagram,
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
            $stmt = $db->prepare('INSERT INTO users (full_name, phone, email, instagram, created_at) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([
                $pending['full_name'],
                $pending['phone'],
                $pending['email'],
                $pending['instagram'],
                date('c')
            ]);
            $_SESSION['user_id'] = (int)$db->lastInsertId();
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
  <title>Register - Temu Padel</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="page">
<?php render_navbar(['isAdmin' => $isAdmin]); ?>

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

  <div class="modal <?= $pending ? 'show' : '' ?>" id="otpModal">
    <div class="modal-card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <div class="modal-title">Verifikasi OTP</div>
        <a class="icon-btn" href="/register?cancel_otp=1" aria-label="Close"><i class="bi bi-x"></i></a>
      </div>
      <div class="help-text">Masukkan kode OTP yang dikirim ke email: <?= $pending ? h($pending['email']) : '-' ?></div>

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
          Kode OTP*
          <input type="text" name="otp" inputmode="numeric" required>
        </label>
        <div class="help-text" id="otpTimer" data-exp="<?= $pending ? (int)$pending['otp_expires'] : 0 ?>">Berlaku 10 menit.</div>
        <div class="modal-actions">
          <button class="btn primary" type="submit">Verifikasi <i class="bi bi-check2-circle"></i></button>
        </div>
      </form>
      <form method="post" action="">
        <input type="hidden" name="step" value="resend_otp">
        <button class="btn ghost" type="submit">Kirim Ulang</button>
      </form>
    </div>
  </div>

  <script>
    (function(){
      var modal = document.getElementById('otpModal');
      if (!modal) return;
      var timer = document.getElementById('otpTimer');
      if (!timer) return;
      var exp = parseInt(timer.dataset.exp || '0', 10) * 1000;
      if (!exp) return;
      var tick = function(){
        var now = Date.now();
        var diff = Math.max(0, exp - now);
        var mins = Math.floor(diff / 60000);
        var secs = Math.floor((diff % 60000) / 1000);
        timer.textContent = diff > 0 ? ('Sisa waktu: ' + mins + 'm ' + (secs < 10 ? '0' : '') + secs + 's') : 'OTP expired. Silakan kirim ulang.';
      };
      tick();
      setInterval(tick, 1000);
    })();
  </script>
</body>
</html>

