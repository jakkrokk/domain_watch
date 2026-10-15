<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/content_generation.php';
session_write_close();
$testDatabase = (string)env_get('DB_NAME');
$lockPath = preg_match('/^domain_watch_test_[a-f0-9]+$/', $testDatabase) ? sys_get_temp_dir().'/'.$testDatabase.'.lock' : __DIR__.'/../storage/content-generation.lock';
$lock=fopen($lockPath,'c');
if (!$lock || !flock($lock, LOCK_EX|LOCK_NB)) exit("Worker active; retry later\n");
function ensure(bool $ok,string $message): void {if (!$ok) throw new RuntimeException($message);}
$base=$argv[1]??'http://127.0.0.1:8000';
$cookie=tempnam(sys_get_temp_dir(),'content-test-');$uids=[];$ids=[];
function request(string $path, ?array $post=null): array {
 global $base,$cookie;
 $ch=curl_init($base.$path);$headers=[];
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>20,CURLOPT_HEADERFUNCTION=>static function($ch,$line) use (&$headers){if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);}]);
 if($post!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
 $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return [$status,$body,$headers];
}
function token(string $body):string {preg_match('/name="csrf" value="([^"]+)"/',$body,$m);return $m[1]??'';}
function fixture(): array {
 return ['title'=>'テスト構成案','related_keywords'=>['関連'],'audience'=>'推定読者','service'=>'推定サービス','site_url'=>'未特定','assumptions'=>['読者を推定'],'intent'=>'情報収集','information_needs'=>['始め方'],'initial_outline'=>['最初の構成'],'conversion_plan'=>'次の行動','lead'=>'リード文','llmo_analysis'=>'ChatGPTは箇条書き。Geminiは表。判断基準を比較表にする。','limitations'=>['順位未取得'],'sections'=>[['level'=>'H2','heading'=>'始め方','points'=>['手順を説明する'],'word_count'=>500,'format'=>'表','sources'=>['https://example.com/']]],'competitors'=>[['rank'=>0,'title'=>'競合','url'=>'https://example.com/','word_count'=>0,'headings'=>['概要'],'useful_information'=>'手順','adopted_points'=>'比較観点']],'fanout'=>[['provider'=>'openai','query'=>'検索クエリ','heading'=>'始め方','action'=>'回答を含める']]];
}
function clean_dir(string $dir): void {if(!is_dir($dir))return;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($dir);}
try {
 ensure(content_validate(['keywords'=>"a\r\nb\n\n"])['keywords']===['a','b'],'newline parsing');
 ensure(count(content_validate(['keywords'=>implode("\n",range(1,10))])['keywords'])===10,'10 allowed');
 foreach([['keywords'=>''],['keywords'=>implode("\n",range(1,11))],['keywords'=>['array']],['keywords'=>'a','site_url'=>'javascript:alert(1)'],['keywords'=>'a','service'=>['array']],['keywords'=>'a','notes'=>['array']],['keywords'=>'a','notes'=>str_repeat('あ',10001)]] as $input){try{content_validate($input);throw new RuntimeException('invalid accepted');}catch(InvalidArgumentException $e){}}
 ensure(content_validate(['keywords'=>'a'])['notes']==='', 'notes optional');
 ensure(mb_strlen(content_validate(['keywords'=>'a','notes'=>str_repeat('あ',10000)])['notes'])===10000, 'notes boundary');
 $notes="田舎の土地の成功条件・失敗要因・適性を重視。\n既存の土地活用アイデアと重複させない。<script>memo</script>";
 $password=bin2hex(random_bytes(10));$emails=[];
 for($i=0;$i<2;$i++){ $emails[]='content-test-'.bin2hex(random_bytes(7)).'@example.invalid';db_exec('INSERT INTO users(email,password_hash) VALUES(?,?)',[$emails[$i],password_hash($password,PASSWORD_DEFAULT)]);$uids[]=(int)db()->lastInsertId();}
 $id=content_create($uids[0],['keywords'=>"=HYPERLINK(\"evil\")\n<script>bad</script>",'notes'=>$notes]);$ids[]=$id;
 $calls=0;
 $chat=static function() use (&$calls){$calls++;return ['answer_text'=>'回答','text'=>'回答','search_queries'=>['検索クエリ'],'source_urls'=>['https://example.com/'],'model'=>'test'];};
 content_process(db_one('SELECT * FROM content_jobs WHERE id=?',[$id]),$chat,static function($job,$item,$evidence) use ($notes) {
     ensure($job['notes']===$notes,'notes persisted and passed to generator');
     ensure(str_contains(content_prompt($job,$item,$evidence),content_json($notes)),'notes in actual Codex prompt');
     return fixture();
 });
 ensure($calls===4,'two APIs per keyword');ensure(db_one('SELECT status FROM content_jobs WHERE id=?',[$id])['status']==='completed','complete');
 ensure(is_file(content_artifact($id)),'artifact');
 $interrupted=content_create($uids[0],['keywords'=>"中断\n成功"]);$ids[]=$interrupted;
 db_exec("UPDATE content_items SET status='running' WHERE job_id=? AND position=1",[$interrupted]);
 content_process(db_one('SELECT * FROM content_jobs WHERE id=?',[$interrupted]),$chat,static fn()=>fixture());
 ensure($calls===6,'interrupted API not repeated');ensure(db_one('SELECT status FROM content_jobs WHERE id=?',[$interrupted])['status']==='partial','partial');
 $failed=content_create($uids[0],['keywords'=>'失敗']);$ids[]=$failed;
 content_process(db_one('SELECT * FROM content_jobs WHERE id=?',[$failed]),static fn()=>throw new RuntimeException('test failure'),static fn()=>throw new RuntimeException('should not generate'));
 ensure(db_one('SELECT status FROM content_jobs WHERE id=?',[$failed])['status']==='failed','failed');ensure(!is_file(content_artifact($failed)),'no failed download');
 ensure(request('/content-generation')[0]===302,'anonymous redirected');ensure(request('/content-generation?download='.$id)[0]===302,'anonymous download denied');
 foreach($emails as $i=>$email){
  $login=request('/?a=login&return=content-generation');$response=request('/?a=login&return=content-generation',['csrf'=>token($login[1]),'email'=>$email,'password'=>$password]);
  ensure(($response[2]['location']??'')==='/content-generation','login return');
  [$status,$body]=request('/content-generation');ensure($status===200,'page renders');ensure(!str_contains($body,'<script>bad</script>'),'escaped');
  ensure(!str_contains($body,'<script>memo</script>'),'notes escaped');
  ensure(str_contains($body,h($notes))===($i===0),'notes ownership');
  ensure((bool)preg_match('/<textarea[^>]*name="notes"[^>]*>/', $body,$noteField)&&!str_contains($noteField[0],'required'),'optional notes textarea');
  $download=request('/content-generation?download='.$id);ensure($download[0]===($i===0?200:404),'download ownership');
  $statuses=request('/content-generation?format=status');ensure(str_contains($statuses[1],'completed')===($i===0),'status ownership');
  if($i===0){
   ensure(str_starts_with($download[1],'PK'),'xlsx');
   ensure(request('/content-generation',['keywords'=>'no csrf'])[0]===400,'csrf');
   ensure(request('/content-generation',['csrf'=>token($body),'keywords'=>implode("\n",range(1,11))])[0]===422,'HTTP limit');
   $resp=request('/content-generation',['csrf'=>token($body),'keywords'=>'任意項目空欄']);ensure($resp[0]===302,'empty optionals create');
   $created=db_one('SELECT * FROM content_jobs WHERE owner_user_id=? ORDER BY id DESC LIMIT 1',[$uids[0]]);$ids[]=(int)$created['id'];ensure($created['service']===''&&$created['audience']===''&&$created['site_url']===''&&$created['notes']==='','optionals stored blank');
   $resp=request('/content-generation',['csrf'=>token($body),'keywords'=>'田舎 土地活用 成功例','notes'=>$notes]);ensure($resp[0]===302,'HTTP notes create');
   $created=db_one('SELECT * FROM content_jobs WHERE owner_user_id=? ORDER BY id DESC LIMIT 1',[$uids[0]]);$ids[]=(int)$created['id'];ensure($created['notes']===$notes,'HTTP notes saved with newlines');
   $resp=request('/content-generation',['csrf'=>token($body),'keywords'=>'','notes'=>$notes]);ensure($resp[0]===422&&str_contains($resp[1],h($notes)),'notes retained on validation error');
  }
  request('/?a=logout');
 }
 // Inspect actual Excel values and formula safety independently of the exporter.
 $python=__DIR__.'/../storage/content-venv/bin/python';
 content_command([$python,'-c', 'import openpyxl,sys; w=openpyxl.load_workbook(sys.argv[1]); s=w.worksheets[0]; assert s["C2"].data_type=="s"; assert s["J25"].value==0; assert s["K25"].value==0; assert s["C14"].value=="始め方"; assert len(w.sheetnames)==2; assert s.title==s["C2"].value; assert sys.argv[2] in s["C2"].comment.text; assert s.freeze_panes is None; assert s["C10"].value=="リード文"; assert s["E14"].value=="参照URL"; assert s["E15"].value=="https://example.com/"',content_artifact($id),$notes],__DIR__,'',content_dir($id).'/test.log',30);
 echo "PASS: notes persistence/prompt/Excel/HTTP, validation, optional fields, queue, recovery, failure, Excel, auth, ownership, CSRF, HTTP creation\n";
} finally {foreach($uids as $uid)db_exec('DELETE FROM users WHERE id=?',[$uid]);foreach($ids as $id)clean_dir(content_dir($id));unlink($cookie);}
