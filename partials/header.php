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
    :root {
      --bg-0: #050505;
      --bg-1: #0d0d0d;
      --bg-2: #161616;
      --line: rgba(255,255,255,.08);
      --text: #ececf0;
      --muted: rgba(232,232,232,.62);
      --yellow: #f6d469;
      --purple: #8b6bff;
      --green: #57c88f;
      --red: #f36f75;
    }
    body {
      font-family: 'Poppins', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      margin: 0;
      color: var(--text);
      background:
        radial-gradient(1200px 700px at 20% 0%, rgba(255,255,255,.04), transparent 70%),
        radial-gradient(900px 500px at 85% 12%, rgba(255,255,255,.03), transparent 70%),
        linear-gradient(180deg, #030303 0%, #090909 100%);
    }
    .sidebar {
      width: 286px;
      min-height: 100vh;
      background: linear-gradient(180deg, rgba(16,16,16,.95), rgba(10,10,10,.98));
      border-right: 1px solid var(--line);
      box-shadow: inset -1px 0 0 rgba(255,255,255,.03);
    }
    .brand { color: var(--text); font-size: 2rem; font-weight: 600; letter-spacing: .02em; }
    .nav-link { color: rgba(236,236,236,.78); border-radius: 10px; padding: 10px 12px; }
    .nav-link:hover, .nav-link.active { color: var(--yellow); background: linear-gradient(90deg, rgba(246,212,105,.16), rgba(246,212,105,.04)); }
    .card { background:#121212; border:1px solid var(--line); border-radius:14px; }
    .btn-yellow { background:var(--yellow); border-color:var(--yellow); color:#0f0f11; font-weight:600; }
    .btn-yellow:hover { background:#ffe18a; border-color:#ffe18a; color:#101012; }
    .badge-soft { background: rgba(246,212,105,.15); border:1px solid rgba(246,212,105,.28); color:var(--yellow); }
    a { color: var(--yellow); }
    .table { --bs-table-bg: transparent; --bs-table-color: #ececf0; }
    .form-control, .form-select { background:#101010; border:1px solid rgba(255,255,255,.12); color:var(--text); }
    .form-control:focus, .form-select:focus { border-color: rgba(246,212,105,.6); box-shadow: 0 0 0 .2rem rgba(246,212,105,.15); }
    .text-muted { color: var(--muted) !important; }
    .small-help { font-size:.9rem; color: var(--muted); }
    main.flex-grow-1 { background: transparent; }
    @media (max-width: 1100px) {
      .sidebar { width: 250px; }
      .brand { font-size: 1.75rem; }
    }
  </style>
</head>
<body>
