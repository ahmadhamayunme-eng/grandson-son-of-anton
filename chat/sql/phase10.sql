-- =============================================================================
-- Anton Chat — Phase 10 schema additions (design migration).
--
-- Apply ONCE on the live DB before deploying the Phase 10 code. Each block
-- below is idempotent-safe except the two ALTER TABLE statements at the top
-- (they assume a fresh schema — if you've already added these columns, skip).
--
-- New columns
--   chat_messages.reply_to_message_id   inline reply-quote (Slack-style)
--   chat_user_prefs.dnd                 Do Not Disturb flag
--
-- New tables
--   chat_saved_messages                 per-user bookmarks
--   chat_pinned_messages                per-channel pins
--   chat_scheduled_messages             send-later queue (delivered by the
--                                       SSE worker on a future tick)
-- =============================================================================

-- ---- 1. Inline reply-quote -------------------------------------------------
ALTER TABLE chat_messages
  ADD COLUMN reply_to_message_id INT NULL AFTER parent_message_id,
  ADD INDEX idx_msg_reply_to (reply_to_message_id),
  ADD CONSTRAINT fk_msg_reply_to FOREIGN KEY (reply_to_message_id)
      REFERENCES chat_messages(id) ON DELETE SET NULL;

-- ---- 2. DND preference -----------------------------------------------------
ALTER TABLE chat_user_prefs
  ADD COLUMN dnd TINYINT(1) NOT NULL DEFAULT 0 AFTER enter_to_send;

-- ---- 3. Saved messages -----------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_saved_messages (
  user_id    INT NOT NULL,
  message_id INT NOT NULL,
  saved_at   DATETIME NOT NULL,
  PRIMARY KEY (user_id, message_id),
  INDEX idx_saved_user (user_id, saved_at),
  CONSTRAINT fk_saved_user FOREIGN KEY (user_id)    REFERENCES users(id)         ON DELETE CASCADE,
  CONSTRAINT fk_saved_msg  FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- 4. Pinned messages (per channel) --------------------------------------
CREATE TABLE IF NOT EXISTS chat_pinned_messages (
  channel_id INT NOT NULL,
  message_id INT NOT NULL,
  pinned_by  INT NOT NULL,
  pinned_at  DATETIME NOT NULL,
  PRIMARY KEY (channel_id, message_id),
  INDEX idx_pin_channel (channel_id, pinned_at),
  CONSTRAINT fk_pin_channel FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
  CONSTRAINT fk_pin_msg     FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_pin_user    FOREIGN KEY (pinned_by)  REFERENCES users(id)         ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- 5. Scheduled messages -------------------------------------------------
-- Delivered by the SSE worker on a future tick (any open chat tab sweeps the
-- queue, no OS cron required). One row per pending send.
CREATE TABLE IF NOT EXISTS chat_scheduled_messages (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id        INT NOT NULL,
  user_id             INT NOT NULL,
  channel_id          INT NULL,
  conversation_id     INT NULL,
  parent_message_id   INT NULL,
  reply_to_message_id INT NULL,
  content_html        TEXT NOT NULL,
  scheduled_for       DATETIME NOT NULL,
  created_at          DATETIME NOT NULL,
  sent_at             DATETIME NULL,
  sent_message_id     INT NULL,
  INDEX idx_sched_due  (scheduled_for, sent_at),
  INDEX idx_sched_user (user_id, scheduled_for),
  CONSTRAINT fk_sched_ws      FOREIGN KEY (workspace_id)        REFERENCES workspaces(id)                ON DELETE CASCADE,
  CONSTRAINT fk_sched_user    FOREIGN KEY (user_id)             REFERENCES users(id)                     ON DELETE CASCADE,
  CONSTRAINT fk_sched_channel FOREIGN KEY (channel_id)          REFERENCES chat_channels(id)             ON DELETE CASCADE,
  CONSTRAINT fk_sched_conv    FOREIGN KEY (conversation_id)     REFERENCES chat_direct_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_sched_parent  FOREIGN KEY (parent_message_id)   REFERENCES chat_messages(id)             ON DELETE CASCADE,
  CONSTRAINT fk_sched_reply   FOREIGN KEY (reply_to_message_id) REFERENCES chat_messages(id)             ON DELETE SET NULL,
  CONSTRAINT fk_sched_sent    FOREIGN KEY (sent_message_id)     REFERENCES chat_messages(id)             ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
