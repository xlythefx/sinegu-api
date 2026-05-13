<?php
/**
 * Migration Script: Create crypto_wallets table
 *
 * Creates the crypto_wallets table (one wallet per user, linked via uni_id to user_credentials).
 *
 * Usage: php run_crypto_wallets_migration.php
 * Or via browser: http://localhost/sinegu-api/migrations/run_crypto_wallets_migration.php
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

$sqlFile = __DIR__ . '/015_create_crypto_wallets_table.sql';

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

$errors = [];

if (mysqli_multi_query($conn, $sql)) {
    do {
        if ($result = mysqli_store_result($conn)) {
            mysqli_free_result($result);
        }
        if (mysqli_error($conn)) {
            $errors[] = mysqli_error($conn);
        }
    } while (mysqli_next_result($conn));
}

if (!empty($errors)) {
    echo json_encode([
        'success' => false,
        'error' => 'Migration errors: ' . implode('; ', $errors),
        'errors' => $errors
    ]);
    exit;
}

$table = 'crypto_wallets';
$checkQuery = "SHOW TABLES LIKE '$table'";
$result = mysqli_query($conn, $checkQuery);

if ($result && mysqli_num_rows($result) > 0) {
    $describeResult = mysqli_query($conn, "DESCRIBE `$table`");
    $columns = [];
    if ($describeResult) {
        while ($row = mysqli_fetch_assoc($describeResult)) {
            $columns[] = $row['Field'];
        }
        mysqli_free_result($describeResult);
    }
    echo json_encode([
        'success' => true,
        'message' => 'Crypto wallets table created successfully',
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
