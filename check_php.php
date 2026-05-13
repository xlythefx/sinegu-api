<?php
/**
 * Quick check: PHP version and upload-related settings.
 * Open in browser: http://localhost/sinegu-api/check_php.php
 */
header('Content-Type: text/plain; charset=utf-8');

echo "PHP Version: " . PHP_VERSION . "\n";
echo "PHP SAPI: " . php_sapi_name() . "\n";
echo "Loaded php.ini: " . (php_ini_loaded_file() ?: '(none)') . "\n\n";

echo "Upload / POST limits:\n";
echo "  upload_max_filesize = " . ini_get('upload_max_filesize') . "\n";
echo "  post_max_size = " . ini_get('post_max_size') . "\n";
echo "  max_file_uploads = " . ini_get('max_file_uploads') . "\n";
echo "  memory_limit = " . ini_get('memory_limit') . "\n";
