-- Fix duplicate EXIT PNL: add idempotency column to trades
-- Run once after deploying code that passes deal_ids to insert_trade.php
-- Comma-separated sorted Capital.com deal IDs for this exit; UNIQUE(account_id, deal_ids) prevents duplicate inserts.

-- Option A: VARCHAR (works on all MySQL; index on full column)
ALTER TABLE trades
ADD COLUMN deal_ids VARCHAR(1000) NULL DEFAULT NULL COMMENT 'Comma-separated sorted Capital.com deal IDs for this exit; used for idempotency';

CREATE UNIQUE INDEX idx_trades_account_deal_ids ON trades (account_id, deal_ids);

-- If your MySQL supports index on TEXT prefix, you can use TEXT instead:
-- ALTER TABLE trades
-- ADD COLUMN deal_ids TEXT NULL DEFAULT NULL COMMENT 'Comma-separated sorted Capital.com deal IDs for this exit; used for idempotency';
-- CREATE UNIQUE INDEX idx_trades_account_deal_ids ON trades (account_id, deal_ids(500));
