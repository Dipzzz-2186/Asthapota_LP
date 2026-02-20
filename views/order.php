<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
ensure_session();

if (empty($_SESSION['user_id'])) {
    redirect('/register?from=packages&notice=register_required');
}

$draft = $_SESSION['order_draft'] ?? null;
if (
    !$draft
    || !is_array($draft)
    || (int)($draft['user_id'] ?? 0) !== (int)$_SESSION['user_id']
    || empty($draft['items'])
) {
    redirect('/packages');
}

$db = get_db();
ensure_order_attendee_checkin_schema($db);
ensure_order_attendee_package_schema($db);
ensure_order_attendee_payment_schema($db);
$userStmt = $db->prepare('SELECT id, full_name, phone, email, instagram, gender FROM users WHERE id = ?');
$userStmt->execute([(int)$_SESSION['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    unset($_SESSION['user_id'], $_SESSION['order_draft'], $_SESSION['order_id']);
    redirect('/register?from=packages');
}

$items = [];
$total = 0;
$totalTickets = 0;
foreach ((array)$draft['items'] as $it) {
    $packageId = (int)($it['package_id'] ?? 0);
    $qty = max(0, (int)($it['qty'] ?? 0));
    $price = max(0, (int)($it['price'] ?? 0));
    $name = trim((string)($it['name'] ?? ''));
    if ($packageId <= 0 || $qty <= 0 || $price <= 0 || $name === '') {
        continue;
    }
    $items[] = [
        'package_id' => $packageId,
        'qty' => $qty,
        'price' => $price,
        'name' => $name,
    ];
    $total += $qty * $price;
    $totalTickets += $qty;
}

if (!$items || $total <= 0 || $totalTickets <= 0) {
    unset($_SESSION['order_draft']);
    redirect('/packages');
}

$instagramLabel = '';
if (!empty($user['instagram'])) {
    $instagramLabel = '@' . ltrim((string)$user['instagram'], '@');
}

$errors = [];
$additionalAttendeeCount = max(0, $totalTickets - 1);
$attendeeNames = array_fill(0, $additionalAttendeeCount, '');
$attendeeGenders = array_fill(0, $additionalAttendeeCount, '');
$attendeePackageIds = array_fill(0, $additionalAttendeeCount, 0);
$attendeeProofNames = array_fill(0, $totalTickets, '');
$allowedAttendeeGenders = ['Laki-laki', 'Perempuan'];
$packageTicketCounts = [];
$packageNamesById = [];
$packageIdOrder = [];
foreach ($items as $it) {
    $pkgId = (int)($it['package_id'] ?? 0);
    $pkgQty = max(0, (int)($it['qty'] ?? 0));
    if ($pkgId <= 0 || $pkgQty <= 0) {
        continue;
    }
    if (!isset($packageTicketCounts[$pkgId])) {
        $packageTicketCounts[$pkgId] = 0;
        $packageNamesById[$pkgId] = (string)($it['name'] ?? ('Package #' . $pkgId));
        $packageIdOrder[] = $pkgId;
    }
    $packageTicketCounts[$pkgId] += $pkgQty;
}
$requiresPackageSelection = count($packageTicketCounts) > 1;
$defaultPackageId = (int)($packageIdOrder[0] ?? 0);
$ownerPackageId = $defaultPackageId;

$normalizeAttendeeGender = static function ($raw) use ($allowedAttendeeGenders): string {
    $value = trim((string)$raw);
    if (in_array($value, $allowedAttendeeGenders, true)) {
        return $value;
    }
    $lower = strtolower($value);
    if (in_array($lower, ['male', 'm', 'laki-laki', 'laki', 'pria'], true)) {
        return 'Laki-laki';
    }
    if (in_array($lower, ['female', 'f', 'perempuan', 'wanita'], true)) {
        return 'Perempuan';
    }
    return '';
};
$ownerGender = $normalizeAttendeeGender((string)($user['gender'] ?? ''));
if ($ownerGender === '') {
    $ownerGender = 'Laki-laki';
}

if ($additionalAttendeeCount > 0 && isset($_SESSION['order_draft']['attendee_names']) && is_array($_SESSION['order_draft']['attendee_names'])) {
    for ($i = 0; $i < $additionalAttendeeCount; $i++) {
        $attendeeNames[$i] = trim((string)($_SESSION['order_draft']['attendee_names'][$i] ?? ''));
    }
}
if ($additionalAttendeeCount > 0 && isset($_SESSION['order_draft']['attendee_genders']) && is_array($_SESSION['order_draft']['attendee_genders'])) {
    for ($i = 0; $i < $additionalAttendeeCount; $i++) {
        $genderInput = (string)($_SESSION['order_draft']['attendee_genders'][$i] ?? '');
        $attendeeGenders[$i] = $normalizeAttendeeGender($genderInput);
    }
}
if ($requiresPackageSelection && $additionalAttendeeCount > 0 && isset($_SESSION['order_draft']['attendee_package_ids']) && is_array($_SESSION['order_draft']['attendee_package_ids'])) {
    for ($i = 0; $i < $additionalAttendeeCount; $i++) {
        $pid = (int)($_SESSION['order_draft']['attendee_package_ids'][$i] ?? 0);
        $attendeePackageIds[$i] = isset($packageTicketCounts[$pid]) ? $pid : 0;
    }
}
if ($requiresPackageSelection) {
    $ownerDraftPackageId = (int)($_SESSION['order_draft']['owner_package_id'] ?? 0);
    $ownerPackageId = isset($packageTicketCounts[$ownerDraftPackageId]) ? $ownerDraftPackageId : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawPackages = $_POST['attendee_package_ids'] ?? [];
    if (!is_array($rawPackages)) {
        $rawPackages = [];
    }
    $rawOwnerPackage = (int)($_POST['owner_package_id'] ?? 0);

    if ($requiresPackageSelection) {
        $ownerPackageId = $rawOwnerPackage;
        if ($ownerPackageId <= 0 || !isset($packageTicketCounts[$ownerPackageId])) {
            $errors[] = 'Please select a valid package for attendee #1.';
        }
    } else {
        $ownerPackageId = $defaultPackageId;
    }

    if ($additionalAttendeeCount > 0) {
        $rawNames = $_POST['attendee_names'] ?? [];
        if (!is_array($rawNames)) {
            $rawNames = [];
        }
        $rawGenders = $_POST['attendee_genders'] ?? [];
        if (!is_array($rawGenders)) {
            $rawGenders = [];
        }
        for ($i = 0; $i < $additionalAttendeeCount; $i++) {
            $nameInput = trim((string)($rawNames[$i] ?? ''));
            $attendeeNames[$i] = $nameInput;
            $genderInput = (string)($rawGenders[$i] ?? '');
            $attendeeGenders[$i] = $normalizeAttendeeGender($genderInput);
            if ($nameInput === '') {
                $errors[] = 'Please fill in attendee name #' . ($i + 2) . '.';
            } elseif (strlen($nameInput) > 120) {
                $errors[] = 'Attendee name #' . ($i + 2) . ' is too long (max 120 characters).';
            }
            if ($attendeeGenders[$i] === '') {
                $errors[] = 'Please select gender for attendee #' . ($i + 2) . '.';
            }
            if ($requiresPackageSelection) {
                $packageIdInput = (int)($rawPackages[$i] ?? 0);
                $attendeePackageIds[$i] = $packageIdInput;
                if ($packageIdInput <= 0 || !isset($packageTicketCounts[$packageIdInput])) {
                    $errors[] = 'Please select a valid package for attendee #' . ($i + 2) . '.';
                }
            } else {
                $attendeePackageIds[$i] = $defaultPackageId;
            }
        }
    }

    if ($requiresPackageSelection) {
        $usedPackageCounts = [];
        if ($ownerPackageId > 0) {
            $usedPackageCounts[$ownerPackageId] = (int)($usedPackageCounts[$ownerPackageId] ?? 0) + 1;
        }
        foreach ($attendeePackageIds as $pkgId) {
            if ($pkgId <= 0) {
                continue;
            }
            $usedPackageCounts[$pkgId] = (int)($usedPackageCounts[$pkgId] ?? 0) + 1;
        }
        foreach ($usedPackageCounts as $pkgId => $usedCount) {
            if ($usedCount > (int)($packageTicketCounts[$pkgId] ?? 0)) {
                $pkgLabel = (string)($packageNamesById[$pkgId] ?? ('Package #' . $pkgId));
                $errors[] = 'Assigned attendees for package "' . $pkgLabel . '" exceed purchased quantity.';
            }
        }
    }

    $proofUploadCandidates = [];
    $proofInput = $_FILES['attendee_payment_proofs'] ?? null;
    if ($totalTickets <= 0) {
        $errors[] = 'Unable to determine the attendee count for payment proof upload.';
    } elseif (
        !is_array($proofInput)
        || !isset($proofInput['error'])
        || !is_array($proofInput['error'])
        || !isset($proofInput['tmp_name'])
        || !is_array($proofInput['tmp_name'])
    ) {
        $errors[] = 'Please upload payment proof for each attendee.';
    } else {
        $allowedProofExts = ['jpg', 'jpeg', 'png'];
        for ($i = 0; $i < $totalTickets; $i++) {
            $labelNo = $i + 1;
            $errorCode = $proofInput['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($errorCode !== UPLOAD_ERR_OK) {
                $errors[] = 'Please upload a valid payment proof for attendee #' . $labelNo . '.';
                continue;
            }
            $tmpName = (string)($proofInput['tmp_name'][$i] ?? '');
            $size = (int)($proofInput['size'][$i] ?? 0);
            $originalName = trim((string)($proofInput['name'][$i] ?? ''));
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                $errors[] = 'Please upload a valid payment proof for attendee #' . $labelNo . '.';
                continue;
            }
            if ($size > 2 * 1024 * 1024) {
                $errors[] = 'Payment proof for attendee #' . $labelNo . ' is too large. Max 2MB.';
                continue;
            }
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedProofExts, true)) {
                $errors[] = 'Only JPG or PNG allowed for attendee #' . $labelNo . '.';
                continue;
            }
            $proofUploadCandidates[$i] = [
                'tmp_name' => $tmpName,
                'ext' => $ext,
            ];
        }
    }

    $_SESSION['order_draft']['attendee_names'] = $attendeeNames;
    $_SESSION['order_draft']['attendee_genders'] = $attendeeGenders;
    $_SESSION['order_draft']['attendee_package_ids'] = $attendeePackageIds;
    $_SESSION['order_draft']['owner_package_id'] = $ownerPackageId;

    if (!$errors) {
        $uploadDir = $CONFIG['upload_dir'];
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
            $errors[] = 'Failed to prepare upload directory for payment proofs.';
        }
    }

    if (!$errors) {
        $movedProofTargets = [];
        $proofTimestamp = time();
        foreach ($proofUploadCandidates as $index => $candidate) {
            $proofName = 'proof_u' . (int)$_SESSION['user_id'] . '_' . $proofTimestamp . '_' . ($index + 1) . '_' . mt_rand(1000, 9999) . '.' . $candidate['ext'];
            $target = $uploadDir . '/' . $proofName;
            if (!move_uploaded_file($candidate['tmp_name'], $target)) {
                $errors[] = 'Failed to upload payment proof for attendee #' . ($index + 1) . '.';
                break;
            }
            $movedProofTargets[] = $target;
            $attendeeProofNames[$index] = $proofName;
        }

        if ($errors) {
            foreach ($movedProofTargets as $uploadedPath) {
                if (is_file($uploadedPath)) {
                    @unlink($uploadedPath);
                }
            }
        }
    }

    if (!$errors) {
        $orderId = 0;
        $proofPayload = array_values(array_filter($attendeeProofNames, static fn ($path) => $path !== ''));
        $primaryProof = $proofPayload[0] ?? null;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare('INSERT INTO orders (user_id, total, status, payment_proof, created_at) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([(int)$_SESSION['user_id'], (int)$total, 'paid', $primaryProof, date('c')]);
            $orderId = (int)$db->lastInsertId();

            $itemStmt = $db->prepare('INSERT INTO order_items (order_id, package_id, qty, price) VALUES (?, ?, ?, ?)');
            foreach ($items as $it) {
                $itemStmt->execute([$orderId, $it['package_id'], $it['qty'], $it['price']]);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            foreach ($movedProofTargets as $uploadedPath) {
                if (is_file($uploadedPath)) {
                    @unlink($uploadedPath);
                }
            }
            $errors[] = 'Failed to save your order. Please try again.';
        }

        if ($orderId > 0) {
            if ($ownerPackageId <= 0) {
                $ownerPackageId = $defaultPackageId;
            }

            $attendeeRows = [
                [
                    'name' => (string)$user['full_name'],
                    'gender' => $ownerGender,
                    'position_no' => 1,
                    'package_id' => $ownerPackageId > 0 ? (int)$ownerPackageId : null,
                    'payment_proof' => $attendeeProofNames[0] ?? null,
                ],
            ];
            foreach ($attendeeNames as $idx => $attendeeName) {
                $attendeeRows[] = [
                    'name' => (string)$attendeeName,
                    'gender' => (string)($attendeeGenders[$idx] ?? ''),
                    'position_no' => $idx + 2,
                    'package_id' => (int)($attendeePackageIds[$idx] ?? 0) > 0 ? (int)$attendeePackageIds[$idx] : null,
                    'payment_proof' => $attendeeProofNames[$idx + 1] ?? null,
                ];
            }
            $attendeeDetailsForAdmin = [];
            foreach ($attendeeRows as $row) {
                $pkgId = (int)($row['package_id'] ?? 0);
                $attendeeDetailsForAdmin[] = [
                    'name' => (string)($row['name'] ?? ''),
                    'position_no' => (int)($row['position_no'] ?? 0),
                    'package' => (string)($packageNamesById[$pkgId] ?? ($pkgId > 0 ? ('Package #' . $pkgId) : '-')),
                    'payment_proof' => (string)($row['payment_proof'] ?? ''),
                ];
            }

            try {
                $attendeeStmt = $db->prepare('INSERT INTO order_attendees (order_id, attendee_name, gender, position_no, package_id, payment_proof, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
                foreach ($attendeeRows as $row) {
                    $attendeeStmt->execute([$orderId, $row['name'], $row['gender'], $row['position_no'], $row['package_id'], $row['payment_proof'], date('Y-m-d H:i:s')]);
                }
            } catch (Throwable $e) {
                // Fallback for older attendee schema without package_id.
                try {
                    $attendeeStmt = $db->prepare('INSERT INTO order_attendees (order_id, attendee_name, gender, position_no, payment_proof, created_at) VALUES (?, ?, ?, ?, ?, ?)');
                    foreach ($attendeeRows as $row) {
                        $attendeeStmt->execute([$orderId, $row['name'], $row['gender'], $row['position_no'], $row['payment_proof'], date('Y-m-d H:i:s')]);
                    }
                } catch (Throwable $e) {
                    // Fallback for older attendee schema with attendee_gender.
                    try {
                        $attendeeStmt = $db->prepare('INSERT INTO order_attendees (order_id, attendee_name, attendee_gender, position_no, payment_proof, created_at) VALUES (?, ?, ?, ?, ?, ?)');
                        foreach ($attendeeRows as $row) {
                            $legacyGender = $row['position_no'] === 1 ? null : $row['gender'];
                            $attendeeStmt->execute([$orderId, $row['name'], $legacyGender, $row['position_no'], $row['payment_proof'], date('Y-m-d H:i:s')]);
                        }
                    } catch (Throwable $e) {
                        // Keep order success even when attendee table does not exist.
                    }
                }
            }

            unset($_SESSION['order_draft']);
            $proofPayloadForNotifications = $proofPayload;
            send_invoice_email([
                'id' => $orderId,
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'instagram' => $instagramLabel !== '' ? $instagramLabel : '-',
                'status' => 'paid',
                'total' => $total,
                'payment_proof' => $primaryProof,
                'payment_proofs' => $proofPayloadForNotifications,
                'created_at' => date('Y-m-d H:i:s'),
            ], $items, (string)$user['email']);
            notify_admins_new_paid_order($db, [
                'id' => $orderId,
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'instagram' => $instagramLabel !== '' ? $instagramLabel : '-',
                'status' => 'paid',
                'total' => $total,
                'payment_proof' => $primaryProof,
                'payment_proofs' => $proofPayloadForNotifications,
                'created_at' => date('Y-m-d H:i:s'),
            ], $items, $attendeeDetailsForAdmin);
            redirect('/thankyou?order=' . $orderId);
        }
    }
}

$attendeeProofInputs = [];
$ownerDisplay = trim((string)$user['full_name']);
$ownerLabel = 'Attendee #1' . ($ownerDisplay !== '' ? ' — ' . $ownerDisplay : ' (Pemilik akun)');
$attendeeProofInputs[] = ['index' => 0, 'label' => $ownerLabel];
for ($i = 0; $i < $additionalAttendeeCount; $i++) {
    $displayName = trim((string)($attendeeNames[$i] ?? ''));
    $label = 'Attendee #' . ($i + 2);
    if ($displayName !== '') {
        $label .= ' — ' . $displayName;
    }
    $attendeeProofInputs[] = ['index' => $i + 1, 'label' => $label];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Order Details - Asthapora</title>
  <link rel="icon" type="image/png" href="/assets/img/LogoTitleAsthapora.png">
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Anton&family=Manrope:wght@400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,600;0,700;1,500&display=swap');
    @import url('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css');

    :root {
      --font-body: "Manrope", "Segoe UI", Tahoma, sans-serif;
      --font-display: "Anton", "Arial Narrow", Impact, sans-serif;
      --font-accent: "Playfair Display", Georgia, serif;
    }

    body {
      margin: 0;
      min-height: 100%;
      color: #eef4ff;
      font-family: var(--font-body);
      font-weight: 500;
      letter-spacing: 0.2px;
      background: url('/assets/img/wallpapeh.jpg') center/cover no-repeat fixed;
      overflow-x: hidden;
      opacity: 0;
      transform: translateY(14px) scale(0.99);
      filter: blur(8px);
      transition: opacity 0.55s ease, transform 0.55s ease, filter 0.55s ease;
    }

    body.page-ready {
      opacity: 1;
      transform: none;
      filter: none;
    }

    body.page-leaving {
      opacity: 0;
      transform: translateY(-10px) scale(0.99);
      filter: blur(8px);
      pointer-events: none;
      transition: opacity 0.28s ease, transform 0.28s ease, filter 0.28s ease;
    }

    .order-shell {
      min-height: 100vh;
      width: min(1260px, 95vw);
      margin: 0 auto;
      padding: 42px 0 56px;
      display: flex;
      align-items: center;
    }

    .order-full {
      width: 100%;
      padding: clamp(24px, 3.1vw, 42px);
      display: grid;
      grid-template-columns: 1.1fr 0.9fr;
      gap: 22px;
      background: rgba(23, 45, 79, 0.58);
      border: 1px solid rgba(255, 255, 255, 0.4);
      border-radius: 20px;
      backdrop-filter: blur(7px);
      box-shadow: 0 14px 34px rgba(0, 0, 0, 0.32);
      transition: transform 0.18s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    }

    .order-full:hover {
      transform: translateY(-3px);
      box-shadow: 0 18px 42px rgba(0, 0, 0, 0.35);
      border-color: rgba(255, 255, 255, 0.56);
    }

    .order-panel {
      background: rgba(25, 52, 91, 0.62);
      border: 1px solid rgba(255, 255, 255, 0.42);
      border-radius: 16px;
      padding: clamp(20px, 2.4vw, 34px);
      backdrop-filter: blur(3px);
      transition: transform 0.18s ease, border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .order-panel:hover {
      transform: translateY(-2px);
      border-color: rgba(255, 255, 255, 0.62);
      box-shadow: 0 14px 26px rgba(0, 0, 0, 0.24);
    }

    .section-title {
      margin-bottom: 16px;
      color: #fff;
      font-family: var(--font-accent);
      font-size: clamp(28px, 3.1vw, 40px);
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 10px;
    }

    .order-meta {
      display: grid;
      gap: 10px;
      color: #d6e3ff;
      line-height: 1.55;
    }

    .order-list {
      margin-top: 14px;
      display: grid;
      gap: 8px;
      color: #f7faff;
    }

    .total {
      margin-top: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 14px;
      padding-top: 12px;
      border-top: 1px solid rgba(255, 255, 255, 0.25);
      font-weight: 800;
      font-size: clamp(24px, 2.6vw, 34px);
      font-family: var(--font-display);
      letter-spacing: 0.8px;
      color: #fff;
    }

    .payment-card {
      background: rgba(255, 255, 255, 0.14);
      border: 1px solid rgba(255, 255, 255, 0.3);
      border-radius: 14px;
      padding: 16px;
      color: #eef4ff;
      transition: border-color 0.2s ease, background 0.2s ease;
    }

    .payment-card:hover {
      border-color: rgba(255, 255, 255, 0.55);
      background: rgba(255, 255, 255, 0.2);
    }

    .payment-card.qris-card {
      display: grid;
      gap: 10px;
      justify-items: center;
      text-align: center;
    }

    .qris-label {
      font-size: 14px;
      font-weight: 700;
      color: #f2f7ff;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .qris-image {
      width: min(100%, 300px);
      border-radius: 12px;
      border: 1px solid rgba(255, 255, 255, 0.4);
      background: #fff;
      padding: 8px;
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.22);
    }

    .qris-download {
      text-decoration: none;
      border: 1px solid rgba(255, 255, 255, 0.5);
      border-radius: 999px;
      padding: 10px 14px;
      font-size: 13px;
      font-weight: 700;
      color: #eef4ff;
      background: rgba(255, 255, 255, 0.12);
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: transform 0.15s ease, background 0.2s ease, border-color 0.2s ease;
    }

    .qris-download:hover {
      transform: translateY(-1px);
      background: rgba(255, 255, 255, 0.22);
      border-color: rgba(255, 255, 255, 0.72);
    }

    .upload-box {
      border: 2px dashed rgba(255, 255, 255, 0.45);
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.12);
      padding: 16px;
      transition: border-color 0.2s ease, background 0.2s ease;
    }

    .upload-box:hover {
      border-color: rgba(255, 255, 255, 0.7);
      background: rgba(255, 255, 255, 0.18);
    }

    .upload-box input[type="file"] {
      width: 100%;
      color: #fff;
    }

    .upload-box input[type="file"]::file-selector-button {
      border: 0;
      border-radius: 8px;
      padding: 10px 14px;
      margin-right: 10px;
      background: #fff;
      color: #0b2d61;
      font-weight: 700;
      cursor: pointer;
      transition: transform 0.15s ease, background 0.2s ease;
    }

    .upload-box input[type="file"]::file-selector-button:hover {
      transform: translateY(-1px);
      background: #dbe9ff;
    }

    .proof-upload-note {
      font-size: 13px;
      color: #d2e3ff;
      margin-bottom: 12px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .proof-grid {
      display: grid;
      gap: 12px;
      grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    }

    .proof-field {
      display: flex;
      flex-direction: column;
      gap: 6px;
      font-size: 13px;
      color: #eaf1ff;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      padding: 12px;
    }

    .proof-field input[type="file"] {
      width: 100%;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.04);
      padding: 8px 6px;
    }

    .proof-field-label {
      font-weight: 600;
      color: #f7fbff;
    }

    .proof-field-note {
      font-size: 12px;
      color: #a5b3cf;
    }

    .attendee-grid {
      display: grid;
      gap: 10px;
      margin: 14px 0 18px;
    }

    .attendee-grid label {
      display: grid;
      gap: 6px;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.3px;
      color: #e9f1ff;
    }

    .attendee-grid input[type="text"] {
      width: 100%;
      border: 1px solid rgba(255, 255, 255, 0.4);
      border-radius: 10px;
      padding: 10px 12px;
      background: rgba(255, 255, 255, 0.95);
      color: #1f2d40;
      font: inherit;
      font-size: 14px;
    }

    .attendee-grid input[readonly] {
      opacity: 1;
      cursor: default;
      pointer-events: none;
    }

    .attendee-row {
      display: grid;
      grid-template-columns: 1fr 180px;
      gap: 10px;
      align-items: end;
    }

    .attendee-row.has-package {
      grid-template-columns: 1fr 180px 230px;
    }

    .attendee-row select {
      width: 100%;
      border: 1px solid rgba(255, 255, 255, 0.4);
      border-radius: 10px;
      padding: 10px 12px;
      background: rgba(255, 255, 255, 0.95);
      color: #1f2d40;
      font: inherit;
      font-size: 14px;
    }

    .attendee-hint {
      margin-top: 8px;
      font-size: 13px;
      color: #dbe7ff;
      opacity: 0.95;
    }

    .proof-preview {
      margin-top: 12px;
      display: none;
      gap: 10px;
    }

    .proof-preview.is-visible {
      display: grid;
    }

    .proof-preview-label {
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.4px;
      color: #dbe7ff;
    }

    .proof-preview img {
      width: min(100%, 360px);
      max-height: 280px;
      object-fit: contain;
      border-radius: 10px;
      border: 1px solid rgba(255, 255, 255, 0.4);
      background: rgba(255, 255, 255, 0.08);
      box-shadow: 0 8px 22px rgba(0, 0, 0, 0.24);
    }

    .btn.primary {
      background: #ffffff;
      color: #0b2d61;
      box-shadow: none;
    }

    .btn.primary::before {
      display: none;
    }

    .btn.primary:hover {
      background: #dbe9ff;
      transform: translateY(-2px);
      box-shadow: none;
    }

    .alert {
      background: rgba(255, 99, 99, 0.2);
      border-color: rgba(255, 140, 140, 0.45);
      color: #ffe7e7;
    }

    @media (max-width: 960px) {
      .order-full {
        grid-template-columns: 1fr;
      }

      .attendee-row {
        grid-template-columns: 1fr;
      }

      .attendee-row.has-package {
        grid-template-columns: 1fr;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      body,
      body.page-ready,
      body.page-leaving {
        opacity: 1;
        transform: none;
        filter: none;
        transition: none;
      }
    }
  </style>
</head>
<body>
  <main class="order-shell">
    <div class="order-full">
      <section class="order-panel order-summary fade-up">
        <div class="section-title"><i class="bi bi-receipt-cutoff"></i> Order Details</div>
        <div class="order-meta">
          <div><i class="bi bi-person-badge"></i> <strong>Full Name</strong> : <?= h($user['full_name']) ?></div>
          <div><i class="bi bi-telephone"></i> <strong>Phone Number</strong> : <?= h($user['phone']) ?></div>
          <div><i class="bi bi-envelope"></i> <strong>E-mail</strong> : <?= h($user['email']) ?></div>
          <div><i class="bi bi-instagram"></i> <strong>Instagram</strong> : <?= $instagramLabel ? h($instagramLabel) : '-' ?></div>
        </div>
        <div style="margin-top:16px;"><i class="bi bi-box-seam"></i> <strong>Order</strong></div>
        <div class="order-list">
        <?php foreach ($items as $it): ?>
          <div><i class="bi bi-check2-circle"></i> <?= (int)$it['qty'] ?> x <?= h($it['name']) ?> @ <?= h(rupiah((int)$it['price'])) ?></div>
        <?php endforeach; ?>
        </div>

        <?php if ($additionalAttendeeCount > 0): ?>
          <div class="payment-card" style="margin-top:16px;">
            <div><i class="bi bi-people"></i> <strong>Attendees</strong></div>
            <div class="attendee-hint">Total tickets: <?= (int)$totalTickets ?>. Please fill names for attendee #2 until #<?= (int)$totalTickets ?>.</div>
            <?php if ($requiresPackageSelection): ?>
              <div class="attendee-hint">You bought different package types. Please assign package for each attendee, including attendee #1 (account owner).</div>
            <?php endif; ?>
            <div class="attendee-grid">
              <?php if ($requiresPackageSelection): ?>
                <div class="attendee-row has-package">
                  <label>
                    Attendee #1 Name
                    <input type="text" value="<?= h((string)$user['full_name']) ?>" readonly>
                  </label>
                  <label>
                    Gender
                    <input type="text" value="<?= h($ownerGender) ?>" readonly>
                  </label>
                  <label for="owner_package_id">
                    Package
                    <select id="owner_package_id" name="owner_package_id" form="orderSubmitForm" required>
                      <option value="">Select package</option>
                      <?php foreach ($packageIdOrder as $pkgId): ?>
                        <?php $pkgLabel = (string)($packageNamesById[$pkgId] ?? ('Package #' . (int)$pkgId)); ?>
                        <?php $pkgQty = (int)($packageTicketCounts[$pkgId] ?? 0); ?>
                        <option value="<?= (int)$pkgId ?>"<?= ((int)$ownerPackageId === (int)$pkgId) ? ' selected' : '' ?>>
                          <?= h($pkgLabel) ?> (<?= $pkgQty ?>x)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </div>
              <?php endif; ?>
              <?php for ($i = 0; $i < $additionalAttendeeCount; $i++): ?>
                <div class="attendee-row<?= $requiresPackageSelection ? ' has-package' : '' ?>">
                  <label for="attendee_name_<?= (int)$i ?>">
                    Attendee #<?= (int)($i + 2) ?> Name
                    <input
                      type="text"
                      id="attendee_name_<?= (int)$i ?>"
                      name="attendee_names[]"
                      form="orderSubmitForm"
                      maxlength="120"
                      value="<?= h($attendeeNames[$i] ?? '') ?>"
                      required
                    >
                  </label>
                  <label for="attendee_gender_<?= (int)$i ?>">
                    Gender
                    <select id="attendee_gender_<?= (int)$i ?>" name="attendee_genders[]" form="orderSubmitForm" required>
                      <option value="">Select gender</option>
                      <option value="Laki-laki"<?= (($attendeeGenders[$i] ?? '') === 'Laki-laki') ? ' selected' : '' ?>>Laki-laki</option>
                      <option value="Perempuan"<?= (($attendeeGenders[$i] ?? '') === 'Perempuan') ? ' selected' : '' ?>>Perempuan</option>
                    </select>
                  </label>
                  <?php if ($requiresPackageSelection): ?>
                    <label for="attendee_package_<?= (int)$i ?>">
                      Package
                      <select id="attendee_package_<?= (int)$i ?>" name="attendee_package_ids[]" form="orderSubmitForm" required>
                        <option value="">Select package</option>
                        <?php foreach ($packageIdOrder as $pkgId): ?>
                          <?php $pkgLabel = (string)($packageNamesById[$pkgId] ?? ('Package #' . (int)$pkgId)); ?>
                          <?php $pkgQty = (int)($packageTicketCounts[$pkgId] ?? 0); ?>
                          <option value="<?= (int)$pkgId ?>"<?= ((int)($attendeePackageIds[$i] ?? 0) === (int)$pkgId) ? ' selected' : '' ?>>
                            <?= h($pkgLabel) ?> (<?= $pkgQty ?>x)
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  <?php endif; ?>
                </div>
              <?php endfor; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="total">
          <div><i class="bi bi-wallet2"></i> Total to Pay:</div>
          <div><?= h(rupiah((int)$total)) ?>,-</div>
        </div>
      </section>

      <section class="order-panel form-wrap fade-up delay-1">
        <div class="section-title"><i class="bi bi-credit-card-2-front"></i> Payment Info</div>
        <div class="payment-card qris-card" style="margin-bottom:16px;">
          <div class="qris-label"><i class="bi bi-qr-code-scan"></i> <strong>Scan QRIS for Payment</strong></div>
          <img class="qris-image" src="/assets/img/qris_payment.jpeg" alt="QRIS payment code">
          <button class="qris-download" id="qrisDownloadBtn" href="/download/qris" download="qris_payment.jpeg">
            <i class="bi bi-download"></i> Download QR
        </button>
        </div>

        <div class="section-title"><i class="bi bi-cloud-arrow-up"></i> Upload Bukti Pembayaran</div>

        <?php if ($errors): ?>
          <div class="alert">
            <?php foreach ($errors as $e): ?>
              <div><?= h($e) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="orderSubmitForm">
          <div class="upload-box">
            <?php if ($attendeeProofInputs): ?>
              <p class="proof-upload-note"><i class="bi bi-info-circle"></i> Upload satu bukti pembayaran per peserta (JPG/PNG, maks 2MB per file).</p>
              <div class="proof-grid">
                <?php foreach ($attendeeProofInputs as $input): ?>
                  <label class="proof-field" for="attendeeProofInput_<?= (int)$input['index'] ?>">
                    <span class="proof-field-label"><?= h($input['label']) ?></span>
                    <input
                      type="file"
                      id="attendeeProofInput_<?= (int)$input['index'] ?>"
                      name="attendee_payment_proofs[]"
                      form="orderSubmitForm"
                      accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                      data-proof-input
                      data-proof-label="<?= h($input['label']) ?>"
                      required
                    >
                    <span class="proof-field-note">Max 2MB &middot; JPG/PNG</span>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <div class="proof-preview" id="proofPreviewWrap" aria-live="polite">
              <div class="proof-preview-label"><i class="bi bi-image"></i> Live Preview <span id="proofPreviewLabel">Pilih file untuk melihat preview</span></div>
              <img id="proofPreviewImage" src="" alt="Payment proof preview">
            </div>
          </div>
          <div style="margin-top:16px;">
            <button class="btn primary" type="submit"><i class="bi bi-upload"></i> Submit Bukti</button>
          </div>
        </form>
      </section>
    </div>
  </main>
  <script>
    (function () {
      var body = document.body;
      var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      window.addEventListener('pageshow', function () {
        if (!body) return;
        body.classList.remove('page-leaving');
        body.classList.add('page-ready');
      });
      if (body && !reduceMotion) {
        requestAnimationFrame(function () {
          body.classList.add('page-ready');
        });
      } else if (body) {
        body.classList.add('page-ready');
      }

      function canAnimateLink(a) {
        if (!a) return false;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#') return false;
        if (href.indexOf('javascript:') === 0 || href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0) return false;
        if (a.target && a.target !== '_self') return false;
        try {
          var next = new URL(a.href, window.location.href);
          return next.origin === window.location.origin;
        } catch (err) {
          return false;
        }
      }

      document.querySelectorAll('a[href]').forEach(function (a) {
        a.addEventListener('click', function (e) {
          if (reduceMotion || !body || !canAnimateLink(a) || e.defaultPrevented) return;
          e.preventDefault();
          if (body.classList.contains('page-leaving')) return;
          body.classList.add('page-leaving');
          window.setTimeout(function () {
            window.location.href = a.href;
          }, 260);
        });
      });

      var qrisDownloadBtn = document.getElementById('qrisDownloadBtn');
      if (qrisDownloadBtn) {
        qrisDownloadBtn.addEventListener('click', function (e) {
          e.preventDefault();
          var downloadUrl = qrisDownloadBtn.getAttribute('href');
          if (!downloadUrl) return;
          var frame = document.createElement('iframe');
          frame.style.display = 'none';
          frame.setAttribute('aria-hidden', 'true');
          frame.src = downloadUrl;
          document.body.appendChild(frame);
          window.setTimeout(function () {
            if (frame && frame.parentNode) {
              frame.parentNode.removeChild(frame);
            }
          }, 3000);
        });
      }

      var proofInputs = Array.prototype.slice.call(document.querySelectorAll('[data-proof-input]'));
      var proofPreviewWrap = document.getElementById('proofPreviewWrap');
      var proofPreviewImage = document.getElementById('proofPreviewImage');
      var proofPreviewLabel = document.getElementById('proofPreviewLabel');
      var packageLimits = <?= json_encode(array_map('intval', $packageTicketCounts), JSON_UNESCAPED_UNICODE) ?>;
      var packageSelectionEnabled = <?= $requiresPackageSelection ? 'true' : 'false' ?>;

      function resetProofPreview() {
        proofPreviewImage.removeAttribute('src');
        proofPreviewWrap.classList.remove('is-visible');
        if (proofPreviewLabel) {
          proofPreviewLabel.textContent = 'Pilih file untuk melihat preview';
        }
      }

      function showProofPreview(event, label) {
        var result = event && event.target && event.target.result ? event.target.result : '';
        proofPreviewImage.src = result;
        proofPreviewWrap.classList.toggle('is-visible', !!result);
        if (proofPreviewLabel) {
          proofPreviewLabel.textContent = label || 'Preview';
        }
      }

      if (proofInputs.length && proofPreviewWrap && proofPreviewImage) {
        proofInputs.forEach(function (input) {
          input.addEventListener('change', function () {
            var file = input.files && input.files[0] ? input.files[0] : null;
            var labelText = input.getAttribute('data-proof-label') || '';
            if (!file || !file.type || file.type.indexOf('image/') !== 0) {
              resetProofPreview();
              return;
            }
            var reader = new FileReader();
            reader.onload = function (event) {
              showProofPreview(event, labelText);
            };
            reader.onerror = function () {
              resetProofPreview();
            };
            reader.readAsDataURL(file);
          });
        });
      }

      if (packageSelectionEnabled) {
        var ownerPackageSelect = document.getElementById('owner_package_id');
        var attendeePackageSelects = Array.prototype.slice.call(document.querySelectorAll('select[name="attendee_package_ids[]"]'));
        var allPackageSelects = [];
        if (ownerPackageSelect) allPackageSelects.push(ownerPackageSelect);
        allPackageSelects = allPackageSelects.concat(attendeePackageSelects);

        function updatePackageAvailability() {
          var counts = {};
          allPackageSelects.forEach(function (sel) {
            var val = String(sel.value || '');
            if (!val) return;
            counts[val] = (counts[val] || 0) + 1;
          });

          allPackageSelects.forEach(function (sel) {
            var current = String(sel.value || '');
            Array.prototype.forEach.call(sel.options, function (opt) {
              var val = String(opt.value || '');
              if (!val) {
                opt.disabled = false;
                return;
              }
              var limit = Number(packageLimits[val] || 0);
              if (!limit) {
                opt.disabled = true;
                return;
              }
              var used = Number(counts[val] || 0);
              opt.disabled = val !== current && used >= limit;
            });
          });
        }

        allPackageSelects.forEach(function (sel) {
          sel.addEventListener('change', updatePackageAvailability);
        });
        updatePackageAvailability();
      }
    })();
  </script>
</body>
</html>
