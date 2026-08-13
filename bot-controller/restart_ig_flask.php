<?php
/**
 * Hard-restart the IG.com Flask services.
 *
 * The missing sibling of restart_capital_flask.php / restart_binance_flask.php —
 * IG was the one broker with no restart endpoint at all, so the admin Flask
 * Control page could restart Capital and Binance but silently offered nothing
 * for IG.
 *
 * OS-aware, unlike its two siblings: it drives systemd on the Ubuntu boxes and
 * NSSM on the Windows ones, through includes/service_control.php. Success is
 * judged from the state the unit actually reaches, never from the command's exit
 * code — nssm reports a false failure whenever it samples a service that is
 * still SERVICE_START_PENDING (see nssm_lib.php), and `systemctl restart`
 * returns before a Flask app has bound its port.
 *
 * POST /bot-controller/restart_ig_flask.php
 * -> 200 all good, 207 partial, with per-unit results.
 *
 * Staging never ran IG, so on those boxes this reports the units as not
 * deployed rather than pretending to restart them.
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
$expectation = sc_expectation('ig');

if (!$expectation['expected']) {
    ob_clean();
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'IG is not deployed on this box: ' . $expectation['note'],
        'env'     => sc_env_key(),
    ]);
    ob_end_flush();
    exit;
}

$results = [];
$allOk   = true;

foreach ($registry['ig']['units'] as $u) {
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
        ? "All $total IG.com services restarted and running"
        : "$restarted/$total IG.com services running",
    'env'       => sc_env_key(),
    'init'      => sc_is_linux() ? 'systemd' : 'nssm',
    'restarted' => $restarted,
    'total'     => $total,
    'services'  => $results,
    'hint'      => $allOk ? null : sc_privilege_hint(),
]);
ob_end_flush();
