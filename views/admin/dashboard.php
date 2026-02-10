<?php
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/auth.php';
require_admin();

$db = get_db();
$flash = ['success' => '', 'error' => ''];

// Accept/Reject order (admin action) (KHOLIS)
// if ($_SERVER['REQUEST_METHOD'] === 'POST') {
//     // Validate request payload
//     $orderId = (int)($_POST['order_id'] ?? 0);
//     $action = $_POST['action'] ?? '';
//     $allowed = ['accept', 'reject'];

//     if (!$orderId || !in_array($action, $allowed, true)) {
//         $flash['error'] = 'Invalid request.';
//     } else {
//         // Load order + user for email notification
//         $stmt = $db->prepare('SELECT o.id, o.status, o.payment_proof, u.email, u.full_name FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?');
//         $stmt->execute([$orderId]);
//         $orderRow = $stmt->fetch(PDO::FETCH_ASSOC);

//         if (!$orderRow) {
//             $flash['error'] = 'Order not found.';
//         } elseif (empty($orderRow['payment_proof'])) {
//             // Require payment proof before any decision
//             $flash['error'] = 'Cannot update. Payment proof is required.';
//         } elseif ($orderRow['status'] !== 'paid') {
//             // Only paid orders can be accepted/rejected
//             $flash['error'] = 'Only paid orders can be accepted or rejected.';
//         } else {
//             // Update status + notify user
//             $newStatus = $action === 'accept' ? 'accepted' : 'rejected';
//             $update = $db->prepare('UPDATE orders SET status = ? WHERE id = ?');
//             $update->execute([$newStatus, $orderId]);

//             $orderRow['status'] = $newStatus;
//             $sent = send_order_status_email($orderRow, $orderRow['email']);
//             $flash['success'] = $sent
//                 ? 'Order status updated and email sent.'
//                 : 'Order status updated, but email failed to send.';
//         }
//     }
// }

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
    if (in_array($o['status'], ['paid', 'accepted', 'rejected'], true)) {
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
    .badge.accepted {
      background: rgba(34, 197, 94, 0.12);
      color: #15803d;
      border-color: rgba(34, 197, 94, 0.3);
    }
    .badge.rejected {
      background: rgba(239, 68, 68, 0.12);
      color: #b91c1c;
      border-color: rgba(239, 68, 68, 0.3);
    }
    .badge.pending {
      background: rgba(255, 180, 0, 0.12);
      color: #8a5a00;
      border-color: rgba(255, 180, 0, 0.3);
    }
    .action-group {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap;
    }
    .btn.small {
      padding: 6px 10px;
      font-size: 12px;
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
      background: transparent;
      border: 0;
      padding: 0;
      cursor: pointer;
    }
    .proof-modal {
      position: fixed;
      inset: 0;
      background: rgba(11, 18, 32, 0.6);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      z-index: 1200;
    }
    .proof-modal.show {
      display: flex;
    }
    .proof-card {
      background: var(--surface);
      border-radius: var(--radius-lg);
      border: 1px solid var(--stroke);
      box-shadow: var(--shadow);
      max-width: min(92vw, 900px);
      width: 100%;
      overflow: hidden;
    }
    .proof-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 12px 16px;
      border-bottom: 1px solid var(--stroke);
      background: var(--surface-2);
    }
    .proof-actions {
      display: flex;
      gap: 8px;
      align-items: center;
    }
    .proof-btn {
      border: 1px solid var(--stroke);
      background: var(--surface);
      color: var(--text);
      border-radius: 8px;
      padding: 6px 10px;
      font-size: 12px;
      cursor: pointer;
    }
    .proof-btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }
    .proof-title {
      font-weight: 700;
      font-size: 14px;
    }
    .proof-close {
      border: 0;
      background: transparent;
      cursor: pointer;
      font-size: 18px;
      line-height: 1;
      color: var(--text);
      padding: 6px;
    }
    .proof-body {
      padding: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #0f172a;
      overflow: hidden;
    }
    .proof-body img {
      max-width: 100%;
      max-height: 70vh;
      border-radius: 10px;
      background: #fff;
      transform-origin: center center;
      transition: transform 0.15s ease;
      cursor: zoom-in;
      user-select: none;
    }
    .proof-body img.zoomed {
      cursor: grab;
    }
    .proof-body img.dragging {
      cursor: grabbing;
    }
    .confirm-text {
      padding: 14px 16px 0;
      color: var(--text);
      font-weight: 600;
    }
    .confirm-sub {
      padding: 0 16px 10px;
      color: var(--muted);
      font-size: 13px;
    }
    .confirm-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      padding: 12px 16px 16px;
      border-top: 1px solid var(--stroke);
      background: var(--surface);
    }
    .confirm-actions .btn {
      padding: 10px 16px;
      font-size: 13px;
      border-radius: 10px;
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
            <div>Asthapora</div>
            <small style="color:var(--muted);">Admin Dashboard</small>
          </div>
        </div>
        <div class="topbar-actions">
          <a class="btn ghost" href="/"><i class="bi bi-house"></i> Home</a>
          <a class="btn primary" href="/admin/logout"><i class="bi bi-box-arrow-right"></i> Logout</a>
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
            <a class="btn ghost" href="/admin/dashboard">Reset</a>
          <?php endif; ?>
        </form>
      </div>

      <?php if ($flash['error']): ?>
        <div class="alert" style="margin-bottom:12px;"><?= h($flash['error']) ?></div>
      <?php endif; ?>
      <?php if ($flash['success']): ?>
        <div class="card" style="margin-bottom:12px;padding:12px 14px;"><?= h($flash['success']) ?></div>
      <?php endif; ?>

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
              <!-- <th>Action</th> (KHOLIS)--> 
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$orders): ?>
              <tr><td colspan="9" class="muted">No orders yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($orders as $o): ?>
              <tr>
                <td>#<?= (int)$o['id'] ?></td>
                <td><?= h($o['full_name']) ?></td>
                <td>
                  <?= h($o['phone']) ?><br>
                  <?= h($o['email']) ?><br>
                  <?php
                    $ig = trim((string)($o['instagram'] ?? ''));
                    $ig = $ig !== '' ? '@' . ltrim($ig, '@') : '-';
                  ?>
                  <?= h($ig) ?>
                </td>
                <td><?= h($o['items'] ?? '-') ?></td>
                <td><?= h(rupiah((int)$o['total'])) ?></td>
                <td>
                  <?php if ($o['status'] === 'paid'): ?>
                    <span class="badge paid">Paid</span>
                  <?php elseif ($o['status'] === 'accepted'): ?>
                    <span class="badge accepted">Accepted</span>
                  <?php elseif ($o['status'] === 'rejected'): ?>
                    <span class="badge rejected">Rejected</span>
                  <?php else: ?>
                    <span class="badge pending"><?= h($o['status']) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($o['payment_proof']): ?>
                    <button class="proof-link" type="button" data-proof="/uploads/<?= h($o['payment_proof']) ?>" data-order="#<?= (int)$o['id'] ?>">
                      <i class="bi bi-file-earmark-image"></i> View
                    </button>
                  <?php else: ?>
                    <span class="muted">-</span>
                  <?php endif; ?>
                </td>
                <!-- <td> (KHOLIS)
                  <?php
                  // Buttons enabled only for paid orders with proof
                  $canAction = !empty($o['payment_proof']) && $o['status'] === 'paid';
                  ?>
                  <div class="action-group">
                    <button
                      class="btn primary small"
                      type="button"
                      data-confirm-action="accept"
                      data-order-id="<?= (int)$o['id'] ?>"
                      data-proof="<?= $o['payment_proof'] ? '/uploads/' . h($o['payment_proof']) : '' ?>"
                      <?= $canAction ? '' : 'disabled' ?>
                    >Accept</button>
                    <button
                      class="btn ghost small"
                      type="button"
                      data-confirm-action="reject"
                      data-order-id="<?= (int)$o['id'] ?>"
                      data-proof="<?= $o['payment_proof'] ? '/uploads/' . h($o['payment_proof']) : '' ?>"
                      <?= $canAction ? '' : 'disabled' ?>
                    >Reject</button>
                    <?php if (!$canAction && empty($o['payment_proof'])): ?>
                      <span class="muted">No proof</span>
                    <?php endif; ?>
                  </div>
                </td> -->
                <td><?= h(date('d M Y H:i', strtotime($o['created_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>

  <div class="proof-modal" id="proofModal" aria-hidden="true">
    <div class="proof-card" role="dialog" aria-modal="true" aria-labelledby="proofTitle">
      <div class="proof-head">
        <div class="proof-title" id="proofTitle">Payment Proof</div>
        <div class="proof-actions">
          <button class="proof-btn" type="button" id="zoomOut">-</button>
          <button class="proof-btn" type="button" id="zoomReset">Reset</button>
          <button class="proof-btn" type="button" id="zoomIn">+</button>
          <button class="proof-close" type="button" aria-label="Close">&times;</button>
        </div>
      </div>
      <div class="proof-body">
        <img id="proofImage" alt="Payment proof">
      </div>
    </div>
  </div>

  <div class="proof-modal" id="confirmModal" aria-hidden="true">
    <div class="proof-card" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
      <div class="proof-head">
        <div class="proof-title" id="confirmTitle">Confirm Action</div>
        <div class="proof-actions">
          <button class="proof-btn" type="button" id="confirmZoomOut">-</button>
          <button class="proof-btn" type="button" id="confirmZoomReset">Reset</button>
          <button class="proof-btn" type="button" id="confirmZoomIn">+</button>
          <button class="proof-close" type="button" aria-label="Close">&times;</button>
        </div>
      </div>
      <div class="confirm-text" id="confirmQuestion">Are you sure?</div>
      <div class="confirm-sub">Please review the payment proof below before confirming.</div>
      <div class="proof-body">
        <img id="confirmProofImage" alt="Payment proof">
      </div>
      <div class="confirm-actions">
        <button class="btn ghost" type="button" id="confirmCancel">Tidak</button>
        <button class="btn primary" type="button" id="confirmSubmit">Ya</button>
      </div>
    </div>
  </div>

  <form method="post" action="/admin/dashboard" id="confirmForm" style="display:none;">
    <input type="hidden" name="order_id" id="confirmOrderId" value="">
    <input type="hidden" name="action" id="confirmAction" value="">
  </form>

  <script>
    (function() {
      var modal = document.getElementById('proofModal');
      var img = document.getElementById('proofImage');
      var title = document.getElementById('proofTitle');
      var closeBtn = modal.querySelector('.proof-close');
      var zoomInBtn = document.getElementById('zoomIn');
      var zoomOutBtn = document.getElementById('zoomOut');
      var zoomResetBtn = document.getElementById('zoomReset');
      var scale = 1;
      var translateX = 0;
      var translateY = 0;
      var isDragging = false;
      var startX = 0;
      var startY = 0;
      var minScale = 1;
      var maxScale = 3;
      var step = 0.2;

      function openModal(src, orderLabel) {
        img.src = src;
        img.alt = 'Payment proof ' + (orderLabel || '');
        title.textContent = orderLabel ? ('Payment Proof ' + orderLabel) : 'Payment Proof';
        scale = 1;
        translateX = 0;
        translateY = 0;
        img.style.transform = 'translate(0px, 0px) scale(1)';
        img.classList.remove('zoomed');
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
      }

      function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        img.src = '';
      }

      function applyTransform() {
        img.style.transform = 'translate(' + translateX + 'px, ' + translateY + 'px) scale(' + scale.toFixed(2) + ')';
      }

      function applyZoom(next) {
        scale = Math.min(maxScale, Math.max(minScale, next));
        if (scale === 1) {
          translateX = 0;
          translateY = 0;
        }
        applyTransform();
        if (scale > 1) {
          img.classList.add('zoomed');
        } else {
          img.classList.remove('zoomed');
        }
        zoomOutBtn.disabled = scale <= minScale;
        zoomInBtn.disabled = scale >= maxScale;
      }

      document.querySelectorAll('.proof-link[data-proof]').forEach(function(btn) {
        btn.addEventListener('click', function() {
          openModal(btn.getAttribute('data-proof'), btn.getAttribute('data-order'));
        });
      });

      zoomInBtn.addEventListener('click', function() {
        applyZoom(scale + step);
      });
      zoomOutBtn.addEventListener('click', function() {
        applyZoom(scale - step);
      });
      zoomResetBtn.addEventListener('click', function() {
        applyZoom(1);
      });
      img.addEventListener('click', function() {
        if (!img.classList.contains('zoomed')) {
          applyZoom(1.6);
        }
      });
      img.addEventListener('wheel', function(e) {
        e.preventDefault();
        var delta = e.deltaY > 0 ? -step : step;
        applyZoom(scale + delta);
      }, { passive: false });

      img.addEventListener('mousedown', function(e) {
        if (scale <= 1) return;
        isDragging = true;
        img.classList.add('dragging');
        startX = e.clientX - translateX;
        startY = e.clientY - translateY;
      });
      window.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        translateX = e.clientX - startX;
        translateY = e.clientY - startY;
        applyTransform();
      });
      window.addEventListener('mouseup', function() {
        if (!isDragging) return;
        isDragging = false;
        img.classList.remove('dragging');
      });

      closeBtn.addEventListener('click', closeModal);
      modal.addEventListener('click', function(e) {
        if (e.target === modal) {
          closeModal();
        }
      });
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('show')) {
          closeModal();
        }
      });
    })();
  </script>

  <script>
    (function() {
      var modal = document.getElementById('confirmModal');
      var img = document.getElementById('confirmProofImage');
      var title = document.getElementById('confirmTitle');
      var question = document.getElementById('confirmQuestion');
      var closeBtn = modal.querySelector('.proof-close');
      var zoomInBtn = document.getElementById('confirmZoomIn');
      var zoomOutBtn = document.getElementById('confirmZoomOut');
      var zoomResetBtn = document.getElementById('confirmZoomReset');
      var cancelBtn = document.getElementById('confirmCancel');
      var submitBtn = document.getElementById('confirmSubmit');
      var form = document.getElementById('confirmForm');
      var orderInput = document.getElementById('confirmOrderId');
      var actionInput = document.getElementById('confirmAction');
      var scale = 1;
      var translateX = 0;
      var translateY = 0;
      var isDragging = false;
      var startX = 0;
      var startY = 0;
      var minScale = 1;
      var maxScale = 3;
      var step = 0.2;

      function applyTransform() {
        img.style.transform = 'translate(' + translateX + 'px, ' + translateY + 'px) scale(' + scale.toFixed(2) + ')';
      }

      function applyZoom(next) {
        scale = Math.min(maxScale, Math.max(minScale, next));
        if (scale === 1) {
          translateX = 0;
          translateY = 0;
        }
        applyTransform();
        if (scale > 1) {
          img.classList.add('zoomed');
        } else {
          img.classList.remove('zoomed');
        }
        zoomOutBtn.disabled = scale <= minScale;
        zoomInBtn.disabled = scale >= maxScale;
      }

      function openModal(src, orderId, action) {
        img.src = src;
        img.alt = 'Payment proof #' + orderId;
        title.textContent = (action === 'accept' ? 'Confirm Accept' : 'Confirm Reject') + ' #' + orderId;
        if (question) {
          question.textContent = action === 'accept'
            ? 'Apakah anda yakin ingin menerima order ini?'
            : 'Apakah anda yakin ingin menolak order ini?';
        }
        orderInput.value = orderId;
        actionInput.value = action;
        scale = 1;
        translateX = 0;
        translateY = 0;
        img.style.transform = 'translate(0px, 0px) scale(1)';
        img.classList.remove('zoomed');
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
      }

      function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        img.src = '';
      }

      document.querySelectorAll('[data-confirm-action]').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var proof = btn.getAttribute('data-proof') || '';
          var orderId = btn.getAttribute('data-order-id') || '';
          var action = btn.getAttribute('data-confirm-action') || '';
          if (!proof || !orderId || !action) return;
          openModal(proof, orderId, action);
        });
      });

      zoomInBtn.addEventListener('click', function() {
        applyZoom(scale + step);
      });
      zoomOutBtn.addEventListener('click', function() {
        applyZoom(scale - step);
      });
      zoomResetBtn.addEventListener('click', function() {
        applyZoom(1);
      });
      img.addEventListener('click', function() {
        if (!img.classList.contains('zoomed')) {
          applyZoom(1.6);
        }
      });
      img.addEventListener('wheel', function(e) {
        e.preventDefault();
        var delta = e.deltaY > 0 ? -step : step;
        applyZoom(scale + delta);
      }, { passive: false });

      img.addEventListener('mousedown', function(e) {
        if (scale <= 1) return;
        isDragging = true;
        img.classList.add('dragging');
        startX = e.clientX - translateX;
        startY = e.clientY - translateY;
      });
      window.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        translateX = e.clientX - startX;
        translateY = e.clientY - startY;
        applyTransform();
      });
      window.addEventListener('mouseup', function() {
        if (!isDragging) return;
        isDragging = false;
        img.classList.remove('dragging');
      });

      function handleClose() {
        closeModal();
      }

      closeBtn.addEventListener('click', handleClose);
      cancelBtn.addEventListener('click', handleClose);
      modal.addEventListener('click', function(e) {
        if (e.target === modal) {
          handleClose();
        }
      });
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('show')) {
          handleClose();
        }
      });

      submitBtn.addEventListener('click', function() {
        form.submit();
      });
    })();
  </script>
</body>
</html>
