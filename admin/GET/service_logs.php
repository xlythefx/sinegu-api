<?php
/**
 * Admin: read the tail of a Flask service log + its NSSM status.
 *
 * This PHP runs under WAMP on the SAME VPS as the Flask services (the same way
 * bot-controller/restart_binance_flask.php runs `nssm restart`). So it can read
 * the local log files directly and query NSSM — no SSH required. The admin
 * Assistant (chatbot) calls this per-environment to "tail" a service.
 *
 * GET /admin/GET/service_logs.php?service=binance|capital|ig&lines=120
 * Auth: if CHATBOT_ADMIN_TOKEN is set in .env, an X-Admin-Token header must match.
 *
 * Services are strictly whitelisted. Log paths are resolved relative to the
 * deployment parent (…\sinegualerts on prod, …\andrea-orcelinvest on staging),
 * which is the grand-parent of this api/ folder. Never accepts arbitrary paths.
 */

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../env.php';

$expectedToken = env('CHATBOT_ADMIN_TOKEN');
if ($expectedToken !== null && $expectedToken !== '') {
    $given = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_GET['token'] ?? '');
    if (!hash_equals((string) $expectedToken, (string) $given)) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

// Deployment parent = grand-parent of api/ (…\<env>\api\admin\GET\this.php).
$parent = dirname(__DIR__, 3);

/**
 * Whitelist: service key -> [ NSSM service name, [candidate log globs relative to $parent] ].
 * NSSM names can be overridden per-VPS via .env (SVC_BINANCE / SVC_CAPITAL / SVC_IG).
 * Candidate globs are tried in order; the most recently modified match is tailed.
 */
$services = [
    'binance' => [
        // Single NSSM service; logs under binance\out\ (service.log + webhook_trades.log).
        'name'  => env('SVC_BINANCE', 'BinanceFlask'),
        'globs' => ['binance/out/*.log', 'binance/out/service.log', 'binance/headless/*.log', 'binance/*.log'],
        'label' => 'Binance Flask',
    ],
    'capital' => [
        // 6 NSSM services (CapitalFlask-Main/-Listener/-Balances/-Positions/-Transactions/-Report);
        // each writes flask\headless\<module>.log. Default status check uses -Main.
        'name'  => env('SVC_CAPITAL', 'CapitalFlask-Main'),
        'globs' => ['flask/headless/*.log', 'flask/sinegualerts.log', 'flask/*.log'],
        'label' => 'Capital.com Flask',
    ],
    'ig' => [
        // IG NSSM services (IGFlask-*); logs under ig\headless\ (ig_main.log + per-module).
        'name'  => env('SVC_IG', 'IGFlask-Main'),
        'globs' => ['ig/headless/*.log', 'ig/*.log'],
        'label' => 'IG Flask',
    ],
];

$service = isset($_GET['service']) ? strtolower(trim($_GET['service'])) : '';
if (!isset($services[$service])) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success'   => false,
        'message'   => 'Unknown service. Use one of: ' . implode(', ', array_keys($services)),
        'available' => array_keys($services),
    ]);
    exit;
}

$lines = isset($_GET['lines']) ? (int) $_GET['lines'] : 120;
$lines = max(10, min(500, $lines));

$cfg = $services[$service];

/** Collect every existing log file across the candidate globs (de-duplicated). */
function collect_log_files($parent, array $globs) {
    $files = [];
    foreach ($globs as $glob) {
        $full = $parent . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $glob);
        $matches = glob($full);
        if (!$matches) {
            continue;
        }
        foreach ($matches as $m) {
            if (is_file($m)) {
                $files[$m] = @filemtime($m) ?: 0;
            }
        }
    }
    arsort($files); // newest first
    return $files; // [absPath => mtime]
}

/**
 * Read the last $n lines of a file. Reads at most the final 512 KB in one pass
 * (bounded memory — safe for large, actively-written rotating logs), then splits
 * once. If the tail 512 KB holds fewer than $n lines we just return what's there.
 */
function read_last_lines($path, $n) {
    $f = @fopen($path, 'rb');
    if (!$f) {
        return null;
    }
    $maxBytes = 512 * 1024;
    fseek($f, 0, SEEK_END);
    $fileSize = ftell($f);
    $toRead = min($fileSize, $maxBytes);
    if ($toRead > 0) {
        fseek($f, $fileSize - $toRead);
        $data = fread($f, $toRead);
    } else {
        $data = '';
    }
    fclose($f);
    if ($data === false) {
        return null;
    }
    $data = str_replace("\r\n", "\n", $data);
    $lines = explode("\n", rtrim($data, "\n"));
    if (count($lines) > $n) {
        $lines = array_slice($lines, -$n);
    }
    return $lines;
}

// --- NSSM status (best-effort; never fatal) ---
$nssmPath = 'C:\\nssm\\nssm.exe';
if (!is_file($nssmPath)) {
    $nssmPath = 'nssm';
}
$statusOut = [];
$statusExit = null;
@exec('"' . $nssmPath . '" status ' . escapeshellarg($cfg['name']) . ' 2>&1', $statusOut, $statusExit);
$nssmStatus = trim(implode(' ', $statusOut));
if ($nssmStatus === '') {
    $nssmStatus = 'unknown';
}

// --- Log tail ---
$allFiles = collect_log_files($parent, $cfg['globs']);          // [absPath => mtime], newest first
$available = array_map('basename', array_keys($allFiles));
$available = array_values(array_unique($available));

// Optional: caller can pick a specific log by basename (e.g. fetch_balances.log).
$fileFilter = isset($_GET['file']) ? basename(trim($_GET['file'])) : null;

$logFile = null;
foreach (array_keys($allFiles) as $abs) {
    if ($fileFilter === null || basename($abs) === $fileFilter) {
        $logFile = $abs; // newest matching (list already sorted newest-first)
        break;
    }
}

$logLines = null;
$logError = null;
if ($logFile === null) {
    $logError = $fileFilter !== null
        ? "No log named '{$fileFilter}' found for this service."
        : 'No log file found for this service on this VPS.';
} else {
    $logLines = read_last_lines($logFile, $lines);
    if ($logLines === null) {
        $logError = 'Log file exists but could not be read.';
    }
}

ob_end_clean();
echo json_encode([
    'success'      => true,
    'service'      => $service,
    'label'        => $cfg['label'],
    'service_name' => $cfg['name'],
    'nssm_status'  => $nssmStatus,
    'log_file'     => $logFile !== null ? str_replace($parent . DIRECTORY_SEPARATOR, '', $logFile) : null,
    'available_logs' => $available,
    'lines'        => $logLines !== null ? $logLines : [],
    'line_count'   => $logLines !== null ? count($logLines) : 0,
    'error'        => $logError,
    'generated_at' => date('c'),
], JSON_INVALID_UTF8_SUBSTITUTE);
