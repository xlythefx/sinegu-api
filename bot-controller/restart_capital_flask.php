<?php
/**
 * Hard-restart the Capital.com Flask Windows services via NSSM.
 *
 * Unlike Binance (one service), the Capital.com bot runs as several NSSM
 * services — main app + background fetchers/listener. This restarts them all on
 * the VPS where this PHP runs under WAMP, then waits for each to report RUNNING.
 * Success is judged from the service state, never from nssm's exit code — nssm
 * reports a false failure whenever a service is still SERVICE_START_PENDING when
 * it samples. See nssm_lib.php.
 *
 * Service names default to the CapitalFlask-* set and can be overridden per-VPS
 * via .env CAPITAL_SERVICES (comma-separated). Use only for code/config changes
 * — a full restart drops in-flight work; for a position-sizing change prefer the
 * soft cache refresh (refresh_bot.php).
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(204);
    ob_end_flush();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    ob_end_flush();
    exit;
}

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/nssm_lib.php';

// Six services x (restart + state poll) — give it room.
@set_time_limit(300);

$default = 'CapitalFlask-Main,CapitalFlask-Listener,CapitalFlask-Balances,CapitalFlask-Positions,CapitalFlask-Transactions,CapitalFlask-Report';
$services = array_values(array_filter(array_map('trim', explode(',', (string) env('CAPITAL_SERVICES', $default)))));

if (empty($services)) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No Capital.com services configured']);
    ob_end_flush();
    exit;
}

$nssmPath = nssm_path();
$results  = [];
$allOk    = true;

foreach ($services as $svc) {
    $r = nssm_restart_service($nssmPath, $svc, 25);
    if (!$r['success']) {
        $allOk = false;
    }
    $results[] = $r;
}

$restarted = count(array_filter($results, fn($r) => $r['success']));
$total = count($results);

ob_clean();
http_response_code($allOk ? 200 : 207); // 207 = partial
echo json_encode([
    'success' => $allOk,
    'message' => $allOk
        ? "All $total Capital.com services restarted and running"
        : "$restarted/$total Capital.com services running",
    'env'       => nssm_env_name(),
    'restarted' => $restarted,
    'total'     => $total,
    'services'  => $results,
    'hint'      => $allOk ? null : 'Check that nssm.exe exists at C:\\nssm and the WAMP service account can control the CapitalFlask-* services. Override names via .env CAPITAL_SERVICES.',
]);
ob_end_flush();
