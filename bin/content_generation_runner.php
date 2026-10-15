<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/content_generation.php';
if (PHP_SAPI !== 'cli') exit;
session_write_close();
set_time_limit(0);
umask(0077);
$lock = fopen(__DIR__ . '/../storage/content-generation.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
// One batch per cron invocation; long jobs never overlap.
$job = db_one("SELECT * FROM content_jobs WHERE status IN ('queued','running') ORDER BY id LIMIT 1");
if (!$job) exit;
try { content_process($job); }
catch (Throwable $e) {
    error_log('Content job ' . $job['id'] . ': ' . $e->getMessage());
    db_exec("UPDATE content_jobs SET status='failed',stage='作成に失敗しました',error_message='Excelの作成処理でエラーが発生しました。管理者にお問い合わせください。',finished_at=NOW() WHERE id=?", [$job['id']]);
    exit(1);
}
echo 'Processed content job ' . $job['id'] . "\n";
