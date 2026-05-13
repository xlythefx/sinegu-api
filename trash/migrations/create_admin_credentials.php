<?php
require_once __DIR__ . '/../conn.php';

$createTableSql = <<<SQL
CREATE TABLE IF NOT EXISTS admin_credentials (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(100) NOT NULL
)
SQL;

if (!mysqli_query($conn, $createTableSql)) {
    die('Failed to create table: ' . mysqli_error($conn));
}

$email = 'admin@example.com';
$passwordPlain = 'changeme123';
$role = 'super_admin';

$passwordHash = password_hash($passwordPlain, PASSWORD_DEFAULT);

$stmt = mysqli_prepare($conn, 'INSERT INTO admin_credentials (email, password, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE password = VALUES(password), role = VALUES(role)');

if (!$stmt) {
    die('Prepare failed: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, 'sss', $email, $passwordHash, $role);

if (!mysqli_stmt_execute($stmt)) {
    die('Insert failed: ' . mysqli_stmt_error($stmt));
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

echo "Migration completed successfully.\n";
