<?php
/**
 * Migration Script: Create Coinsbuy Deposits Tracking Table
 * 
 * This script creates the database table for tracking Coinsbuy deposits:
 * - coinsbuy_deposits: Stores deposit information and links to billing periods
 * 
 * Usage: Run this script once to set up the Coinsbuy deposits tracking system
 * 
 * Database: sinegu_db
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed: ' . mysqli_connect_error()
    ]);
    exit;
}

$migrationFile = '009_create_coinsbuy_deposits_table.sql';
$sqlFile = __DIR__ . '/' . $migrationFile;

if (!file_exists($sqlFile)) {
    echo json_encode([
        'success' => false,
        'error' => "Migration file not found: $migrationFile"
    ]);
    exit;
}

$sql = file_get_contents($sqlFile);

if ($sql === false) {
    echo json_encode([
        'success' => false,
        'error' => "Failed to read migration file: $migrationFile"
    ]);
    exit;
}

$errors = [];
$success = true;

// Create table first
$createTableSql = "CREATE TABLE IF NOT EXISTS `coinsbuy_deposits` (
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
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks Coinsbuy deposits for invoice payments'";

if (!mysqli_query($conn, $createTableSql)) {
    $errorMsg = mysqli_error($conn);
    if (stripos($errorMsg, 'already exists') === false) {
        $errors[] = "Create table: $errorMsg";
        $success = false;
    }
}

// Try to add foreign key constraints if tables exist
$checkBillingPeriods = "SHOW TABLES LIKE 'billing_periods'";
$billingPeriodsExists = false;
$result = mysqli_query($conn, $checkBillingPeriods);
if ($result && mysqli_num_rows($result) > 0) {
    $billingPeriodsExists = true;
}

$checkAccounts = "SHOW TABLES LIKE 'accounts'";
$accountsExists = false;
$result = mysqli_query($conn, $checkAccounts);
if ($result && mysqli_num_rows($result) > 0) {
    $accountsExists = true;
}

if ($billingPeriodsExists) {
    // Check if foreign key already exists
    $checkFK = "SELECT CONSTRAINT_NAME 
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'coinsbuy_deposits' 
                AND CONSTRAINT_NAME = 'fk_coinsbuy_deposits_billing_period_id'";
    $fkResult = mysqli_query($conn, $checkFK);
    $fkExists = ($fkResult && mysqli_num_rows($fkResult) > 0);
    
    if (!$fkExists) {
        $addFK = "ALTER TABLE `coinsbuy_deposits` 
                  ADD CONSTRAINT `fk_coinsbuy_deposits_billing_period_id` 
                  FOREIGN KEY (`billing_period_id`) REFERENCES `billing_periods`(`id`) ON DELETE CASCADE";
        
        if (!mysqli_query($conn, $addFK)) {
            $errorMsg = mysqli_error($conn);
            $errors[] = "Foreign key constraint (non-critical): $errorMsg";
        }
    }
}

if ($accountsExists) {
    $checkFK = "SELECT CONSTRAINT_NAME 
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'coinsbuy_deposits' 
                AND CONSTRAINT_NAME = 'fk_coinsbuy_deposits_account_id'";
    $fkResult = mysqli_query($conn, $checkFK);
    $fkExists = ($fkResult && mysqli_num_rows($fkResult) > 0);
    
    if (!$fkExists) {
        $addFK = "ALTER TABLE `coinsbuy_deposits` 
                  ADD CONSTRAINT `fk_coinsbuy_deposits_account_id` 
                  FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE CASCADE";
        
        if (!mysqli_query($conn, $addFK)) {
            $errorMsg = mysqli_error($conn);
            $errors[] = "Foreign key constraint (non-critical): $errorMsg";
        }
    }
}

// Verify table was created
$tableExists = false;
$checkTable = "SHOW TABLES LIKE 'coinsbuy_deposits'";
$result = mysqli_query($conn, $checkTable);
if ($result && mysqli_num_rows($result) > 0) {
    $tableExists = true;
}

if ($tableExists) {
    echo json_encode([
        'success' => true,
        'message' => 'Coinsbuy deposits table created successfully',
        'table_created' => $tableExists,
        'errors' => empty($errors) ? null : $errors
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Migration may have failed',
        'table_created' => $tableExists,
        'errors' => $errors
    ]);
}

mysqli_close($conn);
?>

