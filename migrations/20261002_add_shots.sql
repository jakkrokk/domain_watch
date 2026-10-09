CREATE TABLE IF NOT EXISTS shots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  target_main_text VARCHAR(255) NOT NULL,
  target_sub_texts JSON NOT NULL,
  status ENUM('queued','running','completed','partial','failed') NOT NULL DEFAULT 'queued',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  KEY idx_shots_owner (owner_user_id,id),
  KEY idx_shots_queue (status,id),
  CONSTRAINT fk_shots_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shot_results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shot_id BIGINT UNSIGNED NOT NULL,
  prompt_index TINYINT UNSIGNED NOT NULL,
  prompt_content TEXT NOT NULL,
  provider VARCHAR(16) NOT NULL,
  status ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
  hit_main TINYINT(1) NULL,
  hit_sub JSON NULL,
  response_text LONGTEXT NULL,
  search_suggestions LONGTEXT NULL,
  model VARCHAR(64) NULL,
  tokens_in INT NULL,
  tokens_out INT NULL,
  latency_ms INT NULL,
  search_performed TINYINT(1) NULL,
  error_message TEXT NULL,
  finished_at DATETIME NULL,
  UNIQUE KEY uniq_shot_prompt_provider (shot_id,prompt_index,provider),
  CONSTRAINT fk_shot_results_shot FOREIGN KEY (shot_id) REFERENCES shots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
