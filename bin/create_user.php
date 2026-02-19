<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (php_sapi_name() !== 'cli') { exit("cli only\n"); }

$email = $argv[1] ?? null;
$pass = $argv[2] ?? null;
if (!$email || !$pass) {
    fwrite(STDERR, "usage: php bin/create_user.php email password\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "invalid email\n");
    exit(1);
}

$hash = password_hash($pass, PASSWORD_DEFAULT);

db_exec('INSERT INTO users(email,password_hash) VALUES(?,?)', [$email, $hash]);
$uid = (int)db()->lastInsertId();

echo "created user id={$uid} email={$email}\n";
