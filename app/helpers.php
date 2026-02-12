<?php
function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function rupiah(int $amount): string {
    return 'IDR ' . number_format($amount, 0, ',', '.');
}

function ensure_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

function smtp_send(string $to, string $subject, string $htmlBody): bool {
    global $CONFIG;
    $host = $CONFIG['smtp_host'] ?? '';
    $port = (int)($CONFIG['smtp_port'] ?? 587);
    $user = $CONFIG['smtp_user'] ?? '';
    $pass = $CONFIG['smtp_pass'] ?? '';
    $from = $CONFIG['smtp_from'] ?? $user;
    $fromName = $CONFIG['smtp_from_name'] ?? 'No-Reply';

    if (!$host || !$user || !$pass || !$from) {
        return false;
    }

    $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
    if (!$socket) return false;

    $read = function () use ($socket) {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $write = function (string $cmd) use ($socket) {
        fwrite($socket, $cmd . "\r\n");
    };

    $read();
    $write('EHLO ' . gethostname());
    $read();
    $write('STARTTLS');
    $read();

    $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    if (!$crypto) return false;

    $write('EHLO ' . gethostname());
    $read();
    $write('AUTH LOGIN');
    $read();
    $write(base64_encode($user));
    $read();
    $write(base64_encode($pass));
    $auth = $read();
    if (strpos($auth, '235') === false) return false;

    $write('MAIL FROM: <' . $from . '>');
    $read();
    $write('RCPT TO: <' . $to . '>');
    $read();
    $write('DATA');
    $read();

    $headers = [];
    $headers[] = 'From: ' . $fromName . ' <' . $from . '>';
    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Subject: ' . $subject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.";
    $write($message);
    $read();
    $write('QUIT');
    fclose($socket);
    return true;
}

function send_otp_email(string $email, string $otp): bool {
    $subject = 'Asthapora - Kode Verifikasi OTP';
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $body = '
      <div style="font-family:Arial,Helvetica,sans-serif;background:#f4f7ff;padding:24px;">
        <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:16px;box-shadow:0 8px 20px rgba(12,27,54,0.12);overflow:hidden;">
          <div style="background:#1e5ed8;color:#ffffff;padding:18px 22px;font-size:18px;font-weight:700;">
            Asthapora
          </div>
          <div style="padding:22px;">
            <p style="margin:0 0 10px;font-size:15px;color:#0c1b36;">Halo,</p>
            <p style="margin:0 0 16px;font-size:15px;color:#5a6b86;">Gunakan kode OTP berikut untuk melanjutkan pendaftaran:</p>
            <div style="font-size:26px;letter-spacing:6px;font-weight:700;color:#1e5ed8;background:#eef4ff;border:1px solid #cfe0ff;padding:12px 16px;border-radius:12px;text-align:center;">
              ' . $safeOtp . '
            </div>
            <p style="margin:16px 0 0;font-size:13px;color:#5a6b86;">Kode ini berlaku 10 menit. Jika kamu tidak meminta kode ini, abaikan email ini.</p>
          </div>
        </div>
      </div>
    ';
    return smtp_send($email, $subject, $body);
}

function send_invoice_email(array $order, array $items, string $toEmail): bool {
    $subject = 'Asthapora - Invoice Order #' . (int)$order['id'];

    $rows = '';
    foreach ($items as $it) {
        $qty = (int)$it['qty'];
        $name = htmlspecialchars($it['name'], ENT_QUOTES, 'UTF-8');
        $price = rupiah((int)$it['price']);
        $rows .= '<tr>
          <td style="padding:8px 0;border-bottom:1px solid #e6ecf8;">' . $name . '</td>
          <td style="padding:8px 0;border-bottom:1px solid #e6ecf8;text-align:center;">' . $qty . '</td>
          <td style="padding:8px 0;border-bottom:1px solid #e6ecf8;text-align:right;">' . $price . '</td>
        </tr>';
    }

    $body = '
      <div style="margin:0;padding:0;background:#eef3ff;font-family:Arial,Helvetica,sans-serif;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#eef3ff;padding:24px 12px;">
          <tr>
            <td align="center">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #dbe6ff;">
                <tr>
                  <td style="background:linear-gradient(135deg,#1658ad 0%,#1e5ed8 100%);padding:22px 24px;color:#ffffff;">
                    <div style="font-size:12px;letter-spacing:1.2px;text-transform:uppercase;opacity:0.9;">Asthapora Ticketing</div>
                    <div style="font-size:24px;font-weight:700;line-height:1.25;margin-top:6px;">Invoice Temu Padel 2026</div>
                    <div style="font-size:13px;line-height:1.5;margin-top:8px;opacity:0.95;">A Monkeybar x BAPORA Event | 28 Februari 2026, 16:00 - 18:00 WIB</div>
                  </td>
                </tr>
                <tr>
                  <td style="padding:24px;">
                    <p style="margin:0 0 8px;font-size:15px;color:#0c1b36;">Halo ' . htmlspecialchars($order['full_name'], ENT_QUOTES, 'UTF-8') . ',</p>
                    <p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#51627f;">Terima kasih, order tiket kamu sudah tercatat. Saat ini pesanan kamu ada di tahap <strong style="color:#0c1b36;">paid</strong> (pembayaran diterima), belum masuk keputusan <strong style="color:#0c1b36;">accepted</strong> atau <strong style="color:#0c1b36;">rejected</strong>.</p>

                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 14px;background:#f6f9ff;border:1px solid #d8e5ff;border-radius:12px;">
                      <tr>
                        <td style="padding:14px 16px;">
                          <div style="font-size:12px;color:#5a6b86;letter-spacing:0.4px;text-transform:uppercase;">Order ID</div>
                          <div style="font-size:20px;font-weight:700;color:#0c1b36;line-height:1.35;">#' . (int)$order['id'] . '</div>
                          <div style="font-size:13px;color:#5a6b86;margin-top:6px;">Status saat ini: Paid (menunggu konfirmasi)</div>
                        </td>
                      </tr>
                    </table>

                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 14px;background:#eaf3ff;border:1px solid #c8dcff;border-radius:12px;">
                      <tr>
                        <td style="padding:12px 16px;font-size:13px;line-height:1.6;color:#1f3d72;">
                          <strong>Info Lanjutan:</strong> Mohon tunggu email berikutnya dari kami untuk informasi final apakah pesanan kamu <strong>accepted</strong> atau <strong>rejected</strong>.
                        </td>
                      </tr>
                    </table>

                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 14px;background:#fff8e8;border:1px solid #ffe2ac;border-radius:12px;">
                      <tr>
                        <td style="padding:12px 16px;font-size:13px;line-height:1.6;color:#6b4d1f;">
                          <strong>Informasi Event:</strong> Tiket yang kamu pesan berlaku untuk <strong>Temu Padel 2026</strong> pada <strong>28 Februari 2026</strong> pukul <strong>16:00 - 18:00 WIB</strong>.
                        </td>
                      </tr>
                    </table>

                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;font-size:14px;color:#0c1b36;">
                      <thead>
                        <tr>
                          <th align="left" style="text-align:left;padding:10px 0;border-bottom:1px solid #e6ecf8;font-size:12px;letter-spacing:0.4px;text-transform:uppercase;color:#5a6b86;">Item Ticket</th>
                          <th align="center" style="text-align:center;padding:10px 0;border-bottom:1px solid #e6ecf8;font-size:12px;letter-spacing:0.4px;text-transform:uppercase;color:#5a6b86;">Qty</th>
                          <th align="right" style="text-align:right;padding:10px 0;border-bottom:1px solid #e6ecf8;font-size:12px;letter-spacing:0.4px;text-transform:uppercase;color:#5a6b86;">Harga</th>
                        </tr>
                      </thead>
                      <tbody>' . $rows . '</tbody>
                    </table>

                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:16px;">
                      <tr>
                        <td style="font-size:14px;color:#0c1b36;font-weight:700;">Total Pembayaran</td>
                        <td align="right" style="font-size:20px;color:#1658ad;font-weight:800;">' . rupiah((int)$order['total']) . '</td>
                      </tr>
                    </table>

                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:16px;background:#f8fbff;border:1px dashed #c6d9ff;border-radius:12px;">
                      <tr>
                        <td style="padding:12px 16px;font-size:13px;line-height:1.6;color:#4e5f7b;">
                          Pembayaran: <strong>BCA 1234567890</strong> a.n. <strong>PT Manifestasi Kehidupan Berlimpah</strong><br>
                          Simpan email ini sebagai bukti transaksi dan tunjukkan saat diperlukan oleh panitia.
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </div>
    ';

    return smtp_send($toEmail, $subject, $body);
}

function send_order_status_email(array $order, string $toEmail): bool {
    $statusRaw = $order['status'] ?? 'pending';
    $statusLabel = match ($statusRaw) {
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'paid' => 'Payment Received',
        default => ucfirst((string)$statusRaw),
    };
    $titleText = match ($statusRaw) {
        'accepted' => 'Pesanan Kamu Diterima',
        'rejected' => 'Pesanan Kamu Ditolak',
        default => 'Informasi Pesanan Kamu',
    };
    $introText = match ($statusRaw) {
        'accepted' => 'Selamat, pesanan tiket Temu Padel 2026 kamu sudah dikonfirmasi panitia.',
        'rejected' => 'Mohon maaf, pesanan tiket Temu Padel 2026 kamu belum dapat kami proses.',
        default => 'Ada informasi terbaru terkait pesanan tiket kamu.',
    };
    $statusCardColor = $statusRaw === 'accepted'
        ? 'background:#eaf8ef;border:1px solid #b9e7c8;'
        : ($statusRaw === 'rejected'
            ? 'background:#fff1f1;border:1px solid #ffd0d0;'
            : 'background:#eef4ff;border:1px solid #cfe0ff;');
    $mapsUrl = 'https://www.google.com/maps/search/?api=1&query='
        . rawurlencode('MY PADEL, Jl. Jelupang Utama, Kec. Serpong Utara, Kota Tangerang Selatan');
    $eventDetails = $statusRaw === 'accepted'
        ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:14px;background:#fff8e8;border:1px solid #ffe2ac;border-radius:12px;">
             <tr>
               <td style="padding:12px 14px;font-size:13px;line-height:1.6;color:#6b4d1f;">
                 <div style="font-weight:700;color:#4f3a18;margin-bottom:6px;">Detail Event Temu Padel 2026</div>
                  <div><strong>Tanggal:</strong> 28 Februari 2026</div>
                  <div><strong>Waktu:</strong> 16:00 - 18:00 WIB</div>
                  <div><strong>Lokasi:</strong> MY PADEL</div>
                  <div><strong>Alamat:</strong> Jl. Jelupang Utama, Kec. Serpong Utara, Kota Tangerang Selatan</div>
                  <div style="margin-top:12px;">
                    <a href="' . htmlspecialchars($mapsUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#1e5ed8;color:#ffffff;text-decoration:none;font-weight:700;font-size:12px;padding:10px 14px;border-radius:10px;">Buka di Google Maps</a>
                  </div>
                </td>
              </tr>
            </table>
            <p style="margin:14px 0 0;font-size:13px;color:#5a6b86;">Silakan hadir 15-30 menit lebih awal untuk proses check-in.</p>'
        : '';
    $rejectNote = $statusRaw === 'rejected'
        ? '<p style="margin:14px 0 0;font-size:13px;line-height:1.6;color:#6d3640;">Pesanan ini dinyatakan <strong>ditolak</strong>. Jika kamu butuh bantuan atau ingin melakukan pemesanan ulang, silakan hubungi tim panitia.</p>'
        : '';

    $subject = 'Asthapora - Order #' . (int)$order['id'] . ' ' . $statusLabel;
    $body = '
      <div style="font-family:Arial,Helvetica,sans-serif;background:#f4f7ff;padding:24px;">
        <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:16px;box-shadow:0 8px 20px rgba(12,27,54,0.12);overflow:hidden;">
          <div style="background:#1e5ed8;color:#ffffff;padding:18px 22px;font-size:18px;font-weight:700;">
            Asthapora - Temu Padel 2026
          </div>
          <div style="padding:22px;">
            <p style="margin:0 0 10px;font-size:15px;color:#0c1b36;">Halo ' . htmlspecialchars($order['full_name'] ?? '', ENT_QUOTES, 'UTF-8') . ',</p>
            <p style="margin:0 0 10px;font-size:16px;font-weight:700;color:#0c1b36;">' . htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') . '</p>
            <p style="margin:0 0 14px;font-size:14px;color:#5a6b86;line-height:1.6;">' . htmlspecialchars($introText, ENT_QUOTES, 'UTF-8') . '</p>
            <div style="' . $statusCardColor . 'border-radius:12px;padding:12px 14px;">
              <div style="font-size:13px;color:#5a6b86;">Order ID</div>
              <div style="font-size:18px;font-weight:700;color:#0c1b36;">#' . (int)$order['id'] . '</div>
              <div style="font-size:13px;color:#5a6b86;margin-top:6px;">Status: ' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</div>
            </div>
            ' . $eventDetails . '
            ' . $rejectNote . '
            <p style="margin:14px 0 0;font-size:13px;color:#5a6b86;">Terima kasih sudah berpartisipasi di Asthapora.</p>
          </div>
        </div>
      </div>
    ';

    return smtp_send($toEmail, $subject, $body);
}

