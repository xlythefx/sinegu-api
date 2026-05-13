<?php
/**
 * Verification Script: Check Community Forum Tables
 * 
 * This script verifies that all community forum tables were created correctly
 */

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    die('Database connection failed: ' . mysqli_connect_error());
}

$tables = ['community_posts', 'community_comments', 'community_post_attachments'];

echo "=== Community Forum Tables Verification ===\n\n";

foreach ($tables as $table) {
    echo "Table: $table\n";
    echo str_repeat('-', 50) . "\n";
    
    $result = mysqli_query($conn, "DESCRIBE $table");
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            printf("%-25s %-30s %-8s %-10s\n", 
                $row['Field'], 
                $row['Type'], 
                $row['Null'], 
                $row['Key']
            );
        }
    } else {
        echo "ERROR: Table not found or cannot be described\n";
    }
    
    echo "\n";
}

mysqli_close($conn);
?>

