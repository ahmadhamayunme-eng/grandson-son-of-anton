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
