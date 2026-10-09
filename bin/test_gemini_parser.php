<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/openai.php';
require_once __DIR__ . '/../src/gemini.php';
require_once __DIR__ . '/../src/domain_match.php';
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$response = gemini_parse_output(['candidates' => [[
    'content' => ['parts' => [
        ['text' => 'Secret reasoning', 'thought' => true],
        ['text' => 'おすすめはAcmeです。'],
    ]],
    'groundingMetadata' => [
        'webSearchQueries' => ['reference-only.example', 'reference-only.example'],
        'searchEntryPoint' => ['renderedContent' => '<div>Google Search</div>'],
        'groundingChunks' => [
            ['web' => ['uri' => 'https://reference-only.example']],
            ['web' => ['uri' => 'https://cited.example']],
        ],
        'groundingSupports' => [['groundingChunkIndices' => [1, 1, 999]]],
    ],
]]]);
check($response['answer_text'] === 'おすすめはAcmeです。', 'Thoughts must not be part of the answer');
check($response['search_queries'] === ['reference-only.example'], 'Deduplicate queries');
check(count($response['source_urls']) === 2, 'Keep referenced sources');
check($response['citation_urls'] === ['https://cited.example'], 'Only supported sources are citations');
check($response['search_performed'], 'Search execution detection');
check($response['search_suggestions'] === '<div>Google Search</div>', 'Preserve Google Search Suggestions for display');
check(text_contains_target($response['answer_text'], 'acme'), 'Reuse case-insensitive matching');
check(!text_contains_target($response['answer_text'], 'reference-only.example'), 'Metadata must not produce answer hits');
$plain = gemini_parse_output(['candidates' => [['content' => ['parts' => [['text' => '回答']]]]]]);
check(!$plain['search_performed'] && $plain['source_urls'] === [], 'No-search response is valid');
foreach ([[], ['promptFeedback' => ['blockReason' => 'SAFETY']], ['candidates' => [['finishReason' => 'SAFETY']]]] as $empty) {
    try { gemini_parse_output($empty); }
    catch (RuntimeException $e) { continue; }
    throw new RuntimeException('Empty or blocked answers must fail, not be counted as misses');
}
echo "Gemini parser and answer-only matching: OK\n";
