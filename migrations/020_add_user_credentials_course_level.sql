-- Migration: Add course_level to user_credentials
-- Description: User course/progress level (e.g. beginner, intermediate, advanced).

ALTER TABLE `user_credentials`
ADD COLUMN `course_level` VARCHAR(100) DEFAULT NULL COMMENT 'User course or progress level' AFTER `affiliate_percentage`;
