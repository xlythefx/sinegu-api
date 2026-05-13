-- API logs table: track API calls (login, flask endpoints, edit, delete, etc.)
-- Columns: api_name, description, action, success, created_at

CREATE TABLE IF NOT EXISTS api_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_name VARCHAR(128) NOT NULL COMMENT 'e.g. users/login, flask/delete_account',
    description VARCHAR(255) DEFAULT NULL COMMENT 'Human-readable description of the call',
    action VARCHAR(64) NOT NULL DEFAULT 'unknown' COMMENT 'login, create, update, delete, fetch, etc.',
    success TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=success, 0=failed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_api_name (api_name),
    KEY idx_action (action),
    KEY idx_success (success),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API request logs for monitoring';
