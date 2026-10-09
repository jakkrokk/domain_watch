<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("cli only\n");
$sql = file_get_contents(__DIR__ . '/../migrations/20261002_add_shots.sql');
foreach (explode(';', $sql) as $statement) {
    if (trim($statement) !== '') db()->exec($statement);
}
echo "Shots schema ready\n";
