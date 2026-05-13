<?php
/**
 * Migration Runner Script
 * Execute this file to run pending migrations
 */

// Database configuration
$db_host = 'localhost';
$db_name = 'sinegu_db'; // Change this to your database name
$db_user = 'root'; // Change this to your database user
$db_pass = ''; // Change this to your database password

try {
    // Connect to database
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to database successfully.\n\n";
    
    // Create migrations tracking table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `migrations` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `migration_name` VARCHAR(255) NOT NULL,
            `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `migration_name` (`migration_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Get all migration files
    $migration_dir = __DIR__;
    $migration_files = glob($migration_dir . '/*.sql');
    sort($migration_files);
    
    foreach ($migration_files as $migration_file) {
        $migration_name = basename($migration_file);
        
        // Check if migration has already been run
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `migrations` WHERE `migration_name` = ?");
        $stmt->execute([$migration_name]);
        
        if ($stmt->fetchColumn() > 0) {
            echo "Skipping $migration_name (already executed)\n";
            continue;
        }
        
        // Read and execute migration
        echo "Running migration: $migration_name\n";
        $sql = file_get_contents($migration_file);
        
        // Execute migration
        $pdo->exec($sql);
        
        // Record migration as executed
        $stmt = $pdo->prepare("INSERT INTO `migrations` (`migration_name`) VALUES (?)");
        $stmt->execute([$migration_name]);
        
        echo "✓ Migration $migration_name completed successfully.\n\n";
    }
    
    echo "All migrations completed!\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

