<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
ensure_session();

$db = get_db();
$packages = $db->query('SELECT * FROM packages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Packages - Temu Padel</title>
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
            <small style="color:var(--muted);">Browse packages</small>
          </div>
        </div>
        <div class="topbar-actions">
          <a class="btn ghost" href="/"><i class="bi bi-arrow-left"></i> Back</a>
          <a class="btn primary" href="/register.php">Register <i class="bi bi-person-plus"></i></a>
        </div>
      </div>
    </div>
  </header>

  <section class="section">
    <div class="container">
      <div class="section-title">Available Packages</div>

      <div class="package-grid">
        <?php foreach ($packages as $p): ?>
          <div class="package-card fade-up">
            <div class="pill"><i class="bi bi-bag-heart"></i> Package</div>
            <h3><?= h($p['name']) ?></h3>
            <div style="color:var(--muted);">What you get:</div>
            <ul>
              <?php foreach (explode("\n", $p['description']) as $line): ?>
                <li><?= h($line) ?></li>
              <?php endforeach; ?>
            </ul>
            <div style="font-size:22px;font-weight:700;"><?= h(rupiah((int)$p['price'])) ?>,-</div>
          </div>
        <?php endforeach; ?>
      </div>

      <div style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap;">
        <a class="btn primary" href="/register.php">Register to Order <i class="bi bi-arrow-right"></i></a>
        <a class="btn ghost" href="/">Back to Home</a>
      </div>
    </div>
  </section>
</body>
</html>
