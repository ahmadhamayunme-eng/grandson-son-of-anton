<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function finance_ensure_schema(PDO $pdo): void {
  static $done = false;
  if ($done) {
    return;
  }

  $statements = [
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS billing_model ENUM('monthly_retainer','hourly','fixed_project','hybrid') NOT NULL DEFAULT 'fixed_project'",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS billing_cycle ENUM('monthly','every_15_days') NULL",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS retainer_amount DECIMAL(12,2) NULL",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS hourly_rate DECIMAL(12,2) NULL",

    "ALTER TABLE projects ADD COLUMN IF NOT EXISTS pricing_model ENUM('fixed_price','hourly') NOT NULL DEFAULT 'fixed_price'",
    "ALTER TABLE projects ADD COLUMN IF NOT EXISTS project_price DECIMAL(12,2) NULL",
    "ALTER TABLE projects ADD COLUMN IF NOT EXISTS payment_terms ENUM('full_upfront','50_50','milestones') NULL",
    "ALTER TABLE projects ADD COLUMN IF NOT EXISTS hourly_rate DECIMAL(12,2) NULL",

    "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS hours_logged DECIMAL(8,2) NOT NULL DEFAULT 0.00",

    "ALTER TABLE finance_receivables ADD COLUMN IF NOT EXISTS invoice_type ENUM('retainer','hourly','project_fixed') NOT NULL DEFAULT 'project_fixed'",
    "ALTER TABLE finance_receivables ADD COLUMN IF NOT EXISTS period_start DATE NULL",
    "ALTER TABLE finance_receivables ADD COLUMN IF NOT EXISTS period_end DATE NULL",
    "ALTER TABLE finance_receivables ADD COLUMN IF NOT EXISTS invoice_no VARCHAR(120) NULL",

    "ALTER TABLE finance_payments ADD COLUMN IF NOT EXISTS receivable_id INT NULL",
    "ALTER TABLE finance_payments ADD COLUMN IF NOT EXISTS invoice_id INT NULL",
  ];

  foreach ($statements as $sql) {
    try {
      $pdo->exec($sql);
    } catch (Throwable $e) {
      // Ignore no-op migration issues on older engines.
    }
  }

  $done = true;
}

function finance_create_receivable_invoice(PDO $pdo, int $workspaceId, int $createdBy, array $input): int {
  finance_ensure_schema($pdo);
  $expectedAmount = (float)($input['amount_due'] ?? $input['expected_amount'] ?? 0);
  if ($expectedAmount <= 0) {
    return 0;
  }

  $clientId = (int)($input['client_id'] ?? 0) ?: null;
  $projectId = (int)($input['project_id'] ?? 0) ?: null;
  $dueDate = (string)($input['due_date'] ?? date('Y-m-d'));
  $invoiceType = (string)($input['invoice_type'] ?? 'project_fixed');
  if (!in_array($invoiceType, ['retainer', 'hourly', 'project_fixed'], true)) {
    $invoiceType = 'project_fixed';
  }

  $invoiceNo = trim((string)($input['invoice_no'] ?? ''));
  if ($invoiceNo === '') {
    $invoiceNo = 'INV-' . date('Ym') . '-' . substr((string)time(), -5);
  }

  $pdo->prepare("INSERT INTO finance_receivables
    (workspace_id,client_id,project_id,expected_amount,received_amount,due_date,status,description,reference,notes,created_by,created_at,invoice_type,period_start,period_end,invoice_no)
    VALUES (?,?,?,?,0,?,'pending',?,?,?,?,NOW(),?,?,?,?)")
    ->execute([
      $workspaceId,
      $clientId,
      $projectId,
      $expectedAmount,
      $dueDate,
      (string)($input['description'] ?? null),
      (string)($input['reference'] ?? null),
      (string)($input['notes'] ?? null),
      $createdBy,
      $invoiceType,
      ($input['period_start'] ?? null) ?: null,
      ($input['period_end'] ?? null) ?: null,
      $invoiceNo,
    ]);

  return (int)$pdo->lastInsertId();
}

function finance_totals(int $workspaceId): array {
  $pdo = db();
  finance_ensure_schema($pdo);

  $summary = $pdo->prepare("SELECT
      COALESCE((SELECT SUM(amount) FROM finance_payments WHERE workspace_id = ?), 0) AS total_received,
      COALESCE((SELECT SUM(GREATEST(expected_amount - received_amount, 0)) FROM finance_receivables WHERE workspace_id = ? AND status IN ('pending','partial')), 0) AS total_unreceived,
      COALESCE((SELECT SUM(amount) FROM finance_expenses WHERE workspace_id = ?), 0) AS total_expenses,
      COALESCE((SELECT SUM(amount) FROM finance_salaries WHERE workspace_id = ?), 0) AS total_salaries,
      COALESCE((SELECT SUM(amount) FROM finance_overheads WHERE workspace_id = ?), 0) AS total_overheads,
      COALESCE((SELECT SUM(fp.amount) FROM finance_payments fp JOIN finance_receivables fr ON fr.id = COALESCE(fp.receivable_id, fp.invoice_id) WHERE fp.workspace_id = ? AND fr.invoice_type = 'retainer'), 0) AS retainer_revenue,
      COALESCE((SELECT SUM(fp.amount) FROM finance_payments fp JOIN finance_receivables fr ON fr.id = COALESCE(fp.receivable_id, fp.invoice_id) WHERE fp.workspace_id = ? AND fr.invoice_type = 'hourly'), 0) AS hourly_revenue,
      COALESCE((SELECT SUM(fp.amount) FROM finance_payments fp JOIN finance_receivables fr ON fr.id = COALESCE(fp.receivable_id, fp.invoice_id) WHERE fp.workspace_id = ? AND fr.invoice_type = 'project_fixed'), 0) AS fixed_revenue");
  $summary->execute([$workspaceId, $workspaceId, $workspaceId, $workspaceId, $workspaceId, $workspaceId, $workspaceId, $workspaceId]);
  $tot = $summary->fetch() ?: [];

  $tot['payments'] = (float)($tot['total_received'] ?? 0);
  $tot['expenses'] = (float)($tot['total_expenses'] ?? 0);
  $tot['salaries'] = (float)($tot['total_salaries'] ?? 0);
  $tot['overheads'] = (float)($tot['total_overheads'] ?? 0);
  $tot['profit'] = $tot['payments'] - ($tot['expenses'] + $tot['salaries'] + $tot['overheads']);
  return $tot;
}

function finance_project_profit_rows(int $workspaceId): array {
  $pdo = db();
  finance_ensure_schema($pdo);
  $stmt = $pdo->prepare("SELECT p.id, p.name AS project_name, c.name AS client_name,
      COALESCE(SUM(DISTINCT CASE WHEN fr.id IS NOT NULL AND fr.status IN ('partial','received') THEN fr.received_amount ELSE 0 END), 0) AS received_revenue,
      COALESCE(SUM(DISTINCT fe.amount), 0) AS expense_total
    FROM projects p
    JOIN clients c ON c.id = p.client_id
    LEFT JOIN finance_receivables fr ON fr.project_id = p.id AND fr.workspace_id = p.workspace_id
    LEFT JOIN finance_expenses fe ON fe.project_id = p.id AND fe.workspace_id = p.workspace_id
    WHERE p.workspace_id = ?
    GROUP BY p.id, p.name, c.name
    ORDER BY p.id DESC");
  $stmt->execute([$workspaceId]);
  return $stmt->fetchAll() ?: [];
}
