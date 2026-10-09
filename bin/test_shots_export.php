<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/shots.php';
require_once __DIR__ . '/../src/shots_export.php';
function expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$shot = ['name'=>'提案書 & 調査','target_main_text'=>'自社','target_sub_texts'=>json_encode(['文字列1','文字列2','文字列3','文字列4','文字列5']),'created_at'=>'2026-10-02 12:00:00','status'=>'partial'];
$long = str_repeat("あ😀<&>\n",7000);
$results = [];
for ($i=1;$i<=50;$i++) foreach (['openai','gemini'] as $provider) {
    $results[] = ['prompt_index'=>$i,'prompt_content'=>$i===1 ? '=SUM(1,2) & 長い質問' : '質問' . $i,'provider'=>$provider,'status'=>$provider==='gemini' ? 'failed' : 'completed','hit_main'=>1,'hit_sub'=>'[1,0,1,0,1]','response_text'=>$i===1 && $provider==='openai' ? $long : '回答','error_message'=>$provider==='gemini' ? '取得失敗' : null,'model'=>'test','finished_at'=>'2026-10-02 12:01:00','search_performed'=>null,'search_suggestions'=>'<div>HTML-should-not-be-exported</div>','tokens_in'=>12,'tokens_out'=>34,'latency_ms'=>100];
}
$bytes = shot_export_xlsx($shot,$results);
expect(str_starts_with($bytes,'PK'), 'XLSX is a ZIP archive');
$offset = 0; $entries = 0;
while (substr($bytes,$offset,4) === "PK\x03\x04") {
    $header = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength',substr($bytes,$offset+4,26));
    expect($header['version']===20 && $header['method']===8,'Deflated ZIP entries require extraction version 2.0');
    expect($header['extraLength']===0,'No nonstandard ZIP extra fields');
    $offset += 30+$header['nameLength']+$header['extraLength']+$header['compressed'];
    $entries++;
}
expect($entries===56 && substr($bytes,$offset,4) === "PK\x01\x02",'All package parts precede a valid central directory');
$temp = tempnam(sys_get_temp_dir(),'test-export-'); unlink($temp); $path = $temp . '.zip';
try {
    file_put_contents($path,$bytes); $zip = new PharData($path);
    $book = simplexml_load_string($zip['xl/workbook.xml']->getContent());
    $book->registerXPathNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $sheets = $book->xpath('//s:sheet');
    expect(count($sheets)===51 && (string)$sheets[0]['name']==='サマリー' && (string)$sheets[50]['name']==='プロンプト50','Summary and 50 numbered prompt sheets');
    $sheet = simplexml_load_string($zip['xl/worksheets/sheet2.xml']->getContent());
    $sheet->registerXPathNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    expect((string)$sheet->xpath('//s:c[@r="B2"]/s:is/s:t')[0]==='=SUM(1,2) & 長い質問','Full prompt near top, stored literally');
    expect(count($sheet->xpath('//s:f'))===0,'No user input becomes a formula');
    $reconstructed = '';
    foreach ($sheet->xpath('//s:row') as $row) {
        $cells = $row->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->c;
        if (count($cells)>1 && str_starts_with((string)$cells[0]->is->t,'回答・検索情報（RAW）') && mb_strlen($reconstructed)<mb_strlen($long)) $reconstructed .= (string)$cells[1]->is->t;
        foreach ($cells as $cell) expect(strlen(mb_convert_encoding((string)$cell->is->t,'UTF-16LE','UTF-8'))/2<=32767,'Excel cell length respected');
    }
    expect($reconstructed===$long,'Long Unicode answers exported in full');
    $summary = $zip['xl/worksheets/sheet1.xml']->getContent();
    expect(str_contains($summary,'文字列5'),'All five measured strings included');
    $summaryXml = simplexml_load_string($summary);
    $summaryXml->registerXPathNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rates = $summaryXml->xpath('//s:c[@s="2"]');
    expect(count($rates)===6,'Only successful-provider rates are numeric percentages');
    expect((float)$rates[0]->v===1.0 && (float)$rates[2]->v===0.0,'Rates stored as numeric ratios');
    foreach ($rates as $rate) expect(!isset($rate['t']) && isset($rate->v),'Percentages are numeric, not text');
    $styles = $zip['xl/styles.xml']->getContent();
    expect(str_contains($styles,'formatCode="0.0%"'),'Numeric percentage display style');
    $sheetXml = $zip['xl/worksheets/sheet2.xml']->getContent();
    foreach (['入力トークン数','出力トークン数','応答時間','Google検索候補（HTML）','HTML-should-not-be-exported'] as $removed) expect(!str_contains($sheetXml,$removed),'Unneeded fields and raw HTML removed');
    foreach ($zip['xl/worksheets'] as $entry) expect(simplexml_load_string($entry->getContent())!==false,'Every worksheet is valid XML');
    if (isset($argv[1])) file_put_contents($argv[1],$bytes);
    echo "XLSX ZIP headers, 51 sheets, numeric percentages, omitted fields and full long answers passed\n";
} finally { unset($zip); unlink($path); }
