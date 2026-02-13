<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
ensure_session();
unset($_SESSION['order_draft']);

$isAdmin = is_admin_logged_in();
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$basePath = ($scriptDir === '/' || $scriptDir === '.') ? '' : rtrim($scriptDir, '/');

$sponsorItems = [];
try {
    $db = get_db();
    $sponsorRows = $db->query('SELECT name, website_url, logo_path FROM sponsors ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sponsorRows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        $logoPath = trim((string)($row['logo_path'] ?? ''));
        $websiteUrl = trim((string)($row['website_url'] ?? ''));
        if ($logoPath === '') {
            continue;
        }

        if (preg_match('/^https?:\/\//i', $logoPath)) {
            $logoSrc = $logoPath;
        } else {
            $logoSrc = $basePath . '/' . ltrim($logoPath, '/');
        }

        $sponsorItems[] = [
            'name' => $name !== '' ? $name : 'Sponsor',
            'logo' => $logoSrc,
            'url' => filter_var($websiteUrl, FILTER_VALIDATE_URL) ? $websiteUrl : '',
        ];
    }
} catch (Throwable $e) {
    $sponsorItems = [];
}

if (!$sponsorItems) {
    $sponsorItems = [
        ['name' => 'HIPPI', 'logo' => $basePath . '/assets/img/hippi.png', 'url' => 'https://www.hippi.or.id/'],
        ['name' => 'BAPORA', 'logo' => $basePath . '/assets/img/logo.webp', 'url' => 'https://www.hippi.or.id/'],
        ['name' => 'FCOM', 'logo' => $basePath . '/assets/img/fcom.png', 'url' => 'https://fcom.co.id/'],
        ['name' => 'MY Padel', 'logo' => $basePath . '/assets/img/mypadel.png', 'url' => 'https://ayo.co.id/v/mypadel'],
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Home - Temu Padel 2026</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Anton&family=Manrope:wght@400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,600;0,700;1,500&display=swap');
    @import url('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css');

    :root {
      --blue: #b8bec8;
      --white: #f6f7fb;
      --shadow: rgba(0, 0, 0, 0.32);
      --soft-shadow: rgba(0, 0, 0, 0.2);
      --text-stroke-dark: rgba(0, 0, 0, 0.88);
      --font-body: "Manrope", "Segoe UI", Tahoma, sans-serif;
      --font-display: "Anton", "Arial Narrow", Impact, sans-serif;
      --font-accent: "Playfair Display", Georgia, serif;
      --sponsor-card-w: 220px;
      --sponsor-card-h: 110px;
      --sponsor-logo-w: 172px;
      --sponsor-logo-h: 72px;
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
      overscroll-behavior: none;
    }

    body {
      color: var(--white);
      font-family: var(--font-body);
      font-weight: 500;
      letter-spacing: 0.2px;
      min-height: 100svh;
      background:
        linear-gradient(180deg, rgba(7, 8, 10, 0.28) 0%, rgba(7, 8, 10, 0.5) 100%),
        url('<?= h($basePath) ?>/assets/img/wallpapeh2.jpg') center center / cover no-repeat;
      background-attachment: scroll;
      position: relative;
      overflow-x: hidden;
      opacity: 0;
      transform: translateY(14px) scale(0.99);
      filter: blur(8px);
      transition: opacity 0.55s ease, transform 0.55s ease, filter 0.55s ease;
    }

    body::before,
    body::after {
      content: "";
      position: fixed;
      inset: -12vh -8vw;
      pointer-events: none;
      z-index: 5;
      opacity: 0;
      transform: scale(1.03);
    }

    body::before {
      background:
        radial-gradient(58% 54% at 50% 42%, rgba(255, 255, 255, 0.24), rgba(255, 255, 255, 0) 72%),
        radial-gradient(36% 34% at 18% 82%, rgba(182, 188, 198, 0.2), rgba(182, 188, 198, 0) 70%),
        radial-gradient(34% 32% at 82% 18%, rgba(138, 144, 156, 0.2), rgba(138, 144, 156, 0) 72%);
      filter: blur(10px);
    }

    body::after {
      background: linear-gradient(110deg, rgba(255, 255, 255, 0) 20%, rgba(255, 255, 255, 0.45) 48%, rgba(255, 255, 255, 0) 72%);
      transform: translateX(-45%) skewX(-16deg);
    }

    body.page-ready::before {
      animation: intro-glow 1.05s ease-out both;
    }

    body.page-ready::after {
      animation: intro-sweep 1.1s ease-out 0.08s both;
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

    .scroll-progress {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 3px;
      z-index: 46;
      pointer-events: none;
      background: rgba(255, 255, 255, 0.14);
      backdrop-filter: blur(2px);
    }

    .scroll-progress-bar {
      display: block;
      width: 100%;
      height: 100%;
      transform-origin: left center;
      transform: scaleX(0);
      background: linear-gradient(90deg, #8f949d 0%, #f2f3f6 56%, #9aa0ab 100%);
      box-shadow: 0 0 12px rgba(197, 203, 214, 0.4);
      transition: transform 0.14s linear;
    }

    .landing {
      min-height: 100svh;
      background: transparent;
    }

    .panel {
      min-height: 100svh;
      width: min(1100px, 92vw);
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      gap: 22px;
      padding: 48px 0;
    }

    .hero {
      min-height: 100svh;
      width: min(1200px, 96vw);
      margin: 0 auto;
      gap: 18px;
      padding: clamp(10px, 1.6vh, 20px) clamp(16px, 3vw, 28px) clamp(34px, 5.4vh, 56px);
      justify-content: center;
      align-items: center;
    }

    .hero [data-seq] {
      opacity: 0;
      transform: translateY(26px) scale(0.98);
      filter: blur(5px);
      transition: opacity 0.62s cubic-bezier(.2,.7,.2,1), transform 0.62s cubic-bezier(.2,.7,.2,1), filter 0.62s ease;
      will-change: opacity, transform, filter;
    }

    body.page-ready .hero [data-seq] {
      opacity: 1;
      transform: none;
      filter: none;
      transition-delay: var(--seq-delay, 0ms);
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
      font-size: clamp(72px, 7.8vw, 110px);
      line-height: 1;
      letter-spacing: 0.4px;
      text-shadow: 0 4px 18px rgba(0, 0, 0, 0.28);
    }

    .title {
      margin: 0;
      font-family: var(--font-display);
      font-size: clamp(80px, 12.5vw, 170px);
      line-height: 0.92;
      font-weight: 400;
      letter-spacing: 2.2px;
      text-transform: uppercase;
      text-shadow: 0 10px 20px rgba(0, 0, 0, 0.28);
    }

    .subtitle {
      margin: 0;
      font-family: var(--font-body);
      font-size: clamp(42px, 3.9vw, 64px);
      font-weight: 800;
      letter-spacing: 1px;
      text-transform: uppercase;
    }

    .hero .welcome,
    .hero .title,
    .hero .subtitle {
      -webkit-text-stroke: 1px var(--text-stroke-dark);
      paint-order: stroke fill;
      text-shadow:
        0 2px 10px rgba(0, 0, 0, 0.42),
        0 0 1px rgba(0, 0, 0, 0.95);
    }

    .date-box {
      margin-top: 18px;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      background: #fff;
      color: #14171d;
      padding: 14px 28px;
      border-radius: 6px;
      font-family: var(--font-display);
      font-size: clamp(40px, 4.4vw, 68px);
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
      position: relative;
      isolation: isolate;
    }

    .countdown-wrap::before,
    .countdown-wrap::after {
      content: "";
      position: absolute;
      inset: -14px -16px;
      border-radius: 18px;
      pointer-events: none;
      opacity: 0;
      transform: scale(0.92);
      z-index: -1;
    }

    .countdown-wrap::before {
      border: 1.5px solid rgba(255, 255, 255, 0.78);
    }

    .countdown-wrap::after {
      background: radial-gradient(circle at 50% 50%, rgba(255, 245, 196, 0.5), rgba(255, 255, 255, 0) 64%);
      filter: blur(6px);
    }

    .countdown-wrap.start-burst::before {
      animation: event-start-ring 780ms cubic-bezier(.12,.64,.16,1) forwards;
    }

    .countdown-wrap.start-burst::after {
      animation: event-start-glow 900ms ease-out forwards;
    }

    .countdown-label {
      margin: 0;
      font-family: var(--font-accent);
      font-size: 28px;
      letter-spacing: 0.6px;
      text-transform: uppercase;
      font-weight: 600;
      opacity: 0.9;
    }

    .countdown {
      display: grid;
      grid-template-columns: repeat(4, minmax(90px, 130px));
      gap: 12px;
    }

    .countdown.is-hidden {
      display: none;
    }

    .count-item {
      background: rgba(255, 255, 255, 0.16);
      border: 1px solid rgba(255, 255, 255, 0.45);
      border-radius: 12px;
      padding: 12px 10px;
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
      font-size: clamp(44px, 5vw, 70px);
      font-weight: 400;
      line-height: 1;
      letter-spacing: 1.1px;
      transform-origin: 50% 50%;
      backface-visibility: hidden;
    }

    .count-value.flip {
      animation: countdown-flip 340ms cubic-bezier(.2,.7,.2,1);
    }

    .count-unit {
      margin-top: 4px;
      font-family: var(--font-body);
      font-size: 18px;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      opacity: 0.9;
      font-weight: 700;
    }

    .count-status {
      margin: 2px 0 0;
      font-family: var(--font-body);
      font-size: 18px;
      opacity: 0.95;
      min-height: 18px;
      font-weight: 600;
    }

    .hero .countdown-label,
    .hero .count-value,
    .hero .count-unit,
    .hero .count-status {
      -webkit-text-stroke: 0.7px rgba(0, 0, 0, 0.84);
      paint-order: stroke fill;
      text-shadow: 0 1px 6px rgba(0, 0, 0, 0.4);
    }

    .count-status.live {
      margin-top: 6px;
      padding: 8px 18px;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.7);
      background: rgba(255, 255, 255, 0.2);
      font-family: var(--font-display);
      letter-spacing: 1.4px;
      text-transform: uppercase;
      font-size: clamp(18px, 2.1vw, 28px);
      line-height: 1.1;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
    }

    .hero-join {
      --mx: 50%;
      --my: 50%;
      --tilt-x: 0deg;
      --tilt-y: 0deg;

      margin-top: 12px;

      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 12px;

      padding: 22px 62px;
      font-size: 30px;
      font-weight: 900;     /* ? lebih tegas */

      border-radius: 999px;
      border: 2px solid rgba(255, 255, 255, 0.72);
      background: rgba(25, 29, 36, 0.46);
      color: #fff;
      letter-spacing: 1.6px;
      
      position: relative;
      overflow: hidden;
      isolation: isolate;
    }

    .hero-join::before {
      content: "";
      position: absolute;
      inset: -2px;
      border-radius: inherit;
      background: linear-gradient(110deg, rgba(255, 255, 255, 0) 24%, rgba(255, 255, 255, 0.42) 48%, rgba(255, 255, 255, 0) 74%);
      transform: translateX(-130%) skewX(-16deg);
      animation: join-sheen 3.6s ease-in-out infinite;
      pointer-events: none;
      z-index: 0;
    }

    .hero-join::after {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: inherit;
      pointer-events: none;
      z-index: 0;
      opacity: 0;
      background: radial-gradient(160px circle at var(--mx) var(--my), rgba(255, 255, 255, 0.35), rgba(255, 255, 255, 0) 62%);
      transition: opacity 0.2s ease;
    }

    .hero-join > * {
      position: relative;
      z-index: 1;
    }

    .hero-join i {
      transition: transform 0.2s ease;
    }

    .hero-join:hover {
      transform: perspective(720px) translateY(-3px) scale(1.02) rotateX(var(--tilt-x)) rotateY(var(--tilt-y));
      background: rgba(34, 39, 48, 0.72);
      border-color: rgba(255, 255, 255, 0.95);
      box-shadow: 0 10px 24px rgba(12, 14, 20, 0.42);
    }

    .hero-join:hover::after {
      opacity: 1;
    }

    .hero-join:hover i {
      animation: join-icon-bob 0.9s ease-in-out infinite;
    }

    .hero-join:active {
      transform: perspective(720px) translateY(-1px) scale(0.995) rotateX(var(--tilt-x)) rotateY(var(--tilt-y));
    }

    .join-ripple {
      position: absolute;
      width: 14px;
      height: 14px;
      border-radius: 999px;
      pointer-events: none;
      z-index: 0;
      opacity: 0.55;
      transform: translate(-50%, -50%) scale(0);
      background: radial-gradient(circle, rgba(255, 255, 255, 0.82) 0%, rgba(255, 255, 255, 0.34) 52%, rgba(255, 255, 255, 0) 100%);
      animation: join-ripple 620ms ease-out forwards;
    }

    .support h2 {
      margin: 0 0 8px;
      font-family: var(--font-accent);
      font-size: clamp(30px, 2.8vw, 46px);
      font-weight: 700;
      letter-spacing: 0.5px;
      text-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
      display: inline-flex;
      align-items: center;
      gap: 10px;
    }

    body.js-observe [data-reveal] {
      opacity: 0;
      transform: translateY(26px) scale(0.99);
      filter: blur(4px);
      transition: opacity 0.55s cubic-bezier(.2,.7,.2,1), transform 0.55s cubic-bezier(.2,.7,.2,1), filter 0.55s ease;
      transition-delay: var(--reveal-delay, 0ms);
      will-change: opacity, transform, filter;
    }

    body.js-observe [data-reveal].is-visible {
      opacity: 1;
      transform: none;
      filter: none;
    }

    .sponsor-strip {
      width: 100vw;
      margin-left: calc(50% - 50vw);
      margin-right: calc(50% - 50vw);
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
      background: linear-gradient(to right, rgba(16, 18, 24, 0.6), transparent);
    }

    .sponsor-strip::after {
      right: 0;
      background: linear-gradient(to left, rgba(16, 18, 24, 0.6), transparent);
    }

    .sponsor-track {
      --marquee-shift: 50%;
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
      width: var(--sponsor-card-w);
      min-width: var(--sponsor-card-w);
      height: var(--sponsor-card-h);
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

    .sponsor:focus-visible {
      outline: none;
      transform: translateY(-2px);
      background: rgba(255, 255, 255, 0.14);
      box-shadow: 0 0 0 2px rgba(9, 26, 53, 0.85), 0 0 0 5px rgba(137, 201, 255, 0.95);
      animation: focus-ring-pulse 760ms ease-out 1;
    }

    .sponsor img {
      width: var(--sponsor-logo-w);
      height: var(--sponsor-logo-h);
      object-fit: contain;
      object-position: center;
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
      to { transform: translateX(calc(-1 * var(--marquee-shift))); }
    }

    @keyframes join-pulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(188, 194, 203, 0.12); }
      50% { box-shadow: 0 0 0 9px rgba(188, 194, 203, 0.03); }
    }

    @keyframes join-sheen {
      0%, 42% { transform: translateX(-130%) skewX(-16deg); opacity: 0; }
      52% { opacity: 1; }
      72%, 100% { transform: translateX(130%) skewX(-16deg); opacity: 0; }
    }

    @keyframes join-icon-bob {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(2px); }
    }

    @keyframes join-ripple {
      from {
        transform: translate(-50%, -50%) scale(0);
        opacity: 0.58;
      }
      to {
        transform: translate(-50%, -50%) scale(14);
        opacity: 0;
      }
    }

    @keyframes countdown-flip {
      0% {
        opacity: 0.26;
        transform: translateY(-10px) scale(0.92) rotateX(-48deg);
      }
      62% {
        opacity: 1;
        transform: translateY(2px) scale(1.04) rotateX(6deg);
      }
      100% {
        opacity: 1;
        transform: none;
      }
    }

    @keyframes focus-ring-pulse {
      0% {
        box-shadow: 0 0 0 0 rgba(187, 193, 202, 0);
      }
      50% {
        box-shadow: 0 0 0 2px rgba(19, 22, 28, 0.85), 0 0 0 8px rgba(187, 193, 202, 0.35);
      }
      100% {
        box-shadow: 0 0 0 2px rgba(19, 22, 28, 0.85), 0 0 0 5px rgba(187, 193, 202, 0.95);
      }
    }

    @keyframes event-start-ring {
      0% {
        opacity: 0;
        transform: scale(0.88);
      }
      20% {
        opacity: 0.95;
      }
      100% {
        opacity: 0;
        transform: scale(1.12);
      }
    }

    @keyframes event-start-glow {
      0% {
        opacity: 0;
        transform: scale(0.94);
      }
      22% {
        opacity: 0.85;
      }
      100% {
        opacity: 0;
        transform: scale(1.18);
      }
    }

    @keyframes intro-glow {
      0% {
        opacity: 0;
        transform: scale(1.06);
      }
      22% {
        opacity: 0.92;
      }
      100% {
        opacity: 0;
        transform: scale(1);
      }
    }

    @keyframes intro-sweep {
      0% {
        opacity: 0;
        transform: translateX(-56%) skewX(-16deg);
      }
      14% {
        opacity: 0.52;
      }
      100% {
        opacity: 0;
        transform: translateX(58%) skewX(-16deg);
      }
    }

    .cta {
      --mx: 50%;
      --my: 50%;
      --tilt-x: 0deg;
      --tilt-y: 0deg;
      margin-top: 10px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      min-width: min(460px, 88vw);
      padding: 14px 28px;
      text-decoration: none;
      background: #f3f4f8;
      color: var(--blue);
      font-family: var(--font-display);
      font-weight: 400;
      font-size: clamp(22px, 2.2vw, 32px);
      font-style: normal;
      letter-spacing: 1px;
      text-transform: uppercase;
      border-radius: 999px;
      border: 2px solid rgba(255, 255, 255, 0.72);
      background: rgba(25, 29, 36, 0.52);
      color: #fff;
      box-shadow: 0 0 0 rgba(182, 188, 198, 0.3);
      position: relative;
      overflow: hidden;
      isolation: isolate;
      animation: join-pulse 2.8s ease-in-out infinite;
      transform: perspective(720px) translateY(0) rotateX(var(--tilt-x)) rotateY(var(--tilt-y));
      transition: transform 0.16s ease, background 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    }

    .cta::before {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: inherit;
      background: linear-gradient(110deg, rgba(255, 255, 255, 0) 24%, rgba(255, 255, 255, 0.42) 48%, rgba(255, 255, 255, 0) 74%);
      transform: translateX(-130%) skewX(-16deg);
      animation: join-sheen 3.6s ease-in-out infinite;
      pointer-events: none;
      z-index: 0;
    }

    .cta::after {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: inherit;
      pointer-events: none;
      z-index: 0;
      opacity: 0;
      background: radial-gradient(160px circle at var(--mx) var(--my), rgba(255, 255, 255, 0.35), rgba(255, 255, 255, 0) 62%);
      transition: opacity 0.2s ease;
    }

    .cta > * {
      position: relative;
      z-index: 1;
    }

    .cta i {
      transition: transform 0.2s ease;
    }

    .cta:hover {
      transform: perspective(720px) translateY(-3px) scale(1.02) rotateX(var(--tilt-x)) rotateY(var(--tilt-y));
      background: rgba(34, 39, 48, 0.78);
      border-color: rgba(255, 255, 255, 0.95);
      box-shadow: 0 10px 24px rgba(12, 14, 20, 0.42);
    }

    .cta:hover::after {
      opacity: 1;
    }

    .cta:hover i {
      animation: join-icon-bob 0.9s ease-in-out infinite;
    }

    .cta:active {
      transform: perspective(720px) translateY(-1px) scale(0.995) rotateX(var(--tilt-x)) rotateY(var(--tilt-y));
    }

    .cta-register {
      background: linear-gradient(135deg, #c6cbd4 0%, #9aa0aa 52%, #6f7682 100%);
      color: #12151c;
      border-color: rgba(236, 239, 245, 0.94);
      box-shadow: 0 10px 24px rgba(10, 12, 17, 0.36);
      animation: none;
      margin-bottom: 12px;
    }

    .cta-register::before,
    .cta-register::after {
      display: none;
    }

    .cta-register:hover {
      background: linear-gradient(135deg, #d9dde4 0%, #acb2bc 52%, #7f8692 100%);
      color: #0f131a;
      border-color: rgba(245, 247, 251, 0.98);
      box-shadow: 0 12px 28px rgba(10, 12, 17, 0.42);
    }

    .hero-join:focus-visible,
    .cta:focus-visible {
      outline: none;
      border-color: #ffffff;
      animation: focus-ring-pulse 760ms ease-out 1;
    }

    .hero-join:focus-visible {
      box-shadow: 0 0 0 2px rgba(19, 22, 28, 0.92), 0 0 0 6px rgba(187, 193, 202, 0.9), 0 10px 24px rgba(12, 14, 20, 0.42);
    }

    .cta:focus-visible {
      box-shadow: 0 0 0 2px rgba(19, 22, 28, 0.92), 0 0 0 7px rgba(187, 193, 202, 0.94), 0 10px 24px rgba(12, 14, 20, 0.42);
    }

    .back-top {
      position: fixed;
      right: clamp(14px, 2vw, 24px);
      bottom: clamp(14px, 2.4vw, 26px);
      width: 50px;
      height: 50px;
      border: 1.6px solid rgba(255, 255, 255, 0.76);
      border-radius: 999px;
      background: rgba(25, 29, 36, 0.62);
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 21px;
      cursor: pointer;
      z-index: 47;
      opacity: 0;
      visibility: hidden;
      transform: translateY(14px) scale(0.95);
      box-shadow: 0 8px 18px rgba(12, 14, 20, 0.36);
      backdrop-filter: blur(4px);
      transition: opacity 0.2s ease, transform 0.2s ease, visibility 0s linear 0.2s, background 0.2s ease, border-color 0.2s ease;
    }

    .back-top.is-visible {
      opacity: 1;
      visibility: visible;
      transform: translateY(0) scale(1);
      transition-delay: 0s;
    }

    .back-top:hover {
      background: rgba(34, 39, 48, 0.84);
      border-color: rgba(255, 255, 255, 0.95);
      transform: translateY(-2px) scale(1.04);
    }

    .back-top:active {
      transform: translateY(0) scale(0.98);
    }

    .back-top:focus-visible {
      outline: none;
      border-color: #fff;
      box-shadow: 0 0 0 2px rgba(19, 22, 28, 0.92), 0 0 0 6px rgba(187, 193, 202, 0.92), 0 8px 18px rgba(12, 14, 20, 0.36);
      animation: focus-ring-pulse 760ms ease-out 1;
    }

    @media (prefers-reduced-motion: reduce) {
      .sponsor-track { animation: none; }
      .hero-join,
      .hero-join::before,
      .hero-join::after,
      .hero-join:hover i,
      .cta,
      .cta::before,
      .cta::after,
      .cta:hover i {
        animation: none;
      }
      .hero-join:focus-visible,
      .cta:focus-visible,
      .sponsor:focus-visible,
      .back-top:focus-visible {
        animation: none;
      }
      .countdown-wrap::before,
      .countdown-wrap::after,
      .countdown-wrap.start-burst::before,
      .countdown-wrap.start-burst::after {
        animation: none;
        opacity: 0;
      }
      .count-value.flip {
        animation: none;
      }
      .scroll-progress-bar {
        transition: none;
      }
      html, body { scroll-snap-type: none; }
      body::before,
      body::after {
        display: none;
      }
      .hero > * {
        opacity: 1;
        transform: none;
        filter: none;
        transition: none;
      }
      body,
      body.page-ready,
      body.page-leaving {
        opacity: 1;
        transform: none;
        filter: none;
        transition: none;
      }
    }

    @media (max-height: 900px) {
      .hero {
        gap: 14px;
        padding: clamp(10px, 1.8vh, 18px) clamp(14px, 2.6vw, 24px) clamp(24px, 4.2vh, 36px);
      }

      .hero-logo {
        width: 64px;
        height: 64px;
      }

      .welcome {
        font-size: clamp(58px, 8.6vh, 94px);
      }

      .support h2 {
        font-size: clamp(27px, 3.3vh, 38px);
      }

      .title {
        font-size: clamp(66px, 14vh, 132px);
      }

      .subtitle {
        font-size: clamp(34px, 5.4vh, 52px);
      }

      .date-box {
        margin-top: 6px;
        padding: 12px 22px;
        font-size: clamp(34px, 5.8vh, 52px);
      }

      .countdown-wrap {
        margin-top: 2px;
        gap: 6px;
      }

      .countdown {
        grid-template-columns: repeat(4, minmax(82px, 110px));
        gap: 10px;
      }

      .count-item {
        padding: 10px 8px;
      }

      .count-value {
        font-size: clamp(38px, 6vh, 56px);
      }

      .hero-join {
        margin-top: 4px;
        padding: 18px 40px;
        font-size: 28px;
      }
    }

    @media (max-height: 760px) {
      .hero {
        gap: 8px;
        padding: clamp(8px, 1.2vh, 14px) clamp(12px, 2.2vw, 20px) clamp(16px, 3vh, 24px);
      }

      .hero-logo {
        width: 56px;
        height: 56px;
      }

      .welcome {
        font-size: clamp(50px, 7vh, 78px);
      }

      .support h2 {
        font-size: clamp(24px, 3.1vh, 34px);
      }

      .title {
        font-size: clamp(56px, 11.8vh, 104px);
      }

      .subtitle {
        font-size: clamp(30px, 4.8vh, 44px);
      }

      .date-box {
        margin-top: 2px;
        padding: 10px 16px;
        font-size: clamp(28px, 5vh, 42px);
      }

      .countdown-label {
        font-size: 20px;
      }

      .countdown {
        grid-template-columns: repeat(4, minmax(72px, 96px));
        gap: 8px;
      }

      .count-item {
        padding: 8px 6px;
      }

      .count-value {
        font-size: clamp(34px, 5.8vh, 48px);
      }

      .count-unit {
        font-size: 15px;
      }

      .hero-join {
        padding: 16px 32px;
        font-size: 24px;
      }
    }

    @media (max-width: 860px) {
      :root {
        --sponsor-card-w: 190px;
        --sponsor-card-h: 96px;
        --sponsor-logo-w: 150px;
        --sponsor-logo-h: 60px;
      }

      .panel {
        gap: 20px;
      }

      .date-box {
        max-width: 100%;
      }

      .countdown {
        grid-template-columns: repeat(2, minmax(90px, 130px));
      }

      .sponsor-strip::before,
      .sponsor-strip::after {
        width: 42px;
      }
    }

    @media (max-width: 560px) {
      :root {
        --sponsor-card-w: 166px;
        --sponsor-card-h: 86px;
        --sponsor-logo-w: 132px;
        --sponsor-logo-h: 54px;
      }

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
        width: 100%;
      }

      /* ?? JOIN US BUTTON - Dibuat jauh lebih besar */
      .hero-join {
        width: 100%;
        padding: 18px 22px;
        font-size: 22px;
        font-weight: 900;
        letter-spacing: 1.5px;
        border-width: 2px;
      }

      .hero-join i {
        font-size: 22px;
      }

      /* ?? CTA BUTTON (See Packages / Admin) */
      .cta {
        width: 100%;
        min-width: 100%;
        padding: 18px 20px;
        font-size: 24px;
        font-weight: 700;
        letter-spacing: 1px;
      }

      .cta i {
        font-size: 22px;
      }

    }
  </style>
</head>
<body>
  <div class="scroll-progress" aria-hidden="true">
    <span class="scroll-progress-bar" id="scrollProgressBar"></span>
  </div>
  <button type="button" class="back-top" id="backTopBtn" aria-label="Back to top">
    <i class="bi bi-arrow-up"></i>
  </button>

  <main class="landing">
    <section class="panel hero">
      <img class="hero-logo" data-seq style="--seq-delay: 80ms;" src="<?= h($basePath) ?>/assets/img/lopad.jpg" alt="Astaphora logo">
      <p class="welcome" data-seq style="--seq-delay: 170ms;">Welcome</p>
      <h1 class="title" data-seq style="--seq-delay: 260ms;">TEMU PADEL</h1>
      <p class="subtitle" data-seq style="--seq-delay: 350ms;"><i class="bi bi-stars"></i> A Monkeybar x BAPORA Event</p>
      <div class="date-box" data-seq style="--seq-delay: 440ms;"><i class="bi bi-calendar-event"></i> FEBRUARY 28<sup>TH</sup>, 2026 | 4 PM - 6 PM</div>

      <div class="countdown-wrap" data-seq style="--seq-delay: 530ms;" aria-live="polite">
        <p class="countdown-label" id="countdownLabel"><i class="bi bi-hourglass-split"></i> Countdown To Event Start</p>
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

      <button type="button" class="hero-join" data-seq style="--seq-delay: 620ms;" id="ikutYukBtn"><i class="bi bi-arrow-down-circle"></i> Join Us</button>
    </section>

    <section class="panel support" id="registerPanel">
      <h2 data-reveal style="--reveal-delay: 60ms;"><i class="bi bi-patch-check"></i> Supported By</h2>
      <div class="sponsor-strip" data-reveal style="--reveal-delay: 150ms;" aria-label="Supported by logos marquee">
        <div class="sponsor-track">
          <?php foreach ($sponsorItems as $sp): ?>
            <?php if (!empty($sp['url'])): ?>
              <a href="<?= h($sp['url']) ?>" target="_blank" rel="noopener noreferrer" class="sponsor"><img src="<?= h($sp['logo']) ?>" alt="<?= h($sp['name']) ?>"></a>
            <?php else: ?>
              <div class="sponsor"><img src="<?= h($sp['logo']) ?>" alt="<?= h($sp['name']) ?>"></div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($isAdmin): ?>
        <a class="cta" data-reveal style="--reveal-delay: 240ms;" href="/admin/dashboard"><i class="bi bi-speedometer2"></i> Go To Admin Dashboard</a>
      <?php else: ?>
        <a class="cta cta-register" data-reveal style="--reveal-delay: 240ms;" href="/register?from=home"><i class="bi bi-person-plus"></i> Click Here To Register</a>
        <a class="cta" data-reveal style="--reveal-delay: 240ms;" href="/packages"><i class="bi bi-box-seam"></i> Click Here To See Packages</a>
      <?php endif; ?>
    </section>
  </main>

  <script>
    (function () {
      if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
      }
      window.scrollTo(0, 0);
      window.addEventListener('pageshow', function () {
        window.scrollTo(0, 0);
        requestScrollProgressUpdate();
      });

      var body = document.body;
      var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      var scrollProgressBar = document.getElementById('scrollProgressBar');
      var backTopBtn = document.getElementById('backTopBtn');
      var progressTicking = false;

      function updateScrollProgress() {
        if (!scrollProgressBar) return;
        var doc = document.documentElement;
        var scrollTop = window.pageYOffset || doc.scrollTop || 0;
        var maxScroll = Math.max(1, doc.scrollHeight - window.innerHeight);
        var progress = Math.max(0, Math.min(1, scrollTop / maxScroll));
        scrollProgressBar.style.transform = 'scaleX(' + progress.toFixed(4) + ')';

        if (backTopBtn) {
          backTopBtn.classList.toggle('is-visible', scrollTop > 320);
        }
      }

      function requestScrollProgressUpdate() {
        if (progressTicking) return;
        progressTicking = true;
        requestAnimationFrame(function () {
          progressTicking = false;
          updateScrollProgress();
        });
      }

      window.addEventListener('scroll', requestScrollProgressUpdate, { passive: true });
      window.addEventListener('resize', requestScrollProgressUpdate);
      requestScrollProgressUpdate();

      if (backTopBtn) {
        backTopBtn.addEventListener('click', function () {
          window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
        });
      }

      if (body && !reduceMotion) {
        requestAnimationFrame(function () {
          body.classList.add('page-ready');
        });
      } else if (body) {
        body.classList.add('page-ready');
      }

      if (body && !reduceMotion && 'IntersectionObserver' in window) {
        body.classList.add('js-observe');
        var revealTargets = document.querySelectorAll('#registerPanel [data-reveal]');
        var revealObserver = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            entry.target.classList.toggle('is-visible', entry.isIntersecting);
          });
        }, { threshold: 0.2, rootMargin: '0px 0px -8% 0px' });
        revealTargets.forEach(function (el) {
          revealObserver.observe(el);
        });
      } else {
        document.querySelectorAll('#registerPanel [data-reveal]').forEach(function (el) {
          el.classList.add('is-visible');
        });
      }

      function setupSponsorMarquee() {
        var tracks = document.querySelectorAll('.sponsor-strip .sponsor-track');
        tracks.forEach(function (track) {
          var strip = track.closest('.sponsor-strip');
          if (!strip) return;

          Array.prototype.slice.call(track.children).forEach(function (node) {
            if (node.dataset.clone === '1') {
              node.remove();
            }
          });

          var baseItems = Array.prototype.slice.call(track.querySelectorAll('.sponsor')).filter(function (item) {
            return item.dataset.clone !== '1';
          });
          if (!baseItems.length) return;

          var style = window.getComputedStyle(track);
          var gap = parseFloat(style.columnGap || style.gap || '0') || 0;
          var firstItem = baseItems[0];
          var lastItem = baseItems[baseItems.length - 1];
          var baseWidth = (lastItem.offsetLeft + lastItem.offsetWidth) - firstItem.offsetLeft;
          var shift = Math.max(1, baseWidth + gap);

          function appendCloneSet() {
            baseItems.forEach(function (item) {
              var clone = item.cloneNode(true);
              clone.dataset.clone = '1';
              clone.setAttribute('aria-hidden', 'true');
              clone.setAttribute('tabindex', '-1');
              track.appendChild(clone);
            });
          }

          appendCloneSet();
          while ((track.scrollWidth - shift) < (strip.clientWidth + 2)) {
            appendCloneSet();
          }

          track.style.setProperty('--marquee-shift', shift + 'px');
        });
      }

      setupSponsorMarquee();
      window.addEventListener('load', setupSponsorMarquee);
      window.addEventListener('resize', setupSponsorMarquee);

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

      var ikutBtn = document.getElementById('ikutYukBtn');
      var registerPanel = document.getElementById('registerPanel');
      var ctaButtons = Array.prototype.slice.call(document.querySelectorAll('.cta'));

      if (ikutBtn && registerPanel) {
        if (!reduceMotion && window.matchMedia('(pointer:fine)').matches) {
          ikutBtn.addEventListener('pointermove', function (event) {
            var rect = ikutBtn.getBoundingClientRect();
            var x = event.clientX - rect.left;
            var y = event.clientY - rect.top;
            var px = Math.max(0, Math.min(100, (x / rect.width) * 100));
            var py = Math.max(0, Math.min(100, (y / rect.height) * 100));
            var tiltX = ((py - 50) / 50) * -3.5;
            var tiltY = ((px - 50) / 50) * 3.5;

            ikutBtn.style.setProperty('--mx', px.toFixed(2) + '%');
            ikutBtn.style.setProperty('--my', py.toFixed(2) + '%');
            ikutBtn.style.setProperty('--tilt-x', tiltX.toFixed(2) + 'deg');
            ikutBtn.style.setProperty('--tilt-y', tiltY.toFixed(2) + 'deg');
          });

          ikutBtn.addEventListener('pointerleave', function () {
            ikutBtn.style.setProperty('--mx', '50%');
            ikutBtn.style.setProperty('--my', '50%');
            ikutBtn.style.setProperty('--tilt-x', '0deg');
            ikutBtn.style.setProperty('--tilt-y', '0deg');
          });
        }

        ikutBtn.addEventListener('click', function () {
          if (!reduceMotion) {
            var rect = ikutBtn.getBoundingClientRect();
            var ripple = document.createElement('span');
            ripple.className = 'join-ripple';
            ripple.style.left = (rect.width / 2) + 'px';
            ripple.style.top = (rect.height / 2) + 'px';
            ikutBtn.appendChild(ripple);
            ripple.addEventListener('animationend', function () {
              ripple.remove();
            });
          }
          registerPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      }

      ctaButtons.forEach(function (ctaBtn) {
        if (!reduceMotion && window.matchMedia('(pointer:fine)').matches) {
          ctaBtn.addEventListener('pointermove', function (event) {
            var rect = ctaBtn.getBoundingClientRect();
            var x = event.clientX - rect.left;
            var y = event.clientY - rect.top;
            var px = Math.max(0, Math.min(100, (x / rect.width) * 100));
            var py = Math.max(0, Math.min(100, (y / rect.height) * 100));
            var tiltX = ((py - 50) / 50) * -3.5;
            var tiltY = ((px - 50) / 50) * 3.5;

            ctaBtn.style.setProperty('--mx', px.toFixed(2) + '%');
            ctaBtn.style.setProperty('--my', py.toFixed(2) + '%');
            ctaBtn.style.setProperty('--tilt-x', tiltX.toFixed(2) + 'deg');
            ctaBtn.style.setProperty('--tilt-y', tiltY.toFixed(2) + 'deg');
          });

          ctaBtn.addEventListener('pointerleave', function () {
            ctaBtn.style.setProperty('--mx', '50%');
            ctaBtn.style.setProperty('--my', '50%');
            ctaBtn.style.setProperty('--tilt-x', '0deg');
            ctaBtn.style.setProperty('--tilt-y', '0deg');
          });
        }

        ctaBtn.addEventListener('click', function () {
          if (!reduceMotion) {
            var rect = ctaBtn.getBoundingClientRect();
            var ripple = document.createElement('span');
            ripple.className = 'join-ripple';
            ripple.style.left = (rect.width / 2) + 'px';
            ripple.style.top = (rect.height / 2) + 'px';
            ctaBtn.appendChild(ripple);
            ripple.addEventListener('animationend', function () {
              ripple.remove();
            });
          }
        });
      });

      document.addEventListener('keydown', function (event) {
        if (!event.ctrlKey || !event.altKey) return;
        if ((event.key || '').toLowerCase() !== 'a') return;

        var target = event.target;
        if (target) {
          var tag = (target.tagName || '').toLowerCase();
          if (tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable) {
            return;
          }
        }

        event.preventDefault();
        window.location.href = '/admin/login';
      });

      var targetStart = new Date('2026-02-28T16:00:00+07:00').getTime();
      var targetEnd = new Date('2026-02-28T18:00:00+07:00').getTime();

      var daysEl = document.querySelector('[data-unit="days"]');
      var hoursEl = document.querySelector('[data-unit="hours"]');
      var minutesEl = document.querySelector('[data-unit="minutes"]');
      var secondsEl = document.querySelector('[data-unit="seconds"]');
      var labelEl = document.getElementById('countdownLabel');
      var countdownEl = document.getElementById('eventCountdown');
      var statusEl = document.getElementById('countdownStatus');
      var countdownWrapEl = document.querySelector('.countdown-wrap');
      var phase = '';

      if (!daysEl || !hoursEl || !minutesEl || !secondsEl || !statusEl || !labelEl || !countdownEl) return;

      function pad(value) {
        return String(value).padStart(2, '0');
      }

      function setValueAnimated(el, value) {
        var nextValue = pad(value);
        if (el.textContent === nextValue) return;

        el.textContent = nextValue;
        if (reduceMotion) return;

        el.classList.remove('flip');
        void el.offsetWidth;
        el.classList.add('flip');
      }

      function setCountdown(ms) {
        var totalSeconds = Math.max(0, Math.floor(ms / 1000));
        var days = Math.floor(totalSeconds / 86400);
        var hours = Math.floor((totalSeconds % 86400) / 3600);
        var minutes = Math.floor((totalSeconds % 3600) / 60);
        var seconds = totalSeconds % 60;

        setValueAnimated(daysEl, days);
        setValueAnimated(hoursEl, hours);
        setValueAnimated(minutesEl, minutes);
        setValueAnimated(secondsEl, seconds);
      }

      function tick() {
        var now = Date.now();

        if (now >= targetStart && now < targetEnd) {
          if (phase !== 'running' && countdownWrapEl && !reduceMotion) {
            countdownWrapEl.classList.remove('start-burst');
            void countdownWrapEl.offsetWidth;
            countdownWrapEl.classList.add('start-burst');
          }
          phase = 'running';
          setCountdown(0);
          labelEl.innerHTML = '<i class="bi bi-lightning-charge-fill"></i> Event Start';
          countdownEl.classList.add('is-hidden');
          statusEl.classList.add('live');
          statusEl.textContent = 'Let\'s Play';
          return;
        }

        if (now >= targetEnd) {
          phase = 'ended';
          setCountdown(0);
          labelEl.innerHTML = '<i class="bi bi-check2-circle"></i> Event Finished';
          countdownEl.classList.add('is-hidden');
          statusEl.classList.remove('live');
          statusEl.textContent = 'Event has ended. See you at the next Temu Padel.';
          return;
        }

        phase = 'before';
        labelEl.innerHTML = '<i class="bi bi-hourglass-split"></i> Countdown To Event Start';
        countdownEl.classList.remove('is-hidden');
        statusEl.classList.remove('live');
        setCountdown(targetStart - now);
        statusEl.textContent = '';
      }

      tick();
      setInterval(tick, 1000);
    })();
  </script>
</body>
</html>

