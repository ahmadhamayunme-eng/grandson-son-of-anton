CREATE TABLE IF NOT EXISTS task_attachment_staging (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL,
  uploaded_by INT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NULL,
  size_bytes BIGINT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_tas_owner (workspace_id, uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 0009_task_attachment_staging.sql
-- Attach-on-create staging table for the "New task" popups. Files are uploaded
-- here (no task_id, no foreign keys) before the task exists, then copied into
-- task_attachments with the real task id on creation and deleted from here.
-- Plain CREATE TABLE only — no ALTER — so it works on shared hosts that grant
-- CREATE but not ALTER. Supersedes the withdrawn 0008 (which relied on ALTER).
