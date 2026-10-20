<?php
declare(strict_types=1);

function content_reference(array $job): array {
    $dir = content_dir((int)$job['id']);
    $output = $dir . '/reference.json';
    if (!is_file($output)) {
        content_command([env_get('CONTENT_PYTHON_BINARY', __DIR__ . '/../storage/content-venv/bin/python'), __DIR__ . '/../bin/content_reference.py', $job['reference_url'], $output], realpath(__DIR__ . '/..'), '', $dir . '/reference.log', 120);
    }
    $reference = json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
    if (($reference['requested_url'] ?? '') !== $job['reference_url'] || empty($reference['headings']) || trim($reference['title'] ?? '') === '') throw new RuntimeException('参照ページの見出しを取得できませんでした。');
    return $reference;
}

function content_rewrite_result(array $job, array $reference, array $analysis): array {
    $entries = $analysis['sections'] ?? [];
    if (count($entries) !== count($reference['headings'])) throw new RuntimeException('既存見出しに対する調査が不足しています。');
    $sections = [];
    foreach ($reference['headings'] as $index=>$heading) {
        $entry = $entries[$index];
        if (($entry['source_index'] ?? null) !== $index + 1 || trim($entry['observation'] ?? '') === '' || !is_array($entry['comments'] ?? null) || !is_array($entry['sources'] ?? null)) throw new RuntimeException('既存見出しと調査結果の対応が不正です。');
        $url = $reference['url'] . (!empty($heading['id']) ? '#' . rawurlencode($heading['id']) : '');
        foreach ($entry['sources'] as $source) if (!filter_var($source, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($source, PHP_URL_SCHEME) ?? ''), ['http','https'], true)) throw new RuntimeException('調査の参照URLが不正です。');
        $sections[] = ['level'=>$heading['level'],'heading'=>$heading['heading'],'points'=>[$entry['observation']],'word_count'=>$heading['word_count'],
            'format'=>'','sources'=>array_values(array_unique([$url,...$entry['sources']])),'source_attributions'=>[],'llmo_reasons'=>[], 'rewrite_comments'=>$entry['comments']];
    }
    if (trim($analysis['audience'] ?? '') === '') throw new RuntimeException('想定読者の調査が不足しています。');
    return ['mode'=>'rewrite','title'=>$reference['title'],'audience'=>$analysis['audience'],'related_keywords'=>[],'sections'=>$sections,
        'competitors'=>[],'fanout'=>[],'reference'=>$reference,'llmo_analysis'=>$analysis['llmo_analysis'] ?? '', 'limitations'=>array_values(array_unique(array_merge($reference['limitations'] ?? [], $analysis['limitations'] ?? []))),
        'lead_guidance'=>['既存の見出しと順序を保ち、LLMOの改善点をコメントする。','概要は既存本文の要約、右欄は実測AI回答と公開資料に基づく改善提案。']];
}
