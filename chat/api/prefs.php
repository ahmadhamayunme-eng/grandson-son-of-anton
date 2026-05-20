<?php
// /chat/api/prefs.php — chat user preferences (Phase 9).
//
//   GET  ?action=get
//        Returns the user's current prefs (timezone, notify_dm,
//        notify_mention, enter_to_send). Defaults applied if no row.
//
//   POST ?action=update
//        Accepts any subset of: timezone, notify_dm, notify_mention,
//        enter_to_send. Upserts chat_user_prefs.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = chat_api_require_login();
$uid  = (int)$user['id'];
$pdo  = db();

$action = (string)($_GET['action'] ?? '');

switch ($action) {
  case 'get':
    chat_json(['prefs' => chat_load_user_prefs($uid)]);

  case 'update':
    chat_api_require_post();
    chat_api_require_csrf();

    $allowed = ['timezone', 'notify_dm', 'notify_mention', 'enter_to_send', 'dnd'];
    $values  = [];
    foreach ($allowed as $k) {
      if (!array_key_exists($k, $_POST)) continue;
      if ($k === 'timezone') {
        $tz = trim((string)$_POST[$k]);
        if (strlen($tz) === 0)  $tz = 'UTC';
        if (strlen($tz) > 64)   chat_json_error('bad_request', 'Timezone too long.', 400);
        $values[$k] = $tz;
      } else {
        // Three checkbox-style booleans — accept "1"/"0"/"true"/"false".
        $raw = strtolower(trim((string)$_POST[$k]));
        $values[$k] = ($raw === '1' || $raw === 'true' || $raw === 'on') ? 1 : 0;
      }
    }
    if (empty($values)) chat_json_error('bad_request', 'Nothing to update.', 400);

    $now  = now();
    $cols = ['user_id', 'updated_at'];
    $vals = [$uid, $now];
    $upd  = ['updated_at = VALUES(updated_at)'];
    foreach ($values as $k => $v) {
      $cols[] = $k;
      $vals[] = $v;
      $upd[]  = "$k = VALUES($k)";
    }
    $sql = 'INSERT INTO chat_user_prefs (' . implode(',', $cols) . ') VALUES ('
         . implode(',', array_fill(0, count($vals), '?')) . ') ON DUPLICATE KEY UPDATE '
         . implode(', ', $upd);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);

    chat_json(['ok' => true, 'prefs' => chat_load_user_prefs($uid)]);

  default:
    chat_json_error('unknown_action', "Unknown action: $action", 400);
}
