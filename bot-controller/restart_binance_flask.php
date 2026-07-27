<?php
/**
 * Hard-restart the Binance Flask Windows service via NSSM.
 *
 * Runs `nssm restart BinanceFlask` on the VPS (this PHP runs under WAMP on the
 * same machine as the service), then WAITS for the service to report RUNNING and
 * reports success from that — not from nssm's exit code, which is a false
 * failure whenever the bot is still SERVICE_START_PENDING when nssm samples it.
 * See nssm_lib.php for the full explanation.
 *
 * Use when a full restart is genuinely needed (code/config changes). For a simple
 * position-sizing change prefer the soft refresh (refresh_binance_flask.php) — a
 * hard restart drops in-flight webhook trades and the bot re-syncs Binance
 * hedge-mode for every account on startup.
 *
 * Requires the Apache/WAMP service account to have permission to control the
 * BinanceFlask service (Local System does by default).
 *
 * Service name is overridable per-VPS via .env BINANCE_SERVICE.
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

// Restart + state poll can take ~30s; don't let PHP time out mid-way.
@set_time_limit(180);

$serviceName = (string) env('BINANCE_SERVICE', 'BinanceFlask');
$nssmPath    = nssm_path();

$result = nssm_restart_service($nssmPath, $serviceName, 30);

ob_clean();

if (!$result['success']) {
    http_response_code(500);
    echo json_encode([
        'success'   => false,
        'message'   => 'Binance Flask did not come back up (state: ' . $result['state'] . ')',
        'env'       => nssm_env_name(),
        'service'   => $serviceName,
        'state'     => $result['state'],
        'exit_code' => $result['exit_code'],
        'output'    => $result['output'],
        'seconds'   => $result['seconds'],
        'hint'      => 'Check that nssm.exe exists at C:\\nssm, that the service name is right (.env BINANCE_SERVICE), and that the WAMP/Apache account can control it. Service logs: binance-flask on the VPS.',
    ]);
    ob_end_flush();
    exit;
}

echo json_encode([
    'success'   => true,
    'message'   => 'Binance Flask service restarted and running (' . $result['seconds'] . 's)',
    'env'       => nssm_env_name(),
    'service'   => $serviceName,
    'state'     => $result['state'],
    'exit_code' => $result['exit_code'],
    'output'    => $result['output'],
    'seconds'   => $result['seconds'],
]);
ob_end_flush();
