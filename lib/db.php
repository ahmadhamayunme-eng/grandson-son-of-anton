<?php
/**
 * Resolve a config value, preferring an environment variable over the
 * (optional) config file array. Keeping credentials out of version control
 * while supporting env-based secrets on the host (item #1).
 */
function cfg(string $envKey, $fallback = null) {
  $v = getenv($envKey);
  return ($v !== false && $v !== '') ? $v : $fallback;
}

/**
 * Load the credentials array from the first config file that exists.
 *
 * Deploys that do a clean checkout wipe anything not in the repo, so the
 * persistent place for secrets is OUTSIDE the deploy folder. We therefore
 * search (in order):
 *   1. $ANTONX_CONFIG            — explicit absolute path, if you set the env var
 *   2. <app>/config.php          — in-repo location (handy for local dev)
 *   3. <parent of app>/antonx-config.php   — one level above the app  ← survives deploys
 *   4. <grandparent>/antonx-config.php      — two levels above the app ← survives deploys
 */
function load_app_config(): array {
  $candidates = array_filter([
    getenv('ANTONX_CONFIG') ?: null,
    __DIR__ . '/../config.php',
    dirname(__DIR__, 2) . '/antonx-config.php',
    dirname(__DIR__, 3) . '/antonx-config.php',
  ]);
  foreach ($candidates as $path) {
    if ($path && is_file($path)) {
      return array_replace_recursive(['db' => [], 'app' => []], (array)require $path);
    }
  }
  return ['db' => [], 'app' => []];
}

function db() : PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  // Env vars win; otherwise fall back to whichever config file is found.
  $config = load_app_config();
  $fallback = $config['db'] ?? [];

  $db = [
    'host'    => cfg('DB_HOST',    $fallback['host']    ?? 'localhost'),
    'name'    => cfg('DB_NAME',    $fallback['name']    ?? ''),
    'user'    => cfg('DB_USER',    $fallback['user']    ?? ''),
    'pass'    => cfg('DB_PASS',    $fallback['pass']    ?? ''),
    'charset' => cfg('DB_CHARSET', $fallback['charset'] ?? 'utf8mb4'),
  ];

  $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
  $pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  return $pdo;
}
function db_exec_file(string $sqlFilePath): void {
  $sql = file_get_contents($sqlFilePath);
  if ($sql === false) throw new RuntimeException("Cannot read SQL file: $sqlFilePath");
  $pdo = db();
  $statements = preg_split('/;\s*\n/', $sql);
  foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '' || str_starts_with($stmt, '--')) continue;
    $pdo->exec($stmt);
  }
}
