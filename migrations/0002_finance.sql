-- 0002_finance.sql
-- Authoritative version of the columns previously added at runtime by
-- finance_ensure_schema() in lib/finance.php (item #16). That function is
-- kept as a safety fallback this release; this migration is the canonical path.

ALTER TABLE clients ADD COLUMN IF NOT EXISTS billing_model ENUM('monthly_retainer','hourly','fixed_project','hybrid') NOT NULL DEFAULT 'fixed_project';
ALTER TABLE clients ADD COLUMN IF NOT EXISTS billing_cycle ENUM('monthly','every_15_days') NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS retainer_amount DECIMAL(12,2) NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS hourly_rate DECIMAL(12,2) NULL;

ALTER TABLE projects ADD COLUMN IF NOT EXISTS pricing_model ENUM('fixed_price','hourly') NOT NULL DEFAULT 'fixed_price';
ALTER TABLE projects ADD COLUMN IF NOT EXISTS project_price DECIMAL(12,2) NULL;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS payment_terms ENUM('full_upfront','50_50','milestones') NULL;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS hourly_rate DECIMAL(12,2) NULL;

ALTER TABLE tasks ADD COLUMN IF NOT EXISTS hours_logged DECIMAL(8,2) NOT NULL DEFAULT 0.00;

ALTER TABLE finance_receivables ADD COLUMN IF NOT EXISTS invoice_type ENUM('retainer','hourly','project_fixed') NOT NULL DEFAULT 'project_fixed';
ALTER TABLE finance_receivables ADD COLUMN IF NOT EXISTS period_start DATE NULL;
ALTER TABLE finance_receivables ADD COLUMN IF NOT EXISTS period_end DATE NULL;
ALTER TABLE finance_receivables ADD COLUMN IF NOT EXISTS invoice_no VARCHAR(120) NULL;

ALTER TABLE finance_payments ADD COLUMN IF NOT EXISTS receivable_id INT NULL;
ALTER TABLE finance_payments ADD COLUMN IF NOT EXISTS invoice_id INT NULL;
