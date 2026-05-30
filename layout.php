<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (ob_get_level() === 0) { ob_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
app_send_security_headers();
auth_require_login();
require_once __DIR__ . '/lib/db.php';

// Pages can set these before including layout.php:
//   $pageTitle      - browser title suffix
//   $activeKey      - sidebar key (dashboard, my-tasks, projects, ...)
//   $pageHeadExtra  - per-page <style>/<link> block injected into <head>
//   $backHref       - back-button href (defaults to javascript:history.back())
$pageTitle     = $pageTitle     ?? 'AntonX';
$activeKey     = $activeKey     ?? '';
$pageHeadExtra = $pageHeadExtra ?? '';
$backHref      = $backHref      ?? 'javascript:history.back()';

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/nav.php';

/**
 * Returns the standard back-button markup required on every page.
 * Pages can override $backHref to point to a specific page.
 */
function back_button_html(string $href = '', string $label = 'Back'): string {
  $h = $href !== '' ? $href : 'javascript:history.back()';
  return '<a class="back-btn" href="' . h($h) . '" title="Go back">'
       . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>'
       . h($label)
       . '</a>';
}
?>
<main class="main" id="main" tabindex="-1">
<div class="flash-region" aria-live="polite" role="status">
<?php if ($m = flash_get('success')): ?>
  <div class="flash flash-success">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
    <span><?= h($m) ?></span>
  </div>
<?php endif; ?>
<?php if ($m = flash_get('error')): ?>
  <div class="flash flash-error">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
    <span><?= h($m) ?></span>
  </div>
<?php endif; ?>
</div>
<?php /* end .flash-region */ ?>
