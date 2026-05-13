-- Migration: Create invoice_payments table
-- Date: 2025-01-XX
-- Description: Tracks payment transactions for invoices

CREATE TABLE IF NOT EXISTS `invoice_payments` (
  `payment_id` INT(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` INT(11) NOT NULL COMMENT 'References invoices.invoice_id',
  `payment_method` VARCHAR(50) NULL DEFAULT NULL COMMENT 'stripe, paypal, bank_transfer, etc.',
  `payment_gateway_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'External payment gateway transaction ID',
  `amount` DECIMAL(15,2) NOT NULL COMMENT 'Amount paid',
  `currency` ENUM('EUR', 'USD') NOT NULL DEFAULT 'USD',
  `status` ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
  `paid_at` DATETIME NULL DEFAULT NULL COMMENT 'When payment was completed',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `notes` TEXT NULL DEFAULT NULL,
  
  PRIMARY KEY (`payment_id`),
  INDEX `idx_invoice_id` (`invoice_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_paid_at` (`paid_at`),
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`invoice_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment transactions for invoices';

