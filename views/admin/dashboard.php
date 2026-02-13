<?php
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../layout/app.php';
require_admin();

$db = get_db();
$flash = ['success' => '', 'error' => ''];
$selectedOrderIdRaw = trim((string)($_REQUEST['filter_order_id'] ?? ''));
$selectedOrderId = ctype_digit($selectedOrderIdRaw) ? (int)$selectedOrderIdRaw : 0;
$selectedPackage = isset($_REQUEST['package']) ? (int)$_REQUEST['package'] : 0;
$selectedName = trim((string)($_REQUEST['name'] ?? ''));
$selectedEmail = trim((string)($_REQUEST['email'] ?? ''));
$selectedDate = trim((string)($_REQUEST['created_date'] ?? ''));
$selectedStatusRaw = trim((string)($_REQUEST['status'] ?? ''));
$selectedPage = isset($_REQUEST['page']) ? max(1, (int)$_REQUEST['page']) : 1;
$allowedStatusFilters = ['pending', 'accepted', 'rejected'];
$selectedStatus = in_array($selectedStatusRaw, $allowedStatusFilters, true) ? $selectedStatusRaw : '';
if ($selectedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = '';
}

ensure_session();
if (!empty($_SESSION['dashboard_flash']) && is_array($_SESSION['dashboard_flash'])) {
    $flash = array_merge($flash, $_SESSION['dashboard_flash']);
    unset($_SESSION['dashboard_flash']);
}

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Start fresh for each action to avoid mixing previous flash with current result.
    $flash = ['success' => '', 'error' => ''];
    $dashboardAction = trim((string)($_POST['dashboard_action'] ?? 'order_decision'));

    if ($dashboardAction === 'create_sponsor') {
        $sponsorName = trim((string)($_POST['sponsor_name'] ?? ''));
        $sponsorLink = trim((string)($_POST['sponsor_link'] ?? ''));
        $logoFile = $_FILES['sponsor_logo'] ?? null;

        if ($sponsorName === '') {
            $flash['error'] = 'Sponsor name is required.';
        } elseif (mb_strlen($sponsorName) > 150) {
            $flash['error'] = 'Sponsor name is too long.';
        } elseif ($sponsorLink !== '' && !filter_var($sponsorLink, FILTER_VALIDATE_URL)) {
            $flash['error'] = 'Sponsor link must be a valid URL.';
        } elseif (!is_array($logoFile) || (int)($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flash['error'] = 'Sponsor logo is required.';
        } else {
            $tmpPath = (string)($logoFile['tmp_name'] ?? '');
            $mime = '';
            if ($tmpPath !== '' && is_file($tmpPath)) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mime = (string)finfo_file($finfo, $tmpPath);
                    finfo_close($finfo);
                }
            }

            $allowedMimes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];

            if (!isset($allowedMimes[$mime])) {
                $flash['error'] = 'Logo must be JPG, PNG, or WEBP format.';
            } else {
                $uploadDir = __DIR__ . '/../../uploads/sponsors';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                    $flash['error'] = 'Failed to prepare sponsor upload directory.';
                } else {
                    $newFileName = 'sponsor-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedMimes[$mime];
                    $targetPath = $uploadDir . '/' . $newFileName;
                    $storedLogoPath = '/uploads/sponsors/' . $newFileName;

                    if (!move_uploaded_file($tmpPath, $targetPath)) {
                        $flash['error'] = 'Failed to upload sponsor logo.';
                    } else {
                        try {
                            $db->exec(
                                "CREATE TABLE IF NOT EXISTS sponsors (
                                    id INT AUTO_INCREMENT PRIMARY KEY,
                                    name VARCHAR(150) NOT NULL,
                                    website_url VARCHAR(255) NULL,
                                    logo_path VARCHAR(255) NOT NULL,
                                    created_at DATETIME NOT NULL
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                            );

                            $insertSponsor = $db->prepare('INSERT INTO sponsors (name, website_url, logo_path, created_at) VALUES (?, ?, ?, ?)');
                            $insertSponsor->execute([
                                $sponsorName,
                                $sponsorLink !== '' ? $sponsorLink : null,
                                $storedLogoPath,
                                date('Y-m-d H:i:s'),
                            ]);
                            $flash['success'] = 'Sponsor added successfully.';
                        } catch (Throwable $e) {
                            if (is_file($targetPath)) {
                                @unlink($targetPath);
                            }
                            $flash['error'] = 'Failed to save sponsor data.';
                        }
                    }
                }
            }
        }
    } else {
        // Validate request payload
        $orderId = (int)($_POST['order_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $allowed = ['accept', 'reject'];

        if (!$orderId || !in_array($action, $allowed, true)) {
            $flash['error'] = 'Invalid request.';
        } else {
            // Load order + user for email notification
            $stmt = $db->prepare('SELECT o.id, o.status, o.payment_proof, u.email, u.full_name FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?');
            $stmt->execute([$orderId]);
            $orderRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$orderRow) {
                $flash['error'] = 'Order not found.';
            } elseif (empty($orderRow['payment_proof'])) {
                // Require payment proof before any decision
                $flash['error'] = 'Cannot update. Payment proof is required.';
            } elseif ($orderRow['status'] !== 'paid') {
                // Only paid orders can be accepted/rejected
                $flash['error'] = 'Only paid orders can be accepted or rejected.';
            } else {
                // Update status + notify user
                $newStatus = $action === 'accept' ? 'accepted' : 'rejected';
                $update = $db->prepare('UPDATE orders SET status = ? WHERE id = ?');
                $update->execute([$newStatus, $orderId]);

                $orderRow['status'] = $newStatus;
                $sent = send_order_status_email($orderRow, $orderRow['email']);
                $flash['success'] = $sent
                    ? 'Order status updated and email sent.'
                    : 'Order status updated, but email failed to send.';
            }
        }
    }

    $_SESSION['dashboard_flash'] = $flash;
    $redirectParams = [];
    if ($selectedPackage > 0) {
        $redirectParams['package'] = $selectedPackage;
    }
    if ($selectedOrderId > 0) {
        $redirectParams['filter_order_id'] = $selectedOrderId;
    }
    if ($selectedName !== '') {
        $redirectParams['name'] = $selectedName;
    }
    if ($selectedEmail !== '') {
        $redirectParams['email'] = $selectedEmail;
    }
    if ($selectedDate !== '') {
        $redirectParams['created_date'] = $selectedDate;
    }
    if ($selectedStatus !== '') {
        $redirectParams['status'] = $selectedStatus;
    }
    if ($selectedPage > 1) {
        $redirectParams['page'] = $selectedPage;
    }
    $redirectPath = '/admin/dashboard';
    if ($redirectParams) {
        $redirectPath .= '?' . http_build_query($redirectParams);
    }
    redirect($redirectPath);
}

$packages = $db->query("SELECT id, name FROM packages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$whereParts = ['1=1'];
$params = [];
if ($selectedOrderId > 0) {
    $whereParts[] = "o.id = ?";
    $params[] = $selectedOrderId;
}
if ($selectedPackage > 0) {
    $whereParts[] = "EXISTS (
        SELECT 1 FROM order_items oi
        JOIN packages p ON p.id = oi.package_id
        WHERE oi.order_id = o.id AND p.id = ?
    )";
    $params[] = $selectedPackage;
}
if ($selectedName !== '') {
    $whereParts[] = "u.full_name LIKE ?";
    $params[] = '%' . $selectedName . '%';
}
if ($selectedEmail !== '') {
    $whereParts[] = "u.email LIKE ?";
    $params[] = '%' . $selectedEmail . '%';
}
if ($selectedDate !== '') {
    $whereParts[] = "DATE(o.created_at) = ?";
    $params[] = $selectedDate;
}
if ($selectedStatus === 'accepted' || $selectedStatus === 'rejected') {
    $whereParts[] = "o.status = ?";
    $params[] = $selectedStatus;
} elseif ($selectedStatus === 'pending') {
    // "pending" on dashboard means orders still waiting final decision by admin.
    $whereParts[] = "o.status IN ('pending', 'paid')";
}
$whereSql = ' WHERE ' . implode(' AND ', $whereParts);

$summarySql = "SELECT
    COUNT(*) AS total_orders,
    COALESCE(SUM(CASE WHEN o.status = 'accepted' THEN o.total ELSE 0 END), 0) AS total_revenue,
    SUM(CASE WHEN o.status IN ('paid', 'accepted', 'rejected') THEN 1 ELSE 0 END) AS paid_orders,
    SUM(CASE WHEN o.status NOT IN ('paid', 'accepted', 'rejected') THEN 1 ELSE 0 END) AS pending_orders
  FROM orders o
  JOIN users u ON u.id = o.user_id" . $whereSql;
$summaryStmt = $db->prepare($summarySql);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalOrders = (int)($summary['total_orders'] ?? 0);
$paidOrders = (int)($summary['paid_orders'] ?? 0);
$pendingOrders = (int)($summary['pending_orders'] ?? 0);
$totalRevenue = (int)($summary['total_revenue'] ?? 0);

$perPage = 20;
$totalPages = max(1, (int)ceil($totalOrders / $perPage));
$currentPage = min($selectedPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;

$sql = "SELECT o.id, u.full_name, u.phone, u.email, u.instagram, o.total, o.status, o.payment_proof, o.created_at,
  (SELECT GROUP_CONCAT(CONCAT(p.name, ' x', oi.qty) SEPARATOR ', ') FROM order_items oi JOIN packages p ON p.id = oi.package_id WHERE oi.order_id = o.id) as items
  FROM orders o JOIN users u ON u.id = o.user_id" . $whereSql .
  " ORDER BY o.created_at DESC, o.id DESC LIMIT ? OFFSET ?";

$stmt = $db->prepare($sql);
foreach ($params as $index => $value) {
    $stmt->bindValue($index + 1, $value);
}
$stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$orderItemDetailsMap = [];
$orderTicketCountMap = [];
$orderAttendeeMap = [];
$orderIds = array_values(array_unique(array_map(static function ($row) {
    return (int)($row['id'] ?? 0);
}, $orders)));

if ($orderIds) {
    $inPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));

    $itemSql = "SELECT oi.order_id, p.name AS package_name, oi.qty, oi.price
        FROM order_items oi
        JOIN packages p ON p.id = oi.package_id
        WHERE oi.order_id IN ($inPlaceholders)
        ORDER BY oi.order_id ASC, p.name ASC";
    $itemStmt = $db->prepare($itemSql);
    foreach ($orderIds as $index => $orderId) {
        $itemStmt->bindValue($index + 1, $orderId, PDO::PARAM_INT);
    }
    $itemStmt->execute();
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $oid = (int)($row['order_id'] ?? 0);
        if ($oid <= 0) {
            continue;
        }
        if (!isset($orderItemDetailsMap[$oid])) {
            $orderItemDetailsMap[$oid] = [];
        }
        $qty = max(0, (int)($row['qty'] ?? 0));
        $price = max(0, (int)($row['price'] ?? 0));
        $orderItemDetailsMap[$oid][] = [
            'package_name' => (string)($row['package_name'] ?? ''),
            'qty' => $qty,
            'price' => $price,
            'subtotal' => $qty * $price,
        ];
        $orderTicketCountMap[$oid] = ($orderTicketCountMap[$oid] ?? 0) + $qty;
    }

    try {
        $attendeeSql = "SELECT order_id, attendee_name, position_no
            FROM order_attendees
            WHERE order_id IN ($inPlaceholders)
            ORDER BY order_id ASC, position_no ASC, id ASC";
        $attendeeStmt = $db->prepare($attendeeSql);
        foreach ($orderIds as $index => $orderId) {
            $attendeeStmt->bindValue($index + 1, $orderId, PDO::PARAM_INT);
        }
        $attendeeStmt->execute();
        foreach ($attendeeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $oid = (int)($row['order_id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            if (!isset($orderAttendeeMap[$oid])) {
                $orderAttendeeMap[$oid] = [];
            }
            $orderAttendeeMap[$oid][] = [
                'position_no' => (int)($row['position_no'] ?? 0),
                'attendee_name' => trim((string)($row['attendee_name'] ?? '')),
            ];
        }
    } catch (Throwable $e) {
        $orderAttendeeMap = [];
    }
}

$hasActiveFilters = $selectedPackage > 0
    || $selectedOrderId > 0
    || $selectedName !== ''
    || $selectedEmail !== ''
    || $selectedDate !== ''
    || $selectedStatus !== '';
$startRow = $totalOrders > 0 ? ($offset + 1) : 0;
$endRow = min($offset + count($orders), $totalOrders);
$paginationBaseParams = [];
if ($selectedOrderId > 0) {
    $paginationBaseParams['filter_order_id'] = $selectedOrderId;
}
if ($selectedPackage > 0) {
    $paginationBaseParams['package'] = $selectedPackage;
}
if ($selectedName !== '') {
    $paginationBaseParams['name'] = $selectedName;
}
if ($selectedEmail !== '') {
    $paginationBaseParams['email'] = $selectedEmail;
}
if ($selectedDate !== '') {
    $paginationBaseParams['created_date'] = $selectedDate;
}
if ($selectedStatus !== '') {
    $paginationBaseParams['status'] = $selectedStatus;
}
$extraHead = <<<'HTML'
<style>
  .filter-card {
    padding: 18px 20px;
    overflow: hidden;
  }

  .dashboard-filter-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    align-items: end;
  }

  .dashboard-filter-form .filter-label {
    grid-column: 1 / -1;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    color: var(--text);
  }

  .dashboard-filter-form .filter-field {
    display: grid;
    gap: 6px;
  }

  .dashboard-filter-form .field-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.4px;
  }

  .dashboard-filter-form input,
  .dashboard-filter-form select {
    width: 100%;
    min-height: 52px;
    padding: 12px 14px;
    border-radius: var(--radius-sm);
    border: 2px solid var(--stroke);
    font-size: 14px;
    font-family: inherit;
    background: var(--surface);
    color: var(--text);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
  }

  .dashboard-filter-form input::placeholder {
    color: var(--muted);
  }

  .dashboard-filter-form input:focus,
  .dashboard-filter-form select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(0, 102, 255, 0.1);
  }

  .dashboard-filter-form .filter-actions {
    display: inline-flex;
    align-items: center;
    justify-content: flex-start;
    grid-column: 1 / -1;
    gap: 8px;
    flex-wrap: wrap;
    padding-top: 8px;
  }

  .dashboard-filter-form .filter-actions .btn {
    min-height: 46px;
  }


  .filter-hint {
    margin-top: 10px;
    font-size: 12px;
    color: var(--muted);
  }

  @media (max-width: 1180px) {
    .dashboard-filter-form {
      grid-template-columns: repeat(3, minmax(170px, 1fr));
    }
  }

  @media (max-width: 760px) {
    .dashboard-filter-form {
      grid-template-columns: 1fr;
    }

    .dashboard-filter-form .btn.ghost,
    .dashboard-filter-form .btn.primary,
    .dashboard-filter-form .filter-actions {
      width: 100%;
      justify-content: center;
    }

  }

  .pagination-wrap {
    margin-top: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
  }

  .pagination-info {
    color: var(--muted);
    font-size: 13px;
  }

  .pagination {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    padding: 6px;
    border: 1px solid var(--stroke);
    border-radius: 999px;
    background: var(--surface);
  }

  .pagination .btn {
    min-width: 40px;
    justify-content: center;
    border-radius: 999px;
  }

  .pagination .btn.active {
    pointer-events: none;
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
  }

  .pagination .btn.is-disabled {
    pointer-events: none;
    opacity: 0.45;
  }

  table.admin-table th:nth-child(3),
  table.admin-table td:nth-child(3) {
    width: 240px;
  }

  .admin-contact-line {
    display: grid;
    grid-template-columns: 16px minmax(0, 1fr);
    align-items: start;
  }

  .admin-contact-line .contact-value {
    overflow-wrap: anywhere;
    word-break: break-word;
  }

  .dashboard-head-actions {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }

  .sponsor-modal {
    position: fixed;
    inset: 0;
    background: rgba(11, 19, 34, 0.72);
    backdrop-filter: blur(3px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1100;
    padding: clamp(12px, 2vw, 22px);
  }

  .sponsor-modal.show {
    display: flex;
    animation: sponsorModalFade 0.2s ease-out;
  }

  .sponsor-modal-card {
    width: min(560px, 100%);
    max-height: min(88vh, 760px);
    background: var(--surface);
    border: 1px solid var(--stroke);
    border-radius: 16px;
    box-shadow: 0 22px 45px rgba(9, 20, 39, 0.35);
    overflow: hidden;
    display: grid;
    grid-template-rows: auto 1fr;
    animation: sponsorModalCardIn 0.24s cubic-bezier(.2, .7, .2, 1);
  }

  .sponsor-modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--stroke);
  }

  .sponsor-modal-title {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    color: var(--text);
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }

  .sponsor-modal-close {
    width: 34px;
    height: 34px;
    border-radius: 999px;
    border: 1px solid var(--stroke);
    background: var(--surface);
    color: var(--text);
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.14s ease;
  }

  .sponsor-modal-close:hover {
    background: #eef4ff;
    border-color: #bfd2ff;
    transform: translateY(-1px);
  }

  .sponsor-form {
    padding: 16px;
    display: grid;
    gap: 12px;
    overflow-y: auto;
  }

  .sponsor-field {
    display: grid;
    gap: 6px;
  }

  .sponsor-field label {
    font-size: 12px;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.4px;
  }

  .sponsor-field input[type="text"],
  .sponsor-field input[type="url"],
  .sponsor-field input[type="file"] {
    width: 100%;
    min-height: 50px;
    padding: 11px 12px;
    border-radius: var(--radius-sm);
    border: 2px solid var(--stroke);
    font-size: 14px;
    font-family: inherit;
    background: var(--surface);
    color: var(--text);
  }

  .sponsor-field input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(0, 102, 255, 0.1);
  }

  .sponsor-field input[type="file"] {
    padding: 8px;
    cursor: pointer;
    background: #f7f9ff;
  }

  .sponsor-field input[type="file"]::file-selector-button {
    border: 0;
    border-radius: 999px;
    padding: 9px 13px;
    margin-right: 10px;
    background: #dfeaff;
    color: #0d3f98;
    font-weight: 700;
    cursor: pointer;
  }

  .sponsor-help {
    font-size: 12px;
    color: var(--muted);
    margin: 0;
  }

  .sponsor-form-actions {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 2px;
  }

  body.sponsor-modal-open {
    overflow: hidden;
  }

  @media (max-width: 640px) {
    .sponsor-modal-card {
      max-height: 92vh;
      border-radius: 14px;
    }

    .sponsor-form-actions {
      justify-content: stretch;
    }

    .sponsor-form-actions .btn {
      width: 100%;
    }
  }

  @keyframes sponsorModalFade {
    from { opacity: 0; }
    to { opacity: 1; }
  }

  @keyframes sponsorModalCardIn {
    from {
      opacity: 0;
      transform: translateY(10px) scale(0.98);
    }
    to {
      opacity: 1;
      transform: translateY(0) scale(1);
    }
  }

  #orderDetailModal .proof-card {
    max-width: min(95vw, 1020px);
    border-radius: 22px;
  }

  #orderDetailModal .proof-head {
    padding: 16px 20px;
  }

  #orderDetailModal .proof-title {
    font-size: 28px;
    font-weight: 800;
    letter-spacing: -0.4px;
  }

  .order-detail-body {
    padding: 14px 20px 20px;
    max-height: calc(90vh - 72px);
    overflow-y: auto;
  }

  .detail-head {
    margin-bottom: 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .detail-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    border-radius: 999px;
    border: 1px solid var(--stroke);
    background: var(--surface-2);
    color: var(--text);
    font-size: 12px;
    line-height: 1.2;
  }

  .detail-chip .chip-label {
    color: var(--muted);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-size: 11px;
  }

  .detail-chip .chip-value {
    color: var(--text);
    font-weight: 700;
  }

  .detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
  }

  .detail-box {
    border: 1px solid var(--stroke);
    border-radius: 14px;
    background: linear-gradient(180deg, var(--surface) 0%, var(--surface-2) 100%);
    padding: 14px;
    box-shadow: 0 10px 20px rgba(15, 23, 42, 0.06);
  }

  .detail-title {
    font-size: 14px;
    font-weight: 800;
    margin-bottom: 10px;
    color: var(--text);
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .detail-list {
    margin: 0;
    padding-left: 0;
    list-style: none;
    display: grid;
    gap: 8px;
    color: var(--text);
    font-size: 13px;
  }

  .detail-list li {
    border: 1px solid var(--stroke);
    border-radius: 10px;
    background: var(--surface);
    padding: 10px 12px;
    line-height: 1.45;
  }

  .detail-empty {
    color: var(--muted);
    font-size: 13px;
    padding: 4px 2px 2px;
  }

  @media (max-width: 900px) {
    #orderDetailModal .proof-title {
      font-size: 22px;
    }

    .order-detail-body {
      padding: 12px;
    }

    .detail-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
HTML;
render_header([
    'title' => 'Admin Dashboard - Asthapora',
    'isAdmin' => true,
    'showNav' => false,
    'brandSubtitle' => 'Dashboard Control Center',
    'extraHead' => $extraHead,
]);
?>

  <main class="admin-shell">
    <div class="container admin-container-wide">
      <!-- Header Section -->
      <div class="admin-header spaced">
        <div>
          <h1 class="admin-title">Dashboard</h1>
          <p class="admin-sub">Ringkasan pesanan dan status pembayaran</p>
        </div>
        <div class="dashboard-head-actions">
          <button class="btn primary" type="button" id="openSponsorModal">
            <i class="bi bi-building-add"></i> Tambah Sponsor
          </button>
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
        <form method="get" class="filter-form dashboard-filter-form" id="dashboardFilterForm">
          <div class="filter-label">
            <i class="bi bi-funnel"></i>
            <div>Filter Orders</div>
          </div>
          <div class="filter-field">
            <label class="field-label" for="filterOrderId">Order ID</label>
            <input id="filterOrderId" type="text" name="filter_order_id" value="<?= $selectedOrderId > 0 ? (int)$selectedOrderId : '' ?>" placeholder="Order ID">
          </div>
          <div class="filter-field">
            <label class="field-label" for="filterName">Nama akun</label>
            <input id="filterName" type="text" name="name" value="<?= h($selectedName) ?>" placeholder="Nama akun">
          </div>
          <div class="filter-field">
            <label class="field-label" for="filterEmail">Email</label>
            <input id="filterEmail" type="email" name="email" value="<?= h($selectedEmail) ?>" placeholder="Email">
          </div>
          <div class="filter-field">
            <label class="field-label" for="filterDate">Tanggal</label>
            <input id="filterDate" type="date" name="created_date" value="<?= h($selectedDate) ?>">
          </div>
          <div class="filter-field">
            <label class="field-label" for="filterStatus">Status</label>
            <select id="filterStatus" name="status">
              <option value="">All Status</option>
              <option value="pending" <?= $selectedStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
              <option value="accepted" <?= $selectedStatus === 'accepted' ? 'selected' : '' ?>>Accept</option>
              <option value="rejected" <?= $selectedStatus === 'rejected' ? 'selected' : '' ?>>Reject</option>
            </select>
          </div>
          <div class="filter-field">
            <label class="field-label" for="filterPackage">Package</label>
            <select id="filterPackage" name="package">
              <option value="0">All Packages</option>
              <?php foreach ($packages as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $selectedPackage === (int)$p['id'] ? 'selected' : '' ?>>
                  <?= h($p['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-actions">
            <button class="btn primary" type="submit"><i class="bi bi-search"></i> Apply</button>
            <?php if ($hasActiveFilters): ?>
              <a class="btn ghost" href="/admin/dashboard"><i class="bi bi-x-circle"></i> Reset</a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- Flash Messages -->
      <?php if ($flash['error']): ?>
        <div class="alert mb-16"><?= h($flash['error']) ?></div>
      <?php endif; ?>
      <?php if ($flash['success']): ?>
        <div class="alert-success"><?= h($flash['success']) ?></div>
      <?php endif; ?>

      <?php if ($totalPages > 1): ?>
        <div class="pagination-wrap">
          <div class="pagination-info">
            Menampilkan <?= (int)$startRow ?>-<?= (int)$endRow ?> dari <?= (int)$totalOrders ?> data
          </div>
          <div class="pagination">
            <?php
              $prevPage = max(1, $currentPage - 1);
              $nextPage = min($totalPages, $currentPage + 1);
              $windowStart = max(1, $currentPage - 2);
              $windowEnd = min($totalPages, $currentPage + 2);
            ?>
            <a class="btn ghost small<?= $currentPage <= 1 ? ' is-disabled' : '' ?>" href="/admin/dashboard?<?= h(http_build_query($paginationBaseParams + ['page' => $prevPage])) ?>"><i class="bi bi-chevron-left"></i></a>
            <?php for ($page = $windowStart; $page <= $windowEnd; $page++): ?>
              <a class="btn ghost small<?= $page === $currentPage ? ' active' : '' ?>" href="/admin/dashboard?<?= h(http_build_query($paginationBaseParams + ['page' => $page])) ?>"><?= (int)$page ?></a>
            <?php endfor; ?>
            <a class="btn ghost small<?= $currentPage >= $totalPages ? ' is-disabled' : '' ?>" href="/admin/dashboard?<?= h(http_build_query($paginationBaseParams + ['page' => $nextPage])) ?>"><i class="bi bi-chevron-right"></i></a>
          </div>
        </div>
      <?php elseif ($totalOrders > 0): ?>
        <div class="pagination-wrap">
          <div class="pagination-info">
            Menampilkan <?= (int)$startRow ?>-<?= (int)$endRow ?> dari <?= (int)$totalOrders ?> data
          </div>
        </div>
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
              <th><i class="bi bi-gear"></i> Action</th>  
              <th><i class="bi bi-calendar"></i> Created</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$orders): ?>
              <tr><td colspan="9" class="table-empty">
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
                  <div class="admin-contact-line"><i class="bi bi-telephone"></i> <span class="contact-value"><?= h($o['phone']) ?></span></div>
                  <div class="admin-contact-line"><i class="bi bi-envelope"></i> <span class="contact-value"><?= h($o['email']) ?></span></div>
                  <div class="admin-contact-line">
                    <i class="bi bi-instagram"></i>
                    <?php
                      $ig = trim((string)($o['instagram'] ?? ''));
                      $ig = $ig !== '' ? '@' . ltrim($ig, '@') : '-';
                    ?>
                    <span class="contact-value"><?= h($ig) ?></span>
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
                <td>
                  <?php
                  // Buttons enabled only for paid orders with proof
                  $canAction = !empty($o['payment_proof']) && $o['status'] === 'paid';
                  $detailOrderId = (int)$o['id'];
                  $detailItems = $orderItemDetailsMap[$detailOrderId] ?? [];
                  $detailAttendees = $orderAttendeeMap[$detailOrderId] ?? [];
                  $detailPayload = [
                      'order_id' => $detailOrderId,
                      'user_name' => (string)($o['full_name'] ?? ''),
                      'total' => (int)($o['total'] ?? 0),
                      'status' => (string)($o['status'] ?? ''),
                      'created_at' => (string)($o['created_at'] ?? ''),
                      'ticket_count' => (int)($orderTicketCountMap[$detailOrderId] ?? 0),
                      'items' => $detailItems,
                      'attendees' => $detailAttendees,
                  ];
                  ?>
                  <div class="action-group">
                    <button
                      class="btn ghost small"
                      type="button"
                      data-order-detail="<?= h(json_encode($detailPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                    ><i class="bi bi-info-circle"></i> Detail</button>
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
                </td>
               
                <td><?= h(date('d M Y H:i', strtotime($o['created_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="pagination-wrap">
          <div class="pagination-info">
            Menampilkan <?= (int)$startRow ?>-<?= (int)$endRow ?> dari <?= (int)$totalOrders ?> data
          </div>
          <div class="pagination">
            <?php
              $prevPage = max(1, $currentPage - 1);
              $nextPage = min($totalPages, $currentPage + 1);
              $windowStart = max(1, $currentPage - 2);
              $windowEnd = min($totalPages, $currentPage + 2);
            ?>
            <a class="btn ghost small<?= $currentPage <= 1 ? ' is-disabled' : '' ?>" href="/admin/dashboard?<?= h(http_build_query($paginationBaseParams + ['page' => $prevPage])) ?>"><i class="bi bi-chevron-left"></i></a>
            <?php for ($page = $windowStart; $page <= $windowEnd; $page++): ?>
              <a class="btn ghost small<?= $page === $currentPage ? ' active' : '' ?>" href="/admin/dashboard?<?= h(http_build_query($paginationBaseParams + ['page' => $page])) ?>"><?= (int)$page ?></a>
            <?php endfor; ?>
            <a class="btn ghost small<?= $currentPage >= $totalPages ? ' is-disabled' : '' ?>" href="/admin/dashboard?<?= h(http_build_query($paginationBaseParams + ['page' => $nextPage])) ?>"><i class="bi bi-chevron-right"></i></a>
          </div>
        </div>
      <?php elseif ($totalOrders > 0): ?>
        <div class="pagination-wrap">
          <div class="pagination-info">
            Menampilkan <?= (int)$startRow ?>-<?= (int)$endRow ?> dari <?= (int)$totalOrders ?> data
          </div>
        </div>
      <?php endif; ?>
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

  <div class="proof-modal" id="orderDetailModal" aria-hidden="true">
    <div class="proof-card" role="dialog" aria-modal="true" aria-labelledby="orderDetailTitle">
      <div class="proof-head">
        <div class="proof-title" id="orderDetailTitle"><i class="bi bi-receipt"></i> Order Detail</div>
        <div class="proof-actions">
          <button class="proof-close" type="button" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
      </div>
      <div class="order-detail-body">
        <div class="detail-head" id="orderDetailHead"></div>
        <div class="detail-grid">
          <div class="detail-box">
            <div class="detail-title"><i class="bi bi-box-seam"></i> Package Breakdown</div>
            <ul class="detail-list" id="orderDetailItems"></ul>
            <div class="detail-empty" id="orderDetailItemsEmpty">No package detail available.</div>
          </div>
          <div class="detail-box">
            <div class="detail-title"><i class="bi bi-people"></i> Attendees</div>
            <ul class="detail-list" id="orderDetailAttendees"></ul>
            <div class="detail-empty" id="orderDetailAttendeesEmpty">No attendee data available.</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <form method="post" action="/admin/dashboard" id="confirmForm" style="display:none;">
    <input type="hidden" name="dashboard_action" value="order_decision">
    <input type="hidden" name="order_id" id="confirmOrderId" value="">
    <input type="hidden" name="action" id="confirmAction" value="">
    <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
    <input type="hidden" name="filter_order_id" value="<?= $selectedOrderId > 0 ? (int)$selectedOrderId : '' ?>">
    <input type="hidden" name="package" value="<?= (int)$selectedPackage ?>">
    <input type="hidden" name="name" value="<?= h($selectedName) ?>">
    <input type="hidden" name="email" value="<?= h($selectedEmail) ?>">
    <input type="hidden" name="created_date" value="<?= h($selectedDate) ?>">
    <input type="hidden" name="status" value="<?= h($selectedStatus) ?>">
  </form>

  <div class="sponsor-modal" id="sponsorModal" aria-hidden="true">
    <div class="sponsor-modal-card" role="dialog" aria-modal="true" aria-labelledby="sponsorModalTitle">
      <div class="sponsor-modal-head">
        <h2 class="sponsor-modal-title" id="sponsorModalTitle"><i class="bi bi-building-add"></i> Tambah Sponsor</h2>
        <button class="sponsor-modal-close" type="button" id="closeSponsorModal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form class="sponsor-form" method="post" action="/admin/dashboard" enctype="multipart/form-data" id="sponsorForm">
        <input type="hidden" name="dashboard_action" value="create_sponsor">
        <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
        <input type="hidden" name="filter_order_id" value="<?= $selectedOrderId > 0 ? (int)$selectedOrderId : '' ?>">
        <input type="hidden" name="package" value="<?= (int)$selectedPackage ?>">
        <input type="hidden" name="name" value="<?= h($selectedName) ?>">
        <input type="hidden" name="email" value="<?= h($selectedEmail) ?>">
        <input type="hidden" name="created_date" value="<?= h($selectedDate) ?>">
        <input type="hidden" name="status" value="<?= h($selectedStatus) ?>">

        <div class="sponsor-field">
          <label for="sponsorName">Nama Sponsor</label>
          <input id="sponsorName" type="text" name="sponsor_name" placeholder="Contoh: FCOM" required>
        </div>

        <div class="sponsor-field">
          <label for="sponsorLink">Link Web Sponsor (opsional)</label>
          <input id="sponsorLink" type="url" name="sponsor_link" placeholder="https://example.com">
        </div>

        <div class="sponsor-field">
          <label for="sponsorLogo">Logo Sponsor</label>
          <input id="sponsorLogo" type="file" name="sponsor_logo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
          <p class="sponsor-help">Format: JPG, PNG, WEBP.</p>
        </div>

        <div class="sponsor-form-actions">
          <button class="btn ghost" type="button" id="cancelSponsorModal"><i class="bi bi-x-circle"></i> Batal</button>
          <button class="btn primary" type="submit"><i class="bi bi-check-circle"></i> Simpan Sponsor</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      var form = document.getElementById('dashboardFilterForm');
      if (!form) return;

      var focusKey = 'adminDashboardFilterFocus';
      var textTimer = null;
      var textDelayMs = 600;

      function saveTypingState(el) {
        if (!el || !el.name) return;
        var cursor = null;
        if (typeof el.selectionStart === 'number') {
          cursor = el.selectionStart;
        } else if (typeof el.value === 'string') {
          // Fallback for input types that do not expose selectionStart consistently (e.g. email).
          cursor = el.value.length;
        }
        try {
          sessionStorage.setItem(focusKey, JSON.stringify({
            name: el.name,
            cursor: cursor
          }));
        } catch (err) {}
      }

      function restoreTypingState() {
        var raw = null;
        try {
          raw = sessionStorage.getItem(focusKey);
        } catch (err) {}
        if (!raw) return;
        try {
          var data = JSON.parse(raw);
          if (!data || !data.name) return;
          var target = form.querySelector('[name=\"' + data.name + '\"]');
          if (!target) return;
          target.focus();
          var max = target.value.length;
          var pos = typeof data.cursor === 'number' ? Math.max(0, Math.min(max, data.cursor)) : max;
          window.requestAnimationFrame(function () {
            if (typeof target.setSelectionRange === 'function') {
              try {
                target.setSelectionRange(pos, pos);
                return;
              } catch (err) {}
            }
            // Fallback: force caret to end when setSelectionRange is unsupported.
            var val = target.value;
            target.value = '';
            target.value = val;
          });
        } catch (err) {}
      }

      function submitNow() {
        var active = document.activeElement;
        if (active && form.contains(active)) {
          saveTypingState(active);
        }
        form.submit();
      }

      restoreTypingState();

      form.querySelectorAll('select,input[type="date"]').forEach(function (el) {
        el.addEventListener('change', submitNow);
      });

      form.querySelectorAll('input[type="text"],input[type="email"]').forEach(function (el) {
        el.addEventListener('input', function () {
          saveTypingState(el);
          if (textTimer) clearTimeout(textTimer);
          textTimer = setTimeout(submitNow, textDelayMs);
        });
        el.addEventListener('click', function () {
          saveTypingState(el);
        });
        el.addEventListener('keyup', function () {
          saveTypingState(el);
        });
        el.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            if (textTimer) clearTimeout(textTimer);
            submitNow();
          }
        });
      });
    })();
  </script>

  <script>
    (function () {
      var modal = document.getElementById('sponsorModal');
      var openBtn = document.getElementById('openSponsorModal');
      var closeBtn = document.getElementById('closeSponsorModal');
      var cancelBtn = document.getElementById('cancelSponsorModal');
      var form = document.getElementById('sponsorForm');

      if (!modal || !openBtn || !closeBtn || !cancelBtn || !form) return;

      function openModal() {
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('sponsor-modal-open');
        var nameInput = document.getElementById('sponsorName');
        if (nameInput) {
          setTimeout(function () { nameInput.focus(); }, 20);
        }
      }

      function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('sponsor-modal-open');
      }

      openBtn.addEventListener('click', openModal);
      closeBtn.addEventListener('click', closeModal);
      cancelBtn.addEventListener('click', closeModal);

      modal.addEventListener('click', function (e) {
        if (e.target === modal) {
          closeModal();
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('show')) {
          closeModal();
        }
      });
    })();
  </script>

  <script>
    (function() {
      var modal = document.getElementById('orderDetailModal');
      if (!modal) return;
      var title = document.getElementById('orderDetailTitle');
      var detailHead = document.getElementById('orderDetailHead');
      var detailItems = document.getElementById('orderDetailItems');
      var detailItemsEmpty = document.getElementById('orderDetailItemsEmpty');
      var detailAttendees = document.getElementById('orderDetailAttendees');
      var detailAttendeesEmpty = document.getElementById('orderDetailAttendeesEmpty');
      var closeBtn = modal.querySelector('.proof-close');

      function statusLabel(status) {
        if (status === 'paid') return 'Paid';
        if (status === 'accepted') return 'Accepted';
        if (status === 'rejected') return 'Rejected';
        return status || '-';
      }

      function asCurrency(n) {
        var value = Number(n || 0);
        return 'Rp ' + value.toLocaleString('id-ID');
      }

      function formatDate(raw) {
        if (!raw) return '-';
        var d = new Date(raw);
        if (isNaN(d.getTime())) return String(raw);
        return d.toLocaleString('id-ID', {
          day: '2-digit',
          month: 'short',
          year: 'numeric',
          hour: '2-digit',
          minute: '2-digit'
        });
      }

      function escapeHtml(text) {
        return String(text == null ? '' : text)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function clearList(el) {
        while (el.firstChild) {
          el.removeChild(el.firstChild);
        }
      }

      function openDetail(rawJson) {
        var payload = null;
        try {
          payload = JSON.parse(rawJson || '{}');
        } catch (err) {
          payload = {};
        }

        var orderId = Number(payload.order_id || 0);
        title.innerHTML = '<i class="bi bi-receipt"></i> Order Detail #' + (orderId || '-');

        var createdAt = formatDate(payload.created_at);
        var ticketCount = Number(payload.ticket_count || 0);
        detailHead.innerHTML =
          '<div class="detail-chip"><span class="chip-label">User</span><span class="chip-value">' + escapeHtml(payload.user_name || '-') + '</span></div>' +
          '<div class="detail-chip"><span class="chip-label">Status</span><span class="chip-value">' + escapeHtml(statusLabel(payload.status || '')) + '</span></div>' +
          '<div class="detail-chip"><span class="chip-label">Tickets</span><span class="chip-value">' + ticketCount + '</span></div>' +
          '<div class="detail-chip"><span class="chip-label">Total</span><span class="chip-value">' + asCurrency(payload.total || 0) + '</span></div>' +
          '<div class="detail-chip"><span class="chip-label">Created</span><span class="chip-value">' + escapeHtml(createdAt) + '</span></div>';

        clearList(detailItems);
        var items = Array.isArray(payload.items) ? payload.items : [];
        items.forEach(function(it) {
          var li = document.createElement('li');
          var packageName = it && it.package_name ? String(it.package_name) : '-';
          var qty = Number(it && it.qty ? it.qty : 0);
          var price = Number(it && it.price ? it.price : 0);
          var subtotal = Number(it && it.subtotal ? it.subtotal : (qty * price));
          li.textContent = packageName + ' x' + qty + ' @ ' + asCurrency(price) + ' = ' + asCurrency(subtotal);
          detailItems.appendChild(li);
        });
        detailItemsEmpty.style.display = items.length ? 'none' : 'block';

        clearList(detailAttendees);
        var attendees = Array.isArray(payload.attendees) ? payload.attendees : [];
        attendees.forEach(function(at) {
          var li = document.createElement('li');
          var pos = Number(at && at.position_no ? at.position_no : 0);
          var name = at && at.attendee_name ? String(at.attendee_name) : '-';
          li.textContent = (pos > 0 ? ('#' + pos + ' - ') : '') + name;
          detailAttendees.appendChild(li);
        });
        detailAttendeesEmpty.style.display = attendees.length ? 'none' : 'block';

        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
      }

      function closeDetail() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
      }

      document.querySelectorAll('[data-order-detail]').forEach(function(btn) {
        btn.addEventListener('click', function() {
          openDetail(btn.getAttribute('data-order-detail') || '{}');
        });
      });

      closeBtn.addEventListener('click', closeDetail);
      modal.addEventListener('click', function(e) {
        if (e.target === modal) {
          closeDetail();
        }
      });
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('show')) {
          closeDetail();
        }
      });
    })();
  </script>

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

      var isSubmitting = false;
      form.addEventListener('submit', function(e) {
        if (isSubmitting) {
          e.preventDefault();
          return;
        }
        isSubmitting = true;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
        cancelBtn.disabled = true;
        closeBtn.disabled = true;
      });

      submitBtn.addEventListener('click', function() {
        form.submit();
      });
    })();
  </script>
  <script>
    (function() {
      var alerts = document.querySelectorAll('.alert, .alert-success');
      if (!alerts.length) return;

      setTimeout(function() {
        alerts.forEach(function(el) {
          el.style.transition = 'opacity 0.35s ease, transform 0.35s ease';
          el.style.opacity = '0';
          el.style.transform = 'translateY(-6px)';
          setTimeout(function() {
            if (el && el.parentNode) {
              el.parentNode.removeChild(el);
            }
          }, 360);
        });
      }, 3500);
    })();
  </script>
  <script>
    (function() {
      setInterval(function() {
        window.location.reload();
      }, 60000);
    })();
  </script>
<?php render_footer(['isAdmin' => true]); ?>
