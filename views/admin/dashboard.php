<?php
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../layout/app.php';
require_admin();

$db = get_db();
ensure_order_qr_schema($db);
ensure_order_attendee_checkin_schema($db);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['success' => '', 'error' => ''];
    $dashboardAction = trim((string)($_POST['dashboard_action'] ?? 'order_decision'));

    if ($dashboardAction === 'change_admin_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $adminId = (int)($_SESSION['admin_id'] ?? 0);

        if ($adminId <= 0) {
            $flash['error'] = 'Session admin tidak valid. Silakan login ulang.';
        } elseif ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $flash['error'] = 'Semua field password wajib diisi.';
        } elseif ($newPassword !== $confirmPassword) {
            $flash['error'] = 'Konfirmasi password baru tidak cocok.';
        } else {
            try {
                $adminStmt = $db->prepare('SELECT id, password_hash FROM admins WHERE id = ? LIMIT 1');
                $adminStmt->execute([$adminId]);
                $adminRow = $adminStmt->fetch(PDO::FETCH_ASSOC);

                if (!$adminRow) {
                    $flash['error'] = 'Admin tidak ditemukan.';
                } elseif (!password_verify($currentPassword, (string)($adminRow['password_hash'] ?? ''))) {
                    $flash['error'] = 'Password saat ini salah.';
                } elseif (password_verify($newPassword, (string)($adminRow['password_hash'] ?? ''))) {
                    $flash['error'] = 'Password baru harus berbeda dari password saat ini.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    if (!$newHash) {
                        $flash['error'] = 'Gagal memproses password baru.';
                    } else {
                        $updStmt = $db->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
                        $updStmt->execute([$newHash, $adminId]);
                        $flash['success'] = 'Password admin berhasil diperbarui.';
                    }
                }
            } catch (Throwable $e) {
                $flash['error'] = 'Gagal memperbarui password admin.';
            }
        }
    } elseif ($dashboardAction === 'create_sponsor') {
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
                if ($finfo) { $mime = (string)finfo_file($finfo, $tmpPath); finfo_close($finfo); }
            }
            $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
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
                            $db->exec("CREATE TABLE IF NOT EXISTS sponsors (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, website_url VARCHAR(255) NULL, logo_path VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                            $insertSponsor = $db->prepare('INSERT INTO sponsors (name, website_url, logo_path, created_at) VALUES (?, ?, ?, ?)');
                            $insertSponsor->execute([$sponsorName, $sponsorLink !== '' ? $sponsorLink : null, $storedLogoPath, date('Y-m-d H:i:s')]);
                            $flash['success'] = 'Sponsor added successfully.';
                        } catch (Throwable $e) {
                            if (is_file($targetPath)) @unlink($targetPath);
                            $flash['error'] = 'Failed to save sponsor data.';
                        }
                    }
                }
            }
        }
    } else {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $allowed = ['accept', 'reject'];
        if (!$orderId || !in_array($action, $allowed, true)) {
            $flash['error'] = 'Invalid request.';
        } else {
            $stmt = $db->prepare('SELECT o.id, o.status, o.payment_proof, o.qr_token, u.email, u.full_name FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?');
            $stmt->execute([$orderId]);
            $orderRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$orderRow) {
                $flash['error'] = 'Order not found.';
            } elseif (empty($orderRow['payment_proof'])) {
                $flash['error'] = 'Cannot update. Payment proof is required.';
            } elseif ($orderRow['status'] !== 'paid') {
                $flash['error'] = 'Only paid orders can be accepted or rejected.';
            } else {
                $newStatus = $action === 'accept' ? 'accepted' : 'rejected';
                if ($newStatus === 'accepted') {
                    $qrToken = extract_qr_token((string)($orderRow['qr_token'] ?? ''));
                    if ($qrToken === '') $qrToken = strtolower(bin2hex(random_bytes(24)));
                    $update = $db->prepare('UPDATE orders SET status = ?, qr_token = ?, qr_sent_at = ?, checked_in_at = NULL WHERE id = ?');
                    $update->execute([$newStatus, $qrToken, date('Y-m-d H:i:s'), $orderId]);
                    $orderRow['qr_token'] = $qrToken;
                    try { $db->prepare('UPDATE order_attendees SET checked_in_at = NULL WHERE order_id = ?')->execute([$orderId]); } catch (Throwable $e) {}
                } else {
                    $update = $db->prepare('UPDATE orders SET status = ?, qr_token = NULL, qr_sent_at = NULL, checked_in_at = NULL WHERE id = ?');
                    $update->execute([$newStatus, $orderId]);
                    $orderRow['qr_token'] = null;
                    try { $db->prepare('UPDATE order_attendees SET checked_in_at = NULL WHERE order_id = ?')->execute([$orderId]); } catch (Throwable $e) {}
                }
                $orderRow['status'] = $newStatus;
                $sent = send_order_status_email($orderRow, $orderRow['email']);
                $flash['success'] = $sent ? 'Order status updated and email sent.' : 'Order status updated, but email failed to send.';
            }
        }
    }

    $_SESSION['dashboard_flash'] = $flash;
    $redirectParams = [];
    if ($selectedPackage > 0) $redirectParams['package'] = $selectedPackage;
    if ($selectedOrderId > 0) $redirectParams['filter_order_id'] = $selectedOrderId;
    if ($selectedName !== '') $redirectParams['name'] = $selectedName;
    if ($selectedEmail !== '') $redirectParams['email'] = $selectedEmail;
    if ($selectedDate !== '') $redirectParams['created_date'] = $selectedDate;
    if ($selectedStatus !== '') $redirectParams['status'] = $selectedStatus;
    if ($selectedPage > 1) $redirectParams['page'] = $selectedPage;
    $redirectPath = '/admin/dashboard';
    if ($redirectParams) $redirectPath .= '?' . http_build_query($redirectParams);
    redirect($redirectPath);
}

$packages = $db->query("SELECT id, name FROM packages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$whereParts = ['1=1'];
$params = [];
if ($selectedOrderId > 0) { $whereParts[] = "o.id = ?"; $params[] = $selectedOrderId; }
if ($selectedPackage > 0) { $whereParts[] = "EXISTS (SELECT 1 FROM order_items oi JOIN packages p ON p.id = oi.package_id WHERE oi.order_id = o.id AND p.id = ?)"; $params[] = $selectedPackage; }
if ($selectedName !== '') { $whereParts[] = "u.full_name LIKE ?"; $params[] = '%' . $selectedName . '%'; }
if ($selectedEmail !== '') { $whereParts[] = "u.email LIKE ?"; $params[] = '%' . $selectedEmail . '%'; }
if ($selectedDate !== '') { $whereParts[] = "DATE(o.created_at) = ?"; $params[] = $selectedDate; }
if ($selectedStatus === 'accepted' || $selectedStatus === 'rejected') { $whereParts[] = "o.status = ?"; $params[] = $selectedStatus; }
elseif ($selectedStatus === 'pending') { $whereParts[] = "o.status IN ('pending', 'paid')"; }
$whereSql = ' WHERE ' . implode(' AND ', $whereParts);

$summarySql = "SELECT
    COALESCE(SUM(CASE WHEN o.status = 'accepted' THEN 1 ELSE 0 END), 0) AS accepted_orders,
    COALESCE(SUM(CASE WHEN o.status = 'accepted' THEN o.total ELSE 0 END), 0) AS total_revenue
    FROM orders o
    JOIN users u ON u.id = o.user_id" . $whereSql;
$summaryStmt = $db->prepare($summarySql);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalOrders = (int)($summary['accepted_orders'] ?? 0);
$totalRevenue = (int)($summary['total_revenue'] ?? 0);

$packageSalesMap = [];
foreach ($packages as $pkg) {
    $packageId = (int)($pkg['id'] ?? 0);
    if ($packageId <= 0) {
        continue;
    }
    $packageSalesMap[$packageId] = [
        'name' => (string)($pkg['name'] ?? '-'),
        'qty' => 0,
    ];
}

$packageSalesSql = "SELECT
    p.id AS package_id,
    COALESCE(SUM(CASE WHEN o.status = 'accepted' THEN oi.qty ELSE 0 END), 0) AS sold_qty
    FROM orders o
    JOIN users u ON u.id = o.user_id
    JOIN order_items oi ON oi.order_id = o.id
    JOIN packages p ON p.id = oi.package_id" . $whereSql . "
    GROUP BY p.id";
$packageSalesStmt = $db->prepare($packageSalesSql);
$packageSalesStmt->execute($params);
foreach ($packageSalesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $packageId = (int)($row['package_id'] ?? 0);
    if ($packageId <= 0 || !isset($packageSalesMap[$packageId])) {
        continue;
    }
    $packageSalesMap[$packageId]['qty'] = max(0, (int)($row['sold_qty'] ?? 0));
}
$packageSalesStats = array_values($packageSalesMap);

$countSql = "SELECT COUNT(*) AS total_records FROM orders o JOIN users u ON u.id = o.user_id" . $whereSql;
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$filteredOrderCount = (int)($countStmt->fetchColumn() ?: 0);

$perPage = 20;
$totalPages = max(1, (int)ceil($filteredOrderCount / $perPage));
$currentPage = min($selectedPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;

$sql = "SELECT o.id, u.full_name, u.phone, u.email, u.instagram, o.total, o.status, o.payment_proof, o.created_at, (SELECT GROUP_CONCAT(CONCAT(p.name, ' x', oi.qty) SEPARATOR ', ') FROM order_items oi JOIN packages p ON p.id = oi.package_id WHERE oi.order_id = o.id) as items FROM orders o JOIN users u ON u.id = o.user_id" . $whereSql . " ORDER BY o.created_at DESC, o.id DESC LIMIT ? OFFSET ?";

$stmt = $db->prepare($sql);
foreach ($params as $index => $value) { $stmt->bindValue($index + 1, $value); }
$stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$orderItemDetailsMap = [];
$orderTicketCountMap = [];
$orderAttendeeMap = [];
$orderIds = array_values(array_unique(array_map(static function ($row) { return (int)($row['id'] ?? 0); }, $orders)));

if ($orderIds) {
    $inPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemSql = "SELECT oi.order_id, p.name AS package_name, oi.qty, oi.price FROM order_items oi JOIN packages p ON p.id = oi.package_id WHERE oi.order_id IN ($inPlaceholders) ORDER BY oi.order_id ASC, p.name ASC";
    $itemStmt = $db->prepare($itemSql);
    foreach ($orderIds as $index => $orderId) { $itemStmt->bindValue($index + 1, $orderId, PDO::PARAM_INT); }
    $itemStmt->execute();
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $oid = (int)($row['order_id'] ?? 0);
        if ($oid <= 0) continue;
        if (!isset($orderItemDetailsMap[$oid])) $orderItemDetailsMap[$oid] = [];
        $qty = max(0, (int)($row['qty'] ?? 0));
        $price = max(0, (int)($row['price'] ?? 0));
        $orderItemDetailsMap[$oid][] = ['package_name' => (string)($row['package_name'] ?? ''), 'qty' => $qty, 'price' => $price, 'subtotal' => $qty * $price];
        $orderTicketCountMap[$oid] = ($orderTicketCountMap[$oid] ?? 0) + $qty;
    }
    try {
        $attendeeSql = "SELECT order_id, attendee_name, position_no, checked_in_at FROM order_attendees WHERE order_id IN ($inPlaceholders) ORDER BY order_id ASC, position_no ASC, id ASC";
        $attendeeStmt = $db->prepare($attendeeSql);
        foreach ($orderIds as $index => $orderId) { $attendeeStmt->bindValue($index + 1, $orderId, PDO::PARAM_INT); }
        $attendeeStmt->execute();
        foreach ($attendeeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $oid = (int)($row['order_id'] ?? 0);
            if ($oid <= 0) continue;
            if (!isset($orderAttendeeMap[$oid])) $orderAttendeeMap[$oid] = [];
            $orderAttendeeMap[$oid][] = ['position_no' => (int)($row['position_no'] ?? 0), 'attendee_name' => trim((string)($row['attendee_name'] ?? '')), 'checked_in_at' => (string)($row['checked_in_at'] ?? '')];
        }
    } catch (Throwable $e) { $orderAttendeeMap = []; }
}

$hasActiveFilters = $selectedPackage > 0 || $selectedOrderId > 0 || $selectedName !== '' || $selectedEmail !== '' || $selectedDate !== '' || $selectedStatus !== '';
$startRow = $filteredOrderCount > 0 ? ($offset + 1) : 0;
$endRow = min($offset + count($orders), $filteredOrderCount);
$paginationBaseParams = [];
if ($selectedOrderId > 0) $paginationBaseParams['filter_order_id'] = $selectedOrderId;
if ($selectedPackage > 0) $paginationBaseParams['package'] = $selectedPackage;
if ($selectedName !== '') $paginationBaseParams['name'] = $selectedName;
if ($selectedEmail !== '') $paginationBaseParams['email'] = $selectedEmail;
if ($selectedDate !== '') $paginationBaseParams['created_date'] = $selectedDate;
if ($selectedStatus !== '') $paginationBaseParams['status'] = $selectedStatus;

$extraHead = <<<'HTML'
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
  /* ─── Base ──────────────────────────────────────────────── */
  .admin-shell, .admin-shell *:not(.bi) {
    font-family: 'Plus Jakarta Sans', var(--font, sans-serif);
  }

  .admin-container-wide {
    max-width: 1480px;
    padding-inline: clamp(12px, 3vw, 40px);
  }

  /* ─── Page Header ────────────────────────────────────────── */
  .admin-header.spaced {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 20px 0 16px;
    border-bottom: 1px solid var(--stroke);
    margin-bottom: 20px;
  }

  .admin-title {
    font-size: clamp(20px, 3vw, 30px);
    font-weight: 800;
    letter-spacing: -0.6px;
    margin: 0 0 3px;
    line-height: 1.1;
  }

  .admin-sub { color: var(--muted); font-size: 13px; margin: 0; font-weight: 500; }

  .dashboard-head-actions {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }

  /* ─── Stat Grid ──────────────────────────────────────────── */
  .stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
  }

  .stat-card {
    position: relative;
    overflow: hidden;
    border: 1px solid var(--stroke);
    border-radius: 16px;
    background: var(--surface);
    padding: 18px 20px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    cursor: default;
    animation: statCardIn 0.4s ease-out both;
  }
  .stat-card:nth-child(1) { animation-delay: 0.05s; }
  .stat-card:nth-child(2) { animation-delay: 0.10s; }
  .stat-card:nth-child(3) { animation-delay: 0.15s; }
  .stat-card:nth-child(4) { animation-delay: 0.20s; }

  .stat-card::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, transparent 55%, rgba(0,102,255,0.035) 100%);
    pointer-events: none;
  }

  .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }

  .stat-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 8px;
  }
  .stat-label .bi { font-size: 13px; color: var(--primary); opacity: 0.75; }

  .stat-value {
    font-size: clamp(22px, 3.5vw, 34px);
    font-weight: 800;
    letter-spacing: -1px;
    line-height: 1;
    color: var(--text);
  }
  .stat-value.small { font-size: clamp(16px, 2.2vw, 24px); letter-spacing: -0.5px; }

  @keyframes statCardIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* ─── Filter Card ────────────────────────────────────────── */
  .filter-card {
    border-radius: 16px;
    border: 1px solid var(--stroke);
    background: var(--surface);
    padding: 18px 20px;
    margin-bottom: 16px;
  }

  /* Mobile filter toggle button */
  .filter-toggle-btn {
    display: none;
    width: 100%;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    background: none;
    border: none;
    padding: 0;
    font: inherit;
    cursor: pointer;
  }
  .filter-toggle-left {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    color: var(--muted);
  }
  .filter-toggle-left .bi { color: var(--primary); }

  .filter-active-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    padding: 0 5px;
  }
  .filter-toggle-caret {
    font-size: 14px;
    color: var(--muted);
    transition: transform 0.22s ease;
    flex-shrink: 0;
  }
  .filter-toggle-btn[aria-expanded="true"] .filter-toggle-caret {
    transform: rotate(180deg);
  }

  .filter-collapsible {
    overflow: hidden;
    transition: max-height 0.3s ease, opacity 0.22s ease;
  }

  .dashboard-filter-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
    align-items: end;
  }

  .dashboard-filter-form .filter-label {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    color: var(--muted);
    padding-bottom: 10px;
    border-bottom: 1.5px solid var(--stroke);
    margin-bottom: 2px;
  }
  .dashboard-filter-form .filter-label .bi { color: var(--primary); }

  .filter-field { display: grid; gap: 6px; }
  .filter-field-status { padding-right: 12px; }
  .filter-field-package { padding-left: 12px; }

  .field-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .dashboard-filter-form input,
  .dashboard-filter-form select {
    width: 100%;
    min-height: 44px;
    padding: 10px 13px;
    border-radius: 10px;
    border: 1.5px solid var(--stroke);
    font-size: 13.5px;
    font-family: inherit;
    background: var(--surface);
    color: var(--text);
    font-weight: 500;
    transition: border-color 0.18s, box-shadow 0.18s;
  }
  .dashboard-filter-form input::placeholder { color: var(--muted); opacity: 0.7; }
  .dashboard-filter-form input:focus,
  .dashboard-filter-form select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
  }

  .dashboard-filter-form .filter-actions {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    padding-top: 6px;
    border-top: 1px solid var(--stroke);
    margin-top: 2px;
  }

  /* ─── Flash Messages ─────────────────────────────────────── */
  .alert, .alert-success {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 13px 16px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 14px;
    animation: flashSlideIn 0.28s ease-out;
  }
  @keyframes flashSlideIn {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* ─── Pagination ─────────────────────────────────────────── */
  .pagination-wrap {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin: 12px 0;
  }
  .pagination-info { color: var(--muted); font-size: 12.5px; font-weight: 600; }
  .pagination {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: var(--surface);
    border: 1px solid var(--stroke);
    border-radius: 999px;
    padding: 5px 8px;
  }
  .pagination .btn {
    min-width: 34px;
    height: 34px;
    padding: 0 8px;
    border-radius: 999px;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
  }
  .pagination .btn.active {
    pointer-events: none;
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
    box-shadow: 0 2px 8px rgba(0,102,255,0.35);
  }
  .pagination .btn.is-disabled { pointer-events: none; opacity: 0.35; }

  /* ─── Desktop Table ──────────────────────────────────────── */
  .table-wrap {
    border-radius: 16px;
    border: 1px solid var(--stroke);
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,0.04);
  }

  table.admin-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
  }

  table.admin-table thead { background: var(--surface-2, #f5f7ff); }

  table.admin-table th {
    padding: 12px 13px;
    text-align: left;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    color: var(--muted);
    border-bottom: 1px solid var(--stroke);
    white-space: nowrap;
  }
  table.admin-table th .bi { font-size: 11px; opacity: 0.7; margin-right: 3px; }

  table.admin-table td {
    padding: 12px 13px;
    border-bottom: 1px solid var(--stroke);
    vertical-align: middle;
    color: var(--text);
    font-weight: 500;
  }

  table.admin-table tbody tr { transition: background 0.15s ease; }
  table.admin-table tbody tr:hover { background: rgba(0,102,255,0.025); }
  table.admin-table tbody tr:last-child td { border-bottom: none; }

  table.admin-table th:nth-child(3),
  table.admin-table td:nth-child(3) { width: 210px; }

  .admin-contact { display: grid; gap: 4px; }
  .admin-contact-line {
    display: grid;
    grid-template-columns: 14px minmax(0, 1fr);
    align-items: start;
    gap: 5px;
    font-size: 12px;
    color: var(--muted);
  }
  .admin-contact-line .bi { font-size: 11px; opacity: 0.65; margin-top: 1px; }
  .admin-contact-line .contact-value { overflow-wrap: anywhere; word-break: break-word; font-weight: 500; color: var(--text); }

  .table-empty { padding: 48px 20px !important; }
  .empty-state { display: flex; flex-direction: column; align-items: center; gap: 10px; color: var(--muted); font-size: 14px; font-weight: 600; }
  .empty-state .bi { font-size: 32px; opacity: 0.35; }

  /* ─── Badges ─────────────────────────────────────────────── */
  .badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
    white-space: nowrap;
    border: 1.5px solid transparent;
  }
  .badge .bi { font-size: 11px; }
  .badge.paid    { background: rgba(16,119,59,0.1);  color: #1a7a3c; border-color: rgba(16,119,59,0.2); }
  .badge.accepted { background: rgba(0,102,255,0.1); color: var(--primary); border-color: rgba(0,102,255,0.2); }
  .badge.rejected { background: rgba(211,47,47,0.08); color: #c0392b; border-color: rgba(211,47,47,0.18); }
  .badge.pending  { background: rgba(180,120,0,0.09); color: #8a6000; border-color: rgba(180,120,0,0.2); }

  /* ─── Proof Button ───────────────────────────────────────── */
  .proof-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 11px;
    border-radius: 8px;
    border: 1.5px solid var(--stroke);
    background: var(--surface);
    color: var(--primary);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.17s ease;
    white-space: nowrap;
    font-family: inherit;
  }
  .proof-link:hover { background: rgba(0,102,255,0.07); border-color: var(--primary); transform: translateY(-1px); }

  /* ─── Action Group ───────────────────────────────────────── */
  .action-group { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }

  .action-group .btn.small {
    padding: 5px 10px;
    font-size: 12px;
    border-radius: 8px;
    height: 32px;
    font-weight: 700;
    white-space: nowrap;
    transition: all 0.17s ease;
  }
  .action-group .btn.primary:not(:disabled):hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,102,255,0.3); }
  .action-group .btn.ghost:not(:disabled):hover { transform: translateY(-1px); }
  .action-group .btn:disabled { opacity: 0.38; cursor: not-allowed; }

  /* ══════════════════════════════════════════════════════════
     MOBILE CARD LAYOUT — replaces table on small screens
  ══════════════════════════════════════════════════════════ */
  .order-cards { display: none; }
  .order-card {
    border: 1px solid var(--stroke);
    border-radius: 14px;
    background: var(--surface);
    margin-bottom: 10px;
    overflow: hidden;
    transition: box-shadow 0.18s ease, border-color 0.18s ease;
  }
  .order-card:last-child { margin-bottom: 0; }
  .order-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.07); border-color: rgba(0,102,255,0.2); }

  .order-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 14px 10px;
    border-bottom: 1px solid var(--stroke);
    background: var(--surface-2, #f8faff);
  }

  .order-card-id {
    font-size: 15px;
    font-weight: 800;
    letter-spacing: -0.3px;
    color: var(--text);
  }

  .order-card-date {
    font-size: 11.5px;
    color: var(--muted);
    font-weight: 500;
    text-align: right;
    line-height: 1.35;
  }

  .order-card-body {
    padding: 12px 14px;
    display: grid;
    gap: 10px;
  }

  .order-card-user {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
  }

  .order-card-name {
    font-size: 14px;
    font-weight: 800;
    color: var(--text);
    margin: 0 0 4px;
  }

  .order-card-contact {
    display: grid;
    gap: 3px;
  }

  .order-card-contact-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--muted);
    font-weight: 500;
  }
  .order-card-contact-item .bi { font-size: 11px; opacity: 0.6; flex-shrink: 0; }
  .order-card-contact-item span { overflow-wrap: anywhere; color: var(--text); }

  .order-card-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 0;
    border-top: 1px solid var(--stroke);
  }

  .order-card-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--muted);
    flex-shrink: 0;
    padding-top: 1px;
  }

  .order-card-value {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    text-align: right;
    word-break: break-word;
  }

  .order-card-total {
    font-size: 16px;
    font-weight: 800;
    letter-spacing: -0.4px;
    color: var(--text);
  }

  .order-card-actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    padding: 10px 14px 12px;
    border-top: 1px solid var(--stroke);
    background: var(--surface-2, #f8faff);
  }

  .order-card-actions .btn {
    flex: 1;
    min-width: 0;
    justify-content: center;
    height: 38px;
    font-size: 12.5px;
    font-weight: 700;
    border-radius: 9px;
  }

  .order-card-actions .btn:disabled { opacity: 0.38; cursor: not-allowed; }

  /* ─── Sponsor Modal ──────────────────────────────────────── */
  .sponsor-modal {
    position: fixed;
    inset: 0;
    background: rgba(11,19,34,0.65);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1100;
    padding: clamp(12px, 2vw, 24px);
  }
  .sponsor-modal.show { display: flex; animation: modalFadeIn 0.2s ease-out; }

  .sponsor-modal-card {
    width: min(540px, 100%);
    max-height: min(88vh, 720px);
    background: var(--surface);
    border: 1px solid var(--stroke);
    border-radius: 20px;
    box-shadow: 0 28px 60px rgba(9,20,39,0.3);
    overflow: hidden;
    display: grid;
    grid-template-rows: auto 1fr;
    animation: modalCardIn 0.25s cubic-bezier(.18,.7,.2,1);
  }

  .sponsor-modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 18px;
    border-bottom: 1px solid var(--stroke);
    background: var(--surface-2, #f8faff);
  }
  .sponsor-modal-title { margin: 0; font-size: 16px; font-weight: 800; color: var(--text); display: inline-flex; align-items: center; gap: 8px; letter-spacing: -0.3px; }
  .sponsor-modal-title .bi { color: var(--primary); }
  .sponsor-modal-close {
    width: 32px; height: 32px; border-radius: 999px;
    border: 1.5px solid var(--stroke); background: var(--surface); color: var(--muted);
    font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
    transition: all 0.16s ease;
  }
  .sponsor-modal-close:hover { background: #eef4ff; border-color: #bfd2ff; color: var(--primary); }

  .sponsor-form { padding: 18px; display: grid; gap: 14px; overflow-y: auto; }
  .sponsor-field { display: grid; gap: 7px; }
  .sponsor-field label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
  .sponsor-field input[type="text"], .sponsor-field input[type="url"], .sponsor-field input[type="password"], .sponsor-field input[type="file"] {
    width: 100%; min-height: 46px; padding: 11px 13px; border-radius: 10px;
    border: 1.5px solid var(--stroke); font-size: 14px; font-family: inherit;
    background: var(--surface); color: var(--text); font-weight: 500; transition: border-color 0.18s, box-shadow 0.18s;
  }
  .sponsor-field input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,102,255,0.1); }
  .sponsor-field input[type="file"] { padding: 8px; cursor: pointer; background: #f7f9ff; font-size: 13px; }
  .sponsor-field input[type="file"]::file-selector-button { border: 0; border-radius: 999px; padding: 8px 14px; margin-right: 10px; background: #dfeaff; color: #0d3f98; font-weight: 700; font-size: 12px; cursor: pointer; }
  .sponsor-help { font-size: 11.5px; color: var(--muted); margin: 0; }
  .sponsor-form-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; padding-top: 4px; border-top: 1px solid var(--stroke); margin-top: 4px; }
  body.sponsor-modal-open { overflow: hidden; }

  /* ─── Order Detail Modal ─────────────────────────────────── */
  #orderDetailModal .proof-card { max-width: min(96vw, 1000px); border-radius: 20px; }
  #orderDetailModal .proof-head { padding: 14px 18px; background: var(--surface-2, #f8faff); }
  #orderDetailModal .proof-title { font-size: 18px; font-weight: 800; letter-spacing: -0.4px; }

  .order-detail-body { padding: 16px 18px 20px; max-height: calc(90vh - 70px); overflow-y: auto; }

  .detail-head { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid var(--stroke); }
  .detail-chip {
    display: inline-flex; align-items: center; gap: 5px; padding: 6px 11px;
    border-radius: 999px; border: 1.5px solid var(--stroke); background: var(--surface);
    font-size: 12px; line-height: 1.2;
  }
  .detail-chip .chip-label { color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; font-size: 10.5px; }
  .detail-chip .chip-value { color: var(--text); font-weight: 700; }

  .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .detail-box { border: 1px solid var(--stroke); border-radius: 12px; background: var(--surface); padding: 14px; }
  .detail-title { font-size: 13px; font-weight: 800; margin-bottom: 10px; color: var(--text); display: inline-flex; align-items: center; gap: 6px; }
  .detail-title .bi { color: var(--primary); font-size: 14px; }
  .detail-list { margin: 0; padding: 0; list-style: none; display: grid; gap: 6px; }
  .detail-list li { border: 1px solid var(--stroke); border-radius: 9px; background: var(--surface-2, #f8faff); padding: 9px 12px; font-size: 12.5px; line-height: 1.5; font-weight: 500; color: var(--text); }
  .detail-empty { color: var(--muted); font-size: 13px; padding: 4px 2px; font-weight: 500; }

  /* ─── Animations ─────────────────────────────────────────── */
  @keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }
  @keyframes modalCardIn { from { opacity: 0; transform: translateY(12px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }

  /* ══════════════════════════════════════════════════════════
     RESPONSIVE BREAKPOINTS
  ══════════════════════════════════════════════════════════ */

  /* Large desktop: 4-col stats */
  @media (max-width: 1200px) {
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
  }

  /* Tablet: collapse table → cards */
  @media (max-width: 900px) {
    .stat-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .detail-grid { grid-template-columns: 1fr; }
    #orderDetailModal .proof-title { font-size: 16px; }
    .order-detail-body { padding: 12px 14px 16px; }

    /* SWAP table for cards */
    .table-wrap { display: none; }
    .order-cards { display: block; }

    /* Show filter toggle, hide desktop label */
    .filter-toggle-btn { display: flex; }
    .dashboard-filter-form .filter-label { display: none; }
  }

  /* Mobile */
  @media (max-width: 640px) {
    .stat-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
    .stat-card { padding: 14px 16px; }
    .stat-value { font-size: 22px; }
    .stat-value.small { font-size: 16px; }

    .filter-card { padding: 14px 14px; }
    .dashboard-filter-form { grid-template-columns: 1fr; gap: 10px; }
    .filter-field-status,
    .filter-field-package { padding: 0; }
    .dashboard-filter-form .filter-actions { flex-direction: column; align-items: stretch; }
    .dashboard-filter-form .filter-actions .btn { width: 100%; justify-content: center; }

    .admin-header.spaced { flex-direction: column; align-items: flex-start; gap: 10px; }
    .dashboard-head-actions { width: 100%; }
    .dashboard-head-actions .btn { flex: 1; justify-content: center; }

    .pagination-wrap { flex-direction: column; align-items: flex-start; gap: 8px; }
    .pagination { width: 100%; justify-content: center; }

    .sponsor-modal-card { border-radius: 16px; max-height: 92vh; }
    .sponsor-form-actions { flex-direction: column; }
    .sponsor-form-actions .btn { width: 100%; justify-content: center; }

    .order-card-actions .btn { font-size: 12px; }
  }

  /* Extra small */
  @media (max-width: 400px) {
    .stat-grid { grid-template-columns: 1fr; }
    .admin-container-wide { padding-inline: 10px; }
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

      <!-- ── Page Header ─────────────────────────────────────── -->
      <div class="admin-header spaced">
        <div>
          <h1 class="admin-title">Dashboard</h1>
          <p class="admin-sub">Ringkasan pesanan dan status pembayaran</p>
        </div>
        <div class="dashboard-head-actions">
          <a class="btn ghost" href="/admin/scan"><i class="bi bi-qr-code-scan"></i> Scan QR</a>
          <button class="btn ghost" type="button" id="openPasswordModal">
            <i class="bi bi-key"></i> Ganti Password
          </button>
          <button class="btn primary" type="button" id="openSponsorModal">
            <i class="bi bi-building-add"></i> Tambah Sponsor
          </button>
        </div>
      </div>

      <!-- ── Stat Cards ──────────────────────────────────────── -->
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-label"><i class="bi bi-basket"></i> Total Orders Accepted</div>
          <div class="stat-value"><?= (int)$totalOrders ?></div>
        </div>
        <?php foreach ($packageSalesStats as $packageStat): ?>
          <div class="stat-card">
            <div class="stat-label"><i class="bi bi-box-seam"></i> <?= h($packageStat['name']) ?></div>
            <div class="stat-value"><?= (int)$packageStat['qty'] ?></div>
          </div>
        <?php endforeach; ?>
        <div class="stat-card">
          <div class="stat-label"><i class="bi bi-cash-stack"></i> Revenue Accepted</div>
          <div class="stat-value small"><?= h(rupiah($totalRevenue)) ?></div>
        </div>
      </div>

      <!-- ── Filter Card ─────────────────────────────────────── -->
      <div class="card filter-card">
        <!-- Mobile toggle (hidden on desktop via CSS) -->
        <button
          class="filter-toggle-btn"
          id="filterToggleBtn"
          type="button"
          aria-expanded="false"
          aria-controls="filterCollapsible"
        >
          <span class="filter-toggle-left">
            <i class="bi bi-funnel-fill"></i>
            Filter Orders
            <?php if ($hasActiveFilters): ?>
              <span class="filter-active-count"><?php
                $fc = (int)($selectedOrderId > 0) + (int)($selectedName !== '') + (int)($selectedEmail !== '') + (int)($selectedDate !== '') + (int)($selectedStatus !== '') + (int)($selectedPackage > 0);
                echo $fc;
              ?></span>
            <?php endif; ?>
          </span>
          <i class="bi bi-chevron-down filter-toggle-caret"></i>
        </button>

        <div class="filter-collapsible" id="filterCollapsible">
        <form method="get" class="dashboard-filter-form" id="dashboardFilterForm">
          <div class="filter-label"><i class="bi bi-funnel-fill"></i> Filter Orders</div>
          <div class="filter-field">
            <label class="field-label" for="filterOrderId">Order ID</label>
            <input id="filterOrderId" type="text" name="filter_order_id" value="<?= $selectedOrderId > 0 ? (int)$selectedOrderId : '' ?>" placeholder="#ID">
          </div>
          <div class="filter-field">
            <label class="field-label" for="filterName">Nama</label>
            <input id="filterName" type="text" name="name" value="<?= h($selectedName) ?>" placeholder="Cari nama...">
          </div>
          <div class="filter-field">
            <label class="field-label" for="filterEmail">Email</label>
            <input id="filterEmail" type="email" name="email" value="<?= h($selectedEmail) ?>" placeholder="Cari email...">
          </div>
          <div class="filter-field">
            <label class="field-label" for="filterDate">Tanggal</label>
            <input id="filterDate" type="date" name="created_date" value="<?= h($selectedDate) ?>">
          </div>
          <div class="filter-field filter-field-status">
            <label class="field-label" for="filterStatus">Status</label>
            <select id="filterStatus" name="status">
              <option value="">Semua Status</option>
              <option value="pending" <?= $selectedStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
              <option value="accepted" <?= $selectedStatus === 'accepted' ? 'selected' : '' ?>>Accepted</option>
              <option value="rejected" <?= $selectedStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
          </div>
          <div class="filter-field filter-field-package">
            <label class="field-label" for="filterPackage">Package</label>
            <select id="filterPackage" name="package">
              <option value="0">Semua Package</option>
              <?php foreach ($packages as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $selectedPackage === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-actions">
            <button class="btn primary" type="submit"><i class="bi bi-search"></i> Terapkan</button>
            <?php if ($hasActiveFilters): ?>
              <a class="btn ghost" href="/admin/dashboard"><i class="bi bi-x-circle"></i> Reset</a>
              <span style="margin-left:auto;font-size:12px;color:var(--primary);font-weight:700;"><i class="bi bi-funnel-fill"></i> Filter aktif</span>
            <?php endif; ?>
          </div>
        </form>
        </div><!-- /.filter-collapsible -->
      </div>
      <?php if ($flash['error']): ?>
        <div class="alert mb-16"><i class="bi bi-exclamation-triangle-fill"></i> <?= h($flash['error']) ?></div>
      <?php endif; ?>
      <?php if ($flash['success']): ?>
        <div class="alert-success"><i class="bi bi-check-circle-fill"></i> <?= h($flash['success']) ?></div>
      <?php endif; ?>

      <!-- ── Pagination Top ──────────────────────────────────── -->
      <?php if ($totalPages > 1 || $filteredOrderCount > 0): ?>
      <div class="pagination-wrap">
        <div class="pagination-info">
          <?php if ($filteredOrderCount > 0): ?>
            Menampilkan <strong><?= (int)$startRow ?>–<?= (int)$endRow ?></strong> dari <strong><?= (int)$filteredOrderCount ?></strong> data
          <?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
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
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- ═══════════════════════════════════════════════════════
           DESKTOP: Standard Table (hidden on mobile via CSS)
      ═══════════════════════════════════════════════════════ -->
      <div class="table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th><i class="bi bi-hash"></i> ID</th>
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
              <tr><td colspan="9" class="table-empty"><div class="empty-state"><i class="bi bi-inbox"></i> Belum ada order</div></td></tr>
            <?php endif; ?>
            <?php foreach ($orders as $o):
              $canAction = !empty($o['payment_proof']) && $o['status'] === 'paid';
              $detailOrderId = (int)$o['id'];
              $detailPayload = ['order_id' => $detailOrderId, 'user_name' => (string)($o['full_name'] ?? ''), 'total' => (int)($o['total'] ?? 0), 'status' => (string)($o['status'] ?? ''), 'created_at' => (string)($o['created_at'] ?? ''), 'ticket_count' => (int)($orderTicketCountMap[$detailOrderId] ?? 0), 'items' => $orderItemDetailsMap[$detailOrderId] ?? [], 'attendees' => $orderAttendeeMap[$detailOrderId] ?? []];
            ?>
              <tr>
                <td><strong style="font-size:13.5px;letter-spacing:-0.3px;">#<?= (int)$o['id'] ?></strong></td>
                <td><strong style="font-size:13px;"><?= h($o['full_name']) ?></strong></td>
                <td class="admin-contact">
                  <div class="admin-contact-line"><i class="bi bi-telephone"></i><span class="contact-value"><?= h($o['phone']) ?></span></div>
                  <div class="admin-contact-line"><i class="bi bi-envelope"></i><span class="contact-value"><?= h($o['email']) ?></span></div>
                  <div class="admin-contact-line"><i class="bi bi-instagram"></i>
                    <?php $ig = trim((string)($o['instagram'] ?? '')); $ig = $ig !== '' ? '@' . ltrim($ig, '@') : '-'; ?>
                    <span class="contact-value"><?= h($ig) ?></span>
                  </div>
                </td>
                <td style="font-size:12px;color:var(--muted);font-weight:500;"><?= h($o['items'] ?? '-') ?></td>
                <td><strong style="font-size:13px;letter-spacing:-0.3px;"><?= h(rupiah((int)$o['total'])) ?></strong></td>
                <td>
                  <?php if ($o['status'] === 'paid'): ?><span class="badge paid"><i class="bi bi-check-circle"></i> Paid</span>
                  <?php elseif ($o['status'] === 'accepted'): ?><span class="badge accepted"><i class="bi bi-check-circle-fill"></i> Accepted</span>
                  <?php elseif ($o['status'] === 'rejected'): ?><span class="badge rejected"><i class="bi bi-x-circle"></i> Rejected</span>
                  <?php else: ?><span class="badge pending"><i class="bi bi-clock"></i> <?= h($o['status']) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($o['payment_proof']): ?>
                    <button class="proof-link" type="button" data-proof="/uploads/<?= h($o['payment_proof']) ?>" data-order="#<?= (int)$o['id'] ?>"><i class="bi bi-file-earmark-image"></i> View</button>
                  <?php else: ?><span style="color:var(--muted);font-size:12px;">—</span><?php endif; ?>
                </td>
                <td>
                  <div class="action-group">
                    <button class="btn ghost small" type="button" data-order-detail="<?= h(json_encode($detailPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"><i class="bi bi-info-circle"></i> Detail</button>
                    <button class="btn primary small" type="button" data-confirm-action="accept" data-order-id="<?= (int)$o['id'] ?>" data-proof="<?= $o['payment_proof'] ? '/uploads/' . h($o['payment_proof']) : '' ?>" <?= $canAction ? '' : 'disabled' ?>><i class="bi bi-check-circle"></i> Accept</button>
                    <button class="btn ghost small" type="button" data-confirm-action="reject" data-order-id="<?= (int)$o['id'] ?>" data-proof="<?= $o['payment_proof'] ? '/uploads/' . h($o['payment_proof']) : '' ?>" <?= $canAction ? '' : 'disabled' ?>><i class="bi bi-x-circle"></i> Reject</button>
                  </div>
                </td>
                <td style="font-size:11.5px;color:var(--muted);white-space:nowrap;font-weight:600;"><?= h(date('d M Y', strtotime($o['created_at']))) ?><br><span style="opacity:0.7;"><?= h(date('H:i', strtotime($o['created_at']))) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- ═══════════════════════════════════════════════════════
           MOBILE: Card Layout (shown on mobile via CSS)
      ═══════════════════════════════════════════════════════ -->
      <div class="order-cards">
        <?php if (!$orders): ?>
          <div style="text-align:center;padding:40px 20px;border:1px solid var(--stroke);border-radius:14px;background:var(--surface);">
            <div class="empty-state"><i class="bi bi-inbox"></i> Belum ada order</div>
          </div>
        <?php endif; ?>
        <?php foreach ($orders as $o):
          $canAction = !empty($o['payment_proof']) && $o['status'] === 'paid';
          $detailOrderId = (int)$o['id'];
          $detailPayload = ['order_id' => $detailOrderId, 'user_name' => (string)($o['full_name'] ?? ''), 'total' => (int)($o['total'] ?? 0), 'status' => (string)($o['status'] ?? ''), 'created_at' => (string)($o['created_at'] ?? ''), 'ticket_count' => (int)($orderTicketCountMap[$detailOrderId] ?? 0), 'items' => $orderItemDetailsMap[$detailOrderId] ?? [], 'attendees' => $orderAttendeeMap[$detailOrderId] ?? []];
          $ig = trim((string)($o['instagram'] ?? '')); $ig = $ig !== '' ? '@' . ltrim($ig, '@') : '-';
        ?>
          <div class="order-card">
            <!-- Card Head -->
            <div class="order-card-head">
              <div>
                <div class="order-card-id">#<?= (int)$o['id'] ?></div>
                <?php if ($o['status'] === 'paid'): ?><span class="badge paid" style="margin-top:4px;"><i class="bi bi-check-circle"></i> Paid</span>
                <?php elseif ($o['status'] === 'accepted'): ?><span class="badge accepted" style="margin-top:4px;"><i class="bi bi-check-circle-fill"></i> Accepted</span>
                <?php elseif ($o['status'] === 'rejected'): ?><span class="badge rejected" style="margin-top:4px;"><i class="bi bi-x-circle"></i> Rejected</span>
                <?php else: ?><span class="badge pending" style="margin-top:4px;"><i class="bi bi-clock"></i> <?= h($o['status']) ?></span>
                <?php endif; ?>
              </div>
              <div class="order-card-date">
                <?= h(date('d M Y', strtotime($o['created_at']))) ?><br><?= h(date('H:i', strtotime($o['created_at']))) ?>
              </div>
            </div>

            <!-- Card Body -->
            <div class="order-card-body">
              <!-- User info -->
              <div>
                <div class="order-card-name"><?= h($o['full_name']) ?></div>
                <div class="order-card-contact">
                  <div class="order-card-contact-item"><i class="bi bi-telephone"></i><span><?= h($o['phone']) ?></span></div>
                  <div class="order-card-contact-item"><i class="bi bi-envelope"></i><span><?= h($o['email']) ?></span></div>
                  <div class="order-card-contact-item"><i class="bi bi-instagram"></i><span><?= h($ig) ?></span></div>
                </div>
              </div>

              <!-- Packages row -->
              <div class="order-card-row">
                <div class="order-card-label">Paket</div>
                <div class="order-card-value" style="font-size:12.5px;color:var(--muted);"><?= h($o['items'] ?? '—') ?></div>
              </div>

              <!-- Total row -->
              <div class="order-card-row">
                <div class="order-card-label">Total</div>
                <div class="order-card-total"><?= h(rupiah((int)$o['total'])) ?></div>
              </div>

              <!-- Proof row -->
              <?php if ($o['payment_proof']): ?>
              <div class="order-card-row">
                <div class="order-card-label">Bukti</div>
                <div>
                  <button class="proof-link" type="button" data-proof="/uploads/<?= h($o['payment_proof']) ?>" data-order="#<?= (int)$o['id'] ?>"><i class="bi bi-file-earmark-image"></i> View Proof</button>
                </div>
              </div>
              <?php endif; ?>
            </div>

            <!-- Card Actions -->
            <div class="order-card-actions">
              <button class="btn ghost small" type="button" data-order-detail="<?= h(json_encode($detailPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"><i class="bi bi-info-circle"></i> Detail</button>
              <button class="btn primary small" type="button" data-confirm-action="accept" data-order-id="<?= (int)$o['id'] ?>" data-proof="<?= $o['payment_proof'] ? '/uploads/' . h($o['payment_proof']) : '' ?>" <?= $canAction ? '' : 'disabled' ?>><i class="bi bi-check-circle"></i> Accept</button>
              <button class="btn ghost small" type="button" data-confirm-action="reject" data-order-id="<?= (int)$o['id'] ?>" data-proof="<?= $o['payment_proof'] ? '/uploads/' . h($o['payment_proof']) : '' ?>" <?= $canAction ? '' : 'disabled' ?>><i class="bi bi-x-circle"></i> Reject</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- ── Pagination Bottom ───────────────────────────────── -->
      <?php if ($totalPages > 1 || $filteredOrderCount > 0): ?>
      <div class="pagination-wrap" style="margin-top:14px;">
        <div class="pagination-info">
          <?php if ($filteredOrderCount > 0): ?>
            Menampilkan <strong><?= (int)$startRow ?>–<?= (int)$endRow ?></strong> dari <strong><?= (int)$filteredOrderCount ?></strong> data
          <?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <a class="btn ghost small<?= $currentPage <= 1 ? ' is-disabled' : '' ?>" href="/admin/dashboard?<?= h(http_build_query($paginationBaseParams + ['page' => $prevPage])) ?>"><i class="bi bi-chevron-left"></i></a>
          <?php for ($page = $windowStart; $page <= $windowEnd; $page++): ?>
            <a class="btn ghost small<?= $page === $currentPage ? ' active' : '' ?>" href="/admin/dashboard?<?= h(http_build_query($paginationBaseParams + ['page' => $page])) ?>"><?= (int)$page ?></a>
          <?php endfor; ?>
          <a class="btn ghost small<?= $currentPage >= $totalPages ? ' is-disabled' : '' ?>" href="/admin/dashboard?<?= h(http_build_query($paginationBaseParams + ['page' => $nextPage])) ?>"><i class="bi bi-chevron-right"></i></a>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    </div>
  </main>

  <!-- ─── Modals ───────────────────────────────────────────── -->
  <div class="proof-modal" id="proofModal" aria-hidden="true">
    <div class="proof-card" role="dialog" aria-modal="true" aria-labelledby="proofTitle">
      <div class="proof-head">
        <div class="proof-title" id="proofTitle"><i class="bi bi-image"></i> Payment Proof</div>
        <div class="proof-actions">
          <button class="proof-btn" type="button" id="zoomOut"><i class="bi bi-dash-lg"></i></button>
          <button class="proof-btn" type="button" id="zoomReset"><i class="bi bi-arrow-counterclockwise"></i></button>
          <button class="proof-btn" type="button" id="zoomIn"><i class="bi bi-plus-lg"></i></button>
          <button class="proof-close" type="button" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
      </div>
      <div class="proof-body"><img id="proofImage" alt="Payment proof"></div>
    </div>
  </div>

  <div class="proof-modal" id="confirmModal" aria-hidden="true">
    <div class="proof-card" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
      <div class="proof-head">
        <div class="proof-title" id="confirmTitle"><i class="bi bi-question-circle"></i> Confirm Action</div>
        <div class="proof-actions">
          <button class="proof-btn" type="button" id="confirmZoomOut"><i class="bi bi-dash-lg"></i></button>
          <button class="proof-btn" type="button" id="confirmZoomReset"><i class="bi bi-arrow-counterclockwise"></i></button>
          <button class="proof-btn" type="button" id="confirmZoomIn"><i class="bi bi-plus-lg"></i></button>
          <button class="proof-close" type="button" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
      </div>
      <div class="confirm-text" id="confirmQuestion">Are you sure?</div>
      <div class="confirm-sub">Please review the payment proof below before confirming.</div>
      <div class="proof-body"><img id="confirmProofImage" alt="Payment proof"></div>
      <div class="confirm-actions">
        <button class="btn ghost" type="button" id="confirmCancel"><i class="bi bi-x-circle"></i> Tidak</button>
        <button class="btn primary" type="button" id="confirmSubmit"><i class="bi bi-check-circle"></i> Ya, Konfirmasi</button>
      </div>
    </div>
  </div>

  <div class="proof-modal" id="orderDetailModal" aria-hidden="true">
    <div class="proof-card" role="dialog" aria-modal="true" aria-labelledby="orderDetailTitle">
      <div class="proof-head">
        <div class="proof-title" id="orderDetailTitle"><i class="bi bi-receipt"></i> Order Detail</div>
        <div class="proof-actions"><button class="proof-close" type="button" aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
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
          <label for="sponsorLink">Link Website <span style="font-weight:400;text-transform:none;">(opsional)</span></label>
          <input id="sponsorLink" type="url" name="sponsor_link" placeholder="https://example.com">
        </div>
        <div class="sponsor-field">
          <label for="sponsorLogo">Logo Sponsor</label>
          <input id="sponsorLogo" type="file" name="sponsor_logo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
          <p class="sponsor-help"><i class="bi bi-info-circle"></i> Format: JPG, PNG, WEBP</p>
        </div>
        <div class="sponsor-form-actions">
          <button class="btn ghost" type="button" id="cancelSponsorModal"><i class="bi bi-x-circle"></i> Batal</button>
          <button class="btn primary" type="submit"><i class="bi bi-check-circle"></i> Simpan</button>
        </div>
      </form>
    </div>
  </div>

  <div class="sponsor-modal" id="passwordModal" aria-hidden="true">
    <div class="sponsor-modal-card" role="dialog" aria-modal="true" aria-labelledby="passwordModalTitle">
      <div class="sponsor-modal-head">
        <h2 class="sponsor-modal-title" id="passwordModalTitle"><i class="bi bi-shield-lock"></i> Ganti Password Admin</h2>
        <button class="sponsor-modal-close" type="button" id="closePasswordModal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form class="sponsor-form" method="post" action="/admin/dashboard" id="passwordForm">
        <input type="hidden" name="dashboard_action" value="change_admin_password">
        <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
        <input type="hidden" name="filter_order_id" value="<?= $selectedOrderId > 0 ? (int)$selectedOrderId : '' ?>">
        <input type="hidden" name="package" value="<?= (int)$selectedPackage ?>">
        <input type="hidden" name="name" value="<?= h($selectedName) ?>">
        <input type="hidden" name="email" value="<?= h($selectedEmail) ?>">
        <input type="hidden" name="created_date" value="<?= h($selectedDate) ?>">
        <input type="hidden" name="status" value="<?= h($selectedStatus) ?>">

        <div class="sponsor-field">
          <label for="currentPassword">Password Saat Ini</label>
          <input id="currentPassword" type="password" name="current_password" autocomplete="current-password" required>
        </div>
        <div class="sponsor-field">
          <label for="newPassword">Password Baru</label>
          <input id="newPassword" type="password" name="new_password" autocomplete="new-password" required>
        </div>
        <div class="sponsor-field">
          <label for="confirmPassword">Konfirmasi Password Baru</label>
          <input id="confirmPassword" type="password" name="confirm_password" autocomplete="new-password" required>
        </div>
        <div class="sponsor-form-actions">
          <button class="btn ghost" type="button" id="cancelPasswordModal"><i class="bi bi-x-circle"></i> Batal</button>
          <button class="btn primary" type="submit"><i class="bi bi-check-circle"></i> Simpan Password</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // ── Mobile filter toggle ───────────────────────────────────
    (function () {
      var btn = document.getElementById('filterToggleBtn');
      var collapsible = document.getElementById('filterCollapsible');
      if (!btn || !collapsible) return;

      function isDesktop() { return window.innerWidth > 900; }

      function setOpen(open) {
        if (isDesktop()) {
          collapsible.style.maxHeight = '';
          collapsible.style.opacity = '';
          btn.setAttribute('aria-expanded', 'true');
          return;
        }
        if (open) {
          collapsible.style.maxHeight = collapsible.scrollHeight + 200 + 'px';
          collapsible.style.opacity = '1';
          btn.setAttribute('aria-expanded', 'true');
        } else {
          collapsible.style.maxHeight = '0';
          collapsible.style.opacity = '0';
          btn.setAttribute('aria-expanded', 'false');
        }
      }

      // Open by default if filters are active
      var hasActive = <?= $hasActiveFilters ? 'true' : 'false' ?>;
      setOpen(hasActive);

      btn.addEventListener('click', function () {
        setOpen(btn.getAttribute('aria-expanded') !== 'true');
      });

      window.addEventListener('resize', function () {
        if (isDesktop()) {
          collapsible.style.maxHeight = '';
          collapsible.style.opacity = '';
        }
      });
    })();

    // ── Auto-submit filter ─────────────────────────────────────
    (function () {
      var form = document.getElementById('dashboardFilterForm');
      if (!form) return;
      var focusKey = 'adminDashboardFilterFocus';
      var textTimer = null;
      var textDelayMs = 600;

      function saveTypingState(el) {
        if (!el || !el.name) return;
        var cursor = typeof el.selectionStart === 'number' ? el.selectionStart : (typeof el.value === 'string' ? el.value.length : null);
        try { sessionStorage.setItem(focusKey, JSON.stringify({ name: el.name, cursor: cursor })); } catch (err) {}
      }

      function restoreTypingState() {
        var raw = null;
        try { raw = sessionStorage.getItem(focusKey); } catch (err) {}
        if (!raw) return;
        try {
          var data = JSON.parse(raw);
          if (!data || !data.name) return;
          var target = form.querySelector('[name="' + data.name + '"]');
          if (!target) return;
          target.focus();
          var max = target.value.length;
          var pos = typeof data.cursor === 'number' ? Math.max(0, Math.min(max, data.cursor)) : max;
          window.requestAnimationFrame(function () {
            if (typeof target.setSelectionRange === 'function') { try { target.setSelectionRange(pos, pos); return; } catch (err) {} }
            var val = target.value; target.value = ''; target.value = val;
          });
        } catch (err) {}
      }

      function submitNow() {
        var active = document.activeElement;
        if (active && form.contains(active)) saveTypingState(active);
        form.submit();
      }

      restoreTypingState();
      form.querySelectorAll('select,input[type="date"]').forEach(function (el) { el.addEventListener('change', submitNow); });
      form.querySelectorAll('input[type="text"],input[type="email"]').forEach(function (el) {
        el.addEventListener('input', function () { saveTypingState(el); if (textTimer) clearTimeout(textTimer); textTimer = setTimeout(submitNow, textDelayMs); });
        el.addEventListener('click', function () { saveTypingState(el); });
        el.addEventListener('keyup', function () { saveTypingState(el); });
        el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (textTimer) clearTimeout(textTimer); submitNow(); } });
      });
    })();
  </script>

  <script>
    // ── Change Password Modal ─────────────────────────────────
    (function () {
      var modal = document.getElementById('passwordModal');
      var openBtn = document.getElementById('openPasswordModal');
      var closeBtn = document.getElementById('closePasswordModal');
      var cancelBtn = document.getElementById('cancelPasswordModal');
      if (!modal || !openBtn || !closeBtn || !cancelBtn) return;
      function openModal() {
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('sponsor-modal-open');
        var c = document.getElementById('currentPassword');
        if (c) setTimeout(function () { c.focus(); }, 20);
      }
      function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('sponsor-modal-open');
      }
      openBtn.addEventListener('click', openModal);
      closeBtn.addEventListener('click', closeModal);
      cancelBtn.addEventListener('click', closeModal);
      modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('show')) closeModal(); });
    })();
  </script>

  <script>
    // ── Sponsor Modal ──────────────────────────────────────────
    (function () {
      var modal = document.getElementById('sponsorModal');
      var openBtn = document.getElementById('openSponsorModal');
      var closeBtn = document.getElementById('closeSponsorModal');
      var cancelBtn = document.getElementById('cancelSponsorModal');
      if (!modal || !openBtn || !closeBtn || !cancelBtn) return;
      function openModal() { modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('sponsor-modal-open'); var n = document.getElementById('sponsorName'); if (n) setTimeout(function () { n.focus(); }, 20); }
      function closeModal() { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('sponsor-modal-open'); }
      openBtn.addEventListener('click', openModal);
      closeBtn.addEventListener('click', closeModal);
      cancelBtn.addEventListener('click', closeModal);
      modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('show')) closeModal(); });
    })();
  </script>

  <script>
    // ── Order Detail Modal ─────────────────────────────────────
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

      function asCurrency(n) { return 'Rp ' + Number(n || 0).toLocaleString('id-ID'); }
      function formatDate(raw) { if (!raw) return '-'; var d = new Date(raw); return isNaN(d.getTime()) ? String(raw) : d.toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); }
      function countCheckedIn(arr) { return Array.isArray(arr) ? arr.filter(function(a) { return a && a.checked_in_at; }).length : 0; }
      function escapeHtml(t) { return String(t == null ? '' : t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
      function clearList(el) { while (el.firstChild) el.removeChild(el.firstChild); }
      function statusLabel(s) { return s === 'paid' ? 'Paid' : s === 'accepted' ? 'Accepted' : s === 'rejected' ? 'Rejected' : (s || '-'); }

      function openDetail(rawJson) {
        var payload = {}; try { payload = JSON.parse(rawJson || '{}'); } catch (err) {}
        var orderId = Number(payload.order_id || 0);
        title.innerHTML = '<i class="bi bi-receipt"></i> Order Detail #' + (orderId || '-');
        var ticketCount = Number(payload.ticket_count || 0);
        var attendeesArr = Array.isArray(payload.attendees) ? payload.attendees : [];
        var arrivedCount = countCheckedIn(attendeesArr);
        detailHead.innerHTML =
          '<div class="detail-chip"><span class="chip-label">User</span><span class="chip-value">' + escapeHtml(payload.user_name || '-') + '</span></div>' +
          '<div class="detail-chip"><span class="chip-label">Status</span><span class="chip-value">' + escapeHtml(statusLabel(payload.status || '')) + '</span></div>' +
          '<div class="detail-chip"><span class="chip-label">Tickets</span><span class="chip-value">' + ticketCount + '</span></div>' +
          '<div class="detail-chip"><span class="chip-label">Hadir</span><span class="chip-value">' + arrivedCount + '/' + ticketCount + '</span></div>' +
          '<div class="detail-chip"><span class="chip-label">Total</span><span class="chip-value">' + asCurrency(payload.total || 0) + '</span></div>' +
          '<div class="detail-chip"><span class="chip-label">Created</span><span class="chip-value">' + escapeHtml(formatDate(payload.created_at)) + '</span></div>';
        clearList(detailItems);
        var items = Array.isArray(payload.items) ? payload.items : [];
        items.forEach(function(it) {
          var li = document.createElement('li');
          var qty = Number(it && it.qty ? it.qty : 0), price = Number(it && it.price ? it.price : 0);
          li.textContent = (it && it.package_name ? String(it.package_name) : '-') + ' ×' + qty + ' @ ' + asCurrency(price) + ' = ' + asCurrency(Number(it && it.subtotal ? it.subtotal : qty * price));
          detailItems.appendChild(li);
        });
        detailItemsEmpty.style.display = items.length ? 'none' : 'block';
        clearList(detailAttendees);
        attendeesArr.forEach(function(at) {
          var li = document.createElement('li');
          var pos = Number(at && at.position_no ? at.position_no : 0);
          var name = at && at.attendee_name ? String(at.attendee_name) : '-';
          var checkedInAt = at && at.checked_in_at ? String(at.checked_in_at) : '';
          var arrived = checkedInAt ? 'Hadir' : 'Belum hadir';
          var arrivedColor = checkedInAt ? '#1f7a45' : '#b44';
          li.innerHTML = escapeHtml((pos > 0 ? '#' + pos + ' — ' : '') + name) + ' <span style="color:' + arrivedColor + ';font-weight:700;font-size:11.5px;">[' + arrived + ']</span>' + (checkedInAt ? ' <span style="color:#8a98b2;font-size:11px;">(' + escapeHtml(formatDate(checkedInAt)) + ')</span>' : '');
          detailAttendees.appendChild(li);
        });
        detailAttendeesEmpty.style.display = attendeesArr.length ? 'none' : 'block';
        modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false');
      }
      function closeDetail() { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); }
      document.querySelectorAll('[data-order-detail]').forEach(function(btn) { btn.addEventListener('click', function() { openDetail(btn.getAttribute('data-order-detail') || '{}'); }); });
      closeBtn.addEventListener('click', closeDetail);
      modal.addEventListener('click', function(e) { if (e.target === modal) closeDetail(); });
      document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && modal.classList.contains('show')) closeDetail(); });
    })();
  </script>

  <script>
    // ── Proof Modal ────────────────────────────────────────────
    (function() {
      var modal = document.getElementById('proofModal');
      var img = document.getElementById('proofImage');
      var title = document.getElementById('proofTitle');
      var closeBtn = modal.querySelector('.proof-close');
      var zoomInBtn = document.getElementById('zoomIn'), zoomOutBtn = document.getElementById('zoomOut'), zoomResetBtn = document.getElementById('zoomReset');
      var scale = 1, tx = 0, ty = 0, isDragging = false, sx = 0, sy = 0, minS = 1, maxS = 3, step = 0.2;

      function applyT() { img.style.transform = 'translate('+tx+'px,'+ty+'px) scale('+scale.toFixed(2)+')'; }
      function applyZ(n) { scale = Math.min(maxS, Math.max(minS, n)); if (scale===1){tx=0;ty=0;} applyT(); img.classList.toggle('zoomed',scale>1); zoomOutBtn.disabled=scale<=minS; zoomInBtn.disabled=scale>=maxS; }
      function open(src, label) { img.src=src; title.innerHTML='<i class="bi bi-image"></i> '+(label?'Proof '+label:'Payment Proof'); scale=1;tx=0;ty=0; img.style.transform='translate(0,0) scale(1)'; img.classList.remove('zoomed'); modal.classList.add('show'); modal.setAttribute('aria-hidden','false'); }
      function close() { modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); img.src=''; }

      document.querySelectorAll('.proof-link[data-proof]').forEach(function(btn) { btn.addEventListener('click', function() { open(btn.getAttribute('data-proof'), btn.getAttribute('data-order')); }); });
      zoomInBtn.addEventListener('click', function(){applyZ(scale+step);}); zoomOutBtn.addEventListener('click', function(){applyZ(scale-step);}); zoomResetBtn.addEventListener('click', function(){applyZ(1);});
      img.addEventListener('click', function(){if(!img.classList.contains('zoomed'))applyZ(1.6);});
      img.addEventListener('wheel', function(e){e.preventDefault();applyZ(scale+(e.deltaY>0?-step:step));},{passive:false});
      img.addEventListener('mousedown', function(e){if(scale<=1)return;isDragging=true;img.classList.add('dragging');sx=e.clientX-tx;sy=e.clientY-ty;});
      window.addEventListener('mousemove', function(e){if(!isDragging)return;tx=e.clientX-sx;ty=e.clientY-sy;applyT();});
      window.addEventListener('mouseup', function(){if(!isDragging)return;isDragging=false;img.classList.remove('dragging');});
      closeBtn.addEventListener('click', close); modal.addEventListener('click', function(e){if(e.target===modal)close();}); document.addEventListener('keydown', function(e){if(e.key==='Escape'&&modal.classList.contains('show'))close();});
    })();
  </script>

  <script>
    // ── Confirm Modal ──────────────────────────────────────────
    (function() {
      var modal = document.getElementById('confirmModal');
      var img = document.getElementById('confirmProofImage');
      var title = document.getElementById('confirmTitle');
      var question = document.getElementById('confirmQuestion');
      var closeBtn = modal.querySelector('.proof-close');
      var zoomInBtn = document.getElementById('confirmZoomIn'), zoomOutBtn = document.getElementById('confirmZoomOut'), zoomResetBtn = document.getElementById('confirmZoomReset');
      var cancelBtn = document.getElementById('confirmCancel'), submitBtn = document.getElementById('confirmSubmit');
      var form = document.getElementById('confirmForm');
      var orderInput = document.getElementById('confirmOrderId'), actionInput = document.getElementById('confirmAction');
      var scale = 1, tx = 0, ty = 0, isDragging = false, sx = 0, sy = 0, minS = 1, maxS = 3, step = 0.2;

      function applyT() { img.style.transform = 'translate('+tx+'px,'+ty+'px) scale('+scale.toFixed(2)+')'; }
      function applyZ(n) { scale=Math.min(maxS,Math.max(minS,n)); if(scale===1){tx=0;ty=0;} applyT(); img.classList.toggle('zoomed',scale>1); zoomOutBtn.disabled=scale<=minS; zoomInBtn.disabled=scale>=maxS; }
      function open(src, orderId, action) {
        img.src=src; img.alt='Payment proof #'+orderId;
        title.innerHTML=(action==='accept'?'<i class="bi bi-check-circle-fill" style="color:#1a7a3c"></i> Konfirmasi Accept':'<i class="bi bi-x-circle-fill" style="color:#c0392b"></i> Konfirmasi Reject')+' #'+orderId;
        if(question)question.textContent=action==='accept'?'Apakah anda yakin ingin menerima order ini?':'Apakah anda yakin ingin menolak order ini?';
        orderInput.value=orderId; actionInput.value=action;
        scale=1;tx=0;ty=0; img.style.transform='translate(0,0) scale(1)'; img.classList.remove('zoomed');
        modal.classList.add('show'); modal.setAttribute('aria-hidden','false');
      }
      function close() { modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); img.src=''; }

      document.querySelectorAll('[data-confirm-action]').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var proof=btn.getAttribute('data-proof')||'';
          var orderId=btn.getAttribute('data-order-id')||'';
          var action=btn.getAttribute('data-confirm-action')||'';
          if(!proof||!orderId||!action)return;
          open(proof,orderId,action);
        });
      });

      zoomInBtn.addEventListener('click',function(){applyZ(scale+step);}); zoomOutBtn.addEventListener('click',function(){applyZ(scale-step);}); zoomResetBtn.addEventListener('click',function(){applyZ(1);});
      img.addEventListener('click',function(){if(!img.classList.contains('zoomed'))applyZ(1.6);});
      img.addEventListener('wheel',function(e){e.preventDefault();applyZ(scale+(e.deltaY>0?-step:step));},{passive:false});
      img.addEventListener('mousedown',function(e){if(scale<=1)return;isDragging=true;img.classList.add('dragging');sx=e.clientX-tx;sy=e.clientY-ty;});
      window.addEventListener('mousemove',function(e){if(!isDragging)return;tx=e.clientX-sx;ty=e.clientY-sy;applyT();});
      window.addEventListener('mouseup',function(){if(!isDragging)return;isDragging=false;img.classList.remove('dragging');});

      var isSubmitting = false;
      form.addEventListener('submit', function(e) { if(isSubmitting){e.preventDefault();return;} isSubmitting=true; submitBtn.disabled=true; submitBtn.innerHTML='<i class="bi bi-hourglass-split"></i> Processing...'; cancelBtn.disabled=true; closeBtn.disabled=true; });
      submitBtn.addEventListener('click', function() { form.submit(); });
      closeBtn.addEventListener('click', close); cancelBtn.addEventListener('click', close);
      modal.addEventListener('click',function(e){if(e.target===modal)close();}); document.addEventListener('keydown',function(e){if(e.key==='Escape'&&modal.classList.contains('show'))close();});
    })();
  </script>

  <script>
    // ── Flash Auto-dismiss ─────────────────────────────────────
    (function() {
      var alerts = document.querySelectorAll('.alert, .alert-success');
      if (!alerts.length) return;
      setTimeout(function() {
        alerts.forEach(function(el) {
          el.style.transition = 'opacity 0.35s ease, transform 0.35s ease';
          el.style.opacity = '0'; el.style.transform = 'translateY(-6px)';
          setTimeout(function() { if (el && el.parentNode) el.parentNode.removeChild(el); }, 360);
        });
      }, 3500);
    })();
  </script>
  
<?php render_footer(['isAdmin' => true]); ?>
