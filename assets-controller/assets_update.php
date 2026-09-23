<?php

// Start output buffering
ob_start();

// Suppress warnings to prevent HTML output before JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

// PHP 8.1+ makes mysqli throw by default. A DB-level rejection (e.g. a leftover
// CHECK constraint on base_size / max_increments) would then fatal and return an
// empty body, which the dashboard reports as "Unexpected end of JSON input".
// Turn reporting off so failures fall through to the JSON error responses below.
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

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
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    ob_end_flush();
    exit;
}

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
    ]);
    ob_end_flush();
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['asset_id']) || !isset($data['ticker']) || !isset($data['max_increments']) || !isset($data['base_size'])) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: asset_id, ticker, max_increments, and base_size are required',
    ]);
    ob_end_flush();
    exit;
}

$asset_id = (int) $data['asset_id'];
$ticker = trim($data['ticker']);
$max_increments = round((float) $data['max_increments'], 3);
$base_size = (float) $data['base_size'];
$enabled = isset($data['enabled']) ? ((int) $data['enabled'] ? 1 : 0) : 1;
$type = isset($data['type']) && $data['type'] !== '' ? trim($data['type']) : null;
$broker = isset($data['broker']) && $data['broker'] !== '' ? trim($data['broker']) : null;
$side = isset($data['side']) ? strtoupper(trim((string) $data['side'])) : 'ALL';
if (!in_array($side, ['LONG', 'SHORT', 'ALL'], true)) {
    $side = 'ALL';
}
$has_asset_image = array_key_exists('asset_image', $data);
$asset_image = $has_asset_image && $data['asset_image'] !== '' ? trim((string) $data['asset_image']) : null;

// Per-asset leverage (crypto futures). Absent from the payload = leave the stored
// value alone; present but blank/0 = clear the override back to "use bot default".
// A value here is a REQUEST — the bot steps it down to the symbol's real Binance
// bracket ceiling if it is too high, so an over-ambitious number degrades instead
// of silently trading at whatever leverage the account happened to hold.
$has_leverage = array_key_exists('leverage', $data);
$leverage = null;
if ($has_leverage && $data['leverage'] !== '' && $data['leverage'] !== null) {
    $leverage = (int) $data['leverage'];
    if ($leverage < 1 || $leverage > 125) {
        ob_clean();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'leverage must be between 1 and 125, or empty to use the bot default',
        ]);
        ob_end_flush();
        exit;
    }
}

// Validation
if ($asset_id < 1) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'asset_id must be a positive integer',
    ]);
    ob_end_flush();
    exit;
}

if (empty($ticker)) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Ticker cannot be empty',
    ]);
    ob_end_flush();
    exit;
}

// Crypto futures brokers size in coins (fractional); everything else in whole increments.
$minIncrement = in_array(strtolower(trim((string) $broker)), ['binance', 'mexc'], true) ? 0.001 : 1;
if ($max_increments < $minIncrement) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $minIncrement < 1
            ? 'max_increments must be at least 0.001'
            : 'max_increments must be at least 1',
    ]);
    ob_end_flush();
    exit;
}

if ($base_size <= 0) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'base_size must be greater than 0',
    ]);
    ob_end_flush();
    exit;
}

// Check asset exists
$checkSql = 'SELECT asset_id FROM assets WHERE asset_id = ?';
$checkStmt = mysqli_prepare($conn, $checkSql);
if ($checkStmt) {
    mysqli_stmt_bind_param($checkStmt, 'i', $asset_id);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);

    if (mysqli_num_rows($checkResult) === 0) {
        mysqli_stmt_close($checkStmt);
        mysqli_close($conn);
        ob_clean();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Asset not found',
        ]);
        ob_end_flush();
        exit;
    }
    mysqli_stmt_close($checkStmt);
}

// Capture the pre-update snapshot for the audit history (old → new diff).
$asset_audit_old = null;
if (file_exists(__DIR__ . '/../includes/asset_audit.php')) {
    require_once __DIR__ . '/../includes/asset_audit.php';
    $asset_audit_old = asset_audit_fetch_snapshot($conn, $asset_id);
}

// Update asset. asset_image and leverage are only written when the payload actually
// carries them, so a partial update (e.g. the dashboard's enabled toggle, which sends
// neither) cannot wipe a value the admin set on the full edit form. The SET clause is
// assembled rather than branched: with two optional columns a hardcoded statement per
// combination is four near-identical queries, and a third would make it eight.
$setParts = ['ticker = ?', 'type = ?', 'broker = ?', 'side = ?'];
$types = 'ssss';
$values = [$ticker, $type, $broker, $side];

if ($has_asset_image) {
    $setParts[] = 'asset_image = ?';
    $types .= 's';
    $values[] = $asset_image;
}

$setParts[] = 'max_increments = ?';
$setParts[] = 'base_size = ?';
$types .= 'dd';
$values[] = $max_increments;
$values[] = $base_size;

if ($has_leverage) {
    $setParts[] = 'leverage = ?';
    // Bound as 'i'; a PHP null binds through as SQL NULL, which is the
    // "no override, use the bot default" case.
    $types .= 'i';
    $values[] = $leverage;
}

$setParts[] = 'enabled = ?';
$setParts[] = 'updated_at = NOW()';
$types .= 'i';
$values[] = $enabled;

$types .= 'i';
$values[] = $asset_id;

$updateSql = 'UPDATE assets SET ' . implode(', ', $setParts) . ' WHERE asset_id = ?';
$stmt = mysqli_prepare($conn, $updateSql);

if (!$stmt) {
    mysqli_close($conn);
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare statement: ' . mysqli_error($conn),
    ]);
    ob_end_flush();
    exit;
}

mysqli_stmt_bind_param($stmt, $types, ...$values);

if (mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'assets-controller/assets_update', 'Asset updated successfully', 'update', true);
    }
    if (function_exists('asset_audit_log')) {
        $asset_audit_new = asset_audit_fetch_snapshot($conn, $asset_id);
        asset_audit_log($conn, $asset_id, $ticker, 'update', $asset_audit_old, $asset_audit_new);
    }
    mysqli_close($conn);
    
    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Asset updated successfully',
    ]);
    ob_end_flush();
} else {
    mysqli_stmt_close($stmt);
    if (file_exists(__DIR__ . '/../includes/api_log.php')) {
        require_once __DIR__ . '/../includes/api_log.php';
        api_log($conn, 'assets-controller/assets_update', 'Asset update failed', 'update', false);
    }
    mysqli_close($conn);
    
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update asset: ' . mysqli_error($conn),
    ]);
    ob_end_flush();
}

?>
