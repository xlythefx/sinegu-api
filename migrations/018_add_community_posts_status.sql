-- Migration: Add status to community_posts for admin approval flow
-- Values: pending, accepted, rejected. Default pending. Existing posts become accepted.

ALTER TABLE `community_posts`
ADD COLUMN `status` ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending'
COMMENT 'Moderation status: pending (awaiting admin), accepted (visible), rejected'
AFTER `category`;

UPDATE `community_posts` SET `status` = 'accepted' WHERE 1=1;
