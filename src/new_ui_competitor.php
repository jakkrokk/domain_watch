<?php
declare(strict_types=1);
require_once __DIR__ . '/domain_match.php';

// Use the recorded target slot, so reordering or replacing competitors cannot
// attribute a historical hit to a different company.
function nu_competitor_hit(array $run, string $target, ?int $fallbackSlot = null): ?bool {
    $target = normalize_target_text($target);
    if ($target === '') return null;
    $snapshot = json_decode((string)($run['target_sub_texts'] ?? ''), true);
    $snapshot = is_array($snapshot) ? array_values($snapshot) : [$run['target_sub1_text'] ?? null,$run['target_sub2_text'] ?? null,$run['target_sub3_text'] ?? null];
    $slot = array_search($target, $snapshot, true);
    if (array_filter($snapshot, static fn($v) => $v !== null && $v !== '') && $slot === false) return null;
    $hits = json_decode((string)($run['hit_sub'] ?? ''), true);
    if (is_array($hits)) return in_array($target, $hits, true);
    $slot = $slot === false ? $fallbackSlot : $slot;
    return $slot === null ? null : (int)($run['hit_sub' . ($slot+1)] ?? 0) === 1;
}

function nu_competitor_answer(int $projectId, int $runId): ?array {
    $row = db_one('SELECT r.run_at,r.response_text,d.response_text AS daily_response_text,d.search_suggestions FROM runs r LEFT JOIN daily_logs d ON d.project_id=r.project_id AND d.prompt_id=r.prompt_id AND d.provider=r.provider AND d.day_key=r.day_key AND d.run_at=r.run_at WHERE r.project_id=? AND r.id=?', [$projectId,$runId]);
    if (!$row) return null;
    $text = $row['response_text'] ?? $row['daily_response_text'];
    if ($text === null || trim($text) === '') return null;
    return ['run_at'=>$row['run_at'],'response_text'=>$text,'search_suggestions'=>$row['search_suggestions']];
}

function nu_competitor_page(array $project, array $period, array $subs, array $prompts, array $runs): void {
    [$start,$end] = $period;
    $projectId = (int)$project['id'];
    $target = $_GET['target'] ?? '';
    if (!is_string($target) || trim($target) === '' || mb_strlen($target) > 255) { http_response_code(404); echo '見つかりません'; return; }
    $target = normalize_target_text($target);
    $slot = array_search($target, array_map('normalize_target_text', $subs), true);
    $provider = is_string($_GET['provider'] ?? null) && in_array($_GET['provider'], ['openai','gemini'], true) ? $_GET['provider'] : 'openai';
    $providerName = $provider === 'gemini' ? 'Gemini' : 'ChatGPT';
    $stats = ['openai'=>nu_empty_stats(0),'gemini'=>nu_empty_stats(0)];
    $dayStats = []; $matches = []; $byDay = [];
    foreach ($runs as $run) {
        if ($run['day_key'] < $start) continue;
        $hit = nu_competitor_hit($run, $target, $slot === false ? null : $slot);
        if ($hit === null) continue;
        $p = $run['provider'];
        $stats[$p]['n']++;
        $stats[$p]['hits'] += (int)$hit;
        if ($p !== $provider) continue;
        $day = $run['day_key'];
        if (!isset($dayStats[$day])) $dayStats[$day] = nu_empty_stats(0);
        $dayStats[$day]['n']++;
        $dayStats[$day]['hits'] += (int)$hit;
        if ($hit) { $matches[(int)$run['id']] = $run; $byDay[$day][] = $run; }
    }
    if ($slot === false && !$stats['openai']['n'] && !$stats['gemini']['n']) { http_response_code(404); echo '見つかりません'; return; }
    $requestedDay = nu_date((string)($_GET['day'] ?? ''), '');
    $selectedRun = null;
    if (isset($_GET['rid'])) {
        $rid = filter_var($_GET['rid'], FILTER_VALIDATE_INT);
        if (!$rid || !isset($matches[$rid])) { http_response_code(404); echo '見つかりません'; return; }
        $selectedRun = $matches[$rid];
    } else {
        foreach ($matches as $run) {
            if (!$requestedDay || $run['day_key'] === $requestedDay) { $selectedRun = $run; break; }
        }
    }
    $selectedDay = $selectedRun['day_key'] ?? $requestedDay;
    $log = $selectedRun ? nu_competitor_answer($projectId, (int)$selectedRun['id']) : null;
    $promptMap = array_column($prompts, null, 'id');
    $base = ['id'=>$projectId,'start'=>$start,'end'=>$end,'target'=>$target];
    nu_header($target . 'の出現した日', $project, 'competitor', $period);
    echo '<a class="back-link" href="' . h(nu_url('overview', ['id'=>$projectId,'start'=>$start,'end'=>$end])) . '">← 概要・比較</a>';
    nu_heading('COMPETITOR DETAIL', $target . 'の出現した日', '回答本文にこの競合が出現した日と、その時のプロンプト・回答を確認できます。');
    nu_period($projectId, 'competitor', $period, ['target'=>$target,'provider'=>$provider]);
    echo '<div class="detail-metrics">';
    foreach (['openai'=>'ChatGPT','gemini'=>'Gemini'] as $p=>$label) {
        echo '<section class="panel detail-metric"><span class="provider-label ' . $p . '"><i></i>' . $label . '</span>'; nu_rate($stats[$p],$p); echo '</section>';
    }
    echo '</div><nav class="provider-tabs" aria-label="回答のAIサービス">';
    foreach (['openai'=>'ChatGPT','gemini'=>'Gemini'] as $p=>$label) echo '<a class="' . ($p === $provider ? 'selected ' : '') . $p . '"' . ($p === $provider ? ' aria-current="page"' : '') . ' href="' . h(nu_url('competitor',$base+['provider'=>$p])) . '"><span class="provider-label ' . $p . '"><i></i>' . $label . '</span></a>';
    echo '</nav><div class="answer-layout"><aside class="panel history-panel"><div class="panel-heading"><h2>出現した日</h2><p>' . h($providerName) . ' · ' . count($byDay) . '日</p></div><div class="history-list">';
    foreach ($byDay as $day=>$dayMatches) {
        $n = count($dayMatches); $promptCount = count(array_unique(array_column($dayMatches, 'prompt_id')));
        echo '<a class="history-item ' . ($day === $selectedDay ? 'selected' : '') . '" href="' . h(nu_url('competitor',$base+['provider'=>$provider,'day'=>$day])) . '"><span><strong>' . h($day) . '</strong><small>出現' . $n . '回 · ' . $promptCount . 'プロンプト</small></span><span class="history-pct">' . h(nu_number(nu_pct($dayStats[$day]))) . '%</span></a>';
    }
    if (!$byDay) echo '<div class="history-empty">この期間に、このサービスで競合が出現した日はありません。</div>';
    echo '</div><p class="history-note">出現した日だけを表示しています。日別の出現率は、その日の計測回数を母数にしています。</p></aside><div class="answer-main">';
    if (isset($byDay[$selectedDay])) {
        echo '<section class="panel competitor-prompts"><div class="panel-heading"><h2>' . h($selectedDay) . 'に出現したプロンプト</h2><p>回答を選ぶと、下に全文と検索情報を表示します。</p></div><div class="table-scroll"><table class="comparison-table"><thead><tr><th>プロンプト</th><th>実行時刻</th><th></th></tr></thead><tbody>';
        foreach ($byDay[$selectedDay] as $run) {
            $pr = $promptMap[$run['prompt_id']] ?? ['name'=>'#' . $run['prompt_id'],'content'=>''];
            $isSelected = (int)$run['id'] === (int)($selectedRun['id'] ?? 0);
            $url = nu_url('competitor',$base+['provider'=>$provider,'day'=>$selectedDay,'rid'=>$run['id']]);
            echo '<tr' . ($isSelected ? ' class="selected-answer"' : '') . '><td><a class="prompt-link" href="' . h($url) . '">' . h($pr['name']) . '</a></td><td>' . h(substr($run['run_at'],11,8)) . '</td><td><a href="' . h($url) . '"' . ($isSelected ? ' aria-current="true"' : '') . '>' . ($isSelected ? '表示中' : '回答を見る') . '</a></td></tr>';
        }
        echo '</tbody></table></div></section>';
    }
    if ($selectedRun) {
        $pr = $promptMap[$selectedRun['prompt_id']] ?? ['name'=>'#' . $selectedRun['prompt_id'],'content'=>''];
        echo '<details class="panel prompt-question"><summary>' . h($pr['name']) . '：登録されている質問内容<span>⌄</span></summary><div>' . nl2br(h($pr['content'])) . '<p><a href="' . h(nu_url('prompt', ['id'=>$projectId,'pid'=>$selectedRun['prompt_id'],'start'=>$start,'end'=>$end,'provider'=>$provider,'day'=>$selectedDay])) . '">このプロンプトの全計測履歴を見る →</a></p></div></details>';
    }
    $emptyText = $selectedRun ? 'この出現時点の回答本文は保存されていません。出現の計測結果は残っていますが、日次ログにはその日の最終回答のみが保存されています。' : '選択した期間・日付・サービスには、この競合が出現した回答がありません。';
    nu_answer_panels($log, $providerName, $selectedDay, '競合が出現した時の回答', $emptyText);
    echo '</div></div>'; nu_footer();
}
