<?php
/**
 * Get VPS Metrics API
 * Returns real-time metrics for all VPS servers
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/conn.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit();
}

try {
    // Get all VPS metrics
    $query = "SELECT 
                vps_id,
                vps_name,
                cpu_percent,
                ram_percent,
                disk_percent,
                latency_ms,
                uptime,
                online,
                updated_at
              FROM vps_metrics
              ORDER BY vps_id ASC";
    
    $result = mysqli_query($conn, $query);
    
    if (!$result) {
        throw new Exception("Query failed: " . mysqli_error($conn));
    }
    
    $vps_metrics = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $vps_metrics[] = [
            'vps_id' => (int)$row['vps_id'],
            'vps_name' => $row['vps_name'],
            'cpu' => (float)$row['cpu_percent'],
            'ram' => (float)$row['ram_percent'],
            'disk' => (float)$row['disk_percent'],
            'latency' => (float)$row['latency_ms'],
            'uptime' => $row['uptime'],
            'online' => (bool)$row['online'],
            'last_update' => $row['updated_at']
        ];
    }
    
    // Get system resources (prioritize Main Server/VPS 0 if online, otherwise average)
    $mainServerQuery = "SELECT cpu_percent, ram_percent, latency_ms 
                        FROM vps_metrics 
                        WHERE vps_id = 0 AND online = 1 
                        LIMIT 1";
    $mainServerResult = mysqli_query($conn, $mainServerQuery);
    $mainServerData = mysqli_fetch_assoc($mainServerResult);
    
    if ($mainServerData) {
        // Use Main Server (Local PC) metrics
        $systemMetrics = [
            'cpu' => round((float)$mainServerData['cpu_percent'], 1),
            'ram' => round((float)$mainServerData['ram_percent'], 1),
            'latency' => round((float)$mainServerData['latency_ms'], 0)
        ];
    } else {
        // Fallback: average of all online VPS
        $avgQuery = "SELECT 
                      AVG(cpu_percent) as avg_cpu,
                      AVG(ram_percent) as avg_ram,
                      AVG(latency_ms) as avg_latency
                    FROM vps_metrics
                    WHERE online = 1";
        $avgResult = mysqli_query($conn, $avgQuery);
        if (!$avgResult) {
            throw new Exception("System query failed: " . mysqli_error($conn));
        }
        $avgData = mysqli_fetch_assoc($avgResult);
        $systemMetrics = [
            'cpu' => round((float)($avgData['avg_cpu'] ?? 0), 1),
            'ram' => round((float)($avgData['avg_ram'] ?? 0), 1),
            'latency' => round((float)($avgData['avg_latency'] ?? 0), 0)
        ];
    }
    
    $response = [
        'success' => true,
        'system' => $systemMetrics,
        'vps' => $vps_metrics
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

