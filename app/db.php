<?php
require_once __DIR__ . '/config.php';

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
