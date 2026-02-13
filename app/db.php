<?php
require_once __DIR__ . '/config.php';

// Keep all PHP-side timestamps (order/check-in/etc.) in one app timezone.
if (!empty($CONFIG['app_timezone']) && is_string($CONFIG['app_timezone'])) {
    date_default_timezone_set($CONFIG['app_timezone']);
}

function get_db(): PDO {
    static $db = null;
    if ($db) return $db;

    global $CONFIG;
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $CONFIG['db_host'],
        $CONFIG['db_name'],
        $CONFIG['db_charset']
    );
    $db = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
}
