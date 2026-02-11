<?php
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/auth.php';
ensure_session();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Thank You - Asthapora</title>
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

    .thankyou-shell {
      min-height: 100vh;
      width: min(1260px, 95vw);
      margin: 0 auto;
      padding: 42px 0 56px;
      display: grid;
      place-items: center;
    }

    .thankyou-card {
      width: min(760px, 100%);
      background: rgba(23, 45, 79, 0.58);
      border: 1px solid rgba(255, 255, 255, 0.4);
      border-radius: 24px;
      padding: clamp(24px, 3.2vw, 40px);
      backdrop-filter: blur(7px);
      display: grid;
      gap: 16px;
      text-align: center;
      box-shadow: 0 14px 34px rgba(0, 0, 0, 0.32);
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

    .pill {
      margin: 0 auto;
      background: rgba(255, 255, 255, 0.16);
      color: #fff;
      border-color: rgba(255, 255, 255, 0.34);
      animation: none;
    }

    .pill i {
      animation: none;
    }

    .venue-card {
      background: rgba(255, 255, 255, 0.14);
      border: 1px solid rgba(255, 255, 255, 0.3);
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
<body>
  <main class="thankyou-shell">
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
