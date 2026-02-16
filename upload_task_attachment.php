<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();
require_post();
csrf_verify();

$pdo = db();
$ws = auth_workspace_id();
$u = auth_user();
$task_id = (int)($_POST['task_id'] ?? 0);
if ($task_id<=0) { flash_set('error','Invalid task'); redirect('dashboard.php'); }

// Verify task belongs to workspace
$ok = $pdo->prepare("SELECT 1 FROM tasks WHERE id=? AND workspace_id=?");
$ok->execute([$task_id,$ws]);
if (!$ok->fetchColumn()) { http_response_code(403); echo 'Forbidden'; exit; }

if (!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK) {
  flash_set('error','Upload failed');
  redirect('task_view.php?id='.$task_id);
}

$f = $_FILES['file'];
$orig = basename($f['name']);
$size = (int)$f['size'];
$mime = $f['type'] ?? null;

// basic limits: 25MB
if ($size > 25*1024*1024) {
  flash_set('error','File too large (max 25MB)');
  redirect('task_view.php?id='.$task_id);
}

$ext = pathinfo($orig, PATHINFO_EXTENSION);
$stored = bin2hex(random_bytes(16)).($ext ? '.'.preg_replace('/[^a-zA-Z0-9]/','',$ext) : '');
$dest = __DIR__ . '/uploads/task_attachments/' . $stored;

if (!move_uploaded_file($f['tmp_name'], $dest)) {
  flash_set('error','Could not save file');
  redirect('task_view.php?id='.$task_id);
}

$pdo->prepare("INSERT INTO task_attachments (workspace_id,task_id,uploaded_by,original_name,stored_name,mime_type,size_bytes,created_at)
  VALUES (?,?,?,?,?,?,?,NOW())")
  ->execute([$ws,$task_id,(int)$u['id'],$orig,$stored,$mime,$size]);

flash_set('success','File uploaded');
redirect('task_view.php?id='.$task_id);
