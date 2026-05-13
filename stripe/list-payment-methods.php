<?php
/**
 * List Payment Methods API
 * Returns all active payment methods for a user
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ob_start();
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/config.php';

if (!$conn) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    ob_end_flush();
    exit();
}

try {
    // Get uni_id from query parameter
    $uniId = isset($_GET['uni_id']) ? trim($_GET['uni_id']) : null;
    
    if (empty($uniId)) {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'uni_id parameter is required']);
        ob_end_flush();
        exit();
    }
    
    // Validate uni_id exists in user_credentials
    $uniIdEscaped = mysqli_real_escape_string($conn, $uniId);
    $validateQuery = "SELECT uni_id FROM user_credentials WHERE uni_id = '$uniIdEscaped' LIMIT 1";
    $validateResult = mysqli_query($conn, $validateQuery);
    
    if (!$validateResult || mysqli_num_rows($validateResult) === 0) {
        ob_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        ob_end_flush();
        exit();
    }
    mysqli_free_result($validateResult);
    
    // Query payment methods
    $query = "SELECT 
                id,
                uni_id,
                stripe_customer_id,
                stripe_payment_method_id,
                payment_method_type,
                card_brand,
                card_last4,
                card_exp_month,
                card_exp_year,
                is_default,
                is_active,
                created_at,
                updated_at
              FROM stripe_payment_methods
              WHERE uni_id = '$uniIdEscaped'
                AND is_active = 1
              ORDER BY is_default DESC, created_at DESC";
    
    $result = mysqli_query($conn, $query);
    
    if (!$result) {
        throw new Exception("Database query failed: " . mysqli_error($conn));
    }
    
    $paymentMethods = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $paymentMethods[] = [
            'id' => (int)$row['id'],
            'uni_id' => $row['uni_id'],
            'stripe_customer_id' => $row['stripe_customer_id'],
            'stripe_payment_method_id' => $row['stripe_payment_method_id'],
            'payment_method_type' => $row['payment_method_type'],
            'card_brand' => $row['card_brand'],
            'card_last4' => $row['card_last4'],
            'card_exp_month' => $row['card_exp_month'] ? (int)$row['card_exp_month'] : null,
            'card_exp_year' => $row['card_exp_year'] ? (int)$row['card_exp_year'] : null,
            'is_default' => (bool)(int)$row['is_default'],
            'is_active' => (bool)(int)$row['is_active'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            // Helper field: formatted card display
            'card_display' => $row['card_brand'] && $row['card_last4'] 
                ? ucfirst($row['card_brand']) . ' •••• ' . $row['card_last4']
                : 'Card •••• ' . ($row['card_last4'] ?? '****'),
            // Helper field: formatted expiry
            'card_expiry' => $row['card_exp_month'] && $row['card_exp_year']
                ? str_pad($row['card_exp_month'], 2, '0', STR_PAD_LEFT) . '/' . substr($row['card_exp_year'], -2)
                : null
        ];
    }
    
    mysqli_free_result($result);
    
    // When user has exactly one active PM, always show it as default (display only; do not update DB)
    if (count($paymentMethods) === 1) {
        $paymentMethods[0]['is_default'] = true;
    }
    
    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'payment_methods' => $paymentMethods,
        'count' => count($paymentMethods)
    ]);
    ob_end_flush();
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    ob_end_flush();
} finally {
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
}
?>
