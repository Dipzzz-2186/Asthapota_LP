<?php
require_once __DIR__ . '/helpers.php';

function is_admin_logged_in() {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

function require_admin(): void {
    ensure_session();
    if (empty($_SESSION['admin_id'])) {
        redirect('/admin/login');
    }
}
