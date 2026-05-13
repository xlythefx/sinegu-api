-- Migration: Create new invoices table structure
-- Date: 2025-01-XX
-- Description: Replaces the old invoice table with a proper structure for monthly invoice tracking

-- Drop old invoice table if exists (backup first!)
-- CREATE TABLE invoice_backup AS SELECT * FROM invoice; -- Run this first to backup!

-- New invoices table with proper structure
CREATE TABLE IF NOT EXISTS `invoices` (
  `invoice_id` INT(11) NOT NULL AUTO_INCREMENT,
  `account_id` INT(11) NOT NULL COMMENT 'References accounts.id',
  `invoice_number` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Format: INV-{account_id}-{YYYYMM}',
  `invoice_month` DATE NOT NULL COMMENT 'The month this invoice covers (e.g., 2025-11-01 for November 2025)',
  `invoice_date` DATE NOT NULL COMMENT 'Date invoice was generated (1st of next month)',
  `due_date` DATE NOT NULL COMMENT 'Due date (invoice_date + 7 days)',
  `paid_date` DATETIME NULL DEFAULT NULL COMMENT 'When invoice was paid',
  `status` ENUM('pending', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'pending',
  
  -- Performance metrics
  `period_start_balance` DECIMAL(15,2) NOT NULL COMMENT 'Account balance at start of invoice period',
  `period_end_balance` DECIMAL(15,2) NOT NULL COMMENT 'Account balance at end of invoice period',
  `hwm_reference` DECIMAL(15,2) NULL DEFAULT NULL COMMENT 'High Water Mark used as reference (null for first invoice)',
  `performance_gain` DECIMAL(15,2) NOT NULL COMMENT 'Gain during this period (end_balance - hwm_reference or profitloss for first)',
  `total_pnl` DECIMAL(15,2) NOT NULL COMMENT 'Total PNL from trades during this period',
  `trade_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'Number of trades in this period',
  
  -- Financial details
  `fee_rate` DECIMAL(5,4) NOT NULL DEFAULT 0.2000 COMMENT 'Performance fee rate (20% = 0.2000)',
  `fee_amount` DECIMAL(15,2) NOT NULL COMMENT 'Calculated fee (performance_gain * fee_rate)',
  `currency_type` ENUM('EUR', 'USD') NOT NULL DEFAULT 'USD',
  
  -- Metadata
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `notes` TEXT NULL DEFAULT NULL,
  
  PRIMARY KEY (`invoice_id`),
  INDEX `idx_account_id` (`account_id`),
  INDEX `idx_invoice_month` (`invoice_month`),
  INDEX `idx_status` (`status`),
  INDEX `idx_due_date` (`due_date`),
  INDEX `idx_invoice_date` (`invoice_date`),
  FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Monthly performance fee invoices';

