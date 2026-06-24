<?php
/**
 * Hard-restart the Binance Flask Windows service via NSSM.
 *
 * Runs `nssm restart BinanceFlask` on the VPS (this PHP runs under WAMP on the
 * same machine as the service). Use when a full restart is genuinely needed
 * (code/config changes). For a simple position-sizing change prefer the soft
 * refresh (refresh_binance_flask.php) — a hard restart drops in-flight webhook
 * trades and the bot re-syncs Binance hedge-mode for every account on startup.
 *
 * Requires the Apache/WAMP service account to have permission to control the
 * BinanceFlask service (Local System does by default).
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

$serviceName = 'BinanceFlask';

// Locate nssm.exe: standard install path first, then PATH fallback.
$nssmPath = 'C:\\nssm\\nssm.exe';
if (!is_file($nssmPath)) {
    $nssmPath = 'nssm'; // rely on PATH
}

$cmd = '"' . $nssmPath . '" restart ' . $serviceName . ' 2>&1';

$output = [];
$exitCode = null;
exec($cmd, $output, $exitCode);

$outputText = trim(implode("\n", $output));

if ($exitCode !== 0) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success'   => false,
        'message'   => 'NSSM restart failed (exit code ' . $exitCode . ')',
        'service'   => $serviceName,
        'exit_code' => $exitCode,
        'output'    => $outputText,
        'hint'      => 'Check that nssm.exe exists at C:\\nssm and that the WAMP/Apache service account can control the BinanceFlask service.',
    ]);
    ob_end_flush();
    exit;
}

ob_clean();
echo json_encode([
    'success' => true,
    'message' => 'Binance Flask service restarted',
    'service' => $serviceName,
    'output'  => $outputText,
]);
ob_end_flush();
