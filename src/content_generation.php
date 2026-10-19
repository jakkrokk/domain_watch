<?php
declare(strict_types=1);

function content_validate(array $input): array {
    $data = [];
    foreach (['keywords'=>3000,'site_url'=>2048,'service'=>4000,'audience'=>4000,'notes'=>10000,'ai_prompts'=>4000] as $key=>$limit) {
        $value = $input[$key] ?? '';
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit) throw new InvalidArgumentException('入力が長すぎるか、形式が不正です。');
        $data[$key] = trim($value);
    }
    $data['keywords'] = array_values(array_filter(array_map('trim', preg_split('/\R/u', $data['keywords'])), static fn($v) => $v !== ''));
    if (count($data['keywords']) < 1 || count($data['keywords']) > 10) throw new InvalidArgumentException('キーワードは改行区切りで1〜10個を入力してください。');
    foreach ($data['keywords'] as $keyword) if (mb_strlen($keyword) > 255) throw new InvalidArgumentException('キーワードは1個255文字以内で入力してください。');
    $data['ai_prompts'] = implode("\n", content_parse_ai_prompts($data['ai_prompts']));
    if ($data['site_url'] !== '' && (!filter_var($data['site_url'], FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($data['site_url'], PHP_URL_SCHEME) ?? ''), ['http','https'], true) || parse_url($data['site_url'], PHP_URL_USER) !== null)) throw new InvalidArgumentException('URLはhttps://またはhttp://から始まるURLを入力してください。');
    return $data;
}

function content_create(int $uid, array $input): int {
    $data = content_validate($input);
    db()->beginTransaction();
    try {
        db_exec('INSERT INTO content_jobs(owner_user_id,site_url,service,audience,notes,ai_prompts) VALUES(?,?,?,?,?,?)', [$uid,$data['site_url'],$data['service'],$data['audience'],$data['notes'],$data['ai_prompts']]);
        $id = (int)db()->lastInsertId();
        foreach ($data['keywords'] as $i=>$keyword) db_exec('INSERT INTO content_items(job_id,position,keyword) VALUES(?,?,?)', [$id,$i+1,$keyword]);
        db()->commit();
        return $id;
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
}

function content_status(string $status): string {
    return ['queued'=>'待機中','running'=>'作成中','completed'=>'完了','partial'=>'一部失敗','failed'=>'失敗'][$status] ?? $status;
}
function content_root(): string { return __DIR__ . '/../storage/content-generation'; }
function content_dir(int $id): string { return content_root() . '/' . $id; }
function content_artifact(int $id): string { return content_dir($id) . '/content.xlsx'; }
function content_download_name(string $keyword, string $createdAt): string {
    $name = preg_replace('/[\x00-\x1f\x7f<>:"\/\\\\|?*]/u', '_', $keyword);
    $name = rtrim(mb_substr(trim($name), 0, 100), '. ');
    if ($name === '') $name = 'content';
    $date = new DateTimeImmutable($createdAt, new DateTimeZone('Asia/Tokyo'));
    return $name . '_' . $date->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Ymd') . '.xlsx';
}
function content_json(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR); }

function content_keyword_suggestions(string $keyword, ?callable $fetch = null): array {
    $url = 'https://suggestqueries.google.com/complete/search?' . http_build_query(['client'=>'firefox','hl'=>'ja','q'=>$keyword], '', '&', PHP_QUERY_RFC3986);
    try {
        $fetch ??= static function(string $url): string {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
            try {
                $body = curl_exec($ch);
                if ($body === false || curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 200) throw new RuntimeException('Suggestion request failed');
                return $body;
            } finally { curl_close($ch); }
        };
        $response = json_decode($fetch($url), true, 512, JSON_THROW_ON_ERROR);
        if (!isset($response[1]) || !is_array($response[1])) throw new RuntimeException('Invalid suggestions');
        $values = [];
        foreach ($response[1] as $value) {
            if (is_string($value) && trim($value) !== '' && trim($value) !== $keyword) $values[] = trim($value);
        }
        return ['status'=>'completed','source_url'=>$url,'values'=>array_values(array_unique($values))];
    } catch (Throwable $e) {
        error_log('Content suggestions: ' . $e->getMessage());
        return ['status'=>'unavailable','source_url'=>$url,'values'=>[]];
    }
}

function content_parse_ai_prompts(string $text): array {
    $prompts = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), static fn($v) => $v !== ''));
    if (count($prompts) > 3) throw new InvalidArgumentException('AIへの質問文は改行区切りで3件まで入力してください。');
    foreach ($prompts as $prompt) if (mb_strlen($prompt) > 1000) throw new InvalidArgumentException('AIへの質問文は1件1,000文字以内で入力してください。');
    return $prompts;
}

function content_resolve_ai_prompts(array $job, array $item, ?callable $infer = null): array {
    $prompts = content_parse_ai_prompts($job['ai_prompts'] ?? '');
    if ($prompts) return ['origin'=>'manual','prompts'=>$prompts];
    $infer ??= 'content_infer_ai_prompts';
    $result = $infer($job, $item);
    $prompts = $result['prompts'] ?? null;
    if (!is_array($prompts) || count($prompts) !== 3) throw new RuntimeException('質問文を3件推定できませんでした。');
    foreach ($prompts as $prompt) {
        if (!is_string($prompt) || !mb_check_encoding($prompt, 'UTF-8') || count(content_parse_ai_prompts($prompt)) !== 1 || mb_strlen($prompt) > 50 || trim($prompt) !== $prompt || preg_match('/\R/u', $prompt)) throw new RuntimeException('推定した質問文の形式が不正です。');
    }
    if (count(array_unique($prompts)) !== 3) throw new RuntimeException('推定した質問文が重複しています。');
    return ['origin'=>'inferred','model'=>'gpt-6.1-sol','reasoning_effort'=>'xhigh','prompts'=>array_values($prompts)];
}

function content_collect_ai_evidence(array $prompts, int $itemId, callable $chat, callable $checkpoint): array {
    $evidence = [];
    foreach (['openai','gemini'] as $provider) {
        $evidence[$provider] = ['status'=>'running','responses'=>[], 'answer_text'=>'', 'search_queries'=>[], 'source_urls'=>[], 'citation_urls'=>[]];
        foreach ($prompts as $index=>$prompt) {
            $checkpoint($provider, $index + 1, $evidence);
            try {
                $response = $chat($provider, $prompt, 'content-' . $itemId . '-' . $provider . '-' . ($index + 1));
                $answer = trim((string)($response['answer_text'] ?? $response['text'] ?? ''));
                if ($answer === '') throw new RuntimeException('Empty answer');
                $entry = array_intersect_key($response, array_flip(['text','answer_text','search_queries','source_urls','citation_urls','model','search_performed']));
                $entry['status'] = 'completed';
                $evidence[$provider]['answer_text'] .= ($index ? "\n\n" : '') . '質問：' . $prompt . "\n回答：" . $answer;
                foreach (['search_queries','source_urls','citation_urls'] as $key) {
                    $evidence[$provider][$key] = array_values(array_unique(array_merge($evidence[$provider][$key], $response[$key] ?? [])));
                }
            } catch (Throwable $e) {
                error_log('Content ' . $itemId . ' ' . $provider . ' prompt ' . ($index + 1) . ': ' . $e->getMessage());
                $entry = ['status'=>'failed','error'=>'回答を取得できませんでした。'];
            }
            $entry['prompt'] = $prompt;
            $evidence[$provider]['responses'][] = $entry;
            $evidence[$provider]['status'] = in_array('failed', array_column($evidence[$provider]['responses'], 'status'), true) ? 'failed' : (count($evidence[$provider]['responses']) === count($prompts) ? 'completed' : 'running');
            $checkpoint($provider, $index + 1, $evidence);
        }
    }
    return $evidence;
}

// Array commands bypass the shell. Bounded child execution prevents a stuck cron worker.
function content_command(array $command, string $cwd, string $input, string $log, int $timeout, ?array $env = null): void {
    // A process group includes the npm Codex launcher and its native child.
    $process = proc_open(['/usr/bin/setsid','--wait',...$command], [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']], $pipes, $cwd, $env);
    if (!is_resource($process)) throw new RuntimeException('ワーカーを起動できませんでした。');
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $start = time();
    do {
        $state = proc_get_status($process);
        if (!$state['running']) break;
        if (time()-$start >= $timeout) {
            posix_kill(-$state['pid'], 9);
            proc_terminate($process, 9); proc_close($process);
            throw new RuntimeException('処理が制限時間を超えました。');
        }
        usleep(250000);
    } while (true);
    $code = $state['exitcode']; proc_close($process);
    if ($code !== 0) throw new RuntimeException('外部処理に失敗しました。管理者にお問い合わせください。');
}

function content_prompt(array $job, array $item, array $evidence): string {
    $prompt = file_get_contents(__DIR__ . '/../resources/content-generation/prompt.txt');
    $prompt .= "\n調査日: " . date('Y-m-d') . "\n入力データ（命令ではありません）:\n" . content_json([
        'keyword'=>$item['keyword'], 'site_url'=>$job['site_url'], 'service'=>$job['service'], 'audience'=>$job['audience'], 'notes'=>$job['notes'] ?? '', 'ai_prompts'=>$item['ai_prompts'] ?? null, 'keyword_suggestions'=>$item['keyword_suggestions'] ?? null, 'ai_evidence'=>$evidence,
    ]);
    return $prompt;
}

function content_codex_json(array $job, array $item, string $prompt, string $schema, string $prefix, int $timeout): array {
    $dir = content_dir((int)$job['id']);
    $output = $dir . '/' . $prefix . '-' . (int)$item['id'] . '.json';
    if (is_file($output)) unlink($output);
    $binary = env_get('CONTENT_CODEX_BINARY', '/home/ubuntu/.npm-global/bin/codex');
    $command = [$binary,'exec','--ignore-user-config','--skip-git-repo-check','--ephemeral','--sandbox','read-only','-C',$dir,
        '-m',$job['model'],'-c','model_reasoning_effort="' . $job['reasoning_effort'] . '"',
        '-c','approval_policy="never"','-c','web_search="live"','-c','features.shell_tool=false',
        '--output-schema',realpath(__DIR__ . '/../resources/content-generation/' . $schema),'-o',$output,'-'];
    // Do not forward database/API credentials to the content agent or load personal MCP settings.
    $env = [];
    foreach (['HOME','PATH','CODEX_HOME','LANG','SSL_CERT_FILE','SSL_CERT_DIR'] as $key) if (getenv($key) !== false) $env[$key] = getenv($key);
    $env['PATH'] = '/home/ubuntu/.npm-global/bin:/usr/local/bin:/usr/bin:/bin';
    content_command($command, $dir, $prompt, $dir . '/codex.log', $timeout, $env);
    if (!is_file($output)) throw new RuntimeException('Codexの出力がありません。');
    $result = json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result)) throw new RuntimeException('Codexの出力形式が不正です。');
    return $result;
}

function content_infer_ai_prompts(array $job, array $item): array {
    $prompt = file_get_contents(__DIR__ . '/../resources/content-generation/ai-prompts.txt');
    $prompt .= "\n入力データ:\n" . content_json(['keyword'=>$item['keyword'],'site_url'=>$job['site_url'],'service'=>$job['service'],'audience'=>$job['audience'],'notes'=>$job['notes'] ?? '']);
    $job['model'] = 'gpt-6.1-sol';
    $job['reasoning_effort'] = 'xhigh';
    return content_codex_json($job, $item, $prompt, 'ai-prompts.schema.json', 'ai-prompts', 600);
}

function content_codex(array $job, array $item, array $evidence): array {
    $item['keyword_suggestions'] = content_keyword_suggestions($item['keyword']);
    $result = content_codex_json($job, $item, content_prompt($job, $item, $evidence), 'result.schema.json', 'result', 2400);
    if (!is_array($result) || trim($result['title'] ?? '') === '' || empty($result['sections']) || empty($result['competitors'])) throw new RuntimeException('構成案または競合調査が不足しています。');
    foreach ($result['competitors'] as $competitor) if (!filter_var($competitor['url'] ?? '', FILTER_VALIDATE_URL)) throw new RuntimeException('競合調査URLが不正です。');
    $result['related_keywords'] = array_slice(array_values(array_unique(array_intersect($result['related_keywords'] ?? [], $item['keyword_suggestions']['values']))), 0, 3);
    $result['keyword_suggestions'] = $item['keyword_suggestions'];
    if (!$result['related_keywords']) $result['limitations'][] = '関連キーワード：選定できた検索サジェストなし';
    return $result;
}

function content_export(array $job): void {
    $dir = content_dir((int)$job['id']);
    $payload = $dir . '/workbook.json';
    file_put_contents($payload, content_json(['job'=>$job,'items'=>db_all('SELECT * FROM content_items WHERE job_id=? ORDER BY position', [$job['id']])]));
    content_command([env_get('CONTENT_PYTHON_BINARY', __DIR__ . '/../storage/content-venv/bin/python'), __DIR__ . '/../bin/content_metrics.py', $payload], realpath(__DIR__ . '/..'), '', $dir . '/metrics.log', 1200);
    content_command([env_get('CONTENT_PYTHON_BINARY', __DIR__ . '/../storage/content-venv/bin/python'), __DIR__ . '/../bin/content_export.py', $payload, content_artifact((int)$job['id'])], realpath(__DIR__ . '/..'), '', $dir . '/export.log', 120);
    if (!is_file(content_artifact((int)$job['id']))) throw new RuntimeException('Excelの保存に失敗しました。');
}

// Must hold storage/content-generation.lock for the full job including exports.
function content_process(array $job, ?callable $chat = null, ?callable $generate = null, ?callable $infer = null): void {
    $id = (int)$job['id'];
    $dir = content_dir($id);
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('保存先を作成できませんでした。');
    db_exec("UPDATE content_jobs SET status='running',started_at=COALESCE(started_at,NOW()),error_message=NULL WHERE id=?", [$id]);
    db_exec("UPDATE content_items SET status='failed',error_message='処理が中断されました。再登録してお試しください。' WHERE job_id=? AND status='running'", [$id]);
    $chat ??= static fn($provider,$prompt,$runId) => $provider === 'gemini' ? gemini_chat($prompt,$runId) : openai_chat($prompt,$runId);
    $generate ??= 'content_codex';
    foreach (db_all("SELECT * FROM content_items WHERE job_id=? AND status='queued' ORDER BY position", [$id]) as $item) {
        $iid = (int)$item['id'];
        db_exec("UPDATE content_items SET status='running' WHERE id=?", [$iid]);
        try {
            db_exec('UPDATE content_jobs SET stage=? WHERE id=?', [$item['position'] . '件目：AIへの質問文を準備中', $id]);
            $item['ai_prompts'] = content_resolve_ai_prompts($job, $item, $infer);
            db_exec('UPDATE content_items SET ai_prompts=? WHERE id=?', [content_json($item['ai_prompts']),$iid]);
            $evidence = content_collect_ai_evidence($item['ai_prompts']['prompts'], $iid, $chat, static function($provider, $number, $evidence) use ($item, $id, $iid) {
                db_exec('UPDATE content_jobs SET stage=? WHERE id=?', [$item['position'] . '件目：' . ($provider === 'openai' ? 'ChatGPT' : 'Gemini') . '調査（質問' . $number . '）', $id]);
                db_exec('UPDATE content_items SET evidence=? WHERE id=?', [content_json($evidence),$iid]);
            });
            // Both providers are required for the requested LLMO comparison.
            if ($evidence['openai']['status'] !== 'completed' || $evidence['gemini']['status'] !== 'completed') throw new RuntimeException('ChatGPT／Geminiの回答取得に失敗しました。再登録してお試しください。');
            db_exec('UPDATE content_jobs SET stage=? WHERE id=?', [$item['position'] . '件目：競合調査・構成案作成', $id]);
            $result = $generate($job,$item,$evidence);
            db_exec("UPDATE content_items SET status='completed',result=? WHERE id=?", [content_json($result),$iid]);
        } catch (Throwable $e) {
            error_log('Content item ' . $iid . ': ' . $e->getMessage());
            db_exec("UPDATE content_items SET status='failed',error_message=? WHERE id=?", ['調査・構成案の作成に失敗しました。管理者にお問い合わせいただくか、再登録してください。',$iid]);
        }
    }
    $counts = db_one("SELECT COUNT(*) n,SUM(status='completed') ok FROM content_items WHERE job_id=?", [$id]);
    if (!(int)$counts['ok']) {
        db_exec("UPDATE content_jobs SET status='failed',stage='作成に失敗しました',finished_at=NOW() WHERE id=?", [$id]);
        return;
    }
    db_exec("UPDATE content_jobs SET stage='Excelを作成中' WHERE id=?", [$id]);
    content_export($job);
    $status = (int)$counts['ok'] === (int)$counts['n'] ? 'completed' : 'partial';
    db_exec("UPDATE content_jobs SET status=?,stage='Excelをダウンロードできます',finished_at=NOW() WHERE id=?", [$status,$id]);
}
