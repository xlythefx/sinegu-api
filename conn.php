<?php
require_once __DIR__ . '/env.php';

$db_host = env('DB_HOST', 'localhost');
$db_user = env('DB_USER', 'root');
$db_pass = env('DB_PASS', '');
$db_name = env('DB_NAME', 'sinegu_db');

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$conn) {
    // Don't use die() as it outputs HTML and breaks JSON responses
    // Set $conn to false so calling code can handle the error
    $conn = false;
    // Store error for debugging if needed
    $conn_error = mysqli_connect_error();
} else {
    mysqli_set_charset($conn, 'utf8mb4');
}
