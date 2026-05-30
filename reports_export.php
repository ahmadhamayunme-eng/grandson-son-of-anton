<?php
// Report CSV export (item #19). Streams a CSV for a given report type, scoped
// to the current workspace. PDF export is handled client-side via the browser
// print dialog ("Save as PDF") on the report pages.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();

$pdo  = db();
$ws   = auth_workspace_id();
$type = preg_replace('/[^a-z_]/', '', strtolower((string)($_GET['type'] ?? '')));

function csv_send(string $filename, array $header, array $rows): void {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  $out = fopen('php://output', 'w');
  fputcsv($out, $header);
  foreach ($rows as $r) fputcsv($out, $r);
  fclose($out);
  exit;
}

if ($type === 'developer_performance') {
  $stmt = $pdo->prepare("SELECT u.name, u.email,
      SUM(CASE WHEN t.status='Approved (Ready to Submit)' THEN 1 ELSE 0 END) AS approved,
      SUM(CASE WHEN t.status='Submitted to Client' THEN 1 ELSE 0 END) AS submitted,
      COUNT(*) AS total
    FROM task_assignees ta
    JOIN users u ON u.id=ta.user_id
    JOIN tasks t ON t.id=ta.task_id
    WHERE t.workspace_id=?
    GROUP BY u.id
    ORDER BY submitted DESC, approved DESC, total DESC");
  $stmt->execute([$ws]);
  $rows = array_map(fn($r) => [
    $r['name'], $r['email'], (int)$r['approved'], (int)$r['submitted'], (int)$r['total'],
  ], $stmt->fetchAll());
  csv_send('developer_performance_' . date('Ymd') . '.csv',
           ['Name', 'Email', 'Approved', 'Submitted', 'Total Assigned'], $rows);
}

if ($type === 'project_profit') {
  require_once __DIR__ . '/lib/finance.php';
  if (!auth_can_finance()) { http_response_code(403); echo 'Forbidden'; exit; }
  $rows = array_map(fn($r) => [
    $r['project_name'], $r['client_name'],
    number_format((float)$r['received_revenue'], 2, '.', ''),
    number_format((float)$r['expense_total'], 2, '.', ''),
    number_format((float)$r['received_revenue'] - (float)$r['expense_total'], 2, '.', ''),
  ], finance_project_profit_rows($ws));
  csv_send('project_profit_' . date('Ymd') . '.csv',
           ['Project', 'Client', 'Received Revenue', 'Expenses', 'Profit'], $rows);
}

http_response_code(400);
echo 'Unknown report type';
