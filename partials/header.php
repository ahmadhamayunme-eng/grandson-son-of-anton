<?php
require_once __DIR__ . '/../lib/helpers.php';
$config = file_exists(__DIR__ . '/../config.php') ? (require __DIR__ . '/../config.php') : ['app'=>['name'=>'AntonX']];
$pageTitle = $pageTitle ?? ($config['app']['name'] ?? 'AntonX');
$pageHeadExtra = $pageHeadExtra ?? '';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>anton x — <?=h($pageTitle)?></title>
  <link rel="icon" type="image/png" href="partials/antonx-favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles/anton.css">
  <!-- Bootstrap remains loaded as a compatibility shim for pages not yet rebuilt against the new design system. -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* Tone-down Bootstrap defaults so it blends with anton.css */
    body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
    .btn-yellow { background: var(--accent); border-color: var(--accent); color: #1a1400; font-weight: 600; }
    .btn-yellow:hover, .btn-yellow:focus { background: var(--accent-hover); border-color: var(--accent-hover); color: #1a1400; }
    .form-control, .form-select { background: var(--surface-2); border-color: var(--border); color: var(--text); }
    .form-control:focus, .form-select:focus { background: var(--bg); border-color: var(--accent); box-shadow: 0 0 0 0.15rem rgba(250,204,21,0.18); }
    .modal-content { background: var(--surface); border: 1px solid var(--border); color: var(--text); }
    .modal-header, .modal-footer { border-color: var(--border); }
    .table { color: var(--text); --bs-table-bg: transparent; --bs-table-color: var(--text); }
    .alert-success { background: var(--success-soft); border-color: rgba(34,197,94,0.25); color: #86efac; }
    .alert-danger { background: var(--danger-soft); border-color: rgba(239,68,68,0.25); color: #fca5a5; }
  </style>
  <?=$pageHeadExtra?>
</head>
<body>
<div class="app" data-screen-label="<?=h($pageTitle)?>">
