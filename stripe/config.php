<?php
/**
 * Stripe Configuration
 *
 * Secrets are loaded from .env (see /env.php). Set APP_ENV in .env:
 *  - APP_ENV=development -> uses STRIPE_*_TEST keys + FRONTEND_BASE_URL_DEV / API_BASE_URL_DEV
 *  - APP_ENV=production  -> uses live STRIPE_* keys + FRONTEND_BASE_URL / API_BASE_URL
 */

require_once __DIR__ . '/../env.php';

$isDevelopment = (strtolower((string) env('APP_ENV', 'production')) === 'development');

if ($isDevelopment) {
    define('STRIPE_SECRET_KEY',      env('STRIPE_SECRET_KEY_TEST'));
    define('STRIPE_PUBLISHABLE_KEY', env('STRIPE_PUBLISHABLE_KEY_TEST'));
    define('STRIPE_WEBHOOK_SECRET',  env('STRIPE_WEBHOOK_SECRET_TEST'));
    define('FRONTEND_BASE_URL',      env('FRONTEND_BASE_URL_DEV', 'http://localhost:8080'));
    define('API_BASE_URL',           env('API_BASE_URL_DEV', 'http://localhost/sinegu-api'));
    define('STRIPE_ENVIRONMENT', 'test');
} else {
    define('STRIPE_SECRET_KEY',      env('STRIPE_SECRET_KEY'));
    define('STRIPE_PUBLISHABLE_KEY', env('STRIPE_PUBLISHABLE_KEY'));
    define('STRIPE_WEBHOOK_SECRET',  env('STRIPE_WEBHOOK_SECRET'));
    define('FRONTEND_BASE_URL',      env('FRONTEND_BASE_URL', 'https://sinegualfamily.com'));
    define('API_BASE_URL',           env('API_BASE_URL', 'https://api.sinegualfamily.com'));
    define('STRIPE_ENVIRONMENT', 'live');
}

// ============================================
// STRIPE SETTINGS
// ============================================

define('DEFAULT_CURRENCY', 'usd');
define('SUPPORTED_CURRENCIES', ['usd', 'eur']);
define('PAYMENT_METHOD_TYPES', ['card']);

// URL paths
define('SUCCESS_URL_PATH', '/user/invoices?payment=success&session_id={CHECKOUT_SESSION_ID}');
define('CANCEL_URL_PATH', '/user/invoices?payment=cancelled');

// ============================================
// HELPER FUNCTIONS
// ============================================

function getSuccessUrl() {
    return FRONTEND_BASE_URL . SUCCESS_URL_PATH;
}

function getCancelUrl() {
    return FRONTEND_BASE_URL . CANCEL_URL_PATH;
}

function isDevelopment() {
    return STRIPE_ENVIRONMENT === 'test';
}

function getStripeSecretKey() {
    return STRIPE_SECRET_KEY;
}

function getStripeWebhookSecret() {
    return STRIPE_WEBHOOK_SECRET;
}

function getWebhookUrl() {
    return API_BASE_URL . '/stripe/webhook.php';
}
