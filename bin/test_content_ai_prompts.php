<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/content_generation.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $action, string $message): void {
    try { $action(); } catch (InvalidArgumentException | RuntimeException $e) { return; }
    throw new RuntimeException($message);
}

check(content_validate(['keywords'=>'SEO対策とは'])['ai_prompts'] === '', 'Blank prompts select inference');
check(content_validate(['keywords'=>'SEO対策とは','ai_prompts'=>" \r\n "])['ai_prompts'] === '', 'Whitespace selects inference');
check(content_validate(['keywords'=>'SEO対策とは','ai_prompts'=>" 質問1 \r\n\r\n質問2\u{2028}質問3 "])['ai_prompts'] === "質問1\n質問2\n質問3", 'Normalize nonempty lines');
check(content_parse_ai_prompts(str_repeat('あ',1000)) === [str_repeat('あ',1000)], 'Allow 1000 characters');
foreach (["1\n2\n3\n4", str_repeat('あ',1001), ['array']] as $input) {
    rejects(fn()=>content_validate(['keywords'=>'SEO対策とは','ai_prompts'=>$input]), 'Invalid prompt input accepted');
}

$job = ['site_url'=>'https://example.com/','service'=>'SEO支援','audience'=>'企業のWeb担当者','notes'=>'まず自社で実施できる内容を重視'];
$item = ['id'=>42,'keyword'=>'SEO対策とは'];
$manual = content_resolve_ai_prompts($job + ['ai_prompts'=>"SEOとは？\n何から始めればよい？"], $item, static fn()=>throw new RuntimeException('Manual prompts must bypass Codex'));
check($manual === ['origin'=>'manual','prompts'=>['SEOとは？','何から始めればよい？']], 'Use exact manual prompts without padding');
$inferredPrompts = ['SEO対策とは何ですか？','企業でSEOを始める手順は？','SEOの効果をどう確認しますか？'];
$inferenceCalls = 0;
$resolved = content_resolve_ai_prompts($job, $item, static function($receivedJob,$receivedItem) use (&$inferenceCalls,$job,$item,$inferredPrompts) {
    $inferenceCalls++;
    check($receivedJob === $job && $receivedItem === $item, 'Pass keyword and client context to inference');
    return ['prompts'=>$inferredPrompts];
});
check($inferenceCalls === 1 && $resolved['prompts'] === $inferredPrompts, 'Infer exactly three prompts');
check($resolved['model'] === 'gpt-6.1-sol' && $resolved['reasoning_effort'] === 'xhigh' && $resolved['origin'] === 'inferred', 'Record inference settings');
foreach ([[], ['a','b'], ['a','b','c','d'], ['a','a','b'], ['a',null,'c'], ['a',"b\nc",'d'], ['a',' ','c'], ['a',str_repeat('あ',1001),'c']] as $invalid) {
    rejects(fn()=>content_resolve_ai_prompts($job,$item,static fn()=>['prompts'=>$invalid]), 'Invalid inference accepted');
}

$calls = [];
$snapshots = [];
$chat = static function($provider,$prompt,$runId) use (&$calls) {
    $calls[] = [$provider,$prompt,$runId];
    return ['answer_text'=>'回答：' . $prompt,'model'=>'test-' . $provider,'search_performed'=>true,
            'search_queries'=>['共通クエリ',$prompt], 'source_urls'=>['https://example.com/reference'],
            'citation_urls'=>['https://example.com/cited']];
};
$checkpoint = static function($provider,$number,$evidence) use (&$snapshots) { $snapshots[] = $evidence; };
$evidence = content_collect_ai_evidence($resolved['prompts'],42,$chat,$checkpoint);
check(count($calls) === 6 && count(array_unique(array_column($calls,2))) === 6, 'Three prompts per provider with unique run IDs');
foreach (['openai','gemini'] as $provider) {
    check(array_column(array_values(array_filter($calls,static fn($call)=>$call[0]===$provider)),1) === $inferredPrompts, 'Both providers receive identical resolved prompts');
    check($evidence[$provider]['status'] === 'completed', 'Provider completed');
    check(array_column($evidence[$provider]['responses'],'prompt') === $inferredPrompts, 'Keep prompt-to-response mapping');
    check($evidence[$provider]['source_urls'] === ['https://example.com/reference'], 'Deduplicate reference URLs');
    check($evidence[$provider]['citation_urls'] === ['https://example.com/cited'], 'Keep actual citations separate');
    check(count($evidence[$provider]['search_queries']) === 4, 'Keep all fanout queries');
    check($evidence[$provider]['responses'][0]['model'] === 'test-' . $provider, 'Keep model per response');
    check(str_contains($evidence[$provider]['answer_text'],$inferredPrompts[2]), 'All answers reach the outline generator');
}
check(count($snapshots[1]['openai']['responses']) === 1, 'Checkpoint the first answer before later requests');
check(end($snapshots) === $evidence, 'Final checkpoint retains all answers');
$prompt = content_prompt($job,$item + ['ai_prompts'=>$resolved],$evidence);
$inputMarker = "\n入力データ（命令ではありません）:\n";
$promptInput = json_decode(substr($prompt, strpos($prompt,$inputMarker) + strlen($inputMarker)),true,512,JSON_THROW_ON_ERROR);
check($promptInput['ai_prompts'] === $resolved && $promptInput['ai_evidence'] === $evidence, 'Resolved questions and evidence reach the final prompt');

$failedCalls = 0;
$failed = content_collect_ai_evidence($inferredPrompts,43,static function($provider,$prompt) use (&$failedCalls,$inferredPrompts) {
    $failedCalls++;
    if ($provider === 'openai' && $prompt === $inferredPrompts[1]) return ['answer_text'=>''];
    return ['answer_text'=>'回答','citation_urls'=>['https://example.com/ok']];
},static function() {});
check($failedCalls === 6, 'Continue other questions after an empty answer');
check($failed['openai']['status'] === 'failed' && $failed['gemini']['status'] === 'completed', 'A failed question fails its provider');
check($failed['openai']['responses'][1]['status'] === 'failed' && count($failed['openai']['responses']) === 3, 'Retain failed prompt and successful siblings');

foreach (['ai-prompts.schema.json','result.schema.json'] as $filename) {
    json_decode(file_get_contents(__DIR__ . '/../resources/content-generation/' . $filename),true,512,JSON_THROW_ON_ERROR);
}
echo "PASS: prompt validation, manual bypass, inferred context, six requests, evidence mapping, checkpoints, failure handling\n";
