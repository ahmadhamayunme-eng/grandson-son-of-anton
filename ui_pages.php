<?php
require_once __DIR__ . '/layout.php';
$dir = __DIR__ . '/ui_pages';
$files = glob($dir . '/*.php');
sort($files);
?>
<h3>UI Pages (49)</h3>
<p class="text-muted">All design-screen pages are present as routable PHP pages in <code>/ui_pages</code>. Use this list to verify presence and theme consistency.</p>
<div class="row g-3">
<?php foreach ($files as $f): $bn = basename($f); $label = str_replace('_',' ', preg_replace('/\.php$/','',$bn)); ?>
  <div class="col-md-4">
    <div class="card p-3 h-100">
      <div class="fw-semibold text-capitalize"><?=h($label)?></div>
      <div class="mt-2"><a class="btn btn-sm btn-yellow" href="ui_pages/<?=h($bn)">Open</a></div>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
