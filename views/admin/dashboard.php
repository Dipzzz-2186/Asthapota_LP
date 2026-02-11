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
  <title>Admin Dashboard - Asthapora</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="page">
  <header class="page-header">
    <div class="container">
      <div class="topbar">
        <div class="brand">
          <img class="brand-badge" src="/assets/img/lopad.jpg" alt="Lopad logo">
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
      <!-- Header Section -->
      <div class="admin-header spaced">
        <div>
          <h1 class="admin-title">Dashboard</h1>
          <p class="admin-sub">Ringkasan pesanan dan status pembayaran</p>
        </div>
      </div>

      <!-- Statistics Cards -->
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-label"><i class="bi bi-basket"></i> Total Orders</div>
          <div class="stat-value"><?= (int)$totalOrders ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="bi bi-check-circle"></i> Paid Orders</div>
          <div class="stat-value"><?= (int)$paidOrders ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="bi bi-clock-history"></i> Pending Orders</div>
          <div class="stat-value"><?= (int)$pendingOrders ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="bi bi-cash-stack"></i> Total Revenue</div>
          <div class="stat-value small"><?= h(rupiah($totalRevenue)) ?></div>
        </div>
      </div>

      <!-- Filter Section -->
      <div class="card filter-card">
        <form method="get" class="filter-form">
          <div class="filter-label">
            <i class="bi bi-funnel"></i>
            <div>Filter Package</div>
          </div>
          <select name="package">
            <option value="0">All Packages</option>
            <?php foreach ($packages as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= $selectedPackage === (int)$p['id'] ? 'selected' : '' ?>>
                <?= h($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn primary" type="submit"><i class="bi bi-search"></i> Apply Filter</button>
          <?php if ($selectedPackage > 0): ?>
            <a class="btn ghost" href="/admin/dashboard"><i class="bi bi-x-circle"></i> Reset</a>
          <?php endif; ?>
        </form>
      </div>

      <!-- Flash Messages -->
      <?php if ($flash['error']): ?>
        <div class="alert mb-16"><?= h($flash['error']) ?></div>
      <?php endif; ?>
      <?php if ($flash['success']): ?>
        <div class="alert-success"><?= h($flash['success']) ?></div>
      <?php endif; ?>

      <!-- Orders Table -->
      <div class="table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th><i class="bi bi-hash"></i> Order ID</th>
              <th><i class="bi bi-person"></i> User</th>
              <th><i class="bi bi-telephone"></i> Contact</th>
              <th><i class="bi bi-box"></i> Packages</th>
              <th><i class="bi bi-cash"></i> Total</th>
              <th><i class="bi bi-activity"></i> Status</th>
              <th><i class="bi bi-image"></i> Proof</th>
              // (KHOLIS)
              <!-- <th><i class="bi bi-gear"></i> Action</th>   -->
              <th><i class="bi bi-calendar"></i> Created</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$orders): ?>
              <tr><td colspan="8" class="table-empty">
                <div class="empty-state">
                  <i class="bi bi-inbox"></i>
                  No orders yet
                </div>
              </td></tr>
            <?php endif; ?>
            <?php foreach ($orders as $o): ?>
              <tr>
                <td><strong>#<?= (int)$o['id'] ?></strong></td>
                <td><strong><?= h($o['full_name']) ?></strong></td>
                <td class="admin-contact">
                  <div class="admin-contact-line"><i class="bi bi-telephone"></i> <?= h($o['phone']) ?></div>
                  <div class="admin-contact-line"><i class="bi bi-envelope"></i> <?= h($o['email']) ?></div>
                  <div class="admin-contact-line">
                    <i class="bi bi-instagram"></i>
                    <?php
                      $ig = trim((string)($o['instagram'] ?? ''));
                      $ig = $ig !== '' ? '@' . ltrim($ig, '@') : '-';
                    ?>
                    <?= h($ig) ?>
                  </div>
                </td>
                <td><?= h($o['items'] ?? '-') ?></td>
                <td><strong><?= h(rupiah((int)$o['total'])) ?></strong></td>
                <td>
                  <?php if ($o['status'] === 'paid'): ?>
                    <span class="badge paid"><i class="bi bi-check-circle"></i> Paid</span>
                  <?php elseif ($o['status'] === 'accepted'): ?>
                    <span class="badge accepted"><i class="bi bi-check-circle-fill"></i> Accepted</span>
                  <?php elseif ($o['status'] === 'rejected'): ?>
                    <span class="badge rejected"><i class="bi bi-x-circle"></i> Rejected</span>
                  <?php else: ?>
                    <span class="badge pending"><i class="bi bi-clock"></i> <?= h($o['status']) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($o['payment_proof']): ?>
                    <button class="proof-link" type="button" data-proof="/uploads/<?= h($o['payment_proof']) ?>" data-order="#<?= (int)$o['id'] ?>">
                      <i class="bi bi-file-earmark-image"></i> View Proof
                    </button>
                  <?php else: ?>
                    <span class="muted">-</span>
                  <?php endif; ?>
                </td>
                // (KHOLIS)
                <!-- <td>
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
                    ><i class="bi bi-check-circle"></i> Accept</button>
                    <button
                      class="btn ghost small"
                      type="button"
                      data-confirm-action="reject"
                      data-order-id="<?= (int)$o['id'] ?>"
                      data-proof="<?= $o['payment_proof'] ? '/uploads/' . h($o['payment_proof']) : '' ?>"
                      <?= $canAction ? '' : 'disabled' ?>
                    ><i class="bi bi-x-circle"></i> Reject</button>
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

  <!-- Payment Proof Modal -->
  <div class="proof-modal" id="proofModal" aria-hidden="true">
    <div class="proof-card" role="dialog" aria-modal="true" aria-labelledby="proofTitle">
      <div class="proof-head">
        <div class="proof-title" id="proofTitle"><i class="bi bi-image"></i> Payment Proof</div>
        <div class="proof-actions">
          <button class="proof-btn" type="button" id="zoomOut" title="Zoom Out"><i class="bi bi-dash-lg"></i></button>
          <button class="proof-btn" type="button" id="zoomReset" title="Reset Zoom"><i class="bi bi-arrow-counterclockwise"></i></button>
          <button class="proof-btn" type="button" id="zoomIn" title="Zoom In"><i class="bi bi-plus-lg"></i></button>
          <button class="proof-close" type="button" aria-label="Close"><i class="bi bi-x-lg"></i></button>
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
        <div class="proof-title" id="confirmTitle"><i class="bi bi-question-circle"></i> Confirm Action</div>
        <div class="proof-actions">
          <button class="proof-btn" type="button" id="confirmZoomOut" title="Zoom Out"><i class="bi bi-dash-lg"></i></button>
          <button class="proof-btn" type="button" id="confirmZoomReset" title="Reset Zoom"><i class="bi bi-arrow-counterclockwise"></i></button>
          <button class="proof-btn" type="button" id="confirmZoomIn" title="Zoom In"><i class="bi bi-plus-lg"></i></button>
          <button class="proof-close" type="button" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
      </div>
      <div class="confirm-text" id="confirmQuestion">Are you sure?</div>
      <div class="confirm-sub">Please review the payment proof below before confirming.</div>
      <div class="proof-body">
        <img id="confirmProofImage" alt="Payment proof">
      </div>
      <div class="confirm-actions">
        <button class="btn ghost" type="button" id="confirmCancel"><i class="bi bi-x-circle"></i> Tidak</button>
        <button class="btn primary" type="button" id="confirmSubmit"><i class="bi bi-check-circle"></i> Ya</button>
      </div>
    </div>
  </div>

  <form method="post" action="/admin/dashboard" id="confirmForm" style="display:none;">
    <input type="hidden" name="order_id" id="confirmOrderId" value="">
    <input type="hidden" name="action" id="confirmAction" value="">
  </form>

  <script>
    // Proof Modal Script
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
        title.innerHTML = '<i class="bi bi-image"></i> ' + (orderLabel ? ('Payment Proof ' + orderLabel) : 'Payment Proof');
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
        var actionIcon = action === 'accept' ? '<i class="bi bi-check-circle"></i>' : '<i class="bi bi-x-circle"></i>';
        title.innerHTML = actionIcon + ' ' + (action === 'accept' ? 'Confirm Accept' : 'Confirm Reject') + ' #' + orderId;
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
