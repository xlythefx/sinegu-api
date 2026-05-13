<?php
/**
 * Root index - prevents directory listing and hides API structure.
 * Returns 403 Forbidden for direct access; forwards /apk route to apk.php.
 */
if (isset($_GET['route']) && $_GET['route'] === 'apk') {
    require_once __DIR__ . '/apk.php';
    exit;
}
http_response_code(403);
header('Content-Type: text/plain');
header('X-Content-Type-Options: nosniff');
echo 'Forbidden';
