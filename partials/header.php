<?php
require_once __DIR__ . '/../lib/helpers.php';
$config = file_exists(__DIR__ . '/../config.php') ? (require __DIR__ . '/../config.php') : ['app'=>['name'=>'SpeedX BMS']];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($config['app']['name'] ?? 'SpeedX BMS')?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { font-family: 'Poppins', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#0b0b0b; color:#f5f5f5; }
    .sidebar { width: 260px; min-height: 100vh; background:#111; border-right:1px solid rgba(255,255,255,.08); }
    .brand { color:#FFD000; font-weight:700; }
    .nav-link { color:#d7d7d7; border-radius:10px; padding:10px 12px; }
    .nav-link:hover, .nav-link.active { background: rgba(255,208,0,.12); color:#fff; }
    .card { background:#121212; border:1px solid rgba(255,255,255,.08); border-radius:16px; }
    .btn-yellow { background:#FFD000; border-color:#FFD000; color:#000; font-weight:600; }
    .btn-yellow:hover { background:#ffdf33; border-color:#ffdf33; color:#000; }
    .badge-soft { background: rgba(255,208,0,.15); border:1px solid rgba(255,208,0,.25); color:#FFD000; }
    a { color:#FFD000; }
    .table { --bs-table-bg: transparent; --bs-table-color: #eee; }
    .form-control, .form-select { background:#0f0f0f; border:1px solid rgba(255,255,255,.12); color:#fff; }
    .form-control:focus, .form-select:focus { border-color: rgba(255,208,0,.6); box-shadow: 0 0 0 .2rem rgba(255,208,0,.15); }
    .text-muted { color: rgba(255,255,255,.6) !important; }
    .small-help { font-size:.9rem; color: rgba(255,255,255,.65); }
  </style>
</head>
<body>
