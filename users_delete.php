<?php
/**
 * Delete User
 * 
 * This endpoint deletes a user from the user_credentials table.
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
    ob_clean();
    http_response_code(204);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Clear any output buffer before processing
    ob_clean();
    
    // Database connection - use conn.php from sinegu-api root
    require_once __DIR__ . '/conn.php';
    
    // Check database connection
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['uni_id']) || empty($input['uni_id'])) {
        throw new Exception('uni_id is required');
    }
    
    $uni_id = trim($input['uni_id']);
    
    // Start transaction to ensure both deletions succeed or fail together
    mysqli_begin_transaction($conn);
    
    try {
        // First, delete all related accounts from accounts table
        $deleteAccountsStmt = mysqli_prepare($conn, 'DELETE FROM accounts WHERE uni_id = ?');
        
        if (!$deleteAccountsStmt) {
            throw new Exception('Failed to prepare delete accounts statement: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($deleteAccountsStmt, 's', $uni_id);
        
        if (!mysqli_stmt_execute($deleteAccountsStmt)) {
            $error = mysqli_stmt_error($deleteAccountsStmt);
            mysqli_stmt_close($deleteAccountsStmt);
            throw new Exception('Failed to delete related accounts: ' . $error);
        }
        
        $accountsDeleted = mysqli_stmt_affected_rows($deleteAccountsStmt);
        mysqli_stmt_close($deleteAccountsStmt);
        
        // Then, delete the user from user_credentials table
        $deleteUserStmt = mysqli_prepare($conn, 'DELETE FROM user_credentials WHERE uni_id = ?');
        
        if (!$deleteUserStmt) {
            throw new Exception('Failed to prepare delete user statement: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($deleteUserStmt, 's', $uni_id);
        
        if (!mysqli_stmt_execute($deleteUserStmt)) {
            $error = mysqli_stmt_error($deleteUserStmt);
            mysqli_stmt_close($deleteUserStmt);
            throw new Exception('Failed to delete user: ' . $error);
        }
        
        $userDeleted = mysqli_stmt_affected_rows($deleteUserStmt);
        mysqli_stmt_close($deleteUserStmt);
        
        if ($userDeleted === 0) {
            throw new Exception('User not found or already deleted');
        }
        
        // Commit transaction if all deletions succeeded
        mysqli_commit($conn);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        mysqli_rollback($conn);
        throw $e;
    }
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'User deleted successfully',
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
    error_log("Delete user error: " . $e->getMessage());
}

// End output buffering
ob_end_flush();

