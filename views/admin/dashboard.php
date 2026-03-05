<?php
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../layout/app.php';
require_admin();
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$db = get_db();
ensure_order_qr_schema($db);
ensure_order_attendee_checkin_schema($db);
ensure_order_attendee_package_schema($db);
ensure_order_attendee_payment_schema($db);
ensure_order_attendee_court_schema($db);
ensure_admin_notification_schema($db);
$flash = ['success' => '', 'error' => ''];
$selectedOrderIdRaw = trim((string)($_REQUEST['filter_order_id'] ?? ''));
$selectedOrderId = ctype_digit($selectedOrderIdRaw) ? (int)$selectedOrderIdRaw : 0;
$selectedPackage = isset($_REQUEST['package']) ? (int)$_REQUEST['package'] : 0;
$selectedCourtRaw = trim((string)($_REQUEST['court'] ?? ''));
$selectedCourt = ctype_digit($selectedCourtRaw) ? (int)$selectedCourtRaw : 0;
$selectedCourt = ($selectedCourt >= 1 && $selectedCourt <= 6) ? $selectedCourt : 0;
$selectedName = trim((string)($_REQUEST['name'] ?? ''));
$selectedEmail = trim((string)($_REQUEST['email'] ?? ''));
$selectedDate = trim((string)($_REQUEST['created_date'] ?? ''));
$selectedStatusRaw = trim((string)($_REQUEST['status'] ?? ''));
$selectedArrivalRaw = trim((string)($_REQUEST['arrival'] ?? ''));
$selectedPage = isset($_REQUEST['page']) ? max(1, (int)$_REQUEST['page']) : 1;
$allowedStatusFilters = ['pending', 'paid', 'accepted', 'rejected'];
$allowedArrivalFilters = ['arrived', 'not_arrived'];
$selectedStatus = in_array($selectedStatusRaw, $allowedStatusFilters, true) ? $selectedStatusRaw : '';
$selectedArrival = in_array($selectedArrivalRaw, $allowedArrivalFilters, true) ? $selectedArrivalRaw : '';
if ($selectedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = '';
}
$sponsorsTableSql = "CREATE TABLE IF NOT EXISTS sponsors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    website_url VARCHAR(255) NULL,
    logo_path VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$adsTableSql = "CREATE TABLE IF NOT EXISTS ads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    video_path VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

function create_image_from_upload(string $path, string $mime) {
    if (!is_file($path)) return null;
    switch ($mime) {
        case 'image/jpeg': return @imagecreatefromjpeg($path) ?: null;
        case 'image/png': return @imagecreatefrompng($path) ?: null;
        case 'image/webp':
            if (!function_exists('imagecreatefromwebp')) return null;
            return @imagecreatefromwebp($path) ?: null;
        default: return null;
    }
}

function rgba_from_image_pixel($img, int $x, int $y): array {
    $rgba = imagecolorsforindex($img, imagecolorat($img, $x, $y));
    return [
        'red' => (int)($rgba['red'] ?? 0),
        'green' => (int)($rgba['green'] ?? 0),
        'blue' => (int)($rgba['blue'] ?? 0),
        'alpha' => (int)($rgba['alpha'] ?? 0),
    ];
}

function save_white_logo_png(string $tmpPath, string $mime, string $targetPath): bool {
    if (!extension_loaded('gd')) return false;
    $src = create_image_from_upload($tmpPath, $mime);
    if (!$src) return false;

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w <= 0 || $h <= 0 || $w > 4096 || $h > 4096) {
        imagedestroy($src);
        return false;
    }

    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    imagecopy($img, $src, 0, 0, 0, 0, $w, $h);
    imagedestroy($src);

    if ($mime === 'image/jpeg') {
        $cornerPoints = [
            [0, 0], [max(0, $w - 1), 0], [0, max(0, $h - 1)], [max(0, $w - 1), max(0, $h - 1)],
            [min(4, max(0, $w - 1)), min(4, max(0, $h - 1))],
            [max(0, $w - 1 - min(4, max(0, $w - 1))), min(4, max(0, $h - 1))],
            [min(4, max(0, $w - 1)), max(0, $h - 1 - min(4, max(0, $h - 1)))],
            [max(0, $w - 1 - min(4, max(0, $w - 1))), max(0, $h - 1 - min(4, max(0, $h - 1)))],
        ];
        $sumR = 0; $sumG = 0; $sumB = 0; $samples = 0;
        foreach ($cornerPoints as $pt) {
            $c = rgba_from_image_pixel($img, (int)$pt[0], (int)$pt[1]);
            $sumR += $c['red']; $sumG += $c['green']; $sumB += $c['blue']; $samples++;
        }
        $bgR = $samples > 0 ? (int)round($sumR / $samples) : 255;
        $bgG = $samples > 0 ? (int)round($sumG / $samples) : 255;
        $bgB = $samples > 0 ? (int)round($sumB / $samples) : 255;
        $threshold = 62;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $p = rgba_from_image_pixel($img, $x, $y);
                $dr = $p['red'] - $bgR;
                $dg = $p['green'] - $bgG;
                $db = $p['blue'] - $bgB;
                $distance = (int)round(sqrt(($dr * $dr) + ($dg * $dg) + ($db * $db)));
                $alpha = $p['alpha'];
                if ($distance < $threshold) {
                    $alpha = max($alpha, (int)round((1 - ($distance / $threshold)) * 127));
                }
                if ($alpha >= 126) {
                    imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, 255, 255, 255, 127));
                } else {
                    imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, 255, 255, 255, $alpha));
                }
            }
        }
    } else {
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $p = rgba_from_image_pixel($img, $x, $y);
                $alpha = $p['alpha'];
                if ($alpha >= 126) {
                    imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, 255, 255, 255, 127));
                } else {
                    imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, 255, 255, 255, $alpha));
                }
            }
        }
    }

    imagealphablending($img, false);
    imagesavealpha($img, true);
    $ok = @imagepng($img, $targetPath, 6);
    imagedestroy($img);
    return $ok;
}

ensure_session();
if (!empty($_SESSION['dashboard_flash']) && is_array($_SESSION['dashboard_flash'])) {
    $flash = array_merge($flash, $_SESSION['dashboard_flash']);
    unset($_SESSION['dashboard_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['success' => '', 'error' => ''];
    $dashboardAction = trim((string)($_POST['dashboard_action'] ?? 'order_decision'));
    $isAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    if ($dashboardAction === 'add_admin_notification_email') {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $adminNotifyEmail = strtolower(trim((string)($_POST['admin_notify_email'] ?? '')));

        if ($adminId <= 0) {
            $flash['error'] = 'Session admin tidak valid. Silakan login ulang.';
        } elseif ($adminNotifyEmail === '' || !filter_var($adminNotifyEmail, FILTER_VALIDATE_EMAIL)) {
            $flash['error'] = 'Format email admin tidak valid.';
        } else {
            try {
                $check = $db->prepare('SELECT id FROM admin_notification_emails WHERE email = ? LIMIT 1');
                $check->execute([$adminNotifyEmail]);
                $exists = $check->fetch(PDO::FETCH_ASSOC);
                if ($exists) {
                    $flash['success'] = 'Email admin sudah terdaftar sebagai penerima notifikasi.';
                } else {
                    $now = date('Y-m-d H:i:s');
                    $insert = $db->prepare('INSERT INTO admin_notification_emails (email, verified_at, created_by_admin_id, created_at) VALUES (?, ?, ?, ?)');
                    $insert->execute([$adminNotifyEmail, $now, $adminId > 0 ? $adminId : null, $now]);
                    $flash['success'] = 'Email admin berhasil ditambahkan untuk menerima notifikasi order.';
                }
            } catch (Throwable $e) {
                $flash['error'] = 'Gagal memproses email admin.';
            }
        }
    } elseif ($dashboardAction === 'remove_admin_notification_email') {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $targetId = (int)($_POST['admin_notification_email_id'] ?? 0);

        if ($adminId <= 0) {
            $flash['error'] = 'Session admin tidak valid. Silakan login ulang.';
        } elseif ($targetId <= 0) {
            $flash['error'] = 'Data email admin tidak valid.';
        } else {
            try {
                $deleteStmt = $db->prepare('DELETE FROM admin_notification_emails WHERE id = ? LIMIT 1');
                $deleteStmt->execute([$targetId]);
                if ($deleteStmt->rowCount() > 0) {
                    $flash['success'] = 'Email admin berhasil dihapus dari daftar notifikasi.';
                } else {
                    $flash['error'] = 'Email admin tidak ditemukan.';
                }
            } catch (Throwable $e) {
                $flash['error'] = 'Gagal menghapus email admin.';
            }
        }
    } elseif ($dashboardAction === 'change_admin_password') {
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
                    $newFileName = 'sponsor-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.png';
                    $targetPath = $uploadDir . '/' . $newFileName;
                    $storedLogoPath = '/uploads/sponsors/' . $newFileName;
                    if (!save_white_logo_png($tmpPath, $mime, $targetPath)) {
                        $flash['error'] = 'Gagal memproses logo. Upload PNG/JPG/WEBP dengan background polos.';
                    } else {
                        try {
                            $db->exec($sponsorsTableSql);
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
    } elseif ($dashboardAction === 'remove_sponsor') {
        $sponsorId = (int)($_POST['sponsor_id'] ?? 0);

        if ($sponsorId <= 0) {
            $flash['error'] = 'Data sponsor tidak valid.';
        } else {
            try {
                $db->exec($sponsorsTableSql);
                $findSponsor = $db->prepare('SELECT logo_path FROM sponsors WHERE id = ? LIMIT 1');
                $findSponsor->execute([$sponsorId]);
                $sponsorRow = $findSponsor->fetch(PDO::FETCH_ASSOC);
                if (!$sponsorRow) {
                    $flash['error'] = 'Sponsor tidak ditemukan.';
                } else {
                    $deleteSponsor = $db->prepare('DELETE FROM sponsors WHERE id = ? LIMIT 1');
                    $deleteSponsor->execute([$sponsorId]);
                    if ($deleteSponsor->rowCount() > 0) {
                        $logoPath = trim((string)($sponsorRow['logo_path'] ?? ''));
                        if (preg_match('#^/uploads/sponsors/[A-Za-z0-9._-]+$#', $logoPath)) {
                            $logoFilePath = __DIR__ . '/../../' . ltrim($logoPath, '/');
                            if (is_file($logoFilePath)) {
                                @unlink($logoFilePath);
                            }
                        }
                        $flash['success'] = 'Sponsor berhasil dihapus.';
                    } else {
                        $flash['error'] = 'Sponsor tidak ditemukan.';
                    }
                }
            } catch (Throwable $e) {
                $flash['error'] = 'Gagal menghapus sponsor.';
            }
        }
    } elseif ($dashboardAction === 'create_ad') {
        $adTitle = trim((string)($_POST['ad_title'] ?? ''));
        $adSourceType = trim((string)($_POST['ad_source_type'] ?? ''));
        $adUrl = trim((string)($_POST['ad_url'] ?? ''));
        $videoFile = $_FILES['ad_video'] ?? null;
        $hasUploadedVideo = is_array($videoFile) && (int)($videoFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

        if ($adTitle === '') {
            $flash['error'] = 'Judul iklan wajib diisi.';
        } elseif (mb_strlen($adTitle) > 150) {
            $flash['error'] = 'Judul iklan terlalu panjang.';
        } elseif (!in_array($adSourceType, ['link', 'upload'], true)) {
            $flash['error'] = 'Pilih sumber iklan: link atau upload video.';
        } elseif ($adSourceType === 'link' && $adUrl === '') {
            $flash['error'] = 'Link iklan wajib diisi.';
        } elseif ($adSourceType === 'upload' && !$hasUploadedVideo) {
            $flash['error'] = 'File video iklan wajib diunggah.';
        } elseif ($adSourceType === 'link' && $hasUploadedVideo) {
            $flash['error'] = 'Mode link dipilih, file video tidak boleh diisi.';
        } elseif ($adSourceType === 'upload' && $adUrl !== '') {
            $flash['error'] = 'Mode upload dipilih, link tidak boleh diisi.';
        } else {
            $storedVideoPath = '';
            if ($adSourceType === 'link') {
                if (!filter_var($adUrl, FILTER_VALIDATE_URL)) {
                    $flash['error'] = 'Link iklan tidak valid.';
                } else {
                    $urlHost = strtolower((string)(parse_url($adUrl, PHP_URL_HOST) ?? ''));
                    $urlPath = strtolower((string)(parse_url($adUrl, PHP_URL_PATH) ?? ''));
                    $isYoutube = in_array($urlHost, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtu.be'], true);
                    $isInstagramReel = in_array($urlHost, ['instagram.com', 'www.instagram.com', 'm.instagram.com'], true)
                        && (strpos($urlPath, '/reel/') === 0 || strpos($urlPath, '/reels/') === 0);
                    if (!$isYoutube && !$isInstagramReel) {
                        $flash['error'] = 'Link harus dari YouTube atau Instagram Reels.';
                    } else {
                        $storedVideoPath = $adUrl;
                    }
                }
            } else {
                $tmpPath = (string)($videoFile['tmp_name'] ?? '');
                $mime = '';
                if ($tmpPath !== '' && is_file($tmpPath)) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo) { $mime = (string)finfo_file($finfo, $tmpPath); finfo_close($finfo); }
                }
                $allowedMimes = [
                    'video/mp4' => 'mp4',
                    'video/webm' => 'webm',
                    'video/ogg' => 'ogv',
                    'video/quicktime' => 'mov',
                ];
                if (!isset($allowedMimes[$mime])) {
                    $flash['error'] = 'Video harus format MP4, WEBM, OGV, atau MOV.';
                } elseif ((int)($videoFile['size'] ?? 0) > 52428800) {
                    $flash['error'] = 'Ukuran video maksimal 50MB.';
                } else {
                    $uploadDir = __DIR__ . '/../../uploads/ads';
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                        $flash['error'] = 'Gagal menyiapkan folder upload iklan.';
                    } else {
                        $newFileName = 'ad-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedMimes[$mime];
                        $targetPath = $uploadDir . '/' . $newFileName;
                        $storedVideoPath = '/uploads/ads/' . $newFileName;
                        if (!move_uploaded_file($tmpPath, $targetPath)) {
                            $flash['error'] = 'Gagal upload video iklan.';
                            $storedVideoPath = '';
                        }
                    }
                }
            }

            if ($flash['error'] === '' && $storedVideoPath !== '') {
                try {
                    $db->exec($adsTableSql);
                    $insertAd = $db->prepare('INSERT INTO ads (title, video_path, created_at) VALUES (?, ?, ?)');
                    $insertAd->execute([$adTitle, $storedVideoPath, date('Y-m-d H:i:s')]);
                    $flash['success'] = 'Iklan berhasil ditambahkan.';
                } catch (Throwable $e) {
                    if (strpos($storedVideoPath, '/uploads/ads/') === 0) {
                        $uploadedFilePath = __DIR__ . '/../../' . ltrim($storedVideoPath, '/');
                        if (is_file($uploadedFilePath)) @unlink($uploadedFilePath);
                    }
                    $flash['error'] = 'Gagal menyimpan data iklan.';
                }
            }
        }
    } elseif ($dashboardAction === 'remove_ad') {
        $adId = (int)($_POST['ad_id'] ?? 0);

        if ($adId <= 0) {
            $flash['error'] = 'Data iklan tidak valid.';
        } else {
            try {
                $db->exec($adsTableSql);
                $findAd = $db->prepare('SELECT video_path FROM ads WHERE id = ? LIMIT 1');
                $findAd->execute([$adId]);
                $adRow = $findAd->fetch(PDO::FETCH_ASSOC);
                if (!$adRow) {
                    $flash['error'] = 'Iklan tidak ditemukan.';
                } else {
                    $deleteAd = $db->prepare('DELETE FROM ads WHERE id = ? LIMIT 1');
                    $deleteAd->execute([$adId]);
                    if ($deleteAd->rowCount() > 0) {
                        $videoPath = trim((string)($adRow['video_path'] ?? ''));
                        if (preg_match('#^/uploads/ads/[A-Za-z0-9._-]+$#', $videoPath)) {
                            $videoFilePath = __DIR__ . '/../../' . ltrim($videoPath, '/');
                            if (is_file($videoFilePath)) {
                                @unlink($videoFilePath);
                            }
                        }
                        $flash['success'] = 'Iklan berhasil dihapus.';
                    } else {
                        $flash['error'] = 'Iklan tidak ditemukan.';
                    }
                }
            } catch (Throwable $e) {
                $flash['error'] = 'Gagal menghapus iklan.';
            }
        }
    } elseif ($dashboardAction === 'update_attendee_court') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $attendeeId = (int)($_POST['attendee_id'] ?? 0);
        $courtNo = (int)($_POST['court_no'] ?? 0);
        $responseCourtNo = ($courtNo >= 1 && $courtNo <= 6) ? $courtNo : 0;

        if ($orderId <= 0 || $attendeeId <= 0) {
            $flash['error'] = 'Data attendee tidak valid.';
        } elseif ($courtNo < 0 || $courtNo > 6) {
            $flash['error'] = 'Court harus No Court atau Court 1 sampai 6.';
        } else {
            try {
                $attendeeStmt = $db->prepare(
                    'SELECT oa.id, p.name AS package_name
                     FROM order_attendees oa
                     LEFT JOIN packages p ON p.id = oa.package_id
                     WHERE oa.id = ? AND oa.order_id = ? LIMIT 1'
                );
                $attendeeStmt->execute([$attendeeId, $orderId]);
                $attendeeRow = $attendeeStmt->fetch(PDO::FETCH_ASSOC);
                if (!$attendeeRow) {
                    $flash['error'] = 'Attendee tidak ditemukan pada order ini.';
                } else {
                    $packageName = trim((string)($attendeeRow['package_name'] ?? ''));
                    $isPackageC = strcasecmp($packageName, 'Package C') === 0;
                    if (!$isPackageC && $courtNo === 0) {
                        $flash['error'] = 'Package ini wajib memilih Court 1 sampai 6.';
                    } else {
                        $storeCourtNo = ($courtNo >= 1 && $courtNo <= 6) ? $courtNo : null;
                        $updateCourtStmt = $db->prepare('UPDATE order_attendees SET court_no = ? WHERE id = ? AND order_id = ? LIMIT 1');
                        $updateCourtStmt->execute([$storeCourtNo, $attendeeId, $orderId]);
                        if ($updateCourtStmt->rowCount() > 0) {
                            $flash['success'] = 'Court attendee berhasil diperbarui.';
                        } else {
                            $flash['success'] = 'Court attendee sudah sesuai.';
                        }
                    }
                }
            } catch (Throwable $e) {
                $flash['error'] = 'Gagal menyimpan court attendee.';
            }
        }

        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok' => $flash['error'] === '',
                'message' => $flash['error'] !== '' ? $flash['error'] : $flash['success'],
                'court_no' => $responseCourtNo,
            ]);
            exit;
        }
    } elseif ($dashboardAction === 'change_attendee_package') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $attendeeId = (int)($_POST['attendee_id'] ?? 0);
        $newPackageId = (int)($_POST['new_package_id'] ?? 0);
        $updatedPackageName = '';
        $updatedOrderTotal = null;
        $packageChangeEmailPayload = null;
        $packageChangeEmailMeta = null;

        if ($orderId <= 0 || $attendeeId <= 0 || $newPackageId <= 0) {
            $flash['error'] = 'Data perubahan package attendee tidak valid.';
        } else {
            try {
                $pkgStmt = $db->prepare('SELECT id, name, price FROM packages WHERE id = ? LIMIT 1');
                $pkgStmt->execute([$newPackageId]);
                $newPackageRow = $pkgStmt->fetch(PDO::FETCH_ASSOC);
                if (!$newPackageRow) {
                    $flash['error'] = 'Package baru tidak ditemukan.';
                } else {
                    $detailStmt = $db->prepare(
                        'SELECT
                            o.id,
                            o.status,
                            o.qr_token,
                            o.package_qr_rotated_at,
                            u.email,
                            u.full_name,
                            oa.id AS attendee_id,
                            oa.attendee_name,
                            oa.checked_in_at,
                            oa.package_id AS old_package_id,
                            pold.name AS old_package_name
                         FROM orders o
                         JOIN users u ON u.id = o.user_id
                         JOIN order_attendees oa ON oa.order_id = o.id
                         LEFT JOIN packages pold ON pold.id = oa.package_id
                         WHERE o.id = ? AND oa.id = ?
                         LIMIT 1'
                    );
                    $detailStmt->execute([$orderId, $attendeeId]);
                    $row = $detailStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$row) {
                        $flash['error'] = 'Attendee tidak ditemukan pada order ini.';
                    } elseif ((string)($row['status'] ?? '') !== 'accepted') {
                        $flash['error'] = 'Package attendee hanya bisa diubah setelah order di-accept.';
                    } elseif (!empty($row['checked_in_at'])) {
                        $flash['error'] = 'Package attendee yang sudah check-in tidak bisa diubah.';
                    } else {
                        $oldPackageId = (int)($row['old_package_id'] ?? 0);
                        $oldPackageName = trim((string)($row['old_package_name'] ?? ''));
                        $attendeeName = trim((string)($row['attendee_name'] ?? ''));
                        if ($oldPackageId === $newPackageId) {
                            $flash['success'] = 'Package attendee sudah sesuai, tidak ada perubahan.';
                            $updatedPackageName = $oldPackageName;
                        } else {
                            $db->beginTransaction();

                            $newPackageName = trim((string)($newPackageRow['name'] ?? ''));
                            $isNewPackageC = strcasecmp($newPackageName, 'Package C') === 0;
                            if ($isNewPackageC) {
                                $updAttendee = $db->prepare('UPDATE order_attendees SET package_id = ?, court_no = NULL WHERE id = ? AND order_id = ?');
                                $updAttendee->execute([$newPackageId, $attendeeId, $orderId]);
                            } else {
                                $updAttendee = $db->prepare('UPDATE order_attendees SET package_id = ? WHERE id = ? AND order_id = ?');
                                $updAttendee->execute([$newPackageId, $attendeeId, $orderId]);
                            }
                            if ($updAttendee->rowCount() <= 0) {
                                throw new RuntimeException('Gagal memperbarui package attendee.');
                            }

                            // Rebuild order_items from attendee rows to avoid mismatch issues.
                            $rebuildStmt = $db->prepare(
                                'SELECT oa.package_id, COUNT(*) AS qty, COALESCE(MAX(p.price), 0) AS price
                                 FROM order_attendees oa
                                 LEFT JOIN packages p ON p.id = oa.package_id
                                 WHERE oa.order_id = ? AND oa.package_id IS NOT NULL AND oa.package_id > 0
                                 GROUP BY oa.package_id'
                            );
                            $rebuildStmt->execute([$orderId]);
                            $rebuiltItems = $rebuildStmt->fetchAll(PDO::FETCH_ASSOC);

                            $clearItemsStmt = $db->prepare('DELETE FROM order_items WHERE order_id = ?');
                            $clearItemsStmt->execute([$orderId]);

                            if ($rebuiltItems) {
                                $insertItemStmt = $db->prepare('INSERT INTO order_items (order_id, package_id, qty, price) VALUES (?, ?, ?, ?)');
                                foreach ($rebuiltItems as $itemRow) {
                                    $rebuiltPackageId = (int)($itemRow['package_id'] ?? 0);
                                    $rebuiltQty = max(0, (int)($itemRow['qty'] ?? 0));
                                    if ($rebuiltPackageId <= 0 || $rebuiltQty <= 0) {
                                        continue;
                                    }
                                    $rebuiltPrice = max(0, (int)($itemRow['price'] ?? 0));
                                    $insertItemStmt->execute([$orderId, $rebuiltPackageId, $rebuiltQty, $rebuiltPrice]);
                                }
                            }

                            $totalStmt = $db->prepare('SELECT COALESCE(SUM(qty * price), 0) FROM order_items WHERE order_id = ?');
                            $totalStmt->execute([$orderId]);
                            $recalculatedTotal = (int)($totalStmt->fetchColumn() ?: 0);
                            $updTotalStmt = $db->prepare('UPDATE orders SET total = ? WHERE id = ?');
                            $updTotalStmt->execute([$recalculatedTotal, $orderId]);

                            $updatedPackageName = (string)($newPackageRow['name'] ?? '');
                            $updatedOrderTotal = $recalculatedTotal;

                            $hasRotatedBefore = !empty($row['package_qr_rotated_at']);
                            if (!$hasRotatedBefore) {
                                // First package change only: issue and send a new QR token.
                                $newQrToken = strtolower(bin2hex(random_bytes(24)));
                                $rotateAt = date('Y-m-d H:i:s');
                                $updQrStmt = $db->prepare('UPDATE orders SET qr_token = ?, qr_sent_at = ?, package_qr_rotated_at = ? WHERE id = ?');
                                $updQrStmt->execute([$newQrToken, $rotateAt, $rotateAt, $orderId]);
                                $packageChangeEmailPayload = [
                                    'id' => $orderId,
                                    'full_name' => (string)($row['full_name'] ?? ''),
                                    'qr_token' => $newQrToken,
                                ];
                                $packageChangeEmailMeta = [
                                    'attendee_name' => $attendeeName !== '' ? $attendeeName : ('Attendee ' . $attendeeId),
                                    'old_package' => $oldPackageName !== '' ? $oldPackageName : ('Package ' . $oldPackageId),
                                    'new_package' => $updatedPackageName !== '' ? $updatedPackageName : ('Package ' . $newPackageId),
                                ];
                            }

                            $db->commit();

                            $flash['success'] = 'Package attendee berhasil diubah.';
                            $toEmail = strtolower(trim((string)($row['email'] ?? '')));
                            if (
                                $packageChangeEmailPayload !== null
                                && $packageChangeEmailMeta !== null
                                && $toEmail !== ''
                                && filter_var($toEmail, FILTER_VALIDATE_EMAIL)
                            ) {
                                $emailSent = send_attendee_package_changed_email($packageChangeEmailPayload, $toEmail, $packageChangeEmailMeta);
                                if (!$emailSent) {
                                    $flash['success'] = 'Package attendee berhasil diubah, tetapi email QR baru gagal dikirim.';
                                }
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($flash['error'] === '') {
                    $flash['error'] = 'Gagal mengubah package attendee.';
                }
            }
        }

        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok' => $flash['error'] === '',
                'message' => $flash['error'] !== '' ? $flash['error'] : $flash['success'],
                'order_id' => $orderId > 0 ? $orderId : null,
                'attendee_id' => $attendeeId > 0 ? $attendeeId : null,
                'package_id' => $newPackageId > 0 ? $newPackageId : null,
                'package_name' => $updatedPackageName,
                'order_total' => $updatedOrderTotal,
            ]);
            exit;
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
            $proofPaths = $orderRow ? get_order_payment_proof_paths($orderRow) : [];
            if (!$orderRow) {
                $flash['error'] = 'Order not found.';
            } elseif (!$proofPaths) {
                $flash['error'] = 'Cannot update. Payment proof is required.';
            } elseif ($orderRow['status'] !== 'paid') {
                $flash['error'] = 'Only paid orders can be accepted or rejected.';
            } else {
                $newStatus = $action === 'accept' ? 'accepted' : 'rejected';
                if ($newStatus === 'accepted') {
                    $missingCourtCount = 0;
                    try {
                        $missingCourtStmt = $db->prepare(
                            "SELECT COUNT(*)
                             FROM order_attendees oa
                             LEFT JOIN packages p ON p.id = oa.package_id
                             WHERE oa.order_id = ?
                               AND (oa.court_no IS NULL OR oa.court_no < 1 OR oa.court_no > 6)
                               AND (p.name IS NULL OR LOWER(TRIM(p.name)) <> 'package c')"
                        );
                        $missingCourtStmt->execute([$orderId]);
                        $missingCourtCount = (int)$missingCourtStmt->fetchColumn();
                    } catch (Throwable $e) {
                        $missingCourtCount = 0;
                    }
                    if ($missingCourtCount > 0) {
                        $flash['error'] = 'Tidak bisa Accept. Masih ada ' . $missingCourtCount . ' attendee yang belum pilih court. Buka Detail dan pilih court dulu.';
                    } else {
                        $qrToken = extract_qr_token((string)($orderRow['qr_token'] ?? ''));
                        if ($qrToken === '') $qrToken = strtolower(bin2hex(random_bytes(24)));
                        $update = $db->prepare('UPDATE orders SET status = ?, qr_token = ?, qr_sent_at = ?, checked_in_at = NULL WHERE id = ?');
                        $update->execute([$newStatus, $qrToken, date('Y-m-d H:i:s'), $orderId]);
                        $orderRow['qr_token'] = $qrToken;
                        try { $db->prepare('UPDATE order_attendees SET checked_in_at = NULL WHERE order_id = ?')->execute([$orderId]); } catch (Throwable $e) {}
                    }
                } else {
                    $update = $db->prepare('UPDATE orders SET status = ?, qr_token = NULL, qr_sent_at = NULL, checked_in_at = NULL WHERE id = ?');
                    $update->execute([$newStatus, $orderId]);
                    $orderRow['qr_token'] = null;
                    try { $db->prepare('UPDATE order_attendees SET checked_in_at = NULL WHERE order_id = ?')->execute([$orderId]); } catch (Throwable $e) {}
                }
                if ($flash['error'] === '') {
                    $orderRow['status'] = $newStatus;
                    $sent = send_order_status_email($orderRow, $orderRow['email']);
                    $flash['success'] = $sent ? 'Order status updated and email sent.' : 'Order status updated, but email failed to send.';
                }
            }
        }
    }

    $_SESSION['dashboard_flash'] = $flash;
    $redirectParams = [];
    if ($selectedPackage > 0) $redirectParams['package'] = $selectedPackage;
    if ($selectedCourt > 0) $redirectParams['court'] = $selectedCourt;
    if ($selectedOrderId > 0) $redirectParams['filter_order_id'] = $selectedOrderId;
    if ($selectedName !== '') $redirectParams['name'] = $selectedName;
    if ($selectedEmail !== '') $redirectParams['email'] = $selectedEmail;
    if ($selectedDate !== '') $redirectParams['created_date'] = $selectedDate;
    if ($selectedStatus !== '') $redirectParams['status'] = $selectedStatus;
    if ($selectedArrival !== '') $redirectParams['arrival'] = $selectedArrival;
    if ($selectedPage > 1) $redirectParams['page'] = $selectedPage;
    $redirectPath = '/admin/dashboard';
    if ($redirectParams) $redirectPath .= '?' . http_build_query($redirectParams);
    redirect($redirectPath);
}

$adminNotificationEmails = [];
try {
    $adminNotificationEmails = $db->query('SELECT id, email, verified_at, created_at FROM admin_notification_emails ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $adminNotificationEmails = [];
}

$dashboardSponsors = [];
try {
    $db->exec($sponsorsTableSql);
    $dashboardSponsors = $db->query('SELECT id, name, website_url, logo_path, created_at FROM sponsors ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dashboardSponsors = [];
}

$dashboardAds = [];
try {
    $db->exec($adsTableSql);
    $dashboardAds = $db->query('SELECT id, title, video_path, created_at FROM ads ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dashboardAds = [];
}

$packages = $db->query("SELECT id, name, price FROM packages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$whereParts = ['1=1'];
$params = [];
if ($selectedOrderId > 0) { $whereParts[] = "o.id = ?"; $params[] = $selectedOrderId; }
if ($selectedPackage > 0) { $whereParts[] = "EXISTS (SELECT 1 FROM order_items oi JOIN packages p ON p.id = oi.package_id WHERE oi.order_id = o.id AND p.id = ?)"; $params[] = $selectedPackage; }
if ($selectedCourt > 0) { $whereParts[] = "EXISTS (SELECT 1 FROM order_attendees oa WHERE oa.order_id = o.id AND oa.court_no = ?)"; $params[] = $selectedCourt; }
if ($selectedName !== '') {
    $whereParts[] = "(u.full_name LIKE ? OR EXISTS (SELECT 1 FROM order_attendees oa_name WHERE oa_name.order_id = o.id AND oa_name.attendee_name LIKE ?))";
    $params[] = '%' . $selectedName . '%';
    $params[] = '%' . $selectedName . '%';
}
if ($selectedEmail !== '') { $whereParts[] = "u.email LIKE ?"; $params[] = '%' . $selectedEmail . '%'; }
if ($selectedDate !== '') { $whereParts[] = "DATE(o.created_at) = ?"; $params[] = $selectedDate; }
if ($selectedStatus === 'paid' || $selectedStatus === 'accepted' || $selectedStatus === 'rejected') { $whereParts[] = "o.status = ?"; $params[] = $selectedStatus; }
elseif ($selectedStatus === 'pending') { $whereParts[] = "o.status = 'pending'"; }
if ($selectedArrival === 'arrived') {
    $whereParts[] = "EXISTS (SELECT 1 FROM order_attendees oa_arr WHERE oa_arr.order_id = o.id AND oa_arr.checked_in_at IS NOT NULL)";
} elseif ($selectedArrival === 'not_arrived') {
    $whereParts[] = "EXISTS (SELECT 1 FROM order_attendees oa_arr WHERE oa_arr.order_id = o.id AND oa_arr.checked_in_at IS NULL)";
}
$whereSql = ' WHERE ' . implode(' AND ', $whereParts);

if (strtolower(trim((string)($_GET['export'] ?? ''))) === 'excel') {
    $exportTypeRaw = strtolower(trim((string)($_GET['export_type'] ?? 'order')));
    $exportType = in_array($exportTypeRaw, ['order', 'attendee'], true) ? $exportTypeRaw : 'order';
    $exportWhereParts = ["o.status = 'accepted'"];
    $exportParams = [];
    if ($selectedOrderId > 0) { $exportWhereParts[] = "o.id = ?"; $exportParams[] = $selectedOrderId; }
    if ($selectedPackage > 0) { $exportWhereParts[] = "EXISTS (SELECT 1 FROM order_items oi JOIN packages p ON p.id = oi.package_id WHERE oi.order_id = o.id AND p.id = ?)"; $exportParams[] = $selectedPackage; }
    if ($selectedCourt > 0) { $exportWhereParts[] = "EXISTS (SELECT 1 FROM order_attendees oa WHERE oa.order_id = o.id AND oa.court_no = ?)"; $exportParams[] = $selectedCourt; }
    if ($selectedName !== '') {
        $exportWhereParts[] = "(u.full_name LIKE ? OR EXISTS (SELECT 1 FROM order_attendees oa_name WHERE oa_name.order_id = o.id AND oa_name.attendee_name LIKE ?))";
        $exportParams[] = '%' . $selectedName . '%';
        $exportParams[] = '%' . $selectedName . '%';
    }
    if ($selectedEmail !== '') { $exportWhereParts[] = "u.email LIKE ?"; $exportParams[] = '%' . $selectedEmail . '%'; }
    if ($selectedDate !== '') { $exportWhereParts[] = "DATE(o.created_at) = ?"; $exportParams[] = $selectedDate; }
    if ($selectedArrival === 'arrived') {
        $exportWhereParts[] = "EXISTS (SELECT 1 FROM order_attendees oa_arr WHERE oa_arr.order_id = o.id AND oa_arr.checked_in_at IS NOT NULL)";
    } elseif ($selectedArrival === 'not_arrived') {
        $exportWhereParts[] = "EXISTS (SELECT 1 FROM order_attendees oa_arr WHERE oa_arr.order_id = o.id AND oa_arr.checked_in_at IS NULL)";
    }
    $exportWhereSql = ' WHERE ' . implode(' AND ', $exportWhereParts);

    $sanitizeCell = static function ($value): string {
        $text = trim((string)$value);
        $text = str_replace(["\r", "\n", "\t"], [' ', ' ', ' '], $text);
        if ($text !== '' && preg_match('/^[=\-+@]/', $text)) {
            $text = "'" . $text;
        }
        return $text;
    };
    $escapeXml = static function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };
    $xmlCell = static function (string $value, string $type = 'String', string $styleId = '') use ($escapeXml): string {
        $styleAttr = $styleId !== '' ? ' ss:StyleID="' . $styleId . '"' : '';
        return '<Cell' . $styleAttr . '><Data ss:Type="' . $type . '">' . $escapeXml($value) . '</Data></Cell>';
    };

    if ($exportType === 'attendee') {
        $exportSql = "SELECT
            oa.attendee_name,
            u.full_name AS orderer_name,
            oa.gender,
            p.name AS package_name,
            oa.court_no,
            oa.checked_in_at
            FROM order_attendees oa
            JOIN orders o ON o.id = oa.order_id
            JOIN users u ON u.id = o.user_id
            LEFT JOIN packages p ON p.id = oa.package_id" . $exportWhereSql . "
            AND TRIM(oa.attendee_name) <> ''
            AND LOWER(TRIM(oa.attendee_name)) <> LOWER(TRIM(u.full_name))
            ORDER BY o.id ASC, oa.id ASC";
    } else {
        $exportSql = "SELECT
            o.id,
            u.full_name,
            u.phone,
            u.email,
            u.instagram,
            o.total,
            o.created_at,
            COALESCE((
                SELECT GROUP_CONCAT(CONCAT(p.name, ' x', oi.qty) SEPARATOR ', ')
                FROM order_items oi
                JOIN packages p ON p.id = oi.package_id
                WHERE oi.order_id = o.id
            ), '') AS items
            FROM orders o
            JOIN users u ON u.id = o.user_id" . $exportWhereSql . "
            ORDER BY o.created_at DESC, o.id DESC";
    }

    $exportStmt = $db->prepare($exportSql);
    $exportStmt->execute($exportParams);
    $exportRows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    $fileName = ($exportType === 'attendee' ? 'Attendee_report-' : 'Order_report-') . date('Ymd-His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    echo "\xEF\xBB\xBF";
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<?mso-application progid="Excel.Sheet"?>';
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
    echo ' xmlns:o="urn:schemas-microsoft-com:office:office"';
    echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
    echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"';
    echo ' xmlns:html="http://www.w3.org/TR/REC-html40">';
    echo '<Styles>';
    echo '<Style ss:ID="Header"><Font ss:Bold="1"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Interior ss:Color="#DCE6F1" ss:Pattern="Solid"/></Style>';
    echo '<Style ss:ID="Text"><NumberFormat ss:Format="@"/></Style>';
    echo '<Style ss:ID="Number"><NumberFormat ss:Format="0"/></Style>';
    echo '<Style ss:ID="CenterText"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><NumberFormat ss:Format="@"/></Style>';
    echo '<Style ss:ID="CenterNumber"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><NumberFormat ss:Format="0"/></Style>';
    echo '</Styles>';
    echo '<Worksheet ss:Name="' . ($exportType === 'attendee' ? 'Attendees' : 'Orders') . '">';
    echo '<Table>';
    if ($exportType === 'attendee') {
        echo '<Column ss:AutoFitWidth="1" ss:Width="45"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="220"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="220"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="90"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="150"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="120"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="210"/>';
        echo '<Row>';
        echo $xmlCell('No', 'String', 'Header');
        echo $xmlCell('Nama Pengorder', 'String', 'Header');
        echo $xmlCell('Nama Attendee', 'String', 'Header');
        echo $xmlCell('Gender', 'String', 'Header');
        echo $xmlCell('Package', 'String', 'Header');
        echo $xmlCell('Court', 'String', 'Header');
        echo $xmlCell('Hadir', 'String', 'Header');
        echo '</Row>';

        $rowNo = 1;
        foreach ($exportRows as $row) {
            $attendeeName = $sanitizeCell((string)($row['attendee_name'] ?? ''));
            if ($attendeeName === '') {
                continue;
            }
            $courtNo = (int)($row['court_no'] ?? 0);
            $courtLabel = ($courtNo >= 1 && $courtNo <= 6) ? ('Court ' . $courtNo) : '-';
            $checkedInRaw = trim((string)($row['checked_in_at'] ?? ''));
            $checkedInTime = '-';
            if ($checkedInRaw !== '') {
                $checkedInTs = strtotime($checkedInRaw);
                if ($checkedInTs !== false) {
                    $dayNameMap = [
                        'Sunday' => 'Minggu',
                        'Monday' => 'Senin',
                        'Tuesday' => 'Selasa',
                        'Wednesday' => 'Rabu',
                        'Thursday' => 'Kamis',
                        'Friday' => 'Jumat',
                        'Saturday' => 'Sabtu',
                    ];
                    $dayName = $dayNameMap[date('l', $checkedInTs)] ?? date('l', $checkedInTs);
                    $checkedInTime = $dayName . ', ' . date('d-m-Y H:i', $checkedInTs);
                } else {
                    $checkedInTime = $checkedInRaw;
                }
            }
            echo '<Row>';
            echo $xmlCell((string)$rowNo, 'Number', 'CenterNumber');
            echo $xmlCell($sanitizeCell((string)($row['orderer_name'] ?? '')), 'String', 'Text');
            echo $xmlCell($attendeeName, 'String', 'Text');
            echo $xmlCell($sanitizeCell((string)($row['gender'] ?? '-')), 'String', 'Text');
            echo $xmlCell($sanitizeCell((string)($row['package_name'] ?? '-')), 'String', 'Text');
            echo $xmlCell($courtLabel, 'String', 'Text');
            echo $xmlCell($checkedInTime, 'String', 'Text');
            echo '</Row>';
            $rowNo++;
        }
    } else {
        echo '<Column ss:AutoFitWidth="1" ss:Width="45"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="70"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="170"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="110"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="220"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="120"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="280"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="140"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="210"/>';
        echo '<Column ss:AutoFitWidth="1" ss:Width="80"/>';

        echo '<Row>';
        echo $xmlCell('No', 'String', 'Header');
        echo $xmlCell('Order ID', 'String', 'Header');
        echo $xmlCell('Nama', 'String', 'Header');
        echo $xmlCell('No. HP', 'String', 'Header');
        echo $xmlCell('Email', 'String', 'Header');
        echo $xmlCell('Instagram', 'String', 'Header');
        echo $xmlCell('Paket', 'String', 'Header');
        echo $xmlCell('Total', 'String', 'Header');
        echo $xmlCell('Tanggal Dibuat', 'String', 'Header');
        echo $xmlCell('Status', 'String', 'Header');
        echo '</Row>';

        $rowNo = 1;
        foreach ($exportRows as $row) {
            $instagramRaw = trim((string)($row['instagram'] ?? ''));
            if ($instagramRaw !== '' && $instagramRaw !== '-' && strpos($instagramRaw, '@') !== 0) {
                $instagramRaw = '@' . ltrim($instagramRaw, '@');
            }
            $instagramCell = str_replace(["\r", "\n", "\t"], [' ', ' ', ' '], $instagramRaw);
            $createdRaw = trim((string)($row['created_at'] ?? ''));
            $createdLabel = '-';
            if ($createdRaw !== '') {
                $createdTs = strtotime($createdRaw);
                if ($createdTs !== false) {
                    $dayNameMap = [
                        'Sunday' => 'Minggu',
                        'Monday' => 'Senin',
                        'Tuesday' => 'Selasa',
                        'Wednesday' => 'Rabu',
                        'Thursday' => 'Kamis',
                        'Friday' => 'Jumat',
                        'Saturday' => 'Sabtu',
                    ];
                    $dayName = $dayNameMap[date('l', $createdTs)] ?? date('l', $createdTs);
                    $createdLabel = $dayName . ', ' . date('d-m-Y H:i', $createdTs);
                } else {
                    $createdLabel = $createdRaw;
                }
            }
            $totalLabel = 'Rp ' . number_format((int)($row['total'] ?? 0), 0, ',', '.');
            echo '<Row>';
            echo $xmlCell((string)$rowNo, 'Number', 'CenterNumber');
            echo $xmlCell($sanitizeCell((string)($row['id'] ?? '')), 'String', 'CenterText');
            echo $xmlCell($sanitizeCell((string)($row['full_name'] ?? '')), 'String', 'Text');
            echo $xmlCell($sanitizeCell((string)($row['phone'] ?? '')), 'String', 'Text');
            echo $xmlCell($sanitizeCell((string)($row['email'] ?? '')), 'String', 'Text');
            echo $xmlCell($instagramCell, 'String', 'Text');
            echo $xmlCell($sanitizeCell((string)($row['items'] ?? '')), 'String', 'Text');
            echo $xmlCell($sanitizeCell($totalLabel), 'String', 'Text');
            echo $xmlCell($sanitizeCell($createdLabel), 'String', 'Text');
            echo $xmlCell('accepted', 'String', 'Text');
            echo '</Row>';
            $rowNo++;
        }
    }

    echo '</Table>';
    echo '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal><TopRowBottomPane>1</TopRowBottomPane></WorksheetOptions>';
    echo '</Worksheet>';
    echo '</Workbook>';
    exit;
}

$acceptedSummaryStmt = $db->prepare("SELECT
    COUNT(*) AS accepted_orders,
    COALESCE(SUM(o.total), 0) AS total_revenue,
    (
        SELECT COUNT(*)
        FROM order_attendees oa
        JOIN orders o2 ON o2.id = oa.order_id
        WHERE o2.status = 'accepted'
    ) AS total_attendees
    FROM orders o
    JOIN users u ON u.id = o.user_id
    WHERE o.status = 'accepted'");
$acceptedSummaryStmt->execute();
$acceptedSummary = $acceptedSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalOrders = (int)($acceptedSummary['accepted_orders'] ?? 0);
$totalRevenue = (int)($acceptedSummary['total_revenue'] ?? 0);
$totalAttendees = (int)($acceptedSummary['total_attendees'] ?? 0);

$packageSalesMap = [];
foreach ($packages as $pkg) {
    $packageId = (int)($pkg['id'] ?? 0);
    if ($packageId <= 0) {
        continue;
    }
    $packageSalesMap[$packageId] = [
        'id' => $packageId,
        'name' => (string)($pkg['name'] ?? '-'),
        'qty' => 0,
    ];
}

$packageSalesWhereParts = ['1=1'];
$packageSalesParams = [];
if ($selectedOrderId > 0) { $packageSalesWhereParts[] = "o.id = ?"; $packageSalesParams[] = $selectedOrderId; }
if ($selectedName !== '') {
    $packageSalesWhereParts[] = "(u.full_name LIKE ? OR EXISTS (SELECT 1 FROM order_attendees oa_name WHERE oa_name.order_id = o.id AND oa_name.attendee_name LIKE ?))";
    $packageSalesParams[] = '%' . $selectedName . '%';
    $packageSalesParams[] = '%' . $selectedName . '%';
}
if ($selectedEmail !== '') { $packageSalesWhereParts[] = "u.email LIKE ?"; $packageSalesParams[] = '%' . $selectedEmail . '%'; }
if ($selectedDate !== '') { $packageSalesWhereParts[] = "DATE(o.created_at) = ?"; $packageSalesParams[] = $selectedDate; }
if ($selectedStatus === 'paid' || $selectedStatus === 'accepted' || $selectedStatus === 'rejected') { $packageSalesWhereParts[] = "o.status = ?"; $packageSalesParams[] = $selectedStatus; }
elseif ($selectedStatus === 'pending') { $packageSalesWhereParts[] = "o.status = 'pending'"; }
if ($selectedArrival === 'arrived') {
    $packageSalesWhereParts[] = "EXISTS (SELECT 1 FROM order_attendees oa_arr WHERE oa_arr.order_id = o.id AND oa_arr.checked_in_at IS NOT NULL)";
} elseif ($selectedArrival === 'not_arrived') {
    $packageSalesWhereParts[] = "EXISTS (SELECT 1 FROM order_attendees oa_arr WHERE oa_arr.order_id = o.id AND oa_arr.checked_in_at IS NULL)";
}
$packageSalesWhereSql = ' WHERE ' . implode(' AND ', $packageSalesWhereParts);

$packageSalesSql = "SELECT
    p.id AS package_id,
    COALESCE(SUM(CASE WHEN o.status = 'accepted' THEN oi.qty ELSE 0 END), 0) AS sold_qty
    FROM orders o
    JOIN users u ON u.id = o.user_id
    JOIN order_items oi ON oi.order_id = o.id
    JOIN packages p ON p.id = oi.package_id" . $packageSalesWhereSql . "
    GROUP BY p.id";
$packageSalesStmt = $db->prepare($packageSalesSql);
$packageSalesStmt->execute($packageSalesParams);
foreach ($packageSalesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $packageId = (int)($row['package_id'] ?? 0);
    if ($packageId <= 0 || !isset($packageSalesMap[$packageId])) {
        continue;
    }
    $packageSalesMap[$packageId]['qty'] = max(0, (int)($row['sold_qty'] ?? 0));
}
$packageSalesStats = array_values($packageSalesMap);

$courtAttendeeCountMap = [
    1 => 0,
    2 => 0,
    3 => 0,
    4 => 0,
    5 => 0,
    6 => 0,
];
try {
    $courtCountStmt = $db->prepare("SELECT oa.court_no, COUNT(*) AS total_attendees
        FROM order_attendees oa
        JOIN orders o ON o.id = oa.order_id
        WHERE oa.court_no BETWEEN 1 AND 6
          AND o.status IN ('paid', 'accepted')
        GROUP BY oa.court_no");
    $courtCountStmt->execute();
    foreach ($courtCountStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $courtNo = (int)($row['court_no'] ?? 0);
        if ($courtNo < 1 || $courtNo > 6) {
            continue;
        }
        $courtAttendeeCountMap[$courtNo] = max(0, (int)($row['total_attendees'] ?? 0));
    }
} catch (Throwable $e) {
    // Keep defaults (0) if query fails.
}

$countSql = "SELECT COUNT(*) AS total_records FROM orders o JOIN users u ON u.id = o.user_id" . $whereSql;
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$filteredOrderCount = (int)($countStmt->fetchColumn() ?: 0);

$perPage = 10;
$totalPages = max(1, (int)ceil($filteredOrderCount / $perPage));
$currentPage = min($selectedPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;

$sql = "SELECT o.id, u.full_name, u.phone, u.email, u.instagram, o.total, o.status, o.payment_proof, o.created_at, (SELECT GROUP_CONCAT(CONCAT(p.name, ' x', oi.qty) SEPARATOR ', ') FROM order_items oi JOIN packages p ON p.id = oi.package_id WHERE oi.order_id = o.id) as items FROM orders o JOIN users u ON u.id = o.user_id" . $whereSql . " ORDER BY
    CASE
      WHEN LOWER(TRIM(o.status)) = 'accepted' THEN 1
      WHEN LOWER(TRIM(o.status)) = 'rejected' THEN 2
      ELSE 0
    END ASC,
    o.created_at DESC,
    o.id DESC
    LIMIT ? OFFSET ?";

$stmt = $db->prepare($sql);
foreach ($params as $index => $value) { $stmt->bindValue($index + 1, $value); }
$stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$orderItemDetailsMap = [];
$orderTicketCountMap = [];
$orderAttendeeMap = [];
$orderMissingCourtCountMap = [];
$orderAttendeePackageSummaryMap = [];
$orderAttendeeCountMap = [];
$orderArrivedCountMap = [];
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
        $attendeeSql = "SELECT oa.id AS attendee_id, oa.order_id, oa.attendee_name, oa.position_no, oa.checked_in_at, oa.package_id, oa.court_no, p.name AS package_name, p.price AS package_price
            FROM order_attendees oa
            LEFT JOIN packages p ON p.id = oa.package_id
            WHERE oa.order_id IN ($inPlaceholders)
            ORDER BY oa.order_id ASC, oa.position_no ASC, oa.id ASC";
        $attendeeStmt = $db->prepare($attendeeSql);
        foreach ($orderIds as $index => $orderId) { $attendeeStmt->bindValue($index + 1, $orderId, PDO::PARAM_INT); }
        $attendeeStmt->execute();
        foreach ($attendeeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $oid = (int)($row['order_id'] ?? 0);
            if ($oid <= 0) continue;
            if (!isset($orderAttendeeMap[$oid])) $orderAttendeeMap[$oid] = [];
            if (!isset($orderMissingCourtCountMap[$oid])) $orderMissingCourtCountMap[$oid] = 0;
            if (!isset($orderArrivedCountMap[$oid])) $orderArrivedCountMap[$oid] = 0;
            $courtNo = (int)($row['court_no'] ?? 0);
            $packageId = (int)($row['package_id'] ?? 0);
            $packageName = trim((string)($row['package_name'] ?? ''));
            if ($courtNo < 1 || $courtNo > 6) {
                if (strcasecmp($packageName, 'Package C') !== 0) {
                    $orderMissingCourtCountMap[$oid]++;
                }
            }
            $includeAttendeeInDetail = true;
            if ($selectedCourt > 0 && $courtNo !== $selectedCourt) {
                $includeAttendeeInDetail = false;
            }
            if ($selectedPackage > 0 && $packageId !== $selectedPackage) {
                $includeAttendeeInDetail = false;
            }
            $checkedInAt = trim((string)($row['checked_in_at'] ?? ''));
            if ($checkedInAt !== '') {
                $orderArrivedCountMap[$oid]++;
            }
            if ($selectedArrival === 'arrived' && $checkedInAt === '') {
                $includeAttendeeInDetail = false;
            } elseif ($selectedArrival === 'not_arrived' && $checkedInAt !== '') {
                $includeAttendeeInDetail = false;
            }
            if ($includeAttendeeInDetail) {
                $orderAttendeeMap[$oid][] = [
                    'attendee_id' => (int)($row['attendee_id'] ?? 0),
                    'position_no' => (int)($row['position_no'] ?? 0),
                    'attendee_name' => trim((string)($row['attendee_name'] ?? '')),
                    'checked_in_at' => $checkedInAt,
                    'package_id' => $packageId,
                    'package_name' => trim((string)($row['package_name'] ?? '')),
                    'court_no' => $courtNo,
                ];
            }
            $orderAttendeeCountMap[$oid] = ($orderAttendeeCountMap[$oid] ?? 0) + 1;

            if ($packageId > 0) {
                if (!isset($orderAttendeePackageSummaryMap[$oid])) {
                    $orderAttendeePackageSummaryMap[$oid] = [];
                }
                if (!isset($orderAttendeePackageSummaryMap[$oid][$packageId])) {
                    $orderAttendeePackageSummaryMap[$oid][$packageId] = [
                        'package_name' => trim((string)($row['package_name'] ?? ('Package ' . $packageId))),
                        'qty' => 0,
                        'price' => max(0, (int)($row['package_price'] ?? 0)),
                    ];
                }
                $orderAttendeePackageSummaryMap[$oid][$packageId]['qty']++;
            }
        }
    } catch (Throwable $e) { $orderAttendeeMap = []; }

    foreach ($orderAttendeeCountMap as $oid => $attendeeCount) {
        if ($attendeeCount <= 0) {
            continue;
        }
        // Attendee rows are source-of-truth after package reassignment.
        $orderTicketCountMap[$oid] = (int)$attendeeCount;
        if (!isset($orderAttendeePackageSummaryMap[$oid])) {
            continue;
        }
        $normalizedItems = [];
        foreach ($orderAttendeePackageSummaryMap[$oid] as $summary) {
            $qty = max(0, (int)($summary['qty'] ?? 0));
            $price = max(0, (int)($summary['price'] ?? 0));
            $normalizedItems[] = [
                'package_name' => (string)($summary['package_name'] ?? ''),
                'qty' => $qty,
                'price' => $price,
                'subtotal' => $qty * $price,
            ];
        }
        usort($normalizedItems, static function (array $a, array $b): int {
            return strcmp((string)($a['package_name'] ?? ''), (string)($b['package_name'] ?? ''));
        });
        if ($normalizedItems) {
            $orderItemDetailsMap[$oid] = $normalizedItems;
        }
    }

    $orderProofPathsMap = [];
    foreach ($orders as $row) {
        $orderId = (int)($row['id'] ?? 0);
        if ($orderId <= 0) continue;
        $orderProofPathsMap[$orderId] = get_order_payment_proof_paths($row);
    }
}

$hasActiveFilters = $selectedPackage > 0 || $selectedCourt > 0 || $selectedOrderId > 0 || $selectedName !== '' || $selectedEmail !== '' || $selectedDate !== '' || $selectedStatus !== '' || $selectedArrival !== '';
$startRow = $filteredOrderCount > 0 ? ($offset + 1) : 0;
$endRow = min($offset + count($orders), $filteredOrderCount);
$cardFilterBaseParams = [];
if ($selectedOrderId > 0) $cardFilterBaseParams['filter_order_id'] = $selectedOrderId;
if ($selectedName !== '') $cardFilterBaseParams['name'] = $selectedName;
if ($selectedEmail !== '') $cardFilterBaseParams['email'] = $selectedEmail;
if ($selectedDate !== '') $cardFilterBaseParams['created_date'] = $selectedDate;
if ($selectedStatus !== '') $cardFilterBaseParams['status'] = $selectedStatus;
if ($selectedArrival !== '') $cardFilterBaseParams['arrival'] = $selectedArrival;
$paginationBaseParams = [];
if ($selectedOrderId > 0) $paginationBaseParams['filter_order_id'] = $selectedOrderId;
if ($selectedPackage > 0) $paginationBaseParams['package'] = $selectedPackage;
if ($selectedCourt > 0) $paginationBaseParams['court'] = $selectedCourt;
if ($selectedName !== '') $paginationBaseParams['name'] = $selectedName;
if ($selectedEmail !== '') $paginationBaseParams['email'] = $selectedEmail;
if ($selectedDate !== '') $paginationBaseParams['created_date'] = $selectedDate;
if ($selectedStatus !== '') $paginationBaseParams['status'] = $selectedStatus;
if ($selectedArrival !== '') $paginationBaseParams['arrival'] = $selectedArrival;
$exportQueryParams = ['export' => 'excel'];
if ($selectedOrderId > 0) $exportQueryParams['filter_order_id'] = $selectedOrderId;
if ($selectedPackage > 0) $exportQueryParams['package'] = $selectedPackage;
if ($selectedCourt > 0) $exportQueryParams['court'] = $selectedCourt;
if ($selectedName !== '') $exportQueryParams['name'] = $selectedName;
if ($selectedEmail !== '') $exportQueryParams['email'] = $selectedEmail;
if ($selectedDate !== '') $exportQueryParams['created_date'] = $selectedDate;
if ($selectedArrival !== '') $exportQueryParams['arrival'] = $selectedArrival;
$exportOrderExcelUrl = '/admin/dashboard?' . http_build_query($exportQueryParams + ['export_type' => 'order']);
$exportAttendeeExcelUrl = '/admin/dashboard?' . http_build_query($exportQueryParams + ['export_type' => 'attendee']);

$extraHead = <<<'HTML'
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
  /* --- Base ------------------------------------------------ */
  .admin-shell, .admin-shell *:not(.bi) {
    font-family: 'Plus Jakarta Sans', var(--font, sans-serif);
  }

  .admin-container-wide {
    max-width: 1480px;
    padding-inline: clamp(12px, 3vw, 40px);
  }

  /* --- Page Header ------------------------------------------ */
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
  .dashboard-head-actions .btn {
    min-height: 44px;
    padding: 0 14px;
    border-radius: 12px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 7px;
  }
  .dashboard-head-actions .btn i { font-size: 14px; }

  .admin-topbar .brand {
    min-width: 0;
  }
  .admin-topbar .brand > div {
    min-width: 0;
  }
  .admin-topbar .brand small {
    display: block;
    max-width: 34ch;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* --- Stat Grid -------------------------------------------- */
  .stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
  }
  @media (min-width: 1201px) {
    .stat-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .stat-card--revenue-top { grid-column: span 2; }
  }
  .court-summary {
    margin-bottom: 16px;
  }
  .court-summary-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
    color: var(--muted);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.45px;
    text-transform: uppercase;
  }
  .court-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 10px;
  }
  .court-card {
    background: var(--surface);
    border: 1px solid var(--stroke);
    border-radius: 12px;
    padding: 10px 11px;
    box-shadow: 0 1px 8px rgba(0,0,0,0.03);
    display: grid;
    gap: 3px;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
  }
  .court-card-link {
    display: grid;
    color: inherit;
    text-decoration: none;
    cursor: pointer;
  }
  .court-card-link:hover {
    border-color: rgba(0, 102, 255, 0.45);
    box-shadow: 0 0 0 2px rgba(0, 102, 255, 0.12);
  }
  .court-card-link:focus-visible {
    outline: 2px solid rgba(0, 102, 255, 0.45);
    outline-offset: 1px;
  }
  .court-card.is-active {
    border-color: rgba(0, 102, 255, 0.45);
    box-shadow: 0 0 0 2px rgba(0, 102, 255, 0.12);
  }
  .court-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--muted);
    letter-spacing: 0.45px;
    text-transform: uppercase;
  }
  .court-value {
    font-size: 22px;
    font-weight: 800;
    color: var(--text);
    letter-spacing: -0.35px;
    line-height: 1.05;
  }
  .court-sub {
    font-size: 11px;
    color: var(--muted);
    font-weight: 600;
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
  .stat-card-link {
    display: block;
    color: inherit;
    text-decoration: none;
    cursor: pointer;
  }
  .stat-card-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
  }
  .stat-card-link:focus-visible {
    outline: 2px solid rgba(0, 102, 255, 0.45);
    outline-offset: 1px;
  }
  .stat-card.is-active {
    border-color: rgba(0, 102, 255, 0.45);
    box-shadow: 0 0 0 2px rgba(0, 102, 255, 0.12);
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

  /* --- Filter Card ------------------------------------------ */
  .dashboard-split-layout {
    display: grid;
    grid-template-columns: minmax(220px, 280px) minmax(0, 1fr);
    gap: 14px;
    align-items: start;
    margin-top: 6px;
  }
  .dashboard-filter-column,
  .dashboard-data-column {
    min-width: 0;
  }
  .dashboard-filter-column {
    position: sticky;
    top: 86px;
    align-self: start;
  }
  .dashboard-filter-column .filter-card {
    margin-bottom: 0;
  }
  .dashboard-data-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
    padding: 0 2px;
    color: var(--muted);
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.6px;
    text-transform: uppercase;
  }
  .export-dropdown {
    position: relative;
    display: inline-flex;
  }
  .export-dropdown-menu {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    min-width: 200px;
    border-radius: 12px;
    border: 1px solid var(--stroke);
    background: #fff;
    box-shadow: 0 10px 26px rgba(12, 27, 54, 0.16);
    padding: 6px;
    display: none;
    z-index: 40;
  }
  .export-dropdown.open .export-dropdown-menu {
    display: grid;
    gap: 4px;
  }
  .export-dropdown-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border-radius: 9px;
    padding: 8px 10px;
    color: #1f3559;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0;
    text-transform: none;
    transition: background 0.18s ease, color 0.18s ease;
  }
  .export-dropdown-item:hover {
    background: #edf4ff;
    color: #0f417f;
  }
  .export-trigger {
    text-transform: none;
    letter-spacing: 0;
  }
  .export-trigger .bi-chevron-down {
    font-size: 11px;
    transition: transform 0.2s ease;
  }
  .export-dropdown.open .export-trigger .bi-chevron-down {
    transform: rotate(180deg);
  }
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
    overflow: visible;
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

  .filter-field { display: grid; gap: 6px; min-width: 0; }
  .filter-field-status,
  .filter-field-package {
    padding: 0;
    box-sizing: border-box;
  }

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

  /* --- Admin Email Modal ------------------------------------ */
  #adminEmailModal .sponsor-modal-card {
    width: min(680px, 100%);
  }
  #adminEmailModal .admin-email-forms {
    display: grid;
    gap: 12px;
    padding: 16px 18px 18px;
    max-height: calc(88vh - 74px);
    overflow-y: auto;
  }
  #adminEmailModal .admin-email-form {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
    align-items: end;
  }
  #adminEmailModal .admin-email-form .sponsor-field {
    margin: 0;
  }
  #adminEmailModal .admin-email-form .btn {
    height: 46px;
    min-width: 168px;
    justify-content: center;
    border-radius: 12px;
  }
  #adminEmailModal .sponsor-field input[type="email"],
  #adminEmailModal .sponsor-field input[type="text"] {
    min-height: 46px;
    border-radius: 12px;
    padding: 11px 13px;
  }
  #adminEmailModal .admin-email-divider {
    height: 1px;
    border: 0;
    margin: 2px 0;
    background: var(--stroke);
  }
  #adminEmailModal .admin-email-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 7px;
    max-height: 220px;
    overflow-y: auto;
    padding-right: 2px;
  }
  #adminEmailModal .admin-email-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    border: 1px solid var(--stroke);
    border-radius: 12px;
    padding: 10px 12px;
    background: var(--surface-2, #f8faff);
    font-size: 12.5px;
  }
  #adminEmailModal .admin-email-item form { margin: 0; }
  #adminEmailModal .admin-email-remove-btn {
    height: 34px;
    min-width: 34px;
    padding: 0 10px;
    border-radius: 10px;
    border: 1px solid rgba(193, 70, 70, 0.28);
    background: rgba(211, 47, 47, 0.08);
    color: #b33434;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease;
  }
  #adminEmailModal .admin-email-remove-btn:hover {
    transform: translateY(-1px);
    background: rgba(211, 47, 47, 0.13);
    border-color: rgba(193, 70, 70, 0.4);
  }
  #adminEmailModal .admin-email-item-main {
    display: grid;
    gap: 4px;
    min-width: 0;
  }
  #adminEmailModal .admin-email-item-main strong {
    font-size: 13px;
    overflow-wrap: anywhere;
    color: var(--text);
  }
  #adminEmailModal .admin-email-item-main span {
    color: var(--muted);
    font-size: 11.5px;
  }
  #adminEmailModal .admin-email-otp-note {
    margin: 0;
    font-size: 12px;
    color: var(--muted);
    font-weight: 600;
    line-height: 1.45;
    padding: 8px 10px;
    border-radius: 10px;
    background: #f7faff;
    border: 1px solid var(--stroke);
  }
  #adminEmailModal .admin-email-empty {
    border: 1px dashed var(--stroke);
    border-radius: 12px;
    padding: 14px 12px;
    color: var(--muted);
    font-size: 12.5px;
    font-weight: 600;
    text-align: center;
  }
  #adminEmailModal .sponsor-form-actions {
    margin-top: 2px;
    padding-top: 10px;
  }
  #adminEmailModal .sponsor-form-actions .btn {
    min-width: 130px;
  }

  /* --- Sponsor Modal (Manage List) ------------------------- */
  #sponsorModal .sponsor-modal-card {
    width: min(760px, 100%);
  }
  #sponsorModal .sponsor-manage-wrap {
    display: grid;
    gap: 12px;
    padding: 16px 18px 18px;
    max-height: calc(88vh - 74px);
    overflow-y: auto;
  }
  #sponsorModal .sponsor-form {
    padding: 0;
    overflow: visible;
    gap: 12px;
  }
  #sponsorModal .sponsor-manage-note {
    margin: 0;
    font-size: 12px;
    color: var(--muted);
    font-weight: 600;
    line-height: 1.45;
    padding: 8px 10px;
    border-radius: 10px;
    background: #f7faff;
    border: 1px solid var(--stroke);
  }
  #sponsorModal .sponsor-manage-divider {
    height: 1px;
    border: 0;
    margin: 2px 0;
    background: var(--stroke);
  }
  #sponsorModal .sponsor-manage-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 8px;
    max-height: 260px;
    overflow-y: auto;
    padding-right: 2px;
  }
  #sponsorModal .sponsor-manage-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border: 1px solid var(--stroke);
    border-radius: 12px;
    padding: 10px 12px;
    background: var(--surface-2, #f8faff);
  }
  #sponsorModal .sponsor-manage-item-main {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  #sponsorModal .sponsor-manage-logo {
    width: 64px;
    height: 42px;
    border-radius: 8px;
    border: 1px solid var(--stroke);
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex: 0 0 auto;
  }
  #sponsorModal .sponsor-manage-logo img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    display: block;
  }
  #sponsorModal .sponsor-manage-meta {
    display: grid;
    gap: 4px;
    min-width: 0;
  }
  #sponsorModal .sponsor-manage-meta strong {
    font-size: 13px;
    color: var(--text);
    overflow-wrap: anywhere;
  }
  #sponsorModal .sponsor-manage-meta span {
    color: var(--muted);
    font-size: 11.5px;
    overflow-wrap: anywhere;
  }
  #sponsorModal .sponsor-manage-item form { margin: 0; }
  #sponsorModal .sponsor-remove-btn {
    height: 34px;
    min-width: 34px;
    padding: 0 10px;
    border-radius: 10px;
    border: 1px solid rgba(193, 70, 70, 0.28);
    background: rgba(211, 47, 47, 0.08);
    color: #b33434;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease;
  }
  #sponsorModal .sponsor-remove-btn:hover {
    transform: translateY(-1px);
    background: rgba(211, 47, 47, 0.13);
    border-color: rgba(193, 70, 70, 0.4);
  }
  #sponsorModal .sponsor-empty {
    border: 1px dashed var(--stroke);
    border-radius: 12px;
    padding: 14px 12px;
    color: var(--muted);
    font-size: 12.5px;
    font-weight: 600;
    text-align: center;
  }

  /* --- Ads Modal (Manage List) ----------------------------- */
  #adModal .sponsor-modal-card {
    width: min(760px, 100%);
  }
  #adModal .ad-manage-wrap {
    display: grid;
    gap: 12px;
    padding: 16px 18px 18px;
    max-height: calc(88vh - 74px);
    overflow-y: auto;
  }
  #adModal .sponsor-form {
    padding: 0;
    overflow: visible;
    gap: 12px;
  }
  #adModal .ad-manage-note {
    margin: 0;
    font-size: 12px;
    color: var(--muted);
    font-weight: 600;
    line-height: 1.45;
    padding: 8px 10px;
    border-radius: 10px;
    background: #f7faff;
    border: 1px solid var(--stroke);
  }
  #adModal .ad-manage-section-title {
    margin: 0;
    font-size: 12px;
    font-weight: 700;
    color: var(--text);
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  #adModal .ad-source-options {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
  }
  #adModal .ad-source-option {
    border: 1px solid var(--stroke);
    border-radius: 10px;
    padding: 10px 11px;
    background: #fff;
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text);
  }
  #adModal .ad-source-option input[type="radio"] {
    margin: 0;
  }
  #adModal .ad-source-option:has(input[type="radio"]:checked) {
    border-color: #9fc2ff;
    background: #eef4ff;
  }
  #adModal .ad-field-group.is-disabled {
    display: none;
  }
  #adModal .ad-manage-divider {
    height: 1px;
    border: 0;
    margin: 2px 0;
    background: var(--stroke);
  }
  #adModal .ad-manage-list {
    margin: 0;
    padding: 8px;
    list-style: none;
    display: block;
    border: 1px solid var(--stroke);
    border-radius: 12px;
    background: #fff;
  }
  #adModal .ad-manage-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border: 1px solid var(--stroke);
    border-radius: 12px;
    padding: 10px 12px;
    background: var(--surface-2, #f8faff);
    margin-bottom: 8px;
  }
  #adModal .ad-manage-item:last-child { margin-bottom: 0; }
  #adModal .ad-manage-item-main {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  #adModal .ad-manage-video {
    width: 84px;
    height: 48px;
    border-radius: 8px;
    border: 1px solid var(--stroke);
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex: 0 0 auto;
  }
  #adModal .ad-manage-video video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  #adModal .ad-manage-link-preview {
    width: 100%;
    height: 100%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 4px 6px;
    font-size: 11px;
    font-weight: 700;
    color: #0d3f98;
    text-decoration: none;
    background: #eef4ff;
  }
  #adModal .ad-manage-link-preview:hover {
    background: #dfeaff;
  }
  #adModal .ad-preview-box {
    border: 1px solid var(--stroke);
    border-radius: 12px;
    padding: 10px;
    background: #f9fbff;
    display: grid;
    gap: 8px;
  }
  #adModal .ad-preview-head {
    font-size: 12px;
    font-weight: 700;
    color: var(--text);
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  #adModal .ad-preview-media {
    width: 100%;
    border: 1px dashed var(--stroke);
    border-radius: 10px;
    background: #fff;
    min-height: 220px;
    height: clamp(220px, 32vh, 320px);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
  }
  #adModal .ad-preview-media iframe,
  #adModal .ad-preview-media video {
    width: 100%;
    height: 100% !important;
    min-height: 0;
    border: 0;
    display: block;
    background: #000;
    object-fit: cover;
  }
  #adModal .ad-preview-empty {
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
    text-align: center;
    padding: 16px 14px;
    line-height: 1.45;
  }
  #adModal .ad-preview-note {
    margin: 0;
    font-size: 11.5px;
    color: var(--muted);
    font-weight: 600;
  }
  #adModal .ad-preview-note.is-error {
    color: #b33434;
  }
  #adModal .ad-manage-meta {
    display: grid;
    gap: 4px;
    min-width: 0;
  }
  #adModal .ad-manage-meta strong {
    font-size: 13px;
    color: var(--text);
    overflow-wrap: anywhere;
  }
  #adModal .ad-manage-meta span {
    color: var(--muted);
    font-size: 11.5px;
    overflow-wrap: anywhere;
  }
  #adModal .ad-manage-item form { margin: 0; }
  #adModal .ad-remove-btn {
    height: 34px;
    min-width: 34px;
    padding: 0 10px;
    border-radius: 10px;
    border: 1px solid rgba(193, 70, 70, 0.28);
    background: rgba(211, 47, 47, 0.08);
    color: #b33434;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease;
  }
  #adModal .ad-remove-btn:hover {
    transform: translateY(-1px);
    background: rgba(211, 47, 47, 0.13);
    border-color: rgba(193, 70, 70, 0.4);
  }
  #adModal .ad-empty {
    border: 1px dashed var(--stroke);
    border-radius: 12px;
    padding: 14px 12px;
    color: var(--muted);
    font-size: 12.5px;
    font-weight: 600;
    text-align: center;
  }

  /* --- Flash Messages --------------------------------------- */
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

  /* --- Pagination ------------------------------------------- */
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

  /* --- Desktop Table ---------------------------------------- */
  .table-wrap {
    border-radius: 16px;
    border: 1px solid var(--stroke);
    overflow-x: auto;
    overflow-y: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,0.04);
    scrollbar-width: thin;
    -ms-overflow-style: auto;
    cursor: grab;
  }
  .table-wrap.is-dragging {
    cursor: grabbing;
    user-select: none;
  }
  .table-wrap::-webkit-scrollbar {
    display: block !important;
    height: 9px;
  }
  .table-wrap::-webkit-scrollbar-track {
    background: rgba(148, 163, 184, 0.2);
    border-radius: 999px;
  }
  .table-wrap::-webkit-scrollbar-thumb {
    background: rgba(100, 116, 139, 0.55);
    border-radius: 999px;
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
    position: relative;
  }

  table.admin-table tbody tr { transition: background 0.15s ease; }
  table.admin-table tbody tr:hover { background: rgba(0,102,255,0.025); }
  table.admin-table tbody tr:last-child td { border-bottom: none; }

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

  /* --- Badges ----------------------------------------------- */
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

  /* --- Proof Button ----------------------------------------- */
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

  /* --- Action Group ----------------------------------------- */
  table.admin-table td:nth-child(8) {
    z-index: 40;
    overflow: visible;
  }

  .action-group {
    display: flex;
    align-items: center;
    gap: 5px;
    flex-wrap: wrap;
    position: relative;
    z-index: 2200;
  }

  .action-group .btn.small {
    padding: 5px 9px;
    font-size: 11.5px;
    border-radius: 8px;
    height: 31px;
    font-weight: 700;
    white-space: nowrap;
    min-width: 0;
    transition: all 0.17s ease;
  }
  .action-group .btn.primary:not(:disabled):hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,102,255,0.3); }
  .action-group .btn.ghost:not(:disabled):hover { transform: translateY(-1px); }
  .action-group .btn:disabled { opacity: 0.38; cursor: not-allowed; }
  .btn.detail-warning {
    background: rgba(245, 158, 11, 0.14);
    border-color: rgba(245, 158, 11, 0.55);
    color: #9a6700;
    position: relative;
    overflow: visible;
  }
  .btn.detail-warning:not(:disabled):hover {
    background: rgba(245, 158, 11, 0.2);
    border-color: rgba(245, 158, 11, 0.72);
    color: #7a5200;
  }
  .btn.detail-warning[data-tooltip]:not([data-tooltip=""])::after,
  .btn.detail-warning[data-tooltip]:not([data-tooltip=""])::before {
    position: absolute;
    left: 50%;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s ease;
    z-index: 2205;
  }
  .btn.detail-warning[data-tooltip]:not([data-tooltip=""])::after {
    content: attr(data-tooltip);
    bottom: calc(100% + 10px);
    transform: translate(-50%, 6px);
    min-width: 220px;
    max-width: min(360px, 72vw);
    padding: 9px 11px;
    border-radius: 10px;
    border: 1px solid rgba(245, 158, 11, 0.55);
    background: linear-gradient(180deg, #252016 0%, #1f1a12 100%);
    color: #ffe8b6;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.4;
    letter-spacing: 0.01em;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.28);
    white-space: normal;
    text-align: left;
  }
  .btn.detail-warning[data-tooltip]:not([data-tooltip=""])::before {
    content: '';
    bottom: calc(100% + 4px);
    transform: translate(-50%, 6px);
    width: 9px;
    height: 9px;
    background: #1f1a12;
    border-right: 1px solid rgba(245, 158, 11, 0.55);
    border-bottom: 1px solid rgba(245, 158, 11, 0.55);
    rotate: 45deg;
  }
  .btn.detail-warning[data-tooltip]:not([data-tooltip=""]):hover::after,
  .btn.detail-warning[data-tooltip]:not([data-tooltip=""]):hover::before,
  .btn.detail-warning[data-tooltip]:not([data-tooltip=""]):focus-visible::after,
  .btn.detail-warning[data-tooltip]:not([data-tooltip=""]):focus-visible::before {
    opacity: 1;
    visibility: visible;
    transform: translate(-50%, 0);
  }

  /* ----------------------------------------------------------
     MOBILE CARD LAYOUT — replaces table on small screens
  ---------------------------------------------------------- */
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

  /* --- Sponsor Modal ---------------------------------------- */
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
  .warn-modal-body {
    padding: 18px;
    display: grid;
    gap: 14px;
  }
  .warn-modal-message {
    margin: 0;
    font-size: 14px;
    line-height: 1.55;
    color: var(--text);
    font-weight: 600;
  }
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

  .dashboard-loading-modal {
    position: fixed;
    inset: 0;
    background: rgba(5, 12, 24, 0.72);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1800;
    padding: 18px;
  }
  .dashboard-loading-modal.show { display: flex; }
  .dashboard-loading-card {
    width: min(420px, 100%);
    background: rgba(7, 22, 45, 0.96);
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 14px;
    padding: 20px;
    display: grid;
    justify-items: center;
    gap: 10px;
    text-align: center;
  }
  .dashboard-loading-spinner {
    width: 52px;
    height: 52px;
    border-radius: 999px;
    border: 4px solid rgba(255, 255, 255, 0.25);
    border-top-color: #fff;
    animation: dashboardLoadingSpin 0.9s linear infinite;
  }
  .dashboard-loading-title {
    font-size: 26px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #f8fbff;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.45);
  }
  .dashboard-loading-subtext {
    font-size: 13px;
    opacity: 0.9;
    color: #dbe8ff;
    text-shadow: 0 1px 8px rgba(0, 0, 0, 0.4);
  }
  @keyframes dashboardLoadingSpin {
    to { transform: rotate(360deg); }
  }

  /* --- Order Detail Modal ----------------------------------- */
  #orderDetailModal .proof-card { max-width: min(96vw, 1000px); border-radius: 20px; }
  #orderDetailModal .proof-head { padding: 14px 18px; background: var(--surface-2, #f8faff); }
  #orderDetailModal .proof-title { font-size: 18px; font-weight: 800; letter-spacing: -0.4px; }

  .order-detail-body { padding: 16px 18px 20px; max-height: calc(90vh - 70px); overflow-y: auto; }

  .detail-head { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid var(--stroke); }
  .screen-notice {
    position: fixed;
    top: 18px;
    right: 18px;
    z-index: 2200;
    min-width: 260px;
    max-width: min(460px, calc(100vw - 24px));
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid transparent;
    box-shadow: 0 12px 30px rgba(9, 20, 39, 0.24);
    font-size: 13px;
    font-weight: 700;
    line-height: 1.45;
    opacity: 0;
    transform: translateY(-8px);
    pointer-events: none;
    transition: opacity 0.2s ease, transform 0.2s ease;
  }
  .screen-notice.show {
    opacity: 1;
    transform: translateY(0);
  }
  .screen-notice.is-success {
    background: #ecf9f0;
    border-color: #b7e8c6;
    color: #1d7a3c;
  }
  .screen-notice.is-error {
    background: #fff1f1;
    border-color: #f2c5c5;
    color: #b33434;
  }
  .detail-chip {
    display: inline-flex; align-items: center; gap: 5px; padding: 6px 11px;
    border-radius: 999px; border: 1.5px solid var(--stroke); background: var(--surface);
    font-size: 12px; line-height: 1.2;
  }
  .detail-chip .chip-label { color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; font-size: 10.5px; }
  .detail-chip .chip-value { color: var(--text); font-weight: 700; }

  .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
  .detail-box { border: 1px solid var(--stroke); border-radius: 12px; background: var(--surface); padding: 14px; }
  .detail-title { font-size: 13px; font-weight: 800; margin-bottom: 10px; color: var(--text); display: inline-flex; align-items: center; gap: 6px; }
  .detail-title .bi { color: var(--primary); font-size: 14px; }
  .detail-list { margin: 0; padding: 0; list-style: none; display: grid; gap: 6px; }
  .detail-list li { border: 1px solid var(--stroke); border-radius: 9px; background: var(--surface-2, #f8faff); padding: 9px 12px; font-size: 12.5px; line-height: 1.5; font-weight: 500; color: var(--text); }
  .detail-attendee-main { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
  .detail-attendee-court-actions { display: inline-flex; align-items: center; gap: 6px; margin-left: 8px; }
  .detail-court-select {
    height: 28px;
    border: 1px solid var(--stroke);
    border-radius: 8px;
    background: #fff;
    color: var(--text);
    font-size: 12px;
    padding: 0 8px;
    font-weight: 700;
  }
  .detail-court-btn {
    border: 1px solid #4f8cff;
    color: #1e5fc9;
    background: #edf3ff;
    height: 28px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    padding: 0 10px;
    cursor: pointer;
  }
  .detail-court-btn:disabled { opacity: 0.65; cursor: not-allowed; }
  .detail-package-wrap {
    margin-top: 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  .detail-package-select {
    min-width: 160px;
    border: 1px solid var(--stroke);
    border-radius: 8px;
    background: #fff;
    color: #1d2b45;
    font-size: 12px;
    font-weight: 600;
    padding: 6px 8px;
  }
  .detail-package-submit {
    border: 1px solid var(--stroke);
    border-radius: 8px;
    background: #fff;
    color: var(--primary);
    font-size: 11.5px;
    font-weight: 700;
    padding: 5px 9px;
    cursor: pointer;
  }
  .detail-package-submit:hover {
    border-color: var(--primary);
    background: rgba(0, 102, 255, 0.08);
  }
  .detail-empty { color: var(--muted); font-size: 13px; padding: 4px 2px; font-weight: 500; }
  .proof-gallery-card { max-width: min(96vw, 640px); }
  .proof-gallery-body {
    padding: 14px 18px 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
  }
  .proof-gallery-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--surface);
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid var(--stroke);
    gap: 10px;
  }
  .proof-gallery-item span {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
  }
  .proof-gallery-item button {
    min-width: 110px;
  }

  /* --- Animations ------------------------------------------- */
  @keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }
  @keyframes modalCardIn { from { opacity: 0; transform: translateY(12px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }

  /* ----------------------------------------------------------
     RESPONSIVE BREAKPOINTS
  ---------------------------------------------------------- */

  /* Large desktop: 4-col stats */
  @media (max-width: 1200px) {
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
    .court-grid { grid-template-columns: repeat(3, 1fr); }
    .dashboard-split-layout {
      grid-template-columns: minmax(200px, 248px) minmax(0, 1fr);
    }
  }

  /* Tablet: collapse table ? cards */
  @media (max-width: 900px) {
    .stat-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .court-grid { grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .dashboard-split-layout { grid-template-columns: 1fr; gap: 12px; }
    .dashboard-filter-column { position: static; top: auto; }
    .detail-grid { grid-template-columns: 1fr; }
    #orderDetailModal .proof-title { font-size: 16px; }
    .order-detail-body { padding: 12px 14px 16px; }

    /* SWAP table for cards */
    .table-wrap { display: none; }
    .order-cards { display: block; }

    /* Show filter toggle, hide desktop label */
    .filter-toggle-btn { display: flex; }
    .dashboard-filter-form .filter-label { display: none; }
    .filter-collapsible { overflow: hidden; }
  }

  /* Mobile */
  @media (max-width: 640px) {
    .admin-shell {
      padding-top: 18px;
      padding-bottom: 42px;
    }
    .admin-container-wide {
      padding-inline: 12px;
    }
    .admin-header.spaced {
      flex-direction: column;
      align-items: flex-start;
      gap: 12px;
      padding: 14px 0 12px;
      margin-bottom: 14px;
    }
    .admin-title {
      font-size: 24px;
      letter-spacing: -0.4px;
      margin-bottom: 2px;
    }
    .admin-sub {
      font-size: 13px;
      line-height: 1.45;
    }

    .admin-header-shell .admin-topbar {
      align-items: flex-start;
      gap: 10px;
    }
    .admin-header-shell .admin-topbar .brand {
      gap: 10px;
      font-size: 18px;
    }
    .admin-header-shell .admin-topbar .brand-badge {
      width: 44px;
      height: 44px;
    }
    .admin-header-shell .admin-topbar .brand small {
      max-width: 24ch;
      white-space: normal;
      overflow: visible;
      text-overflow: clip;
      line-height: 1.25;
      font-size: 12px;
    }
    .admin-header-shell .topbar-actions .btn {
      min-height: 42px;
      padding: 0 13px;
      border-radius: 12px;
    }

    .dashboard-head-actions {
      width: 100%;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 9px;
    }
    .dashboard-head-actions .btn {
      width: 100%;
      min-height: 50px;
      justify-content: center;
      text-align: center;
      white-space: normal;
      line-height: 1.2;
      padding: 8px 10px;
    }

    .stat-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
    .stat-card--revenue-top {
      grid-column: 1 / -1;
      min-height: 118px;
    }
    .stat-card {
      border-radius: 14px;
      padding: 14px;
      min-height: 102px;
    }
    .stat-label {
      font-size: 10px;
      letter-spacing: 0.55px;
      margin-bottom: 7px;
    }
    .stat-value { font-size: 24px; }
    .stat-value.small {
      font-size: 17px;
      line-height: 1.15;
    }
    .court-summary-head {
      font-size: 11px;
      margin-bottom: 7px;
    }
    .court-grid {
      grid-template-columns: 1fr 1fr;
      gap: 8px;
    }
    .court-card {
      border-radius: 11px;
      padding: 10px;
    }
    .court-value {
      font-size: 20px;
    }

    .filter-card {
      padding: 13px;
      border-radius: 14px;
      margin-bottom: 14px;
    }
    .dashboard-data-head {
      margin-bottom: 6px;
      font-size: 11px;
      letter-spacing: 0.45px;
    }
    .filter-toggle-btn {
      padding: 2px 1px;
    }
    .filter-toggle-left {
      font-size: 11px;
      letter-spacing: 0.5px;
    }
    .dashboard-filter-form { grid-template-columns: 1fr; gap: 10px; }
    .filter-field-status,
    .filter-field-package { padding: 0; }
    .field-label {
      font-size: 10.5px;
      letter-spacing: 0.45px;
    }
    .dashboard-filter-form input,
    .dashboard-filter-form select {
      min-height: 46px;
      font-size: 14px;
      border-radius: 11px;
    }
    .dashboard-filter-form .filter-actions { flex-direction: column; align-items: stretch; }
    .dashboard-filter-form .filter-actions .btn {
      width: 100%;
      min-height: 44px;
      justify-content: center;
    }

    .pagination-wrap { flex-direction: column; align-items: flex-start; gap: 8px; }
    .pagination { width: 100%; justify-content: center; }

    .sponsor-modal-card { border-radius: 16px; max-height: 92vh; }
    .sponsor-form-actions { flex-direction: column; }
    .sponsor-form-actions .btn { width: 100%; justify-content: center; }
    #adminEmailModal .admin-email-forms { padding: 14px; }
    #adminEmailModal .admin-email-form { grid-template-columns: 1fr; }
    #adminEmailModal .admin-email-form .btn { width: 100%; min-width: 0; justify-content: center; }
    #adminEmailModal .admin-email-item { align-items: flex-start; }
    #adminEmailModal .admin-email-remove-btn { width: 100%; }
    #adminEmailModal .admin-email-list { max-height: 190px; }
    #sponsorModal .sponsor-manage-wrap { padding: 14px; }
    #sponsorModal .sponsor-manage-item { align-items: flex-start; flex-direction: column; }
    #sponsorModal .sponsor-remove-btn { width: 100%; }
    #adModal .ad-manage-wrap { padding: 14px; }
    #adModal .ad-manage-item { align-items: flex-start; flex-direction: column; }
    #adModal .ad-remove-btn { width: 100%; }
    #adModal .ad-source-options { grid-template-columns: 1fr; }

    .order-card-actions .btn { font-size: 12px; }
  }

  @media (max-width: 460px) {
    .dashboard-head-actions {
      grid-template-columns: 1fr;
    }
    .dashboard-head-actions .btn {
      min-height: 46px;
    }
  }

  /* Extra small */
  @media (max-width: 400px) {
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

      <!-- -- Page Header --------------------------------------- -->
      <div class="admin-header spaced">
        <div>
          <h1 class="admin-title">Dashboard</h1>
          <p class="admin-sub">Ringkasan pesanan dan status pembayaran</p>
        </div>
        <div class="dashboard-head-actions">
          <a class="btn ghost" href="/admin/competition"><i class="bi bi-diagram-3"></i> Competition</a>
          <a class="btn ghost" href="/admin/scan"><i class="bi bi-qr-code-scan"></i> Scan QR</a>
          <button class="btn ghost" type="button" id="openAdminEmailModal">
            <i class="bi bi-envelope-check"></i> Email Admin
          </button>
          <button class="btn ghost" type="button" id="openPasswordModal">
            <i class="bi bi-key"></i> Ganti Password
          </button>
          <button class="btn primary" type="button" id="openSponsorModal">
            <i class="bi bi-building-add"></i> Tambah Sponsor
          </button>
          <button class="btn primary" type="button" id="openAdModal">
            <i class="bi bi-badge-ad"></i> Tambah Iklan
          </button>
        </div>
      </div>

      <!-- -- Stat Cards ---------------------------------------- -->
      <div class="stat-grid">
        <div class="stat-card stat-card--revenue-top">
          <div class="stat-label"><i class="bi bi-cash-stack"></i> Revenue Accepted</div>
          <div class="stat-value small" data-revenue-accepted-value="<?= (int)$totalRevenue ?>"><?= h(rupiah($totalRevenue)) ?></div>
        </div>
        <?php
          $allCardParams = $cardFilterBaseParams;
          $allCardHref = '/admin/dashboard' . ($allCardParams ? ('?' . http_build_query($allCardParams)) : '');
        ?>
        <a class="stat-card stat-card-link<?= ($selectedPackage <= 0 && $selectedCourt <= 0) ? ' is-active' : '' ?>" href="<?= h($allCardHref) ?>">
          <div class="stat-label"><i class="bi bi-basket"></i> Total Orders Accepted</div>
          <div class="stat-value"><?= (int)$totalOrders ?></div>
        </a>
        <?php
          $attendeeCardParams = $cardFilterBaseParams;
          $attendeeCardParams['status'] = 'accepted';
          $attendeeCardHref = '/admin/dashboard?' . http_build_query($attendeeCardParams);
          $isAttendeeCardActive = $selectedStatus === 'accepted';
        ?>
        <a class="stat-card stat-card-link<?= $isAttendeeCardActive ? ' is-active' : '' ?>" href="<?= h($attendeeCardHref) ?>">
          <div class="stat-label"><i class="bi bi-people"></i> Total Attendee Accepted</div>
          <div class="stat-value"><?= (int)$totalAttendees ?></div>
        </a>
        <?php foreach ($packageSalesStats as $packageStat): ?>
          <?php
            $packageCardParams = $cardFilterBaseParams;
            $packageCardParams['package'] = (int)($packageStat['id'] ?? 0);
            $packageCardHref = '/admin/dashboard?' . http_build_query($packageCardParams);
            $isPackageActive = $selectedPackage === (int)($packageStat['id'] ?? 0) && $selectedCourt <= 0;
          ?>
          <a class="stat-card stat-card-link<?= $isPackageActive ? ' is-active' : '' ?>" href="<?= h($packageCardHref) ?>" data-package-card-id="<?= (int)($packageStat['id'] ?? 0) ?>">
            <div class="stat-label"><i class="bi bi-box-seam"></i> <?= h($packageStat['name']) ?></div>
            <div class="stat-value" data-package-card-value="<?= (int)($packageStat['id'] ?? 0) ?>"><?= (int)$packageStat['qty'] ?></div>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="court-summary">
        <div class="court-summary-head">
          <span><i class="bi bi-grid-3x3-gap"></i> Court Attendee</span>
        </div>
        <div class="court-grid">
          <?php for ($courtNo = 1; $courtNo <= 6; $courtNo++): ?>
            <?php
              $courtCardParams = $cardFilterBaseParams;
              $courtCardParams['court'] = $courtNo;
              $courtCardHref = '/admin/dashboard?' . http_build_query($courtCardParams);
              $isCourtActive = $selectedCourt === $courtNo && $selectedPackage <= 0;
            ?>
            <a class="court-card court-card-link<?= $isCourtActive ? ' is-active' : '' ?>" href="<?= h($courtCardHref) ?>" data-court-card-no="<?= (int)$courtNo ?>">
              <div class="court-label">Court <?= (int)$courtNo ?></div>
              <div class="court-value" data-court-card-value="<?= (int)$courtNo ?>"><?= (int)($courtAttendeeCountMap[$courtNo] ?? 0) ?></div>
              <div class="court-sub">attendee</div>
            </a>
          <?php endfor; ?>
        </div>
      </div>

      <div class="dashboard-split-layout">
      <!-- -- Filter Card --------------------------------------- -->
      <aside class="dashboard-filter-column">
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
                $fc = (int)($selectedOrderId > 0) + (int)($selectedName !== '') + (int)($selectedEmail !== '') + (int)($selectedDate !== '') + (int)($selectedStatus !== '') + (int)($selectedArrival !== '') + (int)($selectedPackage > 0) + (int)($selectedCourt > 0);
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
            <input id="filterOrderId" type="text" name="filter_order_id" value="<?= $selectedOrderId > 0 ? (int)$selectedOrderId : '' ?>" placeholder="ID">
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
              <option value="paid" <?= $selectedStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
              <option value="accepted" <?= $selectedStatus === 'accepted' ? 'selected' : '' ?>>Accepted</option>
              <option value="rejected" <?= $selectedStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
          </div>
          <div class="filter-field filter-field-status">
            <label class="field-label" for="filterArrival">Kehadiran</label>
            <select id="filterArrival" name="arrival">
              <option value="">Semua Kehadiran</option>
              <option value="arrived" <?= $selectedArrival === 'arrived' ? 'selected' : '' ?>>Sudah Datang</option>
              <option value="not_arrived" <?= $selectedArrival === 'not_arrived' ? 'selected' : '' ?>>Belum Datang</option>
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
          <div class="filter-field filter-field-package">
            <label class="field-label" for="filterCourt">Court</label>
            <select id="filterCourt" name="court">
              <option value="0">Semua Court</option>
              <?php for ($courtNo = 1; $courtNo <= 6; $courtNo++): ?>
                <option value="<?= (int)$courtNo ?>" <?= $selectedCourt === $courtNo ? 'selected' : '' ?>>Court <?= (int)$courtNo ?></option>
              <?php endfor; ?>
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
      </aside>
      <section class="dashboard-data-column">
      <div class="dashboard-data-head">
        <span><i class="bi bi-card-list"></i> Data Registrasi</span>
        <div class="export-dropdown" data-export-dropdown>
          <button class="btn ghost small export-trigger" type="button" data-export-toggle aria-haspopup="true" aria-expanded="false">
            <i class="bi bi-file-earmark-excel"></i> Export Excel <i class="bi bi-chevron-down"></i>
          </button>
          <div class="export-dropdown-menu" data-export-menu>
            <a class="export-dropdown-item" href="<?= h($exportOrderExcelUrl) ?>">
              <i class="bi bi-list-check"></i> Export Per Order
            </a>
            <a class="export-dropdown-item" href="<?= h($exportAttendeeExcelUrl) ?>">
              <i class="bi bi-people"></i> Export Per Attendee
            </a>
          </div>
        </div>
      </div>
      <?php if ($flash['error']): ?>
        <div class="alert mb-16"><i class="bi bi-exclamation-triangle-fill"></i> <?= h($flash['error']) ?></div>
      <?php endif; ?>
      <?php if ($flash['success']): ?>
        <div class="alert-success"><i class="bi bi-check-circle-fill"></i> <?= h($flash['success']) ?></div>
      <?php endif; ?>

      <!-- -- Pagination Top ------------------------------------ -->
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

      <!-- -------------------------------------------------------
           DESKTOP: Standard Table (hidden on mobile via CSS)
      ------------------------------------------------------- -->
      <div class="table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th><i class="bi bi-fingerprint"></i> ID</th>
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
              $detailOrderId = (int)$o['id'];
              $proofPaths = $orderProofPathsMap[$detailOrderId] ?? [];
              $firstProof = $proofPaths[0] ?? '';
              $canAction = !empty($firstProof) && $o['status'] === 'paid';
              $missingCourtCount = (int)($orderMissingCourtCountMap[$detailOrderId] ?? 0);
              $arrivedCount = (int)($orderArrivedCountMap[$detailOrderId] ?? 0);
              $attendeeTotalCount = (int)($orderAttendeeCountMap[$detailOrderId] ?? 0);
              $notArrivedCount = max(0, $attendeeTotalCount - $arrivedCount);
              $detailPayload = [
                'order_id' => $detailOrderId,
                'user_name' => (string)($o['full_name'] ?? ''),
                'total' => (int)($o['total'] ?? 0),
                'status' => (string)($o['status'] ?? ''),
                'created_at' => (string)($o['created_at'] ?? ''),
                'ticket_count' => (int)($orderTicketCountMap[$detailOrderId] ?? 0),
                'items' => $orderItemDetailsMap[$detailOrderId] ?? [],
                'attendees' => $orderAttendeeMap[$detailOrderId] ?? [],
                'missing_court_count' => $missingCourtCount,
                'proofs' => $proofPaths,
              ];
            ?>
              <tr>
                <td><strong style="font-size:13.5px;letter-spacing:-0.3px;"><?= (int)$o['id'] ?></strong></td>
                <td><strong style="font-size:13px;"><?= h($o['full_name']) ?></strong></td>
                <td class="admin-contact">
                  <div class="admin-contact-line"><i class="bi bi-telephone"></i><span class="contact-value"><?= h($o['phone']) ?></span></div>
                  <div class="admin-contact-line"><i class="bi bi-envelope"></i><span class="contact-value"><?= h($o['email']) ?></span></div>
                  <div class="admin-contact-line"><i class="bi bi-instagram"></i>
                    <?php $ig = trim((string)($o['instagram'] ?? '')); $ig = $ig !== '' ? '@' . ltrim($ig, '@') : '-'; ?>
                    <span class="contact-value"><?= h($ig) ?></span>
                  </div>
                </td>
                <td style="font-size:12px;color:var(--muted);font-weight:500;">
                  <?= h($o['items'] ?? '-') ?>
                  <div style="margin-top:5px;font-size:11.5px;font-weight:700;color:#1f2937;">
                    <span style="color:#166534;">Hadir: <?= (int)$arrivedCount ?></span>
                    <span style="color:#6b7280;"> | </span>
                    <span style="color:#b91c1c;">Belum Datang: <?= (int)$notArrivedCount ?></span>
                  </div>
                </td>
                <td><strong style="font-size:13px;letter-spacing:-0.3px;"><?= h(rupiah((int)$o['total'])) ?></strong></td>
                <td>
                  <?php if ($o['status'] === 'paid'): ?><span class="badge paid"><i class="bi bi-check-circle"></i> Paid</span>
                  <?php elseif ($o['status'] === 'accepted'): ?><span class="badge accepted"><i class="bi bi-check-circle-fill"></i> Accepted</span>
                  <?php elseif ($o['status'] === 'rejected'): ?><span class="badge rejected"><i class="bi bi-x-circle"></i> Rejected</span>
                  <?php else: ?><span class="badge pending"><i class="bi bi-clock"></i> <?= h($o['status']) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($proofPaths): ?>
                    <button class="proof-link proof-gallery-trigger" type="button" data-proof-gallery="<?= h(json_encode($proofPaths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" data-order="<?= (int)$o['id'] ?>"><i class="bi bi-file-earmark-image"></i> View </button>
                  <?php else: ?><span style="color:var(--muted);font-size:12px;">—</span><?php endif; ?>
                </td>
                <td>
                  <div class="action-group">
                    <button class="btn ghost small<?= $missingCourtCount > 0 ? ' detail-warning' : '' ?>" type="button" data-order-detail="<?= h(json_encode($detailPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" data-tooltip="<?= $missingCourtCount > 0 ? h('Masih ada ' . $missingCourtCount . ' attendee belum pilih court. Klik Detail untuk lengkapi.') : '' ?>"><i class="bi bi-info-circle"></i> Detail</button>
                    <button class="btn primary small" type="button" data-confirm-action="accept" data-order-id="<?= (int)$o['id'] ?>" data-proof="<?= $firstProof ? '/uploads/' . h($firstProof) : '' ?>" data-court-missing="<?= (int)$missingCourtCount ?>" <?= $canAction ? '' : 'disabled' ?>><i class="bi bi-check-circle"></i> Accept</button>
                    <button class="btn ghost small" type="button" data-confirm-action="reject" data-order-id="<?= (int)$o['id'] ?>" data-proof="<?= $firstProof ? '/uploads/' . h($firstProof) : '' ?>" <?= $canAction ? '' : 'disabled' ?>><i class="bi bi-x-circle"></i> Reject</button>
                  </div>
                </td>
                <td style="font-size:11.5px;color:var(--muted);white-space:nowrap;font-weight:600;"><?= h(date('d M Y', strtotime($o['created_at']))) ?><br><span style="opacity:0.7;"><?= h(date('H:i', strtotime($o['created_at']))) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- -------------------------------------------------------
           MOBILE: Card Layout (shown on mobile via CSS)
      ------------------------------------------------------- -->
      <div class="order-cards">
        <?php if (!$orders): ?>
          <div style="text-align:center;padding:40px 20px;border:1px solid var(--stroke);border-radius:14px;background:var(--surface);">
            <div class="empty-state"><i class="bi bi-inbox"></i> Belum ada order</div>
          </div>
        <?php endif; ?>
        <?php foreach ($orders as $o):
          $detailOrderId = (int)$o['id'];
          $proofPaths = $orderProofPathsMap[$detailOrderId] ?? [];
          $firstProof = $proofPaths[0] ?? '';
          $canAction = !empty($firstProof) && $o['status'] === 'paid';
          $missingCourtCount = (int)($orderMissingCourtCountMap[$detailOrderId] ?? 0);
          $arrivedCount = (int)($orderArrivedCountMap[$detailOrderId] ?? 0);
          $attendeeTotalCount = (int)($orderAttendeeCountMap[$detailOrderId] ?? 0);
          $notArrivedCount = max(0, $attendeeTotalCount - $arrivedCount);
          $detailPayload = [
            'order_id' => $detailOrderId,
            'user_name' => (string)($o['full_name'] ?? ''),
            'total' => (int)($o['total'] ?? 0),
            'status' => (string)($o['status'] ?? ''),
            'created_at' => (string)($o['created_at'] ?? ''),
            'ticket_count' => (int)($orderTicketCountMap[$detailOrderId] ?? 0),
            'items' => $orderItemDetailsMap[$detailOrderId] ?? [],
            'attendees' => $orderAttendeeMap[$detailOrderId] ?? [],
            'missing_court_count' => $missingCourtCount,
            'proofs' => $proofPaths,
          ];
          $ig = trim((string)($o['instagram'] ?? '')); $ig = $ig !== '' ? '@' . ltrim($ig, '@') : '-';
        ?>
          <div class="order-card">
            <!-- Card Head -->
            <div class="order-card-head">
              <div>
                <div class="order-card-id"><?= (int)$o['id'] ?></div>
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
              <div class="order-card-row">
                <div class="order-card-label">Kehadiran</div>
                <div class="order-card-value" style="font-size:12.5px;font-weight:700;">
                  <span style="color:#166534;">Hadir: <?= (int)$arrivedCount ?></span>
                  <span style="color:#6b7280;"> | </span>
                  <span style="color:#b91c1c;">Belum Datang: <?= (int)$notArrivedCount ?></span>
                </div>
              </div>

              <!-- Total row -->
              <div class="order-card-row">
                <div class="order-card-label">Total</div>
                <div class="order-card-total"><?= h(rupiah((int)$o['total'])) ?></div>
              </div>

              <!-- Proof row -->
              <?php if ($proofPaths): ?>
              <div class="order-card-row">
                <div class="order-card-label">Bukti</div>
                <div>
                  <button class="proof-link proof-gallery-trigger" type="button" data-proof-gallery="<?= h(json_encode($proofPaths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" data-order="<?= (int)$o['id'] ?>"><i class="bi bi-file-earmark-image"></i> View Proof (<?= count($proofPaths) ?>)</button>
                </div>
              </div>
              <?php endif; ?>
            </div>

            <!-- Card Actions -->
            <div class="order-card-actions">
              <button class="btn ghost small<?= $missingCourtCount > 0 ? ' detail-warning' : '' ?>" type="button" data-order-detail="<?= h(json_encode($detailPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" data-tooltip="<?= $missingCourtCount > 0 ? h('Masih ada ' . $missingCourtCount . ' attendee belum pilih court. Klik Detail untuk lengkapi.') : '' ?>"><i class="bi bi-info-circle"></i> Detail</button>
              <button class="btn primary small" type="button" data-confirm-action="accept" data-order-id="<?= (int)$o['id'] ?>" data-proof="<?= $firstProof ? '/uploads/' . h($firstProof) : '' ?>" data-court-missing="<?= (int)$missingCourtCount ?>" <?= $canAction ? '' : 'disabled' ?>><i class="bi bi-check-circle"></i> Accept</button>
              <button class="btn ghost small" type="button" data-confirm-action="reject" data-order-id="<?= (int)$o['id'] ?>" data-proof="<?= $firstProof ? '/uploads/' . h($firstProof) : '' ?>" <?= $canAction ? '' : 'disabled' ?>><i class="bi bi-x-circle"></i> Reject</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- -- Pagination Bottom --------------------------------- -->
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
      </section>
      </div>

    </div>
  </main>

  <!-- --- Modals --------------------------------------------- -->
  <div class="proof-modal" id="proofGalleryModal" aria-hidden="true">
    <div class="proof-card proof-gallery-card" role="dialog" aria-modal="true" aria-labelledby="proofGalleryTitle">
      <div class="proof-head">
        <div class="proof-title" id="proofGalleryTitle"><i class="bi bi-image"></i> Bukti Pembayaran</div>
        <button class="proof-close" type="button" aria-label="Close" data-proof-gallery-close><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="proof-gallery-body" id="proofGalleryList"></div>
    </div>
  </div>

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

  <div class="sponsor-modal" id="acceptWarnModal" aria-hidden="true">
    <div class="sponsor-modal-card" role="dialog" aria-modal="true" aria-labelledby="acceptWarnTitle">
      <div class="sponsor-modal-head">
        <h2 class="sponsor-modal-title" id="acceptWarnTitle"><i class="bi bi-exclamation-triangle-fill"></i> Tidak Bisa Accept</h2>
        <button class="sponsor-modal-close" type="button" id="acceptWarnClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="warn-modal-body">
        <p class="warn-modal-message" id="acceptWarnMessage">Masih ada attendee yang belum pilih court.</p>
        <div class="sponsor-form-actions">
          <button class="btn primary" type="button" id="acceptWarnOk"><i class="bi bi-check2"></i> OK</button>
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
    <input type="hidden" name="arrival" value="<?= h($selectedArrival) ?>">
  </form>

  <div class="screen-notice" id="screenNotice" role="status" aria-live="polite"></div>
  <div class="dashboard-loading-modal" id="dashboardLoadingModal" aria-hidden="true">
    <div class="dashboard-loading-card">
      <div class="dashboard-loading-spinner" aria-hidden="true"></div>
      <div class="dashboard-loading-title"><i class="bi bi-hourglass-split"></i> Processing Request</div>
      <div class="dashboard-loading-subtext">Harap tunggu sebentar, proses sedang berjalan.</div>
    </div>
  </div>

  <form method="post" action="/admin/dashboard" id="attendeePackageForm" style="display:none;">
    <input type="hidden" name="dashboard_action" value="change_attendee_package">
    <input type="hidden" name="order_id" id="attendeePackageOrderId" value="">
    <input type="hidden" name="attendee_id" id="attendeePackageAttendeeId" value="">
    <input type="hidden" name="new_package_id" id="attendeePackageNewPackageId" value="">
    <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
    <input type="hidden" name="filter_order_id" value="<?= $selectedOrderId > 0 ? (int)$selectedOrderId : '' ?>">
    <input type="hidden" name="package" value="<?= (int)$selectedPackage ?>">
    <input type="hidden" name="name" value="<?= h($selectedName) ?>">
    <input type="hidden" name="email" value="<?= h($selectedEmail) ?>">
    <input type="hidden" name="created_date" value="<?= h($selectedDate) ?>">
    <input type="hidden" name="status" value="<?= h($selectedStatus) ?>">
    <input type="hidden" name="arrival" value="<?= h($selectedArrival) ?>">
  </form>

  <div class="sponsor-modal" id="adminEmailModal" aria-hidden="true">
    <div class="sponsor-modal-card" role="dialog" aria-modal="true" aria-labelledby="adminEmailModalTitle">
      <div class="sponsor-modal-head">
        <h2 class="sponsor-modal-title" id="adminEmailModalTitle"><i class="bi bi-envelope-check"></i> Email Notifikasi Admin</h2>
        <button class="sponsor-modal-close" type="button" id="closeAdminEmailModal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="admin-email-forms">
        <p class="admin-email-otp-note">Total email aktif: <strong><?= (int)count($adminNotificationEmails) ?></strong></p>
        <form class="admin-email-form" method="post" action="/admin/dashboard">
          <input type="hidden" name="dashboard_action" value="add_admin_notification_email">
          <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
          <input type="hidden" name="filter_order_id" value="<?= $selectedOrderId > 0 ? (int)$selectedOrderId : '' ?>">
          <input type="hidden" name="package" value="<?= (int)$selectedPackage ?>">
          <input type="hidden" name="name" value="<?= h($selectedName) ?>">
          <input type="hidden" name="email" value="<?= h($selectedEmail) ?>">
          <input type="hidden" name="created_date" value="<?= h($selectedDate) ?>">
          <input type="hidden" name="status" value="<?= h($selectedStatus) ?>">
    <input type="hidden" name="arrival" value="<?= h($selectedArrival) ?>">
          <div class="sponsor-field">
            <label for="adminNotifyEmail">Tambah Email Admin</label>
            <input id="adminNotifyEmail" type="email" name="admin_notify_email" placeholder="contoh: admin2@domain.com" required>
          </div>
          <button class="btn primary" type="submit"><i class="bi bi-plus-circle"></i> Tambah Email</button>
        </form>

        <hr class="admin-email-divider">
        <?php if ($adminNotificationEmails): ?>
          <ul class="admin-email-list">
            <?php foreach ($adminNotificationEmails as $row): ?>
              <li class="admin-email-item">
                <div class="admin-email-item-main">
                  <strong><?= h((string)($row['email'] ?? '')) ?></strong>
                  <span>Verified: <?= h((string)($row['verified_at'] ?? '-')) ?></span>
                </div>
                <form method="post" action="/admin/dashboard" onsubmit="return confirm('Hapus email admin ini dari daftar notifikasi?');">
                  <input type="hidden" name="dashboard_action" value="remove_admin_notification_email">
                  <input type="hidden" name="admin_notification_email_id" value="<?= (int)($row['id'] ?? 0) ?>">
                  <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
                  <input type="hidden" name="filter_order_id" value="<?= $selectedOrderId > 0 ? (int)$selectedOrderId : '' ?>">
                  <input type="hidden" name="package" value="<?= (int)$selectedPackage ?>">
                  <input type="hidden" name="name" value="<?= h($selectedName) ?>">
                  <input type="hidden" name="email" value="<?= h($selectedEmail) ?>">
                  <input type="hidden" name="created_date" value="<?= h($selectedDate) ?>">
                  <input type="hidden" name="status" value="<?= h($selectedStatus) ?>">
    <input type="hidden" name="arrival" value="<?= h($selectedArrival) ?>">
                  <button class="admin-email-remove-btn" type="submit" aria-label="Hapus email admin">
                    <i class="bi bi-trash3"></i> Hapus
                  </button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="admin-email-empty">Belum ada email notifikasi admin. Tambahkan email agar notifikasi order otomatis dikirim.</div>
        <?php endif; ?>

        <div class="sponsor-form-actions">
          <button class="btn ghost" type="button" id="cancelAdminEmailModal"><i class="bi bi-x-circle"></i> Tutup</button>
        </div>
      </div>
    </div>
  </div>

  <div class="sponsor-modal" id="sponsorModal" aria-hidden="true">
    <div class="sponsor-modal-card" role="dialog" aria-modal="true" aria-labelledby="sponsorModalTitle">
      <div class="sponsor-modal-head">
        <h2 class="sponsor-modal-title" id="sponsorModalTitle"><i class="bi bi-building-add"></i> Tambah Sponsor</h2>
        <button class="sponsor-modal-close" type="button" id="closeSponsorModal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="sponsor-manage-wrap">
        <p class="sponsor-manage-note">Total sponsor aktif: <strong><?= (int)count($dashboardSponsors) ?></strong></p>

        <form class="sponsor-form" method="post" action="/admin/dashboard" enctype="multipart/form-data" id="sponsorForm">
          <input type="hidden" name="dashboard_action" value="create_sponsor">
          <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
          <input type="hidden" name="filter_order_id" value="<?= $selectedOrderId > 0 ? (int)$selectedOrderId : '' ?>">
          <input type="hidden" name="package" value="<?= (int)$selectedPackage ?>">
          <input type="hidden" name="name" value="<?= h($selectedName) ?>">
          <input type="hidden" name="email" value="<?= h($selectedEmail) ?>">
          <input type="hidden" name="created_date" value="<?= h($selectedDate) ?>">
          <input type="hidden" name="status" value="<?= h($selectedStatus) ?>">
    <input type="hidden" name="arrival" value="<?= h($selectedArrival) ?>">
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
            <button class="btn primary" type="submit"><i class="bi bi-check-circle"></i> Simpan</button>
          </div>
        </form>

        <hr class="sponsor-manage-divider">

        <?php if ($dashboardSponsors): ?>
          <ul class="sponsor-manage-list">
            <?php foreach ($dashboardSponsors as $sponsorRow): ?>
              <?php
                $rawLogoPath = trim((string)($sponsorRow['logo_path'] ?? ''));
                $logoSrc = $rawLogoPath;
                if ($logoSrc !== '' && !preg_match('/^https?:\/\//i', $logoSrc)) {
                    $logoSrc = '/' . ltrim($logoSrc, '/');
                }
              ?>
              <li class="sponsor-manage-item">
                <div class="sponsor-manage-item-main">
                  <div class="sponsor-manage-logo">
                    <img src="<?= h($logoSrc) ?>" alt="<?= h((string)($sponsorRow['name'] ?? 'Sponsor')) ?>">
                  </div>
                  <div class="sponsor-manage-meta">
                    <strong><?= h((string)($sponsorRow['name'] ?? 'Sponsor')) ?></strong>
                    <span><?= h((string)($sponsorRow['website_url'] ?? 'Tanpa link website')) ?></span>
                  </div>
                </div>
                <form method="post" action="/admin/dashboard" onsubmit="return confirm('Hapus sponsor ini dari daftar?');">
                  <input type="hidden" name="dashboard_action" value="remove_sponsor">
                  <input type="hidden" name="sponsor_id" value="<?= (int)($sponsorRow['id'] ?? 0) ?>">
                  <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
                  <input type="hidden" name="filter_order_id" value="<?= $selectedOrderId > 0 ? (int)$selectedOrderId : '' ?>">
                  <input type="hidden" name="package" value="<?= (int)$selectedPackage ?>">
                  <input type="hidden" name="name" value="<?= h($selectedName) ?>">
                  <input type="hidden" name="email" value="<?= h($selectedEmail) ?>">
                  <input type="hidden" name="created_date" value="<?= h($selectedDate) ?>">
                  <input type="hidden" name="status" value="<?= h($selectedStatus) ?>">
    <input type="hidden" name="arrival" value="<?= h($selectedArrival) ?>">
                  <button class="sponsor-remove-btn" type="submit" aria-label="Hapus sponsor">
                    <i class="bi bi-trash3"></i> Hapus
                  </button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="sponsor-empty">Belum ada sponsor. Tambahkan sponsor baru lewat form di atas.</div>
        <?php endif; ?>

        <div class="sponsor-form-actions">
          <button class="btn ghost" type="button" id="cancelSponsorModal"><i class="bi bi-x-circle"></i> Tutup</button>
        </div>
      </div>
    </div>
  </div>

  <div class="sponsor-modal" id="adModal" aria-hidden="true">
    <div class="sponsor-modal-card" role="dialog" aria-modal="true" aria-labelledby="adModalTitle">
      <div class="sponsor-modal-head">
        <h2 class="sponsor-modal-title" id="adModalTitle"><i class="bi bi-badge-ad"></i> Tambah Iklan</h2>
        <button class="sponsor-modal-close" type="button" id="closeAdModal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="ad-manage-wrap">
        <p class="ad-manage-note">Total iklan tersimpan: <strong><?= (int)count($dashboardAds) ?></strong></p>

        <p class="ad-manage-section-title"><i class="bi bi-collection-play"></i> Daftar Iklan</p>
        <p class="sponsor-help" style="margin:0;">Menampilkan <?= (int)count($dashboardAds) ?> data iklan.</p>

        <?php if ($dashboardAds): ?>
          <ul class="ad-manage-list">
            <?php foreach ($dashboardAds as $adRow): ?>
              <?php
                $rawVideoPath = trim((string)($adRow['video_path'] ?? ''));
                $videoSrc = $rawVideoPath;
                if ($videoSrc !== '' && !preg_match('/^https?:\/\//i', $videoSrc)) {
                    $videoSrc = '/' . ltrim($videoSrc, '/');
                }
                $isRemoteVideo = (bool)preg_match('/^https?:\/\//i', $videoSrc);
                $videoHost = strtolower((string)(parse_url($videoSrc, PHP_URL_HOST) ?? ''));
                $videoPath = strtolower((string)(parse_url($videoSrc, PHP_URL_PATH) ?? ''));
                $linkLabel = 'Link Video';
                if (strpos($videoHost, 'youtube.com') !== false || strpos($videoHost, 'youtu.be') !== false) {
                    $linkLabel = 'YouTube';
                } elseif (strpos($videoHost, 'instagram.com') !== false && (strpos($videoPath, '/reel/') === 0 || strpos($videoPath, '/reels/') === 0)) {
                    $linkLabel = 'Instagram Reels';
                }
              ?>
              <li class="ad-manage-item">
                <div class="ad-manage-item-main">
                  <div class="ad-manage-video">
                    <?php if ($isRemoteVideo): ?>
                      <a href="<?= h($videoSrc) ?>" target="_blank" rel="noopener noreferrer" class="ad-manage-link-preview"><?= h($linkLabel) ?></a>
                    <?php else: ?>
                      <video src="<?= h($videoSrc) ?>" muted preload="metadata"></video>
                    <?php endif; ?>
                  </div>
                  <div class="ad-manage-meta">
                    <strong><?= h((string)($adRow['title'] ?? 'Iklan')) ?></strong>
                    <span><?= h((string)($adRow['video_path'] ?? '-')) ?></span>
                  </div>
                </div>
                <form method="post" action="/admin/dashboard" onsubmit="return confirm('Hapus iklan ini dari daftar?');">
                  <input type="hidden" name="dashboard_action" value="remove_ad">
                  <input type="hidden" name="ad_id" value="<?= (int)($adRow['id'] ?? 0) ?>">
                  <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
                  <input type="hidden" name="filter_order_id" value="<?= $selectedOrderId > 0 ? (int)$selectedOrderId : '' ?>">
                  <input type="hidden" name="package" value="<?= (int)$selectedPackage ?>">
                  <input type="hidden" name="name" value="<?= h($selectedName) ?>">
                  <input type="hidden" name="email" value="<?= h($selectedEmail) ?>">
                  <input type="hidden" name="created_date" value="<?= h($selectedDate) ?>">
                  <input type="hidden" name="status" value="<?= h($selectedStatus) ?>">
    <input type="hidden" name="arrival" value="<?= h($selectedArrival) ?>">
                  <button class="ad-remove-btn" type="submit" aria-label="Hapus iklan">
                    <i class="bi bi-trash3"></i> Hapus
                  </button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="ad-empty">Belum ada iklan tersimpan. Tambahkan iklan lewat link YouTube/Reels IG atau upload video.</div>
        <?php endif; ?>

        <hr class="ad-manage-divider">

        <p class="ad-manage-section-title"><i class="bi bi-plus-circle"></i> Tambah Iklan Baru</p>
        <form class="sponsor-form" method="post" action="/admin/dashboard" enctype="multipart/form-data" id="adForm">
          <input type="hidden" name="dashboard_action" value="create_ad">
          <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
          <input type="hidden" name="filter_order_id" value="<?= $selectedOrderId > 0 ? (int)$selectedOrderId : '' ?>">
          <input type="hidden" name="package" value="<?= (int)$selectedPackage ?>">
          <input type="hidden" name="name" value="<?= h($selectedName) ?>">
          <input type="hidden" name="email" value="<?= h($selectedEmail) ?>">
          <input type="hidden" name="created_date" value="<?= h($selectedDate) ?>">
          <input type="hidden" name="status" value="<?= h($selectedStatus) ?>">
    <input type="hidden" name="arrival" value="<?= h($selectedArrival) ?>">
          <div class="sponsor-field">
            <label for="adTitle">Judul Iklan</label>
            <input id="adTitle" type="text" name="ad_title" placeholder="Contoh: Promo Event 2026" required>
          </div>
          <div class="sponsor-field">
            <label>Pilih Sumber Iklan</label>
            <div class="ad-source-options" id="adSourceOptions">
              <label class="ad-source-option" for="adSourceLink">
                <input id="adSourceLink" type="radio" name="ad_source_type" value="link" checked>
                Link YouTube / Reels IG
              </label>
              <label class="ad-source-option" for="adSourceUpload">
                <input id="adSourceUpload" type="radio" name="ad_source_type" value="upload">
                Upload Video
              </label>
            </div>
          </div>
          <div class="sponsor-field ad-field-group" id="adUrlGroup">
            <label for="adUrl">Link YouTube / Reels IG</label>
            <input id="adUrl" type="url" name="ad_url" placeholder="https://youtube.com/... atau https://instagram.com/reel/...">
            <p class="sponsor-help"><i class="bi bi-link-45deg"></i> Domain yang didukung: YouTube dan Instagram Reels.</p>
          </div>
          <div class="sponsor-field ad-field-group is-disabled" id="adVideoGroup">
            <label for="adVideo">File Video Iklan</label>
            <input id="adVideo" type="file" name="ad_video" accept=".mp4,.webm,.ogv,.mov,video/mp4,video/webm,video/ogg,video/quicktime">
            <p class="sponsor-help"><i class="bi bi-info-circle"></i> Format file: MP4, WEBM, OGV, MOV (maks 50MB)</p>
          </div>
          <div class="ad-preview-box" id="adPreviewBox">
            <div class="ad-preview-head"><i class="bi bi-eye"></i> Preview Iklan</div>
            <div class="ad-preview-media" id="adPreviewMedia">
              <div class="ad-preview-empty">Preview akan muncul setelah isi link atau pilih file video.</div>
            </div>
            <p class="ad-preview-note" id="adPreviewNote">Belum ada input untuk dipreview.</p>
          </div>
          <div class="sponsor-form-actions">
            <button class="btn primary" type="submit"><i class="bi bi-check-circle"></i> Simpan Iklan</button>
          </div>
        </form>

        <div class="sponsor-form-actions">
          <button class="btn ghost" type="button" id="cancelAdModal"><i class="bi bi-x-circle"></i> Tutup</button>
        </div>
      </div>
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
    <input type="hidden" name="arrival" value="<?= h($selectedArrival) ?>">

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
    // -- Card toggle: click active card to clear filters -------
    (function () {
      var resetUrl = '/admin/dashboard';
      document.addEventListener('click', function (e) {
        var card = e.target && e.target.closest ? e.target.closest('.stat-card-link, .court-card-link') : null;
        if (!card) return;
        if (!card.classList.contains('is-active')) return;
        e.preventDefault();
        window.location.href = resetUrl;
      });
    })();

  </script>

  <script>
    // -- Mobile filter toggle -----------------------------------
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

    // -- Auto-submit filter -------------------------------------
    (function () {
      var form = document.getElementById('dashboardFilterForm');
      if (!form) return;
      var focusKey = 'adminDashboardFilterFocus';
      var textTimer = null;
      var textDelayMs = 600;
      var desktopAutoMedia = window.matchMedia('(min-width: 901px)');

      function isDesktopAutoSubmit() {
        return desktopAutoMedia.matches;
      }

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

      function submitNow(options) {
        var allowOnMobile = !!(options && options.allowOnMobile);
        if (!isDesktopAutoSubmit() && !allowOnMobile) return;
        var active = document.activeElement;
        if (active && form.contains(active)) saveTypingState(active);
        form.submit();
      }

      if (isDesktopAutoSubmit()) restoreTypingState();
      form.querySelectorAll('select,input[type="date"]').forEach(function (el) {
        el.addEventListener('change', function () {
          submitNow({ allowOnMobile: true });
        });
      });
      form.querySelectorAll('input[type="text"],input[type="email"]').forEach(function (el) {
        el.addEventListener('input', function () {
          saveTypingState(el);
          if (!isDesktopAutoSubmit()) return;
          if (textTimer) clearTimeout(textTimer);
          textTimer = setTimeout(submitNow, textDelayMs);
        });
        el.addEventListener('click', function () { saveTypingState(el); });
        el.addEventListener('keyup', function () { saveTypingState(el); });
        el.addEventListener('keydown', function (e) {
          if (e.key !== 'Enter') return;
          if (!isDesktopAutoSubmit()) return;
          e.preventDefault();
          if (textTimer) clearTimeout(textTimer);
          submitNow();
        });
      });
      window.addEventListener('resize', function () {
        if (isDesktopAutoSubmit()) return;
        if (textTimer) clearTimeout(textTimer);
      });
    })();
  </script>

  <script>
    // -- Admin Email Modal ------------------------------------
    (function () {
      var modal = document.getElementById('adminEmailModal');
      var openBtn = document.getElementById('openAdminEmailModal');
      var closeBtn = document.getElementById('closeAdminEmailModal');
      var cancelBtn = document.getElementById('cancelAdminEmailModal');
      if (!modal || !openBtn || !closeBtn || !cancelBtn) return;
      function openModal() {
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('sponsor-modal-open');
        var emailInput = document.getElementById('adminNotifyEmail');
        if (emailInput) setTimeout(function () { emailInput.focus(); }, 20);
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
    // -- Change Password Modal ---------------------------------
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
    // -- Sponsor Modal ------------------------------------------
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
    // -- Ads Modal ---------------------------------------------
    (function () {
      var modal = document.getElementById('adModal');
      var openBtn = document.getElementById('openAdModal');
      var closeBtn = document.getElementById('closeAdModal');
      var cancelBtn = document.getElementById('cancelAdModal');
      var adSourceInputs = modal ? modal.querySelectorAll('input[name="ad_source_type"]') : null;
      var adUrlGroup = document.getElementById('adUrlGroup');
      var adVideoGroup = document.getElementById('adVideoGroup');
      var adUrlInput = document.getElementById('adUrl');
      var adVideoInput = document.getElementById('adVideo');
      var adPreviewMedia = document.getElementById('adPreviewMedia');
      var adPreviewNote = document.getElementById('adPreviewNote');
      if (!modal || !openBtn || !closeBtn || !cancelBtn || !adPreviewMedia || !adPreviewNote) return;

      var currentObjectUrl = '';

      function clearPreviewMedia() {
        adPreviewMedia.innerHTML = '<div class="ad-preview-empty">Preview akan muncul setelah isi link atau pilih file video.</div>';
        adPreviewNote.textContent = 'Belum ada input untuk dipreview.';
        adPreviewNote.classList.remove('is-error');
      }

      function selectedSourceType() {
        if (!adSourceInputs || !adSourceInputs.length) return 'link';
        var selected = Array.prototype.find.call(adSourceInputs, function (input) { return input.checked; });
        return selected ? selected.value : 'link';
      }

      function setInputModes() {
        var sourceType = selectedSourceType();
        var isLinkMode = sourceType === 'link';

        if (adUrlInput) {
          adUrlInput.disabled = !isLinkMode;
          adUrlInput.required = isLinkMode;
          if (!isLinkMode) adUrlInput.value = '';
        }
        if (adVideoInput) {
          adVideoInput.disabled = isLinkMode;
          adVideoInput.required = !isLinkMode;
          if (isLinkMode) adVideoInput.value = '';
        }
        if (adUrlGroup) adUrlGroup.classList.toggle('is-disabled', !isLinkMode);
        if (adVideoGroup) adVideoGroup.classList.toggle('is-disabled', isLinkMode);
      }

      function releaseObjectUrl() {
        if (!currentObjectUrl) return;
        URL.revokeObjectURL(currentObjectUrl);
        currentObjectUrl = '';
      }

      function parseYouTubeEmbed(urlText) {
        try {
          var u = new URL(urlText);
          var host = (u.hostname || '').toLowerCase();
          var id = '';
          if (host === 'youtu.be' || host === 'www.youtu.be') {
            id = (u.pathname || '').replace(/^\/+/, '').split('/')[0] || '';
          } else if (host.indexOf('youtube.com') !== -1) {
            if ((u.pathname || '').indexOf('/shorts/') === 0) {
              id = (u.pathname || '').split('/')[2] || '';
            } else {
              id = u.searchParams.get('v') || '';
            }
          }
          if (!id) return '';
          return 'https://www.youtube.com/embed/' + encodeURIComponent(id);
        } catch (err) {
          return '';
        }
      }

      function parseInstagramEmbed(urlText) {
        try {
          var u = new URL(urlText);
          var host = (u.hostname || '').toLowerCase();
          if (host.indexOf('instagram.com') === -1) return '';
          var parts = (u.pathname || '').split('/').filter(Boolean);
          if (parts.length < 2) return '';
          var type = parts[0].toLowerCase();
          var code = parts[1];
          if ((type !== 'reel' && type !== 'reels') || !code) return '';
          return 'https://www.instagram.com/reel/' + encodeURIComponent(code) + '/embed';
        } catch (err) {
          return '';
        }
      }

      function renderIframePreview(src, noteText) {
        adPreviewMedia.innerHTML = '';
        var iframe = document.createElement('iframe');
        iframe.src = src;
        iframe.loading = 'lazy';
        iframe.allowFullscreen = true;
        iframe.referrerPolicy = 'no-referrer-when-downgrade';
        adPreviewMedia.appendChild(iframe);
        adPreviewNote.textContent = noteText;
        adPreviewNote.classList.remove('is-error');
      }

      function renderFilePreview(file) {
        releaseObjectUrl();
        currentObjectUrl = URL.createObjectURL(file);
        adPreviewMedia.innerHTML = '';
        var video = document.createElement('video');
        video.src = currentObjectUrl;
        video.controls = true;
        video.preload = 'metadata';
        adPreviewMedia.appendChild(video);
        adPreviewNote.textContent = 'Preview dari file lokal: ' + file.name;
        adPreviewNote.classList.remove('is-error');
      }

      function renderInvalidUrlPreview() {
        adPreviewMedia.innerHTML = '<div class="ad-preview-empty">Link belum valid untuk preview. Gunakan link YouTube atau Instagram Reels.</div>';
        adPreviewNote.textContent = 'URL belum didukung.';
        adPreviewNote.classList.add('is-error');
      }

      function refreshAdPreview() {
        var sourceType = selectedSourceType();
        var hasFile = !!(adVideoInput && adVideoInput.files && adVideoInput.files.length > 0);
        var urlText = adUrlInput ? String(adUrlInput.value || '').trim() : '';

        if (sourceType === 'upload' && hasFile) {
          renderFilePreview(adVideoInput.files[0]);
          return;
        }

        releaseObjectUrl();
        if (sourceType === 'upload') {
          adPreviewMedia.innerHTML = '<div class="ad-preview-empty">Pilih file video untuk melihat preview.</div>';
          adPreviewNote.textContent = 'Mode upload dipilih.';
          adPreviewNote.classList.remove('is-error');
          return;
        }

        if (!urlText) {
          clearPreviewMedia();
          return;
        }

        var ytEmbed = parseYouTubeEmbed(urlText);
        if (ytEmbed) {
          renderIframePreview(ytEmbed, 'Preview link YouTube.');
          return;
        }

        var igEmbed = parseInstagramEmbed(urlText);
        if (igEmbed) {
          renderIframePreview(igEmbed, 'Preview link Instagram Reels.');
          return;
        }

        renderInvalidUrlPreview();
      }

      function openModal() {
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('sponsor-modal-open');
        var n = document.getElementById('adTitle');
        if (n) setTimeout(function () { n.focus(); }, 20);
        setInputModes();
        refreshAdPreview();
      }
      function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('sponsor-modal-open');
        releaseObjectUrl();
      }
      openBtn.addEventListener('click', openModal);
      closeBtn.addEventListener('click', closeModal);
      cancelBtn.addEventListener('click', closeModal);
      if (adSourceInputs && adSourceInputs.length) {
        Array.prototype.forEach.call(adSourceInputs, function (input) {
          input.addEventListener('change', function () {
            setInputModes();
            refreshAdPreview();
          });
        });
      }
      if (adUrlInput) {
        adUrlInput.addEventListener('input', refreshAdPreview);
        adUrlInput.addEventListener('change', refreshAdPreview);
      }
      if (adVideoInput) {
        adVideoInput.addEventListener('change', refreshAdPreview);
      }
      setInputModes();
      modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('show')) closeModal(); });
      window.addEventListener('beforeunload', releaseObjectUrl);
    })();
  </script>

  <script>
    // -- Order Detail Modal -------------------------------------
    (function() {
      var modal = document.getElementById('orderDetailModal');
      if (!modal) return;
      var title = document.getElementById('orderDetailTitle');
      var detailHead = document.getElementById('orderDetailHead');
      var screenNotice = document.getElementById('screenNotice');
      var detailItems = document.getElementById('orderDetailItems');
      var detailItemsEmpty = document.getElementById('orderDetailItemsEmpty');
      var detailAttendees = document.getElementById('orderDetailAttendees');
      var detailAttendeesEmpty = document.getElementById('orderDetailAttendeesEmpty');
      var closeBtn = modal.querySelector('.proof-close');
      var packageForm = document.getElementById('attendeePackageForm');
      var packageFormOrderId = document.getElementById('attendeePackageOrderId');
      var packageFormAttendeeId = document.getElementById('attendeePackageAttendeeId');
      var packageFormNewPackageId = document.getElementById('attendeePackageNewPackageId');
      var packageOptions = <?= json_encode(array_values(array_map(static function (array $pkg): array {
        return [
          'id' => (int)($pkg['id'] ?? 0),
          'name' => (string)($pkg['name'] ?? '-'),
          'price' => max(0, (int)($pkg['price'] ?? 0)),
        ];
      }, $packages)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

      function asCurrency(n) { return 'Rp ' + Number(n || 0).toLocaleString('id-ID'); }
      function formatDate(raw) { if (!raw) return '-'; var d = new Date(raw); return isNaN(d.getTime()) ? String(raw) : d.toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); }
      function countCheckedIn(arr) { return Array.isArray(arr) ? arr.filter(function(a) { return a && a.checked_in_at; }).length : 0; }
      function escapeHtml(t) { return String(t == null ? '' : t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
      function clearList(el) { while (el.firstChild) el.removeChild(el.firstChild); }
      function statusLabel(s) { return s === 'paid' ? 'Paid' : s === 'accepted' ? 'Accepted' : s === 'rejected' ? 'Rejected' : (s || '-'); }
      function courtLabel(courtNo) { return Number(courtNo) > 0 ? ('Court ' + Number(courtNo)) : ''; }
      function toCourtNo(raw) { var n = Number(raw || 0); return n >= 1 && n <= 6 ? n : 0; }
      function isPackageCName(rawName) {
        var normalized = String(rawName == null ? '' : rawName).trim().toLowerCase();
        return normalized === 'package c';
      }
      function appendCourtActions(li, orderId, attendeeId, selectedCourt, allowNoCourt) {
        if (!li || li.querySelector('.detail-attendee-court-actions')) return;
        var safeCourt = toCourtNo(selectedCourt);
        var canNoCourt = !!allowNoCourt;
        var courtWrap = document.createElement('div');
        courtWrap.className = 'detail-attendee-court-actions';
        var courtSelect = document.createElement('select');
        courtSelect.className = 'detail-court-select';
        courtSelect.setAttribute('data-role', 'court-select');
        if (canNoCourt) {
          var noCourtOption = document.createElement('option');
          noCourtOption.value = '0';
          noCourtOption.textContent = 'No Court';
          if (safeCourt <= 0) {
            noCourtOption.selected = true;
          }
          courtSelect.appendChild(noCourtOption);
        }
        for (var cn = 1; cn <= 6; cn++) {
          var courtOption = document.createElement('option');
          courtOption.value = String(cn);
          courtOption.textContent = 'Court ' + cn;
          if (cn === safeCourt) {
            courtOption.selected = true;
          }
          courtSelect.appendChild(courtOption);
        }
        var courtBtn = document.createElement('button');
        courtBtn.type = 'button';
        courtBtn.className = 'detail-court-btn';
        courtBtn.setAttribute('data-save-court', '1');
        courtBtn.setAttribute('data-order-id', String(orderId));
        courtBtn.setAttribute('data-attendee-id', String(attendeeId));
        courtBtn.textContent = 'Pilih court';
        courtWrap.appendChild(courtSelect);
        courtWrap.appendChild(courtBtn);
        var packageWrap = li.querySelector('.detail-package-wrap');
        if (packageWrap) {
          li.insertBefore(courtWrap, packageWrap);
        } else {
          li.appendChild(courtWrap);
        }
      }
      var detailNoticeTimer = null;
      function showDetailNotice(message, type) {
        if (!screenNotice) {
          if (message) alert(String(message));
          return;
        }
        if (detailNoticeTimer) { clearTimeout(detailNoticeTimer); detailNoticeTimer = null; }
        var isError = type === 'error';
        var text = String(message || '');
        screenNotice.classList.remove('show', 'is-success', 'is-error');
        screenNotice.textContent = text ? ((isError ? 'Gagal: ' : 'Berhasil: ') + text) : '';
        if (!text) {
          return;
        }
        screenNotice.classList.add(isError ? 'is-error' : 'is-success');
        screenNotice.classList.add('show');
        detailNoticeTimer = setTimeout(function() {
          screenNotice.classList.remove('show', 'is-success', 'is-error');
          screenNotice.textContent = '';
          detailNoticeTimer = null;
        }, 4500);
      }
      function submitPackageChange(orderId, attendeeId, packageId) {
        var body = new URLSearchParams();
        body.append('dashboard_action', 'change_attendee_package');
        body.append('order_id', String(Number(orderId || 0)));
        body.append('attendee_id', String(Number(attendeeId || 0)));
        body.append('new_package_id', String(Number(packageId || 0)));
        return fetch('/admin/dashboard', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: body.toString()
        }).then(function(res) {
          return res.json().catch(function() {
            return { ok: false, message: 'Response server tidak valid.' };
          });
        });
      }
      function saveCourt(orderId, attendeeId, courtNo) {
        var body = new URLSearchParams();
        body.append('dashboard_action', 'update_attendee_court');
        body.append('order_id', String(Number(orderId || 0)));
        body.append('attendee_id', String(Number(attendeeId || 0)));
        body.append('court_no', String(toCourtNo(courtNo)));
        return fetch('/admin/dashboard', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: body.toString()
        }).then(function(res) {
          return res.json().catch(function() {
            return { ok: false, message: 'Response server tidak valid.' };
          });
        });
      }
      function rebuildItemsFromAttendees(attendees) {
        var summary = {};
        (Array.isArray(attendees) ? attendees : []).forEach(function(at) {
          var pid = Number(at && at.package_id ? at.package_id : 0);
          if (pid <= 0) return;
          var pkgRef = packageOptions.find(function(option) {
            return Number(option && option.id ? option.id : 0) === pid;
          });
          var key = String(pid);
          if (!summary[key]) {
            summary[key] = {
              package_name: (at && at.package_name ? String(at.package_name) : '') || (pkgRef ? String(pkgRef.name || '-') : ('Package ' + pid)),
              qty: 0,
              price: pkgRef ? Number(pkgRef.price || 0) : 0
            };
          }
          summary[key].qty += 1;
        });
        return Object.keys(summary).map(function(key) {
          var row = summary[key];
          var qty = Number(row && row.qty ? row.qty : 0);
          var price = Number(row && row.price ? row.price : 0);
          return {
            package_name: String(row && row.package_name ? row.package_name : '-'),
            qty: qty,
            price: price,
            subtotal: qty * price
          };
        }).sort(function(a, b) {
          return String(a && a.package_name ? a.package_name : '').localeCompare(String(b && b.package_name ? b.package_name : ''));
        });
      }
      function parseCardCountText(text) {
        var raw = String(text == null ? '' : text).replace(/[^\d-]/g, '');
        var n = Number(raw || 0);
        return isNaN(n) ? 0 : n;
      }
      function setCardCount(el, value) {
        if (!el) return;
        var safe = Math.max(0, Number(value || 0));
        el.textContent = String(safe);
      }
      function bumpCardValue(selector, delta) {
        if (!selector || !delta) return;
        var el = document.querySelector(selector);
        if (!el) return;
        var current = parseCardCountText(el.textContent);
        setCardCount(el, current + Number(delta || 0));
      }
      function bumpCourtCards(oldCourtNo, newCourtNo) {
        var oldNo = toCourtNo(oldCourtNo);
        var nextNo = toCourtNo(newCourtNo);
        if (oldNo > 0 && oldNo !== nextNo) {
          bumpCardValue('[data-court-card-value="' + oldNo + '"]', -1);
        }
        if (nextNo > 0 && nextNo !== oldNo) {
          bumpCardValue('[data-court-card-value="' + nextNo + '"]', 1);
        }
      }
      function bumpPackageCards(oldPackageId, newPackageId) {
        var oldId = Number(oldPackageId || 0);
        var nextId = Number(newPackageId || 0);
        if (oldId > 0 && oldId !== nextId) {
          bumpCardValue('[data-package-card-value="' + oldId + '"]', -1);
        }
        if (nextId > 0 && nextId !== oldId) {
          bumpCardValue('[data-package-card-value="' + nextId + '"]', 1);
        }
      }
      function getAcceptedRevenueValue(el) {
        if (!el) return 0;
        var fromData = Number(el.getAttribute('data-revenue-accepted-value') || 0);
        if (!isNaN(fromData) && fromData > 0) return fromData;
        var fromText = Number(String(el.textContent || '').replace(/[^\d-]/g, '') || 0);
        return isNaN(fromText) ? 0 : fromText;
      }
      function setAcceptedRevenueValue(el, value) {
        if (!el) return;
        var safe = Math.max(0, Number(value || 0));
        el.setAttribute('data-revenue-accepted-value', String(safe));
        el.textContent = asCurrency(safe);
      }
      function bumpAcceptedRevenue(delta) {
        if (!delta) return;
        var el = document.querySelector('[data-revenue-accepted-value]');
        if (!el) return;
        var current = getAcceptedRevenueValue(el);
        setAcceptedRevenueValue(el, current + Number(delta || 0));
      }
      function syncPayloadToButtons(orderId, attendeeId, courtNo) {
        var latestMissingCount = 0;
        var previousCourtNo = 0;
        document.querySelectorAll('[data-order-detail]').forEach(function(btn) {
          var raw = btn.getAttribute('data-order-detail') || '{}';
          var payload = {};
          try { payload = JSON.parse(raw); } catch (err) { payload = {}; }
          if (Number(payload.order_id || 0) !== Number(orderId || 0)) return;
          var attendees = Array.isArray(payload.attendees) ? payload.attendees : [];
          attendees.forEach(function(at) {
            if (Number(at && at.attendee_id ? at.attendee_id : 0) === Number(attendeeId || 0)) {
              if (previousCourtNo <= 0) {
                previousCourtNo = toCourtNo(at && at.court_no ? at.court_no : 0);
              }
              at.court_no = toCourtNo(courtNo);
            }
          });
          var missingCount = attendees.filter(function(at) {
            if (isPackageCName(at && at.package_name ? at.package_name : '')) return false;
            var cn = toCourtNo(at && at.court_no ? at.court_no : 0);
            return cn <= 0;
          }).length;
          payload.missing_court_count = missingCount;
          btn.setAttribute('data-order-detail', JSON.stringify(payload));
          latestMissingCount = missingCount;
          var warnTitle = missingCount > 0 ? ('Masih ada ' + missingCount + ' attendee belum pilih court. Klik Detail untuk lengkapi.') : '';
          btn.setAttribute('data-tooltip', warnTitle);
          btn.classList.toggle('detail-warning', missingCount > 0);
        });
        document.querySelectorAll('[data-confirm-action="accept"]').forEach(function(btn) {
          if (Number(btn.getAttribute('data-order-id') || 0) !== Number(orderId || 0)) return;
          btn.setAttribute('data-court-missing', String(Math.max(0, latestMissingCount)));
        });
        bumpCourtCards(previousCourtNo, courtNo);
      }
      function syncPayloadPackageChange(orderId, attendeeId, packageId, packageName, orderTotal) {
        var previousPackageId = 0;
        var latestMissingCount = 0;
        var nextIsPackageC = isPackageCName(packageName);
        var revenueDelta = 0;
        var revenueAdjusted = false;
        document.querySelectorAll('[data-order-detail]').forEach(function(btn) {
          var raw = btn.getAttribute('data-order-detail') || '{}';
          var payload = {};
          try { payload = JSON.parse(raw); } catch (err) { payload = {}; }
          if (Number(payload.order_id || 0) !== Number(orderId || 0)) return;
          var previousOrderTotal = Number(payload.total || 0);
          if (isNaN(previousOrderTotal) || previousOrderTotal < 0) previousOrderTotal = 0;
          var attendees = Array.isArray(payload.attendees) ? payload.attendees : [];
          attendees.forEach(function(at) {
            if (Number(at && at.attendee_id ? at.attendee_id : 0) === Number(attendeeId || 0)) {
              if (previousPackageId <= 0) {
                previousPackageId = Number(at && at.package_id ? at.package_id : 0);
              }
              at.package_id = Number(packageId || 0);
              at.package_name = String(packageName || at.package_name || '');
              if (nextIsPackageC) {
                at.court_no = 0;
              }
            }
          });
          payload.items = rebuildItemsFromAttendees(attendees);
          payload.ticket_count = attendees.length;
          if (orderTotal !== null && orderTotal !== undefined && orderTotal !== '') {
            payload.total = Number(orderTotal || 0);
          } else {
            payload.total = payload.items.reduce(function(sum, it) {
              return sum + Number(it && it.subtotal ? it.subtotal : 0);
            }, 0);
          }
          var nextOrderTotal = Number(payload.total || 0);
          if (isNaN(nextOrderTotal) || nextOrderTotal < 0) nextOrderTotal = 0;
          if (!revenueAdjusted && String(payload.status || '').toLowerCase() === 'accepted') {
            revenueDelta = nextOrderTotal - previousOrderTotal;
            revenueAdjusted = true;
          }
          var missingCount = attendees.filter(function(at) {
            if (isPackageCName(at && at.package_name ? at.package_name : '')) return false;
            var cn = toCourtNo(at && at.court_no ? at.court_no : 0);
            return cn <= 0;
          }).length;
          payload.missing_court_count = missingCount;
          btn.setAttribute('data-order-detail', JSON.stringify(payload));
          latestMissingCount = missingCount;
          var warnTitle = missingCount > 0 ? ('Masih ada ' + missingCount + ' attendee belum pilih court. Klik Detail untuk lengkapi.') : '';
          btn.setAttribute('data-tooltip', warnTitle);
          btn.classList.toggle('detail-warning', missingCount > 0);
        });
        document.querySelectorAll('[data-confirm-action="accept"]').forEach(function(btn) {
          if (Number(btn.getAttribute('data-order-id') || 0) !== Number(orderId || 0)) return;
          btn.setAttribute('data-court-missing', String(Math.max(0, latestMissingCount)));
        });
        bumpPackageCards(previousPackageId, packageId);
        bumpAcceptedRevenue(revenueDelta);
      }

      function renderDetailItems(items) {
        clearList(detailItems);
        (Array.isArray(items) ? items : []).forEach(function(it) {
          var li = document.createElement('li');
          var qty = Number(it && it.qty ? it.qty : 0);
          var price = Number(it && it.price ? it.price : 0);
          li.textContent = (it && it.package_name ? String(it.package_name) : '-') + ' ×' + qty + ' @ ' + asCurrency(price) + ' = ' + asCurrency(Number(it && it.subtotal ? it.subtotal : qty * price));
          detailItems.appendChild(li);
        });
        detailItemsEmpty.style.display = (Array.isArray(items) && items.length) ? 'none' : 'block';
      }
      function refreshOpenDetailBreakdown(orderId) {
        if (!modal.classList.contains('show')) return;
        var targetBtn = null;
        document.querySelectorAll('[data-order-detail]').forEach(function(btn) {
          if (targetBtn) return;
          var raw = btn.getAttribute('data-order-detail') || '{}';
          var payload = {};
          try { payload = JSON.parse(raw); } catch (err) { payload = {}; }
          if (Number(payload.order_id || 0) === Number(orderId || 0)) {
            targetBtn = btn;
          }
        });
        if (!targetBtn) return;
        var latestPayload = {};
        try { latestPayload = JSON.parse(targetBtn.getAttribute('data-order-detail') || '{}'); } catch (err) { latestPayload = {}; }
        var latestItems = Array.isArray(latestPayload.items) ? latestPayload.items : [];
        renderDetailItems(latestItems);
        var computedTotal = latestItems.reduce(function(sum, it) {
          return sum + Number(it && it.subtotal ? it.subtotal : 0);
        }, 0);
        var totalValueEl = document.getElementById('orderDetailTotalValue');
        if (totalValueEl) {
          totalValueEl.textContent = asCurrency(computedTotal > 0 ? computedTotal : Number(latestPayload.total || 0));
        }
      }

      function openDetail(rawJson) {
        var payload = {}; try { payload = JSON.parse(rawJson || '{}'); } catch (err) {}
        showDetailNotice('', 'success');
        var orderId = Number(payload.order_id || 0);
        var items = Array.isArray(payload.items) ? payload.items : [];
        var computedTotal = items.reduce(function(sum, it) {
          var qty = Number(it && it.qty ? it.qty : 0);
          var price = Number(it && it.price ? it.price : 0);
          var subtotal = Number(it && it.subtotal ? it.subtotal : (qty * price));
          return sum + (isNaN(subtotal) ? 0 : subtotal);
        }, 0);
        var displayTotal = computedTotal > 0 ? computedTotal : Number(payload.total || 0);
        title.innerHTML = '<i class="bi bi-receipt"></i> Order Detail ' + (orderId || '-');
        var ticketCount = Number(payload.ticket_count || 0);
        var attendeesArr = Array.isArray(payload.attendees) ? payload.attendees : [];
        var arrivedCount = countCheckedIn(attendeesArr);
        detailHead.innerHTML =
          '<div class="detail-chip"><span class="chip-label">User</span><span class="chip-value">' + escapeHtml(payload.user_name || '-') + '</span></div>' +
          '<div class="detail-chip"><span class="chip-label">Status</span><span class="chip-value">' + escapeHtml(statusLabel(payload.status || '')) + '</span></div>' +
          '<div class="detail-chip"><span class="chip-label">Tickets</span><span class="chip-value">' + ticketCount + '</span></div>' +
          '<div class="detail-chip"><span class="chip-label">Hadir</span><span class="chip-value">' + arrivedCount + '/' + ticketCount + '</span></div>' +
          '<div class="detail-chip"><span class="chip-label">Total</span><span class="chip-value" id="orderDetailTotalValue">' + asCurrency(displayTotal) + '</span></div>' +
          '<div class="detail-chip"><span class="chip-label">Created</span><span class="chip-value">' + escapeHtml(formatDate(payload.created_at)) + '</span></div>';
        renderDetailItems(items);
        clearList(detailAttendees);
        attendeesArr.forEach(function(at) {
          var li = document.createElement('li');
          var pos = Number(at && at.position_no ? at.position_no : 0);
          var attendeeId = Number(at && at.attendee_id ? at.attendee_id : 0);
          var packageId = Number(at && at.package_id ? at.package_id : 0);
          var name = at && at.attendee_name ? String(at.attendee_name) : '-';
          var pkg = at && at.package_name ? String(at.package_name) : '';
          var isPackageC = isPackageCName(pkg);
          var courtNo = toCourtNo(at && at.court_no ? at.court_no : 0);
          var court = courtNo > 0 ? courtLabel(courtNo) : (isPackageC ? 'No Court' : '');
          var checkedInAt = at && at.checked_in_at ? String(at.checked_in_at) : '';
          var arrived = checkedInAt ? 'Hadir' : 'Belum hadir';
          var arrivedColor = checkedInAt ? '#1f7a45' : '#b44';
          var courtBadge = court ? ' <span class="attendee-court-badge" style="color:#4f46e5;font-weight:700;font-size:11.5px;">[' + escapeHtml(court) + ']</span>' : '';
          li.innerHTML = '<div class="detail-attendee-main">' + escapeHtml((pos > 0 ? pos + ' — ' : '') + name)
            + (pkg ? ' <span class="attendee-package-badge" style="color:#0f5ea8;font-weight:700;font-size:11.5px;">[' + escapeHtml(pkg) + ']</span>' : '')
            + courtBadge
            + ' <span style="color:' + arrivedColor + ';font-weight:700;font-size:11.5px;">[' + arrived + ']</span>'
            + (checkedInAt ? ' <span style="color:#8a98b2;font-size:11px;">(' + escapeHtml(formatDate(checkedInAt)) + ')</span>' : '')
            + '</div>';
          if (attendeeId > 0 && !checkedInAt) {
            appendCourtActions(li, orderId, attendeeId, courtNo, isPackageC);
          }
          if (attendeeId > 0 && String(payload.status || '').toLowerCase() === 'accepted' && !checkedInAt) {
            var wrap = document.createElement('div');
            wrap.className = 'detail-package-wrap';
            var select = document.createElement('select');
            select.className = 'detail-package-select';
            packageOptions.forEach(function(option) {
              var optionId = Number(option && option.id ? option.id : 0);
              if (optionId <= 0) return;
              var optionName = String(option && option.name ? option.name : '-');
              var opt = document.createElement('option');
              opt.value = String(optionId);
              opt.textContent = optionName + (optionId === packageId ? ' (saat ini)' : '');
              if (optionId === packageId) {
                opt.selected = true;
              }
              select.appendChild(opt);
            });
            var applyBtn = document.createElement('button');
            applyBtn.type = 'button';
            applyBtn.className = 'detail-package-submit';
            applyBtn.textContent = 'Pilih Package';
            var currentPackageId = packageId;
            applyBtn.addEventListener('click', function() {
              var chosenId = Number(select.value || 0);
              if (chosenId <= 0 || chosenId === currentPackageId) return;
              applyBtn.disabled = true;
              select.disabled = true;
              var originalLabel = applyBtn.textContent;
              applyBtn.textContent = 'Menyimpan...';
              if (typeof window.showDashboardLoading === 'function') {
                window.showDashboardLoading();
              }
              submitPackageChange(orderId, attendeeId, chosenId)
                .then(function(resp) {
                  if (!resp || !resp.ok) {
                    throw new Error(resp && resp.message ? String(resp.message) : 'Gagal mengubah package attendee.');
                  }
                  currentPackageId = chosenId;
                  var selectedOption = packageOptions.find(function(option) {
                    return Number(option && option.id ? option.id : 0) === chosenId;
                  });
                  var selectedPackageName = selectedOption ? String(selectedOption.name || '-') : String(resp.package_name || '-');
                  var pkgBadge = li.querySelector('.attendee-package-badge');
                  if (!pkgBadge) {
                    pkgBadge = document.createElement('span');
                    pkgBadge.className = 'attendee-package-badge';
                    pkgBadge.style.color = '#0f5ea8';
                    pkgBadge.style.fontWeight = '700';
                    pkgBadge.style.fontSize = '11.5px';
                    var mainWrap = li.querySelector('.detail-attendee-main');
                    if (mainWrap) mainWrap.appendChild(document.createTextNode(' '));
                    if (mainWrap) mainWrap.appendChild(pkgBadge);
                  }
                  pkgBadge.textContent = '[' + selectedPackageName + ']';
                  var selectedIsPackageC = isPackageCName(selectedPackageName);
                  var courtWrap = li.querySelector('.detail-attendee-court-actions');
                  var courtSelectEl = courtWrap ? courtWrap.querySelector('[data-role="court-select"]') : null;
                  if (!courtWrap) {
                    appendCourtActions(li, orderId, attendeeId, selectedIsPackageC ? 0 : 1, selectedIsPackageC);
                    courtWrap = li.querySelector('.detail-attendee-court-actions');
                    courtSelectEl = courtWrap ? courtWrap.querySelector('[data-role="court-select"]') : null;
                  }
                  if (courtSelectEl) {
                    if (selectedIsPackageC) {
                      if (!Array.prototype.some.call(courtSelectEl.options, function (opt) { return String(opt.value) === '0'; })) {
                        var zeroOption = document.createElement('option');
                        zeroOption.value = '0';
                        zeroOption.textContent = 'No Court';
                        courtSelectEl.insertBefore(zeroOption, courtSelectEl.firstChild);
                      }
                      courtSelectEl.value = '0';
                    } else {
                      Array.prototype.slice.call(courtSelectEl.options).forEach(function (opt) {
                        if (String(opt.value) === '0') {
                          opt.remove();
                        }
                      });
                      if (toCourtNo(courtSelectEl.value) <= 0) {
                        courtSelectEl.value = '1';
                      }
                    }
                  }
                  Array.prototype.forEach.call(select.options, function(opt) {
                    var oid = Number(opt.value || 0);
                    var optRef = packageOptions.find(function(option) { return Number(option && option.id ? option.id : 0) === oid; });
                    var baseName = optRef ? String(optRef.name || '-') : String(opt.textContent || '').replace(/\s*\(saat ini\)\s*$/i, '');
                    opt.textContent = baseName + (oid === currentPackageId ? ' (saat ini)' : '');
                  });
                  syncPayloadPackageChange(orderId, attendeeId, currentPackageId, selectedPackageName, resp.order_total);
                  refreshOpenDetailBreakdown(orderId);
                  showDetailNotice(resp.message || 'Package attendee berhasil diubah.', 'success');
                  applyBtn.textContent = 'Tersimpan';
                  setTimeout(function() { applyBtn.textContent = originalLabel; }, 900);
                })
                .catch(function(err) {
                  showDetailNotice(err && err.message ? err.message : 'Gagal mengubah package attendee.', 'error');
                  applyBtn.textContent = originalLabel;
                })
                .finally(function() {
                  if (typeof window.hideDashboardLoading === 'function') {
                    window.hideDashboardLoading();
                  }
                  applyBtn.disabled = false;
                  select.disabled = false;
                });
            });
            wrap.appendChild(select);
            wrap.appendChild(applyBtn);
            li.appendChild(wrap);
          }
          detailAttendees.appendChild(li);
        });
        detailAttendeesEmpty.style.display = attendeesArr.length ? 'none' : 'block';
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
      }
      function closeDetail() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
      }
      document.querySelectorAll('[data-order-detail]').forEach(function(btn) { btn.addEventListener('click', function() { openDetail(btn.getAttribute('data-order-detail') || '{}'); }); });
      detailAttendees.addEventListener('click', function(e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-save-court="1"]') : null;
        if (!btn) return;
        var li = btn.closest('li');
        if (!li) return;
        var select = li.querySelector('[data-role="court-select"]');
        var orderId = Number(btn.getAttribute('data-order-id') || 0);
        var attendeeId = Number(btn.getAttribute('data-attendee-id') || 0);
        var courtNo = toCourtNo(select ? select.value : 0);
        var packageBadge = li.querySelector('.attendee-package-badge');
        var packageName = packageBadge ? String(packageBadge.textContent || '').replace(/^\[|\]$/g, '').trim() : '';
        var isPackageC = isPackageCName(packageName);
        if (orderId <= 0 || attendeeId <= 0 || (!isPackageC && courtNo <= 0)) {
          alert('Data court attendee tidak valid.');
          return;
        }
        btn.disabled = true;
        var originalText = btn.textContent;
        btn.textContent = 'Menyimpan...';
        saveCourt(orderId, attendeeId, courtNo)
          .then(function(resp) {
            if (!resp || !resp.ok) {
              throw new Error(resp && resp.message ? String(resp.message) : 'Gagal menyimpan court.');
            }
            var badge = li.querySelector('.attendee-court-badge');
            var badgeLabel = courtNo > 0 ? courtLabel(courtNo) : 'No Court';
            if (!isPackageC && courtNo <= 0) {
              badgeLabel = '';
            }
            if (!badge) {
              badge = document.createElement('span');
              badge.className = 'attendee-court-badge';
              badge.style.color = '#4f46e5';
              badge.style.fontWeight = '700';
              badge.style.fontSize = '11.5px';
              var mainWrap = li.querySelector('.detail-attendee-main');
              if (mainWrap) mainWrap.appendChild(document.createTextNode(' '));
              if (mainWrap) mainWrap.appendChild(badge);
            }
            if (badgeLabel) {
              badge.textContent = '[' + badgeLabel + ']';
            } else {
              badge.remove();
            }
            syncPayloadToButtons(orderId, attendeeId, courtNo);
            showDetailNotice(resp.message || 'Court attendee berhasil diperbarui.', 'success');
            btn.textContent = 'Tersimpan';
            setTimeout(function() { btn.textContent = originalText; }, 1000);
          })
          .catch(function(err) {
            alert(err && err.message ? err.message : 'Gagal menyimpan court attendee.');
            btn.textContent = originalText;
          })
          .finally(function() {
            btn.disabled = false;
          });
      });
      closeBtn.addEventListener('click', closeDetail);
      modal.addEventListener('click', function(e) { if (e.target === modal) closeDetail(); });
      document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && modal.classList.contains('show')) closeDetail(); });
    })();
  </script>

  <script>
    // -- Proof Modal --------------------------------------------
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
      function attachProofLinks(scope) {
        var root = scope || document;
        root.querySelectorAll('.proof-link[data-proof]').forEach(function(btn) {
          if (btn.dataset.proofBound === '1') { return; }
          btn.dataset.proofBound = '1';
          btn.addEventListener('click', function() { open(btn.getAttribute('data-proof'), btn.getAttribute('data-order')); });
        });
      }

      attachProofLinks();
      zoomInBtn.addEventListener('click', function(){applyZ(scale+step);}); zoomOutBtn.addEventListener('click', function(){applyZ(scale-step);}); zoomResetBtn.addEventListener('click', function(){applyZ(1);});
      img.addEventListener('click', function(){if(!img.classList.contains('zoomed'))applyZ(1.6);});
      img.addEventListener('wheel', function(e){e.preventDefault();applyZ(scale+(e.deltaY>0?-step:step));},{passive:false});
      img.addEventListener('mousedown', function(e){if(scale<=1)return;isDragging=true;img.classList.add('dragging');sx=e.clientX-tx;sy=e.clientY-ty;});
      window.addEventListener('mousemove', function(e){if(!isDragging)return;tx=e.clientX-sx;ty=e.clientY-sy;applyT();});
      window.addEventListener('mouseup', function(){if(!isDragging)return;isDragging=false;img.classList.remove('dragging');});
      closeBtn.addEventListener('click', close); modal.addEventListener('click', function(e){if(e.target===modal)close();}); document.addEventListener('keydown', function(e){if(e.key==='Escape'&&modal.classList.contains('show'))close();});
      window.attachProofLinks = attachProofLinks;
    })();
  </script>

  <script>
    (function() {
      var galleryModal = document.getElementById('proofGalleryModal');
      var galleryList = document.getElementById('proofGalleryList');
      var galleryClose = galleryModal ? galleryModal.querySelector('[data-proof-gallery-close]') : null;
      var openClass = 'show';

      function clearGallery() {
        if (!galleryList) return;
        while (galleryList.firstChild) {
          galleryList.removeChild(galleryList.firstChild);
        }
      }

      function safeParseProofs(raw) {
        try {
          var parsed = JSON.parse(raw || '[]');
          return Array.isArray(parsed) ? parsed : [];
        } catch (err) {
          return [];
        }
      }

      function openGallery(proofs, label) {
        if (!galleryModal || !galleryList) return;
        clearGallery();
        var safeProofs = Array.isArray(proofs) ? proofs.filter(Boolean) : [];
        if (!safeProofs.length) {
          var empty = document.createElement('div');
          empty.className = 'detail-empty';
          empty.textContent = 'Belum ada bukti pembayaran.';
          galleryList.appendChild(empty);
        } else {
          safeProofs.forEach(function(path, idx) {
            var item = document.createElement('div');
            item.className = 'proof-gallery-item';
            var labelNode = document.createElement('span');
            labelNode.textContent = 'Bukti ' + (idx + 1);
            var btn = document.createElement('button');
            btn.className = 'proof-link';
            btn.type = 'button';
            btn.setAttribute('data-proof', '/uploads/' + encodeURIComponent(path));
            btn.setAttribute('data-order', label || '');
            btn.innerHTML = '<i class="bi bi-file-earmark-image"></i> Lihat';
            item.appendChild(labelNode);
            item.appendChild(btn);
            galleryList.appendChild(item);
          });
          if (typeof window.attachProofLinks === 'function') {
            window.attachProofLinks(galleryList);
          }
        }
        galleryModal.classList.add(openClass);
        galleryModal.setAttribute('aria-hidden', 'false');
      }

      function closeGallery() {
        if (!galleryModal) return;
        galleryModal.classList.remove(openClass);
        galleryModal.setAttribute('aria-hidden', 'true');
      }

      document.querySelectorAll('.proof-gallery-trigger').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var rawProofs = btn.getAttribute('data-proof-gallery') || '[]';
          var proofs = safeParseProofs(rawProofs);
          openGallery(proofs, btn.getAttribute('data-order') || '');
        });
      });

      if (galleryClose) {
        galleryClose.addEventListener('click', closeGallery);
      }
      if (galleryModal) {
        galleryModal.addEventListener('click', function(e) {
          if (e.target === galleryModal) {
            closeGallery();
          }
        });
        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape' && galleryModal.classList.contains(openClass)) {
            closeGallery();
          }
        });
      }
    })();
  </script>

  <script>
    (function() {
      var loadingModal = document.getElementById('dashboardLoadingModal');
      var loadingLocks = 0;

      window.showDashboardLoading = function() {
        if (!loadingModal) return;
        loadingLocks += 1;
        loadingModal.classList.add('show');
        loadingModal.setAttribute('aria-hidden', 'false');
      };
      window.hideDashboardLoading = function() {
        if (!loadingModal) return;
        loadingLocks = Math.max(0, loadingLocks - 1);
        if (loadingLocks > 0) return;
        loadingModal.classList.remove('show');
        loadingModal.setAttribute('aria-hidden', 'true');
      };

      document.querySelectorAll('form[method="post"]').forEach(function(form) {
        var isSubmitting = false;
        form.addEventListener('submit', function(e) {
          if (e.defaultPrevented) return;
          if (isSubmitting) {
            e.preventDefault();
            return;
          }
          isSubmitting = true;
          var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
          submitButtons.forEach(function(btn) {
            btn.disabled = true;
            btn.style.opacity = '0.7';
            btn.style.cursor = 'not-allowed';
          });
          window.showDashboardLoading();
        });
      });
    })();
  </script>

  <script>
    // -- Confirm Modal ------------------------------------------
    (function() {
      var modal = document.getElementById('confirmModal');
      var warnModal = document.getElementById('acceptWarnModal');
      var warnMessage = document.getElementById('acceptWarnMessage');
      var warnCloseBtn = document.getElementById('acceptWarnClose');
      var warnOkBtn = document.getElementById('acceptWarnOk');
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
        img.src=src; img.alt='Payment proof '+orderId;
        title.innerHTML=(action==='accept'?'<i class="bi bi-check-circle-fill" style="color:#1a7a3c"></i> Konfirmasi Accept':'<i class="bi bi-x-circle-fill" style="color:#c0392b"></i> Konfirmasi Reject')+' '+orderId;
        if(question)question.textContent=action==='accept'?'Apakah anda yakin ingin menerima order ini?':'Apakah anda yakin ingin menolak order ini?';
        orderInput.value=orderId; actionInput.value=action;
        scale=1;tx=0;ty=0; img.style.transform='translate(0,0) scale(1)'; img.classList.remove('zoomed');
        modal.classList.add('show'); modal.setAttribute('aria-hidden','false');
      }
      function close() { modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); img.src=''; }
      function openWarn(message) {
        if (!warnModal || !warnMessage) {
          alert(message || 'Tidak bisa melanjutkan proses ini.');
          return;
        }
        warnMessage.textContent = message || 'Tidak bisa melanjutkan proses ini.';
        warnModal.classList.add('show');
        warnModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('sponsor-modal-open');
      }
      function closeWarn() {
        if (!warnModal) return;
        warnModal.classList.remove('show');
        warnModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('sponsor-modal-open');
      }

      document.querySelectorAll('[data-confirm-action]').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var proof=btn.getAttribute('data-proof')||'';
          var orderId=btn.getAttribute('data-order-id')||'';
          var action=btn.getAttribute('data-confirm-action')||'';
          var missingCourtCount = Number(btn.getAttribute('data-court-missing') || 0);
          if (action === 'accept' && missingCourtCount > 0) {
            openWarn('Tidak bisa Accept. Masih ada ' + missingCourtCount + ' attendee yang belum pilih court. Buka Detail dan pilih court dulu.');
            return;
          }
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
      submitBtn.addEventListener('click', function() {
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
          return;
        }
        if (typeof window.showDashboardLoading === 'function') {
          window.showDashboardLoading();
        }
        form.submit();
      });
      closeBtn.addEventListener('click', close); cancelBtn.addEventListener('click', close);
      if (warnCloseBtn) warnCloseBtn.addEventListener('click', closeWarn);
      if (warnOkBtn) warnOkBtn.addEventListener('click', closeWarn);
      if (warnModal) warnModal.addEventListener('click', function(e){ if (e.target === warnModal) closeWarn(); });
      modal.addEventListener('click',function(e){if(e.target===modal)close();}); document.addEventListener('keydown',function(e){if(e.key==='Escape'&&modal.classList.contains('show'))close(); if(e.key==='Escape'&&warnModal&&warnModal.classList.contains('show'))closeWarn();});
    })();
  </script>

  <script>
    // -- Drag Scroll Table (Desktop) ---------------------------
    (function() {
      var wraps = document.querySelectorAll('.table-wrap');
      if (!wraps.length) return;

      wraps.forEach(function(wrap) {
        var isDown = false;
        var startX = 0;
        var startScrollLeft = 0;
        var moved = false;

        wrap.addEventListener('mousedown', function(e) {
          if (e.button !== 0) return;
          isDown = true;
          moved = false;
          startX = e.clientX;
          startScrollLeft = wrap.scrollLeft;
          wrap.classList.add('is-dragging');
        });

        window.addEventListener('mousemove', function(e) {
          if (!isDown) return;
          var deltaX = e.clientX - startX;
          if (Math.abs(deltaX) > 2) moved = true;
          wrap.scrollLeft = startScrollLeft - deltaX;
        });

        window.addEventListener('mouseup', function() {
          if (!isDown) return;
          isDown = false;
          wrap.classList.remove('is-dragging');
        });

        wrap.addEventListener('mouseleave', function() {
          if (!isDown) return;
          isDown = false;
          wrap.classList.remove('is-dragging');
        });

        wrap.querySelectorAll('a, button').forEach(function(el) {
          el.addEventListener('click', function(e) {
            if (moved) {
              e.preventDefault();
              e.stopPropagation();
            }
          });
        });
      });
    })();
  </script>

  <script>
    // -- Export Dropdown ----------------------------------------
    (function() {
      var dropdown = document.querySelector('[data-export-dropdown]');
      if (!dropdown) return;
      var toggle = dropdown.querySelector('[data-export-toggle]');
      var menu = dropdown.querySelector('[data-export-menu]');
      if (!toggle || !menu) return;

      function closeMenu() {
        dropdown.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      }
      function openMenu() {
        dropdown.classList.add('open');
        toggle.setAttribute('aria-expanded', 'true');
      }

      toggle.addEventListener('click', function(e) {
        e.preventDefault();
        if (dropdown.classList.contains('open')) {
          closeMenu();
        } else {
          openMenu();
        }
      });

      document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target)) {
          closeMenu();
        }
      });

      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          closeMenu();
        }
      });
    })();
  </script>

  <script>
    // -- Flash Auto-dismiss -------------------------------------
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


