<?php
/**
 * Cron orchestrator: monthly billing run.
 * Schedule: 1st of month, 00:05 America/New_York (fired by the Telegram billing cron listener).
 *
 * Sequences the billing steps in one request, all logic staying in the called endpoints:
 *   0. Refresh broker balances  — POST /admin/balances/refresh on each flask app (capital :5000,
 *      binance :5001, ig :5002). Synchronous; blocks until each broker's accounts are written to
 *      the DB, so invoices bill against fresh unrealized P&L. NON-FATAL: a broker/login failure
 *      logs a warning and does not block invoicing for everyone.
 *   1. Generate invoices        — generate_invoices.php (capital) + _binance + _ig. Each is
 *      transactional and idempotent per account+month; a failure is a warning, not a hard stop.
 *   2. Auto-charge              — stripe/auto-charge-invoices.php (all brokers, saved cards).
 *
 * Secret-guarded (BILLING_CRON_SECRET) — see includes/cron_auth.php.
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

// Each broker's flask app on this VPS (for the pre-invoice balance refresh). Overridable via .env.
$flaskBases = [
    'capital.com' => rtrim((string) env('CAPITAL_FLASK_URL', 'http://localhost:5000'), '/'),
    'binance'     => rtrim((string) env('BINANCE_FLASK_URL', 'http://localhost:5001'), '/'),
    'ig'          => rtrim((string) env('IG_FLASK_URL', 'http://localhost:5002'), '/'),
];

// The three invoice generators (one per broker), keyed the same as $flaskBases.
$generators = [
    'capital.com' => '/generate_invoices.php',
    'binance'     => '/generate_invoices_binance.php',
    'ig'          => '/generate_invoices_ig.php',
];

/**
 * POST JSON to a URL and decode the response.
 * Returns ['ok' => bool, 'http_code' => int, 'json' => array|null, 'raw' => string, 'error' => string|null].
 */
function billing_post($url, array $body, $timeout = 600) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
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
    'warnings' => [],
    'steps'    => [],
];

// ---------- Step 0: refresh balances on each broker (non-fatal) ----------
// Each flask /admin/balances/refresh is synchronous and returns only when all its accounts are
// fetched from the broker and written to the DB. We wait for each so generation reads fresh data.
$refresh = [];
$refreshAllOk = true;
foreach ($flaskBases as $broker => $base) {
    $r = billing_post($base . '/admin/balances/refresh', [], 600);
    $refresh[$broker] = ['ok' => $r['ok'], 'http_code' => $r['http_code'], 'error' => $r['error'], 'result' => $r['json']];
    if (!$r['ok']) {
        $refreshAllOk = false;
        $summary['warnings'][] = "balance refresh failed for $broker: " . ($r['error'] ?? 'unknown');
    }
}
$summary['steps']['refresh_balances'] = ['ok' => $refreshAllOk, 'by_broker' => $refresh];

// ---------- Step 1: generate invoices for all brokers (non-fatal per broker) ----------
// Demo accounts are excluded by default over HTTP. Each generator is transactional (a failure
// rolls back that broker's run, so there is no half-built set), so we proceed to charging.
$gen = [];
$genAllOk = true;
foreach ($generators as $broker => $path) {
    $r = billing_post($selfBase . $path, []);
    $gen[$broker] = ['ok' => $r['ok'], 'http_code' => $r['http_code'], 'error' => $r['error'], 'result' => $r['json']];
    if (!$r['ok']) {
        $genAllOk = false;
        $summary['warnings'][] = "invoice generation failed for $broker: " . ($r['error'] ?? 'unknown');
    }
}
$summary['steps']['generate'] = ['ok' => $genAllOk, 'by_broker' => $gen];

// ---------- Step 2: auto-charge pending invoices with saved cards ----------
$charge = billing_post($selfBase . '/stripe/auto-charge-invoices.php', ['skip_demo' => true]);
$summary['steps']['auto_charge'] = [
    'ok'        => $charge['ok'],
    'http_code' => $charge['http_code'],
    'error'     => $charge['error'],
    'result'    => $charge['json'],
];

// Overall success is gated on the money step (charging). Refresh/generation issues are warnings:
// existing pending invoices are still legitimately chargeable even if this month's generation or a
// balance refresh hit a snag.
$summary['success'] = $charge['ok'];
http_response_code($charge['ok'] ? 200 : 502);
echo json_encode($summary);
