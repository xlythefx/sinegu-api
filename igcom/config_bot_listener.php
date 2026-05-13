<?php
/**
 * IG bot listener config: shared secrets for ig-flask services.
 * Secrets loaded from .env (see /env.php).
 * BOT_LISTENER_SECRET must match ACCOUNTS_LISTENER_SECRET in ig-flask/src/config.py.
 * IG_API_LOG_SECRET must match IG_API_LOG_SECRET in ig-flask/src/config.py.
 */
require_once __DIR__ . '/../env.php';

$BOT_LISTENER_URL       = env('IG_BOT_LISTENER_URL', 'http://localhost:6001');
$BOT_LISTENER_SECRET    = env('IG_BOT_LISTENER_SECRET');
$DISABLE_ACCOUNT_SECRET = env('IG_DISABLE_ACCOUNT_SECRET');
$IG_API_LOG_SECRET      = env('IG_API_LOG_SECRET');
