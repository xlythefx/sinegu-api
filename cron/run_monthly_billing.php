<?php
/**
 * Cron orchestrator: monthly billing run.
 * Schedule: 1st of month, 00:05 America/New_York (fired by the Telegram billing cron listener).
 *
 * Chains the two existing PHP endpoints in order, in one request:
 *   1. generate_invoices.php          — create last month's invoices (pending)
 *   2. stripe/auto-charge-invoices.php — charge saved default cards for pending invoices
 *
 * All billing logic stays in those endpoints; this only sequences them and returns a
 * combined summary. Secret-guarded (BILLING_CRON_SECRET) — see includes/cron_auth.php.
 *
 * CLI:  php cron/run_monthly_billing.php
 * HTTP: POST http://localhost/sinegu-api/cron/run_monthly_billing.php  (header X-Billing-Secret)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Billing-Secret');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/cron_auth.php';
require_billing_cron_secret();

$isCli = php_sapi_name() === 'cli';
date_default_timezone_set('America/New_York');

// Proof-of-run log (mirrors the other cron scripts).
@file_put_contents(
    __DIR__ . '/../cron_monthly_billing.log',
    date('Y-m-d H:i:s') . ' — run_monthly_billing.php run_mode=' . ($isCli ? 'cli' : 'http') . "\n",
    FILE_APPEND
);

// Where to reach our own endpoints. Overridable via .env SELF_API_BASE.
$selfBase = rtrim((string) env('SELF_API_BASE', 'http://localhost/sinegu-api'), '/');

/**
 * POST JSON to one of our endpoints and decode the response.
 * Returns ['ok' => bool, 'http_code' => int, 'json' => array|null, 'raw' => string, 'error' => string|null].
 */
function billing_post($url, array $body) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_TIMEOUT, 600); // auto-charge over many accounts can be slow
    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'http_code' => $httpCode, 'json' => null, 'raw' => '', 'error' => $curlErr ?: 'curl failed'];
    }
    $json = json_decode($raw, true);
    $ok = ($httpCode >= 200 && $httpCode < 300) && is_array($json) && (($json['success'] ?? false) === true);
    return ['ok' => $ok, 'http_code' => $httpCode, 'json' => is_array($json) ? $json : null, 'raw' => $raw, 'error' => $ok ? null : ($json['error'] ?? 'non-success response')];
}

$summary = [
    'success'  => true,
    'run_mode' => $isCli ? 'cli' : 'http',
    'ran_at'   => date('Y-m-d H:i:s T'),
    'steps'    => [],
];

// ---------- Step 1: generate invoices for last month ----------
// HTTP call: demo accounts are excluded by default (generate_invoices.php default).
$gen = billing_post($selfBase . '/generate_invoices.php', []);
$summary['steps']['generate'] = [
    'ok'        => $gen['ok'],
    'http_code' => $gen['http_code'],
    'error'     => $gen['error'],
    'result'    => $gen['json'],
];
if (!$gen['ok']) {
    // Do not auto-charge if generation failed — avoids charging against a half-built set.
    $summary['success'] = false;
    $summary['steps']['auto_charge'] = ['skipped' => true, 'reason' => 'generation step failed'];
    http_response_code(502);
    echo json_encode($summary);
    exit;
}

// ---------- Step 2: auto-charge pending invoices with saved cards ----------
$charge = billing_post($selfBase . '/stripe/auto-charge-invoices.php', [
    'skip_demo' => true,
]);
$summary['steps']['auto_charge'] = [
    'ok'        => $charge['ok'],
    'http_code' => $charge['http_code'],
    'error'     => $charge['error'],
    'result'    => $charge['json'],
];
if (!$charge['ok']) {
    $summary['success'] = false;
}

http_response_code($summary['success'] ? 200 : 502);
echo json_encode($summary);
