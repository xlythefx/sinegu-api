<?php
/**
 * Migration Script: Add User Profile and Banner Columns
 * 
 * This script adds user_profile and user_banner columns to user_credentials table
 * 
 * Usage: Run this script once to add the columns
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

$errors = [];
$success = true;

// Check if user_profile column exists
$checkProfileColumn = "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'user_credentials' 
                AND COLUMN_NAME = 'user_profile'";
$result = mysqli_query($conn, $checkProfileColumn);
$profileColumnExists = false;
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $profileColumnExists = $row['count'] > 0;
}

// Add user_profile column if it doesn't exist
if (!$profileColumnExists) {
    $addProfileColumnSql = "ALTER TABLE `user_credentials`
                            ADD COLUMN `user_profile` VARCHAR(500) DEFAULT NULL COMMENT 'Path/URL to user profile image' AFTER `unrealized_percentage`";
    
    if (!mysqli_query($conn, $addProfileColumnSql)) {
        $errorMsg = mysqli_error($conn);
        if (stripos($errorMsg, 'Duplicate column name') === false) {
            $errors[] = "Add user_profile column: $errorMsg";
            $success = false;
        }
    }
}

// Check if user_banner column exists
$checkBannerColumn = "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'user_credentials' 
                AND COLUMN_NAME = 'user_banner'";
$result = mysqli_query($conn, $checkBannerColumn);
$bannerColumnExists = false;
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $bannerColumnExists = $row['count'] > 0;
}

// Add user_banner column if it doesn't exist
if (!$bannerColumnExists) {
    $addBannerColumnSql = "ALTER TABLE `user_credentials`
                           ADD COLUMN `user_banner` VARCHAR(500) DEFAULT NULL COMMENT 'Path/URL to user banner image' AFTER `user_profile`";
    
    if (!mysqli_query($conn, $addBannerColumnSql)) {
        $errorMsg = mysqli_error($conn);
        if (stripos($errorMsg, 'Duplicate column name') === false) {
            $errors[] = "Add user_banner column: $errorMsg";
            $success = false;
        }
    }
}

// Verify columns were created
$profileColumnExists = false;
$bannerColumnExists = false;

$checkProfileColumn = "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'user_credentials' 
                AND COLUMN_NAME = 'user_profile'";
$result = mysqli_query($conn, $checkProfileColumn);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $profileColumnExists = $row['count'] > 0;
}

$checkBannerColumn = "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'user_credentials' 
                AND COLUMN_NAME = 'user_banner'";
$result = mysqli_query($conn, $checkBannerColumn);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $bannerColumnExists = $row['count'] > 0;
}

if ($profileColumnExists && $bannerColumnExists) {
    echo json_encode([
        'success' => true,
        'message' => 'User profile and banner columns added successfully',
        'profile_column_created' => $profileColumnExists,
        'banner_column_created' => $bannerColumnExists,
        'errors' => empty($errors) ? null : $errors
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Migration may have failed',
        'profile_column_created' => $profileColumnExists,
        'banner_column_created' => $bannerColumnExists,
        'errors' => $errors
    ]);
}

mysqli_close($conn);
?>

