<?php
/**
 * Database migration entry point (item #16).
 *
 * CLI:  php migrate.php          (run from the project root on the server)
 * Web:  /migrate.php             (Super Admin only)
 *
 * Safe to run repeatedly — already-applied migrations are skipped.
 */
require_once __DIR__ . '/lib/migrate.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
  session_start();
  require_once __DIR__ . '/lib/auth.php';
  auth_require_login();
  if ((auth_user()['role_name'] ?? '') !== 'Super Admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }
  header('Content-Type: text/plain; charset=utf-8');
}

try {
  $r = migrations_run();
} catch (Throwable $e) {
  http_response_code(500);
  echo "Migration failed: " . $e->getMessage() . "\n";
  exit(1);
}

echo "Applied:  " . (count($r['applied']) ? implode(', ', $r['applied']) : '(none)') . "\n";
echo "Skipped:  " . (count($r['skipped']) ? implode(', ', $r['skipped']) : '(none)') . "\n";
if ($r['errors']) {
  echo "Tolerated statement errors (" . count($r['errors']) . "):\n";
  foreach ($r['errors'] as $err) {
    echo "  - {$err['version']}: {$err['error']}\n";
  }
}
echo "Done.\n";
