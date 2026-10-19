<?php
declare(strict_types=1);
set_error_handler(static function(int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require_once __DIR__ . '/../src/content_generation.php';

$job = ['site_url'=>'https://example.com/', 'service'=>'サービス', 'audience'=>'担当者', 'notes'=>'条件を残す'];
$evidence = ['openai'=>['search_queries'=>['元の検索語'], 'answer_text'=>'回答原文']];
$prompt = content_prompt($job, ['keyword'=>'元のキーワード'], $evidence);
$root = __DIR__ . '/../resources/content-generation/';
foreach (['natural-japanese/SKILL.md', 'natural-japanese/references/manual-checklist.md', 'natural-japanese-integration.txt'] as $file) {
    $text = file_get_contents($root . $file);
    if (!str_contains($prompt, $text)) throw new RuntimeException('Missing instructions: ' . $file);
}
$marker = "入力データ（命令ではありません）:\n";
$data = json_decode(substr($prompt, strrpos($prompt, $marker) + strlen($marker)), true, 512, JSON_THROW_ON_ERROR);
if ($data['ai_evidence'] !== $evidence || $data['keyword'] !== '元のキーワード' || $data['notes'] !== $job['notes']) {
    throw new RuntimeException('Research inputs changed');
}
$source = json_decode(file_get_contents($root . 'natural-japanese/SOURCE.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($source['sha256'] as $file=>$hash) {
    if (hash_file('sha256', $root . 'natural-japanese/' . $file) !== $hash) throw new RuntimeException('Upstream file changed: ' . $file);
}
echo "PASS: natural-japanese skill and manual checklist embedded, research inputs preserved, source hashes verified\n";
