<?php
require_once __DIR__ . '/helpers.php';

function require_admin(): void {
    ensure_session();
    if (empty($_SESSION['admin_id'])) {
        redirect('/admin/login.php');
    }
}
