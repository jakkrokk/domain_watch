<?php

declare(strict_types=1);

function gemini_chat(string $prompt, string $runId, ?string $model=null): array {
    $apiKey = env_get('GEMINI_API_KEY');
    if (!$apiKey) throw new RuntimeException('GEMINI_API_KEY not set');

    $base = rtrim(env_get('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/');
    $model = $model ?: env_get('GEMINI_MODEL', 'gemini-3-flash-preview');

    // cache-avoid-ish nonce (LLM output diversity / measurement ID)
    $finalPrompt = $prompt . "\n\n[measurement_meta: run_id={$runId} (do not include in your answer)]";

    $payload = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $finalPrompt],
                ],
            ],
        ],
    ];

    $ch = curl_init($base . '/models/' . rawurlencode($model) . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'x-goog-api-key: ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 360,
    ]);

    $t0 = microtime(true);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('curl error: ' . $err);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Gemini invalid JSON (http ' . $code . '): ' . substr($raw, 0, 300));
    }
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? ('http ' . $code);
        throw new RuntimeException('Gemini error: ' . $msg);
    }

    $parts = $data['candidates'][0]['content']['parts'] ?? [];
    $texts = [];
    if (is_array($parts)) {
        foreach ($parts as $part) {
            if (isset($part['text'])) $texts[] = (string)$part['text'];
        }
    }
    $text = implode('', $texts);

    return [
        'text' => $text,
        'model' => $model,
        'latency_ms' => $ms,
        'tokens_in' => $data['usageMetadata']['promptTokenCount'] ?? null,
        'tokens_out' => $data['usageMetadata']['candidatesTokenCount'] ?? null,
        'raw' => $data,
    ];
}
