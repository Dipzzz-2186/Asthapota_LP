<?php
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/auth.php';
require_admin();

$db = get_db();
$orders = $db->query("SELECT o.id, u.full_name, u.phone, u.email, u.instagram, o.total, o.status, o.payment_proof, o.created_at,
  (SELECT GROUP_CONCAT(p.name || ' x' || oi.qty, ', ') FROM order_items oi JOIN packages p ON p.id = oi.package_id WHERE oi.order_id = o.id) as items
  FROM orders o JOIN users u ON u.id = o.user_id ORDER BY o.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Dashboard</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
  <div class="admin-wrap">
    <div class="container" style="display:flex;justify-content:space-between;align-items:center;">
      <h2 style="font-family:'Playfair Display', serif;">Dashboard</h2>
      <a class="btn" href="/admin/logout.php">Logout</a>
    </div>

    <div class="container" style="margin-top:16px;">
      <table class="table">
        <thead>
          <tr>
            <th>Order ID</th>
            <th>User</th>
            <th>Contact</th>
            <th>Packages</th>
            <th>Total</th>
            <th>Status</th>
            <th>Proof</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$orders): ?>
            <tr><td colspan="8">No orders yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($orders as $o): ?>
            <tr>
              <td>#<?= (int)$o['id'] ?></td>
              <td><?= h($o['full_name']) ?></td>
              <td><?= h($o['phone']) ?><br><?= h($o['email']) ?><br><?= h($o['instagram']) ?></td>
              <td><?= h($o['items'] ?? '-') ?></td>
              <td><?= h(rupiah((int)$o['total'])) ?></td>
              <td><?= h($o['status']) ?></td>
              <td><?= $o['payment_proof'] ? h($o['payment_proof']) : '-' ?></td>
              <td><?= h($o['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
