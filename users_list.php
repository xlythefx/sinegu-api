<?php
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
        'message' => 'Method not allowed',
    ]);
    exit;
}

try {
    // Clear any output buffer before processing
    ob_clean();
    
    require_once __DIR__ . '/conn.php';
    require_once __DIR__ . '/includes/user_types.php';


    // Check database connection
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $sql = 'SELECT uni_id, name, email, password, status, ' . user_type_select($conn) . ', realized_percentage, unrealized_percentage, created_at, updated_at FROM user_credentials ORDER BY created_at DESC';
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        throw new Exception('Failed to fetch users: ' . mysqli_error($conn));
    }

    $users = [];

    while ($row = mysqli_fetch_assoc($result)) {
        // Convert decimal strings to float for proper JSON encoding
        if (isset($row['realized_percentage']) && $row['realized_percentage'] !== null) {
            $row['realized_percentage'] = (float) $row['realized_percentage'];
        } else {
            $row['realized_percentage'] = null;
        }
        
        if (isset($row['unrealized_percentage']) && $row['unrealized_percentage'] !== null) {
            $row['unrealized_percentage'] = (float) $row['unrealized_percentage'];
        } else {
            $row['unrealized_percentage'] = null;
        }
        
        $users[] = $row;
    }

    mysqli_free_result($result);
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'users' => $users,
    ]);
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
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
    error_log("Users list error: " . $e->getMessage());
}

// End output buffering
ob_end_flush();

?>

