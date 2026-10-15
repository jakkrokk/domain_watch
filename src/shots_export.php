<?php
declare(strict_types=1);

require_once __DIR__ . '/answer_raw.php';
require_once __DIR__ . '/domain_match.php';

function shot_export_mentions(string $answer, string $target): array {
    $target = normalize_target_text($target);
    if ($target === '') return ['', ''];
    $count = mb_substr_count(_ci($answer), _ci($target), 'UTF-8');
    $locations = [];
    foreach (preg_split('/\R/u', $answer) ?: [] as $i => $line) {
        if (text_contains_target($line, $target)) $locations[] = '行' . ($i+1) . '：' . $line;
    }
    return [$count, implode("\n", $locations)];
}

function shot_export_site_host(string $site): ?string {
    $site = normalize_target_text($site);
    if ($site === '') return null;
    $url = str_contains($site, '://') ? $site : 'https://' . $site;
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true) || empty($parts['host'])) return null;
    $host = strtolower(rtrim($parts['host'], '.'));
    if (preg_match('/[^\x00-\x7F]/', $host)) {
        if (!function_exists('idn_to_ascii')) return null;
        $host = idn_to_ascii($host);
        if ($host === false) return null;
    }
    if (!str_contains($host, '.') || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) return null;
    return preg_replace('/^www\./', '', $host);
}

function shot_export_site_citation(?array $citations, string $site): array {
    $siteHost = shot_export_site_host($site);
    if ($siteHost === null || $citations === null) return ['', ''];
    $unknown = false;
    foreach ($citations as $i => $url) {
        $host = shot_export_site_host($url);
        // Google grounding redirects do not identify the original cited site.
        $opaque = $host === 'vertexaisearch.cloud.google.com'
            || ($host === 'google.com' && parse_url($url, PHP_URL_PATH) === '/url');
        if ($host === null || $opaque) { $unknown = true; continue; }
        if ($host === $siteHost || str_ends_with($host, '.' . $siteHost)) return ['TRUE', $unknown ? '' : $i+1];
    }
    return [$unknown ? '' : 'FALSE', ''];
}

// AnswerIO's 26-column report. Unknown classifications and scores stay blank.
function shot_export_csv(array $shot, array $results): string {
    $subs = json_decode($shot['target_sub_texts'], true, 512, JSON_THROW_ON_ERROR);
    $header = [
        'レポート実行ID','実行日時','ファネル段階','クエリID','クエリテキスト','クエリタイプ',
        'AIモデル','モデルバージョン','回答テキスト','可視性スコア','プレゼンススコア','ポジションスコア',
        '引用スコア','ブランド順位','言及有無','言及回数','言及箇所','抽出ブランド一覧','競合ブランド一覧',
        '抽出された特徴','引用サイト数','引用サイトURL一覧','自社サイト引用有無','自社サイト引用順位',
        '実行ステータス','エラー内容',
    ];
    usort($results, static fn($a, $b) => ((int)$a['prompt_index'] <=> (int)$b['prompt_index']) ?: strcmp($a['provider'], $b['provider']));

    $out = fopen('php://temp', 'w+b');
    if ($out === false) throw new RuntimeException('Cannot create CSV export');
    try {
        // UTF-8 BOM for Japanese in Excel; empty escape uses standard CSV quoting.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ',', '"', '', "\r\n");
        foreach ($results as $result) {
            $completed = $result['status'] === 'completed';
            [$answer, $info] = $completed ? answer_split_raw($result['response_text'] ?? '') : ['', []];
            $hasAnswer = $completed && trim($answer) !== '';
            [$mentionCount, $mentionLocations] = $hasAnswer ? shot_export_mentions($answer, $shot['target_main_text']) : ['', ''];
            $citations = array_key_exists('回答で引用したURL', $info) ? array_values(array_unique(array_map('trim', $info['回答で引用したURL']))) : null;
            [$ownSiteCited, $ownSiteRank] = shot_export_site_citation($citations, $shot['target_main_text']);
            $datetime = empty($result['finished_at']) ? '' : (new DateTimeImmutable($result['finished_at'], new DateTimeZone('Asia/Tokyo')))->format('Y-m-d\TH:i:sP');
            $status = ['completed'=>'成功','failed'=>'失敗','running'=>'実行中','queued'=>'未実行'][$result['status']] ?? $result['status'];
            $row = [
                'shot-' . (int)$shot['id'], $datetime, '',
                'shot-' . (int)$shot['id'] . '-prompt-' . (int)$result['prompt_index'], $result['prompt_content'], '',
                $result['provider'], $result['model'] ?? '', $answer, '', '', '', '', '',
                $completed && isset($result['hit_main']) ? ((int)$result['hit_main'] ? 'TRUE' : 'FALSE') : '',
                $mentionCount, $mentionLocations, '', implode(', ', $subs), '',
                $citations === null ? '' : count($citations), $citations === null ? '' : implode(', ', $citations),
                $ownSiteCited, $ownSiteRank, $status, $result['error_message'] ?? '',
            ];
            fputcsv($out, $row, ',', '"', '', "\r\n");
        }
        rewind($out);
        $csv = stream_get_contents($out);
        if ($csv === false) throw new RuntimeException('Cannot read CSV export');
        return $csv;
    } finally {
        fclose($out);
    }
}

// Native OOXML with inline strings keeps user input literal (including leading '=').
// ZIP headers use version 2.0 for Deflate, as required by the ZIP specification.
function shot_xlsx_sheet(array $rows): string {
    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->startDocument('1.0','UTF-8');
    $xml->startElementNS(null,'worksheet','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $xml->startElement('cols');
    foreach ([26,70,24,20,20,20] as $i=>$width) {
        $xml->startElement('col');
        foreach (['min'=>$i+1,'max'=>$i+1,'width'=>$width,'customWidth'=>1] as $key=>$value) $xml->writeAttribute($key,(string)$value);
        $xml->endElement();
    }
    $xml->endElement();
    $xml->startElement('sheetData');
    foreach ($rows as $i=>$row) {
        $xml->startElement('row'); $xml->writeAttribute('r',(string)($i+1));
        foreach ($row as $j=>$value) {
            $xml->startElement('c');
            $xml->writeAttribute('r',chr(65+$j) . ($i+1));
            $percent = is_array($value) && array_key_exists('percent', $value);
            if ($percent) $value = $value['percent'];
            $xml->writeAttribute('s',$percent ? '2' : ($i===0 ? '1' : '0'));
            if (is_int($value) || is_float($value)) $xml->writeElement('v',(string)$value);
            else {
                $xml->writeAttribute('t','inlineStr');
                $xml->startElement('is'); $xml->startElement('t');
                $xml->writeAttribute('xml:space','preserve');
                // XML 1.0 cannot represent these controls; keep tabs and newlines.
                $xml->text(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u','', (string)$value) ?? '');
                $xml->endElement(); $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
    }
    $xml->endElement(); $xml->endElement(); $xml->endDocument();
    return $xml->outputMemory();
}

function shot_export_field(array &$rows, string $label, ?string $text): void {
    $text ??= '';
    // Excel limits a cell to 32,767 UTF-16 units. Split long answers without dropping text.
    $length = mb_strlen($text,'UTF-8');
    if (!$length) { $rows[] = [$label,'']; return; }
    for ($offset=0; $offset<$length; $offset+=15000) $rows[] = [$offset ? $label . '（続き）' : $label,mb_substr($text,$offset,15000,'UTF-8')];
}

function shot_export_xlsx(array $shot, array $results): string {
    $targets = array_merge([$shot['target_main_text']],json_decode($shot['target_sub_texts'],true,512,JSON_THROW_ON_ERROR));
    $providers = array_values(array_unique(array_column($results,'provider')));
    $providerName = static fn($p) => $p === 'gemini' ? 'Gemini' : 'ChatGPT';
    $prompts = []; $stats = [];
    foreach ($results as $result) {
        $prompts[(int)$result['prompt_index']][] = $result;
        $p = $result['provider'];
        $stats[$p][$result['status']] = ($stats[$p][$result['status']] ?? 0)+1;
        if ($result['status'] !== 'completed') continue;
        $hits = array_merge([(int)$result['hit_main']],json_decode($result['hit_sub'],true,512,JSON_THROW_ON_ERROR));
        foreach ($hits as $i=>$hit) $stats[$p]['hits'][$i] = ($stats[$p]['hits'][$i] ?? 0)+(int)$hit;
    }
    ksort($prompts);
    $summary = [['ショット調査サマリー'],['ショット名',$shot['name']],['受付日時',$shot['created_at']],['調査開始日時',$shot['started_at'] ?? ''],['調査完了日時',$shot['finished_at'] ?? ''],['状態',shot_status_label($shot['status'])],['プロンプト数',count($prompts)],['出現率の母数','成功した回答のみ。未処理・失敗した回答は含めません。'],[],['サービス','成功回答数','失敗回答数','調査中','受付済み']];
    foreach ($providers as $p) $summary[] = [$providerName($p),$stats[$p]['completed'] ?? 0,$stats[$p]['failed'] ?? 0,$stats[$p]['running'] ?? 0,$stats[$p]['queued'] ?? 0];
    $summary[] = []; $summary[] = ['区分','計測したい文字列','サービス','出現率','出現数','成功回答数'];
    foreach ($targets as $i=>$target) foreach ($providers as $p) {
        $n = $stats[$p]['completed'] ?? 0; $hits = $stats[$p]['hits'][$i] ?? 0;
        $summary[] = [$i===0 ? '自社' : '計測したい文字列' . $i,$target,$providerName($p),$n ? ['percent'=>$hits/$n] : '—',$hits,$n];
    }
    $sheets = ['サマリー'=>$summary];
    foreach ($prompts as $index=>$byProvider) {
        $rows = [['プロンプト' . $index]];
        shot_export_field($rows,'プロンプト',$byProvider[0]['prompt_content']);
        $rows[] = ['ショット名',$shot['name']];
        foreach ($byProvider as $result) {
            $rows[] = []; $rows[] = ['サービス',$providerName($result['provider'])];
            $rows[] = ['状態',shot_status_label($result['status'])];
            $rows[] = ['処理完了日時',$result['finished_at'] ?? ''];
            $rows[] = ['モデル',$result['model'] ?? ''];
            $rows[] = ['検索実行',$result['search_performed'] === null ? '不明' : ((int)$result['search_performed'] ? 'あり' : 'なし')];
            $rows[] = ['区分','計測したい文字列','出現'];
            $hits = $result['status'] === 'completed' ? array_merge([(int)$result['hit_main']],json_decode($result['hit_sub'],true,512,JSON_THROW_ON_ERROR)) : [];
            foreach ($targets as $i=>$target) $rows[] = [$i===0 ? '自社' : '計測したい文字列' . $i,$target,isset($hits[$i]) ? ($hits[$i] ? 'あり' : 'なし') : '未計測'];
            shot_export_field($rows,'回答・検索情報（RAW）',$result['response_text']);
            if (!empty($result['error_message'])) shot_export_field($rows,'エラー',$result['error_message']);
        }
        $sheets['プロンプト' . $index] = $rows;
    }
    $files = [];
    $types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $workbook = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $i=0;
    foreach ($sheets as $name=>$rows) {
        $i++;
        $workbook .= '<sheet name="' . htmlspecialchars($name,ENT_XML1 | ENT_QUOTES,'UTF-8') . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        $types .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $files['xl/worksheets/sheet' . $i . '.xml'] = shot_xlsx_sheet($rows);
    }
    $files['[Content_Types].xml'] = $types . '</Types>';
    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $files['xl/workbook.xml'] = $workbook . '</sheets></workbook>';
    $files['xl/_rels/workbook.xml.rels'] = $rels . '<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="0.0%"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    return shot_xlsx_zip($files);
}

// Minimal standard ZIP archive: known sizes/CRC, UTF-8 filenames, Deflate and no extra fields.
// https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT (sections 4.3 and 4.4)
function shot_xlsx_zip(array $files): string {
    $body = ''; $directory = '';
    $now = new DateTimeImmutable();
    $time = ((int)$now->format('G') << 11) | ((int)$now->format('i') << 5) | intdiv((int)$now->format('s'),2);
    $date = (((int)$now->format('Y')-1980) << 9) | ((int)$now->format('n') << 5) | (int)$now->format('j');
    foreach ($files as $name=>$contents) {
        $compressed = gzdeflate($contents);
        if ($compressed === false) throw new RuntimeException('Cannot compress export file');
        $crc = crc32($contents); $size = strlen($contents); $compressedSize = strlen($compressed); $nameLength = strlen($name);
        $offset = strlen($body);
        $body .= pack('VvvvvvVVVvv',0x04034b50,20,0x0800,8,$time,$date,$crc,$compressedSize,$size,$nameLength,0) . $name . $compressed;
        $directory .= pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0x0800,8,$time,$date,$crc,$compressedSize,$size,$nameLength,0,0,0,0,0,$offset) . $name;
    }
    $count = count($files);
    return $body . $directory . pack('VvvvvVVv',0x06054b50,0,0,$count,$count,strlen($directory),strlen($body),0);
}
