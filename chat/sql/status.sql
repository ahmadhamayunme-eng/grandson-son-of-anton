-- ============================================================================
-- Anton Chat — custom status (Slack-style)
--
-- Adds three columns to chat_user_prefs so each user can advertise a status
-- emoji + free-text label that appears next to their name everywhere in the
-- chat module. The optional expires_at lets statuses auto-clear (e.g. "1 hr").
--
-- Apply ONCE on the live DB before deploying the status feature.
-- ============================================================================

ALTER TABLE chat_user_prefs
  ADD COLUMN status_emoji      VARCHAR(16)  NULL AFTER dnd,
  ADD COLUMN status_text       VARCHAR(120) NULL AFTER status_emoji,
  ADD COLUMN status_expires_at DATETIME     NULL AFTER status_text;
