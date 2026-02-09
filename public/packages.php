<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
ensure_session();

if (empty($_SESSION['user_id'])) {
    redirect('/register.php');
}

$db = get_db();
$packages = $db->query('SELECT * FROM packages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
</head>
<body>
  <section class="section">
    <div class="container center">
      <h2 style="font-family:'Playfair Display', serif; font-style: italic;">Select Your Package</h2>

      <?php if ($errors): ?>
        <div class="alert">
          <?php foreach ($errors as $e): ?>
            <div><?= h($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="">
        <div class="packages">
          <?php foreach ($packages as $p): ?>
            <div class="package-card">
              <h3><?= h($p['name']) ?></h3>
              <div>What you get :</div>
              <ul>
                <?php foreach (explode("\n", $p['description']) as $line): ?>
                  <li><?= h($line) ?></li>
                <?php endforeach; ?>
              </ul>
              <div class="center" style="font-size:22px;font-weight:700;"><?= h(rupiah((int)$p['price'])) ?>,-</div>
              <div class="qty">
                <button type="button" data-action="minus">-</button>
                <input type="number" name="qty_<?= (int)$p['id'] ?>" min="0" value="0">
                <button type="button" data-action="plus">+</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="center" style="margin-top:24px;">
          <button class="btn" type="submit">Next</button>
        </div>
      </form>
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
