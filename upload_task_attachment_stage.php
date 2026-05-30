<?php
// upload_task_attachment_stage.php — staged, single-file upload endpoint for
// the "New task" popups (attach-on-create). Mirrors chat/api/upload.php so the
// UX (live XHR progress bar, any file type, 500 MB cap) matches Anton chat.
//
// Flow:
//   1. The popup uploads each chosen file here the moment it is selected.
//   2. We store the bytes under uploads/task_attachments/<random>.<ext> and
//      insert a task_attachments row with task_id = NULL (staged), owned by the
//      uploader. We return { file: { id, name, mime, size, is_image } }.
//   3. The popup keeps each returned id in a hidden <input name="attachment_ids[]">.
//   4. When the task is created, the create handler claims the staged rows
//      (claim_staged_task_attachments) by setting task_id to the new task.
//
// Security / defence-in-depth (any file type is accepted, like chat):
//   * Stored with a random name + sanitised extension — never the user's name.
//   * uploads/task_attachments/.htaccess denies direct browser access.
//   * download.php gates reads behind workspace membership, always sends
//     Content-Disposition: attachment + X-Content-Type-Options: nosniff, so an
//     uploaded .php/.html can never be executed or rendered.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/task_attachments.php';

header('Content-Type: application/json; charset=utf-8');

function stage_json(array $d, int $code = 200): never {
  http_response_code($code);
  echo json_encode($d);
  exit;
}
function stage_err(string $msg, int $code = 400): never {
  stage_json(['ok' => false, 'message' => $msg], $code);
}

$user = auth_user();
if (!$user) stage_err('Not signed in.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') stage_err('POST required.', 405);

// CSRF: accept the token from the X-CSRF-Token header (XHR) or a posted field.
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) stage_err('Invalid CSRF token.', 403);

$ws  = (int)($user['workspace_id'] ?? 0);
$uid = (int)($user['id'] ?? 0);

if (empty($_FILES['file']) || !is_array($_FILES['file'])) stage_err('No file uploaded.', 400);
$f = $_FILES['file'];

if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  $err = (int)$f['error'];
  $msg = match ($err) {
    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE      => 'File is too large for the server limit.',
    UPLOAD_ERR_PARTIAL                             => 'Upload was interrupted. Please retry.',
    UPLOAD_ERR_NO_FILE                             => 'No file was selected.',
    UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE   => 'Server upload directory error — tell an admin.',
    default                                        => 'Upload failed (code ' . $err . ').',
  };
  stage_err($msg, 400);
}

// 500 MB cap (matches Anton chat). .user.ini raises upload_max_filesize /
// post_max_size to 1024M so this app-level cap is the real bottleneck.
$maxBytes = 500 * 1024 * 1024;
$size     = (int)($f['size'] ?? 0);
if ($size <= 0)        stage_err('Empty file cannot be uploaded.', 400);
if ($size > $maxBytes) stage_err('File exceeds the 500 MB limit.', 400);

// Make sure a staged (task_id = NULL) row can be inserted.
if (!ensure_task_attachments_staging($pdo = db())) {
  stage_err('Attachments are unavailable. Ask an admin to run database migrations (php migrate.php).', 500);
}

// Sniff the real MIME (don't trust the browser); used only to populate the row
// and to decide inline-vs-attachment on download. Any MIME is accepted.
$mime = null;
if (class_exists('finfo')) {
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = (string)$finfo->file($f['tmp_name']);
}
if (!$mime) $mime = 'application/octet-stream';

$origName = trim((string)($f['name'] ?? ''));
$origName = $origName !== '' ? basename($origName) : 'attachment.bin';
$extLower = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if ($extLower === '' || !preg_match('/^[a-z0-9]{1,12}$/', $extLower)) {
  $extLower = 'bin';
}

$uploadDir = __DIR__ . '/uploads/task_attachments';
if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
  stage_err('Could not create upload directory.', 500);
}
upload_dir_protect($uploadDir);

$stored = bin2hex(random_bytes(16)) . '.' . $extLower;
$dest   = $uploadDir . '/' . $stored;

if (!@move_uploaded_file($f['tmp_name'], $dest)) {
  stage_err('Could not store the uploaded file.', 500);
}
@chmod($dest, 0644);

try {
  $pdo->prepare(
    "INSERT INTO task_attachments
       (workspace_id, task_id, uploaded_by, original_name, stored_name, mime_type, size_bytes, created_at)
     VALUES (?, NULL, ?, ?, ?, ?, ?, ?)"
  )->execute([$ws, $uid, $origName, $stored, $mime, $size, now()]);
  $fileId = (int)$pdo->lastInsertId();
} catch (Exception $e) {
  @unlink($dest);
  app_log('upload_task_attachment_stage', $e, ['ws' => $ws]);
  stage_err('Saved the file but the database insert failed. Ask an admin to run migrations.', 500);
}

stage_json(['ok' => true, 'file' => [
  'id'       => $fileId,
  'name'     => $origName,
  'mime'     => $mime,
  'size'     => $size,
  'is_image' => strpos($mime, 'image/') === 0 ? 1 : 0,
]], 201);
