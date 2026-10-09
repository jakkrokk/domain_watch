ALTER TABLE projects
  ADD COLUMN run_frequency ENUM('daily_3','daily_1','weekly_1')
  NOT NULL DEFAULT 'daily_1'
  AFTER is_active;
