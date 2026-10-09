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
- `/?newa=settings&id=4` : プロジェクト、対象テキスト、プロンプトの設定

期間は `start` / `end`（最大366日）、1日の指定は `date`。
出現率は計測回数を母数に算出し、同じ日数の直前期間と比較する。
データのないサービス・日付は0%で補完しない。
競合は保存された対象テキストのスナップショットに基づいて集計する。
回答本文は既存のdaily_logsにある1日ごとの最終回答を表示する。
設定の保存先・認証・計測データは既存UIと共通。プレビューで保存した設定は実際の計測に反映される。
既存の `?a=...` ページは引き続き利用できる。

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

「AnswerIO形式でDL」ではanswerIOのRAWデータと同じ列順（`project_id, project_name, prompt_id, prompt_name, provider, date, exec_count, main_text, main_rate_pct, sub1_text, sub1_rate_pct, sub2_text, sub2_rate_pct, sub3_text, sub3_rate_pct`）で出現率を出力する。ショットはプロジェクトと独立しているため、`project_id` / `project_name` はショットID / ショット名、`prompt_id` / `prompt_name` はショット内のプロンプト番号 / プロンプト全文に対応する。1プロンプト・サービスにつき1行で、`provider` は `openai` / `gemini`、`date` は保存された処理完了日時の日付（未処理なら受付日、`YYYY-MM-DD`）。成功時は `exec_count=1`、出現率は出現あり `100` / なし `0`。未処理・失敗時は `exec_count=0`、出現率は空欄とする。未設定の対象も空欄とし、調査のない日付は補完しない。計測したい文字列が4〜5件ある場合は `sub4_text, sub4_rate_pct` / `sub5_text, sub5_rate_pct` を末尾に追加する。日本語をExcelで開けるようUTF-8 BOM付きで出力し、回答本文はExcelのプロンプト別シートから取得できる。

検証（実際のAI APIを呼ばない）:

```bash
php bin/test_shots.php
php bin/test_shots_export.php
php bin/test_shots_csv.php
php bin/test_shots_http.php http://127.0.0.1:8000
```

単体検証は接続内の一時テーブルを利用する。HTTP検証はテスト専用ユーザと完了済みショットを作り、終了時に削除する。
