<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function auth_user(): ?array { return $_SESSION['user'] ?? null; }
function auth_require_login(): void { if (!auth_user()) redirect('login.php'); }
function auth_require_any(array $roleNames): void {
  auth_require_login();
  $r = auth_user()['role_name'] ?? '';
  if (!in_array($r, $roleNames, true)) { http_response_code(403); echo "Forbidden"; exit; }
}
function auth_can_finance(): bool {
  $r = auth_user()['role_name'] ?? '';
  return in_array($r, ['CEO','CFO','CTO','Super Admin'], true);
}
function auth_workspace_id(): int { return (int)(auth_user()['workspace_id'] ?? 0); }


function auth_has_rbac_tables(): bool {
  try {
    $pdo = db();
    $pdo->query("SELECT 1 FROM permissions LIMIT 1");
    $pdo->query("SELECT 1 FROM role_permissions LIMIT 1");
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function auth_can(string $permKey): bool {
  $u = auth_user();
  if (!$u) return false;
  if (($u['role_name'] ?? '') === 'Super Admin') return true;

  if (!auth_has_rbac_tables()) {
    if ($permKey === 'finance.view') return auth_can_finance();
    return true;
  }

  $pdo = db();
  $stmt = $pdo->prepare("
    SELECT 1
    FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE rp.role_id = ? AND p.perm_key = ? AND rp.is_allowed = 1
    LIMIT 1
  ");
  $stmt->execute([(int)$u['role_id'], $permKey]);
  return (bool)$stmt->fetchColumn();
}

function auth_require_perm(string $permKey): void {
  auth_require_login();
  if (!auth_can($permKey)) { http_response_code(403); echo "Forbidden"; exit; }
}

function auth_login(string $email, string $password, bool $superOnly=false): bool {
  $pdo = db();
  $stmt = $pdo->prepare("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? AND u.is_active=1 LIMIT 1");
  $stmt->execute([$email]);
  $u = $stmt->fetch();
  if (!$u) return false;
  if ($superOnly && $u['role_name'] !== 'Super Admin') return false;
  if (!password_verify($password, $u['password_hash'])) return false;

  session_regenerate_id(true);
  $_SESSION['user'] = [
    'id' => (int)$u['id'],
    'workspace_id' => (int)$u['workspace_id'],
    'role_id' => (int)$u['role_id'],
    'role_name' => $u['role_name'],
    'name' => $u['name'],
    'email' => $u['email'],
  ];
  return true;
}
function auth_logout(): void {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
}
