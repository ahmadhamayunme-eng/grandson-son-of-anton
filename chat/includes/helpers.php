<?php
// Helpers used across the Anton Chat module.

// Parsedown is vendored at chat/lib/Parsedown.php. Optional — chat falls
// back to chat_markdown_simple() if the file isn't present.
if (file_exists(__DIR__ . '/../lib/Parsedown.php')) {
  require_once __DIR__ . '/../lib/Parsedown.php';
}
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

// ===== Direct messages (1-on-1 + group, Phase 4) =========================

/**
 * Canonical dm_key for a 1-on-1 DM: "min:max" of the two user IDs. Lets us
 * dedupe "the DM between A and B" with a single indexed lookup. Group DMs
 * always create a fresh row (no dedup) and store dm_key as NULL.
 */
function chat_dm_key_for_pair(int $userId1, int $userId2): string {
  $a = min($userId1, $userId2);
  $b = max($userId1, $userId2);
  return $a . ':' . $b;
}

/**
 * True if the user is a member of the DM conversation.
 */
function chat_user_is_dm_member(int $conversationId, int $userId): bool {
  $stmt = db()->prepare(
    'SELECT 1 FROM chat_direct_conversation_members
     WHERE conversation_id = ? AND user_id = ? LIMIT 1'
  );
  $stmt->execute([$conversationId, $userId]);
  return (bool)$stmt->fetchColumn();
}

/**
 * DMs the user is in, for the sidebar. Each row carries a comma-separated
 * list of the *other* members' names (GROUP_CONCAT) so the sidebar can
 * render "Alice" / "Alice, Bob, Carol" without a second query.
 *
 * Ordered by most recent activity first so the user's freshest threads
 * float to the top — matching Slack.
 */
function chat_load_user_dms(int $workspaceId, int $userId): array {
  $stmt = db()->prepare(
    "SELECT c.id, c.is_group, c.created_at,
            (SELECT MAX(m.id)         FROM chat_messages m WHERE m.conversation_id = c.id) AS last_message_id,
            (SELECT MAX(m.created_at) FROM chat_messages m WHERE m.conversation_id = c.id) AS last_activity_at,
            GROUP_CONCAT(other_u.id   ORDER BY other_u.name SEPARATOR ',')  AS other_user_ids,
            GROUP_CONCAT(other_u.name ORDER BY other_u.name SEPARATOR ', ') AS other_user_names
     FROM chat_direct_conversations c
     JOIN chat_direct_conversation_members me      ON me.conversation_id = c.id AND me.user_id = ?
     JOIN chat_direct_conversation_members other_m ON other_m.conversation_id = c.id AND other_m.user_id != ?
     JOIN users other_u                            ON other_u.id = other_m.user_id
     WHERE c.workspace_id = ?
     GROUP BY c.id
     ORDER BY (last_activity_at IS NULL), last_activity_at DESC, c.created_at DESC"
  );
  $stmt->execute([$userId, $userId, $workspaceId]);
  return $stmt->fetchAll();
}

/**
 * Single DM detail, only if the user is a member. Includes the same
 * GROUP_CONCAT of other members the sidebar uses, so the channel header
 * can render the display name without extra queries.
 */
function chat_load_dm_by_id(int $workspaceId, int $userId, int $conversationId): ?array {
  if (!chat_user_is_dm_member($conversationId, $userId)) return null;
  $stmt = db()->prepare(
    "SELECT c.id, c.is_group, c.created_at, c.created_by,
            GROUP_CONCAT(other_u.id   ORDER BY other_u.name SEPARATOR ',')  AS other_user_ids,
            GROUP_CONCAT(other_u.name ORDER BY other_u.name SEPARATOR ', ') AS other_user_names
     FROM chat_direct_conversations c
     LEFT JOIN chat_direct_conversation_members other_m ON other_m.conversation_id = c.id AND other_m.user_id != ?
     LEFT JOIN users other_u                            ON other_u.id = other_m.user_id
     WHERE c.id = ? AND c.workspace_id = ?
     GROUP BY c.id
     LIMIT 1"
  );
  $stmt->execute([$userId, $conversationId, $workspaceId]);
  $row = $stmt->fetch();
  return $row ?: null;
}

/**
 * Total member count for a DM (including the current user).
 */
function chat_dm_member_count(int $conversationId): int {
  $stmt = db()->prepare('SELECT COUNT(*) FROM chat_direct_conversation_members WHERE conversation_id = ?');
  $stmt->execute([$conversationId]);
  return (int)$stmt->fetchColumn();
}

/**
 * Most-recent N top-level messages in a DM, oldest first. Symmetric to
 * chat_load_recent_messages() but keyed on conversation_id.
 */
function chat_load_dm_messages(int $conversationId, int $limit = 50): array {
  $limit = max(1, min(200, $limit));
  $stmt = db()->prepare(
    'SELECT m.id, m.user_id, m.content, m.created_at, m.edited_at, m.deleted_at,
            u.name AS author_name
     FROM chat_messages m
     JOIN users u ON u.id = m.user_id
     WHERE m.conversation_id = ? AND m.parent_message_id IS NULL
     ORDER BY m.id DESC
     LIMIT ' . (int)$limit
  );
  $stmt->execute([$conversationId]);
  return array_reverse($stmt->fetchAll());
}

/**
 * Best display name for a DM: the other person's name for 1-on-1, the
 * comma-separated other members for a group. Falls back to "Direct
 * Message" if for some reason there are no other members in the row
 * (shouldn't happen in practice — every conversation has at least 2).
 */
function chat_dm_display_name(array $dm): string {
  $names = trim((string)($dm['other_user_names'] ?? ''));
  return $names !== '' ? $names : 'Direct Message';
}

// ===== Reactions, threads, mentions (Phase 5) =============================

/**
 * Reactions for one message, grouped by emoji. user_ids is a comma-separated
 * list of users who reacted — the client uses it to compute `me_reacted`
 * locally (so the same payload works for every recipient via SSE).
 */
function chat_load_message_reactions(int $messageId): array {
  $stmt = db()->prepare(
    "SELECT emoji,
            COUNT(*) AS cnt,
            GROUP_CONCAT(user_id ORDER BY user_id SEPARATOR ',') AS user_ids
     FROM chat_reactions
     WHERE message_id = ?
     GROUP BY emoji
     ORDER BY MIN(created_at) ASC"
  );
  $stmt->execute([$messageId]);
  return $stmt->fetchAll();
}

/**
 * Reactions for a batch of messages — returns a map keyed by message_id.
 * Used when rendering a channel/DM/thread page so we don't run one query
 * per message.
 */
function chat_load_reactions_for_messages(array $messageIds): array {
  if (empty($messageIds)) return [];
  $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
  $stmt = db()->prepare(
    "SELECT message_id, emoji, COUNT(*) AS cnt,
            GROUP_CONCAT(user_id ORDER BY user_id SEPARATOR ',') AS user_ids
     FROM chat_reactions
     WHERE message_id IN ($placeholders)
     GROUP BY message_id, emoji
     ORDER BY message_id ASC, MIN(created_at) ASC"
  );
  $stmt->execute($messageIds);
  $byMessage = [];
  foreach ($stmt->fetchAll() as $r) {
    $mid = (int)$r['message_id'];
    if (!isset($byMessage[$mid])) $byMessage[$mid] = [];
    $byMessage[$mid][] = [
      'emoji'    => $r['emoji'],
      'count'    => (int)$r['cnt'],
      'user_ids' => $r['user_ids'],
    ];
  }
  return $byMessage;
}

/**
 * Reply counts for a batch of parent message IDs. Map of parent_id => count.
 * Excludes soft-deleted replies.
 */
function chat_load_reply_counts(array $parentIds): array {
  if (empty($parentIds)) return [];
  $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
  $stmt = db()->prepare(
    "SELECT parent_message_id, COUNT(*) AS cnt
     FROM chat_messages
     WHERE parent_message_id IN ($placeholders) AND deleted_at IS NULL
     GROUP BY parent_message_id"
  );
  $stmt->execute($parentIds);
  $map = [];
  foreach ($stmt->fetchAll() as $r) $map[(int)$r['parent_message_id']] = (int)$r['cnt'];
  return $map;
}

/**
 * Workspace users keyed by lowercased first word of their name. Used by
 * the @mention parser and the autocomplete picker. Cached per-request so
 * an SSE loop or a multi-message render doesn't re-query every iteration.
 *
 * If two users share a first name, the first one inserted wins for that
 * token. Disambiguation is a Phase-9 polish item.
 */
function chat_workspace_users_by_first_name(int $workspaceId): array {
  static $cache = [];
  if (isset($cache[$workspaceId])) return $cache[$workspaceId];

  $stmt = db()->prepare(
    'SELECT id, name FROM users
     WHERE workspace_id = ? AND is_active = 1
     ORDER BY id ASC'
  );
  $stmt->execute([$workspaceId]);
  $map = [];
  foreach ($stmt->fetchAll() as $u) {
    $first = strtolower((string)strtok($u['name'], " \t"));
    if ($first === '' || isset($map[$first])) continue;
    $map[$first] = ['id' => (int)$u['id'], 'name' => $u['name']];
  }
  $cache[$workspaceId] = $map;
  return $map;
}

/*
 * NOTE: chat_render_message_content() lives further down in this file
 * (Phase 10 version) — it handles both new HTML messages from the
 * contentEditable composer and legacy markdown messages. Don't redeclare
 * it here.
 */

/**
 * Markdown → HTML. Prefers Parsedown when available; falls back to a
 * minimal regex parser that covers bold / italic / strike / code /
 * inline code / blockquote / link / auto-link / nl2br.
 */
function chat_markdown(string $text): string {
  if (class_exists('Parsedown')) {
    static $parsedown = null;
    if ($parsedown === null) {
      $parsedown = new Parsedown();
      $parsedown->setSafeMode(true);
      $parsedown->setMarkupEscaped(true);
      $parsedown->setBreaksEnabled(true);
    }
    $html = $parsedown->text($text);
    // Parsedown's safe-mode link rendering already drops dangerous URL
    // schemes. Add target=_blank + rel=noopener so AntonX users opening
    // links don't get window.opener back-channel access to the chat tab.
    $html = preg_replace_callback('#<a\b([^>]*)>#i', function ($m) {
      $attrs = $m[1];
      if (stripos($attrs, 'target=') === false) $attrs .= ' target="_blank"';
      if (stripos($attrs, 'rel=')    === false) $attrs .= ' rel="noopener noreferrer"';
      return '<a' . $attrs . '>';
    }, $html);
    return $html;
  }
  return chat_markdown_simple($text);
}

/**
 * Fallback markdown renderer used when Parsedown isn't vendored. Handles
 * the same subset of markdown Slack supports, regex-only — fragile on
 * edge cases like nested formatting, but adequate for short chat lines.
 */
function chat_markdown_simple(string $text): string {
  $text = h($text);

  // Fenced code blocks (protect their content first).
  $text = preg_replace_callback('/```([\s\S]*?)```/', function ($m) {
    return '<pre><code>' . trim($m[1], "\n") . '</code></pre>';
  }, $text);

  // Inline code.
  $text = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $text);

  // Bold (** or __), then italic (* or _), then strikethrough (~~).
  $text = preg_replace('/\*\*([^*\n]+)\*\*/',          '<strong>$1</strong>', $text);
  $text = preg_replace('/__([^_\n]+)__/',              '<strong>$1</strong>', $text);
  $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>',         $text);
  $text = preg_replace('/(?<!_)_([^_\n]+)_(?!_)/',     '<em>$1</em>',         $text);
  $text = preg_replace('/~~([^~\n]+)~~/',              '<s>$1</s>',           $text);

  // Markdown links [label](url) — http(s) and mailto only.
  $text = preg_replace_callback(
    '/\[([^\]\n]+)\]\(((?:https?:|mailto:)[^\s)]+)\)/',
    function ($m) {
      return '<a href="' . htmlspecialchars($m[2], ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
    },
    $text
  );

  // Line-level blockquote (one or more leading "> ").
  $text = preg_replace('/^&gt;\s?(.*)$/m', '<blockquote>$1</blockquote>', $text);
  $text = preg_replace('#</blockquote>\n<blockquote>#', '<br>', $text);

  // Auto-link bare URLs (skip if already inside an href).
  $text = preg_replace(
    '#(?<![">\w])(https?://[^\s<>"]+)#',
    '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
    $text
  );

  // Newlines → <br>, then restore real newlines inside <pre>.
  $text = nl2br($text);
  $text = preg_replace_callback('#<pre>(.*?)</pre>#s', function ($m) {
    return '<pre>' . str_replace(['<br />', '<br>'], "\n", $m[1]) . '</pre>';
  }, $text);

  return $text;
}

/**
 * Apply @mention wrapping to an HTML string, but only outside content that
 * shouldn't be touched (<code>, <pre>, <a>). Lets us run mentions AFTER
 * markdown without trampling code blocks or replacing email autolinks.
 */
function chat_apply_mentions_outside_protected(string $html, array $users): string {
  $pattern = '#(<code\b[^>]*>.*?</code>|<pre\b[^>]*>.*?</pre>|<a\b[^>]*>.*?</a>)#si';
  $parts = preg_split($pattern, $html, -1, PREG_SPLIT_DELIM_CAPTURE);
  $result = '';
  foreach ($parts as $i => $part) {
    if ($i % 2 === 0) $result .= chat_replace_mentions_in_html($part, $users);
    else              $result .= $part;
  }
  return $result;
}

function chat_replace_mentions_in_html(string $html, array $users): string {
  return preg_replace_callback(
    '/(^|[^a-z0-9_])@(channel|here|everyone|[a-z][a-z0-9_-]*)(?![a-z0-9_])/i',
    function ($m) use ($users) {
      $prefix = $m[1];
      $token  = strtolower($m[2]);
      if (in_array($token, ['channel', 'here', 'everyone'], true)) {
        return $prefix . '<span class="chat-mention chat-mention-special" data-mention="' . h($token) . '">@' . $token . '</span>';
      }
      if (isset($users[$token])) {
        return $prefix
             . '<span class="chat-mention" data-user-id="' . (int)$users[$token]['id'] . '">@'
             . h($users[$token]['name']) . '</span>';
      }
      return $prefix . '@' . h($token);  // unknown — render plain
    },
    $html
  );
}

/**
 * Attach content_html, reactions, reply_count, and files to a batch of
 * message rows fetched from chat_messages. Soft-deleted messages get
 * content + files blanked and no decorations. Used both by
 * chat/api/messages.php (list action) and chat/index.php (initial render).
 */
function chat_decorate_messages(array $rows, int $workspaceId): array {
  if (empty($rows)) return $rows;
  $ids = [];
  foreach ($rows as $r) { $ids[] = (int)$r['id']; }
  $reactionsByMsg = chat_load_reactions_for_messages($ids);
  $replyCounts    = chat_load_reply_counts($ids);
  $filesByMsg     = chat_load_files_for_messages($ids);

  foreach ($rows as &$r) {
    $deleted = $r['deleted_at'] !== null;
    if ($deleted) {
      $r['content']      = '';
      $r['content_html'] = '';
      $r['files']        = [];
    } else {
      $r['content_html'] = chat_render_message_content((string)$r['content'], $workspaceId);
      $r['files']        = $filesByMsg[(int)$r['id']] ?? [];
    }
    $r['reactions']   = $reactionsByMsg[(int)$r['id']] ?? [];
    $r['reply_count'] = (int)($replyCounts[(int)$r['id']] ?? 0);
  }
  unset($r);
  return $rows;
}

// ===== File attachments (Phase 6) =========================================

/**
 * Files attached to a batch of messages — map of message_id => array of
 * { id, name, mime, size, is_image }. Soft-deleted messages have their
 * files omitted by chat_decorate_messages.
 */
function chat_load_files_for_messages(array $messageIds): array {
  if (empty($messageIds)) return [];
  $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
  $stmt = db()->prepare(
    "SELECT id, message_id, original_name, mime_type, size_bytes
     FROM chat_files
     WHERE message_id IN ($placeholders)
     ORDER BY id ASC"
  );
  $stmt->execute($messageIds);
  $byMessage = [];
  foreach ($stmt->fetchAll() as $f) {
    $mid = (int)$f['message_id'];
    if (!isset($byMessage[$mid])) $byMessage[$mid] = [];
    $byMessage[$mid][] = [
      'id'       => (int)$f['id'],
      'name'     => $f['original_name'],
      'mime'     => $f['mime_type'],
      'size'     => (int)$f['size_bytes'],
      'is_image' => strpos((string)$f['mime_type'], 'image/') === 0 ? 1 : 0,
    ];
  }
  return $byMessage;
}

/**
 * Pretty human-readable file size (e.g. "2.3 MB").
 */
function chat_format_file_size(int $bytes): string {
  if ($bytes < 1024)              return $bytes . ' B';
  if ($bytes < 1024 * 1024)       return round($bytes / 1024, 1) . ' KB';
  if ($bytes < 1024 * 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
  return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
}

// ===== Unread counts & presence (Phase 8) ================================

/**
 * Unread counts for the user, computed from last_read_message_id on each
 * channel / DM membership. Excludes their own messages, soft-deleted
 * messages, and thread replies (we only badge top-level activity).
 *
 * Returns:
 *   [ 'channels' => [channel_id => count, ...],
 *     'dms'      => [conversation_id => count, ...] ]
 * Entries with count 0 are omitted to keep the JSON small.
 */
function chat_unread_counts_for_user(int $workspaceId, int $userId): array {
  $pdo = db();

  $stmt = $pdo->prepare(
    "SELECT cm.channel_id,
            (SELECT COUNT(*) FROM chat_messages m
             WHERE m.channel_id = cm.channel_id
               AND m.parent_message_id IS NULL
               AND m.deleted_at IS NULL
               AND m.user_id != ?
               AND m.id > IFNULL(cm.last_read_message_id, 0)
            ) AS unread
     FROM chat_channel_members cm
     JOIN chat_channels c ON c.id = cm.channel_id
     WHERE cm.user_id = ? AND c.workspace_id = ? AND c.archived_at IS NULL"
  );
  $stmt->execute([$userId, $userId, $workspaceId]);
  $channels = [];
  foreach ($stmt->fetchAll() as $r) {
    $n = (int)$r['unread'];
    if ($n > 0) $channels[(int)$r['channel_id']] = $n;
  }

  $stmt = $pdo->prepare(
    "SELECT dm.conversation_id,
            (SELECT COUNT(*) FROM chat_messages m
             WHERE m.conversation_id = dm.conversation_id
               AND m.parent_message_id IS NULL
               AND m.deleted_at IS NULL
               AND m.user_id != ?
               AND m.id > IFNULL(dm.last_read_message_id, 0)
            ) AS unread
     FROM chat_direct_conversation_members dm
     JOIN chat_direct_conversations c ON c.id = dm.conversation_id
     WHERE dm.user_id = ? AND c.workspace_id = ?"
  );
  $stmt->execute([$userId, $userId, $workspaceId]);
  $dms = [];
  foreach ($stmt->fetchAll() as $r) {
    $n = (int)$r['unread'];
    if ($n > 0) $dms[(int)$r['conversation_id']] = $n;
  }

  return ['channels' => $channels, 'dms' => $dms];
}

/**
 * Set the membership's last_read_message_id to the current MAX(id) of
 * the channel. Idempotent — running again is a no-op. Used both on
 * page load (auto-read the currently-viewed channel) and from the
 * mark_read endpoint when SSE delivers a message to the open channel.
 */
function chat_mark_channel_read(int $channelId, int $userId): void {
  $stmt = db()->prepare(
    'UPDATE chat_channel_members
     SET last_read_message_id = IFNULL((
       SELECT MAX(id) FROM chat_messages WHERE channel_id = ?
     ), last_read_message_id)
     WHERE channel_id = ? AND user_id = ?'
  );
  $stmt->execute([$channelId, $channelId, $userId]);
}

function chat_mark_dm_read(int $conversationId, int $userId): void {
  $stmt = db()->prepare(
    'UPDATE chat_direct_conversation_members
     SET last_read_message_id = IFNULL((
       SELECT MAX(id) FROM chat_messages WHERE conversation_id = ?
     ), last_read_message_id)
     WHERE conversation_id = ? AND user_id = ?'
  );
  $stmt->execute([$conversationId, $conversationId, $userId]);
}

/**
 * Bump the user's last_seen_at to now(). Called from /chat/api/presence.php
 * every ~30s while the page is open. Upserts via ON DUPLICATE KEY so a
 * brand-new user's row is created on first ping.
 */
function chat_presence_ping(int $userId): void {
  $stmt = db()->prepare(
    'INSERT INTO chat_user_presence (user_id, last_seen_at)
     VALUES (?, ?)
     ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)'
  );
  $stmt->execute([$userId, now()]);
}

/**
 * Load the user's chat-specific preferences. Returns sensible defaults
 * if the row doesn't exist yet (e.g. a brand-new user who never visited
 * the Preferences modal).
 *
 *   enter_to_send  1 = Enter sends (default), 0 = Cmd/Ctrl+Enter sends
 *   notify_dm      1 = browser notification on new DMs
 *   notify_mention 1 = browser notification on @user / @here / @channel
 *   timezone       IANA tz string, default 'UTC'
 */
function chat_load_user_prefs(int $userId): array {
  $stmt = db()->prepare(
    'SELECT timezone, notify_dm, notify_mention, enter_to_send, dnd
     FROM chat_user_prefs WHERE user_id = ?'
  );
  $stmt->execute([$userId]);
  $row = $stmt->fetch();
  if (!$row) {
    return [
      'timezone'       => 'UTC',
      'notify_dm'      => 1,
      'notify_mention' => 1,
      'enter_to_send'  => 1,
      'dnd'            => 0,
    ];
  }
  return [
    'timezone'       => (string)$row['timezone'],
    'notify_dm'      => (int)$row['notify_dm'],
    'notify_mention' => (int)$row['notify_mention'],
    'enter_to_send'  => (int)$row['enter_to_send'],
    'dnd'            => (int)($row['dnd'] ?? 0),
  ];
}

/**
 * Online/offline map for every active user in the workspace.
 *   [ user_id => 1 if last_seen_at within 90s, 0 otherwise ]
 * The 90s threshold matches the spec.
 */
function chat_presence_map(int $workspaceId): array {
  $stmt = db()->prepare(
    "SELECT u.id, IF(p.last_seen_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND), 1, 0) AS online
     FROM users u
     LEFT JOIN chat_user_presence p ON p.user_id = u.id
     WHERE u.workspace_id = ? AND u.is_active = 1"
  );
  $stmt->execute([$workspaceId]);
  $map = [];
  foreach ($stmt->fetchAll() as $r) $map[(int)$r['id']] = (int)$r['online'];
  return $map;
}

/**
 * Validate a staged upload: it must exist, belong to this user, and
 * still be unattached (message_id IS NULL). Used by msg_action_send
 * before claiming the files as part of a new message.
 */
function chat_user_owns_staged_file(int $fileId, int $userId, int $workspaceId): bool {
  $stmt = db()->prepare(
    'SELECT 1 FROM chat_files
     WHERE id = ? AND user_id = ? AND workspace_id = ? AND message_id IS NULL
     LIMIT 1'
  );
  $stmt->execute([$fileId, $userId, $workspaceId]);
  return (bool)$stmt->fetchColumn();
}

/**
 * Parse the message's @mention tokens and insert chat_mentions rows.
 * Called inside the send-message transaction so the rows commit atomically
 * with the message itself. Returns the list of inserted mentions for
 * logging / payload.
 */
function chat_parse_and_store_mentions(int $messageId, string $content, int $workspaceId): array {
  $users = chat_workspace_users_by_first_name($workspaceId);
  $now   = now();
  $stmt  = db()->prepare(
    'INSERT INTO chat_mentions (message_id, mention_type, mentioned_user_id, created_at)
     VALUES (?, ?, ?, ?)'
  );

  $inserted = [];
  $seen     = [];
  if (preg_match_all('/(?:^|[^a-z0-9_])@(channel|here|everyone|[a-z][a-z0-9_-]*)(?![a-z0-9_])/i', $content, $matches)) {
    foreach ($matches[1] as $token) {
      $token = strtolower($token);
      if (in_array($token, ['channel', 'here', 'everyone'], true)) {
        if (isset($seen['t:' . $token])) continue;
        $seen['t:' . $token] = true;
        $stmt->execute([$messageId, $token, null, $now]);
        $inserted[] = ['type' => $token];
      } elseif (isset($users[$token])) {
        $uid = $users[$token]['id'];
        if (isset($seen['u:' . $uid])) continue;
        $seen['u:' . $uid] = true;
        $stmt->execute([$messageId, 'user', $uid, $now]);
        $inserted[] = ['type' => 'user', 'user_id' => $uid];
      }
    }
  }
  return $inserted;
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

// =============================================================================
// PHASE 10 — design migration helpers
// =============================================================================

// ---- HTML rendering / sanitization ----------------------------------------

/**
 * Returns true if $content looks like HTML (Phase-10 storage format), false
 * if it looks like markdown (legacy). The composer now ships sanitized HTML;
 * older rows are markdown and still need Parsedown. Heuristic: starts with
 * an opening tag.
 */
function chat_content_is_html(string $content): bool {
  return (bool)preg_match('/^\s*<[a-z!]/i', $content);
}

/**
 * Sanitize HTML coming from the contentEditable composer. Allowlist-based
 * via DOMDocument — strips scripts, on* handlers, javascript: URLs, and
 * anything not in the tag/attribute list.
 *
 * Allowed tags:
 *   p, br, strong, b, em, i, s, u, code, pre, ul, ol, li, blockquote, a, span, div
 *
 * Allowed attributes:
 *   a:    href (http/https/mailto/relative only), auto target=_blank + rel
 *   span: class (only "mention", "mention-special", "ch-link"), data-user-id
 *   pre, code: class (for language hints)
 */
function chat_sanitize_html(string $html): string {
  if (trim($html) === '') return '';

  static $allowedTags = [
    'p', 'br',
    'strong', 'b', 'em', 'i', 's', 'u',
    'code', 'pre',
    'ul', 'ol', 'li',
    'blockquote',
    'a', 'span', 'div',
  ];
  static $allowedAttrsByTag = [
    'a'    => ['href', 'target', 'rel'],
    'span' => ['class', 'data-user-id'],
    'pre'  => ['class'],
    'code' => ['class'],
  ];
  // Keep both naming conventions: 'chat-mention' is what the markdown
  // pipeline emits, 'mention' is the short alias.
  static $allowedSpanClasses = [
    'mention', 'mention-special', 'ch-link',
    'chat-mention', 'chat-mention-special',
  ];

  $dom = new DOMDocument('1.0', 'UTF-8');
  libxml_use_internal_errors(true);
  $wrapped = '<?xml encoding="UTF-8"?><div id="__chat_root__">' . $html . '</div>';
  $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
  libxml_clear_errors();

  $root = $dom->getElementById('__chat_root__');
  if (!$root) return '';

  $walker = function (DOMNode $node) use (&$walker, $allowedTags, $allowedAttrsByTag, $allowedSpanClasses) {
    // Snapshot children since we may mutate the list while iterating.
    $children = [];
    foreach ($node->childNodes as $c) $children[] = $c;

    foreach ($children as $child) {
      if ($child->nodeType === XML_ELEMENT_NODE) {
        $tag = strtolower($child->tagName);
        if (!in_array($tag, $allowedTags, true)) {
          // Unwrap: keep children, drop the tag itself.
          while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
          $node->removeChild($child);
          continue;
        }
        // Strip attributes not in the per-tag allowlist.
        $allowed = $allowedAttrsByTag[$tag] ?? [];
        $remove  = [];
        foreach ($child->attributes as $a) {
          if (!in_array(strtolower($a->name), $allowed, true)) $remove[] = $a->name;
        }
        foreach ($remove as $name) $child->removeAttribute($name);

        // Tag-specific extra validation.
        if ($tag === 'a') {
          $href = $child->getAttribute('href');
          if ($href !== '' && !preg_match('#^(https?:|mailto:|/|#)#i', $href)) {
            $child->removeAttribute('href');
          }
          if ($child->hasAttribute('href')) {
            $child->setAttribute('target', '_blank');
            $child->setAttribute('rel', 'noopener noreferrer');
          }
        }
        if ($tag === 'span' && $child->hasAttribute('class')) {
          $kept = array_intersect(
            preg_split('/\s+/', $child->getAttribute('class')),
            $allowedSpanClasses
          );
          if (!empty($kept)) $child->setAttribute('class', implode(' ', $kept));
          else               $child->removeAttribute('class');
        }
        $walker($child);
      } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
        $node->removeChild($child);
      }
    }
  };
  $walker($root);

  $out = '';
  foreach ($root->childNodes as $child) $out .= $dom->saveHTML($child);
  return trim($out);
}

/**
 * Updated content renderer that handles both new (HTML) and legacy (markdown)
 * messages. New messages from the contentEditable composer are already
 * sanitized HTML — we just re-apply mention wrapping (in case the user typed
 * a fresh @mention as plain text). Legacy markdown messages still go through
 * the Phase 6 pipeline.
 */
function chat_render_message_content(string $content, int $workspaceId): string {
  $users = chat_workspace_users_by_first_name($workspaceId);
  if (chat_content_is_html($content)) {
    // Re-sanitize defensively (already done on store, but cheap).
    $html = chat_sanitize_html($content);
  } else {
    $html = chat_markdown($content);
  }
  return chat_apply_mentions_outside_protected($html, $users);
}

// ---- Saved messages -------------------------------------------------------

function chat_user_has_saved_message(int $messageId, int $userId): bool {
  $stmt = db()->prepare('SELECT 1 FROM chat_saved_messages WHERE user_id = ? AND message_id = ? LIMIT 1');
  $stmt->execute([$userId, $messageId]);
  return (bool)$stmt->fetchColumn();
}

function chat_load_saved_set_for_user(array $messageIds, int $userId): array {
  if (empty($messageIds)) return [];
  $ph = implode(',', array_fill(0, count($messageIds), '?'));
  $stmt = db()->prepare("SELECT message_id FROM chat_saved_messages WHERE user_id = ? AND message_id IN ($ph)");
  $stmt->execute(array_merge([$userId], $messageIds));
  $set = [];
  foreach ($stmt->fetchAll() as $r) $set[(int)$r['message_id']] = true;
  return $set;
}

// ---- Pinned messages ------------------------------------------------------

function chat_message_is_pinned(int $channelId, int $messageId): bool {
  $stmt = db()->prepare('SELECT 1 FROM chat_pinned_messages WHERE channel_id = ? AND message_id = ? LIMIT 1');
  $stmt->execute([$channelId, $messageId]);
  return (bool)$stmt->fetchColumn();
}

function chat_load_pinned_set_for_channel(int $channelId, array $messageIds): array {
  if (empty($messageIds)) return [];
  $ph = implode(',', array_fill(0, count($messageIds), '?'));
  $stmt = db()->prepare("SELECT message_id FROM chat_pinned_messages WHERE channel_id = ? AND message_id IN ($ph)");
  $stmt->execute(array_merge([$channelId], $messageIds));
  $set = [];
  foreach ($stmt->fetchAll() as $r) $set[(int)$r['message_id']] = true;
  return $set;
}

function chat_load_pinned_messages_for_channel(int $channelId, int $workspaceId): array {
  $stmt = db()->prepare(
    'SELECT m.id, m.user_id, m.content, m.created_at, m.edited_at, m.deleted_at,
            m.parent_message_id, p.pinned_by, p.pinned_at,
            u.name AS author_name
     FROM chat_pinned_messages p
     JOIN chat_messages m ON m.id = p.message_id
     JOIN users u ON u.id = m.user_id
     WHERE p.channel_id = ? AND m.deleted_at IS NULL
     ORDER BY p.pinned_at DESC
     LIMIT 100'
  );
  $stmt->execute([$channelId]);
  return chat_decorate_messages($stmt->fetchAll(), $workspaceId);
}

// ---- Threads (user-level) -------------------------------------------------

/**
 * Threads the user is participating in across the workspace. A user
 * "participates" if they posted a reply OR authored the parent. Returns
 * decorated parent messages with reply_count + last reply meta.
 */
function chat_load_user_threads(int $workspaceId, int $userId, int $limit = 50): array {
  $limit = max(1, min(200, $limit));
  $stmt = db()->prepare(
    "SELECT p.id AS parent_id,
            (SELECT MAX(r.id) FROM chat_messages r
             WHERE r.parent_message_id = p.id AND r.deleted_at IS NULL) AS last_reply_id
     FROM chat_messages p
     WHERE p.workspace_id = ?
       AND p.parent_message_id IS NULL
       AND p.deleted_at IS NULL
       AND (
         p.user_id = ?
         OR EXISTS (SELECT 1 FROM chat_messages r
                    WHERE r.parent_message_id = p.id AND r.user_id = ?)
       )
     HAVING last_reply_id IS NOT NULL
     ORDER BY last_reply_id DESC
     LIMIT " . (int)$limit
  );
  $stmt->execute([$workspaceId, $userId, $userId]);
  $parentIds = array_column($stmt->fetchAll(), 'parent_id');
  if (empty($parentIds)) return [];

  $ph = implode(',', array_fill(0, count($parentIds), '?'));
  $stmt = db()->prepare(
    "SELECT m.id, m.user_id, m.content, m.created_at, m.edited_at, m.deleted_at,
            m.parent_message_id, m.channel_id, m.conversation_id,
            u.name AS author_name,
            c.slug AS channel_slug, c.is_private AS channel_is_private
     FROM chat_messages m
     JOIN users u ON u.id = m.user_id
     LEFT JOIN chat_channels c ON c.id = m.channel_id
     WHERE m.id IN ($ph)"
  );
  $stmt->execute($parentIds);
  $byId = [];
  foreach ($stmt->fetchAll() as $r) $byId[(int)$r['id']] = $r;
  // Preserve thread ordering (most-recent first).
  $rows = [];
  foreach ($parentIds as $pid) {
    if (isset($byId[(int)$pid])) $rows[] = $byId[(int)$pid];
  }
  return chat_decorate_messages($rows, $workspaceId);
}

// ---- Inbox ----------------------------------------------------------------

/**
 * Inbox feed: messages that mention the user (@user, @channel, @here,
 * @everyone in channels they're a member of) plus DM messages where the
 * user is a recipient but not the author. Newest first.
 */
function chat_load_user_inbox(int $workspaceId, int $userId, int $limit = 50): array {
  $limit = max(1, min(200, $limit));

  $stmt = db()->prepare(
    "SELECT m.id, m.user_id, m.content, m.created_at, m.edited_at, m.deleted_at,
            m.parent_message_id, m.channel_id, m.conversation_id,
            u.name AS author_name,
            c.slug AS channel_slug, c.is_private AS channel_is_private
     FROM chat_messages m
     JOIN users u ON u.id = m.user_id
     LEFT JOIN chat_channels c ON c.id = m.channel_id
     WHERE m.workspace_id = ?
       AND m.deleted_at IS NULL
       AND m.user_id != ?
       AND (
         EXISTS (
           SELECT 1 FROM chat_mentions mn
           WHERE mn.message_id = m.id
             AND (
               (mn.mention_type = 'user' AND mn.mentioned_user_id = ?)
               OR (mn.mention_type IN ('channel','here','everyone')
                   AND m.channel_id IN (
                     SELECT channel_id FROM chat_channel_members WHERE user_id = ?
                   ))
             )
         )
         OR (m.conversation_id IS NOT NULL AND m.conversation_id IN (
           SELECT conversation_id FROM chat_direct_conversation_members WHERE user_id = ?
         ))
       )
     ORDER BY m.id DESC
     LIMIT " . (int)$limit
  );
  $stmt->execute([$workspaceId, $userId, $userId, $userId, $userId]);
  return chat_decorate_messages($stmt->fetchAll(), $workspaceId);
}

// ---- Per-user mention counts (Phase 10 — for sidebar gold badges) ---------

function chat_mention_counts_for_user(int $workspaceId, int $userId): array {
  $pdo = db();
  $stmt = $pdo->prepare(
    "SELECT cm.channel_id,
            (SELECT COUNT(*) FROM chat_messages m
             JOIN chat_mentions mn ON mn.message_id = m.id
             WHERE m.channel_id = cm.channel_id
               AND m.parent_message_id IS NULL
               AND m.deleted_at IS NULL
               AND m.user_id != ?
               AND m.id > IFNULL(cm.last_read_message_id, 0)
               AND (
                 (mn.mention_type = 'user' AND mn.mentioned_user_id = ?)
                 OR mn.mention_type IN ('channel','here','everyone')
               )
            ) AS cnt
     FROM chat_channel_members cm
     JOIN chat_channels c ON c.id = cm.channel_id
     WHERE cm.user_id = ? AND c.workspace_id = ? AND c.archived_at IS NULL"
  );
  $stmt->execute([$userId, $userId, $userId, $workspaceId]);
  $out = [];
  foreach ($stmt->fetchAll() as $r) {
    $n = (int)$r['cnt'];
    if ($n > 0) $out[(int)$r['channel_id']] = $n;
  }
  return $out;
}

// ---- 4-state presence (Phase 10) ------------------------------------------

/**
 * Returns user_id => 'active'|'idle'|'offline'|'dnd' for every active user
 * in the workspace. DND wins over everything when set.
 *   active  = last_seen within 60s
 *   idle    = last_seen within 300s
 *   offline = older or never
 *   dnd     = user opted in via prefs.dnd
 */
function chat_presence_state_map(int $workspaceId): array {
  $stmt = db()->prepare(
    "SELECT u.id,
            TIMESTAMPDIFF(SECOND, p.last_seen_at, NOW()) AS secs,
            IFNULL(pf.dnd, 0) AS dnd
     FROM users u
     LEFT JOIN chat_user_presence p ON p.user_id = u.id
     LEFT JOIN chat_user_prefs    pf ON pf.user_id = u.id
     WHERE u.workspace_id = ? AND u.is_active = 1"
  );
  $stmt->execute([$workspaceId]);
  $map = [];
  foreach ($stmt->fetchAll() as $r) {
    $id = (int)$r['id'];
    if ((int)$r['dnd']) { $map[$id] = 'dnd'; continue; }
    $secs = $r['secs'];
    if ($secs === null)        $map[$id] = 'offline';
    elseif ((int)$secs <= 60)  $map[$id] = 'active';
    elseif ((int)$secs <= 300) $map[$id] = 'idle';
    else                       $map[$id] = 'offline';
  }
  return $map;
}

// ---- Scheduled message sweep (called from the SSE worker) -----------------

/**
 * Deliver any scheduled messages whose `scheduled_for` is in the past. Runs
 * inside the SSE loop so any open chat tab triggers delivery — no OS cron
 * required. Idempotent: each row claimed via a transaction + sent_at update.
 */
function chat_deliver_due_scheduled_messages(): int {
  $pdo = db();
  $stmt = $pdo->prepare(
    'SELECT * FROM chat_scheduled_messages
     WHERE scheduled_for <= NOW() AND sent_at IS NULL
     ORDER BY scheduled_for ASC
     LIMIT 20'
  );
  $stmt->execute();
  $rows = $stmt->fetchAll();
  if (empty($rows)) return 0;

  $delivered = 0;
  foreach ($rows as $r) {
    $pdo->beginTransaction();
    try {
      // Re-check inside the transaction so two concurrent SSE workers don't
      // double-deliver the same row.
      $check = $pdo->prepare('SELECT sent_at FROM chat_scheduled_messages WHERE id = ? FOR UPDATE');
      $check->execute([(int)$r['id']]);
      $still = $check->fetch();
      if (!$still || $still['sent_at'] !== null) { $pdo->commit(); continue; }

      $now = now();
      $insert = $pdo->prepare(
        'INSERT INTO chat_messages
           (workspace_id, channel_id, conversation_id, parent_message_id, reply_to_message_id, user_id, content, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
      );
      $insert->execute([
        (int)$r['workspace_id'],
        $r['channel_id']          !== null ? (int)$r['channel_id']          : null,
        $r['conversation_id']     !== null ? (int)$r['conversation_id']     : null,
        $r['parent_message_id']   !== null ? (int)$r['parent_message_id']   : null,
        $r['reply_to_message_id'] !== null ? (int)$r['reply_to_message_id'] : null,
        (int)$r['user_id'],
        (string)$r['content_html'],
        $now,
      ]);
      $messageId = (int)$pdo->lastInsertId();
      chat_parse_and_store_mentions($messageId, (string)$r['content_html'], (int)$r['workspace_id']);

      $payload = [];
      if ($r['parent_message_id']   !== null) $payload['parent_message_id']   = (int)$r['parent_message_id'];
      if ($r['reply_to_message_id'] !== null) $payload['reply_to_message_id'] = (int)$r['reply_to_message_id'];

      chat_emit_event(
        (int)$r['workspace_id'], 'message',
        $r['channel_id']      !== null ? (int)$r['channel_id']      : null,
        $r['conversation_id'] !== null ? (int)$r['conversation_id'] : null,
        $messageId,
        (int)$r['user_id'],
        empty($payload) ? null : $payload
      );

      $upd = $pdo->prepare('UPDATE chat_scheduled_messages SET sent_at = ?, sent_message_id = ? WHERE id = ?');
      $upd->execute([$now, $messageId, (int)$r['id']]);

      $pdo->commit();
      $delivered++;
    } catch (Throwable $e) {
      $pdo->rollBack();
      // Swallow — next sweep will retry.
    }
  }
  return $delivered;
}

// ---- chat_decorate_messages extension (Phase 10) --------------------------
// Adds is_saved, is_pinned, reply_to_message, channel context, and the
// content_html re-render to the existing decoration pipeline. Used by
// every list endpoint and the initial page render.
function chat_decorate_messages_phase10(array $rows, int $workspaceId, int $currentUserId, ?int $contextChannelId = null): array {
  if (empty($rows)) return $rows;
  $rows = chat_decorate_messages($rows, $workspaceId);

  $ids = [];
  foreach ($rows as $r) { $ids[] = (int)$r['id']; }
  $savedSet  = chat_load_saved_set_for_user($ids, $currentUserId);
  $pinnedSet = $contextChannelId ? chat_load_pinned_set_for_channel($contextChannelId, $ids) : [];

  // Reply-to messages — fetch in batch.
  $replyIds = [];
  foreach ($rows as $r) {
    if (!empty($r['reply_to_message_id'])) $replyIds[] = (int)$r['reply_to_message_id'];
  }
  $replyById = [];
  if (!empty($replyIds)) {
    $ph = implode(',', array_fill(0, count($replyIds), '?'));
    $stmt = db()->prepare(
      "SELECT m.id, m.content, m.deleted_at, u.name AS author_name
       FROM chat_messages m JOIN users u ON u.id = m.user_id
       WHERE m.id IN ($ph)"
    );
    $stmt->execute($replyIds);
    foreach ($stmt->fetchAll() as $r) {
      $replyById[(int)$r['id']] = [
        'id'          => (int)$r['id'],
        'author_name' => $r['author_name'],
        'content'     => $r['deleted_at'] !== null ? '' : (string)$r['content'],
        'deleted'     => $r['deleted_at'] !== null,
      ];
    }
  }

  foreach ($rows as &$r) {
    $r['is_saved']  = isset($savedSet[(int)$r['id']])  ? 1 : 0;
    $r['is_pinned'] = isset($pinnedSet[(int)$r['id']]) ? 1 : 0;
    $rt = !empty($r['reply_to_message_id']) ? (int)$r['reply_to_message_id'] : 0;
    $r['reply_to']  = $rt && isset($replyById[$rt]) ? $replyById[$rt] : null;
  }
  unset($r);
  return $rows;
}
