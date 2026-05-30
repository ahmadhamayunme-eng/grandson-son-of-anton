-- 0003_task_attachments.sql
-- Authoritative version of ensure_task_attachments_table() (item #16).
-- No-FK variant: safe on restrictive shared hosts. The lib helper remains
-- as a fallback for installs that haven't run migrations yet.

CREATE TABLE IF NOT EXISTS task_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL,
  task_id INT NOT NULL,
  uploaded_by INT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NULL,
  size_bytes BIGINT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_ta_ws (workspace_id),
  INDEX idx_ta_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
