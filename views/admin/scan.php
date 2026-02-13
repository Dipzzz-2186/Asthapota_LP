<?php
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../layout/app.php';
require_admin();

$db = get_db();
ensure_order_qr_schema($db);
ensure_order_attendee_checkin_schema($db);

function normalize_gender_value($rawGender)
{
    $gender = strtolower(trim((string)$rawGender));
    if (in_array($gender, ['male', 'm', 'laki-laki', 'laki', 'pria'], true)) return 'male';
    if (in_array($gender, ['female', 'f', 'perempuan', 'wanita'], true)) return 'female';
    return 'unknown';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    $rawInput = file_get_contents('php://input');
    $json = json_decode((string)$rawInput, true);
    $mode = is_array($json) ? trim((string)($json['mode'] ?? 'resolve')) : trim((string)($_POST['mode'] ?? 'resolve'));
    $scanRaw = '';
    if (is_array($json) && isset($json['token'])) {
        $scanRaw = (string)$json['token'];
    } else {
        $scanRaw = (string)($_POST['token'] ?? '');
    }

    $token = extract_qr_token($scanRaw);
    if ($token === '') {
        echo json_encode(['ok' => false, 'message' => 'QR tidak valid. Coba scan ulang.']);
        exit;
    }

    $stmt = $db->prepare('SELECT o.id, o.status, o.checked_in_at, u.full_name, u.gender
        FROM orders o JOIN users u ON u.id = o.user_id
        WHERE o.qr_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { echo json_encode(['ok' => false, 'message' => 'QR token tidak ditemukan.']); exit; }
    if ((string)($row['status'] ?? '') !== 'accepted') { echo json_encode(['ok' => false, 'message' => 'Order ini belum status accepted.']); exit; }

    $orderId = (int)$row['id'];
    $ownerName = trim((string)($row['full_name'] ?? ''));
    $orderGender = normalize_gender_value($row['gender'] ?? '');

    $attendees = [];
    try {
        $attendeeStmt = $db->prepare('SELECT id, attendee_name, gender, position_no, checked_in_at FROM order_attendees WHERE order_id = ? ORDER BY position_no ASC, id ASC');
        $attendeeStmt->execute([$orderId]);
        foreach ($attendeeStmt->fetchAll(PDO::FETCH_ASSOC) as $attendeeRow) {
            $attendeeId = (int)($attendeeRow['id'] ?? 0);
            if ($attendeeId <= 0) continue;
            $attendeeName = trim((string)($attendeeRow['attendee_name'] ?? ''));
            if ($attendeeName === '') $attendeeName = 'Attendee #' . (int)($attendeeRow['position_no'] ?? 0);
            $attendees[] = ['id' => $attendeeId, 'name' => $attendeeName, 'gender' => normalize_gender_value($attendeeRow['gender'] ?? ''),  'position_no' => (int)($attendeeRow['position_no'] ?? 0), 'checked_in_at' => (string)($attendeeRow['checked_in_at'] ?? '')];
        }
    } catch (Throwable $e) { $attendees = []; }

    if (!$attendees) {
        $attendees[] = ['id' => 0, 'name' => $ownerName !== '' ? $ownerName : 'Pemesan', 'gender' => $orderGender, 'position_no' => 1, 'checked_in_at' => (string)($row['checked_in_at'] ?? '')];
    }

    if ($mode === 'checkin') {
        $attendeeId = is_array($json) ? (int)($json['attendee_id'] ?? 0) : (int)($_POST['attendee_id'] ?? 0);
        if ($attendeeId <= 0) { echo json_encode(['ok' => false, 'message' => 'Pilih nama attendee dulu.']); exit; }
        $selected = null;
        foreach ($attendees as $attendee) { if ((int)$attendee['id'] === $attendeeId) { $selected = $attendee; break; } }
        if (!$selected) { echo json_encode(['ok' => false, 'message' => 'Attendee tidak valid untuk QR ini.']); exit; }
        if (!empty($selected['checked_in_at'])) { echo json_encode(['ok' => false, 'message' => 'Attendee ini sudah check-in sebelumnya.']); exit; }

        $now = date('Y-m-d H:i:s');
        try {
            if ((int)$selected['id'] > 0) {
                $updAttendee = $db->prepare('UPDATE order_attendees SET checked_in_at = ? WHERE id = ? AND order_id = ?');
                $updAttendee->execute([$now, (int)$selected['id'], $orderId]);
            }
        } catch (Throwable $e) {}

        try {
            $remainStmt = $db->prepare('SELECT COUNT(*) FROM order_attendees WHERE order_id = ? AND checked_in_at IS NULL');
            $remainStmt->execute([$orderId]);
            $remainingAfter = (int)$remainStmt->fetchColumn();
            if ($remainingAfter <= 0) {
                $db->prepare('UPDATE orders SET checked_in_at = ? WHERE id = ?')->execute([$now, $orderId]);
            } else {
                $db->prepare('UPDATE orders SET checked_in_at = NULL WHERE id = ?')->execute([$orderId]);
            }
        } catch (Throwable $e) {}

        echo json_encode(['ok' => true, 'order_id' => $orderId, 'name' => (string)$selected['name'], 'gender' => normalize_gender_value($selected['gender'] ?? $orderGender), 'checked_in_at' => $now, 'message' => 'Check-in berhasil.']);
        exit;
    }

    $checked = 0;
    foreach ($attendees as $attendee) { if (!empty($attendee['checked_in_at'])) $checked++; }
    $total = count($attendees);
    $remaining = max(0, $total - $checked);

    echo json_encode(['ok' => true, 'mode' => 'select_attendee', 'order_id' => $orderId, 'order_name' => $ownerName, 'order_gender' => $orderGender, 'total_tickets' => $total, 'checked_in_count' => $checked, 'remaining_count' => $remaining, 'attendees' => $attendees, 'message' => $remaining > 0 ? 'Pilih nama attendee yang mau check-in.' : 'Semua attendee pada QR ini sudah check-in.']);
    exit;
}

$prefillToken = extract_qr_token((string)($_GET['token'] ?? ''));

$extraHead = <<<'HTML'
<style>
  /* ─── Scan Page Layout ───────────────────────────────────── */
  .scan-wrap {
    width: min(1240px, 95vw);
    margin: 28px auto 56px;
    display: flex;
    flex-direction: column;
    gap: 0;
  }

  /* ─── Page Header ────────────────────────────────────────── */
  .scan-page-head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 20px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--stroke);
  }

  .scan-head-left { display: grid; gap: 4px; }

  .scan-title {
    margin: 0;
    font-size: clamp(24px, 3vw, 34px);
    font-weight: 800;
    letter-spacing: -0.6px;
    line-height: 1.1;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .scan-title .bi {
    font-size: 0.85em;
    color: var(--primary);
    opacity: 0.8;
  }

  .scan-sub {
    margin: 0;
    color: var(--muted);
    font-size: 13.5px;
    font-weight: 500;
  }

  .scan-head-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 14px;
    border-radius: 999px;
    background: rgba(0, 102, 255, 0.07);
    border: 1.5px solid rgba(0, 102, 255, 0.18);
    color: var(--primary);
    font-size: 12.5px;
    font-weight: 700;
    letter-spacing: 0.3px;
  }

  .scan-head-badge .dot {
    width: 7px; height: 7px;
    border-radius: 999px;
    background: var(--primary);
    animation: livePulse 1.6s ease-in-out infinite;
  }

  @keyframes livePulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.4; transform: scale(0.7); }
  }

  /* ─── Grid ───────────────────────────────────────────────── */
  .scan-grid {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 16px;
    align-items: start;
  }

  /* ─── Pane Base ──────────────────────────────────────────── */
  .scan-pane {
    background: var(--surface);
    border: 1px solid var(--stroke);
    border-radius: 20px;
    padding: 20px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
    position: relative;
    overflow: hidden;
  }

  .scan-pane::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--primary) 0%, rgba(0,102,255,0.3) 60%, transparent 100%);
    opacity: 0.6;
    pointer-events: none;
  }

  .pane-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 16px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--stroke);
  }

  .pane-title-wrap { display: grid; gap: 2px; }

  .pane-title {
    margin: 0;
    font-size: 15px;
    font-weight: 800;
    letter-spacing: -0.2px;
    color: var(--text);
  }

  .pane-sub {
    margin: 0;
    color: var(--muted);
    font-size: 12px;
    font-weight: 500;
  }

  .pane-icon {
    width: 38px; height: 38px;
    border-radius: 12px;
    background: rgba(0, 102, 255, 0.08);
    border: 1px solid rgba(0, 102, 255, 0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    color: var(--primary);
    flex-shrink: 0;
  }

  /* ─── QR Reader ──────────────────────────────────────────── */
  .qr-reader-wrap {
    border-radius: 14px;
    overflow: hidden;
    border: 1.5px solid var(--stroke);
    background: #f4f8ff;
    min-height: 260px;
    position: relative;
  }

  #qr-reader {
    width: 100%;
    min-height: 260px;
  }

  .qr-idle-placeholder {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    color: var(--muted);
    pointer-events: none;
  }

  .qr-idle-icon {
    font-size: 48px;
    opacity: 0.2;
    animation: idleFloat 2.5s ease-in-out infinite;
  }

  .qr-idle-text {
    font-size: 13px;
    font-weight: 600;
    opacity: 0.45;
  }

  @keyframes idleFloat {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-5px); }
  }

  /* ─── Scan Action Buttons ────────────────────────────────── */
  .scan-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--stroke);
  }

  .scan-actions .btn {
    height: 40px;
    font-size: 13px;
    border-radius: 10px;
    font-weight: 700;
  }

  /* ─── Manual Input ───────────────────────────────────────── */
  .manual-section {
    margin-top: 14px;
  }

  .manual-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 7px;
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .manual-form {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 8px;
  }

  .manual-form input {
    height: 44px;
    border: 1.5px solid var(--stroke);
    border-radius: 10px;
    padding: 10px 13px;
    font: inherit;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--text);
    background: var(--surface);
    transition: border-color 0.18s, box-shadow 0.18s;
  }

  .manual-form input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.1);
  }

  .manual-form input::placeholder { color: var(--muted); opacity: 0.65; }

  .manual-form .btn {
    height: 44px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
  }

  /* ─── Result Pane ────────────────────────────────────────── */
  .result-stack {
    display: grid;
    gap: 14px;
  }

  .result-box {
    border-radius: 16px;
    padding: 16px;
    display: none;
    border: 1.5px solid var(--stroke);
    background: var(--surface);
    animation: resultIn 0.28s ease-out;
  }

  .result-box.show { display: block; }

  .result-box.success {
    background: #f0faf4;
    border-color: #8ed4a8;
  }

  .result-box.error {
    background: #fff5f5;
    border-color: #f5b8b8;
  }

  @keyframes resultIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* ─── Profile Card ───────────────────────────────────────── */
  .profile-card {
    display: grid;
    grid-template-columns: 80px 1fr;
    gap: 14px;
    padding: 14px;
    border-radius: 14px;
    background: #fff;
    border: 1px solid #dce8f5;
    margin: 10px 0 14px;
    box-shadow: 0 4px 14px rgba(20, 41, 74, 0.07);
    transition: box-shadow 0.2s ease;
  }

  .profile-card:hover { box-shadow: 0 8px 22px rgba(20, 41, 74, 0.12); }

  .profile-avatar {
    width: 76px; height: 76px;
    border-radius: 999px;
    position: relative;
    overflow: hidden;
    flex-shrink: 0;
    animation: floaty 2.4s ease-in-out infinite;
    box-shadow: 0 8px 20px rgba(20, 41, 74, 0.18);
  }

  .profile-avatar .avatar-img {
    width: 100%; height: 100%;
    object-fit: cover;
    border-radius: 999px;
    position: relative;
    z-index: 2;
  }

  .profile-avatar::after {
    content: '';
    position: absolute;
    inset: 4px;
    border: 2px solid rgba(255, 255, 255, 0.6);
    border-radius: 999px;
    animation: pulse-ring 2.2s ease-in-out infinite;
    z-index: 3;
    pointer-events: none;
  }

  .profile-avatar.male   { background: linear-gradient(145deg, #1d78ff, #66b6ff); }
  .profile-avatar.female { background: linear-gradient(145deg, #f35aa4, #ff95c8); }
  .profile-avatar.unknown { background: linear-gradient(145deg, #7a8fa6, #aebdcc); }

  @keyframes floaty {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-5px); }
  }

  @keyframes pulse-ring {
    0%, 100% { transform: scale(1); opacity: 0.5; }
    50% { transform: scale(1.08); opacity: 1; }
  }

  .profile-body {
    display: flex;
    flex-direction: column;
    gap: 6px;
    justify-content: center;
  }

  .profile-name {
    margin: 0;
    font-size: 18px;
    font-weight: 900;
    letter-spacing: -0.3px;
    color: #18375f;
    line-height: 1.2;
  }

  .profile-role {
    margin: 0;
    font-size: 12px;
    font-weight: 600;
    color: #5e7a96;
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
  }

  .profile-kpis {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 2px;
  }

  .mini-kpi {
    padding: 4px 9px;
    border-radius: 8px;
    background: #eef4ff;
    border: 1px solid #d0e0f7;
    font-size: 11px;
    font-weight: 800;
    color: #2c4d7a;
    letter-spacing: 0.1px;
  }

  /* ─── Gender Chip ────────────────────────────────────────── */
  .gender-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border-radius: 999px;
    padding: 3px 9px;
    font-size: 11.5px;
    font-weight: 700;
    border: 1.5px solid transparent;
    letter-spacing: 0.1px;
  }

  .gender-chip.male    { color: #0b4e9e; background: #e8f3ff; border-color: #b9d8ff; }
  .gender-chip.female  { color: #9a2a66; background: #ffedf5; border-color: #ffc4dc; }
  .gender-chip.unknown { color: #425773; background: #eff3f8; border-color: #d1dbe8; }

  /* ─── Attendee List ──────────────────────────────────────── */
  .attendee-list {
    display: grid;
    gap: 7px;
    margin-top: 2px;
  }

  .attendee-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 13px;
    border-radius: 11px;
    border: 1.5px solid;
    font-size: 13.5px;
    font-weight: 600;
  }

  .attendee-item.checked {
    background: #edfaf3;
    border-color: #92d8ae;
    color: #185c33;
  }

  .attendee-item-left {
    display: flex;
    align-items: center;
    gap: 9px;
  }

  .attendee-item-left .bi { font-size: 15px; opacity: 0.65; }

  .checked-stamp {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 999px;
    background: #c6f0d6;
    color: #1a6636;
    border: 1px solid #8ed4ab;
    white-space: nowrap;
  }

  .btn-attendee-checkin {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 14px;
    border-radius: 9px;
    border: 1.5px solid rgba(0, 102, 255, 0.25);
    background: rgba(0, 102, 255, 0.07);
    color: var(--primary);
    font: inherit;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.17s ease;
    white-space: nowrap;
  }

  .btn-attendee-checkin:hover {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 102, 255, 0.3);
  }

  /* ─── Result Meta ────────────────────────────────────────── */
  .result-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-bottom: 12px;
  }

  .result-meta-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    background: #fff;
    border: 1px solid #d4e3f5;
    color: #2c4d7a;
  }

  .result-error-msg {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 14px;
    border-radius: 12px;
    background: #fff5f5;
    border: 1px solid #f5b8b8;
    color: #8f2e2e;
    font-size: 14px;
    font-weight: 600;
  }

  .result-error-msg .bi { font-size: 17px; flex-shrink: 0; margin-top: 1px; }

  .section-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.7px;
    text-transform: uppercase;
    color: var(--muted);
    margin: 0 0 8px;
    display: flex;
    align-items: center;
    gap: 6px;
  }

  /* ─── History Box ────────────────────────────────────────── */
  .history-box {
    border: 1px solid var(--stroke);
    border-radius: 16px;
    padding: 16px 18px;
    background: var(--surface);
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
  }

  .history-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 14px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--stroke);
  }

  .history-title {
    margin: 0;
    font-size: 15px;
    font-weight: 800;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .history-title .bi { color: var(--primary); opacity: 0.7; }

  .history-count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 24px;
    height: 24px;
    border-radius: 999px;
    background: rgba(0, 102, 255, 0.1);
    border: 1px solid rgba(0, 102, 255, 0.2);
    color: var(--primary);
    font-size: 12px;
    font-weight: 800;
    padding: 0 7px;
  }

  .history-empty {
    margin: 0;
    color: var(--muted);
    font-size: 13.5px;
    font-weight: 500;
    text-align: center;
    padding: 14px 0;
  }

  .history-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 7px;
  }

  .history-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid var(--stroke);
    background: #f8fbff;
    font-size: 13px;
    transition: background 0.15s;
  }

  .history-item:hover { background: #eef4ff; }

  .history-item-num {
    width: 22px; height: 22px;
    border-radius: 999px;
    background: rgba(0, 102, 255, 0.1);
    border: 1px solid rgba(0, 102, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 800;
    color: var(--primary);
    flex-shrink: 0;
  }

  .history-item-body { flex: 1; min-width: 0; }

  .history-item-name {
    font-weight: 700;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .history-item-meta {
    font-size: 11.5px;
    color: var(--muted);
    font-weight: 500;
  }

  /* ─── Responsive ─────────────────────────────────────────── */
  @media (max-width: 1060px) {
    .scan-grid { grid-template-columns: 340px 1fr; }
  }

  @media (max-width: 900px) {
    .scan-grid { grid-template-columns: 1fr; }
    .scan-page-head { flex-direction: column; align-items: flex-start; }
  }

  @media (max-width: 640px) {
    .manual-form { grid-template-columns: 1fr; }
    .profile-card { grid-template-columns: 64px 1fr; gap: 10px; }
    .profile-avatar { width: 60px; height: 60px; }
    .profile-name { font-size: 15px; }
    .scan-wrap { margin: 16px auto 40px; }
  }
</style>
HTML;

render_header([
    'title' => 'Admin Scan QR - Asthapora',
    'isAdmin' => true,
    'showNav' => false,
    'brandSubtitle' => 'QR Check-In',
    'extraHead' => $extraHead,
]);
?>

<main class="scan-wrap">

  <!-- ── Page Header ───────────────────────────────────────── -->
  <div class="scan-page-head">
    <div class="scan-head-left">
      <h1 class="scan-title"><i class="bi bi-qr-code-scan"></i> QR Check-In</h1>
      <p class="scan-sub">Scan QR tiket → pilih nama attendee → konfirmasi. 1 QR bisa dipakai sesuai jumlah tiket.</p>
    </div>
    <div class="scan-head-badge">
      <span class="dot"></span>
      Live Scanner
    </div>
  </div>

  <div class="scan-grid">

    <!-- ── Left: Scanner Console ──────────────────────────── -->
    <article class="scan-pane">
      <div class="pane-header">
        <div class="pane-title-wrap">
          <h2 class="pane-title">Scanner Console</h2>
          <p class="pane-sub">Kamera browser &amp; scanner gun (USB HID)</p>
        </div>
        <div class="pane-icon"><i class="bi bi-camera-video"></i></div>
      </div>

      <div class="qr-reader-wrap">
        <div id="qr-reader"></div>
        <div class="qr-idle-placeholder" id="qrIdlePlaceholder">
          <div class="qr-idle-icon"><i class="bi bi-qr-code"></i></div>
          <div class="qr-idle-text">Klik Start untuk aktifkan kamera</div>
        </div>
      </div>

      <div class="scan-actions">
        <a class="btn ghost" href="/admin/dashboard"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
        <button class="btn primary" id="startScan" type="button"><i class="bi bi-camera-video"></i> Start</button>
        <button class="btn ghost" id="stopScan" type="button"><i class="bi bi-stop-circle"></i> Stop</button>
      </div>

      <div class="manual-section">
        <div class="manual-label"><i class="bi bi-keyboard"></i> Manual Input</div>
        <form class="manual-form" id="manualForm" method="post" action="/admin/scan">
          <input
            id="manualToken"
            name="token"
            type="text"
            placeholder="Paste token / URL QR..."
            value="<?= h($prefillToken) ?>"
            autocomplete="off"
          >
          <button class="btn ghost" type="submit"><i class="bi bi-search"></i> Verify</button>
        </form>
      </div>
    </article>

    <!-- ── Right: Result + History ────────────────────────── -->
    <div class="result-stack">

      <!-- Result Panel -->
      <article class="scan-pane" style="padding-bottom: 18px;">
        <div class="pane-header">
          <div class="pane-title-wrap">
            <h2 class="pane-title">Check-In Result</h2>
            <p class="pane-sub">Profil attendee &amp; status check-in terbaru</p>
          </div>
          <div class="pane-icon"><i class="bi bi-person-check"></i></div>
        </div>
        <div class="result-box" id="resultBox" aria-live="polite"></div>
        <div id="resultPlaceholder" style="text-align:center;padding:30px 0;color:var(--muted);">
          <div style="font-size:38px;opacity:0.15;margin-bottom:8px;"><i class="bi bi-person-badge"></i></div>
          <div style="font-size:13px;font-weight:600;opacity:0.5;">Hasil scan akan muncul di sini</div>
        </div>
      </article>

      <!-- History Panel -->
      <article class="scan-pane history-box" style="border-radius:20px;">
        <div class="history-header">
          <h2 class="history-title"><i class="bi bi-clock-history"></i> Riwayat Sesi Ini</h2>
          <span class="history-count-badge" id="historyCount">0</span>
        </div>
        <p class="history-empty" id="historyEmpty">Belum ada data scan.</p>
        <ol class="history-list" id="historyList" style="display:none;"></ol>
      </article>

    </div>
  </div>
</main>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
  (function () {
    var resultBox = document.getElementById('resultBox');
    var resultPlaceholder = document.getElementById('resultPlaceholder');
    var startBtn = document.getElementById('startScan');
    var stopBtn = document.getElementById('stopScan');
    var manualForm = document.getElementById('manualForm');
    var manualToken = document.getElementById('manualToken');
    var historyEmpty = document.getElementById('historyEmpty');
    var historyList = document.getElementById('historyList');
    var historyCount = document.getElementById('historyCount');
    var qrIdlePlaceholder = document.getElementById('qrIdlePlaceholder');
    var scanner = null;
    var scanning = false;
    var submitting = false;
    var scanHistory = [];
    var hwBuffer = '';
    var hwLastTs = 0;
    var hwMaxGapMs = 70;
    var hwMinLen = 10;

    function showResult(type, html) {
      resultBox.classList.remove('success', 'error');
      resultBox.classList.add('show', type);
      resultBox.innerHTML = html;
      if (resultPlaceholder) resultPlaceholder.style.display = 'none';
    }

    function escapeHtml(text) {
      return String(text == null ? '' : text)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    function normalizeGender(raw) {
      var v = String(raw || '').toLowerCase().trim();
      if (['male','m','laki-laki','laki'].indexOf(v) !== -1) return 'male';
      if (['female','f','perempuan','wanita'].indexOf(v) !== -1) return 'female';
      return 'unknown';
    }

    function genderLabel(g) {
      return g === 'male' ? 'Male' : g === 'female' ? 'Female' : 'Unknown';
    }

    function genderAvatarSrc(g) {
      return g === 'female' ? '/assets/img/padelpr..png' : '/assets/img/padellaki.png';
    }

    function genderIconClass(g) {
      return g === 'male' ? 'bi-gender-male' : g === 'female' ? 'bi-gender-female' : 'bi-gender-ambiguous';
    }

    function renderProfileCard(name, role, gender, stats) {
      var g = normalizeGender(gender);
      var s = stats || {};
      return '<div class="profile-card">' +
        '<div class="profile-avatar ' + g + '"><img class="avatar-img" src="' + genderAvatarSrc(g) + '" alt="' + escapeHtml(genderLabel(g)) + '"></div>' +
        '<div class="profile-body">' +
          '<p class="profile-name">' + escapeHtml(name || '-') + '</p>' +
          '<p class="profile-role">' + escapeHtml(role || '') +
            ' <span class="gender-chip ' + g + '"><i class="bi ' + genderIconClass(g) + '"></i> ' + escapeHtml(genderLabel(g)) + '</span>' +
          '</p>' +
          '<div class="profile-kpis">' +
            '<span class="mini-kpi"><i class="bi bi-ticket-perforated"></i> ' + escapeHtml(String(s.total || 0)) + ' tiket</span>' +
            '<span class="mini-kpi"><i class="bi bi-check-circle"></i> ' + escapeHtml(String(s.checked || 0)) + ' hadir</span>' +
            '<span class="mini-kpi"><i class="bi bi-hourglass-split"></i> ' + escapeHtml(String(s.remaining || 0)) + ' sisa</span>' +
          '</div>' +
        '</div>' +
      '</div>';
    }

    function renderAttendeeList(attendees, cleanToken) {
      if (!attendees || !attendees.length) return '<p style="color:var(--muted);font-size:13px;margin:0;">Tidak ada data attendee.</p>';
      return '<div class="attendee-list">' + attendees.map(function(a) {
        var isChecked = !!(a.checked_in_at);
        if (isChecked) {
          return '<div class="attendee-item checked">' +
            '<div class="attendee-item-left"><i class="bi bi-person-check-fill"></i><span>' + escapeHtml(a.name || '-') + '</span></div>' +
            '<span class="checked-stamp"><i class="bi bi-check-lg"></i> Sudah hadir</span>' +
          '</div>';
        }
        return '<div class="attendee-item" style="background:#f5f9ff;border-color:#c8d9f0;color:var(--text);">' +
          '<div class="attendee-item-left"><i class="bi bi-person"></i><span>' + escapeHtml(a.name || '-') + '</span></div>' +
          '<button type="button" class="btn-attendee-checkin attendee-checkin-btn"' +
            ' data-attendee-id="' + Number(a.id || 0) + '"' +
            ' data-token="' + escapeHtml(cleanToken) + '"' +
            ' data-name="' + escapeHtml(a.name || '-') + '">' +
            '<i class="bi bi-person-check"></i> Check-in' +
          '</button>' +
        '</div>';
      }).join('') + '</div>';
    }

    function renderHistory() {
      if (!historyEmpty || !historyList) return;
      var count = scanHistory.length;
      if (historyCount) historyCount.textContent = count;
      if (!count) {
        historyEmpty.style.display = '';
        historyList.style.display = 'none';
        historyList.innerHTML = '';
        return;
      }
      historyEmpty.style.display = 'none';
      historyList.style.display = 'grid';
      historyList.innerHTML = scanHistory.map(function(item, idx) {
        return '<li class="history-item">' +
          '<div class="history-item-num">' + (count - idx) + '</div>' +
          '<div class="history-item-body">' +
            '<div class="history-item-name">' + escapeHtml(item.name || '-') + '</div>' +
            '<div class="history-item-meta">Order #' + escapeHtml(String(item.order_id || '-')) + ' &bull; ' + escapeHtml(item.time || '-') + '</div>' +
          '</div>' +
          '<i class="bi bi-check-circle-fill" style="color:#2ea85a;font-size:15px;flex-shrink:0;"></i>' +
        '</li>';
      }).join('');
    }

    function processHardwareScan(raw) {
      var value = String(raw || '').trim();
      if (!value) return;
      if (manualToken) manualToken.value = value;
      verifyToken(value);
    }

    function setupHardwareScannerCapture() {
      document.addEventListener('keydown', function (e) {
        var key = e.key || '';
        var now = Date.now();
        if (['Shift','Control','Alt','Meta','CapsLock'].indexOf(key) !== -1) return;
        if (now - hwLastTs > hwMaxGapMs) hwBuffer = '';
        hwLastTs = now;
        if (key === 'Enter') {
          var isLikelyScanner = hwBuffer.length >= hwMinLen;
          var fallbackInput = manualToken ? String(manualToken.value || '').trim() : '';
          if (isLikelyScanner) { e.preventDefault(); processHardwareScan(hwBuffer); hwBuffer = ''; return; }
          if (fallbackInput) { e.preventDefault(); processHardwareScan(fallbackInput); hwBuffer = ''; return; }
          hwBuffer = ''; return;
        }
        if (key === 'Backspace') { hwBuffer = hwBuffer.slice(0, -1); return; }
        if (key.length === 1) hwBuffer += key;
      }, true);
    }

    function stopScanner() {
      if (!scanner || !scanning) return Promise.resolve();
      scanning = false;
      if (qrIdlePlaceholder) qrIdlePlaceholder.style.display = '';
      return scanner.stop().catch(function(){}).then(function() { return scanner.clear().catch(function(){}); });
    }

    function postJson(payload) {
      var endpoint = manualForm.getAttribute('action') || window.location.pathname || '/admin/scan';
      return fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload || {})
      }).then(function(res) {
        var ct = res.headers.get('content-type') || '';
        if (ct.indexOf('application/json') === -1) {
          return res.text().then(function(txt) {
            var isLogin = txt.indexOf('Admin Login') !== -1 || txt.indexOf('/admin/login') !== -1;
            if (isLogin || res.status === 401 || res.status === 403) throw new Error('Sesi admin habis. Silakan login lagi.');
            throw new Error('Server balas non-JSON (HTTP ' + res.status + ').');
          });
        }
        return res.json();
      });
    }

    function verifyToken(rawToken) {
      if (submitting) return;
      submitting = true;
      var cleanToken = rawToken || '';
      postJson({ mode: 'resolve', token: cleanToken })
      .then(function(data) {
        if (!data || !data.ok) {
          showResult('error',
            '<div class="result-error-msg">' +
              '<i class="bi bi-exclamation-triangle-fill"></i>' +
              '<div><strong>Verifikasi Gagal</strong><br>' + escapeHtml((data && data.message) || 'Token tidak valid.') + '</div>' +
            '</div>'
          );
          return;
        }

        var attendees = Array.isArray(data.attendees) ? data.attendees : [];
        var total = Number(data.total_tickets || attendees.length || 0);
        var checked = Number(data.checked_in_count || 0);
        var remain = Number(data.remaining_count || 0);
        var orderGender = normalizeGender(data.order_gender);

        var metaHtml = '<div class="result-meta">' +
          '<span class="result-meta-chip"><i class="bi bi-hash"></i> Order #' + escapeHtml(String(data.order_id || '-')) + '</span>' +
          '<span class="result-meta-chip"><i class="bi bi-ticket"></i> ' + total + ' tiket</span>' +
          '<span class="result-meta-chip"><i class="bi bi-check-circle"></i> ' + checked + ' hadir</span>' +
          '<span class="result-meta-chip"><i class="bi bi-hourglass-split"></i> ' + remain + ' sisa</span>' +
        '</div>';

        var profileHtml = renderProfileCard(data.order_name || '-', 'Order Owner', orderGender, { total: total, checked: checked, remaining: remain });

        if (!attendees.length) {
          showResult('error', metaHtml + profileHtml + '<p style="margin:0;font-size:13px;color:#8f2e2e;">Data attendee tidak ditemukan.</p>');
          return;
        }

        var attendeeSection = '<div class="section-label"><i class="bi bi-people"></i> Pilih Attendee</div>' +
          renderAttendeeList(attendees, cleanToken);

        showResult('success', metaHtml + profileHtml + attendeeSection);

        resultBox.querySelectorAll('.attendee-checkin-btn').forEach(function(btn) {
          btn.addEventListener('click', function() {
            submitCheckin(
              btn.getAttribute('data-token') || '',
              Number(btn.getAttribute('data-attendee-id') || 0),
              btn.getAttribute('data-name') || '-'
            );
          });
        });
      })
      .catch(function(err) {
        showResult('error',
          '<div class="result-error-msg"><i class="bi bi-wifi-off"></i><div><strong>Koneksi Error</strong><br>' + escapeHtml(err && err.message ? err.message : 'Gagal koneksi ke server.') + '</div></div>'
        );
      })
      .finally(function() { submitting = false; });
    }

    function submitCheckin(token, attendeeId, attendeeName) {
      if (submitting) return;
      submitting = true;
      postJson({ mode: 'checkin', token: token || '', attendee_id: attendeeId || 0 })
      .then(function(data) {
        if (!data || !data.ok) {
          showResult('error',
            '<div class="result-error-msg"><i class="bi bi-exclamation-triangle-fill"></i><div><strong>Check-in Gagal</strong><br>' + escapeHtml((data && data.message) || 'Gagal.') + '</div></div>'
          );
          return;
        }
        var checkedGender = normalizeGender(data.gender);
        var successBanner = '<div style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:12px;background:#c6f0d6;border:1px solid #6dc996;margin-bottom:12px;">' +
          '<i class="bi bi-check-circle-fill" style="font-size:22px;color:#1a6636;flex-shrink:0;"></i>' +
          '<div><div style="font-size:15px;font-weight:800;color:#1a6636;">Check-In Berhasil!</div>' +
          '<div style="font-size:12.5px;color:#2a7a45;font-weight:500;">' + escapeHtml(data.checked_in_at || '') + '</div></div>' +
        '</div>';
        var profileHtml = renderProfileCard('Welcome, ' + (data.name || attendeeName || '-'), 'Confirmed Attendee', checkedGender, { total: 1, checked: 1, remaining: 0 });
        var nextAction = '<div style="margin-top:12px;padding:11px 14px;border-radius:11px;background:#eef4ff;border:1px solid #c8d9f5;font-size:13px;font-weight:600;color:var(--primary);display:flex;align-items:center;gap:8px;">' +
          '<i class="bi bi-qr-code-scan"></i> Scan ulang QR untuk attendee berikutnya' +
        '</div>';

        showResult('success', successBanner + profileHtml + nextAction);

        scanHistory.unshift({ order_id: data.order_id || '-', name: data.name || attendeeName || '-', time: data.checked_in_at || '-' });
        renderHistory();
        if (manualToken) manualToken.value = '';
      })
      .catch(function(err) {
        showResult('error',
          '<div class="result-error-msg"><i class="bi bi-wifi-off"></i><div><strong>Koneksi Error</strong><br>' + escapeHtml(err && err.message ? err.message : 'Gagal koneksi ke server.') + '</div></div>'
        );
      })
      .finally(function() { submitting = false; });
    }

    function startScanner() {
      if (scanning) return;
      if (typeof Html5Qrcode === 'undefined') {
        showResult('error', '<div class="result-error-msg"><i class="bi bi-exclamation-triangle-fill"></i><div><strong>Error</strong><br>Library scanner gagal dimuat.</div></div>');
        return;
      }
      if (qrIdlePlaceholder) qrIdlePlaceholder.style.display = 'none';
      scanner = new Html5Qrcode('qr-reader');
      scanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: 240 },
        function(decodedText) {
          stopScanner().finally(function() { verifyToken(decodedText || ''); });
        },
        function() {}
      ).then(function() {
        scanning = true;
      }).catch(function() {
        if (qrIdlePlaceholder) qrIdlePlaceholder.style.display = '';
        showResult('error', '<div class="result-error-msg"><i class="bi bi-camera-video-off"></i><div><strong>Kamera Error</strong><br>Izinkan akses kamera atau gunakan verify manual.</div></div>');
      });
    }

    startBtn.addEventListener('click', startScanner);
    stopBtn.addEventListener('click', stopScanner);
    manualForm.addEventListener('submit', function(e) { e.preventDefault(); verifyToken(manualToken.value || ''); });

    if (manualToken.value) verifyToken(manualToken.value);
    setupHardwareScannerCapture();
    renderHistory();
  })();
</script>

<?php render_footer(['isAdmin' => true]); ?>