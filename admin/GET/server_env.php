<?php
/**
 * Admin: report which physical box is actually serving this API.
 *
 * The dashboard's environment badge used to derive everything from
 * `window.location.hostname`, which is just whatever is in the address bar —
 * so on https://sinegualfamily.com it could only ever show the domain, never
 * the server. Worse, the domain is proxied through Cloudflare, so even a DNS
 * lookup from the browser returns an edge IP rather than the origin.
 *
 * Only the server knows its own address, so it reports it here.
 *
 * GET /admin/GET/server_env.php
 * ->  { success, ip, env, label, host, hostname, os, php, server_software }
 *
 * NOTE this deliberately returns a FINER-GRAINED env key than env.php's
 * app_server_env(). That function answers "which brand am I?" (prod =
 * sinegual, staging = andrea orcel) because it picks Stripe / Coinsbuy return
 * URLs — sinegualfamily.com must resolve to 'prod' there no matter which box
 * hosts it. This endpoint answers "which machine am I?", so it separates the
 * Windows VPSs from the Ubuntu ones. Don't merge the two.
 *
 * Auth: none, matching the other admin/GET endpoints (admin sessions are
 * client-side only, so a token here would just break the badge). It does
 * disclose the Cloudflare origin IP — gate it behind CHATBOT_ADMIN_TOKEN if
 * that ever matters more than the badge does.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

/** Known boxes: server IP -> [env key, human label]. */
$BOXES = [
    '167.86.109.159'  => ['prod',           'PROD'],
    '156.67.30.81'    => ['staging',        'STAGING'],
    '191.218.162.171' => ['ubuntu-prod',    'UBUNTU PROD'],
    '191.218.162.182' => ['ubuntu-staging', 'UBUNTU STAGING'],
];

$ip       = trim((string) ($_SERVER['SERVER_ADDR'] ?? ''));
$hostname = (string) php_uname('n');
$host     = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

// nginx/apache normally set SERVER_ADDR; fall back to resolving our own name.
if ($ip === '') {
    $resolved = gethostbyname($hostname);
    if ($resolved !== $hostname) {
        $ip = $resolved;
    }
}

// A loopback SERVER_ADDR (reverse proxy on 127.0.0.1) hides the real box, so
// prefer the first non-loopback address the host actually owns.
if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
    $resolved = gethostbyname($hostname);
    if (filter_var($resolved, FILTER_VALIDATE_IP) && strpos($resolved, '127.') !== 0) {
        $ip = $resolved;
    }
}

$env   = 'unknown';
$label = 'UNKNOWN';

if (isset($BOXES[$ip])) {
    [$env, $label] = $BOXES[$ip];
} elseif ($ip === '127.0.0.1' || $ip === '::1' || strpos($host, 'localhost') === 0) {
    $env   = 'local';
    $label = 'LOCAL';
}

echo json_encode([
    'success'         => true,
    'ip'              => $ip,
    'env'             => $env,
    'label'           => $label,
    'host'            => $host,
    'hostname'        => $hostname,
    'os'              => PHP_OS_FAMILY,
    'php'             => PHP_VERSION,
    'server_software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
]);
