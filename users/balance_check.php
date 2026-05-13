<?php
/**
 * Lightweight balance change check endpoint (CACHED)
 * Returns the latest balance update timestamp for a user's accounts
 * Frontend polls this endpoint every 10 seconds to detect changes
 * 
 * Caching: Results cached for 2 seconds to reduce database load
 * 
 * GET: ?uni_id=xxx
 * Returns: {"success": true, "last_update": "2026-01-24 17:30:45", "has_changes": true}
 */

// Turn off error display to prevent HTML output
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start output buffering
ob_start();

// Safe buffer clear (ob_clean on empty buffer can throw in PHP 8+)
$safe_ob_clean = function () {
    if (ob_get_length() !== false) {
        @ob_clean();
    }
};

// CORS headers - MUST be set before any output
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $safe_ob_clean();
    http_response_code(200);
    exit;
}

require_once '../conn.php';

// Check connection BEFORE any mysqli_* calls (avoid fatal when $conn is false)
if (!$conn) {
    $safe_ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

$uni_id = isset($_GET['uni_id']) ? mysqli_real_escape_string($conn, trim($_GET['uni_id'])) : null;

if (!$uni_id) {
    $safe_ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required parameter: uni_id'
    ]);
    exit;
}

// In-memory cache (for single-server setup)
// Cache TTL: 2 seconds (balances update every 30s, so 2s cache is safe)
static $cache = [];
static $cache_time = [];
$cache_ttl = 2; // seconds

$cache_key = "balance_check_{$uni_id}";

// Check cache
if (isset($cache[$cache_key]) && isset($cache_time[$cache_key])) {
    $cache_age = time() - $cache_time[$cache_key];
    if ($cache_age < $cache_ttl) {
        // Return cached result
        $safe_ob_clean();
        echo json_encode($cache[$cache_key]);
        mysqli_close($conn);
        exit;
    }
}

// Get the latest balance update timestamp for this user's accounts
// Use DATE_FORMAT to ensure consistent timestamp format
$sql = "SELECT DATE_FORMAT(MAX(updated_at), '%Y-%m-%d %H:%i:%s') as last_update 
        FROM accounts 
        WHERE uni_id = '{$uni_id}' 
        AND updated_at IS NOT NULL";

$result = mysqli_query($conn, $sql);

if (!$result) {
    $safe_ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => mysqli_error($conn),
        'hint' => 'Check that table accounts has column updated_at'
    ]);
    exit;
}

$row = mysqli_fetch_assoc($result);
$last_update = $row['last_update'] ?? null;

$response = [
    'success' => true,
    'last_update' => $last_update,
    'has_changes' => $last_update !== null
];

// Cache the result
$cache[$cache_key] = $response;
$cache_time[$cache_key] = time();

// Clear output buffer before sending JSON
$safe_ob_clean();

echo json_encode($response);

mysqli_close($conn);
exit;
?>
