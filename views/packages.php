<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/layout/app.php';
ensure_session();

$isAdmin = is_admin_logged_in();
$can_order = !empty($_SESSION['user_id']);
if (!$can_order) {
    unset($_SESSION['order_id']);
}

$db = get_db();
$packages = $db->query('SELECT * FROM packages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_order) {
        redirect('/register?notice=register_required');
    }

    $qtys = [];
    $total = 0;

    foreach ($packages as $p) {
        $key = 'qty_' . $p['id'];
        $qty = max(0, (int)($_POST[$key] ?? 0));
        if ($qty > 0) {
            $qtys[$p['id']] = $qty;
            $total += $qty * (int)$p['price'];
        }
    }

    if (!$qtys) {
        $errors[] = 'Please select at least one package.';
    } else {
        $stmt = $db->prepare('INSERT INTO orders (user_id, total, status, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$_SESSION['user_id'], $total, 'pending', date('c')]);
        $order_id = (int)$db->lastInsertId();

        $itemStmt = $db->prepare('INSERT INTO order_items (order_id, package_id, qty, price) VALUES (?, ?, ?, ?)');
        foreach ($qtys as $pid => $qty) {
            $pkg = array_values(array_filter($packages, fn($p) => (int)$p['id'] === (int)$pid))[0];
            $itemStmt->execute([$order_id, $pid, $qty, $pkg['price']]);
        }

        $_SESSION['order_id'] = $order_id;
        redirect('/order');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Select Package - Temu Padel</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="page">
<?php render_navbar(['isAdmin' => $isAdmin]); ?>

  <section class="hero-section">
    <div class="container">
      <div class="hero-badge">
        <i class="bi bi-tag"></i>
        Select Packages
      </div>
      <h1 class="hero-title">Choose Your Package</h1>
      <p class="hero-subtitle">
        Pick the packages you want and continue to order. Register first to unlock ordering.
      </p>
    </div>
  </section>

  <section class="section">
    <div class="container">
      <div class="info-cards">
        <div class="info-card">
          <div class="info-icon">
            <i class="bi bi-calendar-event"></i>
          </div>
          <div class="info-content">
            <h4>Event Date</h4>
            <p>February 28th, 2026</p>
          </div>
        </div>
        <div class="info-card">
          <div class="info-icon">
            <i class="bi bi-geo-alt"></i>
          </div>
          <div class="info-content">
            <h4>Location</h4>
            <p>MY PADEL, Jelupang Utama</p>
          </div>
        </div>
        <div class="info-card">
          <div class="info-icon">
            <i class="bi bi-clock"></i>
          </div>
          <div class="info-content">
            <h4>Time</h4>
            <p>4 PM - 6 PM</p>
          </div>
        </div>
      </div>

      <?php if (!$can_order): ?>
        <div class="alert">Please register first to continue to package selection.</div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="alert">
          <?php foreach ($errors as $e): ?>
            <div><?= h($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="">
        <div class="package-grid">
          <?php foreach ($packages as $p): ?>
            <div class="package-card fade-up">
              <div class="pill">
                <i class="bi bi-bag-heart"></i>
                Package
              </div>
              <h3><?= h($p['name']) ?></h3>

              <div class="package-features-label">What's Included</div>
              <ul>
                <?php
                $features = array_filter(explode("\n", $p['description']));
                foreach ($features as $line):
                ?>
                  <li><?= h(trim($line)) ?></li>
                <?php endforeach; ?>
              </ul>

              <div class="package-price">
                <?= h(rupiah((int)$p['price'])) ?>
                <small>,-</small>
              </div>

              <div class="qty">
                <button type="button" data-action="minus" <?= $can_order ? '' : 'disabled' ?>>-</button>
                <input type="number" name="qty_<?= (int)$p['id'] ?>" min="0" value="0" <?= $can_order ? '' : 'disabled' ?>>
                <button type="button" data-action="plus" <?= $can_order ? '' : 'disabled' ?>>+</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap;">
          <?php if ($can_order): ?>
            <button class="btn primary" type="submit">Continue to Order <i class="bi bi-arrow-right"></i></button>
          <?php else: ?>
            <a class="btn primary" href="/register">Register to Order <i class="bi bi-person-plus"></i></a>
          <?php endif; ?>
          <a class="btn ghost" href="/">Back to Home</a>
        </div>
      </form>
    </div>
  </section>

  <section class="cta-section">
    <div class="container">
      <h2 class="cta-title">Ready to Join Temu Padel 2026?</h2>
      <p class="cta-text">
        Register now to secure your spot and enjoy the best padel experience with fellow enthusiasts.
      </p>
    </div>
  </section>

  <script>
    document.querySelectorAll('.qty').forEach(function(group){
      var input = group.querySelector('input');
      group.addEventListener('click', function(e){
        if (e.target.dataset.action === 'plus') {
          input.value = parseInt(input.value || '0', 10) + 1;
        }
        if (e.target.dataset.action === 'minus') {
          var v = Math.max(0, parseInt(input.value || '0', 10) - 1);
          input.value = v;
        }
      });
    });
  </script>
</body>
</html>

