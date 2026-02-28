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
      task_id INT NOT NULL,
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
        task_id INT NOT NULL,
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
        case 'task_id': $sql = "ALTER TABLE task_attachments ADD COLUMN task_id INT NOT NULL DEFAULT 0"; break;
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
