<?php
/**
 * Delete Payment Method API
 * Hard deletes a payment method for a user (removes from database)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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
    // Get request data
    $input = @file_get_contents('php://input');
    if ($input === false || empty($input)) {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No request data received']);
        ob_end_flush();
        exit();
    }
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON data: ' . json_last_error_msg()]);
        ob_end_flush();
        exit();
    }
    
    if (!is_array($data)) {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Request data must be a JSON object']);
        ob_end_flush();
        exit();
    }
    
    // Validate required fields
    $paymentMethodId = isset($data['payment_method_id']) ? (int)$data['payment_method_id'] : null;
    $uniId = isset($data['uni_id']) ? trim($data['uni_id']) : null;
    
    if (!$paymentMethodId || $paymentMethodId <= 0) {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid payment_method_id is required']);
        ob_end_flush();
        exit();
    }
    
    if (empty($uniId)) {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'uni_id is required']);
        ob_end_flush();
        exit();
    }
    
    // Validate uni_id exists
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
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    // Verify payment method exists and belongs to user
    $paymentMethodIdEscaped = (int)$paymentMethodId;
    $checkQuery = "SELECT 
                    id,
                    uni_id,
                    stripe_customer_id,
                    stripe_payment_method_id,
                    is_default,
                    is_active
                  FROM stripe_payment_methods
                  WHERE id = $paymentMethodIdEscaped
                    AND uni_id = '$uniIdEscaped'
                    AND is_active = 1
                  LIMIT 1";
    
    $checkResult = mysqli_query($conn, $checkQuery);
    
    if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
        mysqli_rollback($conn);
        ob_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Payment method not found or does not belong to user']);
        ob_end_flush();
        exit();
    }
    
    $paymentMethod = mysqli_fetch_assoc($checkResult);
    mysqli_free_result($checkResult);
    
    // Detach payment method from Stripe customer
    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        \Stripe\Stripe::setApiKey(getStripeSecretKey());
        
        $stripePaymentMethodId = $paymentMethod['stripe_payment_method_id'];
        
        // Detach payment method from customer
        \Stripe\PaymentMethod::retrieve($stripePaymentMethodId)->detach();
    } catch (\Stripe\Exception\ApiErrorException $e) {
        // Log error but continue with hard delete
        error_log("Stripe API error while detaching payment method: " . $e->getMessage());
    } catch (Exception $e) {
        // Log error but continue with hard delete
        error_log("Error while detaching Stripe payment method: " . $e->getMessage());
    }
    
    // Hard delete (DELETE from database)
    $deleteQuery = "DELETE FROM stripe_payment_methods 
                   WHERE id = $paymentMethodIdEscaped
                     AND uni_id = '$uniIdEscaped'";
    
    if (!mysqli_query($conn, $deleteQuery)) {
        mysqli_rollback($conn);
        throw new Exception("Failed to delete payment method: " . mysqli_error($conn));
    }
    
    // Commit transaction
    mysqli_commit($conn);
    
    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Payment method deleted successfully',
        'payment_method_id' => $paymentMethodId
    ]);
    ob_end_flush();
    
} catch (Exception $e) {
    if (isset($conn)) {
        mysqli_rollback($conn);
    }
    
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
