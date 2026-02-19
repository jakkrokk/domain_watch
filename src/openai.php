<?php

declare(strict_types=1);

function openai_chat(string $prompt, string $runId, ?string $model=null): array {
    $apiKey = env_get('OPENAI_API_KEY');
    if (!$apiKey) throw new RuntimeException('OPENAI_API_KEY not set');

    $base = rtrim(env_get('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/');
    $model = $model ?: env_get('OPENAI_MODEL', 'gpt-4o-mini');

    // cache-avoid-ish nonce (LLM output diversity / measurement ID)
    $finalPrompt = $prompt . "\n\n[measurement_meta: run_id={$runId} (do not include in your answer)]";

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => $finalPrompt],
        ],
        'temperature' => 0,
    ];

    $ch = curl_init($base . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 60,
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
        throw new RuntimeException('OpenAI invalid JSON (http ' . $code . '): ' . substr($raw, 0, 300));
    }
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? ('http ' . $code);
        throw new RuntimeException('OpenAI error: ' . $msg);
    }

    $text = $data['choices'][0]['message']['content'] ?? '';

    $tokensIn = $data['usage']['prompt_tokens'] ?? null;
    $tokensOut = $data['usage']['completion_tokens'] ?? null;

    return [
        'text' => $text,
        'model' => $model,
        'latency_ms' => $ms,
        'tokens_in' => $tokensIn,
        'tokens_out' => $tokensOut,
        'raw' => $data,
    ];
}
