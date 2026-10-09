-- domain_watch schema (MySQL 8)

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  run_frequency ENUM('daily_3','daily_1','weekly_1') NOT NULL DEFAULT 'daily_1',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_projects_owner (owner_user_id),
  CONSTRAINT fk_projects_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- optional: members (owner以外のメンバーをプロジェクトに参加させる)
CREATE TABLE IF NOT EXISTS project_members (
  project_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('owner','member') NOT NULL DEFAULT 'member',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id, user_id),
  KEY idx_project_members_user (user_id),
  CONSTRAINT fk_project_members_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_domains (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id BIGINT UNSIGNED NOT NULL,
  type ENUM('main','sub') NOT NULL,
  domain VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_domains_project (project_id),
  KEY idx_domains_project_type (project_id, type),
  CONSTRAINT fk_project_domains_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_prompts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  content TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_prompts_project (project_id),
  CONSTRAINT fk_project_prompts_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id BIGINT UNSIGNED NOT NULL,
  prompt_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(16) NOT NULL DEFAULT 'openai',

  run_at DATETIME NOT NULL,
  day_key DATE NOT NULL,
  hour TINYINT UNSIGNED NOT NULL,

  -- snapshot of target texts at execution time (to keep metrics stable even if settings change later)
  target_main_text VARCHAR(255) NULL,
  target_sub1_text VARCHAR(255) NULL,
  target_sub2_text VARCHAR(255) NULL,
  target_sub3_text VARCHAR(255) NULL,
  target_sub_texts JSON NULL, -- legacy/compat

  hit_main TINYINT(1) NOT NULL DEFAULT 0,
  hit_sub1 TINYINT(1) NOT NULL DEFAULT 0,
  hit_sub2 TINYINT(1) NOT NULL DEFAULT 0,
  hit_sub3 TINYINT(1) NOT NULL DEFAULT 0,
  hit_sub JSON NULL, -- legacy/compat

  response_text LONGTEXT NULL,

  model VARCHAR(64) NOT NULL,
  tokens_in INT NULL,
  tokens_out INT NULL,
  latency_ms INT NULL,
  search_performed TINYINT(1) NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_runs_project_prompt_provider_time (project_id, prompt_id, provider, run_at),
  KEY idx_runs_project_day (project_id, day_key),
  KEY idx_runs_project_prompt_day (project_id, prompt_id, day_key),
  CONSTRAINT fk_runs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_runs_prompt FOREIGN KEY (prompt_id) REFERENCES project_prompts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1/day slot per (project,prompt,day): overwritten each run, so it always keeps the latest text for that day
CREATE TABLE IF NOT EXISTS daily_logs (
  project_id BIGINT UNSIGNED NOT NULL,
  prompt_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(16) NOT NULL DEFAULT 'openai',
  day_key DATE NOT NULL,

  run_at DATETIME NOT NULL,
  response_text LONGTEXT NOT NULL,
  search_suggestions LONGTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (project_id, prompt_id, provider, day_key),
  KEY idx_daily_logs_project_day (project_id, day_key),
  CONSTRAINT fk_daily_logs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_daily_logs_prompt FOREIGN KEY (prompt_id) REFERENCES project_prompts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
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
