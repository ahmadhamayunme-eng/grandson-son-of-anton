-- 0007_activity_log.sql
-- Some installs never had the base activity_log table (it lives in schema.sql
-- but wasn't applied on every DB). The notification feed (#10) and the Activity
-- page depend on it, and migration 0001's index for it was skipped when the
-- table was absent. Create it here (no FKs, safe on restrictive shared hosts)
-- and (re)add the composite index. Idempotent.

CREATE TABLE IF NOT EXISTS activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL,
  actor_user_id INT NULL,
  entity_type VARCHAR(60) NOT NULL,
  entity_id INT NULL,
  action VARCHAR(60) NOT NULL,
  message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_al_ws (workspace_id),
  INDEX idx_al_entity (entity_type, entity_id),
  INDEX idx_al_ws_id (workspace_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
