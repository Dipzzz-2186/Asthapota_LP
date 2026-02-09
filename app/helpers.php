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
