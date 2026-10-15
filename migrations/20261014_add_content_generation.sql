CREATE TABLE IF NOT EXISTS content_jobs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 owner_user_id BIGINT UNSIGNED NOT NULL,
 site_url VARCHAR(2048) NOT NULL DEFAULT '',
 service TEXT NOT NULL,
 audience TEXT NOT NULL,
 status ENUM('queued','running','completed','partial','failed') NOT NULL DEFAULT 'queued',
 stage VARCHAR(255) NOT NULL DEFAULT '受付済み',
 model VARCHAR(64) NOT NULL DEFAULT 'gpt-6.1-sol',
 reasoning_effort VARCHAR(16) NOT NULL DEFAULT 'xhigh',
 error_message TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 started_at DATETIME NULL,
 finished_at DATETIME NULL,
 KEY idx_content_owner (owner_user_id,id),
 KEY idx_content_queue (status,id),
 CONSTRAINT fk_content_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS content_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 job_id BIGINT UNSIGNED NOT NULL,
 position TINYINT UNSIGNED NOT NULL,
 keyword VARCHAR(255) NOT NULL,
 status ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
 evidence JSON NULL,
 result JSON NULL,
 error_message TEXT NULL,
 UNIQUE KEY uniq_content_position (job_id,position),
 CONSTRAINT fk_content_item_job FOREIGN KEY (job_id) REFERENCES content_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
