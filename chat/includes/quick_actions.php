<?php
// chat/includes/quick_actions.php — server-side parser for slash commands
// at the start of a chat message. Called from msg_action_send (chat/api/
// messages.php) BEFORE the message is inserted.
//
// Supported commands (case-insensitive prefix):
//   /task <title>                  — creates an AntonX task in the channel's
//                                    linked project (if there is one). The
//                                    message becomes the tracker card.
//   /share task#1234               — replace the message with a live task card
//   /share project <slug-or-id>    — project card
//   /share client #<id>            — client card
//   /share doc#<id>                — doc card
//   /poll "Q?" "A" "B" "C"         — inline poll; reactions become votes
//
// Returns an array with:
//   ['handled' => bool, 'content' => string, 'card' => array|null,
//    'linked' => ['type'=>..., 'id'=>...] | null,
//    'created' => ['type'=>'task','id'=>N] | null,
//    'poll' => ['question'=>..., 'options'=>[...]] | null]
// If $handled is false, the message proceeds unchanged.

require_once __DIR__ . '/helpers.php';

function chat_parse_quick_action(string $raw, int $workspaceId, int $authorUserId, ?int $channelId, ?int $conversationId): array {
  $stripped = trim(strip_tags($raw));
  if ($stripped === '' || $stripped[0] !== '/') {
    return ['handled' => false];
  }

  // Strip the wrapping <p> the WYSIWYG composer might add.
  $first = strtolower(preg_replace('/\s+/', ' ', $stripped));
  $tokens = explode(' ', $first, 2);
  $cmd  = $tokens[0] ?? '';
  $rest = $tokens[1] ?? '';
  $restOrig = trim(preg_replace('#^/\S+\s*#', '', $stripped));

  switch ($cmd) {
    case '/task':
      return _qa_task($restOrig, $workspaceId, $authorUserId, $channelId);
    case '/share':
      return _qa_share($restOrig, $workspaceId);
    case '/poll':
      return _qa_poll($restOrig);
  }
  return ['handled' => false];
}

function _qa_task(string $title, int $workspaceId, int $authorUserId, ?int $channelId): array {
  if (trim($title) === '') {
    return ['handled' => false];
  }
  $title = mb_substr(trim($title), 0, 220, 'UTF-8');

  // Find a project to attach to. If the channel slug is "proj-<slug>", that's
  // a strong signal. Otherwise pick the project the user belongs to with the
  // most tasks (fallback: first project in the workspace).
  $projectId = 0;
  try {
    $pdo = db();
    if ($channelId) {
      $st = $pdo->prepare('SELECT slug FROM chat_channels WHERE id = ?');
      $st->execute([$channelId]);
      $slug = (string)($st->fetchColumn() ?: '');
      if ($slug !== '' && strpos($slug, 'proj-') === 0) {
        $projSlug = substr($slug, 5);
        $ps = $pdo->prepare('SELECT id FROM projects WHERE workspace_id = ?');
        $ps->execute([$workspaceId]);
        foreach ($ps->fetchAll() as $row) {
          if (chat_slugify((string)$row['name']) === $projSlug) { $projectId = (int)$row['id']; break; }
        }
      }
    }
    if ($projectId <= 0) {
      $st = $pdo->prepare(
        'SELECT t.project_id, COUNT(*) AS n FROM tasks t
         JOIN task_assignees ta ON ta.task_id = t.id
         WHERE t.workspace_id = ? AND ta.user_id = ?
         GROUP BY t.project_id ORDER BY n DESC LIMIT 1'
      );
      $st->execute([$workspaceId, $authorUserId]);
      $projectId = (int)($st->fetchColumn() ?: 0);
    }
    if ($projectId <= 0) {
      $st = $pdo->prepare('SELECT id FROM projects WHERE workspace_id = ? ORDER BY id LIMIT 1');
      $st->execute([$workspaceId]);
      $projectId = (int)($st->fetchColumn() ?: 0);
    }
    if ($projectId <= 0) {
      // No project at all — bail. The message stays untouched.
      return ['handled' => false];
    }

    // First phase of the project.
    $st = $pdo->prepare('SELECT id FROM phases WHERE project_id = ? ORDER BY sort_order, id LIMIT 1');
    $st->execute([$projectId]);
    $phaseId = (int)($st->fetchColumn() ?: 0);
    if ($phaseId <= 0) return ['handled' => false];

    $now = now();
    $ins = $pdo->prepare(
      'INSERT INTO tasks
         (workspace_id, project_id, phase_id, title, description, status, created_by, created_at, updated_at)
       VALUES (?, ?, ?, ?, NULL, "To Do", ?, ?, ?)'
    );
    $ins->execute([$workspaceId, $projectId, $phaseId, $title, $authorUserId, $now, $now]);
    $taskId = (int)$pdo->lastInsertId();
    // Author auto-assigned.
    $pdo->prepare('INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?, ?)')
      ->execute([$taskId, $authorUserId]);

    $card = ['type' => 'task', 'id' => $taskId];
    $content = '<p>✅ Created <strong>' . h($title) . '</strong> as <a href="/task_details.php?id=' . $taskId . '">task#' . $taskId . '</a></p>';
    return [
      'handled' => true,
      'content' => $content,
      'card'    => $card,
      'linked'  => ['type' => 'task', 'id' => $taskId],
      'created' => ['type' => 'task', 'id' => $taskId],
      'poll'    => null,
    ];
  } catch (Throwable $e) {
    error_log('[Anton Jr.] /task failed: ' . $e->getMessage());
    return ['handled' => false];
  }
}

function _qa_share(string $arg, int $workspaceId): array {
  // Accept "task#1234", "task 1234", "project foo", "doc#5", "client#9".
  $arg = trim($arg);
  if ($arg === '') return ['handled' => false];
  if (!preg_match('/^(task|project|proj|client|doc)\s*#?\s*([a-z0-9_-]+)/i', $arg, $m)) {
    return ['handled' => false];
  }
  $type = strtolower($m[1]); if ($type === 'proj') $type = 'project';
  $key  = strtolower($m[2]);

  // Resolve to an id like the entity pill helper does.
  try {
    $pdo = db();
    $id = 0;
    switch ($type) {
      case 'task':
        $st = $pdo->prepare('SELECT id FROM tasks WHERE id = ? AND workspace_id = ?');
        $st->execute([(int)$key, $workspaceId]);
        $id = (int)$st->fetchColumn();
        break;
      case 'project':
        if (ctype_digit($key)) {
          $st = $pdo->prepare('SELECT id FROM projects WHERE id = ? AND workspace_id = ?');
          $st->execute([(int)$key, $workspaceId]);
          $id = (int)$st->fetchColumn();
        } else {
          $st = $pdo->prepare('SELECT id, name FROM projects WHERE workspace_id = ?');
          $st->execute([$workspaceId]);
          foreach ($st->fetchAll() as $r) {
            if (chat_slugify((string)$r['name']) === $key) { $id = (int)$r['id']; break; }
          }
        }
        break;
      case 'client':
        $st = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND workspace_id = ?');
        $st->execute([(int)$key, $workspaceId]);
        $id = (int)$st->fetchColumn();
        break;
      case 'doc':
        $st = $pdo->prepare('SELECT id FROM docs WHERE id = ? AND workspace_id = ?');
        $st->execute([(int)$key, $workspaceId]);
        $id = (int)$st->fetchColumn();
        break;
    }
    if ($id <= 0) return ['handled' => false];

    return [
      'handled' => true,
      'content' => '',
      'card'    => ['type' => $type, 'id' => $id],
      'linked'  => ['type' => $type, 'id' => $id],
      'created' => null,
      'poll'    => null,
    ];
  } catch (Throwable $e) {
    return ['handled' => false];
  }
}

function _qa_poll(string $rest): array {
  // Parse double-quoted segments: "question" "opt1" "opt2" "opt3"...
  if (!preg_match_all('/"([^"]+)"/', $rest, $m) || count($m[1]) < 3) {
    return ['handled' => false];
  }
  $question = trim($m[1][0]);
  $options  = array_values(array_filter(array_map('trim', array_slice($m[1], 1)), fn($s) => $s !== ''));
  if (count($options) < 2 || count($options) > 10) return ['handled' => false];
  return [
    'handled' => true,
    'content' => '<p><strong>📊 ' . h($question) . '</strong></p>',
    'card'    => null,
    'linked'  => null,
    'created' => null,
    'poll'    => ['question' => $question, 'options' => $options],
  ];
}
