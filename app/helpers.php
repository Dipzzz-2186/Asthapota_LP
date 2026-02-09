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
    $subject = 'Asthapora OTP Verification';
    $body = '<p>Halo,</p><p>Kode OTP kamu: <strong>' . htmlspecialchars($otp) . '</strong></p><p>Kode ini berlaku 10 menit.</p>';
    return smtp_send($email, $subject, $body);
}
