<?php
/**
 * Send Invoice Email using PHPMailer
 * 
 * Sends invoice email to user using email from user_credentials table
 * Uses Hostinger SMTP (info@sinegualfamily.com)
 * 
 * @param mysqli $conn Database connection
 * @param string $uniId User's unique ID from accounts table
 * @param array $invoiceData Invoice data including amount, month_year, etc.
 * @return array ['success' => bool, 'message' => string]
 */

// Email configuration - loaded from .env (see /env.php)
require_once __DIR__ . '/../env.php';
if (!defined('SMTP_HOST'))       define('SMTP_HOST',       env('SMTP_HOST', 'smtp.hostinger.com'));
if (!defined('SMTP_USERNAME'))   define('SMTP_USERNAME',   env('SMTP_USERNAME'));
if (!defined('SMTP_PASSWORD'))   define('SMTP_PASSWORD',   env('SMTP_PASSWORD'));
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', env('SMTP_FROM_EMAIL', env('SMTP_USERNAME')));
if (!defined('SMTP_FROM_NAME'))  define('SMTP_FROM_NAME',  env('SMTP_FROM_NAME', 'SineguAlerts'));
if (!defined('SMTP_PORT'))       define('SMTP_PORT',       (int) env('SMTP_PORT', 587));

/**
 * Generate HTML email body for invoice
 */
function generateInvoiceEmailBody($userName, $invoiceData, $currencySymbol, $formattedAmount, $performanceGain, $hwmBeforeFormatted, $equityEndFormatted, $depositAmountFormatted, $logoCid = null) {
    $performanceClass = $performanceGain >= 0 ? 'performance-positive' : 'performance-negative';
    $currentYear = date('Y');
    
    // Get fee percentages from invoice data (passed from generate_invoices.php)
    $realizedFeePercent = isset($invoiceData['realized_fee_percent']) ? (float)$invoiceData['realized_fee_percent'] : 20.0;
    $unrealizedFeePercent = isset($invoiceData['unrealized_fee_percent']) ? (float)$invoiceData['unrealized_fee_percent'] : 30.0;
    
    // Format billing period from "2025-12" to "DECEMBER-2025"
    $billingPeriodFormatted = $invoiceData['month_year'];
    if (preg_match('/^(\d{4})-(\d{2})$/', $invoiceData['month_year'], $matches)) {
        $year = $matches[1];
        $monthNum = (int)$matches[2];
        $monthNames = ['', 'JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 
                      'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];
        if ($monthNum >= 1 && $monthNum <= 12) {
            $billingPeriodFormatted = $monthNames[$monthNum] . '-' . $year;
        }
    }
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; 
                line-height: 1.5; 
                color: #1f2937; 
                margin: 0; 
                padding: 0; 
                background-color: #fafaf9;
            }
            .container { 
                max-width: 650px; 
                margin: 0 auto; 
                background-color: #ffffff;
            }
            .header { 
                background-color: #F5E6D3; 
                padding: 40px 40px 30px 40px; 
                border-bottom: 1px solid #e5e7eb;
            }
            .invoice-header {
                margin-top: 20px;
            }
            .invoice-title-row {
                width: 100%;
            }
            .invoice-title {
                font-size: 32px;
                font-weight: 700;
                color: #1f2937;
                margin: 0;
                letter-spacing: -0.5px;
            }
            .invoice-number {
                color: #6b7280;
                font-size: 14px;
                white-space: nowrap;
                text-align: right;
            }
            .invoice-number-label {
                display: block;
                margin-bottom: 4px;
            }
            .invoice-number-value {
                color: #1f2937;
                font-weight: 600;
            }
            .content { 
                background: #ffffff; 
                padding: 0 40px 40px 40px; 
            }
            .invoice-info {
                display: flex;
                justify-content: space-between;
                margin: 30px 0;
                padding: 25px 0;
                border-top: 1px solid #e5e7eb;
                border-bottom: 1px solid #e5e7eb;
            }
            .info-column {
                flex: 1;
            }
            .info-column.right-align {
                text-align: right !important;
            }
            .info-label {
                font-size: 12px;
                color: #6b7280;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 8px;
                font-weight: 600;
            }
            .info-value {
                font-size: 15px;
                color: #1f2937;
                font-weight: 500;
            }
            .invoice-table {
                width: 100%;
                border-collapse: collapse;
                margin: 30px 0;
            }
            .invoice-table thead {
                background-color: #f9fafb;
            }
            .invoice-table th {
                text-align: left;
                padding: 12px 16px;
                font-size: 12px;
                font-weight: 600;
                color: #6b7280;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                border-bottom: 2px solid #e5e7eb;
            }
            .invoice-table th.text-right {
                text-align: right;
            }
            .invoice-table td {
                padding: 16px;
                border-bottom: 1px solid #f3f4f6;
                font-size: 15px;
                color: #1f2937;
            }
            .invoice-table td.text-right {
                text-align: right;
                padding-right: 16px;
            }
            .invoice-table tbody tr:last-child td {
                border-bottom: none;
            }
            .text-left {
                text-align: left;
            }
            .text-right {
                text-align: right;
            }
            .amount-total {
                font-size: 24px;
                font-weight: 700;
                color: #1f2937;
            }
            .amount-label {
                font-size: 15px;
                font-weight: 600;
                color: #1f2937;
            }
            .hwm-section {
                background-color: #f9fafb;
                padding: 24px;
                margin: 30px 0;
                border: 1px solid #e5e7eb;
            }
            .hwm-title {
                font-weight: 700;
                color: #1f2937;
                font-size: 14px;
                margin-bottom: 16px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .hwm-row {
                display: flex;
                justify-content: space-between;
                padding: 10px 0;
                font-size: 14px;
                border-bottom: 1px solid #e5e7eb;
            }
            .hwm-row:last-child {
                border-bottom: none;
            }
            .hwm-label {
                color: #6b7280;
                font-weight: 500;
            }
            .hwm-value {
                color: #1f2937;
                font-weight: 600;
            }
            .performance-positive {
                color: #059669;
                font-weight: 600;
            }
            .performance-negative {
                color: #dc2626;
                font-weight: 600;
            }
            .footer { 
                background-color: #f9fafb;
                padding: 30px 40px; 
                border-top: 1px solid #e5e7eb;
                color: #6b7280; 
                font-size: 13px;
            }
            .footer p {
                margin: 6px 0;
                line-height: 1.6;
            }
            .footer-link {
                color: #f59e0b;
                text-decoration: none;
                font-weight: 500;
            }
            .footer-link:hover {
                text-decoration: underline;
            }
            .button { 
                display: inline-block; 
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); 
                color: white; 
                padding: 14px 32px; 
                text-decoration: none; 
                border-radius: 8px; 
                margin: 20px 0;
                font-weight: 600;
                font-size: 14px;
                box-shadow: 0 2px 4px rgba(245, 158, 11, 0.3);
            }
            .button-container {
                text-align: center;
                margin: 30px 0;
                background-color: #F5E6D3 !important;
                padding: 30px;
            }
            .note-section {
                margin-top: 30px;
                padding: 20px;
                background-color: #f9fafb;
                border-left: 3px solid #f59e0b;
            }
            .note-text {
                color: #4b5563;
                font-size: 13px;
                line-height: 1.6;
                margin: 0;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='invoice-header'>
                    <table width='100%' cellpadding='0' cellspacing='0' border='0' style='width: 100%;'>
                        <tr>
                            <td align='left' valign='baseline' style='vertical-align: baseline;'>
                                <h1 class='invoice-title' style='margin: 0; padding: 0; line-height: 1; display: inline-block;'>INVOICE</h1>
                            </td>
                            <td align='right' valign='baseline' style='text-align: right; vertical-align: baseline; padding-left: 20px;'>
                                <span class='invoice-number-label' style='color: #6b7280; font-size: 14px;'>Invoice #</span>
                                <span class='invoice-number-value' style='color: #1f2937; font-weight: 600; font-size: 14px;'>{$invoiceData['month_year']}</span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            <div class='content'>
                <div class='invoice-info'>
                    <table width='100%' cellpadding='0' cellspacing='0' border='0' style='width: 100%;'>
                        <tr>
                            <td align='left' valign='top' style='width: 50%;'>
                                <div class='info-label'>Bill To</div>
                                <div class='info-value'>$userName</div>
                            </td>
                            <td align='right' valign='top' style='width: 50%; text-align: right;'>
                                <div class='info-label' style='text-align: right;'>Billing Period</div>
                                <div class='info-value' style='text-align: right;'>{$billingPeriodFormatted}</div>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <table class='invoice-table'>
                    <thead>
                        <tr>
                            <th class='text-left'>Description</th>
                            <th class='text-right'>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class='text-left'>Performance Gain</td>
                            <td class='text-right {$performanceClass}'>" . number_format($performanceGain, 2) . "%</td>
                        </tr>
                        <tr>
                            <td class='text-left'>Realized P&L Fee (" . number_format($realizedFeePercent, 1) . "%)</td>
                            <td class='text-right'>{$currencySymbol}" . number_format($invoiceData['fee_realized'] ?? 0, 2) . "</td>
                        </tr>
                        <tr>
                            <td class='text-left'>Unrealized P&L Fee (" . number_format($unrealizedFeePercent, 1) . "%)</td>
                            <td class='text-right'>{$currencySymbol}" . number_format($invoiceData['fee_unrealized'] ?? 0, 2) . "</td>
                        </tr>
                        <tr>
                            <td class='text-left'>Previous HWM</td>
                            <td class='text-right'>{$hwmBeforeFormatted}</td>
                        </tr>
                        <tr>
                            <td class='text-left'>Current Equity</td>
                            <td class='text-right'>{$equityEndFormatted}</td>
                        </tr>
                        <tr style='background-color: #f9fafb;'>
                            <td class='text-left amount-label'>Total Amount Due</td>
                            <td class='text-right amount-total'>{$formattedAmount}</td>
                        </tr>
                    </tbody>
                </table>
                
                <div class='button-container'>
                    <a href='https://sinegualfamily.com/user/invoices' class='button'>View Invoice & Pay Now</a>
                </div>
                
                <div class='note-section'>
                    <p class='note-text'>
                        <strong>Payment Terms:</strong> This invoice is based on your account's performance above the High Water Mark. Fees are calculated as " . number_format($realizedFeePercent, 1) . "% of realized P&L and " . number_format($unrealizedFeePercent, 1) . "% of unrealized P&L. Payment is due within 30 days of invoice generation.
                    </p>
                </div>
            </div>
            <div class='footer'>
                <p><strong>SineguAlerts</strong> - Professional Trading Platform</p>
                <p>This is an automated invoice notification. Please do not reply to this email.</p>
                <p>Questions? Contact us through your <a href='https://sinegualfamily.com/user/invoices' class='footer-link'>account dashboard</a>.</p>
                <p style='margin-top: 20px; font-size: 12px; color: #9ca3af;'>
                    © {$currentYear} SineguAlerts. All rights reserved.
                </p>
            </div>
        </div>
    </body>
    </html>
    ";
}

function sendInvoiceEmail($conn, $uniId, $invoiceData) {
    // Validate inputs
    if (empty($uniId)) {
        return [
            'success' => false,
            'message' => "uni_id is empty or not provided"
        ];
    }
    
    // Get email from user_credentials table using uni_id
    $escapedUniId = mysqli_real_escape_string($conn, $uniId);
    $emailQuery = "SELECT email, name FROM user_credentials WHERE uni_id = '$escapedUniId' LIMIT 1";
    $emailResult = mysqli_query($conn, $emailQuery);
    
    if (!$emailResult) {
        return [
            'success' => false,
            'message' => "Database error: " . mysqli_error($conn)
        ];
    }
    
    if (mysqli_num_rows($emailResult) === 0) {
        return [
            'success' => false,
            'message' => "User email not found for uni_id: $uniId"
        ];
    }
    
    $userData = mysqli_fetch_assoc($emailResult);
    $userEmail = $userData['email'];
    $userName = $userData['name'] ?? 'Valued Customer';
    
    // Log for debugging
    error_log("sendInvoiceEmail called for uni_id: $uniId, found email: $userEmail, name: $userName");
    
    // Validate email address
    if (empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid email address for uni_id $uniId: $userEmail");
        return [
            'success' => false,
            'message' => "Invalid email address: $userEmail"
        ];
    }
    
    // Check if PHPMailer is available via Composer autoload
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    $phpmailerPath = __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    
    if (file_exists($autoloadPath)) {
        // Use Composer autoloader (preferred method)
        require_once $autoloadPath;
        
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings - Hostinger SMTP
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';
            $mail->SMTPDebug = 0; // Set to 2 for debugging
            $mail->Debugoutput = function($str, $level) {
                error_log("PHPMailer Debug: $str");
            };
            
            // Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($userEmail, $userName);
            
            // Format currency and calculate values
            $currency = $invoiceData['currency'] ?? 'USD';
            $currencySymbol = $currency === 'EUR' ? '€' : '$';
            $formattedAmount = $currencySymbol . number_format($invoiceData['total_fee'], 2);
            
            // Calculate performance gain and HWM values
            $hwmBefore = $invoiceData['hwm_before'] ?? 0;
            $equityEnd = $invoiceData['equity_end'] ?? 0;
            $depositAmount = $invoiceData['deposit_amount'] ?? 0;
            
            $performanceGain = 0;
            if ($hwmBefore > 0) {
                $performanceGain = (($equityEnd - $hwmBefore) / $hwmBefore) * 100;
            } elseif ($depositAmount > 0) {
                $performanceGain = (($equityEnd - $depositAmount) / $depositAmount) * 100;
            }
            
            // Format HWM values
            $hwmBeforeFormatted = $currencySymbol . number_format($hwmBefore, 2);
            $equityEndFormatted = $currencySymbol . number_format($equityEnd, 2);
            $depositAmountFormatted = $currencySymbol . number_format($depositAmount, 2);
            
            // Embed logo as attachment if file exists
            $logoCid = null;
            $logoPath = __DIR__ . '/../assets/logo.png';
            if (file_exists($logoPath)) {
                $logoCid = 'logo_' . uniqid();
                $mail->addEmbeddedImage($logoPath, $logoCid, 'logo.png', 'base64', 'image/png');
            }
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Invoice for ' . $invoiceData['month_year'] . ' - SineguAlerts';
            
            // Generate email body using helper function
            $emailBody = generateInvoiceEmailBody(
                $userName,
                $invoiceData,
                $currencySymbol,
                $formattedAmount,
                $performanceGain,
                $hwmBeforeFormatted,
                $equityEndFormatted,
                $depositAmountFormatted,
                $logoCid
            );
            
            $mail->Body = $emailBody;
            $realizedFeePercentAlt = isset($invoiceData['realized_fee_percent']) ? (float)$invoiceData['realized_fee_percent'] : 20.0;
            $unrealizedFeePercentAlt = isset($invoiceData['unrealized_fee_percent']) ? (float)$invoiceData['unrealized_fee_percent'] : 30.0;
            $mail->AltBody = "Dear $userName,\n\nYour monthly performance invoice for {$invoiceData['month_year']} has been generated.\n\nINVOICE SUMMARY:\nBilling Period: {$invoiceData['month_year']}\nPerformance Gain: " . number_format($performanceGain, 2) . "%\nRealized P&L Fee (" . number_format($realizedFeePercentAlt, 1) . "%): {$currencySymbol}" . number_format($invoiceData['fee_realized'] ?? 0, 2) . "\nUnrealized P&L Fee (" . number_format($unrealizedFeePercentAlt, 1) . "%): {$currencySymbol}" . number_format($invoiceData['fee_unrealized'] ?? 0, 2) . "\n\nHIGH WATER MARK DETAILS:\nInitial Deposit: {$depositAmountFormatted}\nPrevious HWM: {$hwmBeforeFormatted}\nCurrent Equity: {$equityEndFormatted}\nPerformance Above HWM: " . number_format($performanceGain, 2) . "%\n\nTotal Amount Due: {$formattedAmount}\n\nTo view the complete invoice and make a payment, please log in to your SineguAlerts account:\nhttps://sinegualfamily.com/user/invoices\n\nThis invoice is based on your account's performance above the High Water Mark. Fees are calculated as " . number_format($realizedFeePercentAlt, 1) . "% of realized P&L and " . number_format($unrealizedFeePercentAlt, 1) . "% of unrealized P&L. Payment is due within 30 days.\n\nIf you have any questions, please contact our support team through your account dashboard.\n\nSineguAlerts - Professional Trading Platform";
            
            $mail->send();
            
            return [
                'success' => true,
                'message' => "Invoice email sent successfully to $userEmail"
            ];
            
        } catch (PHPMailer\PHPMailer\Exception $e) {
            $errorMsg = $mail->ErrorInfo ?: $e->getMessage();
            error_log("PHPMailer Exception for uni_id $uniId: " . $errorMsg);
            return [
                'success' => false,
                'message' => "Failed to send email: $errorMsg"
            ];
        } catch (Exception $e) {
            error_log("General Exception in sendInvoiceEmail for uni_id $uniId: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to send email: " . $e->getMessage()
            ];
        }
    } elseif (file_exists($phpmailerPath)) {
        // Fallback: Load PHPMailer files directly
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
        
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings - Hostinger SMTP
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';
            $mail->SMTPDebug = 0;
            
            // Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($userEmail, $userName);
            
            // Format currency and calculate values
            $currency = $invoiceData['currency'] ?? 'USD';
            $currencySymbol = $currency === 'EUR' ? '€' : '$';
            $formattedAmount = $currencySymbol . number_format($invoiceData['total_fee'], 2);
            
            // Calculate performance gain and HWM values
            $hwmBefore = $invoiceData['hwm_before'] ?? 0;
            $equityEnd = $invoiceData['equity_end'] ?? 0;
            $depositAmount = $invoiceData['deposit_amount'] ?? 0;
            
            $performanceGain = 0;
            if ($hwmBefore > 0) {
                $performanceGain = (($equityEnd - $hwmBefore) / $hwmBefore) * 100;
            } elseif ($depositAmount > 0) {
                $performanceGain = (($equityEnd - $depositAmount) / $depositAmount) * 100;
            }
            
            // Format HWM values
            $hwmBeforeFormatted = $currencySymbol . number_format($hwmBefore, 2);
            $equityEndFormatted = $currencySymbol . number_format($equityEnd, 2);
            $depositAmountFormatted = $currencySymbol . number_format($depositAmount, 2);
            
            // Embed logo as attachment if file exists
            $logoCid = null;
            $logoPath = __DIR__ . '/../assets/logo.png';
            if (file_exists($logoPath)) {
                $logoCid = 'logo_' . uniqid();
                $mail->addEmbeddedImage($logoPath, $logoCid, 'logo.png', 'base64', 'image/png');
            }
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Invoice for ' . $invoiceData['month_year'] . ' - SineguAlerts';
            
            // Generate email body using helper function
            $emailBody = generateInvoiceEmailBody(
                $userName,
                $invoiceData,
                $currencySymbol,
                $formattedAmount,
                $performanceGain,
                $hwmBeforeFormatted,
                $equityEndFormatted,
                $depositAmountFormatted,
                $logoCid
            );
            
            $mail->Body = $emailBody;
            $realizedFeePercentAlt = isset($invoiceData['realized_fee_percent']) ? (float)$invoiceData['realized_fee_percent'] : 20.0;
            $unrealizedFeePercentAlt = isset($invoiceData['unrealized_fee_percent']) ? (float)$invoiceData['unrealized_fee_percent'] : 30.0;
            $mail->AltBody = "Dear $userName,\n\nYour monthly performance invoice for {$invoiceData['month_year']} has been generated.\n\nINVOICE SUMMARY:\nBilling Period: {$invoiceData['month_year']}\nPerformance Gain: " . number_format($performanceGain, 2) . "%\nRealized P&L Fee (" . number_format($realizedFeePercentAlt, 1) . "%): {$currencySymbol}" . number_format($invoiceData['fee_realized'] ?? 0, 2) . "\nUnrealized P&L Fee (" . number_format($unrealizedFeePercentAlt, 1) . "%): {$currencySymbol}" . number_format($invoiceData['fee_unrealized'] ?? 0, 2) . "\n\nHIGH WATER MARK DETAILS:\nInitial Deposit: {$depositAmountFormatted}\nPrevious HWM: {$hwmBeforeFormatted}\nCurrent Equity: {$equityEndFormatted}\nPerformance Above HWM: " . number_format($performanceGain, 2) . "%\n\nTotal Amount Due: {$formattedAmount}\n\nTo view the complete invoice and make a payment, please log in to your SineguAlerts account:\nhttps://sinegualfamily.com/user/invoices\n\nThis invoice is based on your account's performance above the High Water Mark. Fees are calculated as " . number_format($realizedFeePercentAlt, 1) . "% of realized P&L and " . number_format($unrealizedFeePercentAlt, 1) . "% of unrealized P&L. Payment is due within 30 days.\n\nIf you have any questions, please contact our support team through your account dashboard.\n\nSineguAlerts - Professional Trading Platform";
            
            $mail->send();
            
            return [
                'success' => true,
                'message' => "Invoice email sent successfully to $userEmail"
            ];
            
        } catch (PHPMailer\PHPMailer\Exception $e) {
            $errorMsg = $mail->ErrorInfo ?: $e->getMessage();
            error_log("PHPMailer Exception for uni_id $uniId: " . $errorMsg);
            return [
                'success' => false,
                'message' => "Failed to send email: $errorMsg"
            ];
        } catch (Exception $e) {
            error_log("General Exception in sendInvoiceEmail for uni_id $uniId: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to send email: " . $e->getMessage()
            ];
        }
    } else {
        // Fallback to PHP mail() function if PHPMailer is not available
        $fromEmail = SMTP_FROM_EMAIL;
        $fromName = 'SineguAlerts';
        $subject = 'Invoice for ' . $invoiceData['month_year'] . ' - SineguAlerts';
        
        // Format currency and calculate values
        $currency = $invoiceData['currency'] ?? 'USD';
        $currencySymbol = $currency === 'EUR' ? '€' : '$';
        $formattedAmount = $currencySymbol . number_format($invoiceData['total_fee'], 2);
        
        // Calculate performance gain and HWM values
        $hwmBefore = $invoiceData['hwm_before'] ?? 0;
        $equityEnd = $invoiceData['equity_end'] ?? 0;
        $depositAmount = $invoiceData['deposit_amount'] ?? 0;
        
        $performanceGain = 0;
        if ($hwmBefore > 0) {
            $performanceGain = (($equityEnd - $hwmBefore) / $hwmBefore) * 100;
        } elseif ($depositAmount > 0) {
            $performanceGain = (($equityEnd - $depositAmount) / $depositAmount) * 100;
        }
        
        // Format HWM values
        $hwmBeforeFormatted = $currencySymbol . number_format($hwmBefore, 2);
        $equityEndFormatted = $currencySymbol . number_format($equityEnd, 2);
        $depositAmountFormatted = $currencySymbol . number_format($depositAmount, 2);
        
        // For PHP mail() fallback, we can't embed images, so use text logo
        $logoCid = null;
        
        // Generate email body using helper function
        $emailBody = generateInvoiceEmailBody(
            $userName,
            $invoiceData,
            $currencySymbol,
            $formattedAmount,
            $performanceGain,
            $hwmBeforeFormatted,
            $equityEndFormatted,
            $depositAmountFormatted,
            $logoCid
        );
        
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: $fromName <$fromEmail>\r\n";
        $headers .= "Reply-To: $fromEmail\r\n";
        
        $mailSent = @mail($userEmail, $subject, $emailBody, $headers);
        
        if ($mailSent) {
            return [
                'success' => true,
                'message' => "Invoice email sent successfully to $userEmail"
            ];
        } else {
            return [
                'success' => false,
                'message' => "Failed to send email to $userEmail. Check PHP mail configuration."
            ];
        }
    }
}

/**
 * Generate HTML body for reminder or overdue email (same design as main invoice email).
 * @param string $userName Recipient name (HTML-escaped by caller if needed)
 * @param string $title Header title (e.g. "REMINDER" or "INVOICE OVERDUE")
 * @param string $periodLabel Billing period label (e.g. "2026-01" or "JANUARY-2026")
 * @param string $formattedAmount Currency + amount (e.g. "€128.43")
 * @param string $messageHtml Main message paragraph(s) HTML
 * @param string $buttonText CTA button text
 * @param string $footerNote Optional footer note (e.g. reminder vs overdue)
 */
function generateReminderOverdueEmailBody($userName, $title, $periodLabel, $formattedAmount, $messageHtml, $buttonText = 'View Invoice & Pay Now', $footerNote = '') {
    $currentYear = date('Y');
    $payUrl = 'https://sinegualfamily.com/user/invoices';
    $defaultNote = 'This is an automated notification. Please do not reply to this email.';
    $note = $footerNote !== '' ? $footerNote : $defaultNote;
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.5; color: #1f2937; margin: 0; padding: 0; background-color: #fafaf9; }
            .container { max-width: 650px; margin: 0 auto; background-color: #ffffff; }
            .header { background-color: #F5E6D3; padding: 40px 40px 30px 40px; border-bottom: 1px solid #e5e7eb; }
            .invoice-title { font-size: 32px; font-weight: 700; color: #1f2937; margin: 0; letter-spacing: -0.5px; }
            .invoice-number-label { color: #6b7280; font-size: 14px; }
            .invoice-number-value { color: #1f2937; font-weight: 600; font-size: 14px; }
            .content { background: #ffffff; padding: 0 40px 40px 40px; }
            .invoice-info { margin: 30px 0; padding: 25px 0; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; }
            .info-label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; font-weight: 600; }
            .info-value { font-size: 15px; color: #1f2937; font-weight: 500; }
            .invoice-table { width: 100%; border-collapse: collapse; margin: 30px 0; }
            .invoice-table thead { background-color: #f9fafb; }
            .invoice-table th { text-align: left; padding: 12px 16px; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; }
            .invoice-table th.text-right { text-align: right; }
            .invoice-table td { padding: 16px; border-bottom: 1px solid #f3f4f6; font-size: 15px; color: #1f2937; }
            .invoice-table td.text-right { text-align: right; padding-right: 16px; }
            .invoice-table tbody tr:last-child td { border-bottom: none; }
            .amount-total { font-size: 24px; font-weight: 700; color: #1f2937; }
            .amount-label { font-size: 15px; font-weight: 600; color: #1f2937; }
            .message-section { margin: 24px 0; font-size: 15px; color: #4b5563; line-height: 1.6; }
            .button-container { text-align: center; margin: 30px 0; background-color: #F5E6D3; padding: 30px; }
            .button { display: inline-block; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 14px 32px; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: 600; font-size: 14px; box-shadow: 0 2px 4px rgba(245, 158, 11, 0.3); }
            .footer { background-color: #f9fafb; padding: 30px 40px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 13px; }
            .footer p { margin: 6px 0; line-height: 1.6; }
            .footer-link { color: #f59e0b; text-decoration: none; font-weight: 500; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <table width='100%' cellpadding='0' cellspacing='0' border='0' style='width: 100%;'>
                    <tr>
                        <td align='left' valign='baseline' style='vertical-align: baseline;'>
                            <h1 class='invoice-title' style='margin: 0; padding: 0; line-height: 1; display: inline-block;'>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</h1>
                        </td>
                        <td align='right' valign='baseline' style='text-align: right; vertical-align: baseline; padding-left: 20px;'>
                            <span class='invoice-number-label'>Invoice #</span>
                            <span class='invoice-number-value'>" . htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') . "</span>
                        </td>
                    </tr>
                </table>
            </div>
            <div class='content'>
                <div class='invoice-info'>
                    <table width='100%' cellpadding='0' cellspacing='0' border='0' style='width: 100%;'>
                        <tr>
                            <td align='left' valign='top' style='width: 50%;'>
                                <div class='info-label'>Bill To</div>
                                <div class='info-value'>" . htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') . "</div>
                            </td>
                            <td align='right' valign='top' style='width: 50%; text-align: right;'>
                                <div class='info-label' style='text-align: right;'>Billing Period</div>
                                <div class='info-value' style='text-align: right;'>" . htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') . "</div>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class='message-section'>
                    " . $messageHtml . "
                </div>
                <table class='invoice-table'>
                    <thead>
                        <tr>
                            <th class='text-left'>Description</th>
                            <th class='text-right'>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style='background-color: #f9fafb;'>
                            <td class='text-left amount-label'>Total Amount Due</td>
                            <td class='text-right amount-total'>" . htmlspecialchars($formattedAmount, ENT_QUOTES, 'UTF-8') . "</td>
                        </tr>
                    </tbody>
                </table>
                <div class='button-container'>
                    <a href='" . htmlspecialchars($payUrl, ENT_QUOTES, 'UTF-8') . "' class='button'>" . htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8') . "</a>
                </div>
            </div>
            <div class='footer'>
                <p><strong>SineguAlerts</strong> - Professional Trading Platform</p>
                <p>" . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . "</p>
                <p>Questions? Contact us through your <a href='" . htmlspecialchars($payUrl, ENT_QUOTES, 'UTF-8') . "' class='footer-link'>account dashboard</a>.</p>
                <p style='margin-top: 20px; font-size: 12px; color: #9ca3af;'>© " . (int)$currentYear . " SineguAlerts. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Send invoice reminder email (2nd, 3rd, or 4th reminder).
 * @param mysqli $conn Database connection
 * @param string $uniId User's unique ID (user_id from billing_periods)
 * @param array $invoiceData Same shape as sendInvoiceEmail (month_year, total_fee, currency, etc.)
 * @param int $reminderNumber 2, 3, or 4
 * @return array ['success' => bool, 'message' => string]
 */
function sendInvoiceReminderEmail($conn, $uniId, $invoiceData, $reminderNumber) {
    if (empty($uniId)) {
        return ['success' => false, 'message' => 'uni_id is required'];
    }
    $escapedUniId = mysqli_real_escape_string($conn, $uniId);
    $emailQuery = "SELECT email, name FROM user_credentials WHERE uni_id = '$escapedUniId' LIMIT 1";
    $res = mysqli_query($conn, $emailQuery);
    if (!$res || mysqli_num_rows($res) === 0) {
        return ['success' => false, 'message' => "User not found for uni_id: $uniId"];
    }
    $row = mysqli_fetch_assoc($res);
    $userEmail = $row['email'];
    $userName = $row['name'] ?? 'Valued Customer';
    if (empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => "Invalid email for uni_id: $uniId"];
    }
    $currency = $invoiceData['currency'] ?? 'USD';
    $symbol = $currency === 'EUR' ? '€' : '$';
    $amount = $symbol . number_format($invoiceData['total_fee'] ?? 0, 2);
    $monthYear = $invoiceData['month_year'] ?? '';
    $periodLabel = $monthYear;
    if (preg_match('/^(\d{4})-(\d{2})$/', $monthYear, $m)) {
        $months = ['', 'JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];
        $mon = (int)$m[2];
        if ($mon >= 1 && $mon <= 12) {
            $periodLabel = $months[$mon] . '-' . $m[1];
        }
    }
    $subject = "A quick reminder about your " . $periodLabel . " invoice - SineguAlerts";
    $messageHtml = "<p>Hi " . htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') . ",</p><p>We wanted to reach out with a quick reminder that your invoice for <strong>" . htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') . "</strong> is still open.</p><p>No worries — you can pay whenever it works for you. If you have any questions, we're here to help.</p>";
    $htmlBody = generateReminderOverdueEmailBody($userName, "Invoice reminder", $periodLabel, $amount, $messageHtml, 'View invoice & pay', 'This is an automated reminder. Need help? Reach out through your account dashboard.');
    $altBody = "Hi $userName,\n\nA quick reminder that your invoice for $periodLabel is still open. Amount due: $amount\n\nPay here whenever it works for you: https://sinegualfamily.com/user/invoices\n\n— SineguAlerts";
    return _sendGenericMail($conn, $userEmail, $userName, $subject, $htmlBody, $altBody, $uniId);
}

/**
 * Send overdue / disconnection notice email.
 * @param mysqli $conn Database connection
 * @param string $uniId User's unique ID
 * @param array $invoiceData month_year, total_fee, currency, etc.
 * @return array ['success' => bool, 'message' => string]
 */
function sendOverdueDisconnectEmail($conn, $uniId, $invoiceData) {
    if (empty($uniId)) {
        return ['success' => false, 'message' => 'uni_id is required'];
    }
    $escapedUniId = mysqli_real_escape_string($conn, $uniId);
    $emailQuery = "SELECT email, name FROM user_credentials WHERE uni_id = '$escapedUniId' LIMIT 1";
    $res = mysqli_query($conn, $emailQuery);
    if (!$res || mysqli_num_rows($res) === 0) {
        return ['success' => false, 'message' => "User not found for uni_id: $uniId"];
    }
    $row = mysqli_fetch_assoc($res);
    $userEmail = $row['email'];
    $userName = $row['name'] ?? 'Valued Customer';
    if (empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => "Invalid email for uni_id: $uniId"];
    }
    $currency = $invoiceData['currency'] ?? 'USD';
    $symbol = $currency === 'EUR' ? '€' : '$';
    $amount = $symbol . number_format($invoiceData['total_fee'] ?? 0, 2);
    $monthYear = $invoiceData['month_year'] ?? '';
    $periodLabel = $monthYear;
    if (preg_match('/^(\d{4})-(\d{2})$/', $monthYear, $m)) {
        $months = ['', 'JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];
        $mon = (int)$m[2];
        if ($mon >= 1 && $mon <= 12) {
            $periodLabel = $months[$mon] . '-' . $m[1];
        }
    }
    $subject = "Your " . $periodLabel . " invoice is overdue – let's get you back on track - SineguAlerts";
    $messageHtml = "<p>Hi " . htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') . ",</p><p>Your invoice for <strong>" . htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') . "</strong> is now overdue, and your account has been temporarily paused until we receive payment.</p><p>No worries — you can restore access anytime by paying the amount below. If you need help or have questions, we're here for you.</p>";
    $htmlBody = generateReminderOverdueEmailBody($userName, 'Invoice overdue', $periodLabel, $amount, $messageHtml, 'Pay now & restore access', 'This is an automated notice. Need help? Reach out through your account dashboard.');
    $altBody = "Hi $userName,\n\nYour invoice for $periodLabel is overdue and your account is temporarily paused. Amount due: $amount\n\nPay here to restore access: https://sinegualfamily.com/user/invoices\n\n— SineguAlerts";
    return _sendGenericMail($conn, $userEmail, $userName, $subject, $htmlBody, $altBody, $uniId);
}

/**
 * Internal: send one email via PHPMailer (or mail() fallback).
 */
function _sendGenericMail($conn, $userEmail, $userName, $subject, $htmlBody, $altBody, $uniIdForLog = '') {
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    $phpmailerPath = __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($userEmail, $userName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $altBody;
            $mail->send();
            return ['success' => true, 'message' => "Email sent to $userEmail"];
        } catch (Exception $e) {
            error_log("_sendGenericMail uni_id $uniIdForLog: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
    $ok = @mail($userEmail, $subject, $htmlBody, $headers);
    return $ok
        ? ['success' => true, 'message' => "Email sent to $userEmail"]
        : ['success' => false, 'message' => 'PHP mail() failed'];
}

