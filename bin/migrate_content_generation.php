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
foreach (explode(';', file_get_contents(__DIR__ . '/../migrations/20261019_add_content_ai_prompts.sql')) as $sql) {
    $sql = trim($sql);
    if ($sql === '') continue;
    $table = str_contains($sql, 'content_jobs') ? 'content_jobs' : 'content_items';
    if (!db_one("SHOW COLUMNS FROM $table LIKE 'ai_prompts'")) db()->exec($sql);
}
foreach (explode(';', file_get_contents(__DIR__ . '/../migrations/20261020_add_content_rewrite.sql')) as $sql) {
    $sql = trim($sql);
    if ($sql === '') continue;
    $column = str_contains($sql, 'ADD COLUMN mode ') ? 'mode' : 'reference_url';
    if (!db_one("SHOW COLUMNS FROM content_jobs LIKE '$column'")) db()->exec($sql);
}
echo "Content generation schema ready\n";
