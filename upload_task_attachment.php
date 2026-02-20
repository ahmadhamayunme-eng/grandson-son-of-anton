<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/task_attachments.php';
auth_require_login();
require_post();
csrf_verify();

const APP_ATTACHMENT_MAX_BYTES = 1024 * 1024 * 1024; // 1GB

$pdo = db();
$ws = auth_workspace_id();
$u = auth_user();
$task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
if ($task_id<=0) { flash_set('error','Invalid task'); redirect('dashboard.php'); }

if (!ensure_task_attachments_table($pdo)) {
  flash_set('error','Attachments table is unavailable. Please run DB migrations or grant CREATE TABLE permission.');
  redirect('task_view.php?id='.$task_id);
}

// Verify task belongs to workspace
$ok = $pdo->prepare("SELECT 1 FROM tasks WHERE id=? AND workspace_id=?");
$ok->execute([$task_id,$ws]);
if (!$ok->fetchColumn()) { http_response_code(403); echo 'Forbidden'; exit; }

if (!isset($_FILES['file'])) {
  flash_set('error','No file uploaded');
  redirect('task_view.php?id='.$task_id);
}

$f = $_FILES['file'];
if ((isset($f['error']) ? $f['error'] : UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  $err = (int)$f['error'];
  $effective = effective_upload_limit_bytes();
  if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    $msg = 'Upload exceeds server limit';
    if ($effective > 0) $msg .= ' (current limit: '.human_bytes($effective).')';
    $msg .= '. Ask server admin to set upload_max_filesize/post_max_size to at least 600M (recommended 1G).';
    flash_set('error', $msg);
  } elseif ($err === UPLOAD_ERR_PARTIAL) {
    flash_set('error','Upload was interrupted. Please retry.');
  } else {
    flash_set('error','Upload failed. Error code: '.$err);
  }
  redirect('task_view.php?id='.$task_id);
}

$orig = trim((string)(isset($f['name']) ? $f['name'] : ''));
$orig = $orig !== '' ? basename($orig) : 'attachment.bin';
$size = (int)(isset($f['size']) ? $f['size'] : 0);

$mime = null;
if (function_exists('finfo_open')) {
  $fi = finfo_open(FILEINFO_MIME_TYPE);
  if ($fi) {
    $mime = finfo_file($fi, $f['tmp_name']) ?: null;
    finfo_close($fi);
  }
}

if ($size <= 0) {
  flash_set('error','Empty file cannot be uploaded');
  redirect('task_view.php?id='.$task_id);
}
if ($size > APP_ATTACHMENT_MAX_BYTES) {
  flash_set('error','File too large. Maximum allowed is '.human_bytes(APP_ATTACHMENT_MAX_BYTES).' per file.');
  redirect('task_view.php?id='.$task_id);
}

$uploadDir = __DIR__ . '/uploads/task_attachments';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
  flash_set('error','Could not create upload directory');
  redirect('task_view.php?id='.$task_id);
}

$ext = pathinfo($orig, PATHINFO_EXTENSION);
$safeExt = preg_replace('/[^a-zA-Z0-9]/','', (string)$ext);
$stored = bin2hex(random_bytes(16)).($safeExt ? '.'.$safeExt : '');
$dest = $uploadDir . '/' . $stored;

if (!move_uploaded_file($f['tmp_name'], $dest)) {
  flash_set('error','Could not save file');
  redirect('task_view.php?id='.$task_id);
}

try {
  $pdo->prepare("INSERT INTO task_attachments (workspace_id,task_id,uploaded_by,original_name,stored_name,mime_type,size_bytes,created_at)
    VALUES (?,?,?,?,?,?,?,NOW())")
    ->execute([$ws,$task_id,(int)$u['id'],$orig,$stored,$mime,$size]);
  flash_set('success','File uploaded');
} catch (Exception $e) {
  @unlink($dest);
  flash_set('error','Upload saved file but DB insert failed. Please ensure task_attachments table is accessible to app DB user.');
}
redirect('task_view.php?id='.$task_id);
