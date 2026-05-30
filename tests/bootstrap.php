<?php
// PHPUnit bootstrap (item #21). Loads the DB-free helper libraries and sets up
// a fake session array so session-backed helpers (csrf/flash) are testable in
// CLI without a real session.
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) {
  $_SESSION = [];
}
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/task_attachments.php';
