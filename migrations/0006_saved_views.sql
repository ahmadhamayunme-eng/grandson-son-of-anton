-- 0006_saved_views.sql
-- User-defined saved filters/views for list pages (item #13).

CREATE TABLE IF NOT EXISTS saved_views (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL,
  user_id INT NOT NULL,
  page VARCHAR(64) NOT NULL,
  name VARCHAR(120) NOT NULL,
  query_string VARCHAR(1000) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX idx_sv_lookup (workspace_id, user_id, page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
