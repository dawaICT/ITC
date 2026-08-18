# WUC PORTAL - EMAIL CONFIGURATION GUIDE

This guide will help you configure email sending for password reset functionality.

## Quick Setup (Recommended for Local Development)

### Method 1: Using Gmail SMTP (Easiest)

1. **Enable App Passwords in Gmail:**
   - Go to your Google Account settings
   - Security → 2-Step Verification (enable if not already)
   - Security → App Passwords
   - Generate a new app password for "Mail"
   - Copy the 16-character password

2. **Update `includes/email_config.php`:**
   ```php
   define('EMAIL_METHOD', 'smtp');
   define('SMTP_HOST', 'smtp.gmail.com');
   define('SMTP_PORT', 587);
   define('SMTP_ENCRYPTION', 'tls');
   define('SMTP_USERNAME', 'your-email@gmail.com');
   define('SMTP_PASSWORD', 'your-16-char-app-password');
   define('SMTP_FROM_EMAIL', 'noreply@wucportal.zm');
   define('SMTP_FROM_NAME', 'WUC Portal');
   ```

3. **Install PHPMailer (Required for SMTP):**
   ```bash
   composer require phpmailer/phpmailer
   ```
   OR download manually from https://github.com/PHPMailer/PHPMailer

---

### Method 2: Using XAMPP Sendmail (Local Testing)

1. **Configure `C:\xampp\sendmail\sendmail.ini`:**
   ```ini
   [sendmail]
   smtp_server=smtp.gmail.com
   smtp_port=587
   smtp_ssl=auto
   auth_username=your-email@gmail.com
   auth_password=your-16-char-app-password
   force_sender=noreply@wucportal.zm
   ```

2. **Configure `C:\xampp\php\php.ini`:**
   ```ini
   [mail function]
   SMTP=localhost
   smtp_port=25
   sendmail_path="C:\xampp\sendmail\sendmail.exe -t"
   ```

3. **Update `includes/email_config.php`:**
   ```php
   define('EMAIL_METHOD', 'sendmail');
   define('SENDMAIL_PATH', 'C:\\xampp\\sendmail\\sendmail.exe -t');
   ```

4. **Restart Apache** in XAMPP Control Panel

---

### Method 3: Using Office365/Outlook SMTP

1. **Update `includes/email_config.php`:**
   ```php
   define('EMAIL_METHOD', 'smtp');
   define('SMTP_HOST', 'smtp.office365.com');
   define('SMTP_PORT', 587);
   define('SMTP_ENCRYPTION', 'tls');
   define('SMTP_USERNAME', 'your-email@outlook.com');
   define('SMTP_PASSWORD', 'your-password');
   define('SMTP_FROM_EMAIL', 'your-email@outlook.com');
   define('SMTP_FROM_NAME', 'WUC Portal');
   ```

---

## Testing Email Functionality

### 1. Check Logs

Email activity is logged to:
- `logs/email.log` - Email sending attempts
- `logs/email_preview.txt` - Email content if mail() fails
- `logs/error.log` - PHP errors

### 2. Test Password Reset

1. Go to: `http://localhost/wucportal/staff_forgot_password.php`
2. Enter a valid staff email address
3. Check logs for errors:
   - Success: "Email sent to [email] via SMTP"
   - Failure: Check the error message in logs

### 3. Common Issues

**"PHPMailer library not found"**
- Solution: Install PHPMailer via composer or manually
- The system will fall back to mail() function

**"mail() function failed"**
- Solution: Configure sendmail (Method 2) or use SMTP (Method 1)
- Check `logs/email_preview.txt` for the email content

**"SMTP Error: Could not authenticate"**
- Solution: Check username/password in `email_config.php`
- For Gmail, ensure you're using App Password, not regular password

**"SMTP connect() failed"**
- Solution: Check SMTP_HOST and SMTP_PORT settings
- Ensure your firewall allows outbound SMTP connections

---

## Production Deployment

### For Production Server:

1. **Use SMTP** (most reliable):
   ```php
   define('EMAIL_METHOD', 'smtp');
   ```

2. **Disable Debug Mode**:
   ```php
   define('EMAIL_DEBUG', false);
   ```

3. **Use Environment Variables** (recommended):
   - Store sensitive credentials in environment variables
   - Update `email_config.php` to read from `$_ENV`

4. **Update Application URL**:
   ```php
   define('APP_URL', 'https://portal.wuc.ac.zm');
   ```

5. **Use SSL/TLS**:
   ```php
   define('SMTP_ENCRYPTION', 'tls'); // or 'ssl'
   ```

---

## Security Best Practices

1. **Never commit `email_config.php` with real credentials**
   - Add to `.gitignore`
   - Use environment variables in production

2. **Use App-Specific Passwords**
   - Gmail: Use App Passwords, not account password
   - Office365: Consider using App Registration

3. **Rate Limiting**
   - Consider adding rate limiting to prevent abuse
   - Limit password reset attempts per IP/email

4. **Monitor Logs**
   - Regularly check email logs for suspicious activity
   - Set up alerts for failed email attempts

---

## Troubleshooting Commands

```bash
# Check if sendmail is configured
php -i | findstr sendmail

# Test PHP mail() function
php -r "mail('test@example.com', 'Test', 'Test message');"

# Check XAMPP sendmail logs
type C:\xampp\sendmail\sendmail.log

# View recent email logs
type logs\email.log

# View email previews (if mail() failed)
type logs\email_preview.txt
```

---

## Support

If you encounter issues:
1. Check the logs first (`logs/email.log` and `logs/error.log`)
2. Verify SMTP credentials
3. Test with a simple email script
4. Contact your hosting provider for SMTP server details

For development help, refer to:
- PHPMailer documentation: https://github.com/PHPMailer/PHPMailer
- XAMPP sendmail guide: https://blog.roler.net/how-to-configure-sendmail-on-xampp/
