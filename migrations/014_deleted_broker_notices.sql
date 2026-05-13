-- Notices shown once to user when an account was removed (e.g. invalid API).
-- Cleared after frontend fetches them (get_deleted_broker_notices or account_data).
CREATE TABLE IF NOT EXISTS `deleted_broker_notices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uni_id` VARCHAR(36) NOT NULL,
  `account_name` VARCHAR(255) NOT NULL,
  `reason` VARCHAR(64) NOT NULL DEFAULT 'invalid_api',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_uni_id` (`uni_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
