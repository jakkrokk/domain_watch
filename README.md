# domain_watch (prototype)

プロジェクトごとに「メイン1 + サブ最大3」ドメインが、ChatGPT返答内に出現するかを1日3回チェックして可視化する（PHP + MySQL）。

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

## 3) cron（1日3回実行）
```bash
crontab -e
```
```cron
0 0,8,16 * * * cd /home/ubuntu/clawd/domain_watch && /usr/bin/php bin/runner.php >> storage/logs/runner.log 2>&1
```

## 4) 仕様メモ
- 0時 / 8時 / 16時（Asia/Tokyo）: 全プロジェクト × 全アクティブプロンプト を実行
- daily_logs は1日1枠で、当日の最新 response_text に更新

## 5) Gemini API疎通確認
`.env` の `GEMINI_API_KEY` 設定後に実行:

```bash
php bin/test_gemini.php
```
