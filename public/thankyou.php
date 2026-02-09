<?php
require_once __DIR__ . '/../app/helpers.php';
ensure_session();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Thank You - Temu Padel</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="page">
  <header class="page-header">
    <div class="container">
      <div class="topbar">
        <div class="brand">
          <div class="brand-badge">TP</div>
          <div>
            <div>Temu Padel</div>
            <small style="color:var(--muted);">Confirmation</small>
          </div>
        </div>
        <div class="topbar-actions">
          <a class="btn ghost" href="/"><i class="bi bi-house"></i> Home</a>
          <a class="btn primary" href="/packages.php">Packages <i class="bi bi-bag"></i></a>
        </div>
      </div>
    </div>
  </header>

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
