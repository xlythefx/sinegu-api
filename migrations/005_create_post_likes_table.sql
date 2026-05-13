-- Migration: Create post likes table
-- Created: 2025-01-02
-- Description: Creates table for storing post likes/reactions

-- Table: community_post_likes
-- Stores likes/reactions on posts
CREATE TABLE IF NOT EXISTS `community_post_likes` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Likes/reactions on community posts';

-- Add likes_count column to community_posts if it doesn't exist
ALTER TABLE `community_posts`
ADD COLUMN IF NOT EXISTS `likes_count` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of likes on this post';

-- Add index for likes_count
CREATE INDEX IF NOT EXISTS `idx_likes_count` ON `community_posts` (`likes_count`);

