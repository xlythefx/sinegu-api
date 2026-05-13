<?php
// Suppress error display to prevent HTML output before JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start output buffering to catch any unexpected output
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    ob_end_flush();
    exit;
}

// Clear any output before processing
ob_clean();

try {
    require_once __DIR__ . '/conn.php';

    if (!$conn) {
        throw new Exception('Database connection failed');
    }                

    $data = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data: ' . json_last_error_msg());
    }

    if (!isset($data['uni_id'])) {
        throw new Exception('uni_id is required');
    }

    $uni_id = trim($data['uni_id']);

// Build update query
$updatableFields = [
    'name' => 's',
    'email' => 's',
    'password' => 's',
    'status' => 's',
    'realized_percentage' => 'd',
    'unrealized_percentage' => 'd',
];

$setParts = [];
$types = '';
$values = [];

foreach ($updatableFields as $field => $type) {
    if (array_key_exists($field, $data)) {
        if ($field === 'realized_percentage' || $field === 'unrealized_percentage') {
            // Handle nullable decimal values
            if ($data[$field] === null || $data[$field] === '' || $data[$field] === 'null') {
                // Use NULL in SQL directly (no placeholder needed)
                $setParts[] = "$field = NULL";
            } else {
                // Use placeholder for numeric value
                $setParts[] = "$field = ?";
                $types .= 'd'; // 'd' for double/float
                $values[] = (float)$data[$field];
            }
        } else {
            $setParts[] = "$field = ?";
            $types .= $type;
            if ($field === 'password') {
                // Hash password if provided
                $values[] = password_hash((string)$data[$field], PASSWORD_DEFAULT);
            } else {
                $values[] = (string)$data[$field];
            }
        }
    }
}

    if (empty($setParts)) {
        throw new Exception('No fields to update');
    }

    // Validate status if provided
    if (isset($data['status']) && !in_array($data['status'], ['pending', 'active', 'suspended'])) {
        throw new Exception('Invalid status');
    }

    // Validate email if provided
    if (isset($data['email'])) {
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format');
        }
        
        // Check if email is already taken by another user
        $check_email = mysqli_prepare($conn, "SELECT uni_id FROM user_credentials WHERE email = ? AND uni_id != ?");
        if (!$check_email) {
            throw new Exception('Failed to prepare email check statement: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($check_email, "ss", $data['email'], $uni_id);
        mysqli_stmt_execute($check_email);
        $result = mysqli_stmt_get_result($check_email);
        
        if (mysqli_num_rows($result) > 0) {
            mysqli_stmt_close($check_email);
            throw new Exception('Email already registered');
        }
        mysqli_stmt_close($check_email);
    }

    $sql = 'UPDATE user_credentials SET ' . implode(', ', $setParts) . ' WHERE uni_id = ?';
    $types .= 's'; // Always add 's' for uni_id at the end
    $values[] = $uni_id;

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, $types, ...$values);

    if (!mysqli_stmt_execute($stmt)) {
        $errorMsg = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception('Failed to update user: ' . $errorMsg);
    }

    $affectedRows = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'User updated successfully',
        'affected_rows' => $affectedRows
    ]);
    ob_end_flush();
    exit;

} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    ob_end_flush();
    exit;
} catch (Error $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
    ob_end_flush();
    exit;
}

?>

