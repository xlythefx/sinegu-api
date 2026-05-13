<?php
/**
 * Migration Script: Create Community Details Table
 * 
 * This script creates the database table for community details:
 * - community_details: Stores community information linked to referral codes
 * 
 * Usage: Run this script once to set up the community details system
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

$migrationFile = '006_create_community_details_table.sql';
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

// Create community_details table
$createTableSql = "CREATE TABLE IF NOT EXISTS `community_details` (
  `community_id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `referral_id` INT(11) NOT NULL COMMENT 'Referral ID from referral_codes table',
  `community_name` VARCHAR(255) NOT NULL COMMENT 'Name of the trading community',
  `profile_banner` VARCHAR(500) DEFAULT NULL COMMENT 'Path/URL to profile banner image',
  `profile_image` VARCHAR(500) DEFAULT NULL COMMENT 'Path/URL to profile image',
  `bio` TEXT DEFAULT NULL COMMENT 'Community bio/description',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Community creation timestamp',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
  PRIMARY KEY (`community_id`),
  UNIQUE KEY `uq_referral_id` (`referral_id`),
  KEY `idx_referral_id` (`referral_id`),
  CONSTRAINT `fk_community_details_referral_id` FOREIGN KEY (`referral_id`) REFERENCES `referral_codes` (`referral_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Community details for referral codes'";

if (!mysqli_query($conn, $createTableSql)) {
    $errorMsg = mysqli_error($conn);
    if (stripos($errorMsg, 'already exists') === false) {
        $errors[] = "Create table: $errorMsg";
        $success = false;
    }
}

// Verify table was created
$tableExists = false;
$checkTable = "SHOW TABLES LIKE 'community_details'";
$result = mysqli_query($conn, $checkTable);
if ($result && mysqli_num_rows($result) > 0) {
    $tableExists = true;
}

if ($tableExists) {
    echo json_encode([
        'success' => true,
        'message' => 'Community details table created successfully',
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

