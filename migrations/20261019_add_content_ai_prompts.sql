ALTER TABLE content_jobs ADD COLUMN ai_prompts TEXT NULL AFTER notes;
ALTER TABLE content_items ADD COLUMN ai_prompts JSON NULL AFTER keyword;
