<?php
/**
 * Resolve a config value, preferring an environment variable over the
 * (optional) config.php array. Keeping config.php out of version control
 * while supporting env-based secrets on the host (item #1).
 */
function cfg(string $envKey, $fallback = null) {
  $v = getenv($envKey);
  return ($v !== false && $v !== '') ? $v : $fallback;
}

function db() : PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  // config.php is optional now: env vars take precedence when set.
  $config = ['db' => [], 'app' => []];
  $configFile = __DIR__ . '/../config.php';
  if (is_file($configFile)) {
    $config = array_replace_recursive($config, (array)require $configFile);
  }
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
