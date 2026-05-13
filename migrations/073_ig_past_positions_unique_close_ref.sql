-- Add UNIQUE(account_id, close_deal_reference) so the past_position upsert
-- (insert_past_position.php) can dedupe between the exit-handler insert and
-- the fetch_past_positions.py reconciler.
--
-- MySQL allows multiple NULLs in a UNIQUE index, so existing rows with
-- close_deal_reference IS NULL will not conflict.
--
-- Pre-flight check: verify no duplicate non-null close_deal_reference values
-- exist before applying:
--   SELECT account_id, close_deal_reference, COUNT(*) c
--   FROM ig_past_positions
--   WHERE close_deal_reference IS NOT NULL AND close_deal_reference != ''
--   GROUP BY account_id, close_deal_reference HAVING c > 1;

ALTER TABLE ig_past_positions
  ADD UNIQUE KEY uk_account_close_ref (account_id, close_deal_reference);
