-- Migration: Create capital_trades_transactions table
-- Created: 2025-01-06
-- Description: Creates table for tracking Entry/Exit trading orders
-- This table is separate from capital_transactions (which handles deposits/withdrawals)

CREATE TABLE IF NOT EXISTS `capital_trades_transactions` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `account_id` INT NOT NULL,
    `ticker` VARCHAR(20) NOT NULL,
    `type` ENUM('entry', 'exit') NOT NULL,
    `price` DECIMAL(15,2) NOT NULL,
    `pnl` DECIMAL(15,2) NULL,
    `size` DECIMAL(15,6) NULL,
    `increment` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_account_id` (`account_id`),
    INDEX `idx_ticker` (`ticker`),
    INDEX `idx_type` (`type`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks Entry/Exit trading orders';

