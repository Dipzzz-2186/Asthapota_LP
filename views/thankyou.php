<?php
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/layout/app.php';
ensure_session();

$isAdmin = is_admin_logged_in();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Thank You - Asthapora</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="page">
<?php render_navbar(['isAdmin' => $isAdmin]); ?>

  <section class="section thankyou-wrap">
    <div class="thankyou-card fade-up">
      <div class="pill"><i class="bi bi-check-circle"></i> Registration Complete</div>
      <div class="thankyou-title">Thank You for Registering</div>
      <div style="color:var(--muted);">We will contact you via WhatsApp to confirm your spot.</div>
      <div class="card soft">
        <div><strong>Venue</strong></div>
        <div>MY PADEL</div>
        <div>Jl. Jelupang Utama, Kec. Serpong Utara</div>
        <div>Kota Tangerang Selatan</div>
      </div>
      <div style="font-weight:700;">See you on the court!</div>
    </div>
  </section>
</body>
</html>

