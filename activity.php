<?php
// Activity feed — thin controller (item #22). Data loading lives in
// lib/activity_data.php; presentation in partials/views/activity_view.php.
// The file-based URL (/activity.php) is preserved.
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/activity_data.php';

$activity = activity_page_data(auth_workspace_id(), (int)($_GET['page'] ?? 1));

include __DIR__ . '/partials/views/activity_view.php';

require_once __DIR__ . '/layout_end.php';
