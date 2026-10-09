<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/shots.php';
if (PHP_SAPI !== 'cli') exit("cli only\n");
session_write_close();
set_time_limit(0);
$lock = fopen(__DIR__ . '/../storage/shots.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
try {
    while ($shot = db_one("SELECT * FROM shots WHERE status IN ('queued','running') ORDER BY id LIMIT 1")) {
        shot_process($shot);
        echo 'Processed shot ' . $shot['id'] . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
