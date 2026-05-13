<?php
/**
 * Migration Script: Create Post Likes Table
 * 
 * This script creates the database table for post likes:
 * - community_post_likes: Tracks likes on posts
 * - Adds likes_count column to community_posts
 * 
 * Usage: Run this script once to set up the likes system
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

$migrationFile = '005_create_post_likes_table.sql';
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

// 1. Create community_post_likes table
$createTableSql = "CREATE TABLE IF NOT EXISTS `community_post_likes` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` INT(11) UNSIGNED NOT NULL COMMENT 'Post ID that was liked',
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID from user_credentials table (user who liked)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Like creation timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_post_like` (`post_id`, `uni_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_uni_id` (`uni_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_post_likes_post_id` FOREIGN KEY (`post_id`) REFERENCES `community_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_post_likes_uni_id` FOREIGN KEY (`uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Likes/reactions on community posts'";

if (!mysqli_query($conn, $createTableSql)) {
    $errorMsg = mysqli_error($conn);
    if (stripos($errorMsg, 'already exists') === false) {
        $errors[] = "Create table: $errorMsg";
        $success = false;
    }
}

// 2. Check if likes_count column exists, if not add it
$checkColumn = "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'community_posts' 
                AND COLUMN_NAME = 'likes_count'";
$result = mysqli_query($conn, $checkColumn);
$columnExists = false;
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $columnExists = $row['count'] > 0;
}

if (!$columnExists) {
    $addColumnSql = "ALTER TABLE `community_posts`
                     ADD COLUMN `likes_count` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of likes on this post'";
    
    if (!mysqli_query($conn, $addColumnSql)) {
        $errorMsg = mysqli_error($conn);
        if (stripos($errorMsg, 'Duplicate column name') === false) {
            $errors[] = "Add column: $errorMsg";
            $success = false;
        }
    }
}

// 3. Check if index exists, if not create it
$checkIndex = "SHOW INDEX FROM `community_posts` WHERE Key_name = 'idx_likes_count'";
$result = mysqli_query($conn, $checkIndex);
$indexExists = false;
if ($result && mysqli_num_rows($result) > 0) {
    $indexExists = true;
}

if (!$indexExists) {
    $createIndexSql = "CREATE INDEX `idx_likes_count` ON `community_posts` (`likes_count`)";
    
    if (!mysqli_query($conn, $createIndexSql)) {
        $errorMsg = mysqli_error($conn);
        if (stripos($errorMsg, 'Duplicate key name') === false) {
            $errors[] = "Create index: $errorMsg";
            $success = false;
        }
    }
}

// Verify table was created
$tableExists = false;
$checkTable = "SHOW TABLES LIKE 'community_post_likes'";
$result = mysqli_query($conn, $checkTable);
if ($result && mysqli_num_rows($result) > 0) {
    $tableExists = true;
}

// Check if likes_count column exists
$columnExists = false;
$checkColumn = "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'community_posts' 
                AND COLUMN_NAME = 'likes_count'";
$result = mysqli_query($conn, $checkColumn);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $columnExists = $row['count'] > 0;
}

if ($tableExists && $columnExists) {
    echo json_encode([
        'success' => true,
        'message' => 'Post likes table and likes_count column created successfully',
        'table_created' => $tableExists,
        'column_created' => $columnExists,
        'errors' => empty($errors) ? null : $errors
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Migration may have failed',
        'table_created' => $tableExists,
        'column_created' => $columnExists,
        'errors' => $errors
    ]);
}

mysqli_close($conn);
?>

