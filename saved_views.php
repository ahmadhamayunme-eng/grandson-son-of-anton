<?php
// Saved-views controller (item #13): create/delete a saved filter, then return
// to the originating list page.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/saved_views.php';
auth_require_login();
require_post();
csrf_verify();

$page   = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['page'] ?? '')));
$action = (string)($_POST['action'] ?? '');

// Only allow returning to a known same-origin list page.
$allowedPages = ['my_tasks', 'projects'];
if (!in_array($page, $allowedPages, true)) { redirect('dashboard.php'); }
$returnFile = $page . '.php';

if ($action === 'create') {
  $name = (string)($_POST['name'] ?? '');
  $qs   = (string)($_POST['query_string'] ?? '');
  $okSave = saved_view_create($page, $name, $qs);
  flash_set($okSave ? 'success' : 'error', $okSave ? 'View saved.' : 'Could not save view.');
} elseif ($action === 'delete') {
  saved_view_delete((int)($_POST['id'] ?? 0));
  flash_set('success', 'View removed.');
}

redirect($returnFile);
