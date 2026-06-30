-- Migration 082: asset_audit — change history for trading assets (create/update/delete).
-- One row per admin asset mutation. `changes` holds the field-level diff (old → new);
-- `old_values` / `new_values` hold full JSON snapshots before/after the change.
-- No actor column by design (we only record what changed and when).
-- Created: 2026-06-30
CREATE TABLE IF NOT EXISTS asset_audit (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id INT UNSIGNED DEFAULT NULL COMMENT 'assets.asset_id affected (kept even after the asset is deleted)',
    ticker VARCHAR(64) DEFAULT NULL COMMENT 'Asset ticker at time of change, for readable history',
    action VARCHAR(16) NOT NULL COMMENT 'create | update | delete',
    changes TEXT NULL COMMENT 'JSON of changed fields {field:{from,to}} (update only)',
    old_values TEXT NULL COMMENT 'JSON snapshot before change (update/delete)',
    new_values TEXT NULL COMMENT 'JSON snapshot after change (create/update)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_asset_id (asset_id),
    KEY idx_ticker (ticker),
    KEY idx_action (action),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Audit log of admin trading-asset changes';
