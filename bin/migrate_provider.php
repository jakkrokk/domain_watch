<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("cli only\n");
$lock = fopen(__DIR__ . '/../storage/runner.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Runner is active; retry after completion');
$counts = [];
foreach (['runs', 'daily_logs'] as $table) {
    $counts[$table] = (int)db_one("SELECT COUNT(*) AS n FROM {$table}")['n'];
}
$sql = file_get_contents(__DIR__ . '/../migrations/20261001_add_provider.sql');
foreach (explode(';', $sql) as $statement) {
    $statement = trim($statement);
    if ($statement === '') continue;
    if (!preg_match('/ALTER TABLE (runs|daily_logs)/', $statement, $match)) throw new RuntimeException('Unexpected migration statement');
    $table = $match[1];
    if (db_one("SHOW COLUMNS FROM {$table} LIKE 'provider'")) {
        echo "{$table}: already migrated\n";
        continue;
    }
    db()->exec($statement);
    echo "{$table}: migrated\n";
}
// Also complete an earlier provider migration made before suggestions were added.
if (!db_one("SHOW COLUMNS FROM daily_logs LIKE 'search_suggestions'")) {
    db()->exec('ALTER TABLE daily_logs ADD COLUMN search_suggestions LONGTEXT NULL AFTER response_text');
    echo "daily_logs: search suggestions added\n";
}
foreach ($counts as $table => $count) {
    $after = (int)db_one("SELECT COUNT(*) AS n FROM {$table}")['n'];
    if ($after !== $count) throw new RuntimeException("Row count changed for {$table}");
    echo "{$table}: preserved {$after} rows\n";
}
