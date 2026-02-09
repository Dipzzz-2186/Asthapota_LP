<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
ensure_session();

$can_order = !empty($_SESSION['user_id']);
if (!$can_order) {
    unset($_SESSION['order_id']);
}

$db = get_db();
$packages = $db->query('SELECT * FROM packages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_order) {
        redirect('/register.php?notice=register_required');
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
        redirect('/order.php');
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
  <style>
    :root {
      --primary-blue: #3b82f6;
      --primary-blue-hover: #2563eb;
      --text-dark: #1e293b;
      --text-muted: #64748b;
      --bg-light: #f1f5f9;
      --bg-white: #ffffff;
      --border-light: #e2e8f0;
      --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.08);
      --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.07);
      --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.1);
      --radius-lg: 16px;
      --radius-md: 12px;
    }

    body.page {
      background: var(--bg-light);
      min-height: 100vh;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    }

    .page-header {
      background: var(--bg-white);
      box-shadow: var(--shadow-sm);
      position: sticky;
      top: 0;
      z-index: 100;
      border-bottom: 1px solid var(--border-light);
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 16px 0;
      gap: 24px;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .brand-badge {
      width: 44px;
      height: 44px;
      background: var(--primary-blue);
      color: white;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 16px;
    }

    .brand > div > div:first-child {
      font-size: 18px;
      font-weight: 700;
      color: var(--text-dark);
    }

    .brand small {
      font-size: 13px;
      color: var(--text-muted);
      display: block;
      margin-top: 2px;
    }

    .topbar-actions {
      display: flex;
      gap: 12px;
      align-items: center;
    }

    .hero-section {
      background: var(--bg-white);
      padding: 48px 0;
      border-bottom: 1px solid var(--border-light);
    }

    .hero-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(59, 130, 246, 0.1);
      color: var(--primary-blue);
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 16px;
    }

    .hero-title {
      font-size: 42px;
      font-weight: 800;
      color: var(--text-dark);
      margin-bottom: 16px;
      line-height: 1.2;
    }

    .hero-subtitle {
      font-size: 18px;
      color: var(--text-muted);
      max-width: 700px;
      line-height: 1.6;
      margin-bottom: 32px;
    }

    .section {
      padding: 48px 0;
    }

    .package-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
      gap: 24px;
      margin-top: 32px;
    }

    .package-card {
      background: var(--bg-white);
      border-radius: var(--radius-lg);
      padding: 32px;
      box-shadow: var(--shadow-md);
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      border: 1px solid var(--border-light);
      position: relative;
    }

    .package-card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-lg);
      border-color: var(--primary-blue);
    }

    .pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(59, 130, 246, 0.1);
      color: var(--primary-blue);
      padding: 6px 12px;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 600;
      margin-bottom: 16px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .package-card h3 {
      font-size: 28px;
      font-weight: 800;
      color: var(--text-dark);
      margin: 0 0 16px 0;
      line-height: 1.3;
    }

    .package-features-label {
      color: var(--text-muted);
      font-size: 13px;
      font-weight: 600;
      margin: 0 0 12px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .package-card ul {
      list-style: none;
      padding: 0;
      margin: 0 0 24px;
    }

    .package-card ul li {
      padding: 10px 0;
      color: var(--text-muted);
      font-size: 15px;
      line-height: 1.6;
      display: flex;
      align-items: flex-start;
      gap: 10px;
      border-bottom: 1px solid var(--border-light);
    }

    .package-card ul li:last-child {
      border-bottom: none;
    }

    .package-card ul li::before {
      content: 'v';
      color: var(--primary-blue);
      font-weight: 700;
      font-size: 14px;
      flex-shrink: 0;
      width: 20px;
      height: 20px;
      background: rgba(59, 130, 246, 0.1);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .package-price {
      font-size: 32px;
      font-weight: 800;
      color: var(--text-dark);
      padding-top: 20px;
      border-top: 2px solid var(--border-light);
      display: flex;
      align-items: baseline;
      gap: 4px;
      margin-bottom: 16px;
    }

    .package-price small {
      font-size: 14px;
      font-weight: 500;
      color: var(--text-muted);
    }

    .qty {
      display: grid;
      grid-template-columns: 40px 1fr 40px;
      gap: 8px;
      align-items: center;
      margin-top: 8px;
    }

    .qty button {
      border-radius: 999px;
      border: 1px solid var(--border-light);
      background: var(--bg-light);
      color: var(--primary-blue);
      height: 40px;
      font-size: 18px;
      cursor: pointer;
    }

    .qty button:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .qty input {
      text-align: center;
      border-radius: 999px;
      border: 1px solid var(--border-light);
      padding: 10px;
      font-size: 15px;
      background: #fff;
    }

    .qty input:disabled {
      background: #f8fafc;
    }

    .info-cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 20px;
      margin-bottom: 48px;
    }

    .info-card {
      background: var(--bg-white);
      padding: 24px;
      border-radius: var(--radius-md);
      box-shadow: var(--shadow-sm);
      border: 1px solid var(--border-light);
      display: flex;
      align-items: flex-start;
      gap: 16px;
    }

    .info-icon {
      width: 48px;
      height: 48px;
      background: rgba(59, 130, 246, 0.1);
      color: var(--primary-blue);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      flex-shrink: 0;
    }

    .info-content h4 {
      font-size: 16px;
      font-weight: 700;
      color: var(--text-dark);
      margin: 0 0 6px 0;
    }

    .info-content p {
      font-size: 14px;
      color: var(--text-muted);
      margin: 0;
      line-height: 1.5;
    }

    .cta-section {
      background: var(--bg-white);
      padding: 56px 0;
      text-align: center;
      border-top: 1px solid var(--border-light);
      margin-top: 48px;
    }

    .cta-title {
      font-size: 32px;
      font-weight: 800;
      color: var(--text-dark);
      margin-bottom: 12px;
    }

    .cta-text {
      font-size: 17px;
      color: var(--text-muted);
      margin-bottom: 32px;
      max-width: 600px;
      margin-left: auto;
      margin-right: auto;
    }

    .cta-buttons {
      display: flex;
      gap: 12px;
      justify-content: center;
      flex-wrap: wrap;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 24px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 15px;
      transition: all 0.2s ease;
      cursor: pointer;
      text-decoration: none;
      border: none;
    }

    .btn.primary {
      background: var(--primary-blue);
      color: white;
    }

    .btn.primary:hover {
      background: var(--primary-blue-hover);
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }

    .btn.ghost {
      background: transparent;
      color: var(--text-dark);
      border: 1px solid var(--border-light);
    }

    .btn.ghost:hover {
      background: var(--bg-light);
      border-color: var(--primary-blue);
      color: var(--primary-blue);
    }

    .alert {
      background: #fff1f2;
      border: 1px solid #fecdd3;
      color: #9f1239;
      padding: 12px 14px;
      border-radius: 12px;
      margin-bottom: 16px;
    }

    @keyframes fadeUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .fade-up {
      animation: fadeUp 0.5s ease forwards;
    }

    .package-card:nth-child(1) { animation-delay: 0.05s; opacity: 0; }
    .package-card:nth-child(2) { animation-delay: 0.1s; opacity: 0; }
    .package-card:nth-child(3) { animation-delay: 0.15s; opacity: 0; }
    .package-card:nth-child(4) { animation-delay: 0.2s; opacity: 0; }

    @media (max-width: 768px) {
      .hero-title {
        font-size: 32px;
      }

      .hero-subtitle {
        font-size: 16px;
      }

      .package-grid {
        grid-template-columns: 1fr;
      }

      .topbar {
        flex-direction: column;
        align-items: stretch;
        gap: 16px;
      }

      .topbar-actions {
        width: 100%;
        justify-content: stretch;
      }

      .topbar-actions .btn {
        flex: 1;
        justify-content: center;
      }

      .cta-title {
        font-size: 24px;
      }

      .cta-buttons {
        flex-direction: column;
        width: 100%;
      }

      .cta-buttons .btn {
        width: 100%;
        justify-content: center;
      }

      .info-cards {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body class="page">
  <header class="page-header">
    <div class="container">
      <div class="topbar">
        <div class="brand">
          <div class="brand-badge">TP</div>
          <div>
            <div>Temu Padel</div>
            <small>Choose your package</small>
          </div>
        </div>
        <div class="topbar-actions">
          <a class="btn ghost" href="/"><i class="bi bi-arrow-left"></i> Back</a>
          <?php if ($can_order): ?>
            <a class="btn ghost" href="/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
          <?php else: ?>
            <a class="btn primary" href="/register.php">Register Now <i class="bi bi-arrow-right"></i></a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>

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
            <a class="btn primary" href="/register.php">Register to Order <i class="bi bi-person-plus"></i></a>
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
      <div class="cta-buttons">
        <a class="btn primary" href="/register.php">
          <i class="bi bi-person-plus"></i>
          Register Now
        </a>
        <a class="btn ghost" href="/">
          <i class="bi bi-house"></i>
          Back to Home
        </a>
      </div>
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
