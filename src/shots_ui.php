<?php
declare(strict_types=1);
require_once __DIR__ . '/shots.php';
$shotView = (string)$_GET['newa'];
$period = nu_range([]);
$input = ['name'=>'','main'=>'','sub'=>['','','','',''],'prompts'=>''];
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($shotView !== 'shot_new') { http_response_code(405); exit('method not allowed'); }
    csrf_check();
    $input = $_POST;
    try {
        shot_create($uid, $input, shot_providers());
        flash_set('ok', 'ショットを保存しました。バックグラウンドで調査しています。後ほど一覧から結果をご確認ください。');
        session_write_close();
        shot_start_worker();
        redirect(nu_url('shots'));
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : '保存できませんでした。もう一度お試しください。';
        if (!$e instanceof InvalidArgumentException) error_log('Shot create: ' . $e->getMessage());
        http_response_code(422);
    }
}
if ($shotView === 'shot_new') {
    nu_header('新規ショット', null, $shotView, $period);
    echo '<a class="back-link" href="' . h(nu_url('shots')) . '">← ショット一覧</a>';
    nu_heading('ONE-TIME RESEARCH', '新規ショット', '入力したプロンプトを一度だけ調査します。結果は保存され、あとから確認できます。');
    if ($error) echo '<div class="notice err" role="alert">' . h($error) . '</div>';
    $value = static fn($key) => is_string($input[$key] ?? null) ? $input[$key] : '';
    echo '<section class="panel shot-form"><form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><label>ショット名<input name="name" maxlength="255" value="' . h($value('name')) . '" placeholder="例：提案書用・会計ソフト比較" required></label><label>自社（出現を調べるテキスト）<input name="main" maxlength="255" value="' . h($value('main')) . '" placeholder="会社名・サービス名・ドメインなど" required></label><fieldset><legend>計測したい文字列（最大5件・任意）</legend>';
    for ($i=0; $i<5; $i++) {
        $sub = is_array($input['sub'] ?? null) && is_string($input['sub'][$i] ?? null) ? $input['sub'][$i] : '';
        echo '<label><span class="sr-only">計測したい文字列' . ($i+1) . '</span><input name="sub[]" maxlength="255" placeholder="計測したい文字列' . ($i+1) . '" value="' . h($sub) . '"></label>';
    }
    echo '</fieldset><label for="shot-prompts">プロンプト（1行1件・最大50件）</label><textarea id="shot-prompts" name="prompts" rows="12" placeholder="中小企業におすすめの会計ソフトは？&#10;経理業務を効率化するサービスを教えて" required>' . h($value('prompts')) . '</textarea><p class="muted" id="shot-prompt-count" aria-live="polite">空行は除いて数えます。</p>';
    $providers = shot_providers();
    $names = array_map(static fn($p) => $p === 'gemini' ? 'Gemini' : 'ChatGPT', $providers);
    echo '<p class="muted">調査サービス：' . h(implode(' / ', $names) ?: '利用可能なサービスがありません') . '。受付後にまとめて調査し、この画面で完了を待つ必要はありません。</p><button class="button primary" type="submit"' . (!$providers ? ' disabled' : '') . '>保存して調査を開始</button></form></section>';
    nu_footer(); return;
}
if ($shotView === 'shots') {
    $shots = db_all("SELECT s.*,COUNT(r.id) total,SUM(r.status='completed') completed,SUM(r.status='failed') failed,COUNT(DISTINCT r.prompt_index) prompts FROM shots s LEFT JOIN shot_results r ON r.shot_id=s.id WHERE s.owner_user_id=? GROUP BY s.id ORDER BY s.id DESC", [$uid]);
    nu_header('ショット', null, $shotView, $period);
    nu_heading('ONE-TIME RESEARCH', 'ショット', '提案書や比較調査に使う単発の調査です。過去の結果もここに残ります。');
    echo '<div class="list-toolbar"><span class="muted">' . count($shots) . '件のショット</span><div><a class="button secondary" href="' . h(nu_url('shots')) . '">状態を更新</a> <a class="button primary" href="' . h(nu_url('shot_new')) . '">＋ 新規ショット</a></div></div><section class="panel table-scroll"><table class="comparison-table"><thead><tr><th>ショット名</th><th>自社</th><th>プロンプト数</th><th>状態・進捗</th><th>受付日時</th><th></th></tr></thead><tbody>';
    foreach ($shots as $shot) {
        $url = nu_url('shot', ['id'=>$shot['id']]);
        echo '<tr class="shot-row" data-shot-url="' . h($url) . '"><td><a class="prompt-link" href="' . h($url) . '">' . h($shot['name']) . '</a></td><td>' . h($shot['target_main_text']) . '</td><td>' . (int)$shot['prompts'] . '件</td><td><span class="badge">' . h(shot_status_label($shot['status'])) . '</span><span class="cell-description">' . ((int)$shot['completed']+(int)$shot['failed']) . ' / ' . (int)$shot['total'] . '回答処理済み' . ((int)$shot['failed'] ? '（失敗' . (int)$shot['failed'] . '件）' : '') . '</span></td><td>' . h($shot['created_at']) . '</td><td><a href="' . h($url) . '" aria-label="' . h($shot['name']) . 'の結果">→</a></td></tr>';
    }
    echo '</tbody></table>';
    if (!$shots) echo '<div class="empty-state">まだショットがありません。新規ショットから調査を始めてください。</div>';
    echo '</section>';
    nu_footer(); return;
}
$shot = db_one('SELECT * FROM shots WHERE id=? AND owner_user_id=?', [(int)($_GET['id'] ?? 0),$uid]);
if (!$shot) { http_response_code(404); exit('見つかりません'); }
$results = db_all('SELECT * FROM shot_results WHERE shot_id=? ORDER BY prompt_index,provider', [$shot['id']]);
$download = $_GET['download'] ?? '';
if (in_array($download, ['xlsx','csv'], true)) {
    require_once __DIR__ . '/shots_export.php';
    $csv = $download === 'csv';
    $contents = $csv ? shot_export_csv($shot, $results) : shot_export_xlsx($shot, $results);
    $suffix = $csv ? '-RAW.csv' : '.xlsx';
    session_write_close();
    header('Content-Type: ' . ($csv ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
    header("Content-Disposition: attachment; filename=\"shot-" . (int)$shot['id'] . $suffix . "\"; filename*=UTF-8''" . rawurlencode($shot['name'] . $suffix));
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . strlen($contents));
    echo $contents;
    return;
}
$subs = json_decode($shot['target_sub_texts'], true, 512, JSON_THROW_ON_ERROR);
$targets = array_merge([$shot['target_main_text']], $subs);
$providers = array_values(array_unique(array_column($results,'provider')));
$summary = [];
$prompts = [];
$processed = 0;
foreach ($results as $result) {
    $prompts[$result['prompt_index']][$result['provider']] = $result;
    if (in_array($result['status'],['completed','failed'],true)) $processed++;
    if ($result['status'] !== 'completed') continue;
    $p = $result['provider'];
    $summary[$p]['n'] = ($summary[$p]['n'] ?? 0) + 1;
    foreach (array_merge([(int)$result['hit_main']], json_decode($result['hit_sub'],true)) as $i=>$hit) $summary[$p]['hits'][$i] = ($summary[$p]['hits'][$i] ?? 0) + (int)$hit;
}
nu_header($shot['name'], null, $shotView, $period);
echo '<a class="back-link" href="' . h(nu_url('shots')) . '">← ショット一覧</a>';
nu_heading('SHOT RESULTS', $shot['name'], '受付日時：' . $shot['created_at'] . ' ／ ' . shot_status_label($shot['status']) . ' ／ ' . $processed . ' / ' . count($results) . '回答処理済み');
echo '<div class="list-toolbar"><span class="muted">出現率のRAWデータ（CSV）と、回答全文を含むExcelをダウンロードできます。</span><div><a class="button secondary" href="' . h(nu_url('shot',['id'=>$shot['id'],'download'=>'csv'])) . '">AnswerIO形式でDL</a> <a class="button primary" href="' . h(nu_url('shot',['id'=>$shot['id'],'download'=>'xlsx'])) . '">結果を一括DL（Excel）</a></div></div>';
if (in_array($shot['status'],['queued','running'],true)) echo '<div class="notice ok">調査中です。画面を閉じても処理は続きます。後ほど結果をご確認ください。 <a href="' . h(nu_url('shot',['id'=>$shot['id']])) . '">状態を更新</a></div>';
if (in_array($shot['status'],['partial','failed'],true)) echo '<div class="notice err">取得できなかった回答があります。成功した回答は保存されています。必要に応じて新しいショットで再調査してください。</div>';
echo '<section class="panel table-scroll"><div class="panel-heading"><h2>自社・計測したい文字列の出現率</h2><p>取得に成功した回答を母数にしています。未処理・失敗した回答は含めません。</p></div><table class="comparison-table"><thead><tr><th>対象テキスト</th>';
foreach ($providers as $p) echo '<th>' . ($p === 'gemini' ? 'Gemini' : 'ChatGPT') . '</th>';
echo '</tr></thead><tbody>';
foreach ($targets as $i=>$target) {
    echo '<tr><td>' . h($target) . '<span class="cell-description">' . ($i === 0 ? '自社' : '計測したい文字列') . '</span></td>';
    foreach ($providers as $p) {
        $n = $summary[$p]['n'] ?? 0; $hits = $summary[$p]['hits'][$i] ?? 0;
        echo '<td>' . ($n ? h(nu_number(round($hits/$n*100,1))) . '%<span class="cell-description">' . $hits . ' / ' . $n . '回答で出現</span>' : '—') . '</td>';
    }
    echo '</tr>';
}
echo '</tbody></table></section><div class="shot-answers">';
foreach ($prompts as $index=>$byProvider) {
    $first = reset($byProvider);
    echo '<section class="panel"><div class="panel-heading"><h2>' . (int)$index . '. ' . h($first['prompt_content']) . '</h2></div>';
    foreach ($byProvider as $p=>$result) {
        echo '<details class="shot-answer"><summary>' . ($p === 'gemini' ? 'Gemini' : 'ChatGPT') . ' <span class="badge">' . h(shot_status_label($result['status'])) . '</span>';
        if ($result['status'] === 'completed') {
            $hits = array_merge([(int)$result['hit_main']], json_decode($result['hit_sub'],true));
            foreach ($targets as $i=>$target) echo ' <span class="badge">' . h($target) . '：' . ($hits[$i] ? '出現' : 'なし') . '</span>';
        }
        echo '</summary>';
        if ($result['status'] === 'completed') {
            [$answer,$info] = nu_split_raw($result['response_text']);
            $rawId = 'shot-raw-' . $result['id'];
            echo '<div class="copy-control"><button type="button" class="button secondary" data-copy="' . h($rawId) . '">回答・検索情報をコピー</button><span class="copy-status" role="status"></span></div><textarea id="' . h($rawId) . '" hidden>' . h($result['response_text']) . '</textarea><div class="answer-text">' . nu_answer_html($answer) . '</div>';
            foreach ($info as $label=>$values) if ($values) echo '<div class="shot-search"><h3>' . h($label) . '</h3><div>' . nl2br(h(implode("\n",$values))) . '</div></div>';
            if ($result['search_suggestions']) echo '<div class="search-suggestions"><iframe title="Google検索の候補" sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="' . h($result['search_suggestions']) . '"></iframe></div>';
        } else echo '<p class="muted">' . h($result['error_message'] ?: '回答を取得しています。後ほどご確認ください。') . '</p>';
        echo '</details>';
    }
    echo '</section>';
}
echo '</div>'; nu_footer();
