<?php
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
    '/register' => __DIR__ . '/views/register.php',
    '/register.php' => __DIR__ . '/views/register.php',
    '/packages' => __DIR__ . '/views/packages.php',
    '/packages.php' => __DIR__ . '/views/packages.php',
    '/packages-view' => __DIR__ . '/views/packages-view.php',
    '/packages-view.php' => __DIR__ . '/views/packages-view.php',
    '/order' => __DIR__ . '/views/order.php',
    '/order.php' => __DIR__ . '/views/order.php',
    '/thankyou' => __DIR__ . '/views/thankyou.php',
    '/thankyou.php' => __DIR__ . '/views/thankyou.php',
    '/logout' => __DIR__ . '/views/logout.php',
    '/logout.php' => __DIR__ . '/views/logout.php',
    '/admin' => __DIR__ . '/views/admin/login.php',
    '/admin/login' => __DIR__ . '/views/admin/login.php',
    '/admin/login.php' => __DIR__ . '/views/admin/login.php',
    '/admin/dashboard' => __DIR__ . '/views/admin/dashboard.php',
    '/admin/dashboard.php' => __DIR__ . '/views/admin/dashboard.php',
    '/admin/logout' => __DIR__ . '/views/admin/logout.php',
    '/admin/logout.php' => __DIR__ . '/views/admin/logout.php',
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
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="page">
  <section class="section">
    <div class="container" style="text-align:center;">
      <h1>Page not found</h1>
      <p>The page you are looking for does not exist.</p>
      <a class="btn primary" href="/">Back to Home</a>
    </div>
  </section>
</body>
</html>
