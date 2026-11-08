<?php
/**
 * Proxy webhook requests to Flask to avoid CORS issues
 * This allows the React frontend to send webhook requests through PHP backend
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed',
    ]);
    exit;
}

// Get the webhook payload from request body
$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (!$payload) {
    // Try to get from POST data if JSON body is empty
    $payload = [
        'secret' => $_POST['secret'] ?? '',
        'action' => $_POST['action'] ?? '',
        'symbol' => $_POST['symbol'] ?? '',
        'close' => $_POST['close'] ?? null,
    ];
}

// Extract proxy-only fields from payload if present, then remove them
$flaskUrl = $payload['flask_url'] ?? $_POST['flask_url'] ?? $_GET['flask_url'] ?? 'http://localhost:5000';
if (isset($payload['flask_url'])) {
    unset($payload['flask_url']); // Remove from payload before forwarding
}

$flaskPath = $payload['flask_path'] ?? $_POST['flask_path'] ?? $_GET['flask_path'] ?? '/webhook';
if (isset($payload['flask_path'])) {
    unset($payload['flask_path']); // Remove from payload before forwarding
}

// Every receiver nginx actually mounts. IG was missing here long after the
// /ig_webhook location and the sinegu-ig-* units went live, so every manual IG
// trade 400'd with "Invalid flask_path" and never reached Flask — the dashboard
// reported "All orders failed. Check Flask logs." while the IG log stayed empty,
// because the request died in this file. Adding a receiver means adding it here.
$allowedPaths = ['/webhook', '/binance_webhook', '/binance_test_webhook', '/ig_webhook', '/mexc_webhook', '/bybit_webhook', '/bybit_test_webhook'];
if (!in_array($flaskPath, $allowedPaths, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid flask_path',
    ]);
    exit;
}

// Clean up the Flask URL
// Remove trailing slashes
$flaskUrl = rtrim($flaskUrl, '/');
// Remove query parameters (e.g., ?secret=...)
$flaskUrl = preg_replace('/\?.*$/', '', $flaskUrl);
// Remove supported webhook path if already present
// Keep in step with $allowedPaths — a path missing here survives into $flaskUrl
// and gets doubled up (…/ig_webhook/ig_webhook) when the path is appended below.
$flaskUrl = preg_replace('/\/(?:webhook|binance_webhook|binance_test_webhook|ig_webhook|mexc_webhook|bybit_webhook|bybit_test_webhook)$/', '', $flaskUrl);

// The Binance test feed (tester accounts only) has its own secret. It is filled
// in here rather than shipped in the dashboard bundle; whatever the client sent
// is ignored. Must match binance-flask's BINANCE_TEST_WEBHOOK_SECRET.
if ($flaskPath === '/binance_test_webhook') {
    require_once __DIR__ . '/../env.php';
    $payload['secret'] = (string) env('BINANCE_TEST_WEBHOOK_SECRET', 'binance_test_bot');
}

// Validate required fields
if (empty($payload['secret']) || empty($payload['action']) || empty($payload['symbol'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: secret, action, or symbol',
    ]);
    exit;
}

// Build Flask webhook URL
$webhookUrl = $flaskUrl . $flaskPath;

// Check if URL is HTTPS (for SSL certificate handling)
$isHttps = (strpos($webhookUrl, 'https://') === 0);

/**
 * Make cURL request with SSL handling
 * Tries proper SSL verification first, falls back to relaxed SSL if certificate issues occur
 */
function makeCurlRequest($url, $payload, $verifySsl = true) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    // Handle SSL certificate verification for HTTPS URLs
    if (strpos($url, 'https://') === 0) {
        if ($verifySsl) {
            // Production: Enable SSL verification with proper certificate bundle
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            
            // Try to use system CA bundle if available
            $caBundlePaths = [
                __DIR__ . '/../vendor/stripe/stripe-php/lib/data/ca-certificates.crt', // Stripe's CA bundle
                '/etc/ssl/certs/ca-certificates.crt', // Linux
                '/etc/pki/tls/certs/ca-bundle.crt', // CentOS/RHEL
                'C:/wamp64/bin/php/php*/extras/ssl/cacert.pem', // WAMP
            ];
            
            foreach ($caBundlePaths as $caPath) {
                // Handle wildcard paths
                if (strpos($caPath, '*') !== false) {
                    $globPaths = glob($caPath);
                    if (!empty($globPaths) && file_exists($globPaths[0])) {
                        curl_setopt($ch, CURLOPT_CAINFO, $globPaths[0]);
                        break;
                    }
                } elseif (file_exists($caPath)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $caPath);
                    break;
                }
            }
        } else {
            // Fallback: Relaxed SSL for ngrok/self-signed certificates
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
    }
    
    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'body' => $responseBody,
        'error' => $curlError,
        'httpCode' => $httpCode,
    ];
}

// Try with SSL verification first (production mode)
$result = makeCurlRequest($webhookUrl, $payload, true);

// If SSL certificate error, retry with relaxed SSL (for ngrok/development)
if ($result['error'] && (
    strpos($result['error'], 'SSL certificate') !== false ||
    strpos($result['error'], 'certificate') !== false ||
    strpos($result['error'], 'unable to get local issuer certificate') !== false
)) {
    $result = makeCurlRequest($webhookUrl, $payload, false);
}

// Handle curl errors
if ($result['error']) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to connect to Flask: ' . $result['error'],
    ]);
    exit;
}

$responseBody = $result['body'];
$httpCode = $result['httpCode'];

// Forward Flask's response
http_response_code($httpCode);

// Try to parse as JSON, if not, return as-is
$decoded = json_decode($responseBody, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo json_encode($decoded);
} else {
    echo $responseBody;
}
?>
