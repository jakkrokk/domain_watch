<?php

declare(strict_types=1);

function openai_env_enabled(string $key, bool $default=false): bool {
    $fallback = $default ? '1' : '0';
    $value = strtolower(trim((string)env_get($key, $fallback)));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function openai_unique_strings(array $values): array {
    $seen = [];
    $out = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value === '' || isset($seen[$value])) continue;
        $seen[$value] = true;
        $out[] = $value;
    }
    return $out;
}

function openai_format_web_search_text(
    string $answerText,
    array $searchQueries,
    array $sourceUrls,
    array $citationUrls
): string {
    $formatLines = static function(array $values): string {
        return $values ? implode("\n", $values) : '（取得なし）';
    };

    return trim($answerText)
        . "\n\nAIが生成した検索クエリ\n" . $formatLines($searchQueries)
        . "\n\n参照したURL\n" . $formatLines($sourceUrls)
        . "\n\n回答で実際に引用したURL\n" . $formatLines($citationUrls);
}

function openai_parse_responses_output(array $data): array {
    $answerParts = [];
    $searchQueries = [];
    $sourceUrls = [];
    $citationUrls = [];

    foreach (($data['output'] ?? []) as $item) {
        if (!is_array($item)) continue;

        if (($item['type'] ?? '') === 'web_search_call') {
            $action = $item['action'] ?? [];
            if (!is_array($action)) continue;

            foreach (($action['queries'] ?? []) as $query) {
                $searchQueries[] = $query;
            }
            if (isset($action['query'])) {
                $searchQueries[] = $action['query'];
            }
            foreach (($action['sources'] ?? []) as $source) {
                if (is_array($source) && isset($source['url'])) {
                    $sourceUrls[] = $source['url'];
                }
            }
            continue;
        }

        if (($item['type'] ?? '') !== 'message') continue;
        foreach (($item['content'] ?? []) as $content) {
            if (!is_array($content) || ($content['type'] ?? '') !== 'output_text') continue;
            if (isset($content['text'])) {
                $answerParts[] = (string)$content['text'];
            }
            foreach (($content['annotations'] ?? []) as $annotation) {
                if (
                    is_array($annotation)
                    && ($annotation['type'] ?? '') === 'url_citation'
                    && isset($annotation['url'])
                ) {
                    $citationUrls[] = $annotation['url'];
                }
            }
        }
    }

    return [
        'answer_text' => trim(implode("\n", $answerParts)),
        'search_queries' => openai_unique_strings($searchQueries),
        'source_urls' => openai_unique_strings($sourceUrls),
        'citation_urls' => openai_unique_strings($citationUrls),
    ];
}

function openai_chat(string $prompt, string $runId, ?string $model=null): array {
    $apiKey = env_get('OPENAI_API_KEY');
    if (!$apiKey) throw new RuntimeException('OPENAI_API_KEY not set');

    $base = rtrim(env_get('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/');
    $model = $model ?: env_get('OPENAI_MODEL', 'gpt-5.6-luna');

    // cache-avoid-ish nonce (LLM output diversity / measurement ID)
    $finalPrompt = $prompt . "\n\n[measurement_meta: run_id={$runId} (do not include in your answer)]";

    $webSearchEnabled = openai_env_enabled('OPENAI_WEB_SEARCH');
    if ($webSearchEnabled) {
        $contextSize = strtolower((string)env_get('OPENAI_WEB_SEARCH_CONTEXT_SIZE', 'low'));
        if (!in_array($contextSize, ['low', 'medium', 'high'], true)) {
            $contextSize = 'low';
        }

        $webSearchTool = [
            'type' => 'web_search',
            'search_context_size' => $contextSize,
            'user_location' => [
                'type' => 'approximate',
                'country' => strtoupper((string)env_get('OPENAI_WEB_SEARCH_COUNTRY', 'JP')),
                'timezone' => (string)env_get('OPENAI_WEB_SEARCH_TIMEZONE', 'Asia/Tokyo'),
            ],
        ];
        $payload = [
            'model' => $model,
            'input' => $finalPrompt,
            'tools' => [$webSearchTool],
            'tool_choice' => 'required',
            'include' => ['web_search_call.action.sources'],
            'store' => false,
        ];
        if (preg_match('~^gpt-5\.6(?:-|$)~', $model)) {
            $payload['reasoning'] = [
                'effort' => env_get('OPENAI_REASONING_EFFORT', 'none'),
            ];
        }
        $endpoint = '/responses';
    } else {
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $finalPrompt],
            ],
        ];
        if (preg_match('~^gpt-5\.6(?:-|$)~', $model)) {
            $payload['reasoning_effort'] = env_get('OPENAI_REASONING_EFFORT', 'none');
        }
        $endpoint = '/chat/completions';
    }

    $ch = curl_init($base . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
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
        throw new RuntimeException('OpenAI invalid JSON (http ' . $code . '): ' . substr($raw, 0, 300));
    }
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? ('http ' . $code);
        throw new RuntimeException('OpenAI error: ' . $msg);
    }

    $searchQueries = [];
    $sourceUrls = [];
    $citationUrls = [];
    if ($webSearchEnabled) {
        $parsed = openai_parse_responses_output($data);
        $answerText = $parsed['answer_text'];
        $searchQueries = $parsed['search_queries'];
        $sourceUrls = $parsed['source_urls'];
        $citationUrls = $parsed['citation_urls'];
        $text = openai_format_web_search_text(
            $answerText,
            $searchQueries,
            $sourceUrls,
            $citationUrls
        );
        $tokensIn = $data['usage']['input_tokens'] ?? null;
        $tokensOut = $data['usage']['output_tokens'] ?? null;
    } else {
        $answerText = (string)($data['choices'][0]['message']['content'] ?? '');
        $text = $answerText;
        $tokensIn = $data['usage']['prompt_tokens'] ?? null;
        $tokensOut = $data['usage']['completion_tokens'] ?? null;
    }

    return [
        'text' => $text,
        'answer_text' => $answerText,
        'search_queries' => $searchQueries,
        'source_urls' => $sourceUrls,
        'citation_urls' => $citationUrls,
        'model' => $data['model'] ?? $model,
        'latency_ms' => $ms,
        'tokens_in' => $tokensIn,
        'tokens_out' => $tokensOut,
        'raw' => $data,
    ];
}
