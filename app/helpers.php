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
    $subject = 'Temu Padel - Invoice Order #' . (int)$order['id'];

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
      <div style="font-family:Arial,Helvetica,sans-serif;background:#f4f7ff;padding:24px;">
        <div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:16px;box-shadow:0 8px 20px rgba(12,27,54,0.12);overflow:hidden;">
          <div style="background:#1e5ed8;color:#ffffff;padding:18px 22px;font-size:18px;font-weight:700;">
            Temu Padel - Invoice
          </div>
          <div style="padding:22px;">
            <p style="margin:0 0 8px;font-size:15px;color:#0c1b36;">Halo ' . htmlspecialchars($order['full_name'], ENT_QUOTES, 'UTF-8') . ',</p>
            <p style="margin:0 0 16px;font-size:14px;color:#5a6b86;">Terima kasih sudah melakukan order. Berikut detail pesanan kamu.</p>

            <div style="background:#eef4ff;border:1px solid #cfe0ff;border-radius:12px;padding:12px 14px;margin-bottom:14px;">
              <div style="font-size:13px;color:#5a6b86;">Order ID</div>
              <div style="font-size:18px;font-weight:700;color:#0c1b36;">#' . (int)$order['id'] . '</div>
              <div style="font-size:13px;color:#5a6b86;margin-top:6px;">Status: ' . htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') . '</div>
            </div>

            <table style="width:100%;border-collapse:collapse;font-size:14px;color:#0c1b36;">
              <thead>
                <tr>
                  <th style="text-align:left;padding:8px 0;border-bottom:1px solid #e6ecf8;">Item</th>
                  <th style="text-align:center;padding:8px 0;border-bottom:1px solid #e6ecf8;">Qty</th>
                  <th style="text-align:right;padding:8px 0;border-bottom:1px solid #e6ecf8;">Price</th>
                </tr>
              </thead>
              <tbody>' . $rows . '</tbody>
            </table>

            <div style="margin-top:16px;display:flex;justify-content:space-between;font-size:16px;font-weight:700;">
              <span>Total</span>
              <span>' . rupiah((int)$order['total']) . '</span>
            </div>

            <div style="margin-top:16px;font-size:13px;color:#5a6b86;">
              Pembayaran: BCA 1234567890 a.n. PT Manifestasi Kehidupan Berlimpah
            </div>
          </div>
        </div>
      </div>
    ';

    return smtp_send($toEmail, $subject, $body);
}
