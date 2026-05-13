-- Migration: Migrate data from old invoice table to new invoices table
-- Date: 2025-01-XX
-- Description: Transforms old invoice data to new structure
-- WARNING: Backup your database before running this!

-- Step 1: Create backup of old table
-- CREATE TABLE invoice_old_backup AS SELECT * FROM invoice;

-- Step 2: Migrate existing invoice data
-- This assumes the old invoice table has: invoice_id, user_id (which is actually account_id), hwm, pnl, last_date_invoice

INSERT INTO `invoices` (
  `account_id`,
  `invoice_number`,
  `invoice_month`,
  `invoice_date`,
  `due_date`,
  `paid_date`,
  `status`,
  `period_start_balance`,
  `period_end_balance`,
  `hwm_reference`,
  `performance_gain`,
  `total_pnl`,
  `trade_count`,
  `fee_rate`,
  `fee_amount`,
  `currency_type`,
  `created_at`,
  `updated_at`
)
SELECT 
  i.user_id AS account_id, -- Note: old table uses user_id but it's actually account_id
  CONCAT('INV-', i.user_id, '-', DATE_FORMAT(i.last_date_invoice, '%Y%m')) AS invoice_number,
  DATE_FORMAT(DATE_SUB(i.last_date_invoice, INTERVAL 1 MONTH), '%Y-%m-01') AS invoice_month,
  DATE_FORMAT(i.last_date_invoice, '%Y-%m-01') AS invoice_date,
  DATE_ADD(DATE_FORMAT(i.last_date_invoice, '%Y-%m-01'), INTERVAL 7 DAY) AS due_date,
  CASE 
    WHEN EXISTS (
      SELECT 1 FROM invoice i2 
      WHERE i2.user_id = i.user_id 
      AND i2.last_date_invoice > i.last_date_invoice
    ) THEN i.last_date_invoice
    ELSE NULL
  END AS paid_date,
  CASE 
    WHEN EXISTS (
      SELECT 1 FROM invoice i2 
      WHERE i2.user_id = i.user_id 
      AND i2.last_date_invoice > i.last_date_invoice
    ) THEN 'paid'
    WHEN DATE_ADD(DATE_FORMAT(i.last_date_invoice, '%Y-%m-01'), INTERVAL 7 DAY) < CURDATE() THEN 'overdue'
    ELSE 'pending'
  END AS status,
  COALESCE(a.deposit_amount, a.balance - a.profitloss, 0) AS period_start_balance,
  COALESCE(i.hwm, a.balance, 0) AS period_end_balance,
  NULL AS hwm_reference, -- Will need to calculate from previous invoice
  COALESCE(i.pnl, 0) AS performance_gain,
  COALESCE(i.pnl, 0) AS total_pnl,
  (
    SELECT COUNT(*) 
    FROM trades t 
    WHERE t.account_id = i.user_id
    AND DATE(t.created_at) >= DATE_FORMAT(DATE_SUB(i.last_date_invoice, INTERVAL 1 MONTH), '%Y-%m-01')
    AND DATE(t.created_at) < DATE_FORMAT(i.last_date_invoice, '%Y-%m-01')
  ) AS trade_count,
  0.2000 AS fee_rate,
  COALESCE(i.pnl * 0.20, 0) AS fee_amount,
  COALESCE(a.currency_type, 'USD') AS currency_type,
  i.last_date_invoice AS created_at,
  i.last_date_invoice AS updated_at
FROM invoice i
LEFT JOIN accounts a ON a.id = i.user_id
WHERE NOT EXISTS (
  -- Avoid duplicates if migration runs multiple times
  SELECT 1 FROM invoices inv 
  WHERE inv.account_id = i.user_id 
  AND inv.invoice_date = DATE_FORMAT(i.last_date_invoice, '%Y-%m-01')
);

-- Step 3: Update hwm_reference for non-first invoices
UPDATE invoices i1
INNER JOIN invoices i2 ON i1.account_id = i2.account_id
SET i1.hwm_reference = i2.period_end_balance
WHERE i1.invoice_id != i2.invoice_id
AND i2.invoice_date < i1.invoice_date
AND i1.hwm_reference IS NULL
AND NOT EXISTS (
  SELECT 1 FROM invoices i3
  WHERE i3.account_id = i1.account_id
  AND i3.invoice_date > i2.invoice_date
  AND i3.invoice_date < i1.invoice_date
);

-- Step 4: Recalculate performance_gain for non-first invoices
UPDATE invoices i1
INNER JOIN invoices i2 ON i1.account_id = i2.account_id
SET i1.performance_gain = i1.period_end_balance - i2.period_end_balance,
    i1.fee_amount = (i1.period_end_balance - i2.period_end_balance) * i1.fee_rate
WHERE i1.hwm_reference IS NOT NULL
AND i2.invoice_date < i1.invoice_date
AND NOT EXISTS (
  SELECT 1 FROM invoices i3
  WHERE i3.account_id = i1.account_id
  AND i3.invoice_date > i2.invoice_date
  AND i3.invoice_date < i1.invoice_date
);

-- Step 5: Link trades to invoices (for historical data)
INSERT INTO invoice_trades (invoice_id, trade_id)
SELECT 
  i.invoice_id,
  t.id AS trade_id
FROM invoices i
INNER JOIN trades t ON t.account_id = i.account_id
WHERE DATE(t.created_at) >= i.invoice_month
AND DATE(t.created_at) < DATE_ADD(i.invoice_month, INTERVAL 1 MONTH)
AND NOT EXISTS (
  SELECT 1 FROM invoice_trades it
  WHERE it.invoice_id = i.invoice_id
  AND it.trade_id = t.id
);

