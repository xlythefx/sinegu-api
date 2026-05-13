<?php
/**
 * Migration Script: Create Community Forum Tables
 * 
 * This script creates the database tables for the community forum feature:
 * - community_posts: Forum posts created by users
 * - community_comments: Comments and replies on posts (one level deep)
 * - community_post_attachments: File attachments (images, documents) for posts
 * 
 * Usage: Run this script once to set up the community tables
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
$sqlFile = __DIR__ . '/001_create_community_tables.sql';

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

// Split SQL into individual statements (split by semicolon, but preserve in CREATE statements)
// Simple approach: execute the entire SQL file
// Note: mysqli_multi_query can handle multiple statements

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

// Verify tables were created
$tables = ['community_posts', 'community_comments', 'community_post_attachments'];
$createdTables = [];

foreach ($tables as $table) {
    $checkQuery = "SHOW TABLES LIKE '$table'";
    $result = mysqli_query($conn, $checkQuery);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $createdTables[] = $table;
    }
}

if (count($createdTables) === count($tables)) {
    echo json_encode([
        'success' => true,
        'message' => 'Community forum tables created successfully',
        'tables_created' => $createdTables
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Some tables were not created',
        'expected' => $tables,
        'created' => $createdTables
    ]);
}

mysqli_close($conn);
?>

