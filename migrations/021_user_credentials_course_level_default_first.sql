-- Migration: Set course_level default to 'first' and backfill existing NULLs
UPDATE `user_credentials` SET `course_level` = 'first' WHERE `course_level` IS NULL;
ALTER TABLE `user_credentials`
MODIFY COLUMN `course_level` VARCHAR(100) DEFAULT 'first' COMMENT 'User course or progress level';
