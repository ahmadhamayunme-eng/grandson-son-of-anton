-- 0001_indexes.sql
-- Performance indexes for hot query paths (item #15).
-- Each statement is idempotent-by-tolerance: the runner logs & skips a
-- statement that fails (e.g. index already exists on engines without
-- CREATE INDEX IF NOT EXISTS support).

CREATE INDEX idx_ta_user ON task_assignees (user_id);
CREATE INDEX idx_al_ws_id ON activity_log (workspace_id, id);
CREATE INDEX idx_tasks_ws_due ON tasks (workspace_id, due_date);
CREATE INDEX idx_tasks_project ON tasks (project_id);
