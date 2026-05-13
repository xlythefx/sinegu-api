<?php
$defaultWebhookUrl = 'http://localhost/sinegu-api/webhook_proxy.php';
$pythonTarget = getenv('SINEGU_WEBHOOK_URL') ?: 'http://127.0.0.1:5000/webhook';
$defaultPayload = [
    'secret' => 'mio_webhook_token',
    'action' => 'add',
    'symbol' => 'GOLD',
    'price'  => 4281.22
];

$webhookUrl = $defaultWebhookUrl;
$payload = $defaultPayload;
$payload['close'] = $payload['price'];
$httpCode = null;
$responseArray = null;

$response = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $webhookUrl = !empty($_POST['webhook_url']) ? trim($_POST['webhook_url']) : $defaultWebhookUrl;
    $action = $_POST['action'] ?? $defaultPayload['action'];
    $symbol = $_POST['symbol'] ?? $defaultPayload['symbol'];
    $priceInput  = $_POST['price'] ?? (string)$defaultPayload['price'];
    $priceValue = (float)$priceInput;

    $payload = [
        'secret' => $defaultPayload['secret'],
        'action' => strtolower(trim($action)),
        'symbol' => strtoupper(trim($symbol)),
        'price'  => $priceValue,
        'close'  => $priceValue
    ];

    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
    }
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!isset($error) && $httpCode >= 400) {
        $error = "HTTP $httpCode returned by webhook";
    }

    if ($response !== false && $response !== null) {
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $responseArray = $decoded;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Webhook Tester - GOLD</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        label { display: block; margin-top: 12px; }
        input[type="text"], select { padding: 6px; width: 260px; }
        .btn { margin-top: 16px; padding: 10px 20px; background: #0d6efd; color: #fff; border: none; cursor: pointer; }
        .btn:hover { background: #0b5ed7; }
        pre { background: #f4f4f4; padding: 16px; border-radius: 4px; max-width: 640px; }
        .error { color: #d9534f; }
    </style>
</head>
<body>
    <h1>TradingView Webhook Tester</h1>
    <p>Send a GOLD alert to the Python webhook via the PHP proxy.</p>
    <p><small>Python target: <?= htmlspecialchars($pythonTarget, ENT_QUOTES, 'UTF-8') ?></small></p>
    <form method="post">
        <label>
            Webhook URL
            <input type="text" name="webhook_url" value="<?= htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8') ?>" required>
        </label>
        <label>
            Action
            <select name="action">
                <option value="add" <?= (isset($_POST['action']) && $_POST['action'] === 'add') ? 'selected' : '' ?>>ADD (default)</option>
                <option value="exit" <?= (isset($_POST['action']) && $_POST['action'] === 'exit') ? 'selected' : '' ?>>EXIT</option>
            </select>
        </label>
        <label>
            Symbol / Ticker
            <input type="text" name="symbol" value="GOLD" required>
        </label>
        <label>
            Price
            <input type="text" name="price" value="<?= htmlspecialchars($_POST['price'] ?? $defaultPayload['price'], ENT_QUOTES, 'UTF-8') ?>" required>
        </label>
        <button type="submit" class="btn">Execute</button>
    </form>

    <?php if ($error): ?>
        <p class="error">Request issue: <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if ($httpCode !== null): ?>
        <p>HTTP status: <?= (int)$httpCode ?></p>
    <?php endif; ?>

    <?php if (!empty($payload)): ?>
        <h2>Payload</h2>
        <pre><?= htmlspecialchars(json_encode($payload, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') ?></pre>
    <?php endif; ?>

    <?php if ($responseArray !== null): ?>
        <h2>Response Body</h2>
        <pre><?= htmlspecialchars(json_encode($responseArray, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') ?></pre>
    <?php elseif ($response !== null): ?>
        <h2>Response Body</h2>
        <pre><?= htmlspecialchars($response, ENT_QUOTES, 'UTF-8') ?></pre>
    <?php endif; ?>
</body>
</html>
