<?php
// /chat/api/upload.php — single-file upload endpoint.
//
// Accepts: multipart/form-data with one "file" field, plus the X-CSRF-Token
// header. Per user directive, no MIME / extension restrictions — any file
// type is accepted up to a hard 500 MB cap.
//
// Defence-in-depth still applies:
//   * Files are stored with a random name + their original extension.
//   * chat/uploads/.htaccess denies direct browser access entirely.
//   * chat/api/file.php gates reads behind workspace + channel membership
//     and always emits "Content-Disposition: attachment" (except images,
//     which are inline-displayed) plus "X-Content-Type-Options: nosniff",
//     so an uploaded .php or .html file can never be executed/rendered.
//
// Stores under chat/uploads/YYYY-MM/<random>.<ext>, inserts a chat_files
// row with message_id = NULL (staged), returns
// { file: { id, name, mime, size, is_image } }.
//
// The file is invisible to anyone except the uploader until messages.php
// claims it on send (UPDATE chat_files SET message_id = N WHERE id IN ...).

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = chat_api_require_login();
chat_api_require_post();
chat_api_require_csrf();

$ws  = (int)$user['workspace_id'];
$uid = (int)$user['id'];

if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
  chat_json_error('bad_request', 'No file uploaded.', 400);
}
$f = $_FILES['file'];

// Map PHP upload errors to user-friendly messages.
if ($f['error'] !== UPLOAD_ERR_OK) {
  $msg = match ($f['error']) {
    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large.',
    UPLOAD_ERR_PARTIAL                        => 'Upload was interrupted.',
    UPLOAD_ERR_NO_FILE                        => 'No file was selected.',
    UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Server upload directory error — tell an admin.',
    default                                   => 'Upload failed.',
  };
  chat_json_error('upload_error', $msg, 400);
}

// 500 MB cap (user-configured). php.ini (.user.ini) already raises
// upload_max_filesize / post_max_size to 1024M so this is the bottleneck.
$maxBytes = 500 * 1024 * 1024;
$size     = (int)$f['size'];
if ($size <= 0)         chat_json_error('bad_request', 'Empty file.', 400);
if ($size > $maxBytes)  chat_json_error('too_large',   'File exceeds 500 MB.', 400);

// Sniff the real MIME via finfo (don't trust the browser-supplied type).
// We accept any MIME — finfo is used only to populate the chat_files row
// and to decide inline-vs-attachment on download.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string)$finfo->file($f['tmp_name']);
if ($mime === '' || $mime === false) $mime = 'application/octet-stream';

$origName = (string)$f['name'];
$extLower = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
// Sanitize the extension to something filesystem-safe + a sensible cap.
// Anything weird (no extension, embedded slashes, very long) becomes "bin".
if ($extLower === '' || !preg_match('/^[a-z0-9]{1,12}$/', $extLower)) {
  $extLower = 'bin';
}

// Disk path: chat/uploads/YYYY-MM/<random>.<ext>
$subdir   = date('Y-m');
$baseDir  = __DIR__ . '/../uploads';
$diskDir  = $baseDir . '/' . $subdir;
if (!is_dir($diskDir) && !@mkdir($diskDir, 0775, true) && !is_dir($diskDir)) {
  chat_json_error('server_error', 'Could not create upload directory.', 500);
}
$randomName = bin2hex(random_bytes(16)) . '.' . $extLower;
$storedRel  = $subdir . '/' . $randomName;
$storedAbs  = $diskDir . '/' . $randomName;

if (!@move_uploaded_file($f['tmp_name'], $storedAbs)) {
  chat_json_error('server_error', 'Could not store the uploaded file.', 500);
}
@chmod($storedAbs, 0644);

$now = now();
$pdo = db();
$stmt = $pdo->prepare(
  'INSERT INTO chat_files
     (workspace_id, message_id, user_id, original_name, stored_name, mime_type, size_bytes, created_at)
   VALUES (?, NULL, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([$ws, $uid, $origName, $storedRel, $mime, $size, $now]);
$fileId = (int)$pdo->lastInsertId();

chat_json(['file' => [
  'id'       => $fileId,
  'name'     => $origName,
  'mime'     => $mime,
  'size'     => $size,
  'is_image' => strpos($mime, 'image/') === 0 ? 1 : 0,
]], 201);
