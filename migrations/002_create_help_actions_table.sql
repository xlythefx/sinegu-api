-- Migration: Create help_actions table
-- Created: 2025-01-02
-- Description: Tracks when users click "This helped me" on posts/comments/replies

CREATE TABLE IF NOT EXISTS `help_actions` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `helper_uni_id` VARCHAR(36) NOT NULL COMMENT 'User who clicked "This helped me"',
  `helped_uni_id` VARCHAR(36) NOT NULL COMMENT 'User who received help (post/comment author)',
  `post_id` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Post ID if help was on a post',
  `comment_id` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Comment ID if help was on a comment',
  `action_type` ENUM('post_help', 'comment_help') NOT NULL COMMENT 'Type of help action',
  `category` VARCHAR(50) DEFAULT NULL COMMENT 'Context category (community, team, affiliate) - determined by system',
  `verified` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Auto-verified when clicked (can be overridden by admin)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When help action was created',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_help_action` (`helper_uni_id`, `post_id`, `comment_id`),
  KEY `idx_helper_uni_id` (`helper_uni_id`),
  KEY `idx_helped_uni_id` (`helped_uni_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_comment_id` (`comment_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_verified` (`verified`),
  CONSTRAINT `fk_help_actions_helper_uni_id` FOREIGN KEY (`helper_uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_help_actions_helped_uni_id` FOREIGN KEY (`helped_uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_help_actions_post_id` FOREIGN KEY (`post_id`) REFERENCES `community_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_help_actions_comment_id` FOREIGN KEY (`comment_id`) REFERENCES `community_comments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks help actions when users mark posts/comments as helpful';

