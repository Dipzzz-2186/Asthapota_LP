<?php
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/auth.php';
require_admin();

$db = get_db();
$packages = $db->query("SELECT id, name FROM packages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$selectedPackage = isset($_GET['package']) ? (int)$_GET['package'] : 0;

$sql = "SELECT o.id, u.full_name, u.phone, u.email, u.instagram, o.total, o.status, o.payment_proof, o.created_at,
  (SELECT GROUP_CONCAT(CONCAT(p.name, ' x', oi.qty) SEPARATOR ', ') FROM order_items oi JOIN packages p ON p.id = oi.package_id WHERE oi.order_id = o.id) as items
  FROM orders o JOIN users u ON u.id = o.user_id
  WHERE 1=1";
$params = [];
if ($selectedPackage > 0) {
    $sql .= " AND EXISTS (
        SELECT 1 FROM order_items oi
        JOIN packages p ON p.id = oi.package_id
        WHERE oi.order_id = o.id AND p.id = ?
    )";
    $params[] = $selectedPackage;
}
$sql .= " ORDER BY o.created_at ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalOrders = count($orders);
$paidOrders = 0;
$pendingOrders = 0;
$totalRevenue = 0;
foreach ($orders as $o) {
    $totalRevenue += (int)$o['total'];
    if ($o['status'] === 'paid') {
        $paidOrders++;
    } else {
        $pendingOrders++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Dashboard</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    .admin-shell {
      padding: 24px 0 40px;
    }
    .admin-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }
    .admin-title {
      font-family: 'Fraunces', serif;
      font-size: clamp(26px, 3vw, 36px);
      margin: 0;
    }
    .admin-sub {
      color: var(--muted);
      margin: 6px 0 0;
      font-size: 14px;
    }
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 16px;
      margin: 20px 0 24px;
    }
    .stat-card {
      background: var(--surface);
      border-radius: var(--radius-md);
      padding: 18px;
      border: 1px solid var(--stroke);
      box-shadow: var(--shadow);
    }
    .stat-label {
      font-size: 12px;
      letter-spacing: 0.4px;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 8px;
    }
    .stat-value {
      font-size: 24px;
      font-weight: 700;
    }
    .table-wrap {
      background: var(--surface);
      border-radius: var(--radius-lg);
      padding: 10px;
      border: 1px solid var(--stroke);
      box-shadow: var(--shadow);
      overflow: auto;
    }
    table.admin-table {
      width: 100%;
      border-collapse: collapse;
      min-width: 860px;
    }
    table.admin-table th,
    table.admin-table td {
      padding: 12px 14px;
      text-align: left;
      border-bottom: 1px solid var(--stroke);
      vertical-align: top;
      font-size: 14px;
    }
    table.admin-table th {
      font-size: 12px;
      letter-spacing: 0.4px;
      text-transform: uppercase;
      color: var(--muted);
      background: var(--surface-2);
      position: sticky;
      top: 0;
      z-index: 1;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 600;
      background: var(--surface-2);
      color: var(--text);
      border: 1px solid var(--stroke);
    }
    .badge.paid {
      background: rgba(46, 184, 92, 0.12);
      color: #1f7a3f;
      border-color: rgba(46, 184, 92, 0.3);
    }
    .badge.pending {
      background: rgba(255, 180, 0, 0.12);
      color: #8a5a00;
      border-color: rgba(255, 180, 0, 0.3);
    }
    .muted {
      color: var(--muted);
      font-size: 13px;
    }
    .proof-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
      color: var(--primary);
    }
    @media (max-width: 1100px) {
      .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
    @media (max-width: 640px) {
      .admin-header {
        flex-direction: column;
        align-items: flex-start;
      }
      .stat-grid {
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
            <small style="color:var(--muted);">Admin Dashboard</small>
          </div>
        </div>
        <div class="topbar-actions">
          <a class="btn ghost" href="/"><i class="bi bi-house"></i> Home</a>
          <a class="btn primary" href="/admin/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
      </div>
    </div>
  </header>

  <main class="admin-shell">
    <div class="container">
      <div class="admin-header">
        <div>
          <h1 class="admin-title">Dashboard</h1>
          <p class="admin-sub">Ringkasan pesanan dan status pembayaran.</p>
        </div>
      </div>

      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-label">Total Orders</div>
          <div class="stat-value"><?= (int)$totalOrders ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Paid Orders</div>
          <div class="stat-value"><?= (int)$paidOrders ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Pending Orders</div>
          <div class="stat-value"><?= (int)$pendingOrders ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Total Revenue</div>
          <div class="stat-value"><?= h(rupiah($totalRevenue)) ?></div>
        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <form method="get" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
          <div style="font-weight:600;">Filter Package</div>
          <select name="package">
            <option value="0">All Packages</option>
            <?php foreach ($packages as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= $selectedPackage === (int)$p['id'] ? 'selected' : '' ?>>
                <?= h($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn primary" type="submit">Apply</button>
          <?php if ($selectedPackage > 0): ?>
            <a class="btn ghost" href="/admin/dashboard.php">Reset</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="table-wrap">
        <table class="admin-table">
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
              <tr><td colspan="8" class="muted">No orders yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($orders as $o): ?>
              <tr>
                <td>#<?= (int)$o['id'] ?></td>
                <td><?= h($o['full_name']) ?></td>
                <td>
                  <?= h($o['phone']) ?><br>
                  <?= h($o['email']) ?><br>
                  <?= h($o['instagram']) ?>
                </td>
                <td><?= h($o['items'] ?? '-') ?></td>
                <td><?= h(rupiah((int)$o['total'])) ?></td>
                <td>
                  <?php if ($o['status'] === 'paid'): ?>
                    <span class="badge paid">Paid</span>
                  <?php else: ?>
                    <span class="badge pending"><?= h($o['status']) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($o['payment_proof']): ?>
                    <a class="proof-link" href="/uploads/<?= h($o['payment_proof']) ?>" target="_blank" rel="noopener">
                      <i class="bi bi-file-earmark-image"></i> View
                    </a>
                  <?php else: ?>
                    <span class="muted">-</span>
                  <?php endif; ?>
                </td>
                <td><?= h(date('d M Y H:i', strtotime($o['created_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</body>
</html>
