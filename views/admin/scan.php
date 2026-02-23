<?php
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../layout/app.php';
require_admin();

$db = get_db();
ensure_order_qr_schema($db);
ensure_order_attendee_checkin_schema($db);
ensure_order_attendee_package_schema($db);
ensure_order_attendee_court_schema($db);

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$basePath = ($scriptDir === '/' || $scriptDir === '.') ? '' : rtrim($scriptDir, '/');

$sponsorItems = [];
try {
    $sponsorStmt = $db->query('SELECT name, website_url, logo_path FROM sponsors ORDER BY id DESC');
    foreach ($sponsorStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $logoPath = trim((string)($row['logo_path'] ?? ''));
        if ($logoPath === '') continue;
        if (preg_match('/^https?:\\/\\//i', $logoPath)) { $logoSrc = $logoPath; }
        else { $logoSrc = $basePath . '/' . ltrim($logoPath, '/'); }
        $sponsorItems[] = [
            'name' => trim((string)($row['name'] ?? 'Sponsor')),
            'logo' => $logoSrc,
            'url'  => filter_var(trim((string)($row['website_url'] ?? '')), FILTER_VALIDATE_URL) ? trim((string)($row['website_url'] ?? '')) : '',
        ];
    }
} catch (Throwable $e) { $sponsorItems = []; }

if (!$sponsorItems) {
    $sponsorItems = [
        ['name' => 'HIPPI',    'logo' => $basePath . '/assets/img/hippi.png',   'url' => 'https://www.hippi.or.id/'],
        ['name' => 'BAPORA',   'logo' => $basePath . '/assets/img/logo.webp',   'url' => 'https://www.hippi.or.id/'],
        ['name' => 'FCOM',     'logo' => $basePath . '/assets/img/fcom.png',    'url' => 'https://fcom.co.id/'],
        ['name' => 'MY Padel', 'logo' => $basePath . '/assets/img/mypadel.png', 'url' => 'https://ayo.co.id/v/mypadel'],
    ];
}

$adsTableSql = <<<SQL
CREATE TABLE IF NOT EXISTS ads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  video_path VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

try {
    $db->exec($adsTableSql);
} catch (Throwable $e) {
    // ignore
}

$adSources = [];
try {
    $adStmt = $db->query('SELECT id, title, video_path FROM ads ORDER BY id ASC');
    foreach ($adStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $videoPath = trim((string)($row['video_path'] ?? ''));
        if ($videoPath === '') {
            continue;
        }
        if (!preg_match('/^https?:\\/\\//i', $videoPath)) {
            $videoPath = '/' . ltrim($videoPath, '/');
        }
        $adSources[] = [
            'id' => (int)($row['id'] ?? 0),
            'title' => trim((string)($row['title'] ?? '')),
            'url' => $videoPath,
        ];
    }
} catch (Throwable $e) {
    $adSources = [];
}

function normalize_gender_value($rawGender) {
    $gender = strtolower(trim((string)$rawGender));
    if (in_array($gender, ['male','m','laki-laki','laki','pria'], true)) return 'male';
    if (in_array($gender, ['female','f','perempuan','wanita'], true)) return 'female';
    return 'unknown';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $rawInput = file_get_contents('php://input');
    $json = json_decode((string)$rawInput, true);
    $mode    = is_array($json) ? trim((string)($json['mode'] ?? 'resolve')) : trim((string)($_POST['mode'] ?? 'resolve'));
    $scanRaw = is_array($json) && isset($json['token']) ? (string)$json['token'] : (string)($_POST['token'] ?? '');
    $token   = extract_qr_token($scanRaw);
    if ($token === '') { echo json_encode(['ok'=>false,'message'=>'QR tidak valid. Coba scan ulang.']); exit; }

    $stmt = $db->prepare('SELECT o.id, o.status, o.checked_in_at, u.full_name, u.gender FROM orders o JOIN users u ON u.id = o.user_id WHERE o.qr_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok'=>false,'message'=>'QR token tidak ditemukan.']); exit; }
    if ((string)($row['status'] ?? '') !== 'accepted') { echo json_encode(['ok'=>false,'message'=>'Order ini belum berstatus accepted.']); exit; }

    $orderId     = (int)$row['id'];
    $ownerName   = trim((string)($row['full_name'] ?? ''));
    $orderGender = normalize_gender_value($row['gender'] ?? '');
    $packagePool = [];
    try {
        $packageStmt = $db->prepare('SELECT p.name, oi.qty FROM order_items oi JOIN packages p ON p.id = oi.package_id WHERE oi.order_id = ? ORDER BY p.name ASC');
        $packageStmt->execute([$orderId]);
        foreach ($packageStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $qty = max(1, (int)($pr['qty'] ?? 0));
            $label = trim((string)($pr['name'] ?? 'Package'));
            for ($i = 0; $i < $qty; $i++) $packagePool[] = $label;
        }
    } catch (Throwable $e) { $packagePool = []; }

    $attendees = [];
    try {
        $attendeeStmt = $db->prepare('SELECT oa.id, oa.attendee_name, oa.gender, oa.position_no, oa.checked_in_at, oa.package_id, oa.court_no, p.name AS package_name FROM order_attendees oa LEFT JOIN packages p ON p.id = oa.package_id WHERE oa.order_id = ? ORDER BY oa.position_no ASC, oa.id ASC');
        $attendeeStmt->execute([$orderId]);
        $packIdx = 0;
        foreach ($attendeeStmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
            $aid = (int)($ar['id'] ?? 0);
            if ($aid <= 0) continue;
            $aname = trim((string)($ar['attendee_name'] ?? ''));
            if ($aname === '') $aname = 'Attendee #' . (int)($ar['position_no'] ?? 0);
            $packageLabel = trim((string)($ar['package_name'] ?? ''));
            if ($packageLabel === '') {
                $packageLabel = (string)($packagePool[$packIdx] ?? '');
            }
            $attendees[] = ['id'=>$aid,'name'=>$aname,'gender'=>normalize_gender_value($ar['gender']??''),'position_no'=>(int)($ar['position_no']??0),'checked_in_at'=>(string)($ar['checked_in_at']??''),'package'=>$packageLabel,'court_no'=>(int)($ar['court_no']??0)];
            $packIdx++;
        }
    } catch (Throwable $e) { $attendees = []; }

    if (!$attendees) {
        $attendees[] = ['id'=>0,'name'=>$ownerName!==''?$ownerName:'Pemesan','gender'=>$orderGender,'position_no'=>1,'checked_in_at'=>(string)($row['checked_in_at']??''),'package'=>(string)($packagePool[0]??''),'court_no'=>0];
    }

    if ($mode === 'checkin') {
        $attendeeId = is_array($json) ? (int)($json['attendee_id']??0) : (int)($_POST['attendee_id']??0);
        if ($attendeeId <= 0) { echo json_encode(['ok'=>false,'message'=>'Pilih nama attendee dulu.']); exit; }
        $selected = null;
        foreach ($attendees as $a) { if ((int)$a['id']===$attendeeId){$selected=$a;break;} }
        if (!$selected) { echo json_encode(['ok'=>false,'message'=>'Attendee tidak valid.']); exit; }
        if (!empty($selected['checked_in_at'])) { echo json_encode(['ok'=>false,'message'=>'Attendee ini sudah check-in sebelumnya.']); exit; }
        $now = date('Y-m-d H:i:s');
        try { if ((int)$selected['id']>0){ $db->prepare('UPDATE order_attendees SET checked_in_at=? WHERE id=? AND order_id=?')->execute([$now,(int)$selected['id'],$orderId]); } } catch(Throwable $e){}
        try {
            $rs = $db->prepare('SELECT COUNT(*) FROM order_attendees WHERE order_id=? AND checked_in_at IS NULL');
            $rs->execute([$orderId]); $rc=(int)$rs->fetchColumn();
            if ($rc<=0){ $db->prepare('UPDATE orders SET checked_in_at=? WHERE id=?')->execute([$now,$orderId]); }
            else { $db->prepare('UPDATE orders SET checked_in_at=NULL WHERE id=?')->execute([$orderId]); }
        } catch(Throwable $e){}
        echo json_encode(['ok'=>true,'order_id'=>$orderId,'name'=>(string)$selected['name'],'gender'=>normalize_gender_value($selected['gender']??$orderGender),'checked_in_at'=>$now,'package'=>(string)($selected['package']??''),'court_no'=>(int)($selected['court_no']??0),'message'=>'Check-in berhasil.']);
        exit;
    }

    $checked=0; foreach($attendees as $a){if(!empty($a['checked_in_at']))$checked++;}
    $total=count($attendees);
    echo json_encode(['ok'=>true,'mode'=>'select_attendee','order_id'=>$orderId,'order_name'=>$ownerName,'order_gender'=>$orderGender,'total_tickets'=>$total,'checked_in_count'=>$checked,'remaining_count'=>max(0,$total-$checked),'attendees'=>$attendees]);
    exit;
}

$prefillToken = extract_qr_token((string)($_GET['token'] ?? ''));

$extraHead = <<<'HTML'
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; background: #08091a; }

body.admin-page {
  font-family: 'Plus Jakarta Sans', sans-serif;
  background: url('/assets/img/wallapapeh67.jpeg') center/cover fixed;
  color: #f8fafc;
  font-size: 15px;
}
body.admin-page .page-header,
body.admin-page footer,
body.admin-page nav,
body.admin-page .admin-nav,
body.admin-page .sidebar,
body.admin-page::before,
body.admin-page::after { display: none !important; }

#app {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: url('/assets/img/wallapapeh67.jpeg') center/cover fixed;
  background-color: #07091a;
}

/* ─── Top Bar ────────────────────────────────── */
#topbar {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 36px; min-height: 70px;
  background: transparent; flex-shrink: 0;
  position: sticky; top: 0; z-index: 50;
}
.topbar-left { display:flex; flex-direction:column; align-items:flex-start; gap:6px; margin-top:-4px; }
.topbar-icon, .topbar-title { display: none; }
.topbar-right { display:flex; align-items:center; gap:12px; }
.clock-block { display:flex; flex-direction:column; gap:4px; }

#clock {
  font-size: 44px; font-weight: 800; color: #fff;
  font-variant-numeric: tabular-nums; letter-spacing: -0.03em;
  text-shadow: 0 2px 16px rgba(0,0,0,0.7), 0 0 40px rgba(0,0,0,0.4);
}
#clockDate {
  font-size: 13px; letter-spacing: 0.3em; text-transform: uppercase;
  color: rgba(255, 255, 255, 0.93); font-weight: 700;
  text-shadow: 0 1px 8px rgba(0,0,0,0 .6);
}

.btn-back {
  display:inline-flex; align-items:center; gap:6px; padding:6px 12px;
  border-radius:8px; font-family:inherit; font-size:13px; font-weight:600;
  color:rgba(241,245,249,0.5); background:rgba(255,255,255,0.05);
  border:1px solid rgba(255,255,255,0.09); text-decoration:none; transition:0.15s;
}
.btn-back:hover { color:#f1f5f9; background:rgba(255,255,255,0.1); }

.btn-fs {
  width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center;
  background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.09);
  color:rgb(255, 255, 255); font-size:14px; cursor:pointer; transition:0.15s; flex-shrink:0;
}
.btn-fs:hover { background:rgba(255,255,255,0.1); color:#f1f5f9; }

/* ─── Stage ──────────────────────────────────── */
#stage {
  flex: 1; display:flex; align-items:center; justify-content:center;
  position:relative; overflow:hidden; min-height:0;
  padding-bottom: 96px; /* ruang logo strip */
}
#stage.stage--scanned {
  justify-content:center;
  align-items:center;
  padding-top: 0;
}

#stage.stage--scanned #picker-screen {
  padding-top: clamp(140px, 20vh, 220px);
}

#stage.stage--welcome #welcome-screen {
  padding-top: clamp(8px, 1.4vh, 18px);
}

@keyframes resultHeadFloat {
  0%   { transform: translate(-50%, 40px); opacity: 0; }
  40%  { transform: translate(-50%, 14px); opacity: 0.7; }
  100% { transform: translate(-50%, 0); opacity: 1; }
}

#result-head {
  position: absolute;
  top: 37%;
  left: 50%;
  transform: translate(-50%, -50%);
  margin-top: -72px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  pointer-events: none;
  width: min(92vw, 760px);
  text-align: center;
  z-index: 15;
}

#result-head::before {
  content: '';
  position:absolute;
  inset: -18px;
  border-radius: 60px;
  filter: blur(16px);
  background: radial-gradient(circle at center, rgba(56,189,248,0.25), transparent 60%);
  opacity: 0.65;
  pointer-events:none;
}
@keyframes resultHeadGlow {
  0% { box-shadow: 0 12px 30px rgba(56,189,248,0.24); }
  50% { box-shadow: 0 20px 55px rgba(56,189,248,0.35); }
  100% { box-shadow: 0 12px 30px rgba(56,189,248,0.24); }
}
#stage.stage--scanned #result-head {
  top: clamp(-10px, 1.5vh, 24px);
  bottom: auto;
  left: 50%;
  transform: translate(-50%, 0);
  opacity: 1;
  animation: none;
}
#stage.stage--picker #result-head {
  opacity: 0;
  visibility: hidden;
  transform: translate(-50%, -16px);
}
#stage.stage--welcome #result-head {
  opacity: 0;
  visibility: hidden;
  transform: translate(-50%, -16px);
}
#stage.stage--scanned #result-head .idle-title,
#stage.stage--scanned #result-head .idle-subtitle,
#stage.stage--scanned #result-head .idle-hint {
  animation: resultHeadGlow 2.4s ease-in-out infinite;
}
#stage.stage--picker #result-head .idle-subtitle {
  display: none;
}
#stage.stage--picker #result-head .idle-hint {
  display: none;
}
#stage.stage--picker #picker-screen {
  padding-top: clamp(88px, 13vh, 132px);
  scroll-padding-top: clamp(88px, 13vh, 132px);
}

/* ─── Idle Screen ────────────────────────────── */
#idle-screen {
  position:absolute; inset:0;
  display:flex; align-items:center; justify-content:center;
  padding:clamp(60px,10vh,100px) 24px 40px;
  animation: fadeUp 0.5s ease-out;
  z-index:1;
  pointer-events:none;
}

.idle-qr-ring {
  width:96px; height:96px; border-radius:22px;
  background:rgba(56,189,248,0.07); border:1.5px dashed rgba(56,189,248,0.3);
  display:flex; align-items:center; justify-content:center;
  margin-top: clamp(210px, 30vh, 320px);
  font-size:40px; color:rgba(56,189,248,0.5); animation:bob 3s ease-in-out infinite; position:relative;
}
.idle-qr-ring::before {
  content:''; position:absolute; inset:-10px; border-radius:28px;
  border:1px dashed rgba(56,189,248,0.12);
}

/* ═══ TEMU PADEL — diperkuat kontrasnya ══════ */
.idle-title {
  font-size: clamp(48px, 7vw, 86px);
  font-weight: 900;
  color: #ffffff;
  letter-spacing: -0.04em;
  line-height: 1.05;
  text-shadow:
    0 2px 8px rgba(0,0,0,0.75),
    0 8px 22px rgba(0,0,0,0.55);
}

.idle-subtitle {
  font-size: clamp(20px, 3vw, 27px);
  letter-spacing: 0.38em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.95);
  font-weight: 800;
  text-shadow: 0 1px 6px rgba(0,0,0,0.55), 0 4px 16px rgba(0,0,0,0.35);
}
.idle-subtitle.idle-subtitle--brand {
  font-size: clamp(18px, 2.4vw, 30px);
  font-weight: 900;
  letter-spacing: 0.22em;
  margin-bottom: -10px;
}

.idle-hint {
  font-size: 13px; color: rgba(0, 0, 0, 0.38); font-weight: 500;
  display:flex; align-items:center; gap:10px;
  text-shadow: 0 1px 4px rgba(0,0,0,0.5);
}
.idle-hint-icon {
  font-size: 15px;
  color: rgba(56,189,248,0.9);
  line-height: 1;
  transform: translateY(-1px);
}
.idle-hint::before, .idle-hint::after {
  content:''; width:28px; height:1px; background:linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
}

@keyframes bob    { 0%,100%{transform:translateY(0);} 50%{transform:translateY(-6px);} }
@keyframes fadeUp { from{opacity:0;transform:translateY(10px);} to{opacity:1;transform:translateY(0);} }
@keyframes floaty { 0%,100%{transform:translateY(0);} 50%{transform:translateY(-12px);} }

/* ─── Welcome Screen ─────────────────────────── */
#welcome-screen {
  display:none; flex-direction:column; align-items:center; justify-content:center;
  text-align:center;
  position:fixed;
  top:20px; left:0; right:0; bottom:88px;
  padding: clamp(6px,2vh,14px) clamp(10px,4vw,28px);
  animation: welcomeIn 0.6s cubic-bezier(0.22,1,0.36,1);
  overflow:hidden;
  isolation:isolate;
  z-index:10;
  background: transparent;
}
#welcome-screen::before,
#welcome-screen::after {
  display:none;
}
@keyframes welcomeIn { from{opacity:0;transform:scale(0.96) translateY(30px);} to{opacity:1;transform:scale(1) translateY(0);} }
.welcome-panel{
  width:auto;
  max-width: min(92vw, 760px);
  transform: none;
  border-radius: 0;
  border: none;
  background: transparent;
  box-shadow: none;
  backdrop-filter: none;
  -webkit-backdrop-filter: none;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  padding:0;
  position:relative;
  overflow:visible;
  animation:none;
}
.welcome-panel > *{ position:relative; z-index:1; }
.welcome-avatar { width:clamp(140px,18vw,220px); height:clamp(140px,18vw,220px); margin-bottom:clamp(24px,3vw,40px); position:relative; animation:floaty 2.8s ease-in-out infinite; flex-shrink:0; }
.welcome-avatar::before{
  content:'';
  position:absolute;
  inset:-22px;
  border-radius:50%;
  background:radial-gradient(circle, rgba(125,211,252,0.42), rgba(56,189,248,0.1) 48%, transparent 74%);
  filter:blur(6px);
  animation:welcomePulse 2.8s ease-in-out infinite;
  z-index:-1;
}
.welcome-avatar img { width:100%; height:100%; object-fit:contain; filter:drop-shadow(0 8px 40px rgba(0,0,0,0.5)); }
.welcome-greeting { font-size:clamp(22px,3vw,48px); font-weight:600; color:rgba(255,255,255,0.98); letter-spacing:0.08em; margin-bottom:12px; line-height:1.2; text-shadow:0 2px 12px rgba(0,0,0,0.8), 0 0 6px rgba(0,0,0,0.55); animation:welcomeTitleIn 0.75s cubic-bezier(.16,.8,.2,1) both; }
.welcome-name {
  font-size:clamp(32px,min(8vw,10vh),120px); font-weight:900; color:#fff;
  letter-spacing:-0.04em; line-height:1; margin-bottom:0; max-width:min(92vw,880px);
  text-shadow:0 2px 4px rgba(0,0,0,1),0 8px 30px rgba(0,0,0,0.9),0 20px 60px rgba(0,0,0,0.5);
  word-wrap:break-word; hyphens:auto; white-space:normal;
  animation:welcomeNameIn 0.95s cubic-bezier(.14,.82,.2,1) both;
}
.welcome-package { font-size:clamp(20px,2.7vw,32px); font-weight:800; letter-spacing:0.2em; text-transform:uppercase; color:rgba(255,255,255,0.95); margin-top:12px; text-shadow:0 2px 12px rgba(0,0,0,0.8), 0 0 10px rgba(255,255,255,0.2); animation:welcomePackageIn 1.05s ease both, welcomePackageGlow 2.6s ease-in-out infinite 1s; }
.welcome-court { font-size:clamp(17px,2.1vw,26px); font-weight:900; letter-spacing:0.18em; text-transform:uppercase; color:rgba(125,211,252,1); margin-top:8px; text-shadow:0 2px 12px rgba(0,0,0,0.8), 0 0 12px rgba(56,189,248,0.35); animation:welcomePackageIn 1.15s ease both; }
.welcome-badge,.welcome-time,.welcome-tags { display:none!important; }
@keyframes welcomeAura{
  0%,100%{ transform:scale(1); opacity:0.88; }
  50%{ transform:scale(1.05); opacity:1; }
}
@keyframes welcomeSweep{
  0%{ transform:translateX(-30%) rotate(-4deg); opacity:0.08; }
  45%{ opacity:0.26; }
  100%{ transform:translateX(20%) rotate(-4deg); opacity:0.1; }
}
@keyframes welcomePulse{
  0%,100%{ transform:scale(0.95); opacity:0.6; }
  50%{ transform:scale(1.05); opacity:1; }
}
@keyframes welcomeTitleIn{
  from{ opacity:0; transform:translateY(14px); }
  to{ opacity:1; transform:translateY(0); }
}
@keyframes welcomeNameIn{
  from{ opacity:0; transform:translateY(24px) scale(0.94); letter-spacing:-0.01em; }
  to{ opacity:1; transform:translateY(0) scale(1); letter-spacing:-0.04em; }
}
@keyframes welcomePackageIn{
  from{ opacity:0; transform:translateY(12px); }
  to{ opacity:1; transform:translateY(0); }
}
@keyframes welcomePackageGlow{
  0%,100%{ text-shadow:0 2px 12px rgba(0,0,0,0.6), 0 0 10px rgba(56,189,248,0.12); }
  50%{ text-shadow:0 2px 12px rgba(0,0,0,0.6), 0 0 18px rgba(56,189,248,0.35); }
}
@keyframes welcomePanelIn{
  from{ opacity:0; transform:translateY(26px) scale(0.96); }
  to{ opacity:1; transform:translateY(0) scale(1); }
}

/* ─── Picker Screen ──────────────────────────── */
#picker-screen {
  display:none; flex-direction:column; align-items:center; justify-content:flex-start;
  position:absolute;
  top:0; left:0; right:0;
  /* batas bawah = tinggi logo strip supaya tidak nabrak */
  bottom: 88px;
  padding:clamp(22px,4vh,50px) clamp(20px,7vw,110px) 16px;
  gap:14px; animation:fadeUp 0.35s ease-out;
  overflow-y:auto; overflow-x:hidden;
  scrollbar-width:thin; scrollbar-color:rgba(255,255,255,0.12) transparent;
  scroll-behavior:smooth;
  -webkit-overflow-scrolling:touch;
}
#picker-screen::-webkit-scrollbar{width:5px;}
#picker-screen::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.14);border-radius:999px;}
#picker-screen::-webkit-scrollbar-track{background:transparent;}
.picker-order { width:100%; max-width:760px; display:flex; align-items:center; gap:16px; padding:18px 22px; border-radius:16px; background:rgba(8,12,24,0.92); backdrop-filter:none; -webkit-backdrop-filter:none; border:1px solid rgba(255,255,255,0.18); box-shadow:0 10px 32px rgba(0,0,0,0.6); flex-shrink:0; }
.picker-avatar { width:60px; height:60px; border-radius:999px; flex-shrink:0; overflow:hidden; animation:bob 2.6s ease-in-out infinite; }
.picker-avatar img{width:100%;height:100%;object-fit:cover;border-radius:999px;}
.picker-avatar.male{background:linear-gradient(135deg,#1d78ff,#66b6ff);box-shadow:0 4px 16px rgba(29,120,255,0.35);}
.picker-avatar.female{background:linear-gradient(135deg,#f35aa4,#ff95c8);box-shadow:0 4px 16px rgba(243,90,164,0.35);}
.picker-avatar.unknown{background:linear-gradient(135deg,#7a8fa6,#aebdcc);}
.picker-order-info{flex:1;min-width:0;text-align:center;}
.picker-order-name{font-size:22px;font-weight:800;color:#f1f5f9;letter-spacing:-0.03em;margin-bottom:6px;}
.picker-order-meta{display:flex;gap:7px;flex-wrap:wrap;justify-content:center;}
.picker-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:rgba(241,245,249,0.35);display:flex;align-items:center;gap:10px;width:100%;max-width:760px;margin-top:4px;flex-shrink:0;}
.picker-label::after{content:'';flex:1;height:1px;background:rgba(255,255,255,0.08);}
.picker-list{display:flex;flex-direction:column;gap:10px;width:100%;max-width:760px;flex-shrink:0;padding-bottom:16px;}
.picker-row{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 20px;border-radius:14px;border:1px solid;transition:0.15s;}
.picker-row.pending{background:rgba(10,16,34,0.96);backdrop-filter:none;-webkit-backdrop-filter:none;border-color:rgba(148,163,184,0.34);}
.picker-row.pending:hover{background:rgba(15,26,48,0.98);border-color:rgba(56,189,248,0.45);}
.picker-row.done{background:rgba(9,36,25,0.96);backdrop-filter:none;-webkit-backdrop-filter:none;border-color:rgba(74,222,128,0.42);}
.picker-row-left{display:flex;align-items:center;gap:14px;min-width:0;flex:1;}
.picker-num{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;flex-shrink:0;}
.picker-num.pending{background:rgba(30,41,59,0.95);color:rgba(241,245,249,0.92);border:1px solid rgba(148,163,184,0.42);}
.picker-num.done{background:rgba(34,197,94,0.18);color:#4ade80;border:1px solid rgba(34,197,94,0.3);}
.picker-name{font-size:17px;font-weight:700;color:#f1f5f9;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.picker-name.done{color:#4ade80;}
.picker-package{font-size:14px;letter-spacing:0.16em;text-transform:uppercase;color:rgba(125,211,252,0.98);margin-top:3px;display:block;font-weight:800;}
.picker-court{font-size:13px;letter-spacing:0.14em;text-transform:uppercase;color:rgba(165,180,252,0.98);margin-top:3px;display:block;font-weight:800;}
.done-badge{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:999px;background:rgba(34,197,94,0.14);border:1px solid rgba(34,197,94,0.25);font-size:13px;font-weight:700;color:#4ade80;white-space:nowrap;flex-shrink:0;}
.btn-checkin{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;border-radius:10px;font-family:inherit;font-size:14px;font-weight:700;background:rgba(56,189,248,0.12);border:1px solid rgba(56,189,248,0.25);color:#38bdf8;cursor:pointer;transition:0.15s;white-space:nowrap;flex-shrink:0;}
.btn-checkin:hover{background:#38bdf8;color:#050611;border-color:#38bdf8;box-shadow:0 4px 16px rgba(56,189,248,0.4);transform:translateY(-1px);}

/* ─── Error Screen ───────────────────────────── */
#err-screen{
  display:none;
  position:fixed;
  inset:0;
  align-items:center;
  justify-content:center;
  background:rgba(2,6,23,0.75);
  backdrop-filter:blur(8px);
  padding:24px;
  animation:fadeUp 0.3s ease-out;
  z-index:60;
}
.err-modal{
  width:min(92vw,420px);
  background:rgba(8,14,30,0.96);
  border-radius:26px;
  padding:30px 28px;
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:16px;
  box-shadow:0 30px 60px rgba(0,0,0,0.65),0 0 0 1px rgba(255,255,255,0.05);
  text-align:center;
}
.err-icon{
  width:68px;
  height:68px;
  border-radius:50%;
  border:2px solid rgba(248,113,113,0.35);
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:32px;
  color:#f87171;
  background:rgba(248,113,113,0.08);
}
.err-title{font-size:26px;font-weight:800;color:#f87171;margin:0;letter-spacing:0.04em;}
.err-msg{font-size:16px;color:rgba(241,245,249,0.85);max-width:360px;margin:0;}
.err-close{border:none;background:rgba(248,113,113,0.12);color:#f87171;font-size:13px;font-weight:700;padding:10px 24px;border-radius:999px;cursor:pointer;transition:0.2s;letter-spacing:0.1em;}
.err-close:hover{background:rgba(248,113,113,0.25);}

/* ─── Tag util ───────────────────────────────── */
.tag{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.09);color:rgba(241,245,249,0.55);}
.tag.male{background:rgba(56,189,248,0.1);border-color:rgba(56,189,248,0.2);color:#7dd3fc;}
.tag.female{background:rgba(244,114,182,0.1);border-color:rgba(244,114,182,0.2);color:#f9a8d4;}
.tag.ok{background:rgba(34,197,94,0.1);border-color:rgba(34,197,94,0.2);color:#4ade80;}

.scanner-launcher {
  position:fixed;
  left:16px;
  bottom:96px;
  z-index:41;
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:8px 12px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,0.2);
  background:rgba(4,5,16,0.78);
  color:#f8fafc;
  font-size:12px;
  font-weight:700;
  cursor:pointer;
  backdrop-filter:blur(18px);
  box-shadow:0 10px 24px rgba(0,0,0,0.5);
  transition:0.2s;
}
.scanner-launcher:hover {
  transform:translateY(-2px);
  border-color:rgba(255,255,255,0.35);
}
.scanner-launcher .bi {
  font-size:16px;
}

/* ─── Scanner Widget ─────────────────────────── */
#scanner-widget{position:fixed;left:16px;bottom:96px;width:168px;background:rgba(4,5,16,0.8);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border:1px solid rgba(255,255,255,0.11);border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,0.65);z-index:40;overflow:hidden;transition:0.25s cubic-bezier(0.4,0,0.2,1);}
#scanner-widget.hidden {
  opacity:0;
  visibility:hidden;
  pointer-events:none;
  transform:translateY(22px);
}
.scanner-close-btn {
  border:none;
  background:rgba(255,255,255,0.1);
  color:#f8fafc;
  width:28px;
  height:28px;
  border-radius:8px;
  display:flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  font-size:12px;
}
.scanner-close-btn:hover {
  background:rgba(255,255,255,0.2);
}
.widget-head{display:flex;align-items:center;justify-content:space-between;padding:7px 10px;border-bottom:1px solid rgba(255,255,255,0.06);cursor:pointer;user-select:none;}
.widget-head-left{display:flex;align-items:center;gap:6px;}
.widget-head-icon{width:20px;height:20px;border-radius:6px;background:linear-gradient(135deg,#38bdf8,#818cf8);display:flex;align-items:center;justify-content:center;font-size:10px;color:#fff;flex-shrink:0;}
.widget-head-title{font-size:11px;font-weight:700;color:#f1f5f9;}
.widget-body{padding:8px;}
.mini-qr-box{border-radius:8px;overflow:hidden;background:rgba(0,0,0,0.4);border:1px solid rgba(255,255,255,0.07);position:relative;width:100%;aspect-ratio:1;max-height:150px;}
#qr-reader{width:100%;height:100%;}
.qr-idle{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;pointer-events:none;}
.qr-idle-icon{font-size:22px;color:rgba(56,189,248,0.22);animation:bob 2.6s ease-in-out infinite;}
.qr-idle-label{font-size:10px;font-weight:600;color:rgba(241,245,249,0.2);text-align:center;}
.scan-line{position:absolute;left:6px;right:6px;height:1.5px;background:linear-gradient(90deg,transparent,#38bdf8,transparent);border-radius:2px;box-shadow:0 0 6px rgba(56,189,248,0.6);animation:scanMove 1.8s ease-in-out infinite;opacity:0;pointer-events:none;z-index:3;}
.scan-line.on{opacity:1;}
@keyframes scanMove{0%{top:6px;}50%{top:calc(100% - 8px);}100%{top:6px;}}
.corner{position:absolute;width:10px;height:10px;z-index:3;pointer-events:none;}
.corner::before,.corner::after{content:'';position:absolute;background:#38bdf8;border-radius:1px;}
.corner::before{width:1.5px;height:100%;}
.corner::after{width:100%;height:1.5px;}
.corner.tl{top:5px;left:5px;}.corner.tr{top:5px;right:5px;transform:scaleX(-1);}.corner.bl{bottom:5px;left:5px;transform:scaleY(-1);}.corner.br{bottom:5px;right:5px;transform:scale(-1);}
.widget-controls{display:flex;gap:5px;margin-top:6px;}
.wbtn{flex:1;height:28px;border-radius:7px;font-family:inherit;font-size:11px;font-weight:700;cursor:pointer;border:1px solid;display:flex;align-items:center;justify-content:center;gap:4px;transition:0.15s;}
.wbtn.primary{background:#38bdf8;border-color:#38bdf8;color:#050611;}
.wbtn.primary:hover{background:#7dd3fc;}
.wbtn.ghost{background:rgba(255,255,255,0.04);border-color:rgba(255,255,255,0.1);color:rgba(241,245,249,0.55);}
.wbtn.ghost:hover{background:rgba(255,255,255,0.08);color:#f1f5f9;}
.widget-divider{display:flex;align-items:center;gap:5px;margin:6px 0;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:rgba(241,245,249,0.18);}
.widget-divider::before,.widget-divider::after{content:'';flex:1;height:1px;background:rgba(255,255,255,0.05);}
.widget-input-row{display:flex;gap:4px;}
.widget-input{flex:1;height:28px;border-radius:7px;border:1px solid rgba(255,255,255,0.09);background:rgba(4,5,16,0.65);padding:0 8px;font-family:inherit;font-size:11px;font-weight:500;color:#f1f5f9;transition:0.15s;min-width:0;}
.widget-input:focus{outline:none;border-color:rgba(56,189,248,0.4);box-shadow:0 0 0 2px rgba(56,189,248,0.07);}
.widget-input::placeholder{color:rgba(241,245,249,0.18);}
.widget-submit{width:28px;height:28px;border-radius:7px;padding:0;font-family:inherit;font-size:12px;font-weight:700;cursor:pointer;border:1px solid rgba(56,189,248,0.22);background:rgba(56,189,248,0.1);color:#38bdf8;display:flex;align-items:center;justify-content:center;transition:0.15s;}
.widget-submit:hover{background:rgba(56,189,248,0.2);}
#scanner-widget.collapsed .widget-body{display:none;}

/* ─── Logo Strip ─────────────────────────────── */
.logo-strip {
  position: fixed;
  left: 0; right: 0; bottom: 0;
  height: 88px;
  overflow: hidden;
  z-index: 30;
  padding: 12px 0 20px;
  background: transparent;
  display: flex;
  align-items: center;
  -webkit-mask-image: linear-gradient(90deg, transparent 0%, rgba(0,0,0,0.8) 14%, rgba(0,0,0,0.8) 86%, transparent 100%);
  mask-image: linear-gradient(90deg, transparent 0%, rgba(0,0,0,0.8) 14%, rgba(0,0,0,0.8) 86%, transparent 100%);
}

.logo-strip::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(to top, rgba(1,4,13,0.75), rgba(1,4,13,0.25) 40%, transparent 100%);
  pointer-events: none;
  z-index: -1;
}

.logo-track {
  display: flex;
  gap: 42px;
  align-items: center;
  min-width: 100%;
  width: max-content;
  animation: logo-scroll 30s linear infinite;
  padding: 0 32px;
}
.logo-track:hover {
  animation-play-state: paused;
}

.logo-slide {
  flex: 0 0 auto;
  width: 152px;
  height: 72px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  background: transparent;
  box-shadow: none;
}

.logo-link {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  text-decoration: none;
}

.logo-link img {
  max-width: 132px;
  max-height: 58px;
  object-fit: contain;
  filter: brightness(0) invert(1) drop-shadow(0 0 10px rgba(255,255,255,0.35));
  opacity: 0.9;
  transition: opacity 0.3s ease, filter 0.3s ease;
}
.logo-link:hover img {
  opacity: 1;
  filter: brightness(0) invert(1) drop-shadow(0 0 14px rgba(255,255,255,0.55));
}

@keyframes logo-scroll {
  from { transform: translateX(0); }
  to   { transform: translateX(-50%); }
}

/* ─── Ad Overlay ─────────────────────────────── */
#adOverlay {
  position: fixed;
  inset: 0;
  background: #000;
  display: flex;
  align-items: stretch;
  justify-content: stretch;
  z-index: 200;
  padding: 0;
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transition: opacity 0.55s ease, visibility 0.55s ease;
  cursor: none;
}

#adOverlay.is-visible {
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
  cursor: none;
}

.ad-overlay-note {
  display: none;
}

body.ad-overlay-active,
body.ad-overlay-active * {
  cursor: none !important;
}

.ad-overlay-inner {
  width: 100%;
  height: 100%;
  padding: 0;
  border-radius: 0;
  background: transparent;
  display: flex;
  align-items: stretch;
  justify-content: center;
  flex: 1;
  position: relative;
  min-width: 100vw;
  min-height: 100vh;
}

.ad-media {
  width: 100%;
  height: 100%;
  position: relative;
}

.ad-media iframe,
.ad-media video {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: none;
  border-radius: 0;
  object-fit: cover;
  background: #000;
}

/* ─── Result Head Logo ───────────────────────── */
.result-head-logo {
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 10px;
}
.result-head-logo img {
  max-height: clamp(60px, 8vw, 110px);
  max-width: clamp(140px, 22vw, 280px);
  object-fit: contain;
  filter: saturate(1.18) brightness(1.05) drop-shadow(0 2px 12px rgba(0,0,0,0.65));
  opacity: 1;
  transform-origin: 50% 50%;
  will-change: transform, filter;
  backface-visibility: hidden;
  animation: logoGearSpin 4.8s linear infinite, logoGearJitter 3.2s ease-in-out infinite;
}

@keyframes logoGearSpin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

@keyframes logoGearJitter {
  0%, 100% { filter: saturate(1.18) brightness(1.05) drop-shadow(0 2px 12px rgba(0,0,0,0.65)); }
  50% { filter: saturate(1.26) brightness(1.12) drop-shadow(0 2px 16px rgba(0,0,0,0.72)); }
}

/* ─── Responsive ─────────────────────────────── */
@media (max-width: 1080px) {
  #topbar { padding: 12px 20px; min-height: 62px; }
  #clock { font-size: 36px; }
  #clockDate { font-size: 12px; letter-spacing: 0.22em; }
  #result-head { width: min(94vw, 680px); }
  .idle-title { font-size: clamp(34px, 7vw, 58px); }
  .idle-subtitle { letter-spacing: 0.3em; }
  #stage.stage--scanned #picker-screen { padding-top: clamp(128px, 18vh, 190px); }
  #stage.stage--picker #picker-screen { padding-top: clamp(82px, 12vh, 124px); }
}

@media (max-width: 820px) {
  #topbar { padding: 10px 14px; }
  #clock { font-size: 30px; }
  #clockDate { letter-spacing: 0.16em; }
  .btn-back { padding: 5px 10px; font-size: 12px; }
  .btn-fs { width: 32px; height: 32px; }
  #result-head { width: min(95vw, 560px); gap: 6px; }
  #stage.stage--scanned #result-head { top: 0; }
  #stage.stage--picker #result-head { top: 0; }
  .idle-title { font-size: clamp(30px, 9vw, 48px); }
  .idle-subtitle { font-size: 12px; letter-spacing: 0.24em; }
  .idle-hint { font-size: 12px; }
  .idle-hint::before, .idle-hint::after { width: 18px; }
  .idle-qr-ring { width: 82px; height: 82px; font-size: 34px; margin-top: clamp(170px, 34vh, 260px); }
  #picker-screen { padding: 16px 14px 14px; bottom: 86px; }
  #stage.stage--picker #picker-screen { padding-top: 96px; }
  .picker-order { gap: 12px; padding: 14px; border-radius: 14px; }
  .picker-order-name { font-size: 20px; }
  .picker-row { padding: 13px 14px; }
  .picker-name { font-size: 15px; }
  .btn-checkin { padding: 9px 14px; font-size: 13px; }
  #welcome-screen { padding: 12px; top: 62px; }
  .welcome-panel { width: min(94vw, 760px); border-radius: 22px; transform: none; }
  .welcome-greeting { margin-bottom: 10px; }
.welcome-name { font-size: clamp(32px, 10vw, 58px); line-height: 1.05; }
  .welcome-package { letter-spacing: 0.2em; }
}

@media (max-width: 600px) {
  #scanner-widget { left: 10px; bottom: 92px; width: min(170px, calc(100vw - 20px)); }
  .picker-order { flex-direction: column; text-align: center; }
  .picker-order-meta { justify-content: center; }
  .picker-row { flex-direction: column; align-items: stretch; gap: 10px; }
  .picker-row-left { width: 100%; justify-content: flex-start; }
  .btn-checkin, .done-badge { width: 100%; justify-content: center; }
  .idle-hint::before, .idle-hint::after { display: none; }
  #result-head { width: min(95vw, 520px); }
  #stage.stage--scanned #result-head { top: -4px; }
  #stage.stage--picker #result-head { top: -6px; }
  #stage.stage--picker #picker-screen { padding-top: 86px; }
  #welcome-screen { padding: 12px; top: 56px; }
  .welcome-panel { width: min(94vw, 520px); border-radius: 18px; padding: 22px 16px; transform: none; }
}

/* Global readability: black stroke for text + icons */
#app :is(
  #clock, #clockDate,
  .idle-title, .idle-subtitle, .idle-hint,
  .welcome-greeting, .welcome-name, .welcome-package, .welcome-court,
  .picker-order-name, .picker-name, .picker-package, .picker-court,
  .err-title, .err-msg,
  .widget-head-title, .widget-divider,
  .wbtn, .btn-checkin, .done-badge, .btn-back,
  .tag, .logo-slide
) {
  -webkit-text-stroke: 0.6px rgba(0,0,0,0.9);
  paint-order: stroke fill;
  text-shadow:
    0 1px 2px rgba(0,0,0,0.95),
    0 0 10px rgba(0,0,0,0.55);
}

#app .bi,
#app i {
  filter:
    drop-shadow(0 1px 1px rgba(0,0,0,0.95))
    drop-shadow(0 0 8px rgba(0,0,0,0.55));
}

</style>
HTML;

render_header([
    'title'     => 'Scan QR - Asthapora',
    'isAdmin'   => true,
    'showNav'   => false,
    'extraHead' => $extraHead,
]);
?>

<div id="app">

  <header id="topbar">
    <div class="topbar-left">
      <button class="btn-fs" id="btnFs" type="button" title="Fullscreen">
        <i class="bi bi-fullscreen" id="fsIcon"></i>
      </button>
      <a class="btn-back" href="/admin/dashboard"><i class="bi bi-arrow-left"></i></a>
    </div>
    <div class="topbar-right">
      <div class="clock-block">
        <span id="clock"></span>
        <span id="clockDate"></span>
      </div>
    </div>
  </header>

  <div id="stage">
    <div id="result-head" aria-live="polite">
      <div class="result-head-logo">
        <img src="/assets/img/LogoTitleAsthapora.png" alt="Logo" id="resultHeadLogo">
      </div>
      <div class="idle-subtitle idle-subtitle--brand">MONKEYBAR X BAPORA</div>
      <div class="idle-title">TEMU PADEL</div>
      <div class="idle-subtitle">Welcome</div>
    </div>
    <!-- 1. Idle -->
    <div id="idle-screen">
    </div>
    <!-- 2. Picker -->
    <div id="picker-screen">
      <div class="picker-order" id="pickerOrder"></div>
      <div class="picker-label"><i class="bi bi-people"></i> Pilih attendee</div>
      <div class="picker-list"  id="pickerList"></div>
    </div>
    <!-- 3. Welcome -->
    <div id="welcome-screen">
      <div class="welcome-panel">
        <div class="welcome-avatar" id="welcomeAvatar"><img id="welcomeImg" src="" alt=""></div>
        <div class="welcome-greeting">Selamat Datang</div>
        <div class="welcome-name" id="welcomeName">—</div>
        <div class="welcome-package" id="welcomePackage" style="display:none;"></div>
        <div class="welcome-court" id="welcomeCourt" style="display:none;"></div>
      </div>
    </div>
    <!-- 4. Error -->
    <div id="err-screen" role="alertdialog" aria-live="polite" aria-modal="true">
      <div class="err-modal">
        <div class="err-icon"><i class="bi bi-exclamation-circle"></i></div>
        <div class="err-title">Gagal</div>
        <div class="err-msg" id="errMsg">—</div>
        <button type="button" class="err-close" id="errCloseBtn">Tutup</button>
      </div>
    </div>
  </div>

  <!-- Logo strip -->
  <section class="logo-strip" aria-label="Sponsor logos">
    <div class="logo-track" id="logoTrack">
      <?php foreach ($sponsorItems as $idx => $sp): ?>
        <div class="logo-slide">
          <?php $hasUrl = !empty($sp['url']); ?>
          <?php if ($hasUrl): ?>
            <a class="logo-link" href="<?= h($sp['url']) ?>" target="_blank" rel="noopener noreferrer">
              <img src="<?= h($sp['logo']) ?>" alt="<?= h($sp['name']) ?>">
            </a>
          <?php else: ?>
            <div class="logo-link">
              <img src="<?= h($sp['logo']) ?>" alt="<?= h($sp['name']) ?>">
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <div id="adOverlay" class="ad-overlay" aria-hidden="true">
    <div class="ad-overlay-inner">
      <div class="ad-media">
        <video id="adVideoPlayer" playsinline></video>
        <iframe id="adIframePlayer" allow="autoplay; encrypted-media" allowfullscreen></iframe>
      </div>
      <div class="ad-overlay-note">Tekan atau gerakkan kursor untuk kembali ke check-in.</div>
    </div>
  </div>

</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function(){
  var idle    = document.getElementById('idle-screen');
  var picker  = document.getElementById('picker-screen');
  var welcome = document.getElementById('welcome-screen');
  var err     = document.getElementById('err-screen');
  var pOrder  = document.getElementById('pickerOrder');
  var pList   = document.getElementById('pickerList');
  var wName   = document.getElementById('welcomeName');
  var wPkg    = document.getElementById('welcomePackage');
  var wCourt  = document.getElementById('welcomeCourt');
  var errMsg  = document.getElementById('errMsg');
  var errCloseBtn = document.getElementById('errCloseBtn');
  var btnStart= document.getElementById('btnStart');
  var btnStop = document.getElementById('btnStop');
  var mForm   = document.getElementById('manualForm');
  var mInput  = document.getElementById('manualToken');
  var qrIdle  = document.getElementById('qrIdle');
  var scanLine= document.getElementById('scanLine');
  var clock   = document.getElementById('clock');
  var clockD  = document.getElementById('clockDate');
  var wToggle = document.getElementById('widgetToggleBtn');
  var widget  = document.getElementById('scanner-widget');
  var widgetLauncher = document.getElementById('scanner-widget-launcher');
  var widgetCloseBtn = document.getElementById('scannerCloseBtn');
  var stageEl = document.getElementById('stage');
  var screens = [idle,picker,welcome];
  var scanner=null,scanning=false,busy=false,hwBuf='',hwTs=0;
  var welcomeHoldMs = 8 * 1000;
  var welcomeAudio = null;
  var DAYS=['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
  var MONTHS=['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

  function getWelcomeAudio(){
    if (welcomeAudio) return welcomeAudio;
    try {
      welcomeAudio = new Audio('/assets/video/suara.mp3');
      welcomeAudio.preload = 'auto';
      welcomeAudio.volume = 1;
      return welcomeAudio;
    } catch (e) {
      return null;
    }
  }
  function primeWelcomeAudio(){
    var audio = getWelcomeAudio();
    if (!audio) return;
    try {
      audio.muted = true;
      var p = audio.play();
      if (p && typeof p.then === 'function') {
        p.then(function(){
          audio.pause();
          audio.currentTime = 0;
          audio.muted = false;
        }).catch(function(){
          audio.muted = false;
        });
      } else {
        audio.pause();
        audio.currentTime = 0;
        audio.muted = false;
      }
    } catch (e) {
      audio.muted = false;
    }
  }
  function playWelcomeAudio(){
    var audio = getWelcomeAudio();
    if (!audio) return;
    try {
      audio.pause();
      audio.currentTime = 0;
      audio.muted = false;
      audio.play().catch(function(){});
    } catch (e) {}
  }

  function updateStageBanner(el) {
    if (!stageEl) return;
    var showTop = el === picker || el === welcome;
    stageEl.classList.toggle('stage--scanned', showTop);
    stageEl.classList.toggle('stage--picker', el === picker);
    stageEl.classList.toggle('stage--welcome', el === welcome);
  }
  function notifyScreenChange(el){
    document.dispatchEvent(new CustomEvent('scanScreenChange',{
      detail:{screen:(el&&el.id)?el.id:''}
    }));
  }
  function notifyCameraState(active){
    document.dispatchEvent(new CustomEvent('scanCameraState',{
      detail:{active:!!active}
    }));
  }
  function showScannerWidget(fromLauncher){
    if(!widget) return;
    widget.classList.remove('hidden');
    if(fromLauncher){
      widget.classList.remove('collapsed');
    }
    if(widgetLauncher){
      widgetLauncher.style.display='none';
      widgetLauncher.setAttribute('aria-hidden','true');
    }
  }
  function hideScannerWidget(){
    if(!widget) return;
    widget.classList.add('hidden');
    widget.classList.add('collapsed');
    if(widgetLauncher){
      widgetLauncher.style.display='inline-flex';
      widgetLauncher.removeAttribute('aria-hidden');
    }
  }
  function show(el){
    screens.forEach(function(s){ if(s) s.style.display='none'; });
    if(err){
      err.style.display='none';
      err.setAttribute('aria-hidden','true');
    }
    if(el){
      el.style.display='flex';
      if(el !== err){
        updateStageBanner(el);
        notifyScreenChange(el);
      }
      if(el === err){
        err.setAttribute('aria-hidden','false');
        if(errCloseBtn) errCloseBtn.focus();
      }
    }
  }
  function hideErr(){
    if(err) err.style.display='none';
    show(idle);
  }
  function pad(n){ return String(n).padStart(2,'0'); }

  /* Clock */
  function tick(){
    var d=new Date();
    if(clock) clock.textContent=pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());
    if(clockD) clockD.textContent=DAYS[d.getDay()]+', '+MONTHS[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear();
  }
  tick(); setInterval(tick,1000);

  /* Fullscreen */
  var btnFs=document.getElementById('btnFs'),fsIcon=document.getElementById('fsIcon');
  function isFs(){return!!(document.fullscreenElement||document.webkitFullscreenElement||document.mozFullScreenElement);}
  function enterFs(){var e=document.documentElement;if(e.requestFullscreen)e.requestFullscreen();else if(e.webkitRequestFullscreen)e.webkitRequestFullscreen();else if(e.mozRequestFullScreen)e.mozRequestFullScreen();}
  function exitFs(){if(document.exitFullscreen)document.exitFullscreen();else if(document.webkitExitFullscreen)document.webkitExitFullscreen();else if(document.mozCancelFullScreen)document.mozCancelFullScreen();}
  function syncFs(){fsIcon.className=isFs()?'bi bi-fullscreen-exit':'bi bi-fullscreen';btnFs.title=isFs()?'Exit Fullscreen':'Fullscreen';}
  (function(){if(document.readyState==='complete'||document.readyState==='interactive'){enterFs();}else{document.addEventListener('DOMContentLoaded',enterFs);}})();
  btnFs.addEventListener('click',function(){if(isFs())exitFs();else enterFs();});
  document.addEventListener('fullscreenchange',syncFs);document.addEventListener('webkitfullscreenchange',syncFs);
  window.addEventListener('pagehide',function(){if(isFs())exitFs();});
  window.addEventListener('beforeunload',function(){if(isFs())exitFs();});

  /* ── Logo marquee ──────────────────────────── */
  function buildMarquee(){
    var track=document.getElementById('logoTrack');
    if(!track) return;
    Array.prototype.slice.call(track.querySelectorAll('[data-clone]')).forEach(function(n){n.parentNode.removeChild(n);});
    var origNodes=Array.prototype.slice.call(track.childNodes).filter(function(n){return n.nodeType===1;});
    if(!origNodes.length) return;
    var strip=track.parentElement;
    var needed=(strip?strip.clientWidth:window.innerWidth)*2.5;
    var iter=0;
    while(track.scrollWidth<needed&&iter<10){
      origNodes.forEach(function(n){
        var c=n.cloneNode(true);c.setAttribute('data-clone','1');c.setAttribute('aria-hidden','true');
        if(c.classList&&!c.classList.contains('logo-sep')){c.querySelectorAll('img').forEach(function(img){img.alt='';});}
        track.appendChild(c);
      });
      iter++;
    }
  }
  buildMarquee();
  window.addEventListener('load',buildMarquee);
  window.addEventListener('resize',function(){
    var t=document.getElementById('logoTrack');
    if(t){t.style.animation='none';void t.offsetWidth;t.style.animation='';}
    buildMarquee();
  });

  /* Widget */
  if(wToggle && widget){
    wToggle.addEventListener('click',function(){widget.classList.toggle('collapsed');});
  }
  if(widgetLauncher){
    widgetLauncher.addEventListener('click',function(e){e.preventDefault(); showScannerWidget(true);});
  }
  if(widgetCloseBtn){
    widgetCloseBtn.addEventListener('click',function(e){e.stopPropagation(); hideScannerWidget();});
  }
  if(errCloseBtn){
    errCloseBtn.addEventListener('click',function(){ hideErr(); });
  }
  if(err){
    err.addEventListener('click',function(e){ if(e.target === err){ hideErr(); } });
  }

  /* Utils */
  function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
  function gN(r){var v=String(r||'').toLowerCase().trim();return['male','m','laki-laki','laki'].indexOf(v)!==-1?'male':['female','f','perempuan','wanita'].indexOf(v)!==-1?'female':'unknown';}
  function gL(g){return g==='male'?'Laki-laki':g==='female'?'Perempuan':'Unknown';}
  function gI(g){return g==='male'?'bi-gender-male':g==='female'?'bi-gender-female':'bi-gender-ambiguous';}
  function gA(g){return g==='female'?'/assets/img/perempuan.png':'/assets/img/laki.png';}
  function cNo(raw){var n=Number(raw||0);return n>=1&&n<=6?n:0;}
  function cLabel(raw){var n=cNo(raw);return n>0?('Court '+n):'';}
  var welcomeAvatarPool = {
    male: ['/assets/img/laki.png','/assets/img/laki1.png','/assets/img/laki2.png'],
    female: ['/assets/img/perempuan.png','/assets/img/perempuan1.png','/assets/img/perempuan2.png']
  };
  function gAW(g){
    var key = g==='female' ? 'female' : 'male';
    var pool = welcomeAvatarPool[key] || [];
    if(!pool.length) return gA(g);
    return pool[Math.floor(Math.random() * pool.length)] + '?v=' + Date.now();
  }
  function showErr(m){errMsg.textContent=m||'Terjadi kesalahan.';show(err);}

  function showPicker(data,token){
    var att=Array.isArray(data.attendees)?data.attendees:[];
    var total=Number(data.total_tickets||att.length||0),checked=Number(data.checked_in_count||0),remain=Number(data.remaining_count||0);
    var g=gN(data.order_gender);
    if(att.length===1 && Number(att[0].id||0)>0 && !att[0].checked_in_at){
      setTimeout(function(){ doCheckin(token,Number(att[0].id||0),att[0].name||''); }, 0);
      return;
    }
    pOrder.innerHTML='<div class="picker-avatar '+g+'"><img src="'+gA(g)+'" alt=""></div>'+
      '<div class="picker-order-info"><div class="picker-order-name">'+esc(data.order_name||'-')+'</div>'+
      '<div class="picker-order-meta"><span class="tag">#'+esc(String(data.order_id||'-'))+'</span>'+
      '<span class="tag '+g+'"><i class="bi '+gI(g)+'"></i> '+esc(gL(g))+'</span>'+
      '<span class="tag"><i class="bi bi-ticket-perforated"></i> '+total+'</span>'+
      '<span class="tag"><i class="bi bi-check-circle"></i> '+checked+' hadir</span>'+
      (remain>0?'<span class="tag"><i class="bi bi-hourglass-split"></i> '+remain+' sisa</span>':'')+
      '</div></div>';
    pList.innerHTML=att.map(function(a,i){
      var done=!!a.checked_in_at,pkg=a.package?'<span class="picker-package">'+esc(a.package)+'</span>':'',court=cLabel(a.court_no),courtHtml=court?'<span class="picker-court">'+esc(court)+'</span>':'';
      if(done) return '<div class="picker-row done"><div class="picker-row-left"><div class="picker-num done">'+(i+1)+'</div><div class="picker-name done">'+esc(a.name||'-')+'</div>'+pkg+courtHtml+'</div><span class="done-badge"><i class="bi bi-check-lg"></i> Sudah hadir</span></div>';
      return '<div class="picker-row pending"><div class="picker-row-left"><div class="picker-num pending">'+(i+1)+'</div><div class="picker-name">'+esc(a.name||'-')+'</div>'+pkg+courtHtml+'</div>'+
        '<button type="button" class="btn-checkin do-checkin" data-id="'+Number(a.id||0)+'" data-token="'+esc(token)+'" data-name="'+esc(a.name||'-')+'"><i class="bi bi-person-check"></i> Check-in</button></div>';
    }).join('');
    pList.querySelectorAll('.do-checkin').forEach(function(b){
      b.addEventListener('click',function(){doCheckin(b.getAttribute('data-token'),Number(b.getAttribute('data-id')),b.getAttribute('data-name'));});
    });
    picker.scrollTop=0;
    show(picker);
  }

  function showWelcome(data,fb){
    var name=data.name||fb||'-',g=gN(data.gender);
    welcome.style.animation='none';void welcome.offsetWidth;welcome.style.animation='';
    var wa=document.getElementById('welcomeAvatar'),wi=document.getElementById('welcomeImg');
    if(wa) wa.className='welcome-avatar '+g;
    if(wi) wi.src=gAW(g);
    wName.textContent=name;
    if(wPkg){if(data.package){wPkg.textContent=data.package;wPkg.style.display='block';}else{wPkg.textContent='';wPkg.style.display='none';}}
    if(wCourt){var courtText=cLabel(data.court_no);if(courtText){wCourt.textContent=courtText;wCourt.style.display='block';}else{wCourt.textContent='';wCourt.style.display='none';}}
    show(welcome);
    clearTimeout(window._wt);
    var holdMs = Math.max(8000, Number(welcomeHoldMs) || 0);
    window._wt=setTimeout(function(){show(idle);},holdMs);
  }

  function post(payload){
    var url=(mForm&&mForm.getAttribute('action'))||'/admin/scan';
    return fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
      .then(function(r){
        var ct=r.headers.get('content-type')||'';
        if(!ct.includes('application/json')){
          return r.text().then(function(t){
            if(t.includes('Admin Login')||r.status===401||r.status===403) throw new Error('Sesi admin habis. Silakan login ulang.');
            throw new Error('Server error (HTTP '+r.status+').');
          });
        }
        return r.json();
      });
  }

  function verify(raw){
    if(busy)return;busy=true;
    post({mode:'resolve',token:String(raw||'').trim()})
      .then(function(d){
        if(!d||!d.ok){showErr((d&&d.message)||'Token tidak valid.');return;}
        var att=Array.isArray(d.attendees)?d.attendees:[];
        if(!att.length){showErr('Data attendee tidak ditemukan.');return;}
        showPicker(d,String(raw||'').trim());
      })
      .catch(function(e){showErr(e&&e.message?e.message:'Gagal koneksi ke server.');})
      .finally(function(){busy=false;});
  }

  function doCheckin(token,id,name){
    if(busy)return;busy=true;
    post({mode:'checkin',token:token||'',attendee_id:id||0})
      .then(function(d){
        if(!d||!d.ok){showErr((d&&d.message)||'Check-in gagal.');return;}
        playWelcomeAudio();
        showWelcome(d,name);if(mInput)mInput.value='';
      })
      .catch(function(e){showErr(e&&e.message?e.message:'Gagal koneksi ke server.');})
      .finally(function(){busy=false;});
  }

  function stopCam(){
    if(!scanner||!scanning){notifyCameraState(false);return Promise.resolve();}
    scanning=false;
    notifyCameraState(false);
    if(scanLine)scanLine.classList.remove('on');if(qrIdle)qrIdle.style.display='';
    return scanner.stop().catch(function(){}).then(function(){return scanner.clear().catch(function(){});});
  }

  if(btnStart){
    btnStart.addEventListener('click',function(){
      if(scanning)return;
      if(typeof Html5Qrcode==='undefined'){showErr('Library scanner gagal dimuat.');return;}
      if(!document.getElementById('qr-reader')){showErr('Area scanner tidak ditemukan.');return;}
      notifyCameraState(true);
      if(qrIdle)qrIdle.style.display='none';if(scanLine)scanLine.classList.add('on');
      scanner=new Html5Qrcode('qr-reader');
      scanner.start({facingMode:'environment'},{fps:10,qrbox:160},
        function(text){stopCam().finally(function(){verify(text);});},
        function(){}
      ).then(function(){scanning=true;})
      .catch(function(){
        notifyCameraState(false);
        if(qrIdle)qrIdle.style.display='';if(scanLine)scanLine.classList.remove('on');
        showErr('Izinkan akses kamera atau gunakan input manual.');
      });
    });
  }
  if(btnStop){
    btnStop.addEventListener('click',stopCam);
  }
  if(mForm){
    mForm.addEventListener('submit',function(e){e.preventDefault();primeWelcomeAudio();verify(mInput ? (mInput.value||'') : '');});
  }
  ['pointerdown','keydown','touchstart'].forEach(function(evt){
    document.addEventListener(evt, primeWelcomeAudio, { once:true, passive:true });
  });

  /* Barcode gun */
  document.addEventListener('keydown',function(e){
    var k=e.key||'',now=Date.now();
    if(['Shift','Control','Alt','Meta','CapsLock'].indexOf(k)!==-1)return;
    if(now-hwTs>70)hwBuf='';hwTs=now;
    if(k==='Enter'){
      if(hwBuf.length>=10){e.preventDefault();verify(hwBuf);hwBuf='';return;}
      var v=mInput?mInput.value.trim():'';
      if(v){e.preventDefault();verify(v);hwBuf='';return;}
      hwBuf='';return;
    }
    if(k==='Backspace'){hwBuf=hwBuf.slice(0,-1);return;}
    if(k.length===1)hwBuf+=k;
  },true);

  if(mInput&&mInput.value)verify(mInput.value);
})();
</script>

<script>
(function () {
  var adPlaylist = <?= json_encode($adSources) ?>;
  if (!Array.isArray(adPlaylist) || !adPlaylist.length) return;

  var adOverlay = document.getElementById('adOverlay');
  var adVideoPlayer = document.getElementById('adVideoPlayer');
  var adIframePlayer = document.getElementById('adIframePlayer');
  var idleDelay = 10000;
  var idleTimer = null;
  var overlayVisible = false;
  var pendingHide = false;
  var currentAdIndex = 0;
  var adTimer = null;
  var remoteAdDuration = 25000;
  var currentScreenId = 'idle-screen';
  var cameraActive = false;

  function isRemoteVideo(url) {
    return typeof url === 'string' && /^https?:\/\//i.test(url);
  }

  function appendQuery(url, params) {
    if (!params) return url;
    return url + (url.indexOf('?') === -1 ? '?' : '&') + params;
  }

  function buildEmbedUrl(url) {
    if (!url) return '';
    var ytMatch = url.match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/))([A-Za-z0-9_-]{11})/i);
    if (ytMatch && ytMatch[1]) {
      return appendQuery('https://www.youtube.com/embed/' + ytMatch[1], 'autoplay=1&controls=0&rel=0&modestbranding=1&playsinline=1');
    }
    var igMatch = url.match(/instagram\.com\/reel\/([^/?#&]+)/i);
    if (igMatch && igMatch[1]) {
      return appendQuery('https://www.instagram.com/reel/' + igMatch[1] + '/embed/', 'autoplay=1');
    }
    return appendQuery(url, 'autoplay=1');
  }

  function clearAdTimer() {
    if (adTimer) { window.clearTimeout(adTimer); adTimer = null; }
  }

  function clearIdleTimer() {
    if (idleTimer) { window.clearTimeout(idleTimer); idleTimer = null; }
  }

  function canRunAdCountdown() {
    return currentScreenId === 'idle-screen' && !cameraActive;
  }

  function detectCurrentScreen() {
    var ids = ['idle-screen', 'picker-screen', 'welcome-screen', 'err-screen'];
    for (var i = 0; i < ids.length; i++) {
      var el = document.getElementById(ids[i]);
      if (el && window.getComputedStyle(el).display !== 'none') return ids[i];
    }
    return '';
  }

  function scheduleRemoteAdvance() {
    clearAdTimer();
    adTimer = window.setTimeout(moveToNextAd, remoteAdDuration);
  }

  function moveToNextAd() {
    if (!adPlaylist.length) return;
    currentAdIndex = (currentAdIndex + 1) % adPlaylist.length;
    playCurrentAd();
  }

  function playCurrentAd() {
    if (!adPlaylist.length) return;
    var entry = adPlaylist[currentAdIndex] || {};
    var sourceUrl = (entry.url || '').trim();
    if (!sourceUrl) { moveToNextAd(); return; }
    clearAdTimer();
    if (isRemoteVideo(sourceUrl)) {
      if (adVideoPlayer) adVideoPlayer.style.display = 'none';
      if (adIframePlayer) {
        adIframePlayer.src = buildEmbedUrl(sourceUrl);
        adIframePlayer.style.display = '';
      }
      scheduleRemoteAdvance();
      return;
    }
    if (adIframePlayer) { adIframePlayer.src = ''; adIframePlayer.style.display = 'none'; }
    if (adVideoPlayer) {
      adVideoPlayer.src = sourceUrl;
      adVideoPlayer.style.display = '';
      adVideoPlayer.muted = false;
      adVideoPlayer.volume = 1;
      adVideoPlayer.load();
      adVideoPlayer.play().catch(function () {});
    }
  }

  function stopAdPlayback() {
    clearAdTimer();
    if (adVideoPlayer) { adVideoPlayer.pause(); adVideoPlayer.removeAttribute('src'); adVideoPlayer.style.display = 'none'; }
    if (adIframePlayer) { adIframePlayer.src = ''; adIframePlayer.style.display = 'none'; }
  }

  if (adVideoPlayer) {
    adVideoPlayer.addEventListener('ended', function () { moveToNextAd(); });
  }

  function showAdOverlay() {
    if (!adOverlay || overlayVisible || !canRunAdCountdown()) return;
    adOverlay.classList.add('is-visible');
    adOverlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ad-overlay-active');
    overlayVisible = true;
    pendingHide = false;
    playCurrentAd();
  }

  function hideAdOverlay() {
    if (!adOverlay || !overlayVisible) return;
    adOverlay.classList.remove('is-visible');
    adOverlay.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('ad-overlay-active');
    stopAdPlayback();
    overlayVisible = false;
    pendingHide = true;
  }

  function notifyAdOverlayHidden() {
    document.dispatchEvent(new Event('adOverlayHidden'));
  }

  function scheduleAd() {
    clearIdleTimer();
    if (!canRunAdCountdown()) return;
    idleTimer = window.setTimeout(showAdOverlay, idleDelay);
  }

  function syncAdByState() {
    if (!canRunAdCountdown()) {
      clearIdleTimer();
      if (overlayVisible) hideAdOverlay();
      return;
    }
    scheduleAd();
  }

  function handleUserActivity() {
    if (adOverlay && adOverlay.classList.contains('is-visible')) { hideAdOverlay(); }
    scheduleAd();
  }

  if (adOverlay) {
    adOverlay.addEventListener('transitionend', function (evt) {
      if (evt.propertyName === 'opacity' && !adOverlay.classList.contains('is-visible') && pendingHide) {
        pendingHide = false;
        notifyAdOverlayHidden();
      }
    });
  }

  ['mousemove', 'mousedown', 'touchstart', 'keydown'].forEach(function (evt) {
    document.addEventListener(evt, handleUserActivity, { passive: true });
  });

  var shakeThreshold = 12;
  var lastShakeTs = 0;
  function handleShake(acceleration) {
    if (!acceleration) return;
    var now = Date.now();
    if (now - lastShakeTs < 800) return;
    var x = acceleration.x || 0;
    var y = acceleration.y || 0;
    var z = acceleration.z || 0;
    var magnitude = Math.sqrt(x * x + y * y + z * z);
    if (magnitude > shakeThreshold) {
      lastShakeTs = now;
      handleUserActivity();
    }
  }

  if (window.DeviceMotionEvent) {
    window.addEventListener('devicemotion', function (evt) {
      handleShake(evt.accelerationIncludingGravity || evt.acceleration);
    }, { passive: true });
  }

  document.addEventListener('scanScreenChange', function (evt) {
    var detail = evt && evt.detail ? evt.detail : {};
    currentScreenId = detail.screen || '';
    syncAdByState();
  });

  document.addEventListener('scanCameraState', function (evt) {
    var detail = evt && evt.detail ? evt.detail : {};
    cameraActive = !!detail.active;
    syncAdByState();
  });

  if (adOverlay) {
    adOverlay.addEventListener('click', function () { hideAdOverlay(); scheduleAd(); });
  }

  currentScreenId = detectCurrentScreen() || currentScreenId;
  syncAdByState();
})();
</script>

<?php render_footer(['isAdmin' => true]); ?>
