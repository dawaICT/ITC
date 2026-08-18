# PHP Upload Configuration Fix

## Problem
Your PHP server is limiting file uploads to **64 MB**, but your application needs to support **500 MB** video uploads.

## Quick Fix for XAMPP

### Step 1: Locate php.ini
The file is typically at: `C:\xampp\php\php.ini`

### Step 2: Edit Configuration
Open php.ini in a text editor (as Administrator) and update these lines:

```ini
upload_max_filesize = 500M
post_max_size = 550M
max_execution_time = 300
max_input_time = 300
memory_limit = 512M
```

**Note:** `post_max_size` should be slightly larger than `upload_max_filesize`

### Step 3: Restart Apache
1. Open XAMPP Control Panel
2. Click "Stop" for Apache
3. Wait 2 seconds  
4. Click "Start" for Apache

### Step 4: Verify
Visit: `http://localhost/wucportal/update_php_config.php`

This will show your current configuration and confirm if the changes worked.

## Alternative: .htaccess Method

If you can't edit php.ini, create/edit `.htaccess` in your wucportal root:

```apache
php_value upload_max_filesize 500M
php_value post_max_size 550M
php_value max_execution_time 300
php_value max_input_time 300
php_value memory_limit 512M
```

**Note:** This only works if your server allows `.htaccess` overrides.

## Explanation of Settings

| Setting | Purpose | Recommended Value |
|---------|---------|-------------------|
| `upload_max_filesize` | Maximum size of uploaded file | 500M |
| `post_max_size` | Maximum POST data size (must be >= upload limit) | 550M |
| `max_execution_time` | Script execution timeout | 300s (5 minutes) |
| `max_input_time` | Input parsing timeout | 300s |
| `memory_limit` | PHP memory allocation | 512M |

## Troubleshooting

### Changes Not Working?
1. **Wrong php.ini file**: Check which file is loaded using the diagnostic script
2. **Multiple php.ini files**: XAMPP may have php.ini in multiple locations
3. **Apache not restarted**: PHP configuration is loaded on startup
4. **Syntax errors**: Make sure there are no typos or missing semicolons

### Still Having Issues?
1. Check Apache error logs: `C:\xampp\apache\logs\error.log`
2. Check PHP error logs: `C:\xampp\php\logs\php_error_log`
3. Run the diagnostic: `http://localhost/wucportal/update_php_config.php`

## Web Server Limits

If you're using Nginx or have custom Apache config, you may also need:

### Apache (httpd.conf):
```apache
LimitRequestBody 524288000
```

### Nginx (nginx.conf):
```nginx
client_max_body_size 500M;
```

## Testing

After making changes:

1. Visit: `http://localhost/wucportal/admin/elearning/sessions.php`
2. Create a "Recorded Video" session
3. Click "Upload" button
4. The modal should now show: ✅ **Maximum file size: 500 MB**
5. Test with a video file

## Implementation Details

The application now:
- ✅ Detects actual PHP upload limits automatically
- ✅ Shows user-friendly warnings if limits are too low
- ✅ Validates files against actual server limits (not hardcoded 500MB)
- ✅ Provides clear instructions for fixing configuration
- ✅ Logs detailed upload information for debugging

## Need Help?

Run the diagnostic tool at:
`http://localhost/wucportal/update_php_config.php`

It will tell you exactly what needs to be fixed.
