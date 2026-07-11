<?php
/**
 * EMAIL HELPER CLASS
 * 
 * Provides email sending functionality with multiple methods:
 * 1. SMTP (recommended for production)
 * 2. PHP mail() function (requires sendmail configuration)
 * 3. Fallback to file logging for development
 */

require_once __DIR__ . '/email_config.php';

class EmailHelper {
    private $lastError = '';
    
    /**
     * Send an email
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $message Email body (can be HTML)
     * @param array $options Additional options (from_email, from_name, is_html, attachments)
     * @return bool True on success, false on failure
     */
    public function send($to, $subject, $message, $options = []) {
        // Validate inputs
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'Invalid recipient email address';
            $this->logEmail('ERROR', "Invalid email: $to");
            return false;
        }
        
        if (empty($subject) || empty($message)) {
            $this->lastError = 'Subject and message are required';
            return false;
        }
        
        // Set defaults
        $from_email = $options['from_email'] ?? SMTP_FROM_EMAIL;
        $from_name = $options['from_name'] ?? SMTP_FROM_NAME;
        $is_html = $options['is_html'] ?? true;
        
        // Log the attempt
        $this->logEmail('INFO', "Attempting to send email to: $to, Subject: $subject");
        
        // Choose sending method
        $method = EMAIL_METHOD;
        
        if ($method === 'smtp') {
            return $this->sendViaSMTP($to, $subject, $message, $from_email, $from_name, $is_html);
        } elseif ($method === 'sendmail') {
            return $this->sendViaSendmail($to, $subject, $message, $from_email, $from_name, $is_html);
        } else {
            return $this->sendViaMail($to, $subject, $message, $from_email, $from_name, $is_html);
        }
    }
    
    /**
     * Send email via SMTP (most reliable)
     */
    private function sendViaSMTP($to, $subject, $message, $from_email, $from_name, $is_html) {
        // Check if PHPMailer is available
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $this->lastError = 'PHPMailer library not found. Falling back to mail().';
            $this->logEmail('WARNING', $this->lastError);
            return $this->sendViaMail($to, $subject, $message, $from_email, $from_name, $is_html);
        }
        
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
            $mail->CharSet = 'UTF-8';
            
            // Recipients
            $mail->setFrom($from_email, $from_name);
            $mail->addAddress($to);
            $mail->addReplyTo($from_email, $from_name);
            
            // Content
            $mail->isHTML($is_html);
            $mail->Subject = $subject;
            $mail->Body = $message;
            
            if ($is_html) {
                $mail->AltBody = strip_tags($message);
            }
            
            // Send
            $mail->send();
            $this->logEmail('SUCCESS', "Email sent to $to via SMTP");
            return true;
            
        } catch (Exception $e) {
            $this->lastError = "SMTP Error: {$mail->ErrorInfo}";
            $this->logEmail('ERROR', $this->lastError);
            return false;
        }
    }
    
    /**
     * Send email via sendmail (XAMPP default)
     */
    private function sendViaSendmail($to, $subject, $message, $from_email, $from_name, $is_html) {
        // Configure sendmail
        ini_set('sendmail_path', SENDMAIL_PATH);
        return $this->sendViaMail($to, $subject, $message, $from_email, $from_name, $is_html);
    }
    
    /**
     * Send email via PHP mail() function
     */
    private function sendViaMail($to, $subject, $message, $from_email, $from_name, $is_html) {
        // Build headers
        $headers = [];
        $headers[] = "From: $from_name <$from_email>";
        $headers[] = "Reply-To: $from_email";
        $headers[] = "X-Mailer: PHP/" . phpversion();
        $headers[] = "MIME-Version: 1.0";
        
        if ($is_html) {
            $headers[] = "Content-Type: text/html; charset=UTF-8";
        } else {
            $headers[] = "Content-Type: text/plain; charset=UTF-8";
        }
        
        $header_string = implode("\r\n", $headers);
        
        // Send email
        $result = @mail($to, $subject, $message, $header_string);
        
        if ($result) {
            $this->logEmail('SUCCESS', "Email sent to $to via mail()");
            return true;
        } else {
            $this->lastError = 'mail() function failed. Check server configuration.';
            $this->logEmail('ERROR', $this->lastError);
            
            // Fallback: Log to file for development
            $this->logEmailToFile($to, $subject, $message);
            return false;
        }
    }
    
    /**
     * Log email to file (for development/testing)
     */
    private function logEmailToFile($to, $subject, $message) {
        $logDir = dirname(EMAIL_LOG_FILE);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $emailLog = "==================== EMAIL ====================\n";
        $emailLog .= "Date: " . date('Y-m-d H:i:s') . "\n";
        $emailLog .= "To: $to\n";
        $emailLog .= "Subject: $subject\n";
        $emailLog .= "Message:\n$message\n";
        $emailLog .= "===============================================\n\n";
        
        @file_put_contents(
            str_replace('.log', '_preview.txt', EMAIL_LOG_FILE),
            $emailLog,
            FILE_APPEND
        );
        
        $this->logEmail('INFO', "Email logged to file (mail() failed): $to");
    }
    
    /**
     * Log email activity
     */
    private function logEmail($level, $message) {
        if (!EMAIL_DEBUG) {
            return;
        }
        
        $logDir = dirname(EMAIL_LOG_FILE);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logMessage = "[" . date('Y-m-d H:i:s') . "] [$level] $message\n";
        @file_put_contents(EMAIL_LOG_FILE, $logMessage, FILE_APPEND);
        
        // Also log to error_log for critical errors
        if ($level === 'ERROR') {
            error_log("Email Error: $message");
        }
    }
    
    /**
     * Get last error message
     */
    public function getLastError() {
        return $this->lastError;
    }
    
    /**
     * Create HTML email template
     */
    public function createHTMLTemplate($title, $content, $buttonText = '', $buttonUrl = '') {
        $template = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 600px;
            margin: 20px auto;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .email-header {
            background: linear-gradient(135deg, #4B0082 0%, #3a0066 100%);
            color: #ffffff;
            padding: 30px 20px;
            text-align: center;
        }
        .email-header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .email-body {
            padding: 30px 20px;
        }
        .email-content {
            font-size: 16px;
            margin-bottom: 20px;
        }
        .email-button {
            display: inline-block;
            padding: 12px 30px;
            background-color: #4B0082;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            margin: 20px 0;
        }
        .email-footer {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            font-size: 14px;
            color: #6c757d;
            border-top: 1px solid #dee2e6;
        }
        .email-footer a {
            color: #4B0082;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>' . htmlspecialchars($title) . '</h1>
        </div>
        <div class="email-body">
            <div class="email-content">
                ' . $content . '
            </div>';
            
        if ($buttonText && $buttonUrl) {
            $template .= '
            <div style="text-align: center;">
                <a href="' . htmlspecialchars($buttonUrl) . '" class="email-button">' . htmlspecialchars($buttonText) . '</a>
            </div>';
        }
        
        $template .= '
        </div>
        <div class="email-footer">
            <p>This is an automated message from ' . APP_NAME . '</p>
            <p>If you need assistance, contact us at <a href="mailto:' . SUPPORT_EMAIL . '">' . SUPPORT_EMAIL . '</a></p>
            <p>&copy; ' . date('Y') . ' Industrial training college. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';
        
        return $template;
    }
}

?>
