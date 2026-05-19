-- =============================================================================
-- Anton Chat — schema additions to the AntonX database.
--
-- ROLE MODEL
--   Chat reuses the existing AntonX RBAC. There is no chat-specific role column
--   on the users table. A chat admin is any user whose AntonX role_name is in
--   {'Super Admin', 'CEO', 'Manager'} — the same gate used by partials/nav.php.
--   See chat_is_admin() in chat/includes/helpers.php.
--
-- WORKSPACE SCOPING
--   Every chat_* table that owns its own entity carries workspace_id, matching
--   the rest of AntonX. Today there is one workspace; this stays consistent if
--   that ever changes.
--
-- DELETES
--   Messages are soft-deleted (deleted_at IS NOT NULL). Hard delete only
--   happens via CASCADE when the parent channel or conversation is deleted.
--
-- TABLE CREATION ORDER
--   Tables are ordered so every FK target exists at create time. lib/db.php
--   runs statements one at a time and won't accept forward references.
-- =============================================================================

-- ---- CHANNELS -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_channels (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id    INT NOT NULL,
  slug            VARCHAR(80)  NOT NULL,        -- "general", "random" — lowercase, Slack-style
  topic           VARCHAR(255) NULL,            -- one-liner in the channel header
  description     TEXT NULL,                    -- longer purpose
  is_private      TINYINT(1) NOT NULL DEFAULT 0,
  created_by      INT NOT NULL,
  created_at      DATETIME NOT NULL,
  archived_at     DATETIME NULL,                -- soft-archive
  UNIQUE KEY uniq_ws_slug (workspace_id, slug),
  INDEX idx_ch_ws (workspace_id),
  CONSTRAINT fk_ch_ws   FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_ch_user FOREIGN KEY (created_by)   REFERENCES users(id)      ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- DIRECT CONVERSATIONS (1-on-1 and group, up to ~8 people) -------------
CREATE TABLE IF NOT EXISTS chat_direct_conversations (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL,
  is_group     TINYINT(1) NOT NULL DEFAULT 0,
  dm_key       VARCHAR(40) NULL,                -- "minUid:maxUid" for 1-on-1; NULL for groups
  created_by   INT NOT NULL,
  created_at   DATETIME NOT NULL,
  UNIQUE KEY uniq_ws_dmkey (workspace_id, dm_key),  -- NULLs aren't unique-checked, so groups are fine
  INDEX idx_dc_ws (workspace_id),
  CONSTRAINT fk_dc_ws   FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_dc_user FOREIGN KEY (created_by)   REFERENCES users(id)      ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- MESSAGES (channel + DM, with thread support) -------------------------
-- All ordering / pagination / unread queries use id. AUTO_INCREMENT is
-- monotonic with insertion time, so id ordering == created_at ordering.
CREATE TABLE IF NOT EXISTS chat_messages (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id      INT NOT NULL,
  channel_id        INT NULL,                   -- exactly one of channel_id / conversation_id is set
  conversation_id   INT NULL,
  parent_message_id INT NULL,                   -- NULL = top-level; set = thread reply
  user_id           INT NOT NULL,               -- author
  content           TEXT NOT NULL,              -- raw markdown source
  created_at        DATETIME NOT NULL,
  edited_at         DATETIME NULL,
  deleted_at        DATETIME NULL,              -- soft delete
  INDEX idx_msg_channel_id  (channel_id, id),
  INDEX idx_msg_conv_id     (conversation_id, id),
  INDEX idx_msg_parent_id   (parent_message_id, id),
  INDEX idx_msg_ws_user_id  (workspace_id, user_id, id),
  FULLTEXT KEY ft_content (content),
  CONSTRAINT fk_msg_ws      FOREIGN KEY (workspace_id)      REFERENCES workspaces(id)                ON DELETE CASCADE,
  CONSTRAINT fk_msg_channel FOREIGN KEY (channel_id)        REFERENCES chat_channels(id)             ON DELETE CASCADE,
  CONSTRAINT fk_msg_conv    FOREIGN KEY (conversation_id)   REFERENCES chat_direct_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_parent  FOREIGN KEY (parent_message_id) REFERENCES chat_messages(id)             ON DELETE CASCADE,
  CONSTRAINT fk_msg_user    FOREIGN KEY (user_id)           REFERENCES users(id)                     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- CHANNEL MEMBERSHIP (depends on chat_channels + chat_messages) --------
CREATE TABLE IF NOT EXISTS chat_channel_members (
  channel_id           INT NOT NULL,
  user_id              INT NOT NULL,
  joined_at            DATETIME NOT NULL,
  last_read_message_id INT NULL,                          -- NULL = nothing read yet (all unread)
  notification_pref    ENUM('all','mentions','none') NOT NULL DEFAULT 'all',
  PRIMARY KEY (channel_id, user_id),
  INDEX idx_chm_user (user_id),
  CONSTRAINT fk_chm_channel  FOREIGN KEY (channel_id)           REFERENCES chat_channels(id) ON DELETE CASCADE,
  CONSTRAINT fk_chm_user     FOREIGN KEY (user_id)              REFERENCES users(id)         ON DELETE CASCADE,
  CONSTRAINT fk_chm_lastread FOREIGN KEY (last_read_message_id) REFERENCES chat_messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- DM MEMBERSHIP (depends on chat_direct_conversations + chat_messages) -
CREATE TABLE IF NOT EXISTS chat_direct_conversation_members (
  conversation_id      INT NOT NULL,
  user_id              INT NOT NULL,
  joined_at            DATETIME NOT NULL,
  last_read_message_id INT NULL,
  PRIMARY KEY (conversation_id, user_id),
  INDEX idx_dcm_user (user_id),
  CONSTRAINT fk_dcm_conv     FOREIGN KEY (conversation_id)      REFERENCES chat_direct_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_dcm_user     FOREIGN KEY (user_id)              REFERENCES users(id)                     ON DELETE CASCADE,
  CONSTRAINT fk_dcm_lastread FOREIGN KEY (last_read_message_id) REFERENCES chat_messages(id)             ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- REACTIONS ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_reactions (
  message_id INT NOT NULL,
  user_id    INT NOT NULL,
  emoji      VARCHAR(64) NOT NULL,              -- shortcode like ":thumbsup:" from the emoji picker
  created_at DATETIME NOT NULL,
  PRIMARY KEY (message_id, user_id, emoji),     -- one of each emoji per user per message
  INDEX idx_rx_msg (message_id),
  CONSTRAINT fk_rx_msg  FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_rx_user FOREIGN KEY (user_id)    REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- MENTIONS (one row per @-target found in a message) -------------------
CREATE TABLE IF NOT EXISTS chat_mentions (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  message_id         INT NOT NULL,
  mention_type       ENUM('user','channel','here','everyone') NOT NULL,
  mentioned_user_id  INT NULL,                  -- only set when mention_type='user'
  created_at         DATETIME NOT NULL,
  INDEX idx_mn_user (mentioned_user_id, message_id),
  INDEX idx_mn_msg  (message_id),
  CONSTRAINT fk_mn_msg  FOREIGN KEY (message_id)        REFERENCES chat_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_mn_user FOREIGN KEY (mentioned_user_id) REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- FILE ATTACHMENTS -----------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_files (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id  INT NOT NULL,
  message_id    INT NULL,                       -- NULL until the message is sent (staged upload)
  user_id       INT NOT NULL,                   -- uploader
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(120) NOT NULL,          -- random; never reveal original_name in the URL
  mime_type     VARCHAR(120) NOT NULL,
  size_bytes    INT NOT NULL,
  created_at    DATETIME NOT NULL,
  INDEX idx_file_msg     (message_id),
  INDEX idx_file_ws_time (workspace_id, created_at),
  CONSTRAINT fk_file_ws   FOREIGN KEY (workspace_id) REFERENCES workspaces(id)    ON DELETE CASCADE,
  CONSTRAINT fk_file_msg  FOREIGN KEY (message_id)   REFERENCES chat_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_user FOREIGN KEY (user_id)      REFERENCES users(id)         ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- EVENT STREAM (the heart of SSE fan-out) ------------------------------
CREATE TABLE IF NOT EXISTS chat_events (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  workspace_id    INT NOT NULL,
  event_type      VARCHAR(40) NOT NULL,
  channel_id      INT NULL,
  conversation_id INT NULL,
  message_id      INT NULL,
  actor_user_id   INT NULL,
  payload         JSON NULL,
  created_at      DATETIME NOT NULL,
  INDEX idx_ev_ws_channel (workspace_id, channel_id, id),
  INDEX idx_ev_ws_conv    (workspace_id, conversation_id, id),
  INDEX idx_ev_ws_id      (workspace_id, id),
  CONSTRAINT fk_ev_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- USER PREFS & PRESENCE (side tables — users table untouched) ----------
CREATE TABLE IF NOT EXISTS chat_user_prefs (
  user_id        INT PRIMARY KEY,
  timezone       VARCHAR(64) NOT NULL DEFAULT 'UTC',
  notify_dm      TINYINT(1)  NOT NULL DEFAULT 1,
  notify_mention TINYINT(1)  NOT NULL DEFAULT 1,
  enter_to_send  TINYINT(1)  NOT NULL DEFAULT 1,  -- 1 = Enter sends / Shift+Enter newline
                                                  -- 0 = Cmd/Ctrl+Enter sends / Enter newline
  updated_at     DATETIME NOT NULL,
  CONSTRAINT fk_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_user_presence (
  user_id      INT PRIMARY KEY,
  last_seen_at DATETIME NOT NULL,                -- updated on every API ping; online if within 90s
  INDEX idx_pres_seen (last_seen_at),
  CONSTRAINT fk_pres_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
