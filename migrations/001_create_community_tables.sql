-- Migration: Create community forum tables
-- Created: 2025-01-02
-- Description: Creates tables for community posts, comments, and attachments

-- Table: community_posts
-- Stores forum posts created by users
CREATE TABLE IF NOT EXISTS `community_posts` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID from user_credentials table (post author)',
  `title` VARCHAR(500) NOT NULL COMMENT 'Post title',
  `content` TEXT NOT NULL COMMENT 'Post content/body',
  `category` VARCHAR(100) DEFAULT NULL COMMENT 'Post category (optional for now)',
  `interactions_count` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total number of interactions (comments, likes, etc.)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Post creation timestamp',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
  PRIMARY KEY (`id`),
  KEY `idx_uni_id` (`uni_id`),
  KEY `idx_category` (`category`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_interactions_count` (`interactions_count`),
  CONSTRAINT `fk_community_posts_uni_id` FOREIGN KEY (`uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Community forum posts';

-- Table: community_comments
-- Stores comments and replies to posts (one level deep for replies)
CREATE TABLE IF NOT EXISTS `community_comments` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` INT(11) UNSIGNED NOT NULL COMMENT 'Post ID this comment belongs to',
  `uni_id` VARCHAR(36) NOT NULL COMMENT 'User ID from user_credentials table (comment author)',
  `parent_comment_id` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Parent comment ID if this is a reply (one level deep only)',
  `content` TEXT NOT NULL COMMENT 'Comment content',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Comment creation timestamp',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
  PRIMARY KEY (`id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_uni_id` (`uni_id`),
  KEY `idx_parent_comment_id` (`parent_comment_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_community_comments_post_id` FOREIGN KEY (`post_id`) REFERENCES `community_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_community_comments_uni_id` FOREIGN KEY (`uni_id`) REFERENCES `user_credentials` (`uni_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_community_comments_parent` FOREIGN KEY (`parent_comment_id`) REFERENCES `community_comments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Comments and replies on community posts';

-- Table: community_post_attachments
-- Stores file attachments (images, documents) for posts
CREATE TABLE IF NOT EXISTS `community_post_attachments` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` INT(11) UNSIGNED NOT NULL COMMENT 'Post ID this attachment belongs to',
  `file_path` VARCHAR(500) NOT NULL COMMENT 'Path to uploaded file',
  `file_type` VARCHAR(50) NOT NULL COMMENT 'File type (image, document, etc.)',
  `file_name` VARCHAR(255) NOT NULL COMMENT 'Original file name',
  `file_size` INT(11) UNSIGNED DEFAULT NULL COMMENT 'File size in bytes',
  `mime_type` VARCHAR(100) DEFAULT NULL COMMENT 'MIME type of the file',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Attachment creation timestamp',
  PRIMARY KEY (`id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_file_type` (`file_type`),
  CONSTRAINT `fk_community_attachments_post_id` FOREIGN KEY (`post_id`) REFERENCES `community_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='File attachments for community posts';

