<?php
function db() : PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $config = require __DIR__ . '/../config.php';
  $db = $config['db'];
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
