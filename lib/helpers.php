<?php
function h($str): string { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
function redirect(string $to): never {
  $location = trim($to) !== '' ? $to : './';
  if (!headers_sent()) {
    header('Location: ' . $location, true, 303);
    exit;
  }

  $safe = h($location);
  echo '<script>window.location.replace(' . json_encode($location) . ');</script>';
  echo '<meta http-equiv="refresh" content="0;url=' . $safe . '">';
  echo '<a href="' . $safe . '">Continue</a>';
  exit;
}
function now(): string { return date('Y-m-d H:i:s'); }

/**
 * Centralized error logging. Writes a single line to storage/logs/app.log
 * (the storage/ tree is blocked from web access by the root .htaccess).
 * Callers should still keep their graceful UI fallback — this only records
 * the failure so it stops being invisible in production.
 */
function app_log(string $context, ?Throwable $e = null, array $extra = []): void {
  try {
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $parts = [date('c'), $context];
    if ($e) {
      $parts[] = get_class($e) . ': ' . $e->getMessage();
      $parts[] = $e->getFile() . ':' . $e->getLine();
    }
    if ($extra) $parts[] = json_encode($extra, JSON_UNESCAPED_SLASHES);
    $line = implode(' | ', $parts) . "\n";
    @error_log($line, 3, $dir . '/app.log');
  } catch (Throwable $ignore) {
    // Logging must never throw.
  }
}

function flash_set(string $key, string $msg): void { $_SESSION['flash'][$key] = $msg; }
function flash_get(string $key): ?string {
  if (!isset($_SESSION['flash'][$key])) return null;
  $m = $_SESSION['flash'][$key];
  unset($_SESSION['flash'][$key]);
  return $m;
}
/**
 * Send security headers from PHP as a fallback for hosts without mod_headers
 * (item #3). Mirrors the directives in the root .htaccess. No-op if headers
 * were already sent. Apache's mod_headers, when present, may set duplicates —
 * harmless, the browser honours the first.
 */
function app_send_security_headers(): void {
  if (headers_sent()) return;
  header('X-Frame-Options: SAMEORIGIN');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
  }
  header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

/**
 * Emit a workspace-wide realtime event (item #18) onto the existing chat_events
 * stream, with channel_id/conversation_id NULL so it broadcasts to the whole
 * workspace. Consumed by chat/api/events.php (SSE). Best-effort: wrapped so a
 * missing table or insert failure never blocks the mutation that called it.
 */
function app_emit_event(int $workspaceId, string $eventType, array $payload = [], ?int $actorUserId = null): void {
  try {
    db()->prepare(
      'INSERT INTO chat_events (workspace_id, event_type, channel_id, conversation_id, message_id, actor_user_id, payload, created_at)
       VALUES (?,?,NULL,NULL,NULL,?,?,NOW())'
    )->execute([$workspaceId, $eventType, $actorUserId, $payload ? json_encode($payload) : null]);
  } catch (Throwable $e) {
    app_log('app_emit_event', $e, ['type' => $eventType]);
  }
}

function require_post(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo "Method Not Allowed"; exit; }
}
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}
function csrf_verify(): void {
  $token = $_POST['csrf'] ?? '';
  if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) { http_response_code(403); echo "Invalid CSRF token"; exit; }
}
function format_date(?string $d): string { return $d ? date('Y-m-d', strtotime($d)) : ''; }


function user_initials(string $name): string {
  $name = trim($name);
  if ($name === '') return '?';
  $parts = preg_split('/\s+/', $name) ?: [];
  $first = strtoupper(substr((string)($parts[0] ?? ''), 0, 1));
  $last = '';
  if (count($parts) > 1) {
    $last = strtoupper(substr((string)$parts[count($parts)-1], 0, 1));
  } else {
    $last = strtoupper(substr((string)($parts[0] ?? ''), 1, 1));
  }
  return $first . $last;
}

function user_avatar_url(int $userId): ?string {
  if ($userId <= 0) return null;
  $root = dirname(__DIR__);
  $dir = $root . '/uploads/profile_pictures';
  foreach (['jpg','jpeg','png','webp','gif'] as $ext) {
    $file = $dir . '/' . $userId . '.' . $ext;
    if (is_file($file)) {
      return 'uploads/profile_pictures/' . $userId . '.' . $ext . '?v=' . (int)@filemtime($file);
    }
  }
  return null;
}

function user_avatar_html(int $userId, string $name, string $class = 'avatar'): string {
  $safeClass = trim($class);
  $safeName = h($name);
  $url = user_avatar_url($userId);
  if ($url) {
    return '<img src="' . h($url) . '" alt="' . $safeName . '" class="' . h($safeClass) . '">';
  }
  return '<span class="' . h($safeClass) . '">' . h(user_initials($name)) . '</span>';
}


function client_logo_url(int $clientId): ?string {
  if ($clientId <= 0) return null;
  $root = dirname(__DIR__);
  $dir = $root . '/uploads/client_logos';
  foreach (['png','jpg','jpeg','webp','gif','svg'] as $ext) {
    $file = $dir . '/' . $clientId . '.' . $ext;
    if (is_file($file)) {
      return 'uploads/client_logos/' . $clientId . '.' . $ext . '?v=' . (int)@filemtime($file);
    }
  }
  return null;
}

function client_logo_html(int $clientId, string $name, string $class='client-logo'): string {
  $url = client_logo_url($clientId);
  if ($url) {
    return '<img src="' . h($url) . '" alt="' . h($name) . '" class="' . h($class) . '">';
  }
  return '<span class="' . h($class) . '">' . h(user_initials($name)) . '</span>';
}

/**
 * Anton Jr. — render the user's chat-set status emoji + tooltip text as a
 * small inline chip. Empty string if the user has no current status or the
 * status_* columns don't exist yet (DB not migrated to Phase 10 status.sql).
 * Cached in-process to keep dashboard / user-list pages from doing N queries.
 *
 * Returns SAFE HTML, intended to be inlined next to a user's name.
 */
function user_status_chip(int $userId): string {
  if ($userId <= 0) return '';
  static $cache = null;
  if ($cache === null) {
    $cache = [];
    try {
      $rows = db()->query(
        "SELECT user_id, status_emoji, status_text
         FROM chat_user_prefs
         WHERE status_emoji IS NOT NULL
           AND (status_expires_at IS NULL OR status_expires_at > NOW())"
      )->fetchAll();
      foreach ($rows as $r) {
        $cache[(int)$r['user_id']] = [
          'emoji' => (string)$r['status_emoji'],
          'text'  => (string)($r['status_text'] ?? ''),
        ];
      }
    } catch (Throwable $e) {
      // Table or columns don't exist yet — degrade to empty, but record it.
      app_log('user_status_chip', $e);
      $cache = [];
    }
  }
  if (!isset($cache[$userId])) return '';
  $s = $cache[$userId];
  return '<span class="user-status-chip" title="' . h($s['text']) . '">' . h($s['emoji']) . '</span>';
}
