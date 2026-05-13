<?php
/**
 * Verification Script: Check Community Details Table
 */

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo "Database connection failed: " . mysqli_connect_error() . PHP_EOL;
    exit(1);
}

// Check if table exists
$checkTable = "SHOW TABLES LIKE 'community_details'";
$result = mysqli_query($conn, $checkTable);
$tableExists = $result && mysqli_num_rows($result) > 0;

if ($tableExists) {
    echo "✓ Table 'community_details' exists" . PHP_EOL;
    
    // Show table structure
    $describe = "DESCRIBE community_details";
    $result = mysqli_query($conn, $describe);
    
    if ($result) {
        echo PHP_EOL . "Table structure:" . PHP_EOL;
        echo str_repeat("-", 60) . PHP_EOL;
        printf("%-20s %-25s %-10s %s" . PHP_EOL, "Field", "Type", "Null", "Key");
        echo str_repeat("-", 60) . PHP_EOL;
        
        while ($row = mysqli_fetch_assoc($result)) {
            printf("%-20s %-25s %-10s %s" . PHP_EOL, 
                $row['Field'], 
                $row['Type'], 
                $row['Null'], 
                $row['Key']
            );
        }
    }
    
    // Check foreign key
    $checkFK = "SELECT 
        CONSTRAINT_NAME, 
        TABLE_NAME, 
        COLUMN_NAME, 
        REFERENCED_TABLE_NAME, 
        REFERENCED_COLUMN_NAME 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'community_details' 
    AND REFERENCED_TABLE_NAME IS NOT NULL";
    
    $result = mysqli_query($conn, $checkFK);
    if ($result && mysqli_num_rows($result) > 0) {
        echo PHP_EOL . "Foreign Keys:" . PHP_EOL;
        while ($row = mysqli_fetch_assoc($result)) {
            echo "  - {$row['CONSTRAINT_NAME']}: {$row['COLUMN_NAME']} -> {$row['REFERENCED_TABLE_NAME']}.{$row['REFERENCED_COLUMN_NAME']}" . PHP_EOL;
        }
    }
    
} else {
    echo "✗ Table 'community_details' does NOT exist" . PHP_EOL;
    exit(1);
}

mysqli_close($conn);
echo PHP_EOL . "Verification complete!" . PHP_EOL;
?>

