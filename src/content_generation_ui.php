<?php
declare(strict_types=1);
require_once __DIR__ . '/content_generation.php';
$user = auth_user();
if (!$user) redirect('/?a=login&return=content-generation');
$uid = (int)$user['id'];
header('Cache-Control: private, no-store');
if (isset($_GET['download'])) {
    $id = (int)$_GET['download'];
    $job = db_one('SELECT * FROM content_jobs WHERE id=? AND owner_user_id=?', [$id,$uid]);
    if (!$job || !in_array($job['status'], ['completed','partial'], true) || !is_file(content_artifact($id))) { http_response_code(404); exit('ファイルが見つかりません。'); }
    session_write_close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="content-generation-' . $id . '.xlsx"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize(content_artifact($id)));
    readfile(content_artifact($id)); exit;
}
if (($_GET['format'] ?? '') === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    $jobs = db_all("SELECT id,status,stage FROM content_jobs WHERE owner_user_id=? ORDER BY id DESC LIMIT 100",[$uid]);
    echo content_json($jobs); exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        content_create($uid,$_POST);
        flash_set('ok','キーワードを登録しました。順番に調査・構成案を作成します。');
        redirect('/content-generation');
    } catch (InvalidArgumentException $e) { http_response_code(422); $error = $e->getMessage(); }
}
$jobs = db_all("SELECT j.*, (SELECT COUNT(*) FROM content_items i WHERE i.job_id=j.id AND i.status='completed') done, (SELECT COUNT(*) FROM content_items i WHERE i.job_id=j.id) total FROM content_jobs j WHERE owner_user_id=? ORDER BY id DESC LIMIT 100",[$uid]);
page_header('コンテンツ構成案の作成');
?>
<style>
.topbar{flex-wrap:wrap}.brand,.nav a{white-space:nowrap}.cg-help{color:var(--muted);font-size:13px;line-height:1.8}.cg-fields{display:grid;grid-template-columns:1fr 1fr;gap:16px}.cg-fields .cg-wide{grid-column:1/-1}.cg-form label{display:block;font-weight:600;margin-bottom:7px}.cg-form input,.cg-form textarea{width:100%;max-width:none}.cg-form textarea{min-height:180px}.cg-optional{font-weight:400;font-size:12px;color:var(--muted)}.cg-task{display:flex;justify-content:space-between;gap:20px;padding:20px 0;border-top:1px solid var(--border)}.cg-task:first-child{border-top:0}.cg-main{min-width:0;flex:1;overflow-wrap:anywhere}.cg-right{text-align:right;min-width:150px}.cg-badge{display:inline-block;border-radius:20px;padding:4px 12px;font-size:13px;background:#eee}.cg-completed{color:#12603e;background:#e5f6ed}.cg-running{color:#2153aa;background:#eaf1ff}.cg-failed,.cg-partial{color:#9c4114;background:#fff0e5}.cg-keywords{margin:8px 0;font-weight:600}.cg-dl{display:inline-block;margin-top:10px;font-weight:600}.cg-error{color:var(--err)}@media(max-width:600px){.cg-fields{grid-template-columns:1fr}.cg-task{flex-direction:column}.cg-right{text-align:left}}

.container{max-width:1600px}
.cg-layout{display:grid;grid-template-columns:minmax(0,7fr) minmax(0,3fr);gap:24px;align-items:start}
.cg-layout>.card{min-width:0;margin:0}
.cg-tasks{position:sticky;top:20px;max-height:calc(100dvh - 40px);overflow:auto}
.cg-task-heading{display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap}
.cg-task-heading h2{margin:0}
.cg-task-heading a{font-size:13px;white-space:nowrap}
.cg-task{position:relative;flex-direction:column;gap:8px}
.cg-main>small{display:block;padding-right:76px}
.cg-right{min-width:0;text-align:left;overflow-wrap:anywhere}
.cg-right .cg-badge{position:absolute;right:0;top:20px}
.cg-right p{margin:0}
@media(max-width:960px){.cg-layout{grid-template-columns:minmax(0,1fr);gap:20px}.cg-tasks{position:static;max-height:none;overflow:visible}}
</style>
<p class="cg-help">キーワードから検索意図・競合・AI回答を調査し、SEO／LLMOを考慮した記事構成案をExcelで作成します。</p>
<div class="cg-layout">
<section class="card cg-form" aria-labelledby="cg-form-heading">
<h2 id="cg-form-heading">新しい作業を登録</h2>
<?php if ($error): ?><p class="cg-error" role="alert"><?= h($error) ?></p><?php endif ?>
<form method="post" action="/content-generation" id="cg-form">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<div class="row"><label for="cg-keywords">キーワード <span class="cg-optional">必須・改行区切りで最大10個</span></label>
<textarea id="cg-keywords" name="keywords" required maxlength="3000" placeholder="エンジニア 転職&#10;エンジニア 年収"><?= h(is_string($_POST['keywords'] ?? null) ? $_POST['keywords'] : '') ?></textarea><div class="cg-help" id="cg-count" aria-live="polite">0 / 10個</div></div>
<div class="cg-fields">
<?php foreach (['site_url'=>['URL','https://example.com/','url',2048], 'service'=>['対象サービス','例：エンジニア向け転職支援サービス','text',4000], 'audience'=>['対象読者','例：転職を検討している20〜30代のエンジニア','text',4000]] as $key=>$field): ?>
<div class="<?= $key==='site_url' ? 'cg-wide' : '' ?>"><label for="cg-<?= h($key) ?>"><?= h($field[0]) ?> <span class="cg-optional">任意</span></label><input id="cg-<?= h($key) ?>" type="<?= h($field[2]) ?>" name="<?= h($key) ?>" maxlength="<?= $field[3] ?>" placeholder="<?= h($field[1]) ?>" value="<?= h(is_string($_POST[$key] ?? null) ? $_POST[$key] : '') ?>"></div>
<?php endforeach ?>
</div>
<p class="cg-help">任意項目は空欄のまま登録できます。キーワードや公開情報から推定し、推定した内容・根拠をExcelに記載します。入力内容は今回のすべてのキーワードに適用されます。</p>
<div class="row"><label for="cg-notes">構成案作成の参考メモ <span class="cg-optional">任意・10,000文字まで</span></label>
<textarea id="cg-notes" name="notes" maxlength="10000" aria-describedby="cg-notes-help" placeholder="例：田舎の土地を所有する人向けに、活用方法の一覧ではなく、成功条件・失敗要因・土地の適性・成功事例を解説する。&#10;既存の「土地活用アイデア」と重複せず、どのような土地なら成功しやすいかの判断に特化する。"><?= h(is_string($_POST['notes'] ?? null) ? $_POST['notes'] : '') ?></textarea>
<p class="cg-help" id="cg-notes-help">記事の方向性、重視したい内容、避けたい内容、既存記事との違いなどを入力できます。今回のすべてのキーワードの構成案作成で参考にします。特定のキーワードだけに適用したい場合は、メモ内にキーワードを記載してください。</p></div>
<button type="submit" id="cg-submit">構成案の作成を依頼</button>
</form></section>
<section class="card cg-tasks" aria-labelledby="cg-tasks-heading"><div class="cg-task-heading"><h2 id="cg-tasks-heading">作業タスク一覧</h2><a href="/content-generation">状態を更新</a></div>
<p class="cg-help" id="cg-updates" role="status">最新100件まで表示します。完了後、この一覧からExcelをダウンロードできます。</p>
<div>
<?php if (!$jobs): ?><p class="cg-help">作業タスクはまだありません。</p><?php endif ?>
<?php foreach ($jobs as $job): $items = db_all('SELECT keyword,status,error_message FROM content_items WHERE job_id=? ORDER BY position',[$job['id']]); ?>
<article class="cg-task" data-job-id="<?= (int)$job['id'] ?>" data-status="<?= h($job['status']) ?>" data-stage="<?= h($job['stage']) ?>">
<div class="cg-main"><small>#<?= (int)$job['id'] ?> · <?= h($job['created_at']) ?></small><div class="cg-keywords"><?= h(implode(' / ',array_column($items,'keyword'))) ?></div>
<div class="cg-help">対象サービス：<?= h($job['service'] ?: '自動推定') ?><br>対象読者：<?= h($job['audience'] ?: '自動推定') ?><br>URL：<?= h($job['site_url'] ?: '自動推定') ?></div>
<?php if (!empty($job['notes'])): ?><details class="cg-help"><summary>参考メモ</summary><div style="white-space:pre-wrap"><?= h($job['notes']) ?></div></details><?php endif ?>
<?php if ($job['error_message']): ?><p class="cg-error"><?= h($job['error_message']) ?></p><?php endif ?>
<?php foreach ($items as $item): if ($item['status']==='failed'): ?><p class="cg-error"><?= h($item['keyword']) ?>：<?= h($item['error_message']) ?></p><?php endif; endforeach ?>
</div><div class="cg-right"><span class="cg-badge cg-<?= h($job['status']) ?>"><?= h(content_status($job['status'])) ?></span><p class="cg-help"><?= (int)$job['done'] ?> / <?= (int)$job['total'] ?>件 完了<br><?= h($job['stage']) ?></p>
<?php if (in_array($job['status'],['completed','partial'],true) && is_file(content_artifact((int)$job['id']))): ?><a class="cg-dl" href="/content-generation?download=<?= (int)$job['id'] ?>">Excelをダウンロード</a><?php endif ?>
</div></article>
<?php endforeach ?>
</div>
</section>
</div>
<script>
const cgKeywords=document.getElementById('cg-keywords');
const cgCount=document.getElementById('cg-count');
let cgDirty=<?= $_SERVER['REQUEST_METHOD']==='POST' ? 'true' : 'false' ?>;
function cgValidate(){const n=cgKeywords.value.split(/\r\n|[\n\r\u0085\u2028\u2029]/u).filter(v=>v.trim()).length;cgCount.textContent=`${n} / 10個`;cgKeywords.setCustomValidity(n>10?'キーワードは10個までです。':n===0?'キーワードを入力してください。':'');}
cgKeywords.addEventListener('input',cgValidate);cgValidate();
document.getElementById('cg-form').addEventListener('input',()=>{cgDirty=true;});
document.getElementById('cg-form').addEventListener('submit',()=>{document.getElementById('cg-submit').disabled=true;});
if(document.querySelector('[data-status="queued"],[data-status="running"]'))setInterval(async()=>{try{const response=await fetch('/content-generation?format=status');if(!response.ok||!response.headers.get('content-type')?.includes('application/json'))return;const jobs=await response.json();const changed=jobs.some(j=>{const el=document.querySelector(`[data-job-id="${j.id}"]`);return el&&(el.dataset.status!==j.status||el.dataset.stage!==j.stage);});if(changed){if(!cgDirty)location.reload();else document.getElementById('cg-updates').textContent='作業状況が更新されました。入力を終えたら「状態を更新」で確認できます。';}}catch{}},15000);
</script>
<?php page_footer(); ?>
