-- Migration: Create community details table
-- Created: 2025-01-05
-- Description: Creates table for storing community details linked to referral codes

-- Table: community_details
-- Stores community information (name, banner, profile image, bio) for each referral code
CREATE TABLE IF NOT EXISTS `community_details` (
  `community_id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `referral_id` INT(11) NOT NULL COMMENT 'Referral ID from referral_codes table',
  `community_name` VARCHAR(255) NOT NULL COMMENT 'Name of the trading community',
  `profile_banner` VARCHAR(500) DEFAULT NULL COMMENT 'Path/URL to profile banner image',
  `profile_image` VARCHAR(500) DEFAULT NULL COMMENT 'Path/URL to profile image',
  `bio` TEXT DEFAULT NULL COMMENT 'Community bio/description',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Community creation timestamp',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
  PRIMARY KEY (`community_id`),
  UNIQUE KEY `uq_referral_id` (`referral_id`),
  KEY `idx_referral_id` (`referral_id`),
  CONSTRAINT `fk_community_details_referral_id` FOREIGN KEY (`referral_id`) REFERENCES `referral_codes` (`referral_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Community details for referral codes';

