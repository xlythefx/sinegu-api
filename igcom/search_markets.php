<?php
/**
 * IG instrument lookup for the admin Assets UI.
 *
 * The admin asset form is a free-text ticker box, so "APPL" saves as happily as
 * "AAPL" and only shows up as a failed trade later. This lets the UI offer IG's
 * real instrument list and verify a ticker before it is stored.
 *
 * Two modes:
 *   ?q=<term>        browse — IG's market search, normalised for a picker
 *   ?ticker=<TICKER> verify — the EXACT resolution a live trade would perform
 *
 * The IG Flask app binds 0.0.0.0 but ufw keeps it private and its /admin/ routes
 * are loopback-only, so the browser can never call it directly. This API runs on
 * the same box, which is the whole point of the hop.
 *
 * Returns the Flask payload verbatim on success. Every failure is data, not an
 * exception: a stopped engine must render as "unavailable", never as a 500 the
 * dashboard reports as "Failed to fetch".
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../includes/service_control.php';

$q      = isset($_GET['q']) ? trim($_GET['q']) : '';
$ticker = isset($_GET['ticker']) ? trim($_GET['ticker']) : '';

if ($q === '' && $ticker === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'missing_query',
        'message' => "Pass either 'q' to search or 'ticker' to verify.",
    ]);
    exit;
}

// Same default as includes/service_control.php. Kept in code rather than added as
// a new .env key: deploy-api never ships .env, so a new key would be absent on
// every box and this would silently 503 until someone edited each one by hand.
$port = (int) env('IG_WEBHOOK_PORT', 5002);

if ($ticker !== '') {
    $path = '/admin/markets/resolve?ticker=' . rawurlencode(strtoupper($ticker));
} else {
    $path = '/admin/markets/search?q=' . rawurlencode($q);
}

// Generous next to the 1.5s health probes: this hop makes a live authenticated
// call out to IG, so it is bounded by IG's latency rather than by loopback.
$probe = sc_http_probe($port, $path, 12.0);

if (!$probe['ok'] && $probe['status'] === null) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error'   => 'engine_unreachable',
        'message' => 'The IG engine is not responding. Check that sinegu-ig-main is running.',
    ]);
    exit;
}

$status = $probe['status'] ?: 502;
http_response_code($status);

if (is_array($probe['data'])) {
    echo json_encode($probe['data']);
    exit;
}

echo json_encode([
    'success' => false,
    'error'   => 'bad_engine_response',
    'message' => 'The IG engine returned an unreadable response.',
    'status'  => $status,
]);
