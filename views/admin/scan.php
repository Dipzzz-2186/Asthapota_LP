<?php
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../layout/app.php';
require_admin();

$db = get_db();
ensure_order_qr_schema($db);
ensure_order_attendee_checkin_schema($db);

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
        echo json_encode([
            'ok' => false,
            'message' => 'QR tidak valid. Coba scan ulang.',
        ]);
        exit;
    }

    $stmt = $db->prepare('SELECT o.id, o.status, o.checked_in_at, u.full_name, u.gender
        FROM orders o
        JOIN users u ON u.id = o.user_id
        WHERE o.qr_token = ?
        LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode([
            'ok' => false,
            'message' => 'QR token tidak ditemukan.',
        ]);
        exit;
    }

    if ((string)($row['status'] ?? '') !== 'accepted') {
        echo json_encode([
            'ok' => false,
            'message' => 'Order ini belum status accepted.',
        ]);
        exit;
    }

    $orderId = (int)$row['id'];
    $ownerName = trim((string)($row['full_name'] ?? ''));
    $orderGender = strtolower(trim((string)($row['gender'] ?? '')));
    if ($orderGender !== 'male' && $orderGender !== 'female') {
        $orderGender = 'unknown';
    }

    $attendees = [];
    try {
        $attendeeStmt = $db->prepare('SELECT id, attendee_name, position_no, checked_in_at
            FROM order_attendees
            WHERE order_id = ?
            ORDER BY position_no ASC, id ASC');
        $attendeeStmt->execute([$orderId]);
        foreach ($attendeeStmt->fetchAll(PDO::FETCH_ASSOC) as $attendeeRow) {
            $attendeeId = (int)($attendeeRow['id'] ?? 0);
            if ($attendeeId <= 0) {
                continue;
            }
            $attendeeName = trim((string)($attendeeRow['attendee_name'] ?? ''));
            if ($attendeeName === '') {
                $attendeeName = 'Attendee #' . (int)($attendeeRow['position_no'] ?? 0);
            }
            $attendees[] = [
                'id' => $attendeeId,
                'name' => $attendeeName,
                'position_no' => (int)($attendeeRow['position_no'] ?? 0),
                'checked_in_at' => (string)($attendeeRow['checked_in_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $attendees = [];
    }

    if (!$attendees) {
        $attendees[] = [
            'id' => 0,
            'name' => $ownerName !== '' ? $ownerName : 'Pemesan',
            'position_no' => 1,
            'checked_in_at' => (string)($row['checked_in_at'] ?? ''),
        ];
    }

    if ($mode === 'checkin') {
        $attendeeId = is_array($json) ? (int)($json['attendee_id'] ?? 0) : (int)($_POST['attendee_id'] ?? 0);
        if ($attendeeId <= 0) {
            echo json_encode([
                'ok' => false,
                'message' => 'Pilih nama attendee dulu.',
            ]);
            exit;
        }

        $selected = null;
        foreach ($attendees as $attendee) {
            if ((int)$attendee['id'] === $attendeeId) {
                $selected = $attendee;
                break;
            }
        }
        if (!$selected) {
            echo json_encode([
                'ok' => false,
                'message' => 'Attendee tidak valid untuk QR ini.',
            ]);
            exit;
        }

        if (!empty($selected['checked_in_at'])) {
            echo json_encode([
                'ok' => false,
                'message' => 'Attendee ini sudah check-in sebelumnya.',
            ]);
            exit;
        }

        $now = date('Y-m-d H:i:s');
        try {
            if ((int)$selected['id'] > 0) {
                $updAttendee = $db->prepare('UPDATE order_attendees SET checked_in_at = ? WHERE id = ? AND order_id = ?');
                $updAttendee->execute([$now, (int)$selected['id'], $orderId]);
            }
        } catch (Throwable $e) {
            // Continue to keep main flow usable even if attendee update fails.
        }

        try {
            $remainStmt = $db->prepare('SELECT COUNT(*) FROM order_attendees WHERE order_id = ? AND checked_in_at IS NULL');
            $remainStmt->execute([$orderId]);
            $remainingAfter = (int)$remainStmt->fetchColumn();
            if ($remainingAfter <= 0) {
                $db->prepare('UPDATE orders SET checked_in_at = ? WHERE id = ?')->execute([$now, $orderId]);
            } else {
                $db->prepare('UPDATE orders SET checked_in_at = NULL WHERE id = ?')->execute([$orderId]);
            }
        } catch (Throwable $e) {
            // Keep flow usable even if order aggregate update fails.
        }

        echo json_encode([
            'ok' => true,
            'order_id' => $orderId,
            'name' => (string)$selected['name'],
            'gender' => $orderGender,
            'checked_in_at' => $now,
            'message' => 'Check-in berhasil.',
        ]);
        exit;
    }

    $checked = 0;
    foreach ($attendees as $attendee) {
        if (!empty($attendee['checked_in_at'])) {
            $checked++;
        }
    }
    $total = count($attendees);
    $remaining = max(0, $total - $checked);

    echo json_encode([
        'ok' => true,
        'mode' => 'select_attendee',
        'order_id' => $orderId,
        'order_name' => $ownerName,
        'order_gender' => $orderGender,
        'total_tickets' => $total,
        'checked_in_count' => $checked,
        'remaining_count' => $remaining,
        'attendees' => $attendees,
        'message' => $remaining > 0
            ? 'Pilih nama attendee yang mau check-in.'
            : 'Semua attendee pada QR ini sudah check-in.',
    ]);
    exit;
}

$prefillToken = extract_qr_token((string)($_GET['token'] ?? ''));
$extraHead = <<<'HTML'
<style>
  .scan-wrap {
    width: min(1220px, 95vw);
    margin: 24px auto 50px;
  }

  .scan-page-head {
    margin-bottom: 14px;
    display: grid;
    gap: 8px;
  }

  .scan-title {
    margin: 0;
    font-size: clamp(28px, 3.1vw, 40px);
    line-height: 1.1;
  }

  .scan-sub {
    margin: 0;
    color: var(--muted);
    font-weight: 600;
  }

  .scan-grid {
    display: grid;
    grid-template-columns: minmax(320px, 0.95fr) minmax(420px, 1.25fr);
    gap: 14px;
    align-items: start;
  }

  .scan-pane {
    background: linear-gradient(160deg, #ffffff 0%, #f8fbff 100%);
    border: 1px solid var(--stroke);
    border-radius: 20px;
    box-shadow: 0 14px 35px rgba(17, 34, 62, 0.08);
    padding: 16px;
  }

  .pane-title {
    margin: 0 0 8px;
    font-size: 18px;
    font-weight: 900;
    color: #183a66;
  }

  .pane-sub {
    margin: 0 0 12px;
    color: #5a7292;
    font-size: 13px;
    font-weight: 700;
  }

  #qr-reader {
    width: 100%;
    min-height: 300px;
    border: 1px dashed #c6d8ef;
    border-radius: 14px;
    overflow: hidden;
    background: #f3f8ff;
  }

  .scan-actions {
    margin-top: 12px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .manual-form {
    margin-top: 12px;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 10px;
  }

  .manual-form input {
    min-height: 46px;
    border: 2px solid #d8e5f5;
    border-radius: 12px;
    padding: 10px 12px;
    font: inherit;
    font-weight: 600;
    color: #224161;
    background: #fff;
  }

  .manual-form input:focus {
    outline: none;
    border-color: #7fb0ff;
    box-shadow: 0 0 0 3px rgba(56, 130, 255, 0.16);
  }

  .result-box {
    border: 1px solid var(--stroke);
    border-radius: 14px;
    padding: 14px;
    display: none;
  }

  .result-box.show {
    display: block;
  }

  .result-box.success {
    background: #ecfbf0;
    border-color: #9ed9b0;
    color: #1d5d33;
  }

  .result-box.error {
    background: #fff1f1;
    border-color: #f4b3b3;
    color: #8f2e2e;
  }

  .history-box {
    border: 1px solid var(--stroke);
    border-radius: 14px;
    padding: 14px;
    background: #f8fbff;
  }

  .history-title {
    margin: 0 0 10px;
    font-size: 18px;
    font-weight: 800;
  }

  .history-empty {
    margin: 0;
    color: var(--muted);
  }

  .history-list {
    margin: 0;
    padding-left: 18px;
    display: grid;
    gap: 6px;
  }

  .gender-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 999px;
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 800;
    border: 1px solid transparent;
  }

  .gender-chip.male {
    color: #0b4e9e;
    background: #e8f3ff;
    border-color: #b9d8ff;
  }

  .gender-chip.female {
    color: #9a2a66;
    background: #ffedf5;
    border-color: #ffc4dc;
  }

  .gender-chip.unknown {
    color: #425773;
    background: #eff3f8;
    border-color: #d1dbe8;
  }

  .profile-card {
    margin: 8px 0 10px;
    border: 1px solid #d2deee;
    background: #fff;
    border-radius: 16px;
    padding: 12px;
    display: grid;
    grid-template-columns: 86px 1fr;
    gap: 12px;
  }

  .profile-avatar {
    width: 78px;
    height: 78px;
    border-radius: 999px;
    position: relative;
    display: grid;
    place-items: center;
    color: #fff;
    animation: floaty 2s ease-in-out infinite;
    box-shadow: 0 10px 18px rgba(20, 41, 74, 0.2);
  }

  .profile-avatar .face {
    font-size: 30px;
    z-index: 2;
  }

  .profile-avatar::after {
    content: '';
    position: absolute;
    inset: 5px;
    border: 2px solid rgba(255, 255, 255, 0.55);
    border-radius: 999px;
    animation: pulse-ring 2.1s ease-in-out infinite;
  }

  .profile-avatar.male {
    background: linear-gradient(160deg, #1d78ff, #66b6ff);
  }

  .profile-avatar.female {
    background: linear-gradient(160deg, #f35aa4, #ff95c8);
  }

  .profile-avatar.unknown {
    background: linear-gradient(160deg, #8096b5, #acbbcf);
  }

  .profile-body {
    display: grid;
    gap: 7px;
    align-content: center;
  }

  .profile-name {
    margin: 0;
    font-size: 21px;
    font-weight: 900;
    color: #19385f;
  }

  .profile-role {
    margin: 0;
    font-size: 12px;
    color: #607995;
    font-weight: 700;
    letter-spacing: 0.2px;
  }

  .profile-kpis {
    display: inline-flex;
    gap: 6px;
    flex-wrap: wrap;
  }

  .mini-kpi {
    border-radius: 10px;
    background: #f4f8ff;
    border: 1px solid #d9e6f5;
    padding: 4px 8px;
    font-size: 11px;
    font-weight: 800;
    color: #33527a;
  }

  @keyframes floaty {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-4px); }
  }

  @keyframes pulse-ring {
    0%, 100% { transform: scale(1); opacity: .55; }
    50% { transform: scale(1.08); opacity: 1; }
  }

  .result-stack {
    display: grid;
    gap: 12px;
  }

  @media (max-width: 1020px) {
    .scan-grid {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 700px) {
    .manual-form {
      grid-template-columns: 1fr;
    }
    .profile-card {
      grid-template-columns: 1fr;
      justify-items: center;
      text-align: center;
    }
    .profile-kpis {
      justify-content: center;
    }
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
  <section class="scan-page-head">
    <h1 class="scan-title"><i class="bi bi-qr-code-scan"></i> Scan QR Check-In</h1>
    <p class="scan-sub">1 QR bisa dipakai sesuai jumlah tiket. Scan dulu, lalu pilih nama attendee yang check-in.</p>
  </section>

  <section class="scan-grid">
    <article class="scan-pane">
      <h2 class="pane-title">Scanner Console</h2>
      <p class="pane-sub">Dukung kamera browser dan scanner gun (USB HID keyboard).</p>

      <div id="qr-reader"></div>

      <div class="scan-actions">
        <a class="btn ghost" href="/admin/dashboard"><i class="bi bi-arrow-left-circle"></i> Dashboard</a>
        <button class="btn primary" id="startScan" type="button"><i class="bi bi-camera-video"></i> Start</button>
        <button class="btn ghost" id="stopScan" type="button"><i class="bi bi-stop-circle"></i> Stop</button>
      </div>

      <form class="manual-form" id="manualForm" method="post" action="/admin/scan">
        <input
          id="manualToken"
          name="token"
          type="text"
          placeholder="Paste token / URL QR di sini"
          value="<?= h($prefillToken) ?>"
          autocomplete="off"
        >
        <button class="btn ghost" type="submit"><i class="bi bi-keyboard"></i> Verify</button>
      </form>
    </article>

    <article class="scan-pane result-stack">
      <h2 class="pane-title">Check-In Result</h2>
      <p class="pane-sub">Panel ini menampilkan profile attendee dan status check-in terbaru.</p>
      <div class="result-box" id="resultBox" aria-live="polite"></div>
      <div class="history-box" id="historyBox">
        <p class="history-title"><i class="bi bi-clock-history"></i> Riwayat Scan (Sesi Ini)</p>
        <p class="history-empty" id="historyEmpty">Belum ada data scan.</p>
        <ol class="history-list" id="historyList" style="display:none;"></ol>
      </div>
    </article>
  </section>
</main>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
  (function () {
    var resultBox = document.getElementById('resultBox');
    var startBtn = document.getElementById('startScan');
    var stopBtn = document.getElementById('stopScan');
    var manualForm = document.getElementById('manualForm');
    var manualToken = document.getElementById('manualToken');
    var historyEmpty = document.getElementById('historyEmpty');
    var historyList = document.getElementById('historyList');
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
    }

    function escapeHtml(text) {
      return String(text == null ? '' : text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function normalizeGender(raw) {
      var v = String(raw || '').toLowerCase().trim();
      if (v === 'male' || v === 'm' || v === 'laki-laki' || v === 'laki') return 'male';
      if (v === 'female' || v === 'f' || v === 'perempuan' || v === 'wanita') return 'female';
      return 'unknown';
    }

    function genderLabel(g) {
      if (g === 'male') return 'Male';
      if (g === 'female') return 'Female';
      return 'Unknown';
    }

    function genderFace(g) {
      if (g === 'male') return '🧑';
      if (g === 'female') return '👩';
      return '🙂';
    }

    function genderIcon(g) {
      if (g === 'male') return 'bi-gender-male';
      if (g === 'female') return 'bi-gender-female';
      return 'bi-gender-ambiguous';
    }

    function renderProfileCard(name, role, gender, stats) {
      var g = normalizeGender(gender);
      var s = stats || {};
      return '<div class="profile-card">' +
        '<div class="profile-avatar ' + g + '"><span class="face">' + genderFace(g) + '</span></div>' +
        '<div class="profile-body">' +
          '<p class="profile-name">' + escapeHtml(name || '-') + '</p>' +
          '<p class="profile-role">' + escapeHtml(role || '') + ' <span class="gender-chip ' + g + '"><i class="bi ' + genderIcon(g) + '"></i> ' + escapeHtml(genderLabel(g)) + '</span></p>' +
          '<div class="profile-kpis">' +
            '<span class="mini-kpi">Total: ' + escapeHtml(String(s.total || 0)) + '</span>' +
            '<span class="mini-kpi">Checked: ' + escapeHtml(String(s.checked || 0)) + '</span>' +
            '<span class="mini-kpi">Sisa: ' + escapeHtml(String(s.remaining || 0)) + '</span>' +
          '</div>' +
        '</div>' +
      '</div>';
    }

    function renderHistory() {
      if (!historyEmpty || !historyList) return;
      if (!scanHistory.length) {
        historyEmpty.style.display = '';
        historyList.style.display = 'none';
        historyList.innerHTML = '';
        return;
      }
      historyEmpty.style.display = 'none';
      historyList.style.display = 'grid';
      historyList.innerHTML = scanHistory.map(function (item) {
        return '<li><strong>Order #' + escapeHtml(String(item.order_id || '-')) + '</strong> - ' +
          escapeHtml(item.name || '-') + ' <span style="opacity:.78;">(' + escapeHtml(item.time || '-') + ')</span></li>';
      }).join('');
    }

    function processHardwareScan(raw) {
      var value = String(raw || '').trim();
      if (!value) return;
      if (manualToken) {
        manualToken.value = value;
      }
      verifyToken(value);
    }

    function setupHardwareScannerCapture() {
      // Most USB scanner guns act as a keyboard and send data quickly + Enter.
      document.addEventListener('keydown', function (e) {
        var key = e.key || '';
        var now = Date.now();

        if (key === 'Shift' || key === 'Control' || key === 'Alt' || key === 'Meta' || key === 'CapsLock') {
          return;
        }

        if (now - hwLastTs > hwMaxGapMs) {
          hwBuffer = '';
        }
        hwLastTs = now;

        if (key === 'Enter') {
          var isLikelyScanner = hwBuffer.length >= hwMinLen;
          var fallbackInput = manualToken ? String(manualToken.value || '').trim() : '';
          if (isLikelyScanner) {
            e.preventDefault();
            processHardwareScan(hwBuffer);
            hwBuffer = '';
            return;
          }
          if (fallbackInput) {
            e.preventDefault();
            processHardwareScan(fallbackInput);
            hwBuffer = '';
            return;
          }
          hwBuffer = '';
          return;
        }

        if (key === 'Backspace') {
          hwBuffer = hwBuffer.slice(0, -1);
          return;
        }

        if (key.length === 1) {
          hwBuffer += key;
        }
      }, true);
    }

    function stopScanner() {
      if (!scanner || !scanning) return Promise.resolve();
      scanning = false;
      return scanner.stop().catch(function () {}).then(function () {
        return scanner.clear().catch(function () {});
      });
    }

    function postJson(payload) {
      var endpoint = manualForm.getAttribute('action') || window.location.pathname || '/admin/scan';
      return fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload || {})
      })
      .then(function (res) {
        var contentType = res.headers.get('content-type') || '';
        if (contentType.indexOf('application/json') === -1) {
          return res.text().then(function (txt) {
            var isLoginHtml = txt.indexOf('Admin Login') !== -1 || txt.indexOf('/admin/login') !== -1;
            if (isLoginHtml || res.status === 401 || res.status === 403) {
              throw new Error('Sesi admin habis. Silakan login lagi.');
            }
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
      .then(function (data) {
        if (!data || !data.ok) {
          showResult('error', '<strong>Gagal:</strong> ' + escapeHtml((data && data.message) || 'Verifikasi gagal.'));
          return;
        }

        var attendees = Array.isArray(data.attendees) ? data.attendees : [];
        var total = Number(data.total_tickets || attendees.length || 0);
        var checked = Number(data.checked_in_count || 0);
        var remain = Number(data.remaining_count || 0);
        var orderGender = normalizeGender(data.order_gender);
        var summary = '<p style="margin:0 0 6px;"><strong>Order #' + escapeHtml(String(data.order_id || '-')) + '</strong></p>' +
          renderProfileCard(data.order_name || '-', 'Order Owner', orderGender, { total: total, checked: checked, remaining: remain }) +
          '<p style="margin:0 0 12px;">Ticket: ' + escapeHtml(String(total)) + ' | Sudah check-in: ' + escapeHtml(String(checked)) + ' | Sisa: ' + escapeHtml(String(remain)) + '</p>';

        if (!attendees.length) {
          showResult('error', summary + '<p style="margin:0;">Data attendee tidak ditemukan.</p>');
          return;
        }

        var listHtml = attendees.map(function (a) {
          var aid = Number(a.id || 0);
          var isChecked = !!(a.checked_in_at);
          if (isChecked) {
            return '<div style="padding:8px 10px;border:1px solid #9ed9b0;border-radius:10px;background:#ecfbf0;margin-bottom:8px;">' +
              '<strong>' + escapeHtml(a.name || '-') + '</strong> <span style="opacity:0.8;">(sudah check-in)</span>' +
            '</div>';
          }
          return '<button type="button" class="btn primary attendee-checkin-btn" data-attendee-id="' + aid + '" data-token="' + escapeHtml(cleanToken) + '" data-name="' + escapeHtml(a.name || '-') + '" style="margin:0 8px 8px 0;">' +
            '<i class="bi bi-person-check"></i> Check-in ' + escapeHtml(a.name || '-') +
          '</button>';
        }).join('');

        showResult('success', summary + '<p style="margin:0 0 8px;"><strong>Pilih nama yang check-in:</strong></p>' + listHtml);

        resultBox.querySelectorAll('.attendee-checkin-btn').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var attendeeId = Number(btn.getAttribute('data-attendee-id') || 0);
            var tokenForCheckin = btn.getAttribute('data-token') || '';
            var attendeeName = btn.getAttribute('data-name') || '-';
            submitCheckin(tokenForCheckin, attendeeId, attendeeName);
          });
        });
      })
      .catch(function (err) {
        var msg = err && err.message ? err.message : 'Gagal koneksi ke server.';
        showResult('error', '<strong>Error:</strong> ' + escapeHtml(msg));
      })
      .finally(function () {
        submitting = false;
      });
    }

    function submitCheckin(token, attendeeId, attendeeName) {
      if (submitting) return;
      submitting = true;
      var refreshToken = token || '';
      postJson({
        mode: 'checkin',
        token: refreshToken,
        attendee_id: attendeeId || 0
      })
      .then(function (data) {
        if (!data || !data.ok) {
          showResult('error', '<strong>Gagal:</strong> ' + escapeHtml((data && data.message) || 'Check-in gagal.'));
          return;
        }
        var checkedGender = normalizeGender(data.gender);
        var title = renderProfileCard(
          'Welcome, ' + (data.name || attendeeName || '-'),
          'Check-In Confirmed',
          checkedGender,
          { total: 1, checked: 1, remaining: 0 }
        );
        var info = '<p style="margin:0;"><strong>Order #</strong> ' + escapeHtml(String(data.order_id || '-')) + '</p>' +
          '<p style="margin:4px 0 0;">' + escapeHtml(data.message || 'Check-in berhasil.') + '</p>' +
          '<p style="margin:4px 0 0;">Check-in: ' + escapeHtml(data.checked_in_at || '-') + '</p>' +
          '<p style="margin:8px 0 0;"><strong>Silakan scan ulang QR untuk attendee berikutnya.</strong></p>';
        showResult('success', title + info);
        scanHistory.unshift({
          order_id: data.order_id || '-',
          name: data.name || attendeeName || '-',
          time: data.checked_in_at || '-'
        });
        renderHistory();
        if (manualToken) {
          manualToken.value = '';
        }
      })
      .catch(function (err) {
        var msg = err && err.message ? err.message : 'Gagal koneksi ke server.';
        showResult('error', '<strong>Error:</strong> ' + escapeHtml(msg));
      })
      .finally(function () {
        submitting = false;
      });
    }

    function startScanner() {
      if (scanning) return;
      if (typeof Html5Qrcode === 'undefined') {
        showResult('error', '<strong>Error:</strong> Library scanner gagal dimuat.');
        return;
      }

      scanner = new Html5Qrcode('qr-reader');
      scanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: 250 },
        function (decodedText) {
          stopScanner().finally(function () {
            verifyToken(decodedText || '');
          });
        },
        function () {}
      ).then(function () {
        scanning = true;
      }).catch(function () {
        showResult('error', '<strong>Error:</strong> Kamera tidak bisa dibuka. Izinkan akses kamera atau gunakan verify manual.');
      });
    }

    startBtn.addEventListener('click', function () {
      startScanner();
    });

    stopBtn.addEventListener('click', function () {
      stopScanner();
    });

    manualForm.addEventListener('submit', function (e) {
      e.preventDefault();
      verifyToken(manualToken.value || '');
    });

    if (manualToken.value) {
      verifyToken(manualToken.value);
    }
    setupHardwareScannerCapture();
    renderHistory();
  })();
</script>
<?php render_footer(['isAdmin' => true]); ?>
