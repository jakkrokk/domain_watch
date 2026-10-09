<?php

declare(strict_types=1);

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $to): never {
    header('Location: ' . $to);
    exit;
}

function flash_set(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_get(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string {
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    $t = $_POST['csrf'] ?? '';
    if (!$t || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(400);
        echo 'CSRFが不正です';
        exit;
    }
}

function page_header(string $title, array $opts=[]): void {
    $flash = flash_get();
    $showNav = $opts['showNav'] ?? true;

    echo "<!doctype html><html lang='ja'><head>";
    echo "<meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
    echo '<title>' . h($title) . "</title>";

    // Notion-ish: calm monochrome, generous whitespace, crisp borders.
    echo "<link rel='preconnect' href='https://fonts.googleapis.com'>";
    echo "<link rel='preconnect' href='https://fonts.gstatic.com' crossorigin>";
    echo "<link href='https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;600;700&display=swap' rel='stylesheet'>";

    echo <<<CSS
<style>
:root{
  --bg:#fbfbfa;
  --panel:#ffffff;
  --text:#161616;
  --muted:#6b6b6b;
  --border:rgba(15,15,15,.12);
  --shadow:0 12px 30px rgba(15,15,15,.06);
  --accent:#0b62d6;
  --ok:#0f7b3e;
  --err:#b42318;
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  background:var(--bg);
  color:var(--text);
  font-family:"Noto Sans JP", ui-sans-serif, system-ui;
  letter-spacing:.01em;
}
.container{max-width:980px; margin:0 auto; padding:28px 18px 64px;}
.topbar{display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:18px;}
.brand{font-family:"Noto Sans JP", ui-sans-serif, system-ui; font-weight:700; font-size:18px; letter-spacing:.02em;}
.nav a{color:var(--muted); text-decoration:none; font-weight:500; margin-left:12px;}
.nav a:hover{color:var(--text); text-decoration:underline; text-underline-offset:4px;}

h1{font-family:"Noto Sans JP", ui-sans-serif, system-ui; font-size:28px; margin:0 0 16px;}
h2{font-size:16px; margin:0 0 10px; color:var(--muted); font-weight:600;}
h3{font-size:14px; margin:16px 0 10px; color:var(--muted); font-weight:600;}

.card{
  background:var(--panel);
  border:1px solid var(--border);
  border-radius:14px;
  padding:16px;
  box-shadow:var(--shadow);
  margin:14px 0;
}
.row{margin:12px 0;}
label{font-size:13px; color:var(--muted); font-weight:600;}
input,textarea,select{
  margin-top:6px;
  width:100%;
  padding:10px 12px;
  border-radius:12px;
  border:1px solid var(--border);
  background:#fff;
  font:inherit;
}
textarea{min-height:140px; resize:vertical;}
input:focus,textarea:focus,select:focus{outline:none; border-color:rgba(11,98,214,.45); box-shadow:0 0 0 4px rgba(11,98,214,.12)}

button{
  appearance:none;
  border:1px solid rgba(15,15,15,.14);
  background:#fff;
  padding:10px 14px;
  border-radius:12px;
  font-weight:700;
  cursor:pointer;
}
button:hover{border-color:rgba(15,15,15,.26)}
.provider-toggle{display:inline-flex; align-items:center; gap:8px;}
.provider-toggle-centered{display:flex; justify-content:center; margin:0 0 12px;}
.provider-toggle a{display:inline-flex; align-items:center; justify-content:center; min-width:96px; padding:8px 16px; border:1px solid var(--border); border-radius:10px; background:var(--panel); color:var(--muted); font-size:13px; font-weight:600; text-decoration:none;}
.provider-toggle a:hover{border-color:var(--accent); color:var(--accent);}
.provider-toggle a[aria-current=true]{background:var(--accent); border-color:var(--accent); color:#fff;}
.raw-heading{display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:11px; color:#888; margin-bottom:4px;}
.raw-copy{padding:3px 8px; border-radius:6px; font-size:11px; color:var(--accent);}
.raw-copy:disabled{cursor:wait; opacity:.6;}
.raw-copy-status{color:var(--muted);}

a{color:var(--accent)}

.flash{padding:12px 12px; border-radius:12px; margin:12px 0; border:1px solid var(--border); background:#fff;}
.flash.ok{border-color:rgba(15,123,62,.25); background:rgba(15,123,62,.06); color:var(--ok)}
.flash.err{border-color:rgba(180,35,24,.22); background:rgba(180,35,24,.06); color:var(--err)}

table{border-collapse:separate; border-spacing:0; width:100%; overflow:hidden; border:1px solid var(--border); border-radius:14px; background:#fff;}
th,td{padding:10px 10px; font-size:13px; border-bottom:1px solid var(--border); text-align:left; vertical-align:top;}
th{color:var(--muted); font-weight:700; background:rgba(15,15,15,.02)}
tr:last-child td{border-bottom:none}

small{color:var(--muted)}
pre{margin:0; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size:13px;}

/* checkbox / details helpers */
input[type=checkbox]{
  width:auto;
  margin:0;
  padding:0;
  border-radius:6px;
}
.checkrow{display:flex; align-items:center; gap:8px; margin-top:8px;}
.checkrow label{margin:0; font-weight:600; color:var(--muted);}

summary{cursor:pointer; user-select:none; font-weight:700; color:var(--text);}
details{border:1px dashed rgba(15,15,15,.18); border-radius:14px; padding:12px; background:rgba(255,255,255,.6)}
details > summary{list-style:none;}
details > summary::-webkit-details-marker{display:none;}
.details-sub{margin-top:10px;}
</style>
CSS;

    echo "</head><body><div class='container'>";

    echo '<div class="topbar">';
    echo '<div class="brand">Domain Watch</div>';
    if ($showNav) {
        echo '<div class="nav">';
        if (auth_user()) {
            echo '<a href="/?a=projects">プロジェクト</a>';
            echo '<a href="/?newa=shots">ショット</a>';
            echo '<a href="/?a=logout">ログアウト</a>';
        } else {
            echo '<a href="/?a=login">ログイン</a>';
            echo '<a href="/?a=register">新規登録</a>';
        }
        echo '</div>';
    }
    echo '</div>';

    echo '<h1>' . h($title) . '</h1>';

    if ($flash) {
        $cls = $flash['type'] === 'ok' ? 'ok' : 'err';
        echo '<div class="flash ' . $cls . '">' . h($flash['msg']) . '</div>';
    }
}

function page_footer(): void {
    echo "</div></body></html>";
}
