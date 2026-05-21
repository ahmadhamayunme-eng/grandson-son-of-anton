-- ============================================================================
-- Anton Jr. — Phase 11 unification schema.
--
-- Run ONCE on the live DB before deploying Phase 11. Idempotent: re-running
-- is safe (column-add wrapped in conditional, CREATE TABLE IF NOT EXISTS).
-- ============================================================================

-- 1. Mark a per-workspace system user "Anton Jr." used as the author of
--    every automated DM / channel post. Add a column to flag them; the
--    backfill statement below creates one Anton Jr. per workspace if
--    none exists yet.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_system');
SET @sql := IF(@col = 0, 'ALTER TABLE users ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: one Anton Jr. per workspace. Email is a synthetic local
-- address so the UNIQUE (workspace_id, email) constraint stays happy.
-- We pick a role that exists; "Super Admin" is the most-permissioned default.
INSERT INTO users (workspace_id, role_id, name, email, password_hash, is_active, is_system, created_at, updated_at)
SELECT w.id,
       (SELECT id FROM roles WHERE name = 'Super Admin' LIMIT 1),
       'Anton Jr.',
       CONCAT('anton-jr+', w.id, '@anton.local'),
       '!!ANTON_JR_NO_LOGIN!!',  -- not a valid bcrypt hash; login always fails
       1, 1, NOW(), NOW()
FROM workspaces w
WHERE NOT EXISTS (
  SELECT 1 FROM users u WHERE u.workspace_id = w.id AND u.is_system = 1
);

-- Rename any pre-existing "Anton Connect" rows from an earlier draft of this
-- migration. Idempotent — second run is a no-op.
UPDATE users SET name = 'Anton Jr.' WHERE is_system = 1 AND name = 'Anton Connect';

-- 2. Per-workspace automation config (which channel hosts standup, etc.).
CREATE TABLE IF NOT EXISTS chat_automations (
  workspace_id           INT NOT NULL PRIMARY KEY,
  standup_enabled        TINYINT(1) NOT NULL DEFAULT 0,
  standup_channel_id     INT NULL,
  standup_at             VARCHAR(8) NOT NULL DEFAULT '21:00',   -- HH:MM workspace local
  standup_cutoff_at      VARCHAR(8) NOT NULL DEFAULT '22:30',
  standup_last_sent_date DATE NULL,                              -- last yyyy-mm-dd we sent
  standup_last_cutoff_date DATE NULL,
  submissions_channel_id INT NULL,
  created_at             DATETIME NOT NULL,
  updated_at             DATETIME NOT NULL,
  CONSTRAINT fk_auto_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_auto_standup_ch FOREIGN KEY (standup_channel_id) REFERENCES chat_channels(id) ON DELETE SET NULL,
  CONSTRAINT fk_auto_submit_ch  FOREIGN KEY (submissions_channel_id) REFERENCES chat_channels(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Message ↔ AntonX entity bridge. Used by:
--    - "Convert message to task" so we can re-find the anchor message
--      when the AntonX task gets commented / status-changed
--    - /share task#1234, /share project foo, etc. to re-render live cards
CREATE TABLE IF NOT EXISTS chat_message_links (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL,
  message_id   INT NOT NULL,
  entity_type  ENUM('task','project','client','doc') NOT NULL,
  entity_id    INT NOT NULL,
  created_at   DATETIME NOT NULL,
  INDEX idx_mlink_msg (message_id),
  INDEX idx_mlink_entity (entity_type, entity_id),
  CONSTRAINT fk_mlink_ws  FOREIGN KEY (workspace_id) REFERENCES workspaces(id)    ON DELETE CASCADE,
  CONSTRAINT fk_mlink_msg FOREIGN KEY (message_id)   REFERENCES chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Inline polls (created by /poll). Reactions become votes.
CREATE TABLE IF NOT EXISTS chat_polls (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  message_id   INT NOT NULL UNIQUE,
  workspace_id INT NOT NULL,
  question     VARCHAR(220) NOT NULL,
  options_json TEXT NOT NULL,                        -- JSON array of option labels
  multi        TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL,
  CONSTRAINT fk_poll_msg FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_poll_ws  FOREIGN KEY (workspace_id) REFERENCES workspaces(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_poll_votes (
  poll_id    INT NOT NULL,
  user_id    INT NOT NULL,
  option_idx INT NOT NULL,
  voted_at   DATETIME NOT NULL,
  PRIMARY KEY (poll_id, user_id, option_idx),
  CONSTRAINT fk_pv_poll FOREIGN KEY (poll_id) REFERENCES chat_polls(id) ON DELETE CASCADE,
  CONSTRAINT fk_pv_user FOREIGN KEY (user_id) REFERENCES users(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Optional per-message card payload for entity preview cards (/share *).
--    Stored as JSON; rendered server-side at message render time. Empty for
--    plain messages.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'entity_card_json');
SET @sql := IF(@col = 0, 'ALTER TABLE chat_messages ADD COLUMN entity_card_json TEXT NULL AFTER content', 'SELECT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Standup question/answer pairs the user submitted today (for collation).
CREATE TABLE IF NOT EXISTS chat_standup_replies (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id    INT NOT NULL,
  user_id         INT NOT NULL,
  for_date        DATE NOT NULL,
  question        VARCHAR(120) NOT NULL,
  answer_html     TEXT NOT NULL,
  reply_message_id INT NULL,
  created_at      DATETIME NOT NULL,
  UNIQUE KEY uniq_standup (workspace_id, user_id, for_date, question),
  INDEX idx_standup_date (workspace_id, for_date),
  CONSTRAINT fk_sr_ws   FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_sr_user FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE CASCADE,
  CONSTRAINT fk_sr_msg  FOREIGN KEY (reply_message_id) REFERENCES chat_messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Mark which AntonX comments came from chat (so the mirror doesn't echo).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'source_kind');
SET @sql := IF(@col = 0, "ALTER TABLE comments ADD COLUMN source_kind ENUM('antonx','chat') NOT NULL DEFAULT 'antonx'", 'SELECT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'chat_message_id');
SET @sql := IF(@col = 0, 'ALTER TABLE comments ADD COLUMN chat_message_id INT NULL', 'SELECT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8. Due-soon reminder log so the SSE-tick doesn't re-DM the same person
--    multiple times for the same task.
CREATE TABLE IF NOT EXISTS chat_due_reminders (
  task_id INT NOT NULL,
  user_id INT NOT NULL,
  sent_at DATETIME NOT NULL,
  PRIMARY KEY (task_id, user_id),
  CONSTRAINT fk_dr_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_dr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
