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

function flash_set(string $key, string $msg): void { $_SESSION['flash'][$key] = $msg; }
function flash_get(string $key): ?string {
  if (!isset($_SESSION['flash'][$key])) return null;
  $m = $_SESSION['flash'][$key];
  unset($_SESSION['flash'][$key]);
  return $m;
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
