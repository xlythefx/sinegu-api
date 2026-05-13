-- Seed: two Capital demo accounts (one per uni_id) + open positions.
-- Run after migrations: positions deal_id (048), side (049), strategy (050).
-- Idempotency: uses fixed account `name` values; delete/re-insert seed positions by deal_id prefix.
--
-- Usage (phpMyAdmin / mysql CLI): run the PHP seeder instead if possible:
--   php migrations/seeds/run_seed_two_users_accounts_positions.php
--
-- Manual SQL: adjust if your `accounts.id` already conflicts; names must stay unique (UNIQUE on accounts.name).

SET NAMES utf8mb4;

-- Account A (uni_id 25293010-...)
INSERT INTO `accounts` (
  `uni_id`, `name`, `api_key`, `broker_accountID`, `email`, `password`,
  `demo`, `enabled`, `balance`, `unrealized_pnl`, `equity`,
  `currency_type`, `deposit_amount`, `initial_deposit`, `deleted_at`
) SELECT
  '25293010-a2b7-4e1d-8e04-e2c3350c8118',
  'seed-capital-25293010',
  'Seed25293DemoApiKey01',
  '301171542199988001',
  'seed+25293@example.invalid',
  'seed-not-used',
  1, 1,
  16741.88, -377.49, 16741.88,
  'EUR', 17119.37, 1884.00,
  NULL
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `accounts` WHERE `name` = 'seed-capital-25293010');

-- Account B (uni_id fa9fb7c5-...)
INSERT INTO `accounts` (
  `uni_id`, `name`, `api_key`, `broker_accountID`, `email`, `password`,
  `demo`, `enabled`, `balance`, `unrealized_pnl`, `equity`,
  `currency_type`, `deposit_amount`, `initial_deposit`, `deleted_at`
) SELECT
  'fa9fb7c5-3920-47fc-8972-6466f721f4c2',
  'seed-capital-fa9fb7c5',
  'SeedFa9fDemoApiKey02',
  '301171542199988002',
  'seed+fa9f@example.invalid',
  'seed-not-used',
  1, 1,
  10000.00, 125.50, 10125.50,
  'USD', 10000.00, 10000.00,
  NULL
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `accounts` WHERE `name` = 'seed-capital-fa9fb7c5');

SET @aid_252 := (SELECT `id` FROM `accounts` WHERE `name` = 'seed-capital-25293010' LIMIT 1);
SET @aid_fa9 := (SELECT `id` FROM `accounts` WHERE `name` = 'seed-capital-fa9fb7c5' LIMIT 1);

DELETE FROM `positions`
WHERE `account_id` IN (@aid_252, @aid_fa9)
  AND `deal_id` LIKE '00000000-0000-4eed-%';

INSERT INTO `positions` (
  `account_id`, `ticker`, `side`, `deal_id`, `price`, `unrealized_pnl`, `increments`, `strategy`
) VALUES
(@aid_252, 'CRWD', 'BUY',  '00000000-0000-4eed-2529-000000000001', 445.50,   17.7000, 1, 'Momentum'),
(@aid_252, 'BRKB', 'SELL', '00000000-0000-4eed-2529-000000000002', 478.21,  -33.9400, 1, 'Swing'),
(@aid_fa9, 'AAPL', 'BUY',  '00000000-0000-4eed-fa9f-000000000001', 262.90,   40.2600, 1, 'Long-term'),
(@aid_fa9, 'GOLD', 'SELL', '00000000-0000-4eed-fa9f-000000000002', 5050.26,  -2.6200, 1, 'Hedge');
