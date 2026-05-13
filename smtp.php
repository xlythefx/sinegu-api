<?php
/**
 * SMTP Configuration for Email Sending
 * 
 * This file contains SMTP settings for sending emails via Hostinger SMTP.
 * Configured for info@sinegualfamily.com
 */

// Load PHPMailer (install via Composer: composer require phpmailer/phpmailer)
// Try multiple paths for autoload
$phpmailer_loaded = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    $phpmailer_loaded = class_exists('PHPMailer\PHPMailer\PHPMailer');
} elseif (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $phpmailer_loaded = class_exists('PHPMailer\PHPMailer\PHPMailer');
} elseif (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    $phpmailer_loaded = class_exists('PHPMailer\PHPMailer\PHPMailer');
}

/**
 * SMTP Configuration - Hostinger SMTP
 * Email configuration for SineguAlerts
 */
require_once __DIR__ . '/env.php';

define('SMTP_HOST',       env('SMTP_HOST', 'smtp.hostinger.com'));
define('SMTP_PORT',       (int) env('SMTP_PORT', 587));
define('SMTP_USERNAME',   env('SMTP_USERNAME'));
define('SMTP_PASSWORD',   env('SMTP_PASSWORD'));
define('SMTP_FROM_EMAIL', env('SMTP_FROM_EMAIL', env('SMTP_USERNAME')));
define('SMTP_FROM_NAME',  env('SMTP_FROM_NAME', 'SineguAlerts'));
define('SMTP_ENCRYPTION', env('SMTP_ENCRYPTION', 'tls'));

/**
 * Send Email Function
 * 
 * @param string $to Recipient email address
 * @param string $toName Recipient name
 * @param string $subject Email subject
 * @param string $body HTML email body
 * @return bool True if email sent successfully, false otherwise
 */
function sendEmail($to, $toName, $subject, $body) {
    global $phpmailer_loaded;
    
    // Check if PHPMailer is available
    if ($phpmailer_loaded && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_ENCRYPTION;
            $mail->Port = SMTP_PORT;
            
            // Enable verbose debug output (disable in production)
            // $mail->SMTPDebug = 2;
            
            // Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($to, $toName);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body); // Plain text version
            
            $mail->send();
            return true;
            
        } catch (Exception $e) {
            // Log error (don't expose to user)
            error_log("Email sending failed: " . $mail->ErrorInfo);
            return false;
        }
    } else {
        // Fallback to simple mail() function if PHPMailer is not available
        return sendEmailSimple($to, $toName, $subject, $body);
    }
}

/**
 * Alternative: Simple mail() function (if PHPMailer is not available)
 * Note: This is less reliable and may not work on all servers
 */
function sendEmailSimple($to, $toName, $subject, $body) {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">" . "\r\n";
    
    return mail($to, $subject, $body, $headers);
}

/**
 * Setup Instructions:
 * 
 * 1. Install PHPMailer via Composer:
 *    composer require phpmailer/phpmailer
 * 
 * 2. For Gmail:
 *    - Enable 2-Step Verification
 *    - Generate App Password: https://myaccount.google.com/apppasswords
 *    - Use the App Password (16 characters) as SMTP_PASSWORD
 * 
 * 3. For other email providers:
 *    - Update SMTP_HOST, SMTP_PORT, SMTP_ENCRYPTION
 *    - Common settings:
 *      * Gmail: smtp.gmail.com, 587, tls
 *      * Outlook: smtp-mail.outlook.com, 587, tls
 *      * Yahoo: smtp.mail.yahoo.com, 587, tls
 * 
 * 4. Test email sending:
 *    - Check error logs if emails don't send
 *    - Verify SMTP credentials are correct
 *    - Check firewall/security settings
 */

