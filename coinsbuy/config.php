<?php
/**
 * Coinsbuy Configuration
 * 
 * Switch between development and production by changing $isDevelopment:
 * - true = Development/Test mode (uses sandbox)
 * - false = Production mode (uses live API)
 */ 

// ============================================
// ENVIRONMENT SWITCH
// ============================================
require_once __DIR__ . '/../env.php';

$isDevelopment = (strtolower((string) env('APP_ENV', 'production')) === 'development');

if ($isDevelopment) {
    // DEVELOPMENT CONFIGURATION (Sandbox)
    define('COINSBUY_API_KEY',      env('COINSBUY_API_KEY_SANDBOX'));
    define('COINSBUY_API_SECRET',   env('COINSBUY_API_SECRET_SANDBOX'));
    define('COINSBUY_BASE_URL',     env('COINSBUY_BASE_URL_SANDBOX', 'https://v3.api-sandbox.coinsbuy.com'));
    define('COINSBUY_WEBHOOK_SECRET', env('COINSBUY_WEBHOOK_SECRET_SANDBOX'));
    define('FRONTEND_BASE_URL',     env('FRONTEND_BASE_URL_DEV', 'http://localhost:8080'));
    define('API_BASE_URL',          env('API_BASE_URL_COINSBUY_DEV', env('API_BASE_URL_DEV', 'http://localhost/sinegu-api')));
    define('COINSBUY_ENVIRONMENT', 'sandbox');
    define('COINSBUY_USD_WALLET_ID_OVERRIDE', env('COINSBUY_USD_WALLET_ID_SANDBOX', '873'));
} else {
    // PRODUCTION CONFIGURATION (Live Mode)
    define('COINSBUY_API_KEY',      env('COINSBUY_API_KEY'));
    define('COINSBUY_API_SECRET',   env('COINSBUY_API_SECRET'));
    define('COINSBUY_BASE_URL',     env('COINSBUY_BASE_URL', 'https://v3.api.coinsbuy.com'));
    define('COINSBUY_WEBHOOK_SECRET', env('COINSBUY_WEBHOOK_SECRET'));
    define('FRONTEND_BASE_URL',     env('FRONTEND_BASE_URL', 'https://sinegualfamily.com'));
    define('API_BASE_URL',          env('API_BASE_URL', 'https://api.sinegualfamily.com'));
    define('COINSBUY_ENVIRONMENT', 'production');
    define('COINSBUY_USD_WALLET_ID_OVERRIDE', env('COINSBUY_USD_WALLET_ID', '287'));
}

// ============================================
// COINSBUY SETTINGS
// ============================================

define('DEFAULT_CURRENCY', 'USD');
define('SUPPORTED_CRYPTOCURRENCIES', ['BTC', 'ETH', 'USDT', 'USDC']); // Add more as needed
// Default crypto for payment page: BTC in sandbox, USDT in production
define('DEFAULT_CRYPTOCURRENCY', $isDevelopment ? 'BTC' : 'USDT');
define('SUPPORTED_FIAT_CURRENCIES', ['USD', 'EUR']);
// COINSBUY_USD_WALLET_ID_OVERRIDE is set per-environment above (873 sandbox, 287 production)

// URL paths
define('SUCCESS_URL_PATH', '/user/invoices?payment=success&transaction_id={TRANSACTION_ID}');
define('CANCEL_URL_PATH', '/user/invoices?payment=cancelled');

// ============================================
// HELPER FUNCTIONS
// ============================================

function getSuccessUrl($transactionId = null) {
    $url = FRONTEND_BASE_URL . SUCCESS_URL_PATH;
    if ($transactionId) {
        $url = str_replace('{TRANSACTION_ID}', $transactionId, $url);
    }
    return $url;
}

function getCancelUrl() {
    return FRONTEND_BASE_URL . CANCEL_URL_PATH;
}

function isDevelopment() {
    return COINSBUY_ENVIRONMENT === 'sandbox';
}

function getDefaultCryptocurrency() {
    return defined('DEFAULT_CRYPTOCURRENCY') ? DEFAULT_CRYPTOCURRENCY : 'USDT';
}

function getCoinsbuyApiKey() {
    return COINSBUY_API_KEY;
}

function getCoinsbuyApiSecret() {
    return COINSBUY_API_SECRET;
}

function getCoinsbuyBaseUrl() {
    return COINSBUY_BASE_URL;
}

function getCoinsbuyWebhookSecret() {
    return COINSBUY_WEBHOOK_SECRET;
}

function getWebhookUrl() {
    return API_BASE_URL . '/coinsbuy/webhook.php';
}

/**
 * Get OAuth 2.0 access token from Coinsbuy
 * According to Coinsbuy API v3 documentation:
 * - Send POST request to [base]/token with client_id (API key) and client_secret (API secret)
 * - Response includes access_token, expires_in, and token_type
 * 
 * @return string|false Access token or false on failure
 */
function getCoinsbuyAccessToken() {
    $baseUrl = getCoinsbuyBaseUrl();
    $apiKey = getCoinsbuyApiKey();
    $apiSecret = getCoinsbuyApiSecret();
    
    if (empty($apiKey) || empty($apiSecret)) {
        error_log('Coinsbuy API credentials not configured');
        return false;
    }
    
    // Check if we have a cached token (implement simple file-based cache)
    $cacheFile = __DIR__ . '/.token_cache';
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && isset($cached['token']) && isset($cached['expires_at'])) {
            // Check if token is still valid (with 60 second buffer)
            if (time() < ($cached['expires_at'] - 60)) {
                return $cached['token'];
            }
        }
    }
    
    // Fetch new token
    // Coinsbuy API v3 uses JSON API format for authentication
    $ch = curl_init($baseUrl . '/token/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/vnd.api+json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'data' => [
                'type' => 'auth-token',
                'attributes' => [
                    'client_id' => $apiKey,
                    'client_secret' => $apiSecret,
                ],
            ],
        ]),
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log('Coinsbuy token request error: ' . $error);
        return false;
    }
    
    if ($httpCode !== 200) {
        error_log('Coinsbuy token request failed with HTTP ' . $httpCode . ': ' . $response);
        return false;
    }
    
    $data = json_decode($response, true);
    
    // Coinsbuy API v3 returns token in JSON API format:
    // { "data": { "attributes": { "access": "...", "expires_in": 3599 } } }
    $accessToken = null;
    if (isset($data['data']['attributes']['access'])) {
        $accessToken = $data['data']['attributes']['access'];
        $expiresIn = isset($data['data']['attributes']['expires_in']) 
            ? (int)$data['data']['attributes']['expires_in'] 
            : 3600;
    } elseif (isset($data['access_token'])) {
        // Fallback for non-JSON API format (shouldn't happen in v3)
        $accessToken = $data['access_token'];
        $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] : 3600;
    }
    
    if (!$accessToken) {
        error_log('Coinsbuy token response missing access token: ' . $response);
        return false;
    }
    
    // Cache the token
    $cacheData = [
        'token' => $accessToken,
        'expires_at' => time() + $expiresIn,
    ];
    @file_put_contents($cacheFile, json_encode($cacheData));
    
    return $accessToken;
}

/**
 * Make an authenticated API request to Coinsbuy
 * Follows JSON API specification: https://jsonapi.org/
 * 
 * @param string $endpoint API endpoint (e.g., 'deposits' or 'payouts')
 * @param string $method HTTP method (GET, POST, PATCH, etc.)
 * @param array|null $data Request body data in JSON API format
 * @return array|false Response data or false on failure
 */
function coinsbuyApiRequest($endpoint, $method = 'GET', $data = null) {
    $accessToken = getCoinsbuyAccessToken();
    if (!$accessToken) {
        return false;
    }
    
    $baseUrl = getCoinsbuyBaseUrl();
    // Ensure endpoint doesn't start with / and base URL doesn't end with /
    $endpoint = ltrim($endpoint, '/');
    $baseUrl = rtrim($baseUrl, '/');
    $url = $baseUrl . '/' . $endpoint;
    
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/vnd.api+json',
        'Accept: application/vnd.api+json',
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    
    if ($method === 'POST' || $method === 'PATCH' || $method === 'PUT' || $method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data !== null) {
            // Ensure data follows JSON API format
            $jsonData = json_encode($data, JSON_UNESCAPED_SLASHES);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('Coinsbuy API: JSON encoding error: ' . json_last_error_msg());
                curl_close($ch);
                return false;
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log('Coinsbuy API request error: ' . $error);
        return false;
    }
    
    $responseData = json_decode($response, true);
    
    if ($httpCode >= 400) {
        error_log('Coinsbuy API request failed with HTTP ' . $httpCode);
        error_log('Request URL: ' . $url);
        error_log('Request method: ' . $method);
        if ($data !== null) {
            error_log('Request data: ' . json_encode($data));
        }
        error_log('Response: ' . $response);
        // Clear cached token on 401 so next request fetches fresh token
        if ($httpCode === 401) {
            $cacheFile = __DIR__ . '/.token_cache';
            if (file_exists($cacheFile)) {
                @unlink($cacheFile);
                error_log('Coinsbuy: Cleared token cache (401). Regenerate API keys in Coinsbuy if this persists.');
            }
        }
        return [
            'error' => true,
            'http_code' => $httpCode,
            'response' => $responseData,
        ];
    }
    
    return $responseData;
}

/**
 * Get or find a wallet for a specific currency
 * Handles pagination (Coinsbuy returns 10 per page by default) and matches by
 * both numeric currency ID and ISO code (e.g. "USD").
 *
 * @param string $currencyCode Currency code (e.g., 'BTC', 'ETH', 'USDT', 'USD')
 * @return string|false Wallet ID or false on failure
 */
function getWalletForCurrency($currencyCode) {
    $currencyCodeUpper = strtoupper($currencyCode);
    // Get currency ID (optional - API may use numeric ID or ISO code)
    $currencyId = null;
    $currencyResponse = coinsbuyApiRequest('currency/?filter[alpha]=' . $currencyCode, 'GET');
    if ($currencyResponse && !isset($currencyResponse['error'])) {
        if (isset($currencyResponse['data']['id'])) {
            $currencyId = $currencyResponse['data']['id'];
        } elseif (is_array($currencyResponse['data'] ?? null) && count($currencyResponse['data']) > 0) {
            $currencyId = $currencyResponse['data'][0]['id'] ?? null;
        }
    }
    // Coinsbuy docs: wallet currency relationship id can match ISO code (e.g. "USD") or numeric ID

    // Fetch all wallets with pagination (Coinsbuy default page size is 10, request up to 50)
    $page = 1;
    $pageSize = 50;
    $allWallets = [];
    do {
        $walletUrl = 'wallet/?page[number]=' . $page . '&page[size]=' . $pageSize;
        $walletsResponse = coinsbuyApiRequest($walletUrl, 'GET');
        if (!$walletsResponse) {
            error_log('Coinsbuy: Failed to get wallets - token or network error. Regenerate API keys in Coinsbuy if expired.');
            return false;
        }
        if (isset($walletsResponse['error'])) {
            $httpCode = $walletsResponse['http_code'] ?? 0;
            $detail = $walletsResponse['response']['errors'][0]['detail'] ?? json_encode($walletsResponse['response'] ?? []);
            error_log('Coinsbuy: Wallets API error HTTP ' . $httpCode . ': ' . $detail);
            if ($httpCode === 401) {
                error_log('Coinsbuy: API credentials invalid. Regenerate API Key and Secret in Coinsbuy Settings.');
            }
            return false;
        }
        $wallets = [];
        if (isset($walletsResponse['data']['id'])) {
            $wallets = [$walletsResponse['data']];
        } elseif (is_array($walletsResponse['data'] ?? null)) {
            $wallets = $walletsResponse['data'];
        }
        $allWallets = array_merge($allWallets, $wallets);
        $totalPages = (int)($walletsResponse['meta']['total_pages'] ?? 1);
        $page++;
    } while ($page <= $totalPages && count($wallets) === $pageSize);

    foreach ($allWallets as $wallet) {
        $walletCurrencyId = $wallet['relationships']['currency']['data']['id'] ?? null;
        if ($walletCurrencyId === null) {
            continue;
        }
        $walletCurrencyUpper = strtoupper((string) $walletCurrencyId);
        $matches = ($walletCurrencyUpper === $currencyCodeUpper || $walletCurrencyId == $currencyId);
        if (!$matches) {
            continue;
        }
        $walletStatus = $wallet['attributes']['status'] ?? null;
        // Status 3 = Active per Coinsbuy Wallets docs (number or string)
        $isActive = ($walletStatus == 3 || $walletStatus === '3'
            || strtolower((string) $walletStatus) === 'active');
        if ($isActive) {
            return $wallet['id'];
        }
    }

    error_log('Coinsbuy: No active wallet for ' . $currencyCode . '. Found ' . count($allWallets) . ' wallets total. Ensure a Merchant wallet for ' . $currencyCode . ' exists and is Active.');
    return false;
}

/**
 * Get currency ID from currency code
 * 
 * @param string $currencyCode Currency code (e.g., 'BTC', 'ETH', 'USDT')
 * @return string|false Currency ID or false on failure
 */
function getCurrencyId($currencyCode) {
    $currencyResponse = coinsbuyApiRequest('currency/?filter[alpha]=' . $currencyCode, 'GET');
    if (!$currencyResponse || isset($currencyResponse['error'])) {
        error_log('Failed to get currency for ' . $currencyCode);
        return false;
    }
    
    if (isset($currencyResponse['data'])) {
        if (isset($currencyResponse['data']['id'])) {
            return $currencyResponse['data']['id'];
        } elseif (is_array($currencyResponse['data']) && count($currencyResponse['data']) > 0) {
            return $currencyResponse['data'][0]['id'] ?? false;
        }
    }
    
    return false;
}

