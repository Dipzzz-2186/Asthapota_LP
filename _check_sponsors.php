<?php
require __DIR__ . '/app/db.php';
$db = get_db();
try {
    $cnt = (int)$db->query('SELECT COUNT(*) FROM sponsors')->fetchColumn();
    echo 'sponsors_count=' . $cnt . PHP_EOL;
    $rows = $db->query('SELECT id, name, logo_path, website_url FROM sponsors ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo (int)$r['id'] . ' | ' . (string)$r['name'] . ' | ' . (string)$r['logo_path'] . PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'db_error=' . $e->getMessage() . PHP_EOL;
}
