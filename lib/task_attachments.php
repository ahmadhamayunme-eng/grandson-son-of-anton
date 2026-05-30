<?php

function table_exists_quick($pdo, $table) {
  try {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
  } catch (Exception $e) {
    return false;
  }
}

function list_columns($pdo, $table) {
  try {
    $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[] = (string)(isset($r['Field']) ? $r['Field'] : '');
    return array_values(array_filter($out));
  } catch (Exception $e) {
    return [];
  }
}


function can_use_task_attachments_by_query($pdo) {
  try {
    $pdo->query("SELECT id,workspace_id,task_id,uploaded_by,original_name,stored_name,created_at FROM task_attachments LIMIT 1");
    return true;
  } catch (Exception $e) {
    return false;
  }
}

function ensure_task_attachments_table($pdo) {
  $required = ['id','workspace_id','task_id','uploaded_by','original_name','stored_name','created_at'];

  // 0) If table already exists and has required columns, do not require CREATE/ALTER privileges.
  if (table_exists_quick($pdo, 'task_attachments')) {
    $existingCols = list_columns($pdo, 'task_attachments');
    if (count(array_diff($required, $existingCols)) === 0) return true;
    // Some shared hosts restrict SHOW COLUMNS but still allow normal SELECT/INSERT usage.
    if (!$existingCols && can_use_task_attachments_by_query($pdo)) return true;
  }

  // 1) Try preferred schema (with FKs) only when needed.
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_attachments (
      id INT AUTO_INCREMENT PRIMARY KEY,
      workspace_id INT NOT NULL,
      task_id INT NULL,
      uploaded_by INT NOT NULL,
      original_name VARCHAR(255) NOT NULL,
      stored_name VARCHAR(255) NOT NULL,
      mime_type VARCHAR(120) NULL,
      size_bytes BIGINT NULL,
      created_at DATETIME NOT NULL,
      INDEX idx_ta_ws (workspace_id),
      INDEX idx_ta_task (task_id),
      CONSTRAINT fk_ta_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
      CONSTRAINT fk_ta_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
      CONSTRAINT fk_ta_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Exception $e) {
    // 2) Fallback schema (no FKs) for restrictive DB environments.
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS task_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id INT NOT NULL,
        task_id INT NULL,
        uploaded_by INT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(120) NULL,
        size_bytes BIGINT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_ta_ws (workspace_id),
        INDEX idx_ta_task (task_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e2) {
      // If CREATE is denied but table already exists, continue with validation path.
      if (!table_exists_quick($pdo, 'task_attachments')) return false;
    }
  }

  if (!table_exists_quick($pdo, 'task_attachments')) return false;

  // 3) Best-effort backfill of missing columns for older installs.
  $cols = list_columns($pdo, 'task_attachments');
  $missing = array_diff(
    ['workspace_id','task_id','uploaded_by','original_name','stored_name','mime_type','size_bytes','created_at'],
    $cols
  );

  foreach ($missing as $col) {
    try {
      $sql = null;
      switch ($col) {
        case 'workspace_id': $sql = "ALTER TABLE task_attachments ADD COLUMN workspace_id INT NOT NULL DEFAULT 0"; break;
        case 'task_id': $sql = "ALTER TABLE task_attachments ADD COLUMN task_id INT NULL"; break;
        case 'uploaded_by': $sql = "ALTER TABLE task_attachments ADD COLUMN uploaded_by INT NOT NULL DEFAULT 0"; break;
        case 'original_name': $sql = "ALTER TABLE task_attachments ADD COLUMN original_name VARCHAR(255) NOT NULL DEFAULT ''"; break;
        case 'stored_name': $sql = "ALTER TABLE task_attachments ADD COLUMN stored_name VARCHAR(255) NOT NULL DEFAULT ''"; break;
        case 'mime_type': $sql = "ALTER TABLE task_attachments ADD COLUMN mime_type VARCHAR(120) NULL"; break;
        case 'size_bytes': $sql = "ALTER TABLE task_attachments ADD COLUMN size_bytes BIGINT NULL"; break;
        case 'created_at': $sql = "ALTER TABLE task_attachments ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"; break;
      }
      if ($sql) $pdo->exec($sql);
    } catch (Exception $e) {
      // Ignore; we validate minimum columns below.
    }
  }

  $cols = list_columns($pdo, 'task_attachments');
  if ($cols) return count(array_diff($required, $cols)) === 0;
  return can_use_task_attachments_by_query($pdo);
}

/**
 * Attach-on-create support: make sure task_id is nullable so a file can be
 * staged (task_id = NULL) before its task row exists. Best-effort and
 * idempotent — on hosts that already ran migration 0008, or that deny ALTER,
 * this simply no-ops. Called by the staging upload endpoint as a safety net
 * for installs that haven't run `php migrate.php` yet.
 */
function ensure_task_attachments_staging($pdo): bool {
  if (!ensure_task_attachments_table($pdo)) return false;
  // If a staged (task_id IS NULL) insert already works we are done; probe
  // cheaply without writing by inspecting the column when SHOW COLUMNS is
  // available, otherwise just attempt the ALTER and tolerate failure.
  try {
    $rows = $pdo->query("SHOW COLUMNS FROM task_attachments LIKE 'task_id'")->fetchAll();
    $isNullable = isset($rows[0]['Null']) && strtoupper((string)$rows[0]['Null']) === 'YES';
    if ($isNullable) return true;
  } catch (Exception $e) {
    // SHOW COLUMNS restricted; fall through to the best-effort ALTER.
  }
  try {
    $pdo->exec("ALTER TABLE task_attachments MODIFY COLUMN task_id INT NULL");
    return true;
  } catch (Exception $e) {
    // ALTER denied. Staged inserts will fail loudly in the endpoint, which
    // surfaces a clear "run migrations" message to the admin.
    return false;
  }
}

/**
 * Claim previously-staged attachments for a freshly-created task. Only rows
 * that are still unclaimed (task_id IS NULL), owned by the same uploader, and
 * in the same workspace can be claimed — so one user can never attach another
 * user's staged file, and a file can never be re-pointed at a second task.
 *
 * @param int[] $ids  Candidate task_attachments.id values from the form.
 * @return int Number of rows actually claimed.
 */
function claim_staged_task_attachments($pdo, int $ws, int $uid, int $taskId, array $ids): int {
  $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
  if (!$ids || $taskId <= 0) return 0;
  try {
    if (!ensure_task_attachments_table($pdo)) return 0;
    $place = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE task_attachments
              SET task_id = ?
            WHERE task_id IS NULL
              AND uploaded_by = ?
              AND workspace_id = ?
              AND id IN ($place)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$taskId, $uid, $ws], $ids));
    return $stmt->rowCount();
  } catch (Exception $e) {
    app_log('claim_staged_task_attachments', $e, ['task_id' => $taskId]);
    return 0;
  }
}

function ini_bytes($val) {
  $val = trim($val);
  if ($val === '') return 0;
  $unit = strtolower(substr($val, -1));
  $num = (float)$val;
  switch ($unit) {
    case 'g': return (int)($num * 1024 * 1024 * 1024);
    case 'm': return (int)($num * 1024 * 1024);
    case 'k': return (int)($num * 1024);
    default: return (int)$num;
  }
}

function effective_upload_limit_bytes() {
  $u = ini_bytes((string)ini_get('upload_max_filesize'));
  $p = ini_bytes((string)ini_get('post_max_size'));
  if ($u <= 0 && $p <= 0) return 0;
  if ($u <= 0) return $p;
  if ($p <= 0) return $u;
  return min($u, $p);
}

function human_bytes($bytes) {
  if ($bytes >= 1024 * 1024 * 1024) return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
  if ($bytes >= 1024 * 1024) return round($bytes / (1024 * 1024), 2) . ' MB';
  if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
  return $bytes . ' B';
}

/**
 * Upload hardening (item #4). Writes a deny-all .htaccess into an upload
 * directory so nothing in it is ever executed or browsed directly (files are
 * served through gated PHP endpoints like download.php / chat/api/file.php).
 * Mirrors chat/uploads/.htaccess. Created at runtime because uploads/ is
 * gitignored. Idempotent.
 */
function upload_dir_protect(string $dir): void {
  $ht = $dir . '/.htaccess';
  if (is_file($ht)) return;
  @file_put_contents($ht,
    "Options -Indexes\n" .
    "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
    "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n"
  );
}

/**
 * Hardening for upload dirs whose files ARE served directly by URL (e.g.
 * profile pictures, client logos): disable script execution + directory
 * listing while still allowing the images themselves to be fetched. Idempotent.
 */
function upload_dir_protect_static(string $dir): void {
  $ht = $dir . '/.htaccess';
  if (is_file($ht)) return;
  @file_put_contents($ht,
    "Options -Indexes -ExecCGI\n" .
    "AddHandler cgi-script .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pht .pl .py .cgi\n" .
    "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pht\n" .
    "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n" .
    "<FilesMatch \"\\.(php[0-9]?|phtml|phar|pht|cgi|pl|py|sh|asp|aspx|jsp|exe|js|svg|html?)$\">\n" .
    "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n" .
    "  <IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n  </IfModule>\n" .
    "</FilesMatch>\n"
  );
}

/**
 * Returns true if an extension is unsafe to store (executable / server-script /
 * active-content types). Belt-and-braces alongside random renaming and the
 * deny-all .htaccess — defense in depth.
 */
function upload_is_dangerous_ext(string $ext): bool {
  $ext = strtolower(ltrim($ext, '.'));
  static $blocked = [
    'php','php2','php3','php4','php5','php7','php8','phtml','pht','phar','phps',
    'cgi','pl','py','rb','sh','bash','asp','aspx','jsp','jspx','cfm',
    'exe','com','bat','cmd','msi','scr','dll',
    'htaccess','htpasswd','ini','conf',
    'svg','svgz','xhtml','shtml','xht',
    'js','mjs','jse','vbs','wsf','hta',
  ];
  return in_array($ext, $blocked, true);
}

/**
 * Returns true if a sniffed MIME type is unsafe (active content). Used to
 * reject files whose bytes look like script/markup regardless of extension.
 */
function upload_is_dangerous_mime(?string $mime): bool {
  if (!$mime) return false;
  $mime = strtolower($mime);
  static $blocked = [
    'text/html','application/xhtml+xml','image/svg+xml',
    'application/x-httpd-php','application/x-php','text/x-php',
    'application/javascript','text/javascript','application/x-sh',
    'application/x-executable','application/x-dosexec','application/x-msdownload',
  ];
  return in_array($mime, $blocked, true);
}
