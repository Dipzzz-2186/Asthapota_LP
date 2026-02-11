<?php
require_once __DIR__ . '/../app/auth.php';
ensure_session();

$isAdmin = is_admin_logged_in();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Temu Padel 2026</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Anton&family=Manrope:wght@400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,600;0,700;1,500&display=swap');
    @import url('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css');

    :root {
      --blue: #1658ad;
      --white: #f6f7fb;
      --shadow: rgba(0, 0, 0, 0.32);
      --soft-shadow: rgba(0, 0, 0, 0.2);
      --font-body: "Manrope", "Segoe UI", Tahoma, sans-serif;
      --font-display: "Anton", "Arial Narrow", Impact, sans-serif;
      --font-accent: "Playfair Display", Georgia, serif;
    }

    * { box-sizing: border-box; }

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
      background: #0b2d61;
      overflow-x: hidden;
    }

    .landing {
      scroll-snap-type: y mandatory;
      background: url('/assets/img/wallpaper.avif') center top / cover no-repeat;
      background-attachment: scroll;
    }

    .panel {
      min-height: 100vh;
      width: min(1100px, 92vw);
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      gap: 22px;
      scroll-snap-align: start;
      padding: 48px 0;
    }

    .hero-logo {
      width: 74px;
      height: 74px;
      border-radius: 14px;
      object-fit: cover;
      box-shadow: 0 8px 25px var(--soft-shadow);
      transition: transform 0.18s ease, box-shadow 0.2s ease;
    }

    .hero-logo:hover {
      transform: translateY(-3px);
      box-shadow: 0 12px 28px rgba(0, 0, 0, 0.3);
    }

    .welcome {
      margin: 0;
      font-family: var(--font-accent);
      font-style: italic;
      font-size: clamp(38px, 4.4vw, 66px);
      line-height: 1;
      letter-spacing: 0.4px;
      text-shadow: 0 4px 18px rgba(0, 0, 0, 0.28);
    }

    .title {
      margin: 0;
      font-family: var(--font-display);
      font-size: clamp(48px, 10vw, 128px);
      line-height: 0.92;
      font-weight: 400;
      letter-spacing: 2.2px;
      text-transform: uppercase;
      text-shadow: 0 10px 20px rgba(0, 0, 0, 0.28);
    }

    .subtitle {
      margin: 0;
      font-family: var(--font-body);
      font-size: clamp(24px, 2.2vw, 36px);
      font-weight: 800;
      letter-spacing: 1px;
      text-transform: uppercase;
    }

    .date-box {
      margin-top: 18px;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      background: #fff;
      color: var(--blue);
      padding: 14px 28px;
      border-radius: 6px;
      font-family: var(--font-display);
      font-size: clamp(22px, 2.4vw, 42px);
      font-weight: 400;
      letter-spacing: 1.7px;
      text-transform: uppercase;
      box-shadow: 0 8px 24px var(--soft-shadow);
      transition: transform 0.16s ease, box-shadow 0.2s ease;
    }

    .date-box:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 28px rgba(0, 0, 0, 0.24);
    }

    .date-box i {
      font-size: 0.72em;
    }

    .date-box sup {
      font-family: var(--font-body);
      font-size: 0.38em;
      font-weight: 800;
      letter-spacing: 0.8px;
      margin-left: 2px;
    }

    .countdown-wrap {
      margin-top: 8px;
      display: grid;
      gap: 10px;
      justify-items: center;
    }

    .countdown-label {
      margin: 0;
      font-family: var(--font-accent);
      font-size: 15px;
      letter-spacing: 0.6px;
      text-transform: uppercase;
      font-weight: 600;
      opacity: 0.9;
    }

    .countdown {
      display: grid;
      grid-template-columns: repeat(4, minmax(72px, 110px));
      gap: 10px;
    }

    .count-item {
      background: rgba(255, 255, 255, 0.16);
      border: 1px solid rgba(255, 255, 255, 0.45);
      border-radius: 12px;
      padding: 10px 8px;
      backdrop-filter: blur(3px);
      transition: transform 0.16s ease, border-color 0.2s ease, background 0.2s ease;
    }

    .count-item:hover {
      transform: translateY(-2px);
      border-color: rgba(255, 255, 255, 0.65);
      background: rgba(255, 255, 255, 0.24);
    }

    .count-value {
      font-family: var(--font-display);
      font-size: clamp(24px, 2.9vw, 38px);
      font-weight: 400;
      line-height: 1;
      letter-spacing: 1.1px;
    }

    .count-unit {
      margin-top: 4px;
      font-family: var(--font-body);
      font-size: 11px;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      opacity: 0.9;
      font-weight: 700;
    }

    .count-status {
      margin: 2px 0 0;
      font-family: var(--font-body);
      font-size: 13px;
      opacity: 0.95;
      min-height: 18px;
      font-weight: 600;
    }

    .hero-join {
      margin-top: 8px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 12px 26px;
      border-radius: 999px;
      border: 2px solid rgba(255, 255, 255, 0.72);
      background: rgba(11, 45, 97, 0.35);
      color: #fff;
      font-family: var(--font-body);
      font-size: 18px;
      font-weight: 800;
      letter-spacing: 1px;
      text-transform: uppercase;
      cursor: pointer;
      transition: transform 0.15s ease, background 0.2s ease;
    }

    .hero-join:hover {
      transform: translateY(-2px);
      background: rgba(11, 45, 97, 0.55);
    }

    .hero-join:active {
      transform: translateY(0);
    }

    .support h2 {
      margin: 0 0 8px;
      font-family: var(--font-accent);
      font-size: clamp(36px, 3.4vw, 56px);
      font-weight: 700;
      letter-spacing: 0.5px;
      text-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
      display: inline-flex;
      align-items: center;
      gap: 10px;
    }

    .sponsor-strip {
      width: min(980px, 100%);
      overflow: hidden;
      padding: 12px 0;
      position: relative;
    }

    .sponsor-strip::before,
    .sponsor-strip::after {
      content: "";
      position: absolute;
      top: 0;
      width: 80px;
      height: 100%;
      z-index: 1;
      pointer-events: none;
    }

    .sponsor-strip::before {
      left: 0;
      background: linear-gradient(to right, rgba(11, 45, 97, 0.48), transparent);
    }

    .sponsor-strip::after {
      right: 0;
      background: linear-gradient(to left, rgba(11, 45, 97, 0.48), transparent);
    }

    .sponsor-track {
      width: max-content;
      display: flex;
      align-items: center;
      gap: 30px;
      animation: sponsor-scroll 22s linear infinite;
    }

    .sponsor-strip:hover .sponsor-track {
      animation-play-state: paused;
    }

    .sponsor {
      min-width: 220px;
      min-height: 110px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 8px 12px;
      border-radius: 12px;
      transition: transform 0.16s ease, background 0.2s ease;
    }

    .sponsor:hover {
      transform: translateY(-3px);
      background: rgba(255, 255, 255, 0.08);
    }

    .sponsor img {
      width: 100%;
      max-width: 230px;
      max-height: 96px;
      object-fit: contain;
      filter: brightness(0) saturate(100%) invert(100%);
      user-select: none;
      pointer-events: none;
      transition: transform 0.16s ease, filter 0.2s ease;
    }

    .sponsor:hover img {
      transform: scale(1.03);
      filter: brightness(0) saturate(100%) invert(100%) drop-shadow(0 5px 10px rgba(0, 0, 0, 0.24));
    }

    @keyframes sponsor-scroll {
      from { transform: translateX(0); }
      to { transform: translateX(-50%); }
    }

    .cta {
      margin-top: 10px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      min-width: min(560px, 90vw);
      padding: 16px 34px;
      text-decoration: none;
      background: #f3f4f8;
      color: var(--blue);
      font-family: var(--font-display);
      font-weight: 400;
      font-size: clamp(30px, 3vw, 44px);
      font-style: normal;
      letter-spacing: 1.4px;
      text-transform: uppercase;
      border-radius: 999px;
      border: 4px solid #07162d;
      box-shadow: 10px 10px 0 var(--shadow);
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .cta:hover {
      transform: translate(2px, 2px);
      box-shadow: 7px 7px 0 var(--shadow);
    }

    .cta:active {
      transform: translate(4px, 4px);
      box-shadow: 5px 5px 0 var(--shadow);
    }

    @media (prefers-reduced-motion: reduce) {
      .sponsor-track { animation: none; }
      .landing { scroll-snap-type: none; }
    }

    @media (max-width: 860px) {
      .panel {
        gap: 20px;
      }

      .date-box {
        max-width: 100%;
      }

      .countdown {
        grid-template-columns: repeat(2, minmax(90px, 130px));
      }

      .sponsor {
        min-width: 190px;
      }

      .sponsor-strip::before,
      .sponsor-strip::after {
        width: 42px;
      }
    }

    @media (max-width: 560px) {
      .panel {
        width: 94vw;
      }

      .hero-logo {
        width: 64px;
        height: 64px;
      }

      .subtitle {
        font-size: clamp(20px, 5vw, 28px);
        letter-spacing: 0.6px;
      }

      .date-box {
        font-size: clamp(18px, 6vw, 28px);
        padding: 12px 16px;
        letter-spacing: 1px;
      }

      .countdown {
        grid-template-columns: repeat(2, minmax(74px, 1fr));
        width: min(320px, 100%);
      }

      .sponsor {
        min-width: 164px;
      }

      .cta {
        min-width: 94vw;
      }
    }
  </style>
</head>
<body>
  <main class="landing">
    <section class="panel hero">
      <img class="hero-logo" src="/assets/img/lopad.jpg" alt="Astaphora logo">
      <p class="welcome">Welcome</p>
      <h1 class="title">TEMU PADEL</h1>
      <p class="subtitle"><i class="bi bi-stars"></i> A Monkeybar x BAPORA Event</p>
      <div class="date-box"><i class="bi bi-calendar-event"></i> FEBRUARY 28<sup>TH</sup>, 2026 | 4 PM - 6 PM</div>

      <div class="countdown-wrap" aria-live="polite">
        <p class="countdown-label"><i class="bi bi-hourglass-split"></i> Countdown To Event Start</p>
        <div class="countdown" id="eventCountdown">
          <div class="count-item">
            <div class="count-value" data-unit="days">00</div>
            <div class="count-unit">Days</div>
          </div>
          <div class="count-item">
            <div class="count-value" data-unit="hours">00</div>
            <div class="count-unit">Hours</div>
          </div>
          <div class="count-item">
            <div class="count-value" data-unit="minutes">00</div>
            <div class="count-unit">Minutes</div>
          </div>
          <div class="count-item">
            <div class="count-value" data-unit="seconds">00</div>
            <div class="count-unit">Seconds</div>
          </div>
        </div>
        <p class="count-status" id="countdownStatus"></p>
      </div>

      <button type="button" class="hero-join" id="ikutYukBtn"><i class="bi bi-arrow-down-circle"></i> Join Us</button>
    </section>

    <section class="panel support" id="registerPanel">
      <h2><i class="bi bi-patch-check"></i> Supported By</h2>
      <div class="sponsor-strip" aria-label="Supported by logos marquee">
        <div class="sponsor-track">
          <div class="sponsor"><img src="/assets/img/hippi.png" alt="HIPPI"></div>
          <div class="sponsor"><img src="/assets/img/logo.webp" alt="BAPORA"></div>
          <div class="sponsor"><img src="/assets/img/fcom.png" alt="FCOM"></div>
          <div class="sponsor"><img src="/assets/img/mypadel.png" alt="MY Padel"></div>

          <div class="sponsor"><img src="/assets/img/hippi.png" alt="HIPPI"></div>
          <div class="sponsor"><img src="/assets/img/logo.webp" alt="BAPORA"></div>
          <div class="sponsor"><img src="/assets/img/fcom.png" alt="FCOM"></div>
          <div class="sponsor"><img src="/assets/img/mypadel.png" alt="MY Padel"></div>
        </div>
      </div>

      <?php if ($isAdmin): ?>
        <a class="cta" href="/admin/dashboard"><i class="bi bi-speedometer2"></i> Go To Admin Dashboard</a>
      <?php else: ?>
        <a class="cta" href="/packages"><i class="bi bi-box-seam"></i> Click Here To See Packages</a>
      <?php endif; ?>
    </section>
  </main>

  <script>
    (function () {
      var ikutBtn = document.getElementById('ikutYukBtn');
      var registerPanel = document.getElementById('registerPanel');

      if (ikutBtn && registerPanel) {
        ikutBtn.addEventListener('click', function () {
          registerPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      }

      var targetStart = new Date('2026-02-28T16:00:00+07:00').getTime();
      var targetEnd = new Date('2026-02-28T18:00:00+07:00').getTime();

      var daysEl = document.querySelector('[data-unit="days"]');
      var hoursEl = document.querySelector('[data-unit="hours"]');
      var minutesEl = document.querySelector('[data-unit="minutes"]');
      var secondsEl = document.querySelector('[data-unit="seconds"]');
      var statusEl = document.getElementById('countdownStatus');

      if (!daysEl || !hoursEl || !minutesEl || !secondsEl || !statusEl) return;

      function pad(value) {
        return String(value).padStart(2, '0');
      }

      function setCountdown(ms) {
        var totalSeconds = Math.max(0, Math.floor(ms / 1000));
        var days = Math.floor(totalSeconds / 86400);
        var hours = Math.floor((totalSeconds % 86400) / 3600);
        var minutes = Math.floor((totalSeconds % 3600) / 60);
        var seconds = totalSeconds % 60;

        daysEl.textContent = pad(days);
        hoursEl.textContent = pad(hours);
        minutesEl.textContent = pad(minutes);
        secondsEl.textContent = pad(seconds);
      }

      function tick() {
        var now = Date.now();

        if (now >= targetStart && now < targetEnd) {
          setCountdown(0);
          statusEl.textContent = 'Event is currently running (4 PM - 6 PM).';
          return;
        }

        if (now >= targetEnd) {
          setCountdown(0);
          statusEl.textContent = 'Event has ended. See you at the next Temu Padel.';
          return;
        }

        setCountdown(targetStart - now);
        statusEl.textContent = '';
      }

      tick();
      setInterval(tick, 1000);
    })();
  </script>
</body>
</html>
