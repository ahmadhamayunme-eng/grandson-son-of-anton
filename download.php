<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/task_attachments.php';
auth_require_login();
$pdo = db();
$ws = auth_workspace_id();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare("SELECT * FROM task_attachments WHERE id=? AND workspace_id=?");
$stmt->execute([$id,$ws]);
$a = $stmt->fetch();
if (!$a) { http_response_code(404); echo 'Not found'; exit; }

$path = __DIR__ . '/uploads/task_attachments/' . $a['stored_name'];
if (!is_file($path)) { http_response_code(404); echo 'File missing'; exit; }

$mime = $a['mime_type'] ?: 'application/octet-stream';
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($path));
header('Content-Disposition: attachment; filename="'.preg_replace('/[^\w\-. ]/','_', $a['original_name']).'"');
readfile($path);
exit;
