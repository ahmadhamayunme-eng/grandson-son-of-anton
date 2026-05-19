<?php
// Helpers used across the Anton Chat module.
//
// Pure(ish) functions: queries are allowed, side-effect ordering is the
// caller's responsibility. Callers are expected to have already required
// lib/auth.php, lib/helpers.php, and lib/db.php — this file does not
// re-require them (so an ordering bug surfaces immediately).

// ===== Identity & permissions ============================================

/**
 * True if the given user (defaults to the current session user) should be
 * treated as a chat admin. There is no chat-specific role column — admin
 * status is derived from the existing AntonX RBAC, matching the gate used
 * by partials/nav.php for the "Admin" sidebar section.
 */
function chat_is_admin(?array $u = null): bool {
  $u = $u ?? auth_user();
  return in_array($u['role_name'] ?? '', ['Super Admin', 'CEO', 'Manager'], true);
}

/**
 * True if the user is a member of the channel. One indexed lookup.
 */
function chat_user_is_channel_member(int $channelId, int $userId): bool {
  $stmt = db()->prepare('SELECT 1 FROM chat_channel_members WHERE channel_id = ? AND user_id = ? LIMIT 1');
  $stmt->execute([$channelId, $userId]);
  return (bool)$stmt->fetchColumn();
}

/**
 * True if the user is allowed to *see* a channel — they're a member of it,
 * OR it's a public channel in the same workspace. (Reading public channels
 * without joining matches Slack's "preview" behaviour.)
 */
function chat_user_can_see_channel(array $channel, int $userId, int $workspaceId): bool {
  if ((int)$channel['workspace_id'] !== $workspaceId) return false;
  if (empty($channel['is_private'])) return true;
  return chat_user_is_channel_member((int)$channel['id'], $userId);
}

// ===== Validation helpers ================================================

/**
 * Validate and normalize a channel slug. Returns the sanitized slug or null
 * if invalid. Slack convention: lowercase letters, digits, dashes,
 * underscores; 1–80 chars. Reserved words rejected.
 */
function chat_normalize_slug(string $raw): ?string {
  $s = strtolower(trim($raw));
  // Drop leading "#" if the user typed it.
  if (str_starts_with($s, '#')) $s = substr($s, 1);
  if (!preg_match('/^[a-z0-9_-]+$/', $s)) return null;
  if (strlen($s) > 80) return null;
  $reserved = ['here', 'channel', 'everyone'];
  if (in_array($s, $reserved, true)) return null;
  return $s;
}

// ===== Server-side queries used by chat/index.php =========================

/**
 * Channels to render in the user's sidebar: every public channel in the
 * workspace, plus every private channel the user is a member of. Each
 * row carries an `is_member` flag (0/1) so the UI can dim channels the
 * user hasn't joined yet but is allowed to see.
 *
 * Discovery model differs from Slack: with only 10–30 users we want
 * public channels obviously visible in everyone's sidebar, not buried
 * behind a Browse modal. Clicking an unjoined channel still shows the
 * "Join channel" prompt — joining isn't automatic.
 */
function chat_load_user_channels(int $workspaceId, int $userId): array {
  $stmt = db()->prepare(
    'SELECT c.id, c.slug, c.topic, c.is_private,
            EXISTS(SELECT 1 FROM chat_channel_members WHERE channel_id = c.id AND user_id = ?) AS is_member
     FROM chat_channels c
     WHERE c.workspace_id = ?
       AND c.archived_at IS NULL
       AND (
         c.is_private = 0
         OR c.id IN (SELECT channel_id FROM chat_channel_members WHERE user_id = ?)
       )
     ORDER BY c.slug ASC'
  );
  $stmt->execute([$userId, $workspaceId, $userId]);
  return $stmt->fetchAll();
}

/**
 * Fetch a single channel by its workspace + slug. Returns null if not found.
 */
function chat_load_channel_by_slug(int $workspaceId, string $slug): ?array {
  $stmt = db()->prepare(
    'SELECT * FROM chat_channels
     WHERE workspace_id = ? AND slug = ? AND archived_at IS NULL
     LIMIT 1'
  );
  $stmt->execute([$workspaceId, $slug]);
  $row = $stmt->fetch();
  return $row ?: null;
}

/**
 * Most-recent N top-level (non-thread) messages of a channel, oldest first.
 * Indexed by (channel_id, id) so this scans the tail of the index, not the
 * whole table.
 */
function chat_load_recent_messages(int $channelId, int $limit = 50): array {
  $limit = max(1, min(200, $limit));
  $stmt = db()->prepare(
    'SELECT m.id, m.user_id, m.content, m.created_at, m.edited_at, m.deleted_at,
            u.name AS author_name
     FROM chat_messages m
     JOIN users u ON u.id = m.user_id
     WHERE m.channel_id = ? AND m.parent_message_id IS NULL
     ORDER BY m.id DESC
     LIMIT ' . (int)$limit
  );
  $stmt->execute([$channelId]);
  return array_reverse($stmt->fetchAll());
}

/**
 * Member count for a channel.
 */
function chat_channel_member_count(int $channelId): int {
  $stmt = db()->prepare('SELECT COUNT(*) FROM chat_channel_members WHERE channel_id = ?');
  $stmt->execute([$channelId]);
  return (int)$stmt->fetchColumn();
}

// ===== Event stream (used by Phase 3 SSE; written from Phase 2 onward) ====

/**
 * Insert a row into chat_events. Used by every state-changing endpoint so
 * the SSE stream (Phase 3) can fan out without any backfill. Caller owns
 * the surrounding transaction (if any).
 *
 * event_type is a short string — see chat/sql/schema.sql for the list.
 * Use $payload for anything not in the dedicated columns (e.g. invitee
 * user_id on a channel_member_added event).
 */
function chat_emit_event(
  int $workspaceId,
  string $eventType,
  ?int $channelId = null,
  ?int $conversationId = null,
  ?int $messageId = null,
  ?int $actorUserId = null,
  ?array $payload = null
): void {
  $stmt = db()->prepare(
    'INSERT INTO chat_events
       (workspace_id, event_type, channel_id, conversation_id, message_id, actor_user_id, payload, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->execute([
    $workspaceId, $eventType, $channelId, $conversationId, $messageId, $actorUserId,
    $payload === null ? null : json_encode($payload),
    now(),
  ]);
}

// ===== Display helpers ====================================================

/**
 * Render a Slack-style relative timestamp for a message.
 *   today      -> "10:42 AM"
 *   yesterday  -> "Yesterday at 4:30 PM"
 *   this year  -> "Mar 12 at 9:00 AM"
 *   older      -> "Mar 12, 2024 at 9:00 AM"
 */
function chat_format_message_time(string $datetime): string {
  $ts = strtotime($datetime);
  if ($ts === false) return '';
  $now = time();
  if (date('Y-m-d', $ts) === date('Y-m-d', $now)) return date('g:i A', $ts);
  if (date('Y-m-d', $ts) === date('Y-m-d', $now - 86400)) return 'Yesterday at ' . date('g:i A', $ts);
  if (date('Y', $ts) === date('Y', $now)) return date('M j \a\t g:i A', $ts);
  return date('M j, Y \a\t g:i A', $ts);
}

// ===== JSON API plumbing (for chat/api/*) =================================

/**
 * Send a JSON response and exit.
 */
function chat_json(array $data, int $status = 200): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

/**
 * Send a JSON error and exit. "error" is a short machine-readable code
 * (e.g. "not_found"); "message" is a human-readable string for the UI.
 */
function chat_json_error(string $code, string $message = '', int $status = 400): never {
  chat_json(['error' => $code, 'message' => $message], $status);
}

/**
 * Require an authenticated session on an API endpoint. Returns the user.
 * Sends 401 JSON on failure (vs lib/auth.php's HTML redirect, which would
 * confuse a JS fetch).
 */
function chat_api_require_login(): array {
  if (session_status() === PHP_SESSION_NONE) { session_start(); }
  $u = auth_user();
  if (!$u) chat_json_error('unauthorized', 'You must be logged in.', 401);
  return $u;
}

/**
 * Require POST on a state-changing endpoint. Sends 405 JSON otherwise.
 */
function chat_api_require_post(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chat_json_error('method_not_allowed', 'POST required.', 405);
  }
}

/**
 * Require a valid CSRF token. Accepts it via "csrf" POST field OR the
 * X-CSRF-Token header (the latter is what the chat JS uses).
 */
function chat_api_require_csrf(): void {
  $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    chat_json_error('invalid_csrf', 'Bad or missing CSRF token. Reload the page.', 403);
  }
}
