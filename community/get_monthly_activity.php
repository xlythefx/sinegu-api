<?php
/**
 * API Endpoint: Get Monthly Activity
 * 
 * Returns monthly activity status for a user
 * 
 * GET Parameters:
 * - uni_id: User unique identifier (required)
 * - month: Month in YYYY-MM format (optional, defaults to current month)
 */

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}

$uni_id = $_GET['uni_id'] ?? null;
$month = $_GET['month'] ?? date('Y-m');

if (!$uni_id) {
    echo json_encode([
        'success' => false,
        'error' => 'User ID (uni_id) is required'
    ]);
    exit;
}

// Validate month format
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid month format. Expected YYYY-MM'
    ]);
    exit;
}

$escapedUniId = mysqli_real_escape_string($conn, $uni_id);
$escapedMonth = mysqli_real_escape_string($conn, $month);

// Get monthly activity record
$sql = "SELECT * FROM user_monthly_activity 
        WHERE uni_id = '$escapedUniId' 
        AND month_year = '$escapedMonth'
        LIMIT 1";

$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode([
        'success' => false,
        'error' => 'Database query failed: ' . mysqli_error($conn)
    ]);
    exit;
}

if (mysqli_num_rows($result) === 0) {
    // No record exists yet - return default values
    echo json_encode([
        'success' => true,
        'month_year' => $month,
        'help_actions_count' => 0,
        'unique_people_helped' => 0,
        'is_active' => false
    ]);
    exit;
}

$row = mysqli_fetch_assoc($result);

echo json_encode([
    'success' => true,
    'month_year' => $row['month_year'],
    'help_actions_count' => (int)$row['help_actions_count'],
    'unique_people_helped' => (int)$row['unique_people_helped'],
    'is_active' => (bool)$row['is_active'],
    'created_at' => $row['created_at'],
    'updated_at' => $row['updated_at']
]);

mysqli_close($conn);
?>

