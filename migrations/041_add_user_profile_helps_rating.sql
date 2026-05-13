-- Migration: Add rating column to user_profile_helps (1-10, optional).
-- Created for help experience rating from "Yes, they helped me" flow.

ALTER TABLE `user_profile_helps`
ADD COLUMN `rating` TINYINT UNSIGNED NULL DEFAULT NULL COMMENT '1-10 help experience rating from helped user' AFTER `status`;
