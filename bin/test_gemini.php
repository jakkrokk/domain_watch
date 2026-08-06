<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (php_sapi_name() !== 'cli') { exit("cli only\n"); }

$prompt = $argv[1] ?? '短く一言で、Gemini APIの疎通確認と返してください。';
$runId = 'gemini-test-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));

try {
    $resp = gemini_chat($prompt, $runId);
    echo 'model=' . $resp['model'] . "\n";
    echo 'latency_ms=' . $resp['latency_ms'] . "\n";
    echo 'tokens_in=' . ($resp['tokens_in'] ?? '') . "\n";
    echo 'tokens_out=' . ($resp['tokens_out'] ?? '') . "\n";
    echo "text:\n" . trim((string)$resp['text']) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
