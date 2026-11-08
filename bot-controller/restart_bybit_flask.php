<?php
/**
 * Hard-restart the Bybit Flask services.
 *
 * Twin of restart_ig_flask.php for the Bybit bot (flask/bybit), which
 * was born on the Ubuntu fleet: it drives the six sinegu-bybit-* systemd units
 * through includes/service_control.php. There is no NSSM counterpart — Bybit
 * never ran on the Windows boxes, so on those this reports the units as not
 * deployed rather than pretending to restart them.
 *
 * Success is judged from the state each unit actually reaches, never from the
 * command's exit code — `systemctl restart` returns before a Flask app has
 * bound its port.
 *
 * POST /bot-controller/restart_bybit_flask.php
 * -> 200 all good, 207 partial, with per-unit results.
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
require_once __DIR__ . '/../includes/service_control.php';

// Six units x (restart + state poll) — give it room.
@set_time_limit(300);

$registry = sc_registry();
$expectation = sc_expectation('bybit');

if (!$expectation['expected']) {
    ob_clean();
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'Bybit is not deployed on this box: ' . $expectation['note'],
        'env'     => sc_env_key(),
    ]);
    ob_end_flush();
    exit;
}

$results = [];
$allOk   = true;

foreach ($registry['bybit']['units'] as $u) {
    $r = sc_control($u['unit'], 'restart');
    if (!$r['success']) {
        $allOk = false;
    }
    $results[] = [
        'service'   => $u['unit'],
        'label'     => $u['label'],
        'success'   => $r['success'],
        'state'     => $r['state'],
        'exit_code' => $r['exit_code'],
        'output'    => $r['output'],
        'seconds'   => $r['seconds'],
    ];
}

$restarted = count(array_filter($results, fn($r) => $r['success']));
$total     = count($results);

ob_clean();
http_response_code($allOk ? 200 : 207); // 207 = partial
echo json_encode([
    'success' => $allOk,
    'message' => $allOk
        ? "All $total Bybit services restarted and running"
        : "$restarted/$total Bybit services running",
    'env'       => sc_env_key(),
    'init'      => sc_is_linux() ? 'systemd' : 'nssm',
    'restarted' => $restarted,
    'total'     => $total,
    'services'  => $results,
    'hint'      => $allOk ? null : sc_privilege_hint(),
]);
ob_end_flush();
