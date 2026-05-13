<?php
/**
 * Migration Script: Create Capital Trades Transactions Table
 * 
 * This script creates the database table for capital trades transactions:
 * - capital_trades_transactions: Stores Entry/Exit trading orders
 * 
 * Usage: Run this script once to set up the capital trades transactions system
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

$migrationFile = '008_create_capital_trades_transactions_table.sql';
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
$createTableSql = "CREATE TABLE IF NOT EXISTS `capital_trades_transactions` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks Entry/Exit trading orders'";

if (!mysqli_query($conn, $createTableSql)) {
    $errorMsg = mysqli_error($conn);
    if (stripos($errorMsg, 'already exists') === false) {
        $errors[] = "Create table: $errorMsg";
        $success = false;
    }
}

// Try to add foreign key constraint if accounts table exists
$checkAccounts = "SHOW TABLES LIKE 'accounts'";
$accountsExists = false;
$result = mysqli_query($conn, $checkAccounts);
if ($result && mysqli_num_rows($result) > 0) {
    $accountsExists = true;
    
    // Check if foreign key already exists
    $checkFK = "SELECT CONSTRAINT_NAME 
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'capital_trades_transactions' 
                AND CONSTRAINT_NAME = 'fk_capital_trades_account_id'";
    $fkResult = mysqli_query($conn, $checkFK);
    $fkExists = ($fkResult && mysqli_num_rows($fkResult) > 0);
    
    if (!$fkExists) {
        $addFK = "ALTER TABLE `capital_trades_transactions` 
                  ADD CONSTRAINT `fk_capital_trades_account_id` 
                  FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE CASCADE";
        
        if (!mysqli_query($conn, $addFK)) {
            $errorMsg = mysqli_error($conn);
            // Don't fail migration if FK can't be added, just log it
            $errors[] = "Foreign key constraint (non-critical): $errorMsg";
        }
    }
}

// Verify table was created
$tableExists = false;
$checkTable = "SHOW TABLES LIKE 'capital_trades_transactions'";
$result = mysqli_query($conn, $checkTable);
if ($result && mysqli_num_rows($result) > 0) {
    $tableExists = true;
}

if ($tableExists) {
    echo json_encode([
        'success' => true,
        'message' => 'Capital trades transactions table created successfully',
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

