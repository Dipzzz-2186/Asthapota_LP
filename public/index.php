<?php
require_once __DIR__ . '/../app/helpers.php';
ensure_session();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Temu Padel</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
  <section class="hero">
    <div class="hero-content container">
      <div class="hero-logo">TP</div>
      <h2>Welcome</h2>
      <h1>TEMU PADEL</h1>
      <div class="sub">A Monkeybar x BAPORA Event</div>
      <div class="date-chip">FEBRUARY 28TH, 2026 | 4 PM - 6 PM</div>
      <div class="supported" style="margin-top:28px;">
        <div class="center">Supported By</div>
        <div class="logos" style="margin-top:12px;">
          <div class="logo-box">HIPPI</div>
          <div class="logo-box">FCOM</div>
          <div class="logo-box">MY PADEL</div>
          <div class="logo-box">BAPORA</div>
        </div>
        <div class="center" style="margin-top:18px;">
          <a class="btn" href="/register.php">Click Here To Register</a>
        </div>
      </div>
    </div>
  </section>
</body>
</html>
