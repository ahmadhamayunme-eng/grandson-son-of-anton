<?php

function ensure_task_attachments_table(PDO $pdo): bool {
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

    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function ini_bytes(string $val): int {
  $val = trim($val);
  if ($val === '') return 0;
  $unit = strtolower(substr($val, -1));
  $num = (float)$val;
  return match ($unit) {
    'g' => (int)($num * 1024 * 1024 * 1024),
    'm' => (int)($num * 1024 * 1024),
    'k' => (int)($num * 1024),
    default => (int)$num,
  };
}

function effective_upload_limit_bytes(): int {
  $u = ini_bytes((string)ini_get('upload_max_filesize'));
  $p = ini_bytes((string)ini_get('post_max_size'));
  if ($u <= 0 && $p <= 0) return 0;
  if ($u <= 0) return $p;
  if ($p <= 0) return $u;
  return min($u, $p);
}

function human_bytes(int $bytes): string {
  if ($bytes >= 1024 * 1024 * 1024) return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
  if ($bytes >= 1024 * 1024) return round($bytes / (1024 * 1024), 2) . ' MB';
  if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
  return $bytes . ' B';
}
