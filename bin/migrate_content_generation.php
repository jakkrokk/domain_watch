<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit;
foreach (explode(';', file_get_contents(__DIR__ . '/../migrations/20261014_add_content_generation.sql')) as $sql) {
    if (trim($sql) !== '') db()->exec($sql);
}
if (!db_one("SHOW COLUMNS FROM content_jobs LIKE 'notes'")) {
    db()->exec(file_get_contents(__DIR__ . '/../migrations/20261014_add_content_notes.sql'));
}
echo "Content generation schema ready\n";
