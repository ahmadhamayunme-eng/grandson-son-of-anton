-- 0005_notification_reads.sql
-- Per-user read marker for the in-app notification center (item #10).
-- Unread = activity_log rows in the user's workspace with id > last_read_id.

CREATE TABLE IF NOT EXISTS notification_reads (
  user_id INT NOT NULL PRIMARY KEY,
  last_read_id INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
