<?php
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/auth.php';
ensure_session();

if (empty($_SESSION['user_id'])) {
    redirect('/register?notice=register_required');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Thank You - Temu Padel 2026</title>
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
      background: url('/assets/img/wallpaper.avif') center/cover no-repeat fixed;
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
      transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    }

    .thankyou-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 18px 42px rgba(0, 0, 0, 0.36);
      border-color: rgba(255, 255, 255, 0.58);
    }

    .thankyou-title {
      font-family: var(--font-display);
      font-weight: 400;
      font-size: clamp(34px, 5vw, 56px);
      line-height: 0.95;
      letter-spacing: 1.2px;
      text-transform: uppercase;
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
      transition: border-color 0.2s ease, background 0.2s ease;
    }

    .venue-card:hover {
      border-color: rgba(255, 255, 255, 0.55);
      background: rgba(255, 255, 255, 0.2);
    }

    .thankyou-note {
      font-weight: 700;
      color: #fff;
      letter-spacing: 0.2px;
      font-family: var(--font-accent);
      font-size: 24px;
    }

    .thankyou-actions {
      margin-top: 2px;
      display: flex;
      gap: 10px;
      justify-content: center;
      flex-wrap: wrap;
    }

    .thankyou-actions .btn {
      text-decoration: none;
      border-radius: 999px;
      padding: 11px 18px;
      font-size: 14px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: transform 0.15s ease, filter 0.2s ease, background 0.2s ease;
    }

    .thankyou-actions .btn:hover {
      transform: translateY(-2px);
      filter: brightness(1.04);
    }

    .thankyou-actions .btn.primary {
      background: #fff;
      color: #0b2d61;
      border: 1px solid #fff;
    }

    .thankyou-actions .btn.ghost {
      background: rgba(255, 255, 255, 0.16);
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.45);
    }

    .thankyou-actions .btn.ghost:hover {
      background: rgba(255, 255, 255, 0.24);
    }

    @media (max-width: 640px) {
      .thankyou-actions .btn {
        width: 100%;
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
  <main class="thankyou-shell">
    <section class="thankyou-card fade-up">
      <div class="pill"><i class="bi bi-check-circle"></i> Registration Complete</div>
      <div class="thankyou-title">Thank You for Registering</div>
      <div class="thankyou-copy">We will contact you via WhatsApp to confirm your spot.</div>
      <div class="venue-card">
        <div><i class="bi bi-geo-alt-fill"></i> <strong>Venue</strong></div>
        <div><i class="bi bi-building"></i> MY PADEL</div>
        <div><i class="bi bi-signpost"></i> Jl. Jelupang Utama, Kec. Serpong Utara</div>
        <div><i class="bi bi-pin-map"></i> Kota Tangerang Selatan</div>
      </div>
      <div class="thankyou-note"><i class="bi bi-trophy"></i> See you on the court!</div>
    </section>
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
