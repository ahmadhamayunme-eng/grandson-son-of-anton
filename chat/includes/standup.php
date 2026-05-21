<?php
// chat/includes/standup.php — Anton Jr. daily standup automation.
//
// Cron-less: every SSE worker tick checks if any workspace has hit its
// standup_at time today and hasn't been pinged yet. Each participant gets
// a DM with the 3 standup questions. Replies are recorded in
// chat_standup_replies; at the cutoff time we collate them into one tidy
// post in the configured standup channel.
//
// Default times (set by phase11.sql): 21:00 send, 22:30 cutoff (9 pm local
// like the user asked). Configurable per workspace via chat_automations.

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/notifications.php';

const ANTON_STANDUP_QUESTIONS = [
  'What did you do yesterday?',
  'What are you working on today?',
  'Any blockers?',
];

/**
 * Master entry — call from the SSE tick. Cheap when nothing's due.
 */
function chat_standup_tick(): void {
  try {
    $pdo = db();
    $stmt = $pdo->query(
      'SELECT * FROM chat_automations WHERE standup_enabled = 1'
    );
    foreach ($stmt->fetchAll() as $row) {
      _chat_standup_run_for_workspace($row);
    }
  } catch (Throwable $e) {
    // chat_automations may not exist yet — silently skip.
  }
}

function _chat_standup_run_for_workspace(array $cfg): void {
  $ws = (int)$cfg['workspace_id'];
  $now = new DateTimeImmutable('now');
  $today = $now->format('Y-m-d');
  $hhmmNow = $now->format('H:i');

  // Ensure the #standup channel exists (auto-create on first run).
  $channelId = chat_ensure_standup_channel($ws);

  // Send the standup prompts when the clock ticks past standup_at.
  $lastSent = (string)($cfg['standup_last_sent_date'] ?? '');
  if ($hhmmNow >= ((string)$cfg['standup_at']) && $lastSent !== $today) {
    _chat_standup_send_prompts($ws, $channelId);
    db()->prepare('UPDATE chat_automations SET standup_last_sent_date = ?, updated_at = ? WHERE workspace_id = ?')
      ->execute([$today, now(), $ws]);
  }

  // Collate at the cutoff time.
  $lastCutoff = (string)($cfg['standup_last_cutoff_date'] ?? '');
  if ($hhmmNow >= ((string)$cfg['standup_cutoff_at']) && $lastCutoff !== $today) {
    _chat_standup_collate($ws, $channelId, $today);
    db()->prepare('UPDATE chat_automations SET standup_last_cutoff_date = ?, updated_at = ? WHERE workspace_id = ?')
      ->execute([$today, now(), $ws]);
  }
}

function _chat_standup_send_prompts(int $ws, int $channelId): void {
  // Participants = all active non-system users in the workspace who are
  // currently a member of at least one channel (so they're real chat users).
  $stmt = db()->prepare(
    "SELECT DISTINCT u.id, u.name
     FROM users u
     WHERE u.workspace_id = ? AND u.is_active = 1 AND IFNULL(u.is_system, 0) = 0
       AND u.id IN (
         SELECT user_id FROM chat_channel_members
         UNION SELECT user_id FROM chat_direct_conversation_members
       )"
  );
  $stmt->execute([$ws]);
  $participants = $stmt->fetchAll();

  $qs = ANTON_STANDUP_QUESTIONS;
  $body = '<p>👋 Time for your daily standup. Reply in this DM with one message per question:</p>'
        . '<ol>'
        . '<li>' . h($qs[0]) . '</li>'
        . '<li>' . h($qs[1]) . '</li>'
        . '<li>' . h($qs[2]) . '</li>'
        . '</ol>'
        . '<p><em>Your answers get rolled up into a single post in the standup channel later tonight.</em></p>';

  foreach ($participants as $p) {
    chat_dm_user_as_system($ws, (int)$p['id'], $body);
  }
}

function _chat_standup_collate(int $ws, int $channelId, string $forDate): void {
  if ($channelId <= 0) return;

  $stmt = db()->prepare(
    'SELECT r.user_id, r.question, r.answer_html, u.name
     FROM chat_standup_replies r
     JOIN users u ON u.id = r.user_id
     WHERE r.workspace_id = ? AND r.for_date = ?
     ORDER BY u.name ASC, r.id ASC'
  );
  $stmt->execute([$ws, $forDate]);
  $rows = $stmt->fetchAll();
  if (empty($rows)) return;

  // Group by user.
  $byUser = [];
  foreach ($rows as $r) {
    $uid = (int)$r['user_id'];
    if (!isset($byUser[$uid])) {
      $byUser[$uid] = ['name' => (string)$r['name'], 'answers' => []];
    }
    $byUser[$uid]['answers'][(string)$r['question']] = (string)$r['answer_html'];
  }

  $html = '<p><strong>📋 Daily standup — ' . h(date('M j', strtotime($forDate))) . '</strong></p>';
  foreach ($byUser as $entry) {
    $html .= '<p><strong>' . h($entry['name']) . '</strong></p><ul>';
    foreach (ANTON_STANDUP_QUESTIONS as $q) {
      if (!empty($entry['answers'][$q])) {
        $ans = trim(strip_tags((string)$entry['answers'][$q]));
        if ($ans !== '') $html .= '<li>' . h($ans) . '</li>';
      }
    }
    $html .= '</ul>';
  }
  chat_post_as_system($ws, $channelId, null, $html);
}

/**
 * Called from chat/api/messages.php after a DM to Anton Jr. lands. If the
 * thread that the user is replying in matches a standup prompt, save the
 * answer keyed by date so the cutoff job can roll it up.
 */
function chat_standup_capture_reply(int $workspaceId, int $userId, string $contentHtml, ?int $conversationId): void {
  if (!$conversationId) return;
  $system = chat_get_system_user($workspaceId);
  if (!$system) return;

  try {
    // Confirm the DM is between the user and Anton Jr..
    $st = db()->prepare(
      'SELECT 1 FROM chat_direct_conversation_members WHERE conversation_id = ? AND user_id = ?'
    );
    $st->execute([$conversationId, (int)$system['id']]);
    if (!$st->fetchColumn()) return;

    // Was the most recent prior message in this DM a standup prompt from
    // Anton Jr. within the last 24h? If so, attach this reply to the
    // next-unanswered question for today's date.
    $cur = db()->prepare(
      'SELECT id, content FROM chat_messages
       WHERE conversation_id = ? AND user_id = ?
         AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
       ORDER BY id DESC LIMIT 1'
    );
    $cur->execute([$conversationId, (int)$system['id']]);
    $prev = $cur->fetch();
    if (!$prev) return;
    if (strpos((string)$prev['content'], 'Time for your daily standup') === false) return;

    $today = date('Y-m-d');
    foreach (ANTON_STANDUP_QUESTIONS as $q) {
      $check = db()->prepare(
        'SELECT 1 FROM chat_standup_replies
         WHERE workspace_id = ? AND user_id = ? AND for_date = ? AND question = ?'
      );
      $check->execute([$workspaceId, $userId, $today, $q]);
      if (!$check->fetchColumn()) {
        db()->prepare(
          'INSERT INTO chat_standup_replies
             (workspace_id, user_id, for_date, question, answer_html, created_at)
           VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$workspaceId, $userId, $today, $q, $contentHtml, now()]);
        return;   // captured one question, move on
      }
    }
  } catch (Throwable $e) {}
}
