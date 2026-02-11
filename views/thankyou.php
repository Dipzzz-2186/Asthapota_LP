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
  <style>
    body.page.thankyou-page {
      background: linear-gradient(rgba(7, 15, 34, 0.74), rgba(7, 15, 34, 0.74)), url('/assets/img/wallpaper.avif') center/cover no-repeat fixed;
      color: #eef4ff;
    }

    .thankyou-page::before,
    .thankyou-page::after {
      display: none;
    }

    .thankyou-page .page-header {
      background: rgba(7, 15, 34, 0.82);
      border-bottom: 1px solid rgba(255, 255, 255, 0.2);
    }

    .thankyou-page .brand,
    .thankyou-page .nav a,
    .thankyou-page .topbar-actions a {
      color: #eef4ff;
    }

    .thankyou-page .nav a:hover,
    .thankyou-page .nav a.active {
      background: rgba(255, 255, 255, 0.14);
      color: #fff;
    }

    .thankyou-full {
      min-height: calc(100vh - 92px);
      display: grid;
      place-items: center;
      padding: clamp(20px, 4vw, 48px);
    }

    .thankyou-card {
      width: min(760px, 100%);
      background: rgba(8, 16, 36, 0.8);
      border: 1px solid rgba(255, 255, 255, 0.22);
      border-radius: 24px;
      padding: clamp(24px, 3.2vw, 40px);
      backdrop-filter: blur(8px);
      display: grid;
      gap: 16px;
      text-align: center;
    }

    .thankyou-title {
      font-family: 'Poppins', sans-serif;
      font-weight: 800;
      font-size: clamp(30px, 4.8vw, 46px);
      line-height: 1.15;
      color: #fff;
    }

    .thankyou-copy {
      color: #d8e6ff;
      font-size: 17px;
    }

    .thankyou-page .pill {
      margin: 0 auto;
      background: rgba(255, 255, 255, 0.14);
      color: #fff;
      border-color: rgba(255, 255, 255, 0.24);
      animation: none;
    }

    .thankyou-page .pill i {
      animation: none;
    }

    .venue-card {
      background: rgba(255, 255, 255, 0.12);
      border: 1px solid rgba(255, 255, 255, 0.24);
      border-radius: 16px;
      padding: 18px;
      color: #eef4ff;
      line-height: 1.6;
    }

    .thankyou-note {
      font-weight: 700;
      color: #fff;
      letter-spacing: 0.2px;
    }
  </style>
</head>
<body class="page thankyou-page">

  <main class="thankyou-full">
    <section class="thankyou-card fade-up">
      <div class="pill"><i class="bi bi-check-circle"></i> Registration Complete</div>
      <div class="thankyou-title">Thank You for Registering</div>
      <div class="thankyou-copy">We will contact you via WhatsApp to confirm your spot.</div>
      <div class="venue-card">
        <div><strong>Venue</strong></div>
        <div>MY PADEL</div>
        <div>Jl. Jelupang Utama, Kec. Serpong Utara</div>
        <div>Kota Tangerang Selatan</div>
      </div>
      <div class="thankyou-note">See you on the court!</div>
    </section>
  </main>
</body>
</html>

