ALTER TABLE content_jobs ADD COLUMN mode ENUM('create','rewrite') NOT NULL DEFAULT 'create' AFTER ai_prompts;
ALTER TABLE content_jobs ADD COLUMN reference_url VARCHAR(2048) NOT NULL DEFAULT '' AFTER mode;
