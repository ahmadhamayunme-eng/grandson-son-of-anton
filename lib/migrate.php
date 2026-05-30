<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Ordered, versioned SQL migrations runner (item #16).
 *
 * Replaces the previous ad-hoc runtime schema probes (finance_ensure_schema,
 * ensure_task_attachments_table, tv_column_exists, SHOW COLUMNS checks) with a
 * single authoritative path. Those legacy probes remain as fallbacks this
 * release so installs that haven't run migrations keep working.
 *
 * Design notes:
 *  - A schema_migrations ledger records which files have been applied.
 *  - Each migration file is split into statements (reusing the same splitter
 *    style as db_exec_file in lib/db.php).
 *  - Per-statement failures are logged and skipped rather than aborting, which
 *    matches the resilience the app already relied on (shared hosts vary in
 *    support for "ADD COLUMN IF NOT EXISTS" / "CREATE INDEX IF NOT EXISTS").
 *  - Re-running is safe: applied versions are skipped; statements use
 *    IF NOT EXISTS where the engine supports it.
 *
 * @return array{applied: string[], skipped: string[], errors: array<int, array{version:string, error:string}>}
 */
function migrations_run(?PDO $pdo = null, ?string $dir = null): array {
  $pdo = $pdo ?: db();
  $dir = $dir ?: dirname(__DIR__) . '/migrations';

  $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $applied = [];
  foreach ($pdo->query("SELECT version FROM schema_migrations")->fetchAll() as $row) {
    $applied[(string)$row['version']] = true;
  }

  $files = glob($dir . '/*.sql') ?: [];
  sort($files, SORT_STRING);

  $result = ['applied' => [], 'skipped' => [], 'errors' => []];

  foreach ($files as $file) {
    $version = basename($file);
    if (isset($applied[$version])) { $result['skipped'][] = $version; continue; }

    $sql = file_get_contents($file);
    if ($sql === false) {
      $result['errors'][] = ['version' => $version, 'error' => 'unreadable file'];
      continue;
    }

    foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
      $stmt = trim($stmt);
      if ($stmt === '' || str_starts_with($stmt, '--')) continue;
      try {
        $pdo->exec($stmt);
      } catch (Throwable $e) {
        // Tolerated: e.g. duplicate index on an engine without IF NOT EXISTS.
        app_log('migrations_run', $e, ['version' => $version, 'sql' => substr($stmt, 0, 80)]);
        $result['errors'][] = ['version' => $version, 'error' => $e->getMessage()];
      }
    }

    $ins = $pdo->prepare("INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)");
    $ins->execute([$version, date('Y-m-d H:i:s')]);
    $result['applied'][] = $version;
  }

  return $result;
}
