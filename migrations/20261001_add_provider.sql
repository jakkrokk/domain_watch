-- Existing rows remain OpenAI measurements.
ALTER TABLE runs
  ADD COLUMN provider VARCHAR(16) NOT NULL DEFAULT 'openai' AFTER prompt_id,
  ADD COLUMN search_performed TINYINT(1) NULL AFTER latency_ms,
  DROP INDEX uniq_runs_project_prompt_time,
  ADD UNIQUE KEY uniq_runs_project_prompt_provider_time (project_id,prompt_id,provider,run_at);

ALTER TABLE daily_logs
  ADD COLUMN provider VARCHAR(16) NOT NULL DEFAULT 'openai' AFTER prompt_id,
  ADD COLUMN search_suggestions LONGTEXT NULL AFTER response_text,
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (project_id,prompt_id,provider,day_key);
