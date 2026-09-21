-- Migration: Allow broker = 'mexc' in trade_logs.
-- Created: 2026-09-21
-- Description: trade_logs.broker was ENUM('capital','binance','ig') (084). The MEXC bot
-- (mexc-flask) posts its signal log through the same flask/log_trade_signal.php with
-- broker 'mexc'; without this value the INSERT is refused and the signal is never
-- recorded. Widening an ENUM by appending a value is an in-place metadata change.

ALTER TABLE `trade_logs`
MODIFY `broker` ENUM('capital','binance','ig','mexc') NOT NULL;
