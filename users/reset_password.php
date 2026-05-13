<?php
/**
 * Reset Password
 * 
 * This endpoint resets the user's password after code verification.
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
    
    if (!isset($input['new_password']) || empty($input['new_password'])) {
        throw new Exception('New password is required');
    }
    
    $email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);
    $code = preg_replace('/[^0-9]/', '', $input['code']);
    $newPassword = $input['new_password'];
    
    // Validate password length
    if (strlen($newPassword) < 8) {
        throw new Exception('Password must be at least 8 characters long');
    }
    
    // Verify code again (security check)
    $stmt = mysqli_prepare($conn, "
        SELECT uni_id, reset_code, reset_code_expires_at 
        FROM user_credentials 
        WHERE email = ? AND reset_code = ?
    ");
    mysqli_stmt_bind_param($stmt, "ss", $email, $code);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$user) {
        throw new Exception('Invalid email or code');
    }
    
    // Check if code has expired
    if (strtotime($user['reset_code_expires_at']) < time()) {
        throw new Exception('Reset code has expired. Please request a new one.');
    }
    
    // Hash new password
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // Update password and clear reset code
    $updateStmt = mysqli_prepare($conn, "
        UPDATE user_credentials 
        SET password = ?, 
            reset_code = NULL, 
            reset_code_expires_at = NULL, 
            reset_code_attempts = 0,
            updated_at = NOW()
        WHERE email = ? AND reset_code = ?
    ");
    if (!$updateStmt) {
        throw new Exception('Failed to prepare update statement: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($updateStmt, "sss", $hashedPassword, $email, $code);
    
    if (!mysqli_stmt_execute($updateStmt)) {
        $error = mysqli_stmt_error($updateStmt);
        mysqli_stmt_close($updateStmt);
        throw new Exception('Failed to reset password: ' . $error);
    }
    
    if (mysqli_stmt_affected_rows($updateStmt) === 0) {
        mysqli_stmt_close($updateStmt);
        throw new Exception('Failed to reset password. Code may have expired or already been used.');
    }
    mysqli_stmt_close($updateStmt);
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Password has been reset successfully'
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
    error_log("Reset password error: " . $e->getMessage());
}

// End output buffering
ob_end_flush();

