<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function finance_totals(int $workspaceId): array {
  $pdo = db();
  $tot = [];
  foreach ([
    'payments' => 'finance_payments',
    'expenses' => 'finance_expenses',
    'salaries' => 'finance_salaries',
    'overheads' => 'finance_overheads',
  ] as $k=>$t) {
    try {
      $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM {$t} WHERE workspace_id=?");
      $stmt->execute([$workspaceId]);
      $tot[$k] = (float)$stmt->fetchColumn();
    } catch (Throwable $e) { $tot[$k]=0.0; }
  }
  $tot['profit'] = $tot['payments'] - ($tot['expenses'] + $tot['salaries'] + $tot['overheads']);
  return $tot;
}
