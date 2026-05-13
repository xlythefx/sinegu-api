<?php
/**
 * Migration Script: Create Help Actions and Monthly Activity Tables
 * 
 * This script creates the database tables for the help actions system:
 * - help_actions: Tracks when users mark posts/comments as helpful
 * - user_monthly_activity: Tracks monthly active user status
 * - Updates user_credentials.last_activity column
 * 
 * Usage: Run this script once to set up the help actions system
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

$migrations = [
    '002_create_help_actions_table.sql',
    '003_create_user_monthly_activity_table.sql',
    '004_update_last_activity_tracking.sql'
];

$results = [];
$errors = [];

foreach ($migrations as $migrationFile) {
    $sqlFile = __DIR__ . '/' . $migrationFile;
    
    if (!file_exists($sqlFile)) {
        $errors[] = "Migration file not found: $migrationFile";
        continue;
    }
    
    $sql = file_get_contents($sqlFile);
    
    if ($sql === false) {
        $errors[] = "Failed to read migration file: $migrationFile";
        continue;
    }
    
    // Execute the migration
    if (mysqli_multi_query($conn, $sql)) {
        do {
            // Store each result
            if ($result = mysqli_store_result($conn)) {
                mysqli_free_result($result);
            }
            
            // Check for errors
            if (mysqli_error($conn)) {
                $errorMsg = mysqli_error($conn);
                // Ignore "already exists" messages as they're not errors
                if (stripos($errorMsg, 'already exists') === false && 
                    stripos($errorMsg, 'Duplicate key name') === false) {
                    $errors[] = "$migrationFile: $errorMsg";
                }
            }
            
            // Move to next result
        } while (mysqli_next_result($conn));
        
        $results[] = $migrationFile;
    } else {
        $errors[] = "$migrationFile: " . mysqli_error($conn);
    }
}

// Verify tables were created
$tables = ['help_actions', 'user_monthly_activity'];
$createdTables = [];

foreach ($tables as $table) {
    $checkQuery = "SHOW TABLES LIKE '$table'";
    $result = mysqli_query($conn, $checkQuery);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $createdTables[] = $table;
    }
}

// Check if last_activity column exists
$lastActivityExists = false;
$checkColumn = "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'user_credentials' 
                AND COLUMN_NAME = 'last_activity'";
$result = mysqli_query($conn, $checkColumn);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $lastActivityExists = $row['count'] > 0;
}

if (count($createdTables) === count($tables) && $lastActivityExists) {
    echo json_encode([
        'success' => true,
        'message' => 'Help actions tables and last_activity column created successfully',
        'migrations_run' => $results,
        'tables_created' => $createdTables,
        'last_activity_column' => $lastActivityExists ? 'exists' : 'missing',
        'errors' => empty($errors) ? null : $errors
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Some migrations failed or tables were not created',
        'expected_tables' => $tables,
        'created_tables' => $createdTables,
        'last_activity_column' => $lastActivityExists ? 'exists' : 'missing',
        'migrations_run' => $results,
        'errors' => $errors
    ]);
}

mysqli_close($conn);
?>

