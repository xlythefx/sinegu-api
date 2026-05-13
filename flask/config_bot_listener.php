<?php
/**
 * Bot listener config: used when connect_broker.php notifies the accounts listener,
 * and by disable_account.php for shared secret.
 * Secrets loaded from .env (see /env.php).
 * BOT_LISTENER_SECRET must match ACCOUNTS_LISTENER_SECRET in hooks.py.
 * DISABLE_ACCOUNT_SECRET must match DISABLE_ACCOUNT_SECRET in hooks.py.
 * CAPITAL_API_LOG_SECRET must match CAPITAL_API_LOG_SECRET in src/config.py.
 */
require_once __DIR__ . '/../env.php';

$BOT_LISTENER_URL       = env('BOT_LISTENER_URL', 'http://localhost:5001');
$BOT_LISTENER_SECRET    = env('BOT_LISTENER_SECRET');
$DISABLE_ACCOUNT_SECRET = env('DISABLE_ACCOUNT_SECRET');
$CAPITAL_API_LOG_SECRET = env('CAPITAL_API_LOG_SECRET');
