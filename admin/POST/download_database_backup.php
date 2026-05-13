<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json; charset=utf-8');

function sendError($message, $detail = null) {
    echo json_encode(['success' => false, 'message' => $message, 'detail' => $detail]);
    exit;
}

chdir(__DIR__);

$backupBase = __DIR__ . '/../../uploads/database-backup';
if (!is_dir($backupBase)) {
    if (!@mkdir($backupBase, 0755, true)) sendError('Could not create backup directory', $backupBase);
}
$backupDir = realpath($backupBase);
if (!$backupDir || !is_dir($backupDir)) sendError('Backup directory not found', $backupBase);

require_once __DIR__ . '/../../conn.php';
if (!$conn) sendError('Database connection failed', isset($conn_error) ? $conn_error : 'Check conn.php');

$filename = 'sinegu_db_' . date('Y-m-d_His') . '.sql';
$filepath = $backupDir . DIRECTORY_SEPARATOR . $filename;

$fp = @fopen($filepath, 'w');
if (!$fp) sendError('Could not create file', $filepath);

fwrite($fp, "-- Sinegu DB backup " . date('Y-m-d H:i:s') . "\n");
fwrite($fp, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

$tables = [];
$res = mysqli_query($conn, "SHOW TABLES");
if (!$res) { fclose($fp); @unlink($filepath); sendError('Could not list tables', mysqli_error($conn)); }
while ($row = mysqli_fetch_array($res)) $tables[] = $row[0];
mysqli_free_result($res);

foreach ($tables as $table) {
    $create = mysqli_query($conn, "SHOW CREATE TABLE `" . mysqli_real_escape_string($conn, $table) . "`");
    if (!$create) continue;
    $row = mysqli_fetch_row($create);
    mysqli_free_result($create);
    if ($row) {
        fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($fp, $row[1] . ";\n\n");
    }

    $cols = [];
    $r = mysqli_query($conn, "SELECT * FROM `" . mysqli_real_escape_string($conn, $table) . "` LIMIT 0");
    if (!$r) continue;
    while ($f = mysqli_fetch_field($r)) $cols[] = "`" . $f->name . "`";
    mysqli_free_result($r);
    $colList = implode(',', $cols);

    $sel = mysqli_query($conn, "SELECT * FROM `" . mysqli_real_escape_string($conn, $table) . "`");
    if (!$sel) continue;
    $count = 0;
    while ($row = mysqli_fetch_row($sel)) {
        $vals = [];
        foreach ($row as $v) {
            if ($v === null) $vals[] = 'NULL';
            else $vals[] = "'" . mysqli_real_escape_string($conn, $v) . "'";
        }
        fwrite($fp, "INSERT INTO `$table` ($colList) VALUES (" . implode(',', $vals) . ");\n");
        $count++;
    }
    mysqli_free_result($sel);
    if ($count > 0) fwrite($fp, "\n");
}

fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
fclose($fp);

if (filesize($filepath) === 0) { @unlink($filepath); sendError('Export failed', 'Backup file is empty'); }

if (file_exists(__DIR__ . '/../../includes/api_log.php')) {
    require_once __DIR__ . '/../../includes/api_log.php';
    api_log($conn, 'admin/download_database_backup', 'Database backup created and download initiated', 'fetch', true);
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
readfile($filepath);
exit;
