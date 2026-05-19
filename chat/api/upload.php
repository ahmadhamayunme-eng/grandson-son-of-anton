<?php
// /chat/api/upload.php — single-file upload endpoint (Phase 6).
//
// Accepts: multipart/form-data with one "file" field, plus the X-CSRF-Token
// header. Validates MIME (via finfo) + extension matches MIME + size <= 25 MB.
// Stores the file under chat/uploads/YYYY-MM/<random>.<ext> with random
// filename, inserts a chat_files row with message_id = NULL (staged),
// returns { file: { id, name, mime, size, is_image } }.
//
// The file is invisible to anyone except the uploader until messages.php
// claims it on send (UPDATE chat_files SET message_id = N WHERE id IN ...).
// Files are never served by direct URL — chat/uploads/.htaccess denies all
// direct access; reads go through chat/api/file.php.

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

// 25 MB cap (matches spec). Defence-in-depth — php.ini also caps via
// upload_max_filesize / post_max_size, which Hostinger usually sets
// higher than 25 MB.
$maxBytes = 25 * 1024 * 1024;
$size     = (int)$f['size'];
if ($size <= 0)         chat_json_error('bad_request', 'Empty file.', 400);
if ($size > $maxBytes)  chat_json_error('too_large',   'File exceeds 25 MB.', 400);

// Sniff the real MIME via finfo (don't trust the browser-supplied type).
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string)$finfo->file($f['tmp_name']);

$origName  = (string)$f['name'];
$extLower  = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

// MIME allow-list. Anything not here is rejected.
$allowed = [
  // images
  'image/jpeg'       => ['jpg', 'jpeg'],
  'image/png'        => ['png'],
  'image/gif'        => ['gif'],
  'image/webp'       => ['webp'],
  // documents
  'application/pdf'  => ['pdf'],
  'application/msword' => ['doc'],
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
  'application/vnd.ms-excel' => ['xls'],
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
  'application/vnd.ms-powerpoint' => ['ppt'],
  'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
  // text
  'text/plain'       => ['txt', 'md', 'log'],
  'text/csv'         => ['csv'],
  'text/markdown'    => ['md'],
  // archives
  'application/zip'  => ['zip'],
];

if (!isset($allowed[$mime])) {
  chat_json_error('bad_type', "Files of type \"$mime\" aren't allowed.", 400);
}
if (!in_array($extLower, $allowed[$mime], true)) {
  chat_json_error('bad_ext', 'File extension doesn\'t match the actual file type.', 400);
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
