<?php
$filePath = __DIR__ . '/../assets/img/qris_payment.jpeg';

if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'QR file not found.';
    exit;
}

$fileSize = filesize($filePath);
if ($fileSize === false) {
    $fileSize = 0;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: image/jpeg');
header('Content-Disposition: attachment; filename="qris_payment.jpeg"');
header('Content-Length: ' . (int)$fileSize);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($filePath);
exit;
