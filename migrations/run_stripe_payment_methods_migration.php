<?php
/**
 * Migration Script: Create Stripe Payment Methods Table
 * 
 * This script creates the database table for storing Stripe payment methods:
 * - stripe_payment_methods: Stores payment methods linked to users for auto-charging
 * 
 * Usage: Run this script once to set up the stripe_payment_methods table
 * 
 * Database: sinegu_db
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed: ' . mysqli_connect_error()
    ]);
    exit;
}

// Read the SQL migration file
$sqlFile = __DIR__ . '/010_create_stripe_payment_methods_table.sql';

if (!file_exists($sqlFile)) {
    echo json_encode([
        'success' => false,
        'error' => 'Migration file not found: ' . $sqlFile
    ]);
    exit;
}

$sql = file_get_contents($sqlFile);

if ($sql === false) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to read migration file'
    ]);
    exit;
}

$results = [];
$errors = [];

// Execute the migration
if (mysqli_multi_query($conn, $sql)) {
    do {
        // Store each result
        if ($result = mysqli_store_result($conn)) {
            mysqli_free_result($result);
        }
        
        // Check for errors
        if (mysqli_error($conn)) {
            $errors[] = mysqli_error($conn);
        }
        
        // Move to next result
    } while (mysqli_next_result($conn));
}

// Check if there were any errors
if (!empty($errors)) {
    echo json_encode([
        'success' => false,
        'error' => 'Migration errors: ' . implode('; ', $errors),
        'errors' => $errors
    ]);
    exit;
}

// Verify table was created
$table = 'stripe_payment_methods';
$checkQuery = "SHOW TABLES LIKE '$table'";
$result = mysqli_query($conn, $checkQuery);

if ($result && mysqli_num_rows($result) > 0) {
    // Check table structure
    $describeQuery = "DESCRIBE `$table`";
    $describeResult = mysqli_query($conn, $describeQuery);
    $columns = [];
    
    if ($describeResult) {
        while ($row = mysqli_fetch_assoc($describeResult)) {
            $columns[] = $row['Field'];
        }
        mysqli_free_result($describeResult);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Stripe payment methods table created successfully',
        'table' => $table,
        'columns' => $columns
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Table was not created',
        'expected' => $table
    ]);
}

mysqli_close($conn);
?>
