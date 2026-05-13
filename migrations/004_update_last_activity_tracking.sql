-- Migration: Verify and update last_activity column in user_credentials
-- Created: 2025-01-02
-- Description: Ensures last_activity column exists and adds index if needed

-- Check if last_activity column exists, if not add it
SET @col_exists = (
  SELECT COUNT(*) 
  FROM INFORMATION_SCHEMA.COLUMNS 
  WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'user_credentials' 
  AND COLUMN_NAME = 'last_activity'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `user_credentials` ADD COLUMN `last_activity` TIMESTAMP NULL DEFAULT NULL COMMENT ''Last user activity timestamp'' AFTER `updated_at`',
  'SELECT "Column last_activity already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index on last_activity if it doesn't exist
SET @index_exists = (
  SELECT COUNT(*) 
  FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'user_credentials' 
  AND INDEX_NAME = 'idx_last_activity'
);

SET @sql = IF(@index_exists = 0,
  'ALTER TABLE `user_credentials` ADD INDEX `idx_last_activity` (`last_activity`)',
  'SELECT "Index idx_last_activity already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

