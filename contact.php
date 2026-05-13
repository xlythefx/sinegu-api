<?php
/**
 * Contact form handler – sends message via SMTP (Hostinger) to configured address.
 * Uses credentials from smtp.php. POST: name, email, subject, message.
 * CORS-friendly for frontend.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    http_response_code(400);
    exit;
}

$name    = isset($input['name']) ? trim((string) $input['name']) : '';
$email   = isset($input['email']) ? trim((string) $input['email']) : '';
$subject = isset($input['subject']) ? trim((string) $input['subject']) : '';
$message = isset($input['message']) ? trim((string) $input['message']) : '';

if ($name === '' || $email === '' || $subject === '' || $message === '') {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    http_response_code(400);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    http_response_code(400);
    exit;
}

$to = 'info@sinegualerts.com';

$emailSubject = '[SineguAlerts Contact] ' . $subject;
$body = "Name: $name\n";
$body .= "Email: $email\n";
$body .= "Subject: $subject\n\n";
$body .= "Message:\n$message\n";

$sent = false;
$errMsg = 'Failed to send. Please try email or Telegram.';

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    echo json_encode(['success' => false, 'message' => $errMsg]);
    http_response_code(500);
    exit;
}

require_once $autoloadPath;

// Load SMTP config (Hostinger credentials)
if (file_exists(__DIR__ . '/smtp.php')) {
    require_once __DIR__ . '/smtp.php';
} else {
    require_once __DIR__ . '/env.php';
    define('SMTP_HOST',       env('SMTP_HOST', 'smtp.hostinger.com'));
    define('SMTP_PORT',       (int) env('SMTP_PORT', 587));
    define('SMTP_USERNAME',   env('SMTP_USERNAME'));
    define('SMTP_PASSWORD',   env('SMTP_PASSWORD'));
    define('SMTP_FROM_EMAIL', env('SMTP_FROM_EMAIL', env('SMTP_USERNAME')));
    define('SMTP_FROM_NAME',  env('SMTP_FROM_NAME', 'SineguAlerts'));
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($to);
    $mail->addReplyTo($email, $name);

    $mail->isHTML(false);
    $mail->Subject = $emailSubject;
    $mail->Body    = $body;

    $mail->send();
    $sent = true;
} catch (PHPMailerException $e) {
    error_log('Contact form PHPMailer: ' . $e->getMessage());
    $errMsg = 'Failed to send. Please try email or Telegram.';
} catch (Exception $e) {
    error_log('Contact form: ' . $e->getMessage());
}

if ($sent) {
    echo json_encode(['success' => true, 'message' => 'Message sent']);
    http_response_code(200);
} else {
    echo json_encode(['success' => false, 'message' => $errMsg]);
    http_response_code(500);
}
