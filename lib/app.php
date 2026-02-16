<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

function current_path(): string {
  $p = '';
  if (isset($_GET['path'])) {
    $p = (string)$_GET['path'];
  } else {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $p = ltrim((string)$uri, '/');
  }
  $p = preg_replace('~//+~', '/', trim($p));
  return trim($p, '/');
}

function path_segments(string $path): array {
  $path = trim($path, '/');
  return $path == '' ? [] : explode('/', $path);
}

function match_route(string $pattern, string $path, array &$params): bool {
  $params = [];
  $pSeg = path_segments($path);
  $rSeg = path_segments(trim($pattern, '/'));
  if (count($pSeg) != count($rSeg)) return False;
  for ($i = 0; $i < count($rSeg); $i++) {
    $r = $rSeg[$i];
    $p = $pSeg[$i];
    if (preg_match('/^{([a-zA-Z_][a-zA-Z0-9_]*)}$/', $r, $m)) {
      $params[$m[1]] = $p;
      continue;
    }
    if ($r !== $p) return False;
  }
  return True;
}

function require_roles(array $names): void {
  $u = auth_user();
  if (!$u) redirect('login.php');
  $role = $u['role_name'] ?? ($u['role'] ?? '');
  if (!in_array($role, $names, true)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }
}

function render_page(string $title, string $contentFile, array $vars = []): void {
  $GLOBALS['page_title'] = $title;
  extract($vars);
  include __DIR__ . '/../layout.php';
  include $contentFile;
  include __DIR__ . '/../layout_end.php';
}
