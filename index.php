<?php
require_once __DIR__ . '/app/helpers.php';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Let the built-in server handle existing static files directly.
if (php_sapi_name() === 'cli-server') {
    $file = __DIR__ . $uri;
    if ($uri !== '/' && is_file($file)) {
        return false;
    }
}

$path = rtrim($uri, '/');
if ($path === '') {
    $path = '/';
}

$routes = [
    '/' => __DIR__ . '/views/home.php',
    '/index.php' => __DIR__ . '/views/home.php',
    '/events' => __DIR__ . '/views/event.php',
    '/home' => __DIR__ . '/views/event.php',
    '/register' => __DIR__ . '/views/register.php',
    '/packages' => __DIR__ . '/views/packages.php',
    '/order' => __DIR__ . '/views/order.php',
    '/order.php' => __DIR__ . '/views/order.php',
    '/download/qris' => __DIR__ . '/views/download_qris.php',
    '/thankyou' => __DIR__ . '/views/thankyou.php',
    '/logout' => __DIR__ . '/views/logout.php',
    admin_login_path() => __DIR__ . '/views/admin/login.php',
    '/admin/dashboard' => __DIR__ . '/views/admin/dashboard.php',
    '/admin/scan' => __DIR__ . '/views/admin/scan.php',
    '/admin/logout' => __DIR__ . '/views/admin/logout.php',
];

if (isset($routes[$path])) {
    require $routes[$path];
    return;
}

http_response_code(404);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>404 - Not Found</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Anton&family=Manrope:wght@500;700;800&family=Playfair+Display:ital,wght@1,500&display=swap');

    :root {
      --blue: #1658ad;
      --white: #f6f7fb;
      --font-body: "Manrope", "Segoe UI", Tahoma, sans-serif;
      --font-display: "Anton", "Arial Narrow", Impact, sans-serif;
      --font-accent: "Playfair Display", Georgia, serif;
    }

    * { box-sizing: border-box; }

    html, body {
      margin: 0;
      min-height: 100%;
    }

    body {
      min-height: 100svh;
      display: grid;
      place-items: center;
      text-align: center;
      padding: 24px;
      color: var(--white);
      font-family: var(--font-body);
      background: url('/assets/img/WallpaperHome.jpeg') center center / cover no-repeat;
      position: relative;
      overflow: hidden;
    }

    body::before {
      content: "";
      position: fixed;
      inset: 0;
      background:
        radial-gradient(58% 54% at 50% 42%, rgba(255, 255, 255, 0.22), rgba(255, 255, 255, 0) 72%),
        linear-gradient(180deg, rgba(8, 32, 68, 0.58), rgba(8, 32, 68, 0.42));
      pointer-events: none;
    }

    .nf-wrap {
      width: min(760px, 92vw);
      position: relative;
      z-index: 1;
      border-radius: 24px;
      padding: clamp(28px, 5vw, 54px) clamp(20px, 4vw, 44px);
      background: rgba(8, 29, 61, 0.38);
      border: 1px solid rgba(255, 255, 255, 0.42);
      box-shadow: 0 14px 42px rgba(0, 0, 0, 0.3);
      backdrop-filter: blur(4px);
    }

    .welcome {
      margin: 0 0 8px;
      font-family: var(--font-accent);
      font-size: clamp(28px, 4vw, 44px);
      line-height: 1;
      font-style: italic;
      text-shadow: 0 4px 14px rgba(0, 0, 0, 0.28);
    }

    .title {
      margin: 0;
      font-family: var(--font-display);
      font-size: clamp(48px, 12vw, 124px);
      line-height: 0.92;
      letter-spacing: 2px;
      text-transform: uppercase;
      text-shadow: 0 10px 20px rgba(0, 0, 0, 0.28);
    }

    .subtitle {
      margin: 12px 0 0;
      font-size: clamp(14px, 2vw, 20px);
      font-weight: 800;
      letter-spacing: 1px;
      text-transform: uppercase;
    }

    .cta {
      margin-top: 24px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      border: 2px solid rgba(255, 255, 255, 0.72);
      padding: 12px 24px;
      color: #fff;
      background: rgba(11, 45, 97, 0.5);
      text-decoration: none;
      font-weight: 800;
      letter-spacing: 1px;
      text-transform: uppercase;
      transition: transform 0.16s ease, background 0.2s ease, border-color 0.2s ease;
    }

    .cta:hover {
      transform: translateY(-2px);
      background: rgba(11, 45, 97, 0.62);
      border-color: rgba(255, 255, 255, 0.94);
    }
  </style>
</head>
<body>
  <main class="nf-wrap">
    <p class="welcome">Oops</p>
    <h1 class="title">404</h1>
    <p class="subtitle">Page Not Found</p>
    <p>The page you are looking for does not exist.</p>
    <a class="cta" href="/">Back to Home</a>
  </main>
</body>
</html>


