<?php
declare(strict_types=1);

function shot_providers(): array {
    $providers = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)env_get('RUNNER_PROVIDERS', 'openai,gemini'))))));
    return array_values(array_filter($providers, static fn($p) => in_array($p, ['openai','gemini'], true) && (bool)env_get(strtoupper($p) . '_API_KEY')));
}

function shot_validate(array $input): array {
    foreach (['name','main','prompts'] as $key) {
        if (!isset($input[$key]) || !is_string($input[$key])) throw new InvalidArgumentException('ショット名、自社、プロンプトを入力してください。');
    }
    $name = trim($input['name']);
    $main = normalize_target_text($input['main']);
    if ($name === '' || $main === '' || mb_strlen($name) > 255 || mb_strlen($main) > 255) throw new InvalidArgumentException('ショット名と自社を255文字以内で入力してください。');
    $postedSubs = $input['sub'] ?? [];
    if (!is_array($postedSubs) || count($postedSubs) > 5) throw new InvalidArgumentException('計測したい文字列は最大5件です。');
    $subs = [];
    foreach ($postedSubs as $sub) {
        if (!is_string($sub) || mb_strlen($sub) > 255) throw new InvalidArgumentException('計測したい文字列を255文字以内で入力してください。');
        $sub = normalize_target_text($sub);
        if ($sub !== '') $subs[] = $sub;
    }
    $prompts = array_values(array_filter(array_map('trim', preg_split('/\R/u', $input['prompts']) ?: []), static fn($p) => $p !== ''));
    if (!$prompts || count($prompts) > 50) throw new InvalidArgumentException('プロンプトは1行1件で、1〜50件を入力してください。');
    foreach ($prompts as $prompt) if (strlen($prompt) > 65535) throw new InvalidArgumentException('1件のプロンプトが長すぎます。');
    return [$name, $main, $subs, $prompts];
}

function shot_create(int $uid, array $input, array $providers): int {
    [$name, $main, $subs, $prompts] = shot_validate($input);
    if (!$providers || array_diff($providers, ['openai','gemini'])) throw new InvalidArgumentException('利用可能なAIサービスがありません。管理者にお問い合わせください。');
    db()->beginTransaction();
    try {
        db_exec('INSERT INTO shots(owner_user_id,name,target_main_text,target_sub_texts) VALUES(?,?,?,?)', [$uid,$name,$main,json_encode($subs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $id = (int)db()->lastInsertId();
        foreach ($prompts as $index => $prompt) foreach (array_unique($providers) as $provider) {
            db_exec('INSERT INTO shot_results(shot_id,prompt_index,prompt_content,provider) VALUES(?,?,?,?)', [$id,$index+1,$prompt,$provider]);
        }
        db()->commit();
        return $id;
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function shot_status_label(string $status): string {
    return ['queued'=>'受付済み','running'=>'調査中','completed'=>'完了','partial'=>'一部失敗','failed'=>'失敗'][$status] ?? $status;
}

// Called only by the CLI worker under an exclusive lock. Persist each answer as it arrives.
function shot_process(array $shot, ?callable $chat = null): void {
    $id = (int)$shot['id'];
    if (!in_array($shot['status'], ['queued','running'], true)) return;
    $subs = json_decode($shot['target_sub_texts'], true, 512, JSON_THROW_ON_ERROR);
    db_exec("UPDATE shots SET status='running',started_at=COALESCE(started_at,NOW()) WHERE id=?", [$id]);
    // A previous worker stopped mid-request. Do not issue the same paid request automatically.
    db_exec("UPDATE shot_results SET status='failed',error_message=?,finished_at=NOW() WHERE shot_id=? AND status='running'", ['処理が中断されました。必要に応じて新しいショットで再調査してください。',$id]);
    $chat ??= static fn($provider,$prompt,$runId) => $provider === 'gemini' ? gemini_chat($prompt,$runId) : openai_chat($prompt,$runId);
    foreach (db_all("SELECT * FROM shot_results WHERE shot_id=? AND status='queued' ORDER BY prompt_index,provider", [$id]) as $result) {
        $rid = (int)$result['id'];
        db_exec("UPDATE shot_results SET status='running' WHERE id=?", [$rid]);
        try {
            $resp = $chat($result['provider'], $result['prompt_content'], 'shot-' . $id . '-' . $rid . '-' . bin2hex(random_bytes(4)));
            $text = (string)$resp['text'];
            $answer = (string)($resp['answer_text'] ?? $text);
            if (trim($answer) === '') throw new RuntimeException('AIサービスから空の回答が返りました。');
            $hits = array_map(static fn($s) => (int)text_contains_target($answer, $s), $subs);
            db_exec("UPDATE shot_results SET status='completed',hit_main=?,hit_sub=?,response_text=?,search_suggestions=?,model=?,tokens_in=?,tokens_out=?,latency_ms=?,search_performed=?,finished_at=NOW() WHERE id=?", [
                (int)text_contains_target($answer,$shot['target_main_text']),json_encode($hits,JSON_THROW_ON_ERROR),$text,$resp['search_suggestions'] ?? null,$resp['model'] ?? null,$resp['tokens_in'] ?? null,$resp['tokens_out'] ?? null,$resp['latency_ms'] ?? null,isset($resp['search_performed']) ? (int)$resp['search_performed'] : null,$rid,
            ]);
        } catch (Throwable $e) {
            error_log('Shot result ' . $rid . ': ' . $e->getMessage());
            db_exec("UPDATE shot_results SET status='failed',error_message=?,finished_at=NOW() WHERE id=?", ['回答を取得できませんでした。必要に応じて新しいショットで再調査してください。',$rid]);
        }
    }
    $counts = db_one("SELECT COUNT(*) n,SUM(status='completed') ok FROM shot_results WHERE shot_id=?", [$id]);
    $status = (int)$counts['ok'] === (int)$counts['n'] ? 'completed' : ((int)$counts['ok'] ? 'partial' : 'failed');
    db_exec('UPDATE shots SET status=?,finished_at=NOW() WHERE id=?', [$status,$id]);
}

function shot_start_worker(): void {
    // Best-effort immediate start; the every-minute cron also picks up persisted jobs.
    if (!is_callable('exec')) return;
    $php = (string)env_get('SHOT_PHP_BINARY', '/usr/bin/php');
    $command = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/../bin/shot_runner.php') . ' >> ' . escapeshellarg(__DIR__ . '/../storage/logs/shots.log') . ' 2>&1 < /dev/null &';
    try { exec($command); } catch (Throwable $e) { error_log('Shot worker launch failed: ' . $e->getMessage()); }
}
