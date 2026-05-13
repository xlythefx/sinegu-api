-- Migration: Create coinsbuy_deposits tracking table
-- Created: 2025-01-06
-- Description: Creates table for tracking Coinsbuy deposits and matching them to billing periods

CREATE TABLE IF NOT EXISTS `coinsbuy_deposits` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `deposit_id` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Coinsbuy deposit ID',
    `billing_period_id` INT NOT NULL,
    `account_id` INT NOT NULL,
    `user_id` VARCHAR(255) NOT NULL,
    `month_year` VARCHAR(7) NOT NULL COMMENT 'Format: YYYY-MM',
    `amount` DECIMAL(15,2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL,
    `cryptocurrency` VARCHAR(10) NOT NULL,
    `status` VARCHAR(50) DEFAULT 'pending' COMMENT 'pending, completed, failed, etc.',
    `destination` VARCHAR(255) NULL COMMENT 'Cryptocurrency address',
    `transaction_hash` VARCHAR(255) NULL COMMENT 'Blockchain transaction hash',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_deposit_id` (`deposit_id`),
    INDEX `idx_billing_period_id` (`billing_period_id`),
    INDEX `idx_account_id` (`account_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    FOREIGN KEY (`billing_period_id`) REFERENCES `billing_periods`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks Coinsbuy deposits for invoice payments';

