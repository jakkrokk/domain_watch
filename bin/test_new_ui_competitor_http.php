<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("cli only\n");
$base = $argv[1] ?? 'http://127.0.0.1:8000';
$cookie = tempnam(sys_get_temp_dir(), 'competitor-http-');
$uids = []; $projects = [];
function ensure_competitor(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function competitor_request(string $path, ?array $post = null): array {
    global $base, $cookie;
    $c = curl_init($base . $path);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>10]);
    if ($post !== null) curl_setopt_array($c, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $body = curl_exec($c);
    if ($body === false) throw new RuntimeException(curl_error($c));
    $status = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return [$status,$body];
}
function competitor_xpath(string $body): DOMXPath {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $body);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    return new DOMXPath($dom);
}
function competitor_days(string $body): array {
    $result = [];
    foreach (competitor_xpath($body)->query('//a[contains(concat(" ",normalize-space(@class)," ")," history-item ")]//strong') as $node) $result[] = $node->textContent;
    return $result;
}
function competitor_path(array $params): string { return '/?' . http_build_query($params); }
function competitor_run(int $projectId, int $promptId, string $provider, string $at, array $snapshot, array $hits, ?string $savedAnswer, ?string $dailyAnswer, bool $legacy = false): int {
    $flags = [];
    for ($i=0;$i<3;$i++) $flags[] = isset($snapshot[$i]) && in_array($snapshot[$i],$hits,true) ? 1 : 0;
    db_exec('INSERT INTO runs(project_id,prompt_id,provider,run_at,day_key,hour,target_main_text,target_sub1_text,target_sub2_text,target_sub3_text,target_sub_texts,hit_sub1,hit_sub2,hit_sub3,hit_sub,response_text,model) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$projectId,$promptId,$provider,$at,substr($at,0,10),(int)substr($at,11,2),'自社',$snapshot[0] ?? null,$snapshot[1] ?? null,$snapshot[2] ?? null,json_encode($snapshot,JSON_UNESCAPED_UNICODE),...$flags,$legacy ? null : json_encode($hits,JSON_UNESCAPED_UNICODE),$savedAnswer,'test']);
    $id = (int)db()->lastInsertId();
    if ($dailyAnswer !== null) db_exec('INSERT INTO daily_logs(project_id,prompt_id,provider,day_key,run_at,response_text) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE run_at=VALUES(run_at),response_text=VALUES(response_text)', [$projectId,$promptId,$provider,substr($at,0,10),$at,$dailyAnswer]);
    return $id;
}
try {
    $password = bin2hex(random_bytes(12)); $emails = [];
    for ($i=0;$i<2;$i++) {
        $emails[] = 'competitor-test-' . bin2hex(random_bytes(8)) . '@example.invalid';
        db_exec('INSERT INTO users(email,password_hash) VALUES(?,?)', [$emails[$i],password_hash($password,PASSWORD_DEFAULT)]);
        $uids[] = (int)db()->lastInsertId();
    }
    for ($i=0;$i<2;$i++) {
        // Disabled projects/prompts ensure the real worker never runs these fixtures.
        db_exec('INSERT INTO projects(owner_user_id,name,is_active) VALUES(?,?,0)', [$uids[0],'競合履歴HTTP確認' . $i]);
        $projects[] = (int)db()->lastInsertId();
    }
    $id = $projects[0]; $target = '競合 & <比較>'; $snapshot = [$target,'競合B'];
    foreach (['main'=>'自社','sub'=>$target] as $type=>$text) db_exec('INSERT INTO project_domains(project_id,type,domain) VALUES(?,?,?)', [$id,$type,$text]);
    db_exec('INSERT INTO project_domains(project_id,type,domain) VALUES(?,?,?)', [$id,'sub','競合B']);
    $pids = [];
    foreach ([$id,$id,$projects[1]] as $i=>$projectId) {
        db_exec('INSERT INTO project_prompts(project_id,name,content,is_active) VALUES(?,?,?,0)', [$projectId,'質問' . $i . ' <script>alert(1)</script>','質問内容' . $i]);
        $pids[] = (int)db()->lastInsertId();
    }
    $before = competitor_run($id,$pids[0],'openai','2026-10-07 00:00:00',$snapshot,[$target],null,'OUTSIDE-PERIOD');
    $missing = competitor_run($id,$pids[0],'openai','2026-10-08 08:00:00',$snapshot,[$target],null,null);
    competitor_run($id,$pids[0],'openai','2026-10-08 16:00:00',$snapshot,[],null,'LATER-ANSWER-NOT-THE-HIT');
    $saved = competitor_run($id,$pids[0],'openai','2026-10-09 08:00:00',$snapshot,[$target],openai_format_web_search_text('EARLY-SAVED-HIT ' . $target,[],[],['https://example.com/early']),null);
    competitor_run($id,$pids[0],'openai','2026-10-09 16:00:00',$snapshot,[],null,'LATER-SAME-DAY');
    $aligned = competitor_run($id,$pids[0],'openai','2026-10-10 00:00:00',$snapshot,[$target],null,openai_format_web_search_text('ALIGNED-LEGACY-HIT ' . $target . ' <script>alert(2)</script>',[$target],['https://example.com/source'],['https://example.com/cite']));
    competitor_run($id,$pids[1],'openai','2026-10-10 08:00:00',$snapshot,[$target],null,'SECOND-PROMPT-HIT ' . $target);
    $noHit = competitor_run($id,$pids[0],'openai','2026-10-11 00:00:00',$snapshot,[],null,openai_format_web_search_text('NO-BODY-HIT',[$target],['https://example.com/'],[]));
    competitor_run($id,$pids[0],'openai','2026-10-12 00:00:00',['競合B',$target],[$target],null,'REORDERED-SNAPSHOT-HIT ' . $target,true);
    competitor_run($id,$pids[0],'openai','2026-10-13 00:00:00',['置き換えた競合'],['置き換えた競合'],null,'REPLACEMENT-HIT');
    competitor_run($id,$pids[0],'openai','2026-10-14 00:00:00',$snapshot,[],null,'LATEST-NO-HIT');
    $gemini = competitor_run($id,$pids[0],'gemini','2026-10-14 00:00:00',$snapshot,[$target],null,'GEMINI-HIT ' . $target);
    $foreign = competitor_run($projects[1],$pids[2],'openai','2026-10-10 00:00:00',$snapshot,[$target],null,'FOREIGN-PROJECT-ANSWER');
    $params = ['newa'=>'competitor','id'=>$id,'target'=>$target,'provider'=>'openai','start'=>'2026-10-08','end'=>'2026-10-14'];
    ensure_competitor(competitor_request(competitor_path($params))[0] === 302,'Anonymous history redirects to login');
    foreach ($emails as $i=>$email) {
        [, $login] = competitor_request('/?a=login');
        ensure_competitor((bool)preg_match('/name="csrf" value="([^"]+)"/', $login, $m),'Login token available');
        ensure_competitor(competitor_request('/?a=login',['csrf'=>$m[1],'email'=>$email,'password'=>$password])[0] === 302,'Login succeeds');
        [$status,$body] = competitor_request(competitor_path($params));
        ensure_competitor($status === ($i === 0 ? 200 : 404),'Project ownership applies to competitor history');
        if ($i === 0) {
            ensure_competitor(competitor_days($body) === ['2026-10-12','2026-10-10','2026-10-09','2026-10-08'],'Only positive days, within the period, ordered newest first');
            ensure_competitor(str_contains($body,'5 / 9回で出現') && str_contains($body,'55.6'),'Rate denominator includes negative measurements and excludes unmeasured replacements');
            ensure_competitor(str_contains($body,'REORDERED-SNAPSHOT-HIT') && !str_contains($body,'LATEST-NO-HIT'),'Newest positive answer selected, using its historical target slot');
            if (isset($argv[2])) file_put_contents($argv[2], str_replace('<head>', '<head><base href="' . h($base) . '">', $body));
            ensure_competitor(!str_contains($body,'<script>alert(1)</script>') && str_contains($body,'&lt;比較&gt;'),'Competitor and prompt names escaped');
            $xpath = competitor_xpath($body);
            ensure_competitor($xpath->query('//input[@name="target"]')->item(0)->getAttribute('value') === $target,'Period form preserves competitor');
            [, $overview] = competitor_request(competitor_path(array_replace($params,['newa'=>'overview'])));
            $links = competitor_xpath($overview)->query('//a[@class="rate-link"]');
            ensure_competitor($links->length === 2,'Only positive competitor rates become links');
            foreach ($links as $link) {
                parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$query);
                ensure_competitor($query['newa'] === 'competitor' && $query['target'] === $target && $query['start'] === $params['start'] && $query['end'] === $params['end'],'Overview carries competitor and period into history');
            }
            [, $body] = competitor_request(competitor_path($params+['day'=>'2026-10-10','rid'=>$aligned]));
            ensure_competitor(str_contains($body,'ALIGNED-LEGACY-HIT') && str_contains($body,'質問0') && str_contains($body,'質問1'),'All prompts on the selected positive day are available');
            ensure_competitor(str_contains($body,'https://example.com/cite') && str_contains($body,'id="answer-raw"') && !str_contains($body,'<script>alert(2)</script>'),'Search information, copying and safe answer rendering');
            [, $body] = competitor_request(competitor_path($params+['rid'=>$saved]));
            ensure_competitor(str_contains($body,'EARLY-SAVED-HIT') && !str_contains($body,'LATER-SAME-DAY'),'Exact saved run answer survives a later daily overwrite');
            [, $body] = competitor_request(competitor_path($params+['rid'=>$missing]));
            ensure_competitor(str_contains($body,'この出現時点の回答本文は保存されていません') && !str_contains($body,'LATER-ANSWER-NOT-THE-HIT'),'Do not substitute a mismatched final answer for a missing hit answer');
            [, $body] = competitor_request(competitor_path(array_replace($params,['provider'=>'gemini'])));
            ensure_competitor(competitor_days($body) === ['2026-10-14'] && str_contains($body,'GEMINI-HIT') && !str_contains($body,'REORDERED-SNAPSHOT-HIT'),'Provider separation');
            foreach ([$foreign,$noHit,$before,$gemini] as $rid) ensure_competitor(competitor_request(competitor_path($params+['rid'=>$rid]))[0] === 404,'Foreign, negative, out-of-period and other-provider runs rejected');
            [, $body] = competitor_request(competitor_path(array_replace($params,['start'=>'2026-10-10','end'=>'2026-10-11'])));
            ensure_competitor(competitor_days($body) === ['2026-10-10'],'Period changes filter the positive days');
            [, $body] = competitor_request(competitor_path(array_replace($params,['target'=>'競合B'])));
            ensure_competitor(competitor_days($body) === [] && str_contains($body,'競合が出現した日はありません'),'Known competitor with no hits has an empty state');
            ensure_competitor(competitor_request(competitor_path(array_replace($params,['target'=>'未登録競合'])))[0] === 404,'Unknown competitors rejected');
            ensure_competitor(competitor_request(competitor_path(array_replace($params,['target'=>['invalid']])))[0] === 404,'Malformed target rejected');
            [, $body] = competitor_request(competitor_path(['newa'=>'prompt','id'=>$id,'pid'=>$pids[0],'start'=>$params['start'],'end'=>$params['end'],'provider'=>'openai','day'=>'2026-10-08']));
            ensure_competitor(str_contains($body,'LATER-ANSWER-NOT-THE-HIT') && str_contains($body,'この日の最終回答'),'Regular prompt detail retains the daily final answer');
        } else {
            db_exec('INSERT INTO project_members(project_id,user_id,role) VALUES(?,?,?)',[$id,$uids[1],'member']);
            ensure_competitor(competitor_request(competitor_path($params))[0] === 200,'Authorized project members can use the history');
        }
        competitor_request('/?a=logout');
    }
    echo "Competitor HTTP navigation, positive days, rates, snapshots, precise answers, missing history, providers, periods, escaping, authorization and prompt regression passed\n";
} finally {
    foreach ($projects as $projectId) db_exec('DELETE FROM projects WHERE id=?',[$projectId]);
    foreach ($uids as $uid) db_exec('DELETE FROM users WHERE id=?',[$uid]);
    unlink($cookie);
}
