<?php
/**
 * EMAIL CONFIGURATION TEST SCRIPT
 * 
 * This script tests your email configuration
 * Access it at: http://localhost/wucportal/test_email.php
 */

// Prevent access in production
if ($_SERVER['SERVER_NAME'] !== 'localhost' && $_SERVER['SERVER_NAME'] !== '127.0.0.1') {
    die('This script can only be run on localhost');
}

require_once __DIR__ . '/includes/email_helper.php';
require_once __DIR__ . '/includes/email_config.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Configuration Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .test-card {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .status-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        .status-success { background-color: #d4edda; color: #155724; }
        .status-error { background-color: #f8d7da; color: #721c24; }
        .status-warning { background-color: #fff3cd; color: #856404; }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="test-card">
            <h1 class="mb-4">📧 Email Configuration Test</h1>
            
            <h3 class="mt-4 mb-3">Current Configuration</h3>
            <table class="table table-striped">
                <tr>
                    <td><strong>Email Method:</strong></td>
                    <td><?= EMAIL_METHOD ?></td>
                </tr>
                <?php if (EMAIL_METHOD === 'smtp'): ?>
                <tr>
                    <td><strong>SMTP Host:</strong></td>
                    <td><?= SMTP_HOST ?></td>
                </tr>
                <tr>
                    <td><strong>SMTP Port:</strong></td>
                    <td><?= SMTP_PORT ?></td>
                </tr>
                <tr>
                    <td><strong>SMTP Encryption:</strong></td>
                    <td><?= SMTP_ENCRYPTION ?></td>
                </tr>
                <tr>
                    <td><strong>SMTP Username:</strong></td>
                    <td><?= SMTP_USERNAME ?></td>
                </tr>
                <tr>
                    <td><strong>From Email:</strong></td>
                    <td><?= SMTP_FROM_EMAIL ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td><strong>Debug Mode:</strong></td>
                    <td><?= EMAIL_DEBUG ? 'Enabled' : 'Disabled' ?></td>
                </tr>
            </table>

            <h3 class="mt-4 mb-3">PHPMailer Status</h3>
            <?php
            $phpmailerStatus = class_exists('PHPMailer\PHPMailer\PHPMailer');
            ?>
            <p>
                <?php if ($phpmailerStatus): ?>
                    <span class="status-badge status-success">✓ PHPMailer is installed</span>
                <?php else: ?>
                    <span class="status-badge status-warning">⚠ PHPMailer not found (falling back to mail())</span>
                <?php endif; ?>
            </p>
            
            <?php if (!$phpmailerStatus && EMAIL_METHOD === 'smtp'): ?>
                <div class="alert alert-warning">
                    <strong>Note:</strong> SMTP method requires PHPMailer. Install it with:
                    <pre>composer require phpmailer/phpmailer</pre>
                    Or change EMAIL_METHOD to 'mail' or 'sendmail' in <code>includes/email_config.php</code>
                </div>
            <?php endif; ?>

            <h3 class="mt-4 mb-3">Send Test Email</h3>
            
            <?php if (isset($_POST['send_test'])): ?>
                <?php
                $testEmail = $_POST['test_email'] ?? '';
                if (filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                    $emailHelper = new EmailHelper();
                    
                    $testContent = '
                        <p>Congratulations! Your email configuration is working correctly.</p>
                        <p>This is a test email sent from the ITC Portal email system.</p>
                        <p><strong>Configuration Details:</strong></p>
                        <ul>
                            <li>Method: ' . EMAIL_METHOD . '</li>
                            <li>Time: ' . date('Y-m-d H:i:s') . '</li>
                        </ul>
                        <p>If you can read this email, your email settings are configured properly!</p>
                    ';
                    
                    $htmlMessage = $emailHelper->createHTMLTemplate(
                        'Email Test Successful',
                        $testContent,
                        'View Portal',
                        APP_URL
                    );
                    
                    $result = $emailHelper->send(
                        $testEmail,
                        'ITC Portal - Email Test',
                        $htmlMessage
                    );
                    
                    if ($result): ?>
                        <div class="alert alert-success">
                            <strong>✓ Success!</strong> Test email sent to <?= htmlspecialchars($testEmail) ?>
                            <br>Check your inbox (and spam folder).
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger">
                            <strong>✗ Error:</strong> <?= htmlspecialchars($emailHelper->getLastError()) ?>
                            <br><br>
                            <strong>Troubleshooting:</strong>
                            <ul>
                                <li>Check <code>logs/email.log</code> for detailed error messages</li>
                                <li>If using SMTP, verify credentials in <code>includes/email_config.php</code></li>
                                <li>If using Gmail, ensure you're using an App Password</li>
                                <li>Check <code>logs/email_preview.txt</code> for the email content</li>
                            </ul>
                        </div>
                    <?php endif;
                } else {
                    echo '<div class="alert alert-danger">Invalid email address</div>';
                }
                ?>
            <?php endif; ?>
            
            <form method="POST">
                <div class="mb-3">
                    <label for="test_email" class="form-label">Enter your email address:</label>
                    <input type="email" class="form-control" id="test_email" name="test_email" 
                           placeholder="your.email@example.com" required>
                    <small class="text-muted">A test email will be sent to this address</small>
                </div>
                <button type="submit" name="send_test" class="btn btn-primary">
                    <i class="bi bi-envelope"></i> Send Test Email
                </button>
            </form>

            <h3 class="mt-5 mb-3">Log Files</h3>
            <div class="row">
                <div class="col-md-6">
                    <h5>Email Log</h5>
                    <?php
                    $emailLogPath = __DIR__ . '/logs/email.log';
                    if (file_exists($emailLogPath)) {
                        $emailLog = file_get_contents($emailLogPath);
                        $lines = explode("\n", $emailLog);
                        $recentLines = array_slice($lines, -20);
                        echo '<pre style="max-height: 300px; overflow-y: auto;">' . htmlspecialchars(implode("\n", $recentLines)) . '</pre>';
                    } else {
                        echo '<p class="text-muted">No log file yet. Try sending an email first.</p>';
                    }
                    ?>
                </div>
                <div class="col-md-6">
                    <h5>Error Log</h5>
                    <?php
                    $errorLogPath = __DIR__ . '/logs/error.log';
                    if (file_exists($errorLogPath)) {
                        $errorLog = file_get_contents($errorLogPath);
                        $lines = explode("\n", $errorLog);
                        $recentLines = array_slice($lines, -20);
                        echo '<pre style="max-height: 300px; overflow-y: auto;">' . htmlspecialchars(implode("\n", $recentLines)) . '</pre>';
                    } else {
                        echo '<p class="text-muted">No errors logged</p>';
                    }
                    ?>
                </div>
            </div>

            <div class="mt-4">
                <a href="EMAIL_SETUP_GUIDE.md" class="btn btn-outline-secondary" target="_blank">
                    📖 View Setup Guide
                </a>
                <a href="staff_forgot_password.php" class="btn btn-outline-primary">
                    Test Password Reset
                </a>
            </div>
        </div>
    </div>
</body>
</html>
