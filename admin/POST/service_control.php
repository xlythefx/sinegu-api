<?php
/**
 * Admin: start / stop / restart one engine unit.
 *
 * POST /admin/POST/service_control.php
 *   { "unit": "sinegu-binance-performance", "action": "restart", "admin_password": "…" }
 * ->  { success, unit, action, state, output, seconds }
 *
 * SECURITY, and why the password guard is here:
 *
 * The admin API has no server-side session auth — AdminRouteGuard in the React
 * app is a client-side email allowlist, so every admin/* endpoint is reachable
 * with curl by anyone who knows the URL. For a read-only endpoint that is a
 * disclosure problem; for THIS one it would mean a stranger could stop live
 * trading. So it reuses the same guard the destructive invoice endpoints
 * already use (includes/admin_action_auth.php -> requireAdminActionPassword),
 * checked server-side precisely because a frontend-only prompt is bypassable.
 *
 * That guard is a speed bump against accidental and casual access, NOT real
 * authentication. Proper auth on admin/* remains outstanding.
 *
 * The unit name is never used as given: it is looked up in the registry in
 * includes/service_control.php and only a registry-owned string reaches the
 * shell. On Linux the actual privilege comes from a sudoers rule limited to
 * `systemctl {start,stop,restart} sinegu-*` — see sc_privilege_hint().
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../env.php';
require_once __DIR__ . '/../../includes/service_control.php';

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = [];
}

// Exits 401 on failure.
require_once __DIR__ . '/../../includes/admin_action_auth.php';
requireAdminActionPassword($input);

$unit   = isset($input['unit']) ? (string) $input['unit'] : '';
$action = isset($input['action']) ? strtolower(trim((string) $input['action'])) : '';

if (!in_array($action, ['start', 'stop', 'restart'], true)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'action must be one of: start, stop, restart',
    ]);
    exit;
}

// The whitelist. Anything not in the registry never reaches a shell.
$entry = sc_find_unit($unit);
if ($entry === null) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success'        => false,
        'error'          => 'Unknown unit',
        'known_units'    => sc_all_units(),
    ]);
    exit;
}

$result = sc_control($entry['unit'], $action);

// Best-effort audit trail; never let a logging failure change the outcome.
$logged = false;
require_once __DIR__ . '/../../conn.php';
if ($conn) {
    @require_once __DIR__ . '/../../includes/api_log.php';
    if (function_exists('api_log')) {
        @api_log(
            $conn,
            'service_control',
            $action . ' ' . $entry['unit'] . ' -> ' . $result['state'],
            $action,
            $result['success'] ? 1 : 0
        );
        $logged = true;
    }
    mysqli_close($conn);
}

ob_end_clean();
if (!$result['success']) {
    // 409, not 500: the request was understood and executed, the unit just did
    // not reach the state we asked for. The page shows `output` verbatim.
    http_response_code(409);
}
echo json_encode([
    'success'        => $result['success'],
    'unit'           => $result['unit'],
    'label'          => $entry['label'],
    'broker'         => $entry['broker'],
    'action'         => $result['action'],
    'state'          => $result['state'],
    'exit_code'      => $result['exit_code'],
    'output'         => $result['output'],
    'seconds'        => $result['seconds'],
    'audit_logged'   => $logged,
    'privilege_hint' => $result['success'] ? null : sc_privilege_hint(),
    'generated_at'   => date('c'),
], JSON_INVALID_UTF8_SUBSTITUTE);
