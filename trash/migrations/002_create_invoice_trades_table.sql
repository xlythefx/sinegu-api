-- Migration: Create invoice_trades junction table
-- Date: 2025-01-XX
-- Description: Links trades to invoices for accurate period-based PNL calculation

CREATE TABLE IF NOT EXISTS `invoice_trades` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` INT(11) NOT NULL COMMENT 'References invoices.invoice_id',
  `trade_id` INT(11) NOT NULL COMMENT 'References trades.id',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_invoice_trade` (`invoice_id`, `trade_id`),
  INDEX `idx_invoice_id` (`invoice_id`),
  INDEX `idx_trade_id` (`trade_id`),
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`invoice_id`) ON DELETE CASCADE,
  FOREIGN KEY (`trade_id`) REFERENCES `trades`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Links trades to invoices for period tracking';

