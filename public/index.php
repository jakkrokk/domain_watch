<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$a = $_GET['a'] ?? '';
if ($a === '') {
    if (auth_user()) redirect('/?a=projects');
    redirect('/?a=login');
}

// --- Auth ---
if ($a === 'register') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $email = trim((string)($_POST['email'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('err', 'メールアドレスが不正です');
            redirect('/?a=register');
        }
        if (strlen($pass) < 6) {
            flash_set('err', 'パスワードが短すぎます（6文字以上）');
            redirect('/?a=register');
        }
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        try {
            db_exec('INSERT INTO users(email,password_hash) VALUES(?,?)', [$email, $hash]);
            $uid = (int)db()->lastInsertId();
            auth_login($uid);
            flash_set('ok', '登録しました');
            redirect('/?a=projects');
        } catch (Throwable $e) {
            flash_set('err', '登録に失敗しました（既に使われているメールかも）');
            redirect('/?a=register');
        }
    }
    page_header('新規登録');
    echo '<form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
    echo '<div class=row><label>メールアドレス</label><br><input name=email type=email required></div>';
    echo '<div class=row><label>パスワード</label><br><input name=password type=password required></div>';
    echo '<button type=submit>新規登録</button></form>';
    page_footer();
    exit;
}

if ($a === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $email = trim((string)($_POST['email'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $u = db_one('SELECT id,password_hash FROM users WHERE email=?', [$email]);
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            flash_set('err', 'ログインに失敗しました');
            redirect('/?a=login');
        }
        auth_login((int)$u['id']);
        flash_set('ok', 'ログインしました');
        redirect('/?a=projects');
    }
    page_header('ログイン');
    echo '<form method="post"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
    echo '<div class=row><label>メールアドレス</label><br><input name=email type=email required></div>';
    echo '<div class=row><label>パスワード</label><br><input name=password type=password required></div>';
    echo '<button type=submit>ログイン</button></form>';
    page_footer();
    exit;
}

if ($a === 'logout') {
    auth_logout();
    redirect('/?a=login');
}

// --- Auth required ---
$user = require_login();
$uid = (int)$user['id'];

if ($a === 'projects') {
    $projects = db_all('SELECT * FROM projects WHERE owner_user_id=? ORDER BY id DESC', [$uid]);
    page_header('プロジェクト');
    echo '<div class=card><form method=post action="/?a=project_create">';
    echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
    echo '<div class=row><label>新規プロジェクト名</label><br><input name=name required></div>';
    echo '<button type=submit>作成</button></form></div>';

    echo '<div class=card><h2>一覧</h2>';
    if (!$projects) {
        echo '<div>まだプロジェクトがありません。</div>';
    } else {
        echo '<table><thead><tr><th>ID</th><th>名称</th><th>稼働</th><th>操作</th></tr></thead><tbody>';
        $today = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
        foreach ($projects as $p) {
            echo '<tr>';
            echo '<td>' . h((string)$p['id']) . '</td>';
            echo '<td>' . h($p['name']) . '</td>';
            echo '<td>' . ($p['is_active'] ? 'はい' : 'いいえ') . '</td>';
            echo '<td>';
            echo '<a href="/?a=project&id=' . h((string)$p['id']) . '" style="text-decoration:none"><button type="button">設定</button></a> ';
            echo '<a href="/?a=trends&id=' . h((string)$p['id']) . '" style="text-decoration:none"><button type="button">日次トレンド</button></a> ';
            echo '<a href="/?a=dashboard&id=' . h((string)$p['id']) . '&date=' . h($today) . '" style="text-decoration:none"><button type="button">詳細（今日）</button></a> ';
            echo '<form method="post" action="/?a=project_delete&id=' . h((string)$p['id']) . '" style="display:inline" onsubmit="return confirm(\'本当に削除しますか？（元に戻せません）\')">';
            echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
            echo '<button type="submit">削除</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    page_footer();
    exit;
}

if ($a === 'project_delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/?a=projects');
    csrf_check();
    $pid = (int)($_GET['id'] ?? 0);
    if (!$pid) redirect('/?a=projects');

    // Only owner can delete
    $p = db_one('SELECT id,owner_user_id FROM projects WHERE id=?', [$pid]);
    if (!$p || (int)$p['owner_user_id'] !== $uid) { http_response_code(403); exit('forbidden'); }

    db_exec('DELETE FROM projects WHERE id=?', [$pid]);
    flash_set('ok', 'プロジェクトを削除しました');
    redirect('/?a=projects');
}

if ($a === 'project_create') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/?a=projects');
    csrf_check();
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        flash_set('err', 'プロジェクト名は必須です');
        redirect('/?a=projects');
    }
    db_exec('INSERT INTO projects(owner_user_id,name) VALUES(?,?)', [$uid, $name]);
    $pid = (int)db()->lastInsertId();
    // owner as member too
    db_exec('INSERT IGNORE INTO project_members(project_id,user_id,role) VALUES(?,?,?)', [$pid, $uid, 'owner']);
    flash_set('ok', 'プロジェクトを作成しました');
    redirect('/?a=project&id=' . $pid);
}

if ($a === 'project') {
    $pid = (int)($_GET['id'] ?? 0);
    if (!$pid || !auth_can_access_project($uid, $pid)) {
        http_response_code(404);
        echo '見つかりません';
        exit;
    }

    $project = db_one('SELECT * FROM projects WHERE id=?', [$pid]);
    $domains = db_all('SELECT * FROM project_domains WHERE project_id=? ORDER BY FIELD(type,\'main\',\'sub\'), id', [$pid]);
    $prompts = db_all('SELECT * FROM project_prompts WHERE project_id=? ORDER BY id DESC', [$pid]);

    $today = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');

    page_header('プロジェクト: ' . $project['name']);
    echo '<div class=card>';
    echo '<div><a href="/?a=trends&id=' . h((string)$pid) . '">日次トレンド</a> / ' . '<a href="/?a=dashboard&id=' . h((string)$pid) . '&date=' . h($today) . '">詳細（今日）</a></div>';
    echo '</div>';

    // targets (was domains)
    $hasTargets = count($domains) > 0;
    echo '<div class=card>';
    if ($hasTargets) {
        echo '<details><summary>対象テキスト（クリックで開く）</summary><div class=details-sub>';
    } else {
        echo '<h2>対象テキスト</h2>';
    }
    echo '<form method=post action="/?a=domains_save&id=' . h((string)$pid) . '">';
    echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';

    $main = '';
    $subs = ['', '', ''];
    foreach ($domains as $d) {
        if ($d['type'] === 'main') $main = $d['domain'];
        if ($d['type'] === 'sub') {
            for ($i=0; $i<3; $i++) {
                if ($subs[$i] === '') { $subs[$i] = $d['domain']; break; }
            }
        }
    }

    echo '<div class=row><label>メイン対象テキスト（1件）</label><br><input name=main value="' . h($main) . '" placeholder="例: a.com / ＊＊＊株式会社 / a/com/bbb"></div>';
    for ($i=0;$i<3;$i++) {
        echo '<div class=row><label>サブ対象テキスト ' . ($i+1) . '</label><br><input name=sub[] value="' . h($subs[$i]) . '" placeholder="例: competitor / 商品名"></div>';
    }
    echo '<button type=submit>対象テキストを保存</button></form>';
    if ($hasTargets) { echo '</div></details>'; }
    echo '</div>';

    // prompts
    $hasPrompts = count($prompts) > 0;
    echo '<div class=card>';
    if ($hasPrompts) {
        echo '<details><summary>プロンプト（クリックで開く）</summary><div class=details-sub>';
    } else {
        echo '<h2>プロンプト</h2>';
    }
    echo '<form method=post action="/?a=prompt_create&id=' . h((string)$pid) . '">';
    echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
    echo '<div class=row><label>プロンプト名</label><br><input name=name required></div>';
    echo '<div class=row><label>プロンプト内容</label><br><textarea name=content required></textarea></div>';
    echo '<div class=row><div class=checkrow><input id=pr_is_active type=checkbox name=is_active checked><label for=pr_is_active>有効</label></div></div>';
    echo '<button type=submit>プロンプトを追加</button></form>';

    echo '<h3>登録済み</h3>';
    if (!$prompts) {
        echo '<div>まだプロンプトがありません。</div>';
    } else {
        echo '<table><thead><tr><th>ID</th><th>名称</th><th>稼働</th><th>操作</th></tr></thead><tbody>';
        foreach ($prompts as $pr) {
            echo '<tr>';
            echo '<td>' . h((string)$pr['id']) . '</td>';
            echo '<td>' . h($pr['name']) . '</td>';
            echo '<td>' . ($pr['is_active'] ? 'はい' : 'いいえ') . '</td>';
            echo '<td>';
            echo '<a href="/?a=prompt_edit&id=' . h((string)$pid) . '&pid=' . h((string)$pr['id']) . '">edit</a> ';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    if ($hasPrompts) { echo '</div></details>'; }
    echo '</div>';

    page_footer();
    exit;
}

if ($a === 'domains_save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/?a=projects');
    csrf_check();
    $projectId = (int)($_GET['id'] ?? 0);
    if (!$projectId || !auth_can_access_project($uid, $projectId)) { http_response_code(403); exit('forbidden'); }

    $main = trim((string)($_POST['main'] ?? ''));
    $subs = $_POST['sub'] ?? [];
    if (!is_array($subs)) $subs = [];

    db_exec('DELETE FROM project_domains WHERE project_id=?', [$projectId]);
    if ($main !== '') {
        db_exec('INSERT INTO project_domains(project_id,type,domain) VALUES(?,?,?)', [$projectId, 'main', $main]);
    }
    $count = 0;
    foreach ($subs as $sd) {
        $sd = trim((string)$sd);
        if ($sd === '') continue;
        $count++;
        if ($count > 3) break;
        db_exec('INSERT INTO project_domains(project_id,type,domain) VALUES(?,?,?)', [$projectId, 'sub', $sd]);
    }
    flash_set('ok', 'ドメインを保存しました');
    redirect('/?a=project&id=' . $projectId);
}

if ($a === 'prompt_create') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/?a=projects');
    csrf_check();
    $projectId = (int)($_GET['id'] ?? 0);
    if (!$projectId || !auth_can_access_project($uid, $projectId)) { http_response_code(403); exit('forbidden'); }

    $name = trim((string)($_POST['name'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $active = isset($_POST['is_active']) ? 1 : 0;
    if ($name === '' || $content === '') {
        flash_set('err', 'name/content required');
        redirect('/?a=project&id=' . $projectId);
    }
    db_exec('INSERT INTO project_prompts(project_id,name,content,is_active) VALUES(?,?,?,?)', [$projectId,$name,$content,$active]);
    flash_set('ok', 'プロンプトを追加しました');
    redirect('/?a=project&id=' . $projectId);
}

if ($a === 'prompt_edit') {
    $projectId = (int)($_GET['id'] ?? 0);
    $promptId = (int)($_GET['pid'] ?? 0);
    if (!$projectId || !$promptId || !auth_can_access_project($uid, $projectId)) { http_response_code(404); exit('not found'); }
    $prompt = db_one('SELECT * FROM project_prompts WHERE id=? AND project_id=?', [$promptId, $projectId]);
    if (!$prompt) { http_response_code(404); exit('not found'); }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $name = trim((string)($_POST['name'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $active = isset($_POST['is_active']) ? 1 : 0;
        db_exec('UPDATE project_prompts SET name=?, content=?, is_active=? WHERE id=? AND project_id=?', [$name,$content,$active,$promptId,$projectId]);
        flash_set('ok', 'プロンプトを更新しました');
        redirect('/?a=project&id=' . $projectId);
    }

    page_header('プロンプト編集');
    echo '<form method=post>';
    echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
    echo '<div class=row><label>Name</label><br><input name=name value="' . h($prompt['name']) . '"></div>';
    echo '<div class=row><label>Content</label><br><textarea name=content>' . h($prompt['content']) . '</textarea></div>';
    echo '<div class=row><label><input type=checkbox name=is_active ' . ($prompt['is_active'] ? 'checked' : '') . '> 有効</label></div>';
    echo '<button type=submit>保存</button></form>';
    page_footer();
    exit;
}

if ($a === 'trends') {
    $projectId = (int)($_GET['id'] ?? 0);
    if (!$projectId || !auth_can_access_project($uid, $projectId)) { http_response_code(404); exit('not found'); }

    $tz = new DateTimeZone('Asia/Tokyo');
    $today = (new DateTime('now', $tz))->format('Y-m-d');

    $start = (string)($_GET['start'] ?? '');
    $end = (string)($_GET['end'] ?? '');
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $end)) $end = $today;
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $start)) {
        $dt = new DateTime($end, $tz);
        $dt->modify('-13 days');
        $start = $dt->format('Y-m-d');
    }

    // build date labels (inclusive)
    $labels = [];
    $d0 = new DateTime($start, $tz);
    $d1 = new DateTime($end, $tz);
    if ($d0 > $d1) { $tmp=$d0; $d0=$d1; $d1=$tmp; $start=$d0->format('Y-m-d'); $end=$d1->format('Y-m-d'); }
    for ($d = clone $d0; $d <= $d1; $d->modify('+1 day')) {
        $labels[] = $d->format('Y-m-d');
        if (count($labels) > 370) break; // safety
    }

    $project = db_one('SELECT * FROM projects WHERE id=?', [$projectId]);
    $trows = db_all('SELECT type,domain FROM project_domains WHERE project_id=? ORDER BY FIELD(type,\'main\',\'sub\'), id', [$projectId]);
    $labelMain = '';
    $labelSub1 = '';
    $labelSub2 = '';
    $labelSub3 = '';
    $subs = [];
    foreach ($trows as $tr) {
        if ($tr['type'] === 'main') { $labelMain = (string)$tr['domain']; }
        if ($tr['type'] === 'sub') { $subs[] = (string)$tr['domain']; }
    }
    $labelSub1 = $subs[0] ?? '';
    $labelSub2 = $subs[1] ?? '';
    $labelSub3 = $subs[2] ?? '';
    $tz = new DateTimeZone('Asia/Tokyo');
    $today = (new DateTime('now', $tz))->format('Y-m-d');

    $prompts = db_all('SELECT id,name FROM project_prompts WHERE project_id=? AND is_active=1 ORDER BY id', [$projectId]);

    // latest saved RAW (response_text) per prompt
    $latestRows = db_all('SELECT NULL AS id, prompt_id, day_key, run_at, response_text FROM daily_logs WHERE project_id=? ORDER BY day_key DESC, run_at DESC', [$projectId]);
    $latestByPrompt = [];
    foreach ($latestRows as $lr) {
        $pid2 = (int)$lr['prompt_id'];
        if (!isset($latestByPrompt[$pid2])) $latestByPrompt[$pid2] = $lr;
    }

    // Aggregate from runs: per day, per prompt (per slot)
    $rows = db_all(
        'SELECT day_key, prompt_id, COUNT(*) AS exec_count, ' .
        'SUM(hit_main) AS main_count, SUM(hit_sub1) AS sub1_count, SUM(hit_sub2) AS sub2_count, SUM(hit_sub3) AS sub3_count ' .
        'FROM runs WHERE project_id=? AND day_key BETWEEN ? AND ? GROUP BY day_key, prompt_id',
        [$projectId, $start, $end]
    );

    $byPrompt = [];
    foreach ($prompts as $p) {
        $pid2 = (int)$p['id'];
        $byPrompt[$pid2] = [
            'prompt_id' => $pid2,
            'prompt_name' => (string)$p['name'],
            'by_day' => [],
        ];
    }
    foreach ($rows as $r) {
        $day = (string)$r['day_key'];
        $pid2 = (int)$r['prompt_id'];
        if (!isset($byPrompt[$pid2])) continue;
        $exec = (int)$r['exec_count'];
        $byPrompt[$pid2]['by_day'][$day] = [
            'exec' => $exec,
            'main_pct' => $exec ? round(((int)$r['main_count']) / $exec * 100, 2) : null,
            'sub1_pct' => $exec ? round(((int)$r['sub1_count']) / $exec * 100, 2) : null,
            'sub2_pct' => $exec ? round(((int)$r['sub2_count']) / $exec * 100, 2) : null,
            'sub3_pct' => $exec ? round(((int)$r['sub3_count']) / $exec * 100, 2) : null,
        ];
    }

    page_header('日次トレンド: ' . $project['name']);

    echo '<div class=card>';
    echo '<form method=get style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap">';
    echo '<input type=hidden name=a value=trends><input type=hidden name=id value=' . h((string)$projectId) . '>';
    echo '<div><label>Start</label><br><input type=date name=start value="' . h($start) . '"></div>';
    echo '<div><label>End</label><br><input type=date name=end value="' . h($end) . '"></div>';
    echo '<div><button type=submit>表示</button></div>';
    echo '</form>';
    echo '<div style="margin-top:8px"><small>棒（または日付リンク）をクリックすると、その日の詳細ページに移動します。</small></div>';
    echo '</div>';

    if (!$prompts) {
        echo '<div class=card>プロンプトがありません。</div>';
        page_footer();
        exit;
    }

    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
    echo '<script>const LABELS=' . json_encode($labels, JSON_UNESCAPED_UNICODE) . '; const PROJECT_ID=' . (int)$projectId . ';</script>';

    $i = 0;
    foreach ($byPrompt as $pid2 => $p) {
        $i++;
        $seriesMain = [];
        $seriesSub1 = [];
        $seriesSub2 = [];
        $seriesSub3 = [];
        foreach ($labels as $day) {
            $d = $p['by_day'][$day] ?? [];
            $seriesMain[] = $d['main_pct'] ?? null;
            $seriesSub1[] = $d['sub1_pct'] ?? null;
            $seriesSub2[] = $d['sub2_pct'] ?? null;
            $seriesSub3[] = $d['sub3_pct'] ?? null;
        }

        echo '<div class=card>';
        echo '<h2>' . h($p['prompt_name'] ?: ('#'.$pid2)) . ' <a href="#" class="latestRaw" data-pid="' . h((string)$pid2) . '" data-name="' . h($p['prompt_name'] ?: ('#'.$pid2)) . '" style="font-size:12px; font-weight:700; margin-left:8px">最新RAW</a></h2>';
        echo '<div><canvas id="c' . h((string)$pid2) . '" height="120"></canvas></div>';
        echo '<div style="margin-top:10px; display:flex; flex-wrap:wrap; gap:8px">';
        foreach ($labels as $day) {
            echo '<a href="/?a=dashboard&id=' . h((string)$projectId) . '&date=' . h($day) . '" style="font-size:12px">' . h(substr($day,5)) . '</a>';
        }
        echo '</div>';
        echo '<div style="margin-top:8px"><small>期間: ' . h($start) . ' 〜 ' . h($end) . '</small></div>';
        echo '<div style="margin-top:12px; padding-top:12px; border-top:1px solid rgba(15,15,15,.12)">';
        echo '<h3 style="margin:0 0 8px">AIで比較</h3>';
        echo '<form onsubmit="return openComparePanel(' . h((string)$projectId) . ', ' . h((string)$pid2) . ', this)" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap">';
        echo '<div><label>日付A</label><br><input type=date name=dateA required></div>';
        echo '<div><label>日付B</label><br><input type=date name=dateB required></div>';
        echo '<div><button type=submit>比較</button></div>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<script>';
        echo '(() => {';
        echo 'const ctx=document.getElementById(' . json_encode('c'.$pid2) . ');';
        echo 'const main=' . json_encode($seriesMain, JSON_UNESCAPED_UNICODE) . '; const sub1=' . json_encode($seriesSub1, JSON_UNESCAPED_UNICODE) . '; const sub2=' . json_encode($seriesSub2, JSON_UNESCAPED_UNICODE) . '; const sub3=' . json_encode($seriesSub3, JSON_UNESCAPED_UNICODE) . ';';
        echo 'const projectId=' . (int)$projectId . ';';
        echo 'new Chart(ctx, {';
        echo 'type:"bar",';
        echo 'data:{labels:LABELS, datasets:[
  {label:' . json_encode($labelMain !== '' ? $labelMain : 'メイン', JSON_UNESCAPED_UNICODE) . ', data:main},
  {label:' . json_encode($labelSub1 !== '' ? $labelSub1 : 'サブ1', JSON_UNESCAPED_UNICODE) . ', data:sub1},
  {label:' . json_encode($labelSub2 !== '' ? $labelSub2 : 'サブ2', JSON_UNESCAPED_UNICODE) . ', data:sub2},
  {label:' . json_encode($labelSub3 !== '' ? $labelSub3 : 'サブ3', JSON_UNESCAPED_UNICODE) . ', data:sub3}
]},';
        echo 'options:{responsive:true, interaction:{mode:"nearest", intersect:true}, plugins:{legend:{display:true}}, scales:{y:{beginAtZero:true, max:100}}, onClick:(evt, els)=>{ if(!els.length) return; const idx=els[0].index; const day=LABELS[idx]; window.location = `/?a=dashboard&id=${projectId}&date=${day}`; }}';
        echo '});';
        echo '})();';
        echo '</script>';
    }


    
    // --- Compare side panel (stub)
    echo '<div id="comparePanel" style="position:fixed; top:0; right:-520px; width:520px; height:100%; background:#fff; border-left:1px solid rgba(15,15,15,.12); box-shadow:-12px 0 30px rgba(15,15,15,.12); z-index:9998; transition:right .22s ease;">';
    echo '<div style="display:flex; justify-content:space-between; align-items:center; padding:12px 14px; border-bottom:1px solid rgba(15,15,15,.12)">';
    echo '<div><strong>AIで比較</strong> <small id="compareMeta" style="margin-left:8px"></small></div>';
    echo '<button type="button" onclick="closeComparePanel()">閉じる</button>';
    echo '</div>';
    echo '<div style="padding:14px"><div id="compareBody" style="white-space:pre-wrap; color:#333"></div></div>';
    echo '</div>';

    echo '<div id="compareOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.20); z-index:9997;"></div>';

    echo '<script>';
    echo 'function openComparePanel(projectId, promptId, form){const a=form.dateA.value; const b=form.dateB.value; document.getElementById("compareMeta").textContent = `prompt=${promptId}  ${a} vs ${b}`; document.getElementById("compareBody").textContent = "（ここにAI比較結果が出ます：stub）"; document.getElementById("compareOverlay").style.display="block"; document.getElementById("comparePanel").style.right="0px"; return false;}';
    echo 'function closeComparePanel(){document.getElementById("comparePanel").style.right="-520px"; document.getElementById("compareOverlay").style.display="none";}';
    echo 'document.getElementById("compareOverlay").addEventListener("click", closeComparePanel);';
    echo '</script>';

// --- Latest RAW modal (popup)
    echo '<div id="latestRawModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:9999; padding:24px;">';
    echo '<div style="max-width:960px; margin:0 auto; background:#fff; border:1px solid rgba(15,15,15,.16); border-radius:14px; box-shadow:0 12px 30px rgba(15,15,15,.20); overflow:hidden">';
    echo '<div style="display:flex; justify-content:space-between; align-items:center; padding:12px 14px; border-bottom:1px solid rgba(15,15,15,.12)">';
    echo '<div><strong id="latestRawTitle">RAW</strong> <small id="latestRawMeta" style="margin-left:8px"></small></div>';
    echo '<button type="button" onclick="closeLatestRaw()">閉じる</button>';
    echo '</div>';
    echo '<div style="padding:14px"><pre id="latestRawBody" style="white-space:pre-wrap"></pre></div>';
    echo '</div>';
    echo '</div>';

    echo '<script>';
    echo 'async function _openLatestRaw(promptId, promptName){const m=document.getElementById("latestRawModal"); const t=document.getElementById("latestRawTitle"); const meta=document.getElementById("latestRawMeta"); const b=document.getElementById("latestRawBody"); t.textContent="RAW: "+promptName; meta.textContent=""; b.textContent="読み込み中..."; m.style.display="block"; try{const res=await fetch("/?a=latest_raw&id="+PROJECT_ID+"&prompt_id="+encodeURIComponent(promptId)); const j=await res.json(); const row=j.row; if(!row){meta.textContent="（保存RAWなし）"; b.textContent=""; return;} meta.textContent = (row.day_key||"") + " " + (row.run_at||""); b.textContent = row.response_text || "";} catch(e){meta.textContent="（取得失敗）"; b.textContent=String(e);} }';
    echo 'function closeLatestRaw(){document.getElementById("latestRawModal").style.display="none";}';
    echo 'document.querySelectorAll("a.latestRaw").forEach(a=>a.addEventListener("click",(e)=>{e.preventDefault(); _openLatestRaw(a.dataset.pid, a.dataset.name);}));';
    echo 'document.getElementById("latestRawModal").addEventListener("click", (e)=>{if(e.target.id==="latestRawModal") closeLatestRaw();});';
    echo '</script>';

    page_footer();
    exit;
}

if ($a === 'dashboard') {
    $projectId = (int)($_GET['id'] ?? 0);
    $date = (string)($_GET['date'] ?? '');
    if (!$projectId || !auth_can_access_project($uid, $projectId)) { http_response_code(404); exit('not found'); }
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) {
        $date = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
    }
    $project = db_one('SELECT * FROM projects WHERE id=?', [$projectId]);
    $tz = new DateTimeZone('Asia/Tokyo');
    $today = (new DateTime('now', $tz))->format('Y-m-d');

    page_header('詳細: ' . $project['name'] . ' / ' . $date);
    echo '<div><a href="/?a=project&id=' . h((string)$projectId) . '">戻る</a> / <a href="/?a=trends&id=' . h((string)$projectId) . '">日次トレンド</a></div>';

    echo '<div class=card>';
    echo '<form method=get style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap">';
    echo '<input type=hidden name=a value=dashboard><input type=hidden name=id value=' . h((string)$projectId) . '>';
    echo '<div><label>Date</label><br><input type=date name=date value="' . h($date) . '"></div>';
    echo '<div><button type=submit>表示</button></div>';
    echo '</form>';
    echo '</div>';


    $prompts = db_all('SELECT id,name FROM project_prompts WHERE project_id=? AND is_active=1 ORDER BY id', [$projectId]);
    $runs = db_all('SELECT * FROM runs WHERE project_id=? AND day_key=? ORDER BY hour ASC, prompt_id ASC', [$projectId, $date]);

    $trows = db_all("SELECT type,domain FROM project_domains WHERE project_id=? ORDER BY FIELD(type,'main','sub'), id", [$projectId]);
    $labelMainDash = '';
    foreach ($trows as $tr) { if ($tr['type'] === 'main') { $labelMainDash = (string)$tr['domain']; break; } }

    // saved log (1/day) per prompt: overwritten each run, so it keeps the latest text for that day
    $savedRows = db_all('SELECT NULL AS id, prompt_id, run_at, response_text FROM daily_logs WHERE project_id=? AND day_key=? ORDER BY run_at DESC', [$projectId, $date]);
    $savedByPrompt = [];
    foreach ($savedRows as $sr) {
        $pid2 = (int)$sr['prompt_id'];
        if (!isset($savedByPrompt[$pid2])) $savedByPrompt[$pid2] = $sr;
    }

    // aggregate
    $total = count($runs);
    $hitMain = 0;
    $hitAnySub = 0;
    $subCounts = [];
    foreach ($runs as $r) {
        if ((int)$r['hit_main'] === 1) $hitMain++;
        $subs = $r['hit_sub'] ? json_decode($r['hit_sub'], true) : [];
        if (is_array($subs) && count($subs) > 0) $hitAnySub++;
        if (is_array($subs)) {
            foreach ($subs as $d) {
                $subCounts[$d] = ($subCounts[$d] ?? 0) + 1;
            }
        }
    }


    // --- Per-prompt aggregates (daily)
    $byPrompt = [];
    foreach ($prompts as $p) {
        $byPrompt[(int)$p['id']] = [
            'prompt_id' => (int)$p['id'],
            'prompt_name' => (string)$p['name'],
            'exec_count' => 0,
            'match_main' => 0,
            'hit_main' => 0,
            'hit_sub1' => 0,
            'hit_sub2' => 0,
            'hit_sub3' => 0,
        ];
    }
    foreach ($runs as $r) {
        $pid2 = (int)$r['prompt_id'];
        if (!isset($byPrompt[$pid2])) continue;
        $byPrompt[$pid2]['exec_count']++;
        $isMain = ((int)$r['hit_main'] === 1);
        if ($isMain) $byPrompt[$pid2]['match_main']++;
        // per-slot hits
        if ((int)$r['hit_sub1'] === 1) $byPrompt[$pid2]['hit_sub1']++;
        if ((int)$r['hit_sub2'] === 1) $byPrompt[$pid2]['hit_sub2']++;
        if ((int)$r['hit_sub3'] === 1) $byPrompt[$pid2]['hit_sub3']++;
        if ($isMain) $byPrompt[$pid2]['hit_main']++;
    }


    echo '<div class=card><h2>サマリー（プロンプト別・日次）</h2>';
    if ($total === 0) {
        echo '<div>この日の実行データはまだありません。</div>';
    } else {
        echo '<div>総実行回数: ' . h((string)$total) . '</div>';
        echo '<table><thead><tr><th>プロンプト</th><th>実行回数</th><th>自社(メイン)%</th><th>競合A(サブ1)%</th><th>競合B(サブ2)%</th><th>競合C(サブ3)%</th></tr></thead><tbody>';
        foreach ($byPrompt as $row) {
            $exec = (int)$row['exec_count'];
                        echo '<tr>';
            echo '<td>' . h($row['prompt_name'] ?: ('#'.$row['prompt_id'])) . '</td>';
            echo '<td>' . h((string)$exec) . '</td>';
            $pMain = $exec ? round(((int)$row['hit_main']) / $exec * 100, 1) : 0.0;
            $pS1 = $exec ? round(((int)$row['hit_sub1']) / $exec * 100, 1) : 0.0;
            $pS2 = $exec ? round(((int)$row['hit_sub2']) / $exec * 100, 1) : 0.0;
            $pS3 = $exec ? round(((int)$row['hit_sub3']) / $exec * 100, 1) : 0.0;
            echo '<td>' . h((string)$pMain) . '%</td>';
            echo '<td>' . h((string)$pS1) . '%</td>';
            echo '<td>' . h((string)$pS2) . '%</td>';
            echo '<td>' . h((string)$pS3) . '%</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    // --- 詳細テーブル（プロンプト別 / 競合(サブ)横軸）
    $targetsSub = db_all("SELECT domain FROM project_domains WHERE project_id=? AND type='sub' ORDER BY id", [$projectId]);
    $subs = array_map(fn($r) => (string)$r['domain'], $targetsSub);

    // Prefer per-run snapshot sub targets (stable even if settings changed later)
    $snapSubs = [];
    foreach ($runs as $r) {
        if (!isset($r['target_sub_texts']) || !$r['target_sub_texts']) continue;
        $arr = json_decode((string)$r['target_sub_texts'], true);
        if (!is_array($arr)) continue;
        foreach ($arr as $s) {
            $s = (string)$s;
            if ($s === '') continue;
            $snapSubs[$s] = true;
        }
    }
    if ($snapSubs) {
        $subs = array_keys($snapSubs);
    }

    echo '<div class=card><h2>詳細（プロンプト別）</h2>';
    if (!$prompts) {
        echo '<div>プロンプトがありません。</div>';
    } else {
        $now = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $isToday = ($date === $now->format('Y-m-d'));
        $curHour = (int)$now->format('G');

        foreach ($prompts as $pp) {
            $pid2 = (int)$pp['id'];
            $pname = (string)$pp['name'];

            // index runs by hour for this prompt
            $byHour = [];
            foreach ($runs as $r) {
                if ((int)$r['prompt_id'] !== $pid2) continue;
                $byHour[(int)$r['hour']] = $r;
            }

            echo '<h3>' . h($pname ?: ('#'.$pid2)) . '</h3>';
            echo '<div style="overflow:auto">';
            echo '<table><thead><tr>';
            echo '<th>Hour</th><th>実行</th><th>' . h($labelMainDash !== '' ? $labelMainDash : 'メイン') . '</th>';
            if ($subs) {
                foreach ($subs as $s) {
                    echo '<th>' . h($s) . '</th>';
                }
            } else {
                echo '<th><small>※サブ（競合）未設定</small></th>';
            }
            echo '</tr></thead><tbody>';

            for ($h=0; $h<24; $h++) {
                $r = $byHour[$h] ?? null;
                echo '<tr>';
                echo '<td>' . sprintf('%02d:00', $h) . '</td>';

                if (!$r) {
                    if ($isToday && $h > $curHour) {
                        echo '<td style="background:#f6f6f6; color:#666">なし</td>'; // 未取得
                        echo '<td style="background:#f6f6f6; color:#666">-</td>';
                        if ($subs) foreach ($subs as $_) echo '<td style="background:#f6f6f6; color:#666">-</td>';
                        else echo '<td style="background:#f6f6f6; color:#666">-</td>';
                    } else {
                        echo '<td style="background:#ffecec">未</td>'; // 未実行
                        echo '<td style="background:#ffecec">×</td>';
                        if ($subs) foreach ($subs as $_) echo '<td style="background:#ffecec">×</td>';
                        else echo '<td style="background:#ffecec">×</td>';
                    }
                    echo '</tr>';
                    continue;
                }

                // OK
                echo '<td style="background:#eaffea">済</td>';

                $isMain = ((int)$r['hit_main'] === 1);
                echo '<td style="background:' . ($isMain ? '#eaffea' : '#fff7e6') . '">' . ($isMain ? 'hit' : '-') . '</td>';

                $hits = $r['hit_sub'] ? json_decode($r['hit_sub'], true) : [];
                if (!is_array($hits)) $hits = [];

                if ($subs) {
                    foreach ($subs as $s) {
                        $hit = in_array($s, $hits, true);
                        echo '<td style="background:' . ($hit ? '#eaffea' : '#fff7e6') . '">' . ($hit ? 'hit' : '-') . '</td>';
                    }
                } else {
                    echo '<td>-</td>';
                }

                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
        }

        echo '<div style="color:#666;margin-top:8px">凡例：済=実行あり / 未=実行なし（過去の時間） / なし=未取得（今日の未来時間）</div>';
    }


    // --- Log modal (popup)
    echo '<div id="logModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:9999; padding:24px;">';
    echo '<div style="max-width:960px; margin:0 auto; background:#fff; border:1px solid rgba(15,15,15,.16); border-radius:14px; box-shadow:0 12px 30px rgba(15,15,15,.20); overflow:hidden">';
    echo '<div style="display:flex; justify-content:space-between; align-items:center; padding:12px 14px; border-bottom:1px solid rgba(15,15,15,.12)">';
    echo '<div><strong id="logModalTitle">ログ</strong> <small id="logModalMeta" style="margin-left:8px"></small></div>';
    echo '<button type="button" onclick="closeLogModal()">閉じる</button>';
    echo '</div>';
    echo '<div style="padding:14px"><pre id="logModalBody" style="white-space:pre-wrap"></pre></div>';
    echo '</div>';
    echo '</div>';

    echo '<script>';
    echo 'const SAVED_BY_PROMPT=' . json_encode($savedByPrompt, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';';
    echo 'function openLogModal(promptId, promptName){const m=document.getElementById("logModal"); const t=document.getElementById("logModalTitle"); const meta=document.getElementById("logModalMeta"); const b=document.getElementById("logModalBody"); const row=SAVED_BY_PROMPT[String(promptId)] || SAVED_BY_PROMPT[promptId]; if(!row){t.textContent=`ログ: ${promptName}`; meta.textContent="（この日はログなし）"; b.textContent=""; } else {t.textContent=`ログ: ${promptName}`; meta.textContent=row.run_at ? `(${row.run_at})` : ""; b.textContent=row.response_text || "";} m.style.display="block"; }';
    echo 'function closeLogModal(){document.getElementById("logModal").style.display="none";}';
    echo 'document.getElementById("logModal").addEventListener("click", (e)=>{if(e.target.id==="logModal") closeLogModal();});';
    echo '</script>';
    echo '</div>';
    page_footer();
    exit;
}

if ($a === 'latest_raw') {
    $projectId = (int)($_GET['id'] ?? 0);
    $promptId = (int)($_GET['prompt_id'] ?? 0);
    if (!$projectId || !$promptId || !auth_can_access_project($uid, $projectId)) { http_response_code(404); exit('not found'); }

    header('Content-Type: application/json; charset=utf-8');
    $row = db_one('SELECT day_key, run_at, response_text FROM daily_logs WHERE project_id=? AND prompt_id=? ORDER BY day_key DESC, run_at DESC LIMIT 1', [$projectId, $promptId]);
    echo json_encode(['project_id'=>$projectId,'prompt_id'=>$promptId,'row'=>$row], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($a === 'raw') {
    $projectId = (int)($_GET['id'] ?? 0);
    $date = (string)($_GET['date'] ?? '');
    if (!$projectId || !auth_can_access_project($uid, $projectId)) { http_response_code(404); exit('not found'); }
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) {
        $date = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
    }

    header('Content-Type: application/json; charset=utf-8');
    $runs = db_all('SELECT * FROM runs WHERE project_id=? AND day_key=? ORDER BY hour ASC, prompt_id ASC', [$projectId, $date]);
    echo json_encode(['project_id'=>$projectId,'date'=>$date,'runs'=>$runs], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($a === 'run_text') {
    $rid = (int)($_GET['rid'] ?? 0);
    $run = db_one('SELECT * FROM runs WHERE id=?', [$rid]);
    if (!$run) { http_response_code(404); exit('not found'); }
    if (!auth_can_access_project($uid, (int)$run['project_id'])) { http_response_code(403); exit('forbidden'); }

    page_header('保存テキスト');
    echo '<div><a href="/?a=dashboard&id=' . h((string)$run['project_id']) . '&date=' . h((string)$run['day_key']) . '">戻る</a></div>';
    echo '<div class=card><pre style="white-space:pre-wrap">' . h($run['response_text']) . '</pre></div>';
    page_footer();
    exit;
}

http_response_code(404);
echo 'Unknown action';
