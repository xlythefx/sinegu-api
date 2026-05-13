<?php
/**
 * Forgot Password - Send Reset Code
 * 
 * This endpoint sends a 6-digit reset code to the user's email address.
 * The code is stored in the database with an expiration time.
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

// SMTP configuration - include smtp.php from sinegu-api root
if (file_exists(__DIR__ . '/../smtp.php')) {
    require_once __DIR__ . '/../smtp.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'SMTP configuration not found']);
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
    
    $email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }
    
    // Check if user exists
    $stmt = mysqli_prepare($conn, "SELECT uni_id, name, email FROM user_credentials WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    // Always return success message (security: don't reveal if email exists)
    if (!$user) {
        // Still return success to prevent email enumeration
        echo json_encode([
            'success' => true,
            'message' => 'If the email exists, a reset code has been sent.'
        ]);
        exit;
    }
    
    // Generate 6-digit code
    $resetCode = str_pad((string)rand(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // Set expiration (15 minutes from now)
    $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    
    // Update user record with reset code
    $updateStmt = mysqli_prepare($conn, "
        UPDATE user_credentials 
        SET reset_code = ?, 
            reset_code_expires_at = ?, 
            reset_code_attempts = 0,
            updated_at = NOW()
        WHERE email = ?
    ");
    if (!$updateStmt) {
        throw new Exception('Failed to prepare update statement: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($updateStmt, "sss", $resetCode, $expiresAt, $email);
    
    if (!mysqli_stmt_execute($updateStmt)) {
        $error = mysqli_stmt_error($updateStmt);
        mysqli_stmt_close($updateStmt);
        throw new Exception('Failed to update reset code: ' . $error);
    }
    
    mysqli_stmt_close($updateStmt);
    
    // Send email with reset code
    $subject = "Password Reset Code - SineguAlerts";
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
            .code-box { background: white; border: 2px dashed #f59e0b; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px; }
            .code { font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #f59e0b; }
            .footer { text-align: center; margin-top: 20px; color: #6b7280; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>SineguAlerts</h1>
            </div>
            <div class='content'>
                <h2>Password Reset Request</h2>
                <p>Hello {$user['name']},</p>
                <p>You have requested to reset your password. Use the code below to proceed:</p>
                <div class='code-box'>
                    <div class='code'>{$resetCode}</div>
                </div>
                <p><strong>This code will expire in 15 minutes.</strong></p>
                <p>If you didn't request this reset, please ignore this email.</p>
                <div class='footer'>
                    <p>© " . date('Y') . " SineguAlerts. All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $mailSent = sendEmail($email, $user['name'], $subject, $message);
    
    if (!$mailSent) {
        // Log error but don't reveal to user
        error_log("Failed to send password reset email to: $email");
    }
    
    // Clear output buffer and send JSON
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'If the email exists, a reset code has been sent.'
    ]);
    
} catch (Exception $e) {
    // Clear output buffer and send error JSON
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
    error_log("Forgot password error: " . $e->getMessage());
}

// End output buffering
ob_end_flush();

