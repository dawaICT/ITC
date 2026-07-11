<?php
/**
 * EMAIL CONFIGURATION
 * 
 * Configure your email settings here
 * For production, use SMTP with authentication
 */

// Email Service Configuration
define('EMAIL_METHOD', 'smtp'); // Options: 'smtp', 'sendmail', 'mail'

// SMTP Configuration (Recommended for production)
define('SMTP_HOST', 'smtp.gmail.com'); // e.g., smtp.gmail.com, smtp.office365.com
define('SMTP_PORT', 587); // 587 for TLS, 465 for SSL
define('SMTP_ENCRYPTION', 'tls'); // 'tls' or 'ssl'
define('SMTP_USERNAME', 'your-email@gmail.com'); // Your email address
define('SMTP_PASSWORD', 'your-app-password'); // Your email password or app-specific password
define('SMTP_FROM_EMAIL', 'noreply@wucportal.zm'); // From email address
define('SMTP_FROM_NAME', 'ITC Portal'); // From name

// Alternative: For testing with local mail server (XAMPP sendmail)
define('SENDMAIL_PATH', 'C:\\xampp\\sendmail\\sendmail.exe -t'); // Path to sendmail

// Email Settings
define('EMAIL_DEBUG', true); // Set to false in production
define('EMAIL_LOG_FILE', __DIR__ . '/../logs/email.log'); // Email log file path

// Application URLs
define('APP_NAME', 'Industrial training college Portal');
define('APP_URL', 'http://localhost/wucportal'); // Update for production
define('SUPPORT_EMAIL', 'support@wucportal.zm');

?>
