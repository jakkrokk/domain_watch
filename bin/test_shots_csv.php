<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/shots_export.php';
require_once __DIR__ . '/../src/openai.php';

function expect_csv(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function csv_rows(string $csv): array {
    expect_csv(str_starts_with($csv, "\xEF\xBB\xBF"), 'UTF-8 BOM for Excel');
    $stream = fopen('php://temp', 'w+b');
    try {
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) $rows[] = $row;
        return $rows;
    } finally {
        fclose($stream);
    }
}

$shot = ['id'=>4, 'name'=>'提案書調査', 'target_main_text'=>'自社', 'target_sub_texts'=>'["競合A","競合B","競合C","競合D","競合E"]', 'created_at'=>'2026-10-01 23:59:00'];
$prompt = '=SUM(1,2) ' . str_repeat("長い質問😀\\\"\n", 6000);
$answer = trim("自社と競合Aを比較。\n自社を再検討。\n" . str_repeat("長い回答😀<&>,\"\\\n", 6000));
$raw = openai_format_web_search_text($answer, ['自社検索'], ['https://source-only.example/'], ['https://rival.example/a','https://self.example/page','https://self.example/page']);
$base = ['prompt_index'=>1, 'prompt_content'=>$prompt, 'provider'=>'gemini', 'status'=>'completed', 'hit_main'=>1, 'response_text'=>$raw, 'model'=>'gemini-test-version', 'error_message'=>null, 'finished_at'=>'2026-10-02 00:01:00'];
$results = [
    array_replace($base, ['prompt_index'=>4, 'status'=>'queued', 'hit_main'=>null, 'response_text'=>null, 'model'=>null, 'finished_at'=>null]),
    array_replace($base, ['prompt_index'=>2, 'status'=>'failed', 'error_message'=>"エラー, \"引用\"\n詳細"]),
    array_replace($base, ['provider'=>'openai', 'hit_main'=>0, 'model'=>null, 'response_text'=>openai_format_web_search_text('競合Aだけの回答', [], [], [])]),
    array_replace($base, ['prompt_index'=>3, 'status'=>'running', 'hit_main'=>null, 'response_text'=>null, 'model'=>null, 'finished_at'=>null]),
    $base,
];
$csv = shot_export_csv($shot, $results);
$rows = csv_rows($csv);
$expectedHeader = ['レポート実行ID','実行日時','ファネル段階','クエリID','クエリテキスト','クエリタイプ','AIモデル','モデルバージョン','回答テキスト','可視性スコア','プレゼンススコア','ポジションスコア','引用スコア','ブランド順位','言及有無','言及回数','言及箇所','抽出ブランド一覧','競合ブランド一覧','抽出された特徴','引用サイト数','引用サイトURL一覧','自社サイト引用有無','自社サイト引用順位','実行ステータス','エラー内容'];
expect_csv($rows[0] === $expectedHeader, 'Exact AnswerIO 26-column header and order');
expect_csv(count($rows) === 6, 'One row per prompt/provider');
expect_csv(array_column(array_slice($rows, 1), 3) === ['shot-4-prompt-1','shot-4-prompt-1','shot-4-prompt-2','shot-4-prompt-3','shot-4-prompt-4'], 'Stable query IDs shared across providers');
expect_csv(array_unique(array_column(array_slice($rows, 1), 0)) === ['shot-4'], 'Stable report ID for the shot');
expect_csv($csv === shot_export_csv($shot, array_reverse($results)), 'Repeat downloads keep the same IDs and ordering');
expect_csv($rows[1][1] === '2026-10-02T00:01:00+09:00', 'Stored completion datetime with seconds and timezone');
expect_csv($rows[1][4] === $prompt && $rows[1][8] === $answer, 'Full Unicode prompts and answers round trip without truncation');
expect_csv($rows[1][6] === 'gemini' && $rows[1][7] === 'gemini-test-version' && $rows[2][6] === 'openai' && $rows[2][7] === '', 'Saved provider/model only; no invented latest version');
expect_csv($rows[1][14] === 'TRUE' && $rows[1][15] === '2' && $rows[1][16] === "行1：自社と競合Aを比較。\n行2：自社を再検討。", 'Mentions count and locations exclude search metadata');
expect_csv($rows[2][14] === 'FALSE' && $rows[2][15] === '0' && $rows[2][16] === '', 'Known absence differs from missing data');
expect_csv($rows[1][20] === '2' && $rows[1][21] === 'https://rival.example/a, https://self.example/page', 'Actual citation URLs deduplicated in original order; source-only URLs excluded');
expect_csv($rows[2][20] === '0' && $rows[2][21] === '', 'Recorded empty citation list has a known zero count');
expect_csv(array_column(array_slice($rows, 1), 24) === ['成功','成功','失敗','実行中','未実行'], 'Execution statuses translated');
expect_csv($rows[3][25] === $results[1]['error_message'], 'Saved multiline error preserved');
foreach (array_slice($rows, 1) as $row) {
    expect_csv(count($row) === 26, 'Exactly 26 values in every row');
    foreach ([2,5,9,10,11,12,13,17,19] as $column) expect_csv($row[$column] === '', 'Unrecorded classifications, scores, brand extraction and rank stay blank');
    expect_csv($row[18] === '競合A, 競合B, 競合C, 競合D, 競合E', 'All five configured competitors included');
}
foreach (array_slice($rows, 3) as $row) {
    foreach ([8,14,15,16,20,21,22,23] as $column) expect_csv($row[$column] === '', 'Failed/pending answers never export stale measurements');
}
expect_csv($rows[4][1] === '' && $rows[5][1] === '', 'No invented execution datetime for pending results');
$plainAnswer = "自社の回答\n\n参照したURL\nこの記事内の見出し";
$plain = csv_rows(shot_export_csv($shot, [array_replace($base, ['response_text'=>$plainAnswer])]));
expect_csv($plain[1][8] === $plainAnswer && $plain[1][20] === '' && $plain[1][21] === '', 'Answer headings retained and missing citation metadata remains unknown');
$otherShot = csv_rows(shot_export_csv(array_replace($shot, ['id'=>5, 'target_sub_texts'=>'[]']), [$base]));
expect_csv($otherShot[1][0] === 'shot-5' && $otherShot[1][3] === 'shot-5-prompt-1' && $otherShot[1][18] === '', 'Different shots get distinct IDs and unconfigured competitors stay empty');
expect_csv(shot_export_mentions('ACME / acme / AcMe', 'acme') === [3, '行1：ACME / acme / AcMe'], 'Case-insensitive matching follows the existing detection rule');
expect_csv(shot_export_mentions('anything', '') === ['', ''], 'Missing target is not an absent mention');
expect_csv(shot_export_site_host('HTTPS://WWW.SELF.EXAMPLE/path') === 'self.example' && shot_export_site_host('株式会社 自社') === null, 'Site URLs/domains recognized without inferring a domain from a company name');
expect_csv(shot_export_site_citation(['https://rival.example/', 'https://www.self.example/page'], 'self.example') === ['TRUE', 2], 'Own site rank is the first matching citation position');
expect_csv(shot_export_site_citation(['https://blog.self.example/page'], 'https://www.self.example/') === ['TRUE', 1], 'Own site subdomains recognized');
expect_csv(shot_export_site_citation(['https://self.example.evil.example/', 'https://notself.example/'], 'self.example') === ['FALSE', ''], 'Similar or malicious hostnames are not own-site citations');
expect_csv(shot_export_site_citation([], 'self.example') === ['FALSE', ''] && shot_export_site_citation(null, 'self.example') === ['', ''], 'Known empty citations differ from missing metadata');
expect_csv(shot_export_site_citation(['https://self.example/'], '自社') === ['', ''], 'Company names do not invent own-site identity');
$proxy = 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/opaque';
expect_csv(shot_export_site_citation([$proxy], 'self.example') === ['', ''], 'Opaque Google redirects never imply own-site absence');
expect_csv(shot_export_site_citation([$proxy, 'https://self.example/'], 'self.example') === ['TRUE', ''], 'Known citation survives an opaque predecessor, but first own-site rank remains unknown');
expect_csv(shot_export_site_citation(['https://self.example/', $proxy], 'self.example') === ['TRUE', 1], 'First known own-site citation has a definite first rank');
expect_csv(shot_export_site_citation(['https://www.google.com/url?q=https%3A%2F%2Fself.example%2F'], 'self.example') === ['', ''], 'Other unresolved Google redirects stay unknown');
expect_csv(shot_export_site_citation(['unparseable'], 'self.example') === ['', ''], 'Malformed citation URLs never invent an absence');
$domainShot = array_replace($shot, ['target_main_text'=>'self.example']);
$siteRows = csv_rows(shot_export_csv($domainShot, [array_replace($base, ['hit_main'=>0]), array_replace($results[2], ['prompt_index'=>2])]));
expect_csv($siteRows[1][22] === 'TRUE' && $siteRows[1][23] === '2' && $siteRows[1][14] === 'FALSE', 'CSV uses first target as own site and keeps site citations separate from text mentions');
expect_csv($siteRows[2][22] === 'FALSE' && $siteRows[2][23] === '', 'CSV exports a known uncited own site without inventing a rank');
expect_csv(csv_rows(shot_export_csv($shot, [])) === [$expectedHeader], 'Empty results still export a valid header');
echo "AnswerIO 26-column CSV, stable IDs, datetimes, unknown values, mentions, citations, own-site matching, five competitors and long Unicode quoting passed\n";
