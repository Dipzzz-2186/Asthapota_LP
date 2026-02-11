<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/auth.php';
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
  <title>Packages - Temu Padel 2026</title>
  <style>
    :root {
      --blue: #1658ad;
      --white: #f6f7fb;
      --soft: rgba(10, 30, 66, 0.55);
      --line: rgba(255, 255, 255, 0.34);
    }

    * { box-sizing: border-box; }

    html, body {
      margin: 0;
      min-height: 100%;
      scroll-behavior: smooth;
    }

    body {
      color: var(--white);
      font-family: "Segoe UI", Tahoma, sans-serif;
      background: url('/assets/img/wallpaper.avif') center/cover no-repeat fixed;
      overflow-x: hidden;
    }

    .landing {
      min-height: 100vh;
      width: min(1120px, 92vw);
      margin: 0 auto;
      padding: 42px 0 56px;
      display: flex;
      align-items: center;
    }

    #packagePanel {
      width: 100%;
      background: rgba(255, 255, 255, 0.18);
      border: 1px solid rgba(255, 255, 255, 0.48);
      border-radius: 20px;
      backdrop-filter: blur(5px);
      padding: clamp(20px, 2.6vw, 34px);
      box-shadow: 0 14px 34px rgba(0, 0, 0, 0.32);
    }

    h1 {
      margin: 0 0 16px;
      font-size: clamp(26px, 3vw, 38px);
    }

    .alert {
      margin: 0 0 14px;
      background: rgba(255, 140, 140, 0.24);
      border: 1px solid rgba(255, 210, 210, 0.72);
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 14px;
    }

    .package-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
      gap: 14px;
    }

    .package-card {
      background: rgba(255, 255, 255, 0.24);
      border: 1px solid rgba(255, 255, 255, 0.58);
      border-radius: 14px;
      padding: 16px;
      display: grid;
      gap: 10px;
      backdrop-filter: blur(2px);
    }

    .package-card h3 {
      margin: 0;
      font-size: 22px;
    }

    .package-card ul {
      margin: 0;
      padding-left: 18px;
      display: grid;
      gap: 5px;
      font-size: 14px;
    }

    .price {
      font-size: 24px;
      font-weight: 800;
    }

    .qty {
      display: grid;
      grid-template-columns: 40px 1fr 40px;
      gap: 8px;
      align-items: center;
    }

    .qty input,
    .qty button {
      height: 40px;
      border-radius: 8px;
      border: 1px solid rgba(255, 255, 255, 0.38);
      font-size: 16px;
    }

    .qty input {
      text-align: center;
      background: rgba(255, 255, 255, 0.93);
      color: #1f2d40;
      width: 100%;
      appearance: textfield;
      -moz-appearance: textfield;
    }

    .qty input::-webkit-outer-spin-button,
    .qty input::-webkit-inner-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }

    .qty button {
      background: rgba(11, 45, 97, 0.82);
      color: #fff;
      cursor: pointer;
    }

    .actions {
      margin-top: 18px;
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .btn {
      text-decoration: none;
      border: 0;
      border-radius: 999px;
      padding: 11px 18px;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .btn.primary {
      background: #fff;
      color: var(--blue);
    }

    .btn.ghost {
      background: rgba(255, 255, 255, 0.16);
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.45);
    }

    @media (max-width: 640px) {
      .landing { width: 94vw; }
      #packagePanel { padding: 18px; }
      .actions .btn { width: 100%; }
    }
  </style>
</head>
<body>
  <main class="landing">
    <section id="packagePanel">
      <h1>Select Your Package</h1>

      <?php if (!$can_order): ?>
        <div class="alert">Please register first before selecting packages.</div>
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
            <article class="package-card">
              <h3><?= h($p['name']) ?></h3>
              <ul>
                <?php
                $features = array_filter(explode("\n", $p['description']));
                foreach ($features as $line):
                ?>
                  <li><?= h(trim($line)) ?></li>
                <?php endforeach; ?>
              </ul>
              <div class="price"><?= h(rupiah((int)$p['price'])) ?>,-</div>
              <div class="qty">
                <button type="button" data-action="minus" <?= $can_order ? '' : 'disabled' ?>>-</button>
                <input type="number" name="qty_<?= (int)$p['id'] ?>" min="0" value="0" <?= $can_order ? '' : 'disabled' ?>>
                <button type="button" data-action="plus" <?= $can_order ? '' : 'disabled' ?>>+</button>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <div class="actions">
          <?php if ($can_order): ?>
            <button class="btn primary" type="submit">Continue to Order</button>
          <?php endif; ?>
          <?php if (!$can_order): ?>
            <a class="btn primary" href="/register">Register Now</a>
          <?php endif; ?>
          <a class="btn ghost" href="/">Back to Home</a>
        </div>
      </form>
    </section>
  </main>

  <script>
    (function () {
      document.querySelectorAll('.qty').forEach(function(group){
        var input = group.querySelector('input');
        group.addEventListener('click', function(e){
          if (!input || input.disabled) return;
          if (e.target.dataset.action === 'plus') {
            input.value = parseInt(input.value || '0', 10) + 1;
          }
          if (e.target.dataset.action === 'minus') {
            var v = Math.max(0, parseInt(input.value || '0', 10) - 1);
            input.value = v;
          }
        });
      });
    })();
  </script>
</body>
</html>
