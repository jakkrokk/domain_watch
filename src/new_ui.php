<?php

declare(strict_types=1);

require_once __DIR__ . '/new_ui_markdown.php';
require_once __DIR__ . '/answer_raw.php';
require_once __DIR__ . '/new_ui_answer.php';
require_once __DIR__ . '/new_ui_competitor.php';

// The preview has its own routes and assets; measurement and storage stay shared.
function nu_url(string $view = 'projects', array $params = []): string {
    return '/?' . http_build_query(['newa' => $view] + $params);
}
function nu_date(string $value, string $fallback): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Tokyo'));
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
}
function nu_range(array $query): array {
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
    $end = nu_date((string)($query['end'] ?? $query['date'] ?? ''), $today);
    $start = nu_date((string)($query['start'] ?? $query['date'] ?? ''), (new DateTimeImmutable($end))->modify('-6 days')->format('Y-m-d'));
    if ($start > $end) [$start, $end] = [$end, $start];
    $days = (int)(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
    if ($days > 366) { $start = (new DateTimeImmutable($end))->modify('-365 days')->format('Y-m-d'); $days = 366; }
    return [$start, $end, $days];
}
function nu_empty_stats(int $competitors): array {
    return ['n' => 0, 'hits' => 0, 'last' => null, 'subs' => array_fill(0, $competitors, ['n' => 0, 'hits' => 0])];
}
function nu_add(array &$stat, array $run, array $subs): void {
    $stat['n']++;
    $stat['hits'] += (int)$run['hit_main'];
    if (!$stat['last'] || $run['run_at'] > $stat['last']) $stat['last'] = $run['run_at'];
    foreach ($subs as $i => $target) {
        $hit = nu_competitor_hit($run, $target, $i);
        if ($hit === null) continue;
        $stat['subs'][$i]['n']++;
        $stat['subs'][$i]['hits'] += (int)$hit;
    }
}
function nu_pct(array $stat): ?float {
    return $stat['n'] ? round($stat['hits'] / $stat['n'] * 100, 1) : null;
}
function nu_number(?float $value): string {
    return $value === null ? '—' : rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
}
function nu_rate(array $stat, string $provider = '', ?string $url = null, string $linkLabel = ''): void {
    $pct = nu_pct($stat);
    if ($url) echo '<a class="rate-link" href="' . h($url) . '" aria-label="' . h($linkLabel) . '">';
    echo '<div class="rate ' . h($provider) . '"><strong>' . ($pct === null ? '—' : h(nu_number($pct)) . '<small>%</small>') . '</strong>';
    echo '<span>' . ($pct === null ? '計測データなし' : $stat['hits'] . ' / ' . $stat['n'] . '回で出現') . '</span></div>';
    if ($url) echo '</a>';
}
function nu_delta(array $current, array $previous): void {
    $a = nu_pct($current); $b = nu_pct($previous);
    if ($a === null || $b === null) { echo '<span class="delta neutral">前期間の比較データなし</span>'; return; }
    $delta = round($a - $b, 1);
    echo '<span class="delta ' . ($delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'neutral')) . '">' . ($delta > 0 ? '↗ +' : ($delta < 0 ? '↘ ' : '')) . h(nu_number($delta)) . ' pt<span> 前期間比</span></span>';
}
function nu_runs(int $id, string $start, string $end, ?int $prompt = null): array {
    return db_all('SELECT id,prompt_id,provider,day_key,run_at,hit_main,hit_sub1,hit_sub2,hit_sub3,hit_sub,target_main_text,target_sub1_text,target_sub2_text,target_sub3_text,target_sub_texts,model,search_performed FROM runs WHERE project_id=? AND provider IN (\'openai\',\'gemini\') AND day_key BETWEEN ? AND ?' . ($prompt ? ' AND prompt_id=?' : '') . ' ORDER BY run_at DESC,id DESC', $prompt ? [$id,$start,$end,$prompt] : [$id,$start,$end]);
}
function nu_split_raw(string $raw): array {
    return answer_split_raw($raw);
}
function nu_header(string $title, ?array $project, string $view, array $period): void {
    [$start, $end] = $period;
    $flash = flash_get();
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' · Domain Watch</title><link rel="stylesheet" href="/assets/new-ui.css?v=5"><script src="/assets/new-ui.js?v=2" defer></script></head><body>';
    echo '<aside class="sidebar"><a class="brand" href="' . h(nu_url()) . '"><span class="brand-mark">d<span>↗</span></span><span>Domain Watch<small>AI Visibility Monitor</small></span></a><nav aria-label="メインナビゲーション"><a class="nav-item ' . (!$project && !in_array($view, ['shots','shot_new','shot'], true) ? 'active' : '') . '" href="' . h(nu_url()) . '"><span>▦</span>プロジェクト一覧</a>';
    if ($view === 'projects') {
        echo '<button class="nav-item" type="button" data-open-create><span>＋</span>プロジェクト追加</button>';
    } else {
        echo '<a class="nav-item" href="' . h(nu_url('projects', ['create' => 1])) . '#create-panel"><span>＋</span>プロジェクト追加</a>';
    }
    echo '<a class="nav-item ' . (in_array($view, ['shots','shot_new','shot'], true) ? 'active' : '') . '" href="' . h(nu_url('shots')) . '"><span>◉</span>ショット一覧</a>';
    if ($project) {
        $params = ['id' => $project['id'], 'start' => $start, 'end' => $end];
        echo '<div class="nav-project">' . h($project['name']) . '</div>';
        foreach (['overview' => ['◫', '概要・比較'], 'prompts' => ['≡', 'プロンプト'], 'settings' => ['⚙', '設定']] as $key => [$icon, $label]) {
            echo '<a class="nav-item ' . (($view === $key || ($key === 'prompts' && $view === 'prompt') || ($key === 'overview' && $view === 'competitor')) ? 'active' : '') . '" href="' . h(nu_url($key, $params)) . '"><span>' . $icon . '</span>' . $label . '</a>';
        }
    }
    echo '</nav><div class="sidebar-bottom"><div class="live-indicator"><i></i>実際の計測データを表示</div><a href="' . ($project ? '/?a=trends&id=' . (int)$project['id'] : '/?a=projects') . '">従来のUIへ ↗</a></div></aside><div class="app"><header class="app-header"><span>ワークスペース <span class="breadcrumb-separator">/</span> ' . h($project ? $project['name'] : (in_array($view, ['shots','shot_new','shot'], true) ? 'ショット' : 'プロジェクト')) . '</span><a class="header-logout" href="/?a=logout">ログアウト</a></header><main>';
    if ($flash) echo '<div role="status" class="notice ' . h($flash['type']) . '">' . h($flash['msg']) . '</div>';
}
function nu_footer(): void { echo '<footer class="page-footer">Domain Watch <span>回答本文に対象テキストが含まれるかを計測しています。</span></footer></main></div></body></html>'; }
function nu_heading(string $eyebrow, string $title, string $description): void {
    echo '<div class="page-heading"><div><div class="eyebrow">' . h($eyebrow) . '</div><h1>' . h($title) . '</h1><p>' . h($description) . '</p></div></div>';
}
function nu_period(int $id, string $view, array $period, array $extra = []): void {
    [$start, $end, $days] = $period;
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
    echo '<section class="period-panel" aria-label="集計期間"><div class="period-shortcuts">';
    foreach ([1 => '今日', 7 => '過去7日', 30 => '過去30日'] as $n => $label) {
        $from = (new DateTimeImmutable($today))->modify('-' . ($n - 1) . ' days')->format('Y-m-d');
        echo '<a class="chip ' . ($from === $start && $end === $today ? 'selected' : '') . '" href="' . h(nu_url($view, ['id' => $id, 'start' => $from, 'end' => $today] + $extra)) . '">' . $label . '</a>';
    }
    echo '</div><form method="get" class="period-form"><input type="hidden" name="newa" value="' . h($view) . '"><input type="hidden" name="id" value="' . $id . '">';
    foreach ($extra as $key => $value) echo '<input type="hidden" name="' . h($key) . '" value="' . h((string)$value) . '">';
    echo '<label><span class="sr-only">開始日</span><input type="date" name="start" value="' . h($start) . '" required></label><span class="muted">—</span><label><span class="sr-only">終了日</span><input type="date" name="end" value="' . h($end) . '" required></label><button class="button secondary" type="submit">適用</button></form></section>';
}
function nu_prompt_table(array $prompts, array $stats, array $previous, int $id, array $period): void {
    echo '<div class="table-scroll"><table class="comparison-table"><thead><tr><th>プロンプト</th><th><span class="provider-label openai"><i></i>ChatGPT</span></th><th><span class="provider-label gemini"><i></i>Gemini</span></th><th class="last-column">最終計測</th><th><span class="sr-only">詳細</span></th></tr></thead><tbody>';
    foreach ($prompts as $prompt) {
        $pid = (int)$prompt['id']; $last = null;
        echo '<tr><td><a class="prompt-link" href="' . h(nu_url('prompt', ['id' => $id, 'pid' => $pid, 'start' => $period[0], 'end' => $period[1]])) . '">' . h($prompt['name']) . '</a><span class="cell-description">' . h(mb_strimwidth(preg_replace('/\s+/u', ' ', $prompt['content']), 0, 80, '…')) . '</span></td>';
        foreach (['openai', 'gemini'] as $provider) {
            $stat = $stats[$pid][$provider] ?? nu_empty_stats(0);
            if ($stat['last'] && (!$last || $stat['last'] > $last)) $last = $stat['last'];
            echo '<td>'; nu_rate($stat, $provider); nu_delta($stat, $previous[$pid][$provider] ?? nu_empty_stats(0)); echo '</td>';
        }
        echo '<td class="last-column"><span class="last-date">' . ($last ? h(substr($last,5,11)) : '未計測') . '</span></td><td><a class="arrow-link" aria-label="' . h($prompt['name']) . 'の詳細" href="' . h(nu_url('prompt', ['id' => $id, 'pid' => $pid, 'start' => $period[0], 'end' => $period[1]])) . '">→</a></td></tr>';
    }
    echo '</tbody></table></div>';
    if (!$prompts) echo '<div class="empty-state">プロンプトがまだありません。設定から追加してください。</div>';
}

$user = auth_user();
if (!$user) redirect('/?a=login&newui=1');
$uid = (int)$user['id'];
if (in_array((string)($_GET['newa'] ?? ''), ['shots','shot_new','shot'], true)) {
    require __DIR__ . '/shots_ui.php';
    return;
}
$projectId = (int)($_GET['id'] ?? 0);
$view = (string)($_GET['newa'] ?: ($projectId ? 'overview' : 'projects'));
if (!in_array($view, ['projects','overview','prompts','prompt','competitor','settings'], true)) { http_response_code(404); exit('not found'); }
$period = nu_range($_GET);
[$start, $end, $days] = $period;
$project = null;
if ($view !== 'projects') {
    if (!$projectId || !auth_can_access_project($uid, $projectId)) { http_response_code(404); exit('not found'); }
    $project = db_one('SELECT * FROM projects WHERE id=?', [$projectId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $return = nu_url('settings', ['id' => $projectId]);
    try {
        if ($view === 'projects' && $action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 255) throw new InvalidArgumentException('プロジェクト名を255文字以内で入力してください。');
            db()->beginTransaction();
            db_exec('INSERT INTO projects(owner_user_id,name) VALUES(?,?)', [$uid,$name]);
            $projectId = (int)db()->lastInsertId();
            db_exec('INSERT INTO project_members(project_id,user_id,role) VALUES(?,?,?)', [$projectId,$uid,'owner']);
            db()->commit();
            flash_set('ok', 'プロジェクトを作成しました。対象テキストとプロンプトを設定してください。');
            redirect(nu_url('settings', ['id' => $projectId]));
        }
        if ($view !== 'settings' || !$project) { http_response_code(405); exit('method not allowed'); }
        if ($action === 'save_settings') {
            $name = trim((string)($_POST['name'] ?? ''));
            $frequency = (string)($_POST['run_frequency'] ?? '');
            $main = trim((string)($_POST['main'] ?? ''));
            $postedSubs = $_POST['sub'] ?? [];
            $subs = is_array($postedSubs) ? array_slice(array_values(array_filter(array_map(static fn($v) => is_string($v) ? trim($v) : '', $postedSubs), static fn($v) => $v !== '')), 0, 3) : [];
            if ($name === '' || mb_strlen($name) > 255 || !isset(project_schedule_frequency_options()[$frequency])) throw new InvalidArgumentException('プロジェクト名と実行頻度を確認してください。');
            foreach (array_merge([$main], $subs) as $text) if (mb_strlen($text) > 255) throw new InvalidArgumentException('対象テキストは255文字以内で入力してください。');
            db()->beginTransaction();
            db_exec('UPDATE projects SET name=?,run_frequency=?,is_active=? WHERE id=?', [$name,$frequency,isset($_POST['is_active']) ? 1 : 0,$projectId]);
            db_exec('DELETE FROM project_domains WHERE project_id=?', [$projectId]);
            if ($main !== '') db_exec('INSERT INTO project_domains(project_id,type,domain) VALUES(?,?,?)', [$projectId,'main',$main]);
            foreach ($subs as $sub) db_exec('INSERT INTO project_domains(project_id,type,domain) VALUES(?,?,?)', [$projectId,'sub',$sub]);
            db()->commit();
            flash_set('ok', 'プロジェクト設定を保存しました。');
        } elseif ($action === 'save_prompt' || $action === 'add_prompt') {
            $name = trim((string)($_POST['name'] ?? ''));
            $content = trim((string)($_POST['content'] ?? ''));
            if ($name === '' || $content === '' || mb_strlen($name) > 255) throw new InvalidArgumentException('プロンプト名と内容を入力してください（名前は255文字以内）。');
            $active = isset($_POST['is_active']) ? 1 : 0;
            if ($action === 'save_prompt') {
                $pid = (int)($_POST['pid'] ?? 0);
                if (!db_one('SELECT id FROM project_prompts WHERE id=? AND project_id=?', [$pid,$projectId])) { http_response_code(404); exit('not found'); }
                db_exec('UPDATE project_prompts SET name=?,content=?,is_active=? WHERE id=? AND project_id=?', [$name,$content,$active,$pid,$projectId]);
            } else db_exec('INSERT INTO project_prompts(project_id,name,content,is_active) VALUES(?,?,?,?)', [$projectId,$name,$content,$active]);
            flash_set('ok', 'プロンプトを保存しました。');
            $return .= '#prompt-settings';
        } else { http_response_code(400); exit('invalid action'); }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash_set('err', $e instanceof InvalidArgumentException ? $e->getMessage() : '保存に失敗しました。入力内容を確認してもう一度お試しください。');
        if ($view === 'projects') $return = nu_url();
    }
    redirect($return);
}

if ($view === 'projects') {
    $projects = db_all('SELECT DISTINCT p.* FROM projects p LEFT JOIN project_members m ON m.project_id=p.id WHERE p.owner_user_id=? OR m.user_id=? ORDER BY p.id DESC', [$uid,$uid]);
    $rows = db_all('SELECT r.project_id,r.provider,COUNT(*) n,SUM(r.hit_main) hits,MAX(r.run_at) last FROM runs r JOIN projects p ON p.id=r.project_id WHERE (p.owner_user_id=? OR EXISTS(SELECT 1 FROM project_members m WHERE m.project_id=p.id AND m.user_id=?)) AND r.day_key BETWEEN ? AND ? GROUP BY r.project_id,r.provider', [$uid,$uid,$start,$end]);
    $stats = [];
    foreach ($rows as $row) $stats[$row['project_id']][$row['provider']] = $row;
    nu_header('プロジェクト', null, $view, $period);
    nu_heading('WORKSPACE', 'プロジェクト', 'ChatGPTとGeminiで、自社がどのくらい見つかるかを確認しましょう。');
    echo '<div class="list-toolbar"><span class="muted">' . count($projects) . '件のプロジェクト</span></div>';
    echo '<section class="panel create-panel" id="create-panel"' . (($_GET['create'] ?? '') === '1' ? '' : ' hidden') . '><h2>新しいプロジェクト</h2><form method="post" class="inline-create"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="create"><label><span class="sr-only">プロジェクト名</span><input name="name" maxlength="255" placeholder="プロジェクト名" required></label><button class="button primary">作成</button><button class="button secondary" type="button" data-open-create>閉じる</button></form></section>';
    nu_period(0, 'projects', $period);
    echo '<div class="project-grid">';
    foreach ($projects as $p) {
        $id = (int)$p['id'];
        echo '<article class="panel project-card"><div class="section-heading"><span class="project-symbol">' . h(mb_substr($p['name'],0,1)) . '</span><span class="badge ' . ($p['is_active'] ? 'running' : '') . '">' . ($p['is_active'] ? '計測有効' : '計測停止') . '</span></div><h2><a href="' . h(nu_url('overview', ['id' => $id, 'start' => $start, 'end' => $end])) . '">' . h($p['name']) . '</a></h2><p class="muted schedule-label">' . h(project_schedule_label($p['run_frequency'])) . '</p><div class="project-rates">';
        $last = null;
        foreach (['openai' => 'ChatGPT','gemini' => 'Gemini'] as $provider => $label) {
            $stat = $stats[$id][$provider] ?? nu_empty_stats(0);
            if ($stat['last'] && (!$last || $stat['last'] > $last)) $last = $stat['last'];
            echo '<div><span class="provider-label ' . $provider . '"><i></i>' . $label . '</span>'; nu_rate($stat, $provider); echo '</div>';
        }
        echo '</div><div class="project-card-footer"><span>期間内の最終計測 ' . ($last ? h(substr($last,5,11)) : 'なし') . '</span><a href="' . h(nu_url('overview', ['id' => $id, 'start' => $start, 'end' => $end])) . '" aria-label="' . h($p['name']) . 'の概要">概要を見る →</a></div></article>';
    }
    echo '</div>';
    if (!$projects) echo '<section class="panel empty-state"><h2>最初のプロジェクトを作成しましょう</h2><p>対象テキストとプロンプトを登録すると、ここで計測結果を確認できます。</p></section>';
    nu_footer(); return;
}

$targets = db_all('SELECT type,domain FROM project_domains WHERE project_id=? ORDER BY FIELD(type,\'main\',\'sub\'),id', [$projectId]);
$mainTarget = ''; $subs = [];
foreach ($targets as $target) { if ($target['type'] === 'main') $mainTarget = $target['domain']; else $subs[] = $target['domain']; }
$subs = array_slice($subs, 0, 3);
$prompts = db_all('SELECT * FROM project_prompts WHERE project_id=? ORDER BY is_active DESC,id', [$projectId]);
$base = ['id' => $projectId, 'start' => $start, 'end' => $end];

if ($view === 'settings') {
    nu_header('設定', $project, $view, $period);
    nu_heading('PROJECT SETTINGS', '設定', '計測する対象とプロンプトを管理します。変更は従来のUIにも反映されます。');
    echo '<div class="settings-grid"><section class="panel"><div class="panel-heading"><h2>プロジェクト・対象テキスト</h2><p>回答本文に含まれるかを調べる名前やブランドを登録します。</p></div><form method="post" class="settings-form"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="save_settings"><label>プロジェクト名<input name="name" value="' . h($project['name']) . '" maxlength="255" required></label><label>実行頻度<select name="run_frequency">';
    foreach (project_schedule_frequency_options() as $value => $label) echo '<option value="' . h($value) . '"' . ($value === $project['run_frequency'] ? ' selected' : '') . '>' . h(project_schedule_label($value)) . '</option>';
    echo '</select></label><label class="checkbox-label"><input type="checkbox" name="is_active"' . ($project['is_active'] ? ' checked' : '') . '>定期計測を有効にする</label><hr><label>自社の対象テキスト<input name="main" maxlength="255" value="' . h($mainTarget) . '" placeholder="例：会社名・ブランド名"></label>';
    for ($i = 0; $i < 3; $i++) echo '<label>競合 ' . ($i+1) . '<input name="sub[]" maxlength="255" value="' . h($subs[$i] ?? '') . '" placeholder="競合名（任意）"></label>';
    echo '<button class="button primary">設定を保存</button></form></section><section class="panel" id="prompt-settings"><div class="panel-heading"><h2>プロンプト</h2><p>ChatGPT・Geminiに尋ねる質問を設定します。</p></div>';
    foreach ($prompts as $pr) {
        echo '<details class="prompt-editor"><summary><span>' . h($pr['name']) . '</span><span class="badge ' . ($pr['is_active'] ? 'running' : '') . '">' . ($pr['is_active'] ? '有効' : '停止') . '</span></summary>';
        nu_prompt_form($pr, 'save_prompt'); echo '</details>';
    }
    echo '<details class="prompt-editor"><summary>＋ プロンプトを追加</summary>'; nu_prompt_form(['id'=>0,'name'=>'','content'=>'','is_active'=>1], 'add_prompt'); echo '</details></section></div>';
    nu_footer(); return;
}
function nu_prompt_form(array $pr, string $action): void {
    echo '<form method="post" class="settings-form"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="pid" value="' . (int)$pr['id'] . '"><label>プロンプト名<input name="name" maxlength="255" value="' . h($pr['name']) . '" required></label><label>質問内容<textarea name="content" rows="5" required>' . h($pr['content']) . '</textarea></label><label class="checkbox-label"><input type="checkbox" name="is_active"' . ($pr['is_active'] ? ' checked' : '') . '>計測を有効にする</label><button class="button primary">' . ($action === 'add_prompt' ? '追加する' : '変更を保存') . '</button></form>';
}

$prevEnd = (new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');
$prevStart = (new DateTimeImmutable($start))->modify('-' . $days . ' days')->format('Y-m-d');
$prompt = null; $pid = null;
if ($view === 'prompt') {
    $pid = (int)($_GET['pid'] ?? 0);
    $prompt = db_one('SELECT * FROM project_prompts WHERE id=? AND project_id=?', [$pid,$projectId]);
    if (!$prompt) { http_response_code(404); exit('not found'); }
}
$runs = nu_runs($projectId, $prevStart, $end, $pid);
if ($view === 'competitor') {
    nu_competitor_page($project, $period, $subs, $prompts, $runs);
    return;
}
$totals = []; $previous = []; $byPrompt = []; $previousByPrompt = []; $byDay = [];
foreach (['openai','gemini'] as $provider) { $totals[$provider] = nu_empty_stats(count($subs)); $previous[$provider] = nu_empty_stats(count($subs)); }
foreach ($runs as $run) {
    $provider = $run['provider']; $rid = (int)$run['prompt_id']; $day = $run['day_key'];
    if ($day < $start) {
        nu_add($previous[$provider], $run, $subs);
        if (!isset($previousByPrompt[$rid][$provider])) $previousByPrompt[$rid][$provider] = nu_empty_stats(count($subs));
        nu_add($previousByPrompt[$rid][$provider], $run, $subs); continue;
    }
    nu_add($totals[$provider], $run, $subs);
    if (!isset($byPrompt[$rid][$provider])) $byPrompt[$rid][$provider] = nu_empty_stats(count($subs));
    if (!isset($byDay[$day][$provider])) $byDay[$day][$provider] = nu_empty_stats(count($subs));
    nu_add($byPrompt[$rid][$provider], $run, $subs); nu_add($byDay[$day][$provider], $run, $subs);
}

if ($view === 'overview' || $view === 'prompts') {
    nu_header($view === 'overview' ? '概要・比較' : 'プロンプト', $project, $view, $period);
    nu_heading($view === 'overview' ? 'PROJECT OVERVIEW' : 'PROMPTS', $view === 'overview' ? $project['name'] : 'プロンプト', $view === 'overview' ? 'ChatGPTとGeminiを横断して、出現率とその変化を確認できます。' : '同じ質問に対する出現率を比較し、回答の詳細を確認できます。');
    nu_period($projectId, $view, $period);
    if (!$mainTarget) echo '<div class="notice">自社の対象テキストが未設定です。<a href="' . h(nu_url('settings',['id'=>$projectId])) . '">設定する →</a></div>';
    if ($view === 'overview') {
        echo '<div class="metric-grid">';
        foreach (['openai'=>'ChatGPT','gemini'=>'Gemini'] as $provider=>$label) {
            echo '<section class="panel metric-card ' . $provider . '"><div class="section-heading"><span class="provider-label ' . $provider . '"><i></i>' . $label . '</span><span class="muted">自社の出現率</span></div>'; nu_rate($totals[$provider],$provider); nu_delta($totals[$provider],$previous[$provider]);
            echo '<div class="metric-footer">' . ($totals[$provider]['last'] ? '最終計測 ' . h($totals[$provider]['last']) : 'この期間には計測データがありません') . '</div></section>';
        }
        echo '<section class="panel context-card"><span class="eyebrow">MONITORING</span><h2>' . h($mainTarget ?: '対象未設定') . '</h2><div><span class="muted">登録プロンプト</span><strong>' . count($prompts) . '<small>件</small></strong></div><div><span class="muted">実行頻度</span><span>' . h(project_schedule_frequency_options()[$project['run_frequency']]) . '</span></div><a href="' . h(nu_url('settings',['id'=>$projectId])) . '">計測設定を見る →</a></section></div>';
        echo '<section class="panel chart-panel"><div class="panel-heading section-heading"><div><h2>出現率の推移</h2><p>同じ期間で両サービスを比較。計測のない日は空白で表示します。</p></div><label class="chart-target-label"><span class="sr-only">グラフの対象</span><select id="chart-target"><option value="main">自社</option>';
        foreach ($subs as $i=>$sub) echo '<option value="sub' . $i . '">' . h($sub) . '</option>';
        echo '</select></label></div><div class="chart-legend"><span class="provider-label openai"><i></i>ChatGPT</span><span class="provider-label gemini"><i></i>Gemini</span></div><div id="trend-chart" class="trend-chart" role="group" aria-label="ChatGPTとGeminiの日別出現率"></div><div class="chart-tooltip" id="chart-tooltip" hidden></div><p class="chart-footnote">グラフの点を選ぶと、その日のプロンプト一覧に移動します。</p></section>';
        $labels = [];
        for ($date = new DateTimeImmutable($start); $date->format('Y-m-d') <= $end; $date = $date->modify('+1 day')) $labels[] = $date->format('Y-m-d');
        $chart = ['labels'=>$labels,'series'=>[],'project'=>$projectId];
        foreach (array_merge(['main'],array_map(static fn($i)=>'sub'.$i,array_keys($subs))) as $key) {
            foreach (['openai','gemini'] as $provider) {
                $chart['series'][$key][$provider] = array_map(static function($day) use ($byDay,$provider,$key) {
                    $stat = $byDay[$day][$provider] ?? null;
                    if ($key !== 'main') $stat = $stat['subs'][(int)substr($key,3)] ?? null;
                    return $stat ? ['pct'=>nu_pct($stat),'n'=>$stat['n'],'hits'=>$stat['hits']] : ['pct'=>null,'n'=>0,'hits'=>0];
                },$labels);
            }
        }
        echo '<script type="application/json" id="chart-data">' . json_encode($chart,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . '</script>';
        echo '<section class="panel"><div class="panel-heading"><h2>競合との比較</h2><p>競合の数値を選ぶと、出現した日と回答の詳細を確認できます。</p></div><div class="table-scroll"><table class="competitor-table"><thead><tr><th>対象テキスト</th><th><span class="provider-label openai"><i></i>ChatGPT</span></th><th><span class="provider-label gemini"><i></i>Gemini</span></th></tr></thead><tbody>';
        foreach (array_merge([$mainTarget ?: '自社（計測時の対象）'],$subs) as $i=>$target) {
            echo '<tr><td><span class="target-name">' . h($target) . '</span>' . ($i === 0 ? '<span class="badge self">自社</span>' : '') . '</td>';
            foreach (['openai','gemini'] as $provider) {
                $stat = $i === 0 ? $totals[$provider] : $totals[$provider]['subs'][$i-1];
                $url = $i > 0 && $stat['hits'] > 0 ? nu_url('competitor',$base+['target'=>$target,'provider'=>$provider]) : null;
                echo '<td>'; nu_rate($stat,$provider,$url,$target . 'が出現した' . ($provider === 'gemini' ? 'Gemini' : 'ChatGPT') . 'の回答を見る'); echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></section>';
    }
    echo '<section class="panel"><div class="panel-heading section-heading"><div><h2>プロンプト別の比較</h2><p>出現率は回答本文で判定。検索クエリや参照URLは判定に含みません。</p></div><span class="badge">' . count($prompts) . '件</span></div>';
    nu_prompt_table($prompts,$byPrompt,$previousByPrompt,$projectId,$period);
    echo '</section><p class="data-note">集計期間：' . h($start) . ' 〜 ' . h($end) . ' ／ 比較期間：' . h($prevStart) . ' 〜 ' . h($prevEnd) . '。出現率は計測回数を母数に算出します。自社は計測時の対象、競合は現在登録されている対象の計測を集計します。</p>';
    nu_footer(); return;
}

// Prompt detail: metrics for both providers, answer and history for one provider.
$provider = (string)($_GET['provider'] ?? 'openai');
if (!in_array($provider,['openai','gemini'],true)) $provider = 'openai';
$providerName = $provider === 'gemini' ? 'Gemini' : 'ChatGPT';
$logs = db_all('SELECT day_key,run_at,response_text,search_suggestions FROM daily_logs WHERE project_id=? AND prompt_id=? AND provider=? AND day_key BETWEEN ? AND ? ORDER BY day_key DESC',[$projectId,$pid,$provider,$start,$end]);
$requestedDay = nu_date((string)($_GET['day'] ?? ''),'');
$selected = $requestedDay ?: ($logs[0]['day_key'] ?? $end);
$log = null;
foreach ($logs as $candidate) if ($candidate['day_key'] === $selected) { $log = $candidate; break; }
$dayStats = [];
foreach ($runs as $run) {
    if ($run['day_key'] < $start || $run['provider'] !== $provider) continue;
    if (!isset($dayStats[$run['day_key']])) $dayStats[$run['day_key']] = nu_empty_stats(count($subs));
    nu_add($dayStats[$run['day_key']],$run,$subs);
}
nu_header($prompt['name'],$project,$view,$period);
echo '<a class="back-link" href="' . h(nu_url('prompts',$base)) . '">← プロンプト一覧</a>';
nu_heading('PROMPT DETAIL',$prompt['name'],'計測結果を比較し、サービスごとの回答と検索情報を確認できます。');
nu_period($projectId,'prompt',$period,['pid'=>$pid,'provider'=>$provider]);
echo '<details class="panel prompt-question"><summary>質問内容を確認する<span>⌄</span></summary><div>' . nl2br(h($prompt['content'])) . '</div></details><div class="detail-metrics">';
foreach (['openai'=>'ChatGPT','gemini'=>'Gemini'] as $p=>$label) {
    echo '<section class="panel detail-metric"><span class="provider-label ' . $p . '"><i></i>' . $label . '</span>'; nu_rate($totals[$p],$p); nu_delta($totals[$p],$previous[$p]); echo '</section>';
}
echo '</div><nav class="provider-tabs" aria-label="回答のAIサービス">';
foreach (['openai'=>'ChatGPT','gemini'=>'Gemini'] as $p=>$label) echo '<a class="' . ($p === $provider ? 'selected ' : '') . $p . '"' . ($p === $provider ? ' aria-current="page"' : '') . ' href="' . h(nu_url('prompt',$base+['pid'=>$pid,'provider'=>$p]+($requestedDay ? ['day'=>$requestedDay] : []))) . '"><span class="provider-label ' . $p . '"><i></i>' . $label . '</span></a>';
echo '</nav><div class="answer-layout"><aside class="panel history-panel"><div class="panel-heading"><h2>計測履歴</h2><p>' . h($providerName) . ' · 日別</p></div><div class="history-list">';
$historyDays = array_unique(array_merge(array_keys($dayStats),array_column($logs,'day_key'))); rsort($historyDays);
foreach ($historyDays as $day) {
    $stat = $dayStats[$day] ?? nu_empty_stats(0);
    echo '<a class="history-item ' . ($day === $selected ? 'selected' : '') . '" href="' . h(nu_url('prompt',$base+['pid'=>$pid,'provider'=>$provider,'day'=>$day])) . '"><span><strong>' . h($day) . '</strong><small>' . ($stat['n'] ? $stat['n'] . '回計測' : '回答ログあり') . '</small></span><span class="history-pct">' . ($stat['n'] ? h(nu_number(nu_pct($stat))) . '%' : '—') . '</span></a>';
}
if (!$historyDays) echo '<div class="history-empty">この期間の計測はありません。</div>';
echo '</div><p class="history-note">回答本文は1日につき最終の1件を保存しています。</p></aside><div class="answer-main">';
$emptyText = isset($dayStats[$selected]) ? '計測結果はありますが、この日の回答本文は保存されていません。' : 'この日・サービスの回答ログはありません。履歴から別の日を選ぶか、期間を変更してください。';
nu_answer_panels($log, $providerName, $selected, 'この日の最終回答', $emptyText);
echo '</div></div>'; nu_footer();
