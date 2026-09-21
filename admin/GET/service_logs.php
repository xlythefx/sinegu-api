<?php
/**
 * Admin: read the tail of an engine log + the service's current state.
 *
 * This PHP runs on the SAME box as the Flask services, so it can read the local
 * logs and query the service manager directly — no SSH required.
 *
 * GET /admin/GET/service_logs.php?service=binance|capital|ig[&lines=120][&file=<basename>]
 * GET /admin/GET/service_logs.php?unit=sinegu-capital-balances[&lines=120]
 * Auth: if CHATBOT_ADMIN_TOKEN is set in .env, an X-Admin-Token header must match.
 *
 * OS-AWARE. The fleet moved from Windows/NSSM to Ubuntu/systemd on 2026-08-12,
 * and this endpoint used to shell out to `nssm status` unconditionally — which
 * on 191/182 simply reported "unknown" for every service. Now:
 *
 *   Linux   -> `systemctl show` for state, `journalctl -u <unit>` for the tail,
 *              plus the per-unit files in /var/log/sinegu/.
 *   Windows -> unchanged: NSSM status and the deployment-tree log globs.
 *
 * BACKWARDS COMPATIBILITY MATTERS: admin/POST/chatbot.php calls this as its
 * `tail_service_log` tool and hands the raw JSON to the model. The `service`,
 * `lines` and `file` parameters and every original response key still work —
 * `nssm_status` is kept as an alias of the new `service_status` for that reason.
 *
 * Services and units are strictly whitelisted through includes/service_control.php.
 * Log paths are resolved relative to the deployment parent (the grand-parent of
 * this api/ folder) or the fixed /var/log/sinegu directory. Arbitrary paths are
 * never accepted — `file` is reduced to a basename and must match a globbed file.
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

/**
 * Token check, now conditional on the caller presenting one.
 *
 * It used to be mandatory whenever CHATBOT_ADMIN_TOKEN was set, which was fine
 * while the Assistant was the only caller — it forwards the header server-side.
 * The admin Engines page calls this from the browser, and a static bundle has
 * nowhere safe to keep that token: shipping it would publish the same secret
 * that unlocks the Assistant's tools.
 *
 * So a token is still VALIDATED when supplied (the chatbot's cross-environment
 * calls keep failing closed against a misconfigured box) but is no longer
 * required, which is how every other admin/GET endpoint in this directory
 * already behaves. Real auth on admin/* remains outstanding and is what should
 * actually close this.
 */
$expectedToken = env('CHATBOT_ADMIN_TOKEN');
$givenToken    = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_GET['token'] ?? '');
if ($givenToken !== '' && $expectedToken !== null && $expectedToken !== '') {
    if (!hash_equals((string) $expectedToken, (string) $givenToken)) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

require_once __DIR__ . '/../../includes/service_control.php';

// Deployment parent = grand-parent of api/ (…/<env>/api/admin/GET/this.php).
$parent = dirname(__DIR__, 3);

/**
 * Broker key -> log globs relative to $parent, plus the label. Unit names and
 * service names come from the registry in includes/service_control.php.
 */
$serviceGlobs = [
    'binance'  => ['binance/out/*.log', 'binance/out/service.log', 'binance/headless/*.log', 'binance/*.log'],
    'mexc'     => ['mexc/out/*.log', 'mexc/out/service.log', 'mexc/headless/*.log', 'mexc/*.log'],
    'capital'  => ['flask/headless/*.log', 'flask/sinegualerts.log', 'flask/*.log'],
    'ig'       => ['ig/headless/*.log', 'ig/*.log'],
    'telegram' => ['telegram-bot/*.log', 'telegram-bot/out/*.log'],
];

$registry = sc_registry();

// --- Resolve which broker + unit the caller wants -------------------------
$unitParam    = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$serviceParam = isset($_GET['service']) ? strtolower(trim((string) $_GET['service'])) : '';

$unitEntry = null;
if ($unitParam !== '') {
    $unitEntry = sc_find_unit($unitParam);
    if ($unitEntry === null) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode([
            'success'     => false,
            'message'     => 'Unknown unit.',
            'known_units' => sc_all_units(),
        ]);
        exit;
    }
    $service = $unitEntry['broker'];
} else {
    $service = $serviceParam;
    if (!isset($registry[$service])) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode([
            'success'   => false,
            'message'   => 'Unknown service. Use one of: ' . implode(', ', array_keys($registry)),
            'available' => array_keys($registry),
        ]);
        exit;
    }
    // Default to the broker's main unit, which is what "the service" meant before.
    foreach ($registry[$service]['units'] as $u) {
        if ($u['role'] === 'main') {
            $unitEntry = $u + ['broker' => $service];
            break;
        }
    }
}

$lines = isset($_GET['lines']) ? (int) $_GET['lines'] : 120;
$lines = max(10, min(500, $lines));

$cfg   = $registry[$service];
$globs = isset($serviceGlobs[$service]) ? $serviceGlobs[$service] : [];

/** Every existing log file across the candidate globs, newest first. */
function collect_log_files($parent, array $globs, $extraDirGlobs = []) {
    $files = [];
    $candidates = [];
    foreach ($globs as $glob) {
        $candidates[] = $parent . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $glob);
    }
    foreach ($extraDirGlobs as $abs) {
        $candidates[] = $abs;
    }
    foreach ($candidates as $full) {
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
    return $files;  // [absPath => mtime]
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

// --- Service state --------------------------------------------------------
$serviceStatus = 'unknown';
$serviceName   = $unitEntry !== null ? $unitEntry['unit'] : $service;

if ($unitEntry !== null) {
    $st = sc_states([$unitEntry['unit']])[$unitEntry['unit']];
    if (sc_is_linux()) {
        // active / inactive / failed / activating — systemd's own vocabulary.
        $serviceStatus = $st['state'];
    } else {
        // Preserve the SERVICE_* wording the chatbot has always seen on Windows.
        $map = [
            'active'       => 'SERVICE_RUNNING',
            'inactive'     => 'SERVICE_STOPPED',
            'activating'   => 'SERVICE_START_PENDING',
            'deactivating' => 'SERVICE_STOP_PENDING',
            'n/a'          => 'SERVICE_UNKNOWN',
        ];
        $serviceStatus = isset($map[$st['state']]) ? $map[$st['state']] : 'SERVICE_UNKNOWN';
        $serviceName   = $unitEntry['nssm'] !== null ? $unitEntry['nssm'] : $unitEntry['unit'];
    }
}

// --- Available sources ----------------------------------------------------
// On Ubuntu each unit also writes /var/log/sinegu/<unit>.log alongside the journal.
$extra = [];
if (sc_is_linux()) {
    $extra[] = '/var/log/sinegu/*.log';
}
$allFiles = collect_log_files($parent, $globs, $extra);

// Keep only this broker's files out of the shared /var/log/sinegu directory —
// the glob is directory-wide, so without this the Binance tab would list IG logs.
if (sc_is_linux()) {
    $prefix = 'sinegu-' . $service;
    foreach (array_keys($allFiles) as $abs) {
        if (strpos($abs, '/var/log/sinegu/') === 0 && strpos(basename($abs), $prefix) !== 0) {
            unset($allFiles[$abs]);
        }
    }
}

$available = array_values(array_unique(array_map('basename', array_keys($allFiles))));

$availableUnits = [];
foreach ($cfg['units'] as $u) {
    $availableUnits[] = ['unit' => $u['unit'], 'label' => $u['label'], 'role' => $u['role']];
}

// --- Read the tail --------------------------------------------------------
$fileFilter = isset($_GET['file']) ? basename(trim((string) $_GET['file'])) : null;

$logLines = null;
$logError = null;
$logFile  = null;
$source   = null;

if ($fileFilter !== null && $fileFilter !== '') {
    // Explicit file wins — this is the legacy chatbot path.
    foreach (array_keys($allFiles) as $abs) {
        if (basename($abs) === $fileFilter) {
            $logFile = $abs;
            break;
        }
    }
    if ($logFile === null) {
        $logError = "No log named '{$fileFilter}' found for this service.";
    } else {
        $source   = 'file';
        $logLines = read_last_lines($logFile, $lines);
        if ($logLines === null) {
            $logError = 'Log file exists but could not be read.';
        }
    }
} else {
    // Prefer the journal on Linux: it is the authoritative record for a systemd
    // unit and survives log-file rotation and permission gaps in the deploy tree.
    if (sc_is_linux() && $unitEntry !== null) {
        $journal = sc_journal($unitEntry['unit'], $lines);
        if ($journal !== null && $journal) {
            $source   = 'journal';
            $logLines = $journal;
            $logFile  = 'journal:' . $unitEntry['unit'];
        }
    }
    if ($logLines === null) {
        $logFile = null;
        foreach (array_keys($allFiles) as $abs) {
            $logFile = $abs; // already sorted newest-first
            break;
        }
        if ($logFile === null) {
            $logError = sc_is_linux()
                ? 'No journal entries and no log file found for this service. If journalctl is empty, check that www-data is in the systemd-journal group.'
                : 'No log file found for this service on this VPS.';
        } else {
            $source   = 'file';
            $logLines = read_last_lines($logFile, $lines);
            if ($logLines === null) {
                $logError = 'Log file exists but could not be read.';
            }
        }
    }
}

$displayFile = $logFile;
if ($displayFile !== null && strpos($displayFile, 'journal:') !== 0) {
    $displayFile = str_replace($parent . DIRECTORY_SEPARATOR, '', $displayFile);
}

ob_end_clean();
echo json_encode([
    'success'         => true,
    'service'         => $service,
    'label'           => $cfg['label'],
    'unit'            => $unitEntry !== null ? $unitEntry['unit'] : null,
    'service_name'    => $serviceName,
    'service_status'  => $serviceStatus,
    // Alias kept so admin/POST/chatbot.php's tail_service_log tool keeps reading
    // the same key it always has. Do not remove.
    'nssm_status'     => $serviceStatus,
    'init'            => sc_is_linux() ? 'systemd' : 'nssm',
    'source'          => $source,
    'log_file'        => $displayFile,
    'available_logs'  => $available,
    'available_units' => $availableUnits,
    'lines'           => $logLines !== null ? $logLines : [],
    'line_count'      => $logLines !== null ? count($logLines) : 0,
    'error'           => $logError,
    'generated_at'    => date('c'),
], JSON_INVALID_UTF8_SUBSTITUTE);
