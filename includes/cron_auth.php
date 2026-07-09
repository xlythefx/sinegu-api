<?php
/**
 * Shared secret guard for cron-triggered billing endpoints.
 *
 * The Telegram bot (billing cron listener) calls these endpoints over HTTP and
 * must present BILLING_CRON_SECRET (from .env). Enforcement policy:
 *   - CLI runs (php.exe / Task Scheduler) are always allowed.
 *   - If BILLING_CRON_SECRET is not set in .env, the guard is a no-op (so nothing
 *     breaks before the secret is deployed). Once set, it is enforced for HTTP.
 *
 * The caller may pass the secret via any of:
 *   - HTTP header  X-Billing-Secret: <secret>
 *   - Query string ?secret=<secret>
 *   - POST field   secret=<secret>
 *   - JSON body    {"secret": "<secret>"}
 *
 * Usage (top of an endpoint, after headers are set):
 *   require_once __DIR__ . '/../includes/cron_auth.php';
 *   require_billing_cron_secret();   // sends 403 + exits on mismatch
 */

require_once __DIR__ . '/../env.php';

if (!function_exists('billing_cron_secret')) {
    function billing_cron_secret() {
        return (string) env('BILLING_CRON_SECRET', '');
    }
}

if (!function_exists('billing_cron_provided_secret')) {
    /** Pull the presented secret from header / query / POST / JSON body. */
    function billing_cron_provided_secret() {
        // Header (Apache/most SAPIs expose it as HTTP_X_BILLING_SECRET)
        if (!empty($_SERVER['HTTP_X_BILLING_SECRET'])) {
            return (string) $_SERVER['HTTP_X_BILLING_SECRET'];
        }
        if (isset($_GET['secret']) && $_GET['secret'] !== '') {
            return (string) $_GET['secret'];
        }
        if (isset($_POST['secret']) && $_POST['secret'] !== '') {
            return (string) $_POST['secret'];
        }
        // JSON body (does not disturb a later php://input read meaningfully for these endpoints)
        $raw = @file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['secret']) && $decoded['secret'] !== '') {
                return (string) $decoded['secret'];
            }
        }
        return '';
    }
}

if (!function_exists('require_billing_cron_secret')) {
    /**
     * Enforce the billing cron secret. Returns true when allowed; otherwise
     * emits a 403 JSON response and exits.
     */
    function require_billing_cron_secret() {
        // CLI is always trusted (Task Scheduler / local php.exe).
        if (php_sapi_name() === 'cli') {
            return true;
        }
        $expected = billing_cron_secret();
        // Not configured yet -> no-op (back-compat until the secret is deployed).
        if ($expected === '') {
            return true;
        }
        $provided = billing_cron_provided_secret();
        if ($provided !== '' && hash_equals($expected, $provided)) {
            return true;
        }
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Forbidden: invalid or missing billing cron secret']);
        exit;
    }
}
