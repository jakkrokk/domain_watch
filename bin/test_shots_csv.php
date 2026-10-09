<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/shots_export.php';

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

$shot = ['id'=>4, 'name'=>"調査, \"サクラサク\"\r\n😀", 'target_main_text'=>'サクラサクマーケティング', 'target_sub_texts'=>'["PLAN-B","ナイル","Lany"]', 'created_at'=>'2026-10-01 23:59:00'];
$prompt = '=SUM(1,2) ' . str_repeat("長い質問😀\\\"\n", 6000);
$base = ['prompt_index'=>1, 'prompt_content'=>$prompt, 'provider'=>'gemini', 'status'=>'completed', 'hit_main'=>0, 'hit_sub'=>'[1,1,0]', 'finished_at'=>'2026-10-02 00:01:00'];
$results = [
    array_replace($base, ['prompt_index'=>4, 'status'=>'queued', 'hit_main'=>null, 'hit_sub'=>null, 'finished_at'=>null]),
    array_replace($base, ['prompt_index'=>2, 'status'=>'failed', 'hit_main'=>1, 'hit_sub'=>'[1,1,1]']),
    array_replace($base, ['provider'=>'openai', 'hit_main'=>1, 'hit_sub'=>'[0,1,0]']),
    array_replace($base, ['prompt_index'=>3, 'status'=>'running', 'hit_main'=>null, 'hit_sub'=>null, 'finished_at'=>null]),
    $base,
];
$rows = csv_rows(shot_export_csv($shot, $results));
$expectedHeader = ['project_id','project_name','prompt_id','prompt_name','provider','date','exec_count','main_text','main_rate_pct','sub1_text','sub1_rate_pct','sub2_text','sub2_rate_pct','sub3_text','sub3_rate_pct'];
expect_csv($rows[0] === $expectedHeader, 'Exact answerIO header and column order');
expect_csv(count($rows) === 6, 'One row per prompt/provider without filler dates');
expect_csv(array_column(array_slice($rows, 1), 2) === ['1','1','2','3','4'], 'Stable prompt ordering');
expect_csv($rows[1] === ['4',$shot['name'],'1',$prompt,'gemini','2026-10-02','1','サクラサクマーケティング','0','PLAN-B','100','ナイル','100','Lany','0'], 'Example values and full quoted Unicode text round trip');
expect_csv($rows[2][4] === 'openai' && $rows[2][8] === '100' && $rows[2][10] === '0', 'Provider results remain separate');
foreach (array_slice($rows, 3) as $row) {
    expect_csv($row[6] === '0', 'Failed and pending answers have no successful execution');
    foreach ([8,10,12,14] as $column) expect_csv($row[$column] === '', 'Unmeasured rates are blank, including stale hits');
}
expect_csv($rows[3][5] === '2026-10-02' && $rows[4][5] === '2026-10-01' && $rows[5][5] === '2026-10-01', 'Completion date or receipt date independent of download time');

$emptyTargets = csv_rows(shot_export_csv(array_replace($shot, ['target_sub_texts'=>'[]']), [array_replace($base, ['hit_sub'=>'[]'])]));
expect_csv($emptyTargets[0] === $expectedHeader && array_slice($emptyTargets[1], 9) === array_fill(0, 6, ''), 'Three compatibility slots stay blank when unconfigured');
foreach ([4,5] as $subCount) {
    $targets = array_slice(['PLAN-B','ナイル','Lany','文字列4','文字列5'], 0, $subCount);
    $hits = array_slice([1,0,1,0,1], 0, $subCount);
    $extended = csv_rows(shot_export_csv(array_replace($shot, ['target_sub_texts'=>json_encode($targets)]), [array_replace($base, ['hit_sub'=>json_encode($hits)])]));
    expect_csv(array_slice($extended[0], 0, 15) === $expectedHeader, 'Extra targets preserve the first fifteen columns');
    expect_csv(count($extended[0]) === 9 + 2 * $subCount && count($extended[1]) === count($extended[0]), 'All configured target columns included');
    for ($i=0; $i<$subCount; $i++) expect_csv($extended[1][9+2*$i] === $targets[$i] && $extended[1][10+2*$i] === (string)($hits[$i]*100), 'All target names and rates preserved');
}
expect_csv(csv_rows(shot_export_csv($shot, [])) === [$expectedHeader], 'Empty results still export a valid header');
echo "Shot RAW CSV column compatibility, rates, dates, provider separation, five targets and long Unicode quoting passed\n";
