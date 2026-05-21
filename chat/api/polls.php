<?php
// /chat/api/polls.php — vote on inline polls created by /poll.
//
// POST ?action=vote { poll_id, option_idx } — toggles the vote for this user.
//
// Single-choice for now: voting on option B clears any prior vote on A.
// Returns the fresh counts so the client can update inline.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = chat_api_require_login();
$ws   = (int)$user['workspace_id'];
$uid  = (int)$user['id'];
$pdo  = db();

$action = (string)($_GET['action'] ?? '');
if ($action !== 'vote') chat_json_error('unknown_action', "Unknown action: $action", 400);
chat_api_require_post();
chat_api_require_csrf();

$pollId    = (int)($_POST['poll_id'] ?? 0);
$optionIdx = (int)($_POST['option_idx'] ?? -1);
if ($pollId <= 0 || $optionIdx < 0) chat_json_error('bad_request', 'Bad poll vote payload.', 400);

// Resolve the poll and confirm it lives in the user's workspace.
$st = $pdo->prepare('SELECT * FROM chat_polls WHERE id = ? AND workspace_id = ?');
$st->execute([$pollId, $ws]);
$poll = $st->fetch();
if (!$poll) chat_json_error('not_found', 'Poll not found.', 404);

$opts = json_decode((string)$poll['options_json'], true) ?: [];
if ($optionIdx >= count($opts)) chat_json_error('bad_request', 'Option out of range.', 400);

$pdo->beginTransaction();
try {
  // Toggle behaviour: if the user has already voted for this option, remove
  // that vote. Otherwise clear any other vote on this poll and add the new one.
  $exists = $pdo->prepare('SELECT 1 FROM chat_poll_votes WHERE poll_id = ? AND user_id = ? AND option_idx = ?');
  $exists->execute([$pollId, $uid, $optionIdx]);
  if ($exists->fetchColumn()) {
    $pdo->prepare('DELETE FROM chat_poll_votes WHERE poll_id = ? AND user_id = ? AND option_idx = ?')
      ->execute([$pollId, $uid, $optionIdx]);
  } else {
    if (empty($poll['multi'])) {
      $pdo->prepare('DELETE FROM chat_poll_votes WHERE poll_id = ? AND user_id = ?')->execute([$pollId, $uid]);
    }
    $pdo->prepare('INSERT INTO chat_poll_votes (poll_id, user_id, option_idx, voted_at) VALUES (?, ?, ?, ?)')
      ->execute([$pollId, $uid, $optionIdx, now()]);
  }
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  chat_json_error('server_error', $e->getMessage(), 500);
}

// Fresh counts.
$counts = [];
$cs = $pdo->prepare('SELECT option_idx, COUNT(*) AS n FROM chat_poll_votes WHERE poll_id = ? GROUP BY option_idx');
$cs->execute([$pollId]);
foreach ($cs->fetchAll() as $r) $counts[(int)$r['option_idx']] = (int)$r['n'];
$total = array_sum($counts);

chat_json(['ok' => true, 'counts' => $counts, 'total' => $total]);
