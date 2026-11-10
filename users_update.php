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
    require_once __DIR__ . '/includes/user_types.php';

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

    // Account type (migration 095). Outside the generic loop: it is checked against
    // the one list in includes/user_types.php, and a change that crosses feeds
    // (user_type_feed differs: main / test / quantlab) moves the user's Binance and
    // Bybit accounts to another signal feed — refused while they hold a position on
    // either, because the other feed's exit would close this feed's entry.
    $typeUnchanged = false;
    $feedMoved = false;
    if (array_key_exists('type', $data)) {
        $newType = is_string($data['type']) ? trim($data['type']) : '';
        if (!is_valid_user_type($newType)) {
            throw new Exception('Invalid type. Must be one of: ' . implode(', ', USER_TYPES));
        }
        if (!user_credentials_has_type($conn)) {
            throw new Exception('Account types are not available yet — run migration 095 first');
        }
        $currentType = user_type_of($conn, $uni_id);
        if ($currentType === null) {
            throw new Exception('User not found');
        }
        if ($newType === $currentType) {
            $typeUnchanged = true;
        } else {
            $feedMoved = user_type_feed($newType) !== user_type_feed($currentType);
            $openOn = [];
            if ($feedMoved && user_has_open_binance_positions($conn, $uni_id)) {
                $openOn[] = 'Binance';
            }
            if ($feedMoved && user_has_open_bybit_positions($conn, $uni_id)) {
                $openOn[] = 'Bybit';
            }
            if ($openOn) {
                ob_clean();
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'error_code' => 'OPEN_POSITIONS',
                    'message' => 'This user holds an open ' . implode(' and ', $openOn) . ' position — close it first. '
                        . 'This type change moves their accounts to another signal feed, '
                        . 'whose exit would close this position.',
                ]);
                ob_end_flush();
                exit;
            }
            $setParts[] = '`type` = ?';
            $types .= 's';
            $values[] = $newType;
        }
    }

    if (empty($setParts) && $typeUnchanged) {
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'User updated successfully', 'affected_rows' => 0]);
        ob_end_flush();
        exit;
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

    // The engines cache accounts (with their feed) for 90 s; drop the caches so the
    // new feed applies to the next signal. Best effort — a miss only means 90 s.
    // `engine_refreshed` stays Binance's answer (the dashboard reads it); Bybit's is
    // reported beside it and is false on a box that does not run Bybit.
    $engineRefreshed = $feedMoved ? refresh_binance_engine_accounts() : null;
    $bybitRefreshed = $feedMoved ? refresh_bybit_engine_accounts() : null;

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'User updated successfully',
        'affected_rows' => $affectedRows,
        'engine_refreshed' => $engineRefreshed,
        'bybit_engine_refreshed' => $bybitRefreshed,
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

