<?php
require_once __DIR__ . '/../app/db.php';

function prompt(string $label, bool $hidden = false): string {
    if ($hidden && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        echo $label;
        system('stty -echo');
        $value = trim(fgets(STDIN));
        system('stty echo');
        echo PHP_EOL;
        return $value;
    }
    echo $label;
    return trim(fgets(STDIN));
}

$email = $argv[1] ?? '';
$password = $argv[2] ?? '';

if ($email === '') {
    $email = prompt('Email admin: ');
}
if ($password === '') {
    $password = prompt('Password admin: ');
}

if ($email === '' || $password === '') {
    fwrite(STDERR, "Email/password wajib diisi.\n");
    exit(1);
}

try {
    $db = get_db();
    $stmt = $db->prepare('SELECT id FROM admins WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        fwrite(STDERR, "Admin sudah ada.\n");
        exit(1);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = $db->prepare('INSERT INTO admins (email, password_hash, created_at) VALUES (?, ?, ?)');
    $insert->execute([$email, $hash, date('Y-m-d H:i:s')]);

    echo "Admin created: {$email}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
