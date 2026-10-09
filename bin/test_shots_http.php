<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/shots.php';
if (PHP_SAPI !== 'cli') exit("cli only\n");
$lock = fopen(__DIR__ . '/../storage/shots.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Shot worker is active; retry after completion\n");
$base = $argv[1] ?? 'http://127.0.0.1:8000';
$cookie = tempnam(sys_get_temp_dir(),'shots-http-');
$uids = [];
function request(string $url, ?array $post = null): array {
    global $cookie;
    $c = curl_init($url);
    $headers = [];
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>10,CURLOPT_HEADERFUNCTION=>static function ($curl, string $line) use (&$headers): int {
        if (str_contains($line, ':')) {
            [$name,$value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return strlen($line);
    }]);
    if ($post !== null) curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $body = curl_exec($c);
    if ($body === false) throw new RuntimeException(curl_error($c));
    $status = curl_getinfo($c,CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return [$status,$body,$headers];
}
function ensure(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function token(string $body): string {
    if (!preg_match('/name="csrf" value="([^"]+)"/',$body,$m)) throw new RuntimeException('Missing CSRF token');
    return $m[1];
}
try {
    $password = bin2hex(random_bytes(12));
    $emails = [];
    for ($i=0;$i<2;$i++) {
        $emails[$i] = 'shots-test-' . bin2hex(random_bytes(8)) . '@example.invalid';
        db_exec('INSERT INTO users(email,password_hash) VALUES(?,?)',[$emails[$i],password_hash($password,PASSWORD_DEFAULT)]);
        $uids[] = (int)db()->lastInsertId();
    }
    $id = shot_create($uids[0],['name'=>'HTTP確認用ショット','main'=>'自社','sub'=>['競合'],'prompts'=>'<script>alert(1)</script>'],['openai']);
    shot_process(db_one('SELECT * FROM shots WHERE id=?',[$id]),static fn()=>['text'=>'自社の回答','model'=>'test']);
    ensure(request($base . '/?newa=shots')[0] === 302,'Anonymous access redirects');
    ensure(request($base . '/?newa=shot&id=' . $id . '&download=csv')[0] === 302,'Anonymous RAW download redirects');
    foreach ($emails as $i=>$email) {
        [$status,$body] = request($base . '/?a=login');
        ensure(request($base . '/?a=login',['csrf'=>token($body),'email'=>$email,'password'=>$password])[0] === 302,'Login');
        [$status,$body] = request($base . '/?newa=shots');
        ensure($status === 200 && str_contains($body,'ショット一覧'),'List renders');
        ensure(str_contains($body,'HTTP確認用ショット') === ($i===0),'Only owned shots listed');
        [$status,$body] = request($base . '/?newa=shot&id=' . $id);
        ensure($status === ($i===0 ? 200 : 404),'Detail ownership enforced');
        [$downloadStatus,$download] = request($base . '/?newa=shot&id=' . $id . '&download=xlsx');
        ensure($downloadStatus === ($i===0 ? 200 : 404),'Download ownership enforced');
        if ($i===0) ensure(str_starts_with($download,'PK'),'Excel download served');
        [$csvStatus,$csv,$headers] = request($base . '/?newa=shot&id=' . $id . '&download=csv');
        ensure($csvStatus === ($i===0 ? 200 : 404),'RAW CSV ownership enforced');
        if ($i===0) {
            ensure(str_contains($body,'AnswerIO形式でDL') && str_contains($body,'download=csv'),'RAW CSV download link renders');
            ensure(($headers['content-type'] ?? '') === 'text/csv; charset=UTF-8','RAW CSV content type');
            ensure(str_contains($headers['content-disposition'] ?? '', 'attachment; filename="shot-' . $id . '-RAW.csv"'),'RAW CSV attachment filename');
            ensure(($headers['content-length'] ?? '') === (string)strlen($csv) && ($headers['cache-control'] ?? '') === 'private, no-store','Download length and private caching');
            ensure(str_starts_with($csv,"\xEF\xBB\xBFproject_id,project_name,prompt_id,prompt_name,provider,date,exec_count,main_text,main_rate_pct"),'Compatible RAW CSV header and BOM');
            $lines = explode("\r\n", substr($csv,3));
            $row = str_getcsv($lines[1], ',', '"', '');
            ensure(count($lines) === 3 && $row[0] === (string)$id && $row[1] === 'HTTP確認用ショット' && $row[2] === '1' && $row[3] === '<script>alert(1)</script>' && $row[4] === 'openai','Saved prompt and shot exported once');
            ensure($row[6] === '1' && $row[7] === '自社' && $row[8] === '100' && $row[9] === '競合' && $row[10] === '0' && array_slice($row,11) === ['','','',''],'RAW CSV saved hit values and empty slots');
            ensure(str_contains($body,'100%') && str_contains($body,'自社の回答'),'Result and aggregate rendered');
            ensure(!str_contains($body,'<script>alert(1)</script>'),'Prompt escaped');
            [$status,$body] = request($base . '/?newa=shot_new');
            ensure($status===200 && str_contains($body,'shot-prompts'),'Create form renders');
            ensure(substr_count($body,'name="sub[]"')===5 && str_contains($body,'計測したい文字列（最大5件・任意）'),'Five measured-string fields rendered');
            $csrf = token($body);
            $before = db_one('SELECT COUNT(*) n FROM shots')['n'];
            [$status,$body] = request($base . '/?newa=shot_new',['csrf'=>$csrf,'name'=>'上限確認','main'=>'自社','prompts'=>implode("\n",array_fill(0,51,'質問'))]);
            ensure($status===422 && str_contains($body,'1〜50件'),'HTTP limit enforced');
            ensure(db_one('SELECT COUNT(*) n FROM shots')['n']===$before,'Invalid input not queued');
            ensure(request($base . '/?newa=shot_new',['name'=>'CSRF確認'])[0]===400,'CSRF enforced');
        }
        request($base . '/?a=logout');
    }
    echo "Shot HTTP pages, Excel and RAW CSV downloads, aggregates, ownership, escaping, validation and CSRF passed\n";
} finally {
    foreach ($uids as $uid) db_exec('DELETE FROM users WHERE id=?',[$uid]);
    unlink($cookie);
}
