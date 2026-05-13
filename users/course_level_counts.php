<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$levels = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
$counts = array_fill_keys($levels, 0);

$safe_in = "'first','second','third','fourth','fifth','sixth'";
$query = "SELECT course_level, COUNT(*) AS cnt FROM user_credentials WHERE course_level IN ($safe_in) GROUP BY course_level";
$result = mysqli_query($conn, $query);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $level = $row['course_level'] ?? '';
        if (isset($counts[$level])) {
            $counts[$level] = (int) $row['cnt'];
        }
    }
}

echo json_encode([
    'success' => true,
    'counts' => $counts,
]);
