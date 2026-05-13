<?php
// Proxy incoming webhook calls to the Python service.

header('Content-Type: application/json');

$pythonWebhookUrl = getenv('SINEGU_WEBHOOK_URL') ?: 'http://127.0.0.1:5000/webhook';

$rawInput = file_get_contents('php://input');
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if ($rawInput === '' && stripos($contentType, 'application/json') === false) {
    // Fallback for form submissions; build JSON from POST fields.
    if (!isset($_POST['price']) || $_POST['price'] === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Price is required']);
        exit;
    }

    $price = (float)$_POST['price'];
    $payload = [
        'secret' => $_POST['secret'] ?? '',
        'action' => $_POST['action'] ?? '',
        'symbol' => $_POST['symbol'] ?? '',
        'price'  => $price,
        'close'  => $price,
    ];

    $rawInput = json_encode($payload);
    $contentType = 'application/json';
}

if ($rawInput === '' || $rawInput === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty request body']);
    exit;
}

$decoded = json_decode($rawInput, true);
if (json_last_error() === JSON_ERROR_NONE) {
    if (!array_key_exists('price', $decoded)) {
        if (array_key_exists('close', $decoded)) {
            $decoded['price'] = (float)$decoded['close'];
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Price field missing']);
            exit;
        }
    } else {
        $decoded['price'] = (float)$decoded['price'];
    }

    if (!array_key_exists('close', $decoded)) {
        $decoded['close'] = $decoded['price'];
    }

    $rawInput = json_encode($decoded);
}

$ch = curl_init($pythonWebhookUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $rawInput);

$responseBody = curl_exec($ch);
$curlError = curl_error($ch);
$statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 500;
curl_close($ch);

http_response_code($statusCode);

if ($curlError) {
    echo json_encode([
        'success' => false,
        'error' => $curlError,
    ]);
    exit;
}

if ($responseBody === false || $responseBody === '') {
    echo json_encode([
        'success' => false,
        'error' => 'No response from Python webhook',
    ]);
    exit;
}

// Attempt to detect if Python already returned JSON; if not, wrap it.
$decoded = json_decode($responseBody, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo json_encode($decoded);
    exit;
}

echo json_encode([
    'success' => false,
    'error' => 'Non-JSON response from Python webhook',
    'raw' => $responseBody,
]);
?>

