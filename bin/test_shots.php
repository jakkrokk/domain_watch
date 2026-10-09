<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/shots.php';
if (PHP_SAPI !== 'cli') exit("cli only\n");
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
// Session-local tables shadow the real queue, so tests never call paid APIs or touch live jobs.
foreach (['shots','shot_results'] as $table) {
    $ddl = db_one('SHOW CREATE TABLE ' . $table)['Create Table'];
    $ddl = preg_replace('/,\n  CONSTRAINT[^\n]+/', '', $ddl);
    db()->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl));
}
$input = ['name'=>'提案書調査','main'=>'自社','sub'=>['競合A','','競合B'],'prompts'=>"質問1\r\n\r\n質問2"];
$parsed = shot_validate($input);
check(count($parsed[3]) === 2 && $parsed[2] === ['競合A','競合B'], 'Blank lines and targets');
check(count(shot_validate(array_replace($input, ['sub'=>['a','b','c','d','e']]))[2]) === 5, 'Five measured strings accepted');
shot_validate(array_replace($input, ['prompts'=>implode("\n",array_fill(0,50,'質問'))]));
foreach ([['prompts'=>implode("\n",array_fill(0,51,'質問'))],['prompts'=>" \n "],['main'=>''],['name'=>str_repeat('あ',256)],['sub'=>['a','b','c','d','e','f']],['prompts'=>['invalid']]] as $invalid) {
    try { shot_validate(array_replace($input,$invalid)); throw new RuntimeException('Invalid input accepted'); }
    catch (InvalidArgumentException $e) {}
}
try { shot_create(1,$input,[]); throw new RuntimeException('Missing providers accepted'); } catch (InvalidArgumentException $e) {}
$id = shot_create(1,$input,['openai','gemini']);
$shot = db_one('SELECT * FROM shots WHERE id=?',[$id]);
check($shot['status'] === 'queued', 'Persist before processing');
check((int)db_one('SELECT COUNT(*) n FROM shot_results WHERE shot_id=?',[$id])['n'] === 4,'Provider fanout');
$calls = 0;
$fake = static function ($provider,$prompt,$runId) use (&$calls): array {
    $calls++;
    if ($provider === 'gemini' && $prompt === '質問2') throw new RuntimeException('Simulated failure');
    return ['text'=>"自社の回答\n競合A（検索メタデータ）",'answer_text'=>'自社の回答','model'=>'test','search_performed'=>false];
};
shot_process($shot,$fake);
check($calls === 4, 'All prompts attempted despite failure');
$shot = db_one('SELECT * FROM shots WHERE id=?',[$id]);
check($shot['status'] === 'partial' && $shot['finished_at'] !== null, 'Partial completion');
$results = db_all('SELECT * FROM shot_results WHERE shot_id=? AND status=?',[$id,'completed']);
check(count($results) === 3,'Success retained');
foreach ($results as $result) check((int)$result['hit_main'] === 1 && json_decode($result['hit_sub'],true) === [0,0], 'Only answer text contributes to hits');
shot_process($shot,$fake);
check($calls === 4,'Finished shots never repeat');
$id2 = shot_create(1,$input,['openai']);
db_exec("UPDATE shots SET status='running' WHERE id=?",[$id2]);
db_exec("UPDATE shot_results SET status='running' WHERE shot_id=? AND prompt_index=1",[$id2]);
$shot2 = db_one('SELECT * FROM shots WHERE id=?',[$id2]);
shot_process($shot2,$fake);
check($calls === 5,'Interrupted request is not repeated; pending request continues');
check(db_one('SELECT status FROM shots WHERE id=?',[$id2])['status'] === 'partial','Interrupted status finalized');
$id3 = shot_create(1,array_replace($input,['prompts'=>'質問1']),['gemini']);
shot_process(db_one('SELECT * FROM shots WHERE id=?',[$id3]),$fake);
check(db_one('SELECT status FROM shots WHERE id=?',[$id3])['status'] === 'completed','Completed status');
$id4 = shot_create(1,array_replace($input,['prompts'=>'質問2']),['gemini']);
shot_process(db_one('SELECT * FROM shots WHERE id=?',[$id4]),$fake);
check(db_one('SELECT status FROM shots WHERE id=?',[$id4])['status'] === 'failed','Failed status');
echo "Shot validation, fanout, saved results, failure recovery and idempotency passed\n";
