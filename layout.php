<?php
session_start();
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
auth_require_login();
require_once __DIR__ . '/lib/db.php';
?>
<?php include __DIR__ . '/partials/header.php'; ?>
<div class="d-flex">
  <?php include __DIR__ . '/partials/nav.php'; ?>
  <main class="flex-grow-1 p-4">
    <?php if ($m = flash_get('success')): ?><div class="alert alert-success"><?=h($m)?></div><?php endif; ?>
    <?php if ($m = flash_get('error')): ?><div class="alert alert-danger"><?=h($m)?></div><?php endif; ?>
