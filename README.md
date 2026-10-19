# domain_watch (prototype)

プロジェクトごとに「メイン1 + サブ最大3」の対象テキストが、ChatGPT / Gemini APIの回答本文に出現するかを定期チェックして可視化する（PHP + MySQL）。

## 0) 前提
- Ubuntu
- PHP 8.3+
- MySQL 8.0+

このサーバでは `php` / `mysql` は導入済み。

## 1) セットアップ
### 1-1. DB作成
```bash
sudo mysql
```
```sql
CREATE DATABASE domain_watch CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'domain_watch'@'localhost' IDENTIFIED BY 'CHANGE_ME_PASSWORD';
GRANT ALL PRIVILEGES ON domain_watch.* TO 'domain_watch'@'localhost';
FLUSH PRIVILEGES;
```

### 1-2. スキーマ適用
```bash
mysql -u domain_watch -p domain_watch < schema.sql
```

### 1-3. 環境変数
```bash
cp .env.example .env
nano .env
```
Gemini APIを使う場合は、Google AI StudioでAPIキーを作成し、`.env` の `GEMINI_API_KEY` に設定します。

OpenAI側は `OPENAI_WEB_SEARCH=1` のときResponses APIのWeb Searchを必須で実行し、
回答本文の下に検索クエリ、参照URL、実際の引用URLを改行区切りで保存します。
検索地域は `OPENAI_WEB_SEARCH_COUNTRY` と `OPENAI_WEB_SEARCH_TIMEZONE` で指定します。

### 1-4. 初期ユーザ作成（管理者）
```bash
php bin/create_user.php admin@example.com 'password'
```

## 2) 起動（IP直打ち）
```bash
cd public
php -S 0.0.0.0:8000 index.php
```
ブラウザ: `http://<server-ip>:8000/`

## 3) cron（最大1日3回起動）
```bash
crontab -e
```
```cron
0 0,8,16 * * * cd /home/ubuntu/clawd/domain_watch && /usr/bin/php bin/runner.php >> storage/logs/runner.log 2>&1
```

## 4) 仕様メモ
- cronは0時 / 8時 / 16時（Asia/Tokyo）に起動し、各プロジェクトの実行頻度設定で対象を絞る
- 実行頻度は「1日3回」「1日1回」「1週間に1回」から選択でき、デフォルトは1日1回
- 1日1回は00:00、1週間に1回は毎週月曜00:00に実行
- daily_logs はプロンプト・サービスごとに1日1枠で、当日の最新 response_text に更新
- runs と daily_logs の provider は `openai` / `gemini`。実行回数・出現率・回答ログはサービス別に保存・表示する
- Geminiは `GEMINI_WEB_SEARCH=1`（未指定時も有効）でGoogle Search Groundingを利用する。検索はモデルが判断するため、検索なしの回答もあり、runs.search_performed に実行有無を保存する
- 出現判定は回答本文だけに適用する。検索クエリ・参照URL・引用URLは回答ログに追記する
- Geminiの検索候補が返った場合は、日次ログに保存し、回答表示と同じ画面のsandbox iframe内に表示する
- APIキー設定済みのサービスを同じcronで計測する。`RUNNER_PROVIDERS=openai,gemini` で指定できる
- UIのChatGPT / Gemini切り替えはページ全体に適用され、集計・詳細・最新RAW・CSVも切り替わる。計測前のデータは補完しない

## 既存DBのGemini対応

```bash
php bin/migrate_provider.php
```

既存行は `provider=openai` として維持する。runnerと移行処理は同じロックを利用する。
初回の限定計測（現在の時間枠、指定プロジェクト・プロンプトのみ）:

```bash
RUNNER_PROVIDERS=gemini RUNNER_IGNORE_SCHEDULE=1 RUNNER_PROJECT_ID=4 RUNNER_PROMPT_ID=123 php bin/runner.php
```

通常のcronは変更不要。失敗したサービスは記録を確定せず、再実行時に同じ枠を再試行できる。成功済みのサービスはスキップする。

## 5) Gemini API疎通確認
`.env` の `GEMINI_API_KEY` 設定後に実行:

```bash
php bin/test_gemini.php
```

## 6) 新UIプレビュー

- `/?newa` : アクセス可能なプロジェクト一覧
- `/?newa&id=4` : ChatGPT / Geminiを同時に表示するプロジェクト概要
- `/?newa=prompts&id=4` : 両サービスのプロンプト別比較
- `/?newa=prompt&id=4&pid=<prompt_id>` : LLM別の日次回答・検索情報・RAWコピー
- 概要の「競合との比較」で出現している競合の数値をクリックすると、`/?newa=competitor&id=<project_id>&target=<対象テキスト>&provider=<サービス>&start=<開始日>&end=<終了日>` でその競合が出現した日だけを確認できる。日付を選ぶと、その日に出現したプロンプトと実行時刻が並び、回答全文・検索情報・コピーを表示する。AIサービスや期間を変更しても対象の競合を引き継ぐ
- `/?newa=settings&id=4` : プロジェクト、対象テキスト、プロンプトの設定

期間は `start` / `end`（最大366日）、1日の指定は `date`。
出現率は計測回数を母数に算出し、同じ日数の直前期間と比較する。
データのないサービス・日付は0%で補完しない。
競合は保存された対象テキストのスナップショットに基づいて集計する。
回答本文は既存のdaily_logsにある1日ごとの最終回答を表示する。
競合詳細は計測当時の対象テキストと出現判定を使うため、競合の並び順や設定変更によって別の競合の結果が混ざらない。出現率の母数は指定期間にその競合を計測した全回答。履歴一覧には出現した日だけを表示し、各日の回答を実行単位で選べる。回答は該当する `runs.response_text` を優先し、未保存なら実行時刻が一致する日次ログだけを使う。過去に本文が保存されず日次ログも後の回答で上書きされている場合は、出現時点の本文が未保存と表示する。今後の定期計測では、競合が出現した回の回答・検索情報を `runs.response_text` に残し、後の回答で上書きされないようにする。
設定の保存先・認証・計測データは既存UIと共通。プレビューで保存した設定は実際の計測に反映される。
既存の `?a=...` ページは引き続き利用できる。

競合詳細の検証（実際のAI APIを呼ばず、HTTPテストは計測停止の専用プロジェクトを作成して終了時に削除する）:

```bash
php bin/test_new_ui_competitor.php
php bin/test_new_ui_competitor_http.php http://127.0.0.1:8000
```

## 7) 単発調査「ショット」

- `/?newa=shots`：自分が作成したショットの一覧。過去の結果・状態・進捗を保存する
- `/?newa=shot_new`：名前、自社、計測したい文字列（最大5件）、プロンプト（テキストエリアに1行1件、空行を除いて最大50件）を入力
- `/?newa=shot&id=<shot_id>`：サービス別の自社・計測したい文字列の出現率、プロンプトごとの回答・検索情報・コピー

既存DBには次を適用する（新規DBは `schema.sql` に含まれる）:

```bash
php bin/migrate_shots.php
```

受付時に全プロンプト・設定済みサービスの組み合わせをDBに保存し、画面は一覧に戻る。
APIリクエストは専用CLIワーカーで実行するため、Web側のPHPタイムアウトを待つ必要はない。
新規受付時にワーカーを起動する。起動できない環境や処理中断に備えて、次のcronも必ず設定する:

```cron
* * * * * cd /home/ubuntu/clawd/domain_watch && /usr/bin/php bin/shot_runner.php >> storage/logs/shots.log 2>&1
```

`storage/` と `storage/logs/` はWeb・cronの実行ユーザから書き込み可能にする。
PHP CLIが `/usr/bin/php` 以外の場合は `.env` に `SHOT_PHP_BINARY` を設定する。
既存プロジェクトの定期実行cronは変更不要。ショットはプロジェクトとは独立して保存し、定期再調査しない。
ワーカーは専用ロックで多重起動を防止し、各回答を随時保存する。途中で失敗しても残りを処理し、成功した回答を母数に出現率を集計する。
処理中断時は未処理分だけを再開する。中断したAPI呼び出しは失敗として表示し、同じ有料リクエストは自動で再送しない。
一覧・結果の「状態を更新」で最新状況を確認できる。
結果画面の「結果を一括DL（Excel）」で、サマリーと各プロンプトのシートを含む `.xlsx` を取得できる。シート名は「プロンプト1」等とし、シート上部に全文を記載する。回答・検索情報・各文字列の出現判定・失敗状況も含める。出現率は数値（比率）とパーセント表示形式で保存し、Excelで計算に利用できる。入出力トークン・応答時間・検索候補HTMLは含めない。長い内容はExcelのセル上限を超えないよう複数行に分割する。

「AnswerIO形式でDL」では、回答・言及・引用情報を次の26列・列順で出力する。1プロンプト・サービスにつき1行、日本語をExcelで開けるUTF-8 BOM付きCSV。回答本文の改行・引用符・長文はCSVの引用で保持する。未取得・未定義の項目を推測や `0` / `FALSE` で補完しない。

| 列 | 出力内容 |
| --- | --- |
| レポート実行ID | `shot-{ショットID}`。ダウンロードごとに変更しない |
| 実行日時 | 保存された回答の処理完了日時、`YYYY-MM-DDTHH:mm:ss+09:00`（DBのJST、秒精度）。完了日時がなければ空欄 |
| ファネル段階 | 空欄 |
| クエリID | `shot-{ショットID}-prompt-{プロンプト番号}`。同じプロンプトはサービス間で共通 |
| クエリテキスト | プロンプト全文 |
| クエリタイプ | 空欄 |
| AIモデル | 実際に利用したサービス `openai` / `gemini` |
| モデルバージョン | 保存されたモデル名。未保存なら空欄 |
| 回答テキスト | 成功した回答本文。末尾の検索情報は分離 |
| 可視性スコア | 空欄 |
| プレゼンススコア | 空欄 |
| ポジションスコア | 空欄 |
| 引用スコア | 空欄 |
| ブランド順位 | 空欄 |
| 言及有無 | 保存された自社の出現判定 `TRUE` / `FALSE`。未処理・失敗・未保存は空欄 |
| 言及回数 | 自社文字列の回答本文での一致数（大文字小文字を区別せず、重ならない一致を数える）。検索情報は除外。本文未取得は空欄 |
| 言及箇所 | 自社文字列を含む行、`行N：本文`。複数行は改行で区切る |
| 抽出ブランド一覧 | 空欄。未登録ブランドの自動抽出は行わない |
| 競合ブランド一覧 | 登録された比較対象文字列（最大5件）を `, ` で区切る |
| 抽出された特徴 | 空欄 |
| 引用サイト数 | 保存された「回答で実際に引用したURL」の重複を除いた件数。ドメイン単位の件数ではない。引用情報未取得は空欄、取得済みで引用なしなら `0` |
| 引用サイトURL一覧 | 上記URLを保存順のまま `, ` で区切る。参照のみのURLは含めない |
| 自社サイト引用有無 | 先頭の「自社」欄がURL・ドメインの場合、引用URLのホストを比較して `TRUE` / `FALSE`。`www.` は同一視し、配下のサブドメインも対象。会社名のみ・引用情報未取得・未解決のGoogle中継URLしか判定材料がない場合は空欄 |
| 自社サイト引用順位 | 上記引用URL一覧で自社サイトが最初に現れる位置（1始まり）。未解決URLがその前にある場合や順位不明・引用なしは空欄 |
| 実行ステータス | `成功` / `失敗` / `実行中` / `未実行` |
| エラー内容 | 保存されたエラーメッセージ |

検証（実際のAI APIを呼ばない）:

```bash
php bin/test_shots.php
php bin/test_shots_export.php
php bin/test_shots_csv.php
php bin/test_shots_http.php http://127.0.0.1:8000
```

単体検証は接続内の一時テーブルを利用する。HTTP検証はテスト専用ユーザと完了済みショットを作り、終了時に削除する。

## 8) コンテンツ構成案の作成

`/content-generation` に直接アクセスする。ログイン必須で、ナビゲーションには追加しない。
キーワードは改行区切りで1〜10件。URL・対象サービス・対象読者はすべて任意で、登録内の全キーワードに適用する。
AIへの質問文も任意で改行区切り1〜3件（手入力は1件1,000文字以内）。手入力した質問はタスク内の全キーワードで共通して使用し、追加の自動補完はしない。空欄なら、キーワードごとに対象サービス・読者・参考メモを踏まえてサーバーのCodex `gpt-6.1-sol` / `xhigh` が異なる観点の短い質問を3件推定する（15〜35文字程度、最大50文字、1件につき疑問1つ）。既存DBにも `php bin/migrate_content_generation.php` を実行して質問文の保存列を追加する。
参考メモも任意（改行可・最大10,000文字）。記事の方向性・重視/除外する論点・既存記事との重複回避を構成作成時に考慮し、メモ原文はDBに保持し、Excelのメモ・コメント機能には残さない。基本は登録内の全キーワードに適用し、メモ内でキーワードを指定した方針は該当キーワードに適用する。既存DBへのメモ欄追加も `php bin/migrate_content_generation.php` で適用する。
未指定のURL・サービス・読者は公開情報・キーワードから推定し、Excelに推定根拠を記載する。URLを特定できない場合は未特定として扱う。
一覧は新しい順に最新100件まで表示し、右ペイン内でスクロールできる。一覧とダウンロードは登録ユーザ本人のみアクセス可能。

セットアップ（更新時もマイグレーションを実行）:

```bash
php bin/migrate_content_generation.php
python3 -m venv storage/content-venv
storage/content-venv/bin/pip install -r resources/content-generation/requirements.txt
```

cron実行ユーザはCodexにログイン済みであること。Webとcronの実行ユーザが異なる場合は `storage/content-generation/` とロックの読み書き権限を調整する。
毎分起動し、1回につき1タスクを処理する。専用ロックで同時実行を防止する。

```cron
* * * * * cd /home/ubuntu/clawd/domain_watch && /usr/bin/php bin/content_generation_runner.php >> storage/logs/content-generation.log 2>&1
```

各キーワードの質問文ごとにショットと同じ `openai_chat` / `gemini_chat` を呼び、回答・実際の検索クエリ・参照URL・引用URLを保存する。3質問なら各AIに3回ずつ、計6回呼び出す。確定した質問文と手入力／推定の区別・推定モデルは `content_items.ai_prompts` にAPI送信前に保存する。`evidence` にはprovider別の集約情報と、質問文と回答を対応づけた `responses` を保存する。
ChatGPT/Geminiの引用URL・参照URLを根拠候補として閲覧・検証し、各見出しの主張と対応づける。確認済みの根拠は `sections.source_attributions` に情報源名・URL・裏付ける内容・本文で出典名を示す執筆指示・候補URLの取得元を保存する。Excelでは「LLMOのため出典を明示（資料名）」と簡潔に表示し、URLは参照URL欄に掲載する。根拠の詳細は内部JSONに保持する。本文にURLを記載する指示は不要。引用と参照を区別し、AIが引用しただけでは採用せず、可能なら原典にたどる。URL一覧からAI回答の文との対応を断定しない。未確認の主張・クライアント実績は確認事項として残す。根拠が不要または未確認の節は空配列とし、従来の生成結果も再出力可能。
その資料をCodex CLIの `gpt-6.1-sol` / `xhigh` に渡し、Web検索・競合調査・検索意図整理・構成案・LLMO調整を実施する。
Codexはユーザ設定を読み込まず、シェルを無効にしてWeb調査と構造化JSON出力に限定する。DB/API認証情報を子プロセスへ渡さない。
モデル・推論設定はタスクに保存する。`CONTENT_CODEX_BINARY` と `CONTENT_PYTHON_BINARY` で実行ファイルのみ変更できる。
Codexの実行方法は [OpenAIの公式例](https://developers.openai.com/cookbook/examples/codex/build_iterative_repair_loops_with_codex) を参照。

出力は指定の [構成案サンプル.xlsx](https://docs.google.com/spreadsheets/d/1b8g2Zvq8ZnqGxlkWcz2uBW6SgsN_sSRc/edit) の「テンプレ」シートを複製し、1キーワードにつきテーマ名（キーワード）の構成案シートを1枚だけ作成する（5本なら5シート）。概要・調査/LLMOの別シートは作成しない。Excelの31文字制限・禁止文字・重複に合わせてシート名を整える。ウィンドウ固定は行わない。
サンプルの列幅・行高・フォント・罫線・表示形式を使用する。10行目は7行目と同じ空行、11行目はリード文見出し、12・13行目はリード文の執筆指示、15行目から構成を開始する。概要は1行1文、参照URLは見出し行にラベル、その下に1行1URLで記載する。内容量に応じてB〜F列の構成行を可変にし、最終行の下罫線をB〜F列まで閉じる。元のサンプル・隠しマスターの内容は出力しない。
ペルソナ直下の9行目に「想定プロンプト」を表示する。各キーワードで実際に使用した手入力／自動推定の質問文を、ペルソナ欄と同じ書式で1件1行のセル内改行で掲載する。保存データがない過去の結果は推測で補わず「未取得」と表示する。
競合記事は10件を目標に調査し、不足時は理由を記録する。上部の関連キーワードは実際に取得したGoogle検索サジェストから2〜3件を選定し、候補と取得状況を `keyword_suggestions` に保持する。サジェストの取得失敗は空欄を創作で埋めず「未取得」と表示する。調査用のsite:付き検索文やURLを関連キーワードに混ぜない。検索回数・CPCは0。競合の掲載番号は1〜10で見出し欄と対応させる（実測のGoogle順位ではない）。文字数は取得HTMLから記事本文を抽出し、空白を除いて実測する。取得不能時だけ0とし理由は内部の計測JSONに保存する。未取得の枠は「未取得」と明記し、測定不能な値の0とは区別する。
競合見出し欄の「順位」は対応する掲載番号を示す「No.」とし、その他のテンプレートの見出し名・グリッド線非表示・90%表示を維持し、C4のSUM数式と初期表示用の計算値を保存する。項目確認結果は各出力に対応する `content.coverage.json` に残す。クライアント記入欄・余白は意図的に空欄にする。
右側の関連キーワード表は取得した検索サジェストを最大50件まで表示し、API検索クエリは分析用JSONに保持する。AI回答・調査詳細はDBと中間JSONに保存する。Excelにはメモ・コメントを付けない。任意の取材・追加確認は「（可能なら）」、表作成は「（表の作成）」を文頭に付けて赤字表示する。概要は具体的で簡潔な肯定文にし、LLMOの採用理由（sections.llmo_reasons）は「※LLMO用：」を付けて概要の末尾に1文1行で記載する。URLセルは横位置「繰り返し」、構成案末尾以降の不要な書式を削除する。ダウンロード名は登録した最初のキーワードと登録日を使い「キーワード_YYYYMMDD.xlsx」とする。

待機中・作成中・完了・一部失敗・失敗を表示する。15秒おきに更新を検出し、入力途中のフォームを再読み込みで消さない。
ChatGPT/Geminiのいずれかの質問で回答を取得できないキーワードは失敗扱いとし、ほかの質問・キーワードは継続する。成功した構成があれば一部失敗でもExcelを取得できる。
中断時は実行中だったキーワードを失敗として記録し、未処理のキーワードのみ再開する。有料APIを同じキーワードに自動再送しない。
Codexの質問文推定は1キーワード10分、構成作成は1キーワード40分、Excel出力は2分でタイムアウトする。失敗時は再登録で再実行する。
ログ・中間データ・Excelは公開ディレクトリ外の `storage/content-generation/{id}/` に保存し、Excelは完成後に原子的に公開する。

検証（外部AI呼び出しなし・専用ユーザとデータは終了時削除）:

```bash
php bin/test_content_generation.php http://127.0.0.1:8000
php bin/test_content_ai_prompts.php
storage/content-venv/bin/python bin/test_content_export.py
```
