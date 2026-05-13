-- Add due_date to billing_periods
-- Rule: from month_year (e.g. 2025-11), invoice date = 1st of next month, due_date = invoice date + 7 days
-- Example: month_year = 2025-11 -> due_date = 2025-12-08

ALTER TABLE billing_periods ADD COLUMN due_date DATE NULL COMMENT 'Payment due date (1st of next month after month_year + 7 days)' AFTER status;
