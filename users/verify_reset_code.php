<?php
/**
 * Verify Reset Code
 * 
 * This endpoint verifies the reset code entered by the user.
 * It checks if the code is valid and not expired.
 */

// Turn off error display to prevent HTML output in JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start output buffering to catch any unexpected output
ob_start();

// CORS headers - must be set before any output
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database connection - use conn.php from sinegu-api root
require_once __DIR__ . '/../conn.php';

// Check database connection
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Clear any output buffer before processing
    ob_clean();
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['email']) || empty($input['email'])) {
        throw new Exception('Email is required');
    }
    
    if (!isset($input['code']) || empty($input['code'])) {
        throw new Exception('Reset code is required');
    }
    
    $email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);
    $code = preg_replace('/[^0-9]/', '', $input['code']); // Only digits
    
    if (strlen($code) !== 6) {
        throw new Exception('Invalid code format');
    }
    
    // Check user and reset code
    $stmt = mysqli_prepare($conn, "
        SELECT uni_id, reset_code, reset_code_expires_at, reset_code_attempts 
        FROM user_credentials 
        WHERE email = ?
    ");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$user) {
        throw new Exception('Invalid email or code');
    }
    
    // Check if code exists
    if (empty($user['reset_code'])) {
        throw new Exception('No reset code found. Please request a new one.');
    }
    
    // Check if code has expired
    if (strtotime($user['reset_code_expires_at']) < time()) {
        // Clear expired code
        $clearStmt = mysqli_prepare($conn, "
            UPDATE user_credentials 
            SET reset_code = NULL, 
                reset_code_expires_at = NULL, 
                reset_code_attempts = 0
            WHERE email = ?
        ");
        mysqli_stmt_bind_param($clearStmt, "s", $email);
        mysqli_stmt_execute($clearStmt);
        mysqli_stmt_close($clearStmt);
        
        throw new Exception('Reset code has expired. Please request a new one.');
    }
    
    // Check attempts (max 5 attempts)
    if ($user['reset_code_attempts'] >= 5) {
        // Clear code after too many attempts
        $clearStmt = mysqli_prepare($conn, "
            UPDATE user_credentials 
            SET reset_code = NULL, 
                reset_code_expires_at = NULL, 
                reset_code_attempts = 0
            WHERE email = ?
        ");
        mysqli_stmt_bind_param($clearStmt, "s", $email);
        mysqli_stmt_execute($clearStmt);
        mysqli_stmt_close($clearStmt);
        
        throw new Exception('Too many failed attempts. Please request a new code.');
    }
    
    // Verify code
    if ($user['reset_code'] !== $code) {
        // Increment attempts
        $attemptStmt = mysqli_prepare($conn, "
            UPDATE user_credentials 
            SET reset_code_attempts = reset_code_attempts + 1
            WHERE email = ?
        ");
        mysqli_stmt_bind_param($attemptStmt, "s", $email);
        mysqli_stmt_execute($attemptStmt);
        mysqli_stmt_close($attemptStmt);
        
        $remainingAttempts = 5 - ($user['reset_code_attempts'] + 1);
        throw new Exception("Invalid code. {$remainingAttempts} attempts remaining.");
    }
    
    // Code is valid - return success
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Code verified successfully'
    ]);
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (Error $e) {
    // Catch fatal errors
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again later.'
    ]);
    error_log("Verify reset code error: " . $e->getMessage());
}

// End output buffering
ob_end_flush();

