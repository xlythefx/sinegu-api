<?php
/**
 * Get User Percentages
 * Retrieves realized_percentage and unrealized_percentage from user_credentials table
 */

// Turn off error display to prevent HTML output in JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start output buffering to catch any unexpected output
ob_start();

// CORS headers - must be set before any output
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ob_clean();
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit;
}

try {
    // Clear any output buffer before processing
    ob_clean();
    
    require_once __DIR__ . '/../conn.php';
    
    // Check database connection
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    // Get uni_id from query parameter
    if (!isset($_GET['uni_id']) || empty($_GET['uni_id'])) {
        throw new Exception('uni_id parameter is required');
    }
    
    $uni_id = trim($_GET['uni_id']);
    
    // Sanitize input
    $uni_id = mysqli_real_escape_string($conn, $uni_id);
    
    $query = "SELECT 
                realized_percentage,
                unrealized_percentage
              FROM user_credentials
              WHERE uni_id = '$uni_id'
              LIMIT 1";
    
    $result = mysqli_query($conn, $query);
    
    if (!$result) {
        throw new Exception('Failed to fetch user percentages: ' . mysqli_error($conn));
    }
    
    if (mysqli_num_rows($result) === 0) {
        ob_clean();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'User not found'
        ]);
        exit;
    }
    
    $user = mysqli_fetch_assoc($result);
    mysqli_free_result($result);
    
    // Convert decimal to float and provide defaults if null
    $realized_percentage = isset($user['realized_percentage']) && $user['realized_percentage'] !== null 
        ? (float)$user['realized_percentage'] 
        : 20.0;
    
    $unrealized_percentage = isset($user['unrealized_percentage']) && $user['unrealized_percentage'] !== null 
        ? (float)$user['unrealized_percentage'] 
        : 30.0;
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'percentages' => [
            'realized_percentage' => $realized_percentage,
            'unrealized_percentage' => $unrealized_percentage,
        ]
    ]);
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Error $e) {
    // Catch fatal errors
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred. Please try again later.'
    ]);
    error_log("Get user percentages error: " . $e->getMessage());
}

// End output buffering
ob_end_flush();

?>

