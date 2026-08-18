# Registration System Redesign - Installation Guide

## Overview
This document provides step-by-step instructions for deploying the redesigned registration system for WUC Portal.

## What's New

### 1. **Enhanced Database Schema**
- Normalized registration tables with proper foreign keys
- Academic sessions management
- Comprehensive audit logging
- JSON support for complex data structures
- Database triggers for automatic calculations
- Stored procedures for common operations
- Optimized indexes for better performance

### 2. **Modern Database Connection Layer**
- Singleton pattern PDO connection
- Transaction support with nested transactions
- Prepared statements for security
- Query builder methods
- Connection pooling ready

### 3. **Service-Oriented Architecture**
- `RegistrationService` class handles all business logic
- Separation of concerns
- Reusable methods across the application
- Comprehensive validation

### 4. **RESTful API Endpoints**
- JSON-based API for AJAX operations
- CSRF protection
- Authentication middleware
- Clean error handling

### 5. **Enhanced UI**
- Modern, responsive design
- Real-time status updates
- Better user experience
- Accessibility improvements

## Installation Steps

### Step 1: Backup Current System

Before making any changes, backup your current database and files:

```bash
# Backup database
cd C:\xampp\mysql\bin
.\mysqldump.exe -u root wucportal > C:\backups\wucportal_backup_%date%.sql

# Backup files
xcopy C:\xampp\htdocs\wucportal C:\backups\wucportal_files\ /E /I
```

### Step 2: Apply Database Schema

Run the new schema to create/update tables:

```bash
# From the project root
cd C:\xampp\htdocs\wucportal
php -f db/apply_registration_schema.php
```

Or manually via MySQL:

```bash
cd C:\xampp\mysql\bin
.\mysql.exe -u root wucportal < C:\xampp\htdocs\wucportal\db\registration_schema.sql
```

### Step 3: Verify Database Changes

Check that new tables were created:

```sql
USE wucportal;

-- Check new tables
SHOW TABLES LIKE '%registration%';
SHOW TABLES LIKE 'academic_sessions';

-- Verify structure
DESCRIBE semester_registration;
DESCRIBE course_registration;
DESCRIBE academic_sessions;
DESCRIBE registration_audit_log;
DESCRIBE registration_fees;

-- Check views
SHOW FULL TABLES WHERE TABLE_TYPE LIKE 'VIEW';

-- Check stored procedures
SHOW PROCEDURE STATUS WHERE Db = 'wucportal';

-- Check triggers
SHOW TRIGGERS;
```

### Step 4: Migrate Existing Data (Optional)

If you have existing registration data, migrate it:

```php
<?php
// Run this script once to migrate old data
require_once 'includes/DatabaseConnection.php';

$db = DatabaseConnection::getInstance();

// Create default academic session if none exists
$sessionExists = $db->fetchOne("SELECT id FROM academic_sessions LIMIT 1");

if (!$sessionExists) {
    $sessionId = $db->insert('academic_sessions', [
        'session_name' => '1',  // Year of study (1, 2, 3, or 4)
        'start_date' => '2025-09-01',
        'end_date' => '2026-06-30',
        'is_active' => true,
        'is_registration_open' => true,
        'registration_start_date' => '2025-08-01',
        'registration_end_date' => '2025-09-15'
    ]);
    echo "Created default academic session with ID: $sessionId\n";
}

// Migrate old semester_registration data if old table exists
$oldTableExists = $db->tableExists('semester_registration_old');
if ($oldTableExists) {
    // Add migration logic here based on old schema
    echo "Migrating old registration data...\n";
    // ... migration code ...
}

echo "Migration complete!\n";
?>
```

### Step 5: Test API Endpoints

Test the new API to ensure it's working:

```bash
# Test from command line or browser
curl http://localhost/wucportal/api/registration.php

# Check registration status
curl http://localhost/wucportal/api/registration.php/check-status

# Get current session
curl http://localhost/wucportal/api/registration.php/session
```

### Step 6: Update Existing Pages

Gradually update existing registration pages to use the new system:

**Option A: Soft Launch (Recommended)**
- Keep old system running
- Deploy new system as `/students/registration_redesigned.php`
- Test thoroughly with limited users
- Gradually migrate features
- Switch DNS/routes when ready

**Option B: Hard Launch**
- Backup everything
- Replace old files
- Update all links
- Monitor for issues

### Step 7: Update Configuration

Update any configuration files that reference old database connections:

```php
// Replace old mysqli connections with new DatabaseConnection class
// OLD:
// require_once 'db/connect.php';
// $result = $db->query("SELECT ...");

// NEW:
require_once 'includes/DatabaseConnection.php';
$db = DatabaseConnection::getInstance();
$result = $db->fetchAll("SELECT ...");
```

## File Structure

```
wucportal/
├── api/
│   └── registration.php          # New RESTful API endpoints
├── db/
│   └── registration_schema.sql   # New database schema
├── includes/
│   ├── DatabaseConnection.php    # New DB connection class
│   └── RegistrationService.php   # Business logic service
└── students/
    ├── registration.php          # Original registration page
    └── registration_redesigned.php # New redesigned page
```

## Configuration

### Database Connection Settings

Edit `includes/DatabaseConnection.php` if your database credentials differ:

```php
private $config = [
    'host' => '127.0.0.1',        // Database host
    'port' => 3306,                // Database port
    'database' => 'wucportal',     // Database name
    'username' => 'root',          // Database username
    'password' => '',              // Database password
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
```

### API Base URL

Update API base URL in frontend JavaScript if needed:

```javascript
// In registration_redesigned.php
const API_BASE_URL = '/wucportal/api/registration.php';
```

## Testing Checklist

- [ ] Database schema applied successfully
- [ ] New tables created (academic_sessions, semester_registration, course_registration, etc.)
- [ ] Views created successfully
- [ ] Stored procedures created
- [ ] Triggers created
- [ ] API endpoints respond correctly
- [ ] CSRF token generation works
- [ ] Registration status check works
- [ ] Create semester registration works
- [ ] Course registration works
- [ ] Submit registration works
- [ ] UI loads correctly
- [ ] JavaScript API calls work
- [ ] Error handling works properly

## Rollback Plan

If issues occur, rollback to the previous system:

### Database Rollback
```sql
-- Drop new tables
DROP TABLE IF EXISTS registration_fees;
DROP TABLE IF EXISTS registration_audit_log;
DROP TABLE IF EXISTS course_registration;
DROP TABLE IF EXISTS semester_registration;
DROP TABLE IF EXISTS academic_sessions;
DROP TABLE IF EXISTS student_id_pool;

-- Drop views
DROP VIEW IF EXISTS v_registration_details;
DROP VIEW IF EXISTS v_course_registration_details;
DROP VIEW IF EXISTS v_student_registration_summary;

-- Drop procedures
DROP PROCEDURE IF EXISTS sp_generate_registration_number;
DROP PROCEDURE IF EXISTS sp_complete_semester_registration;

-- Restore from backup
SOURCE C:\backups\wucportal_backup_YYYYMMDD.sql;
```

### File Rollback
```bash
# Restore files from backup
xcopy C:\backups\wucportal_files\* C:\xampp\htdocs\wucportal\ /E /Y
```

## Common Issues & Solutions

### Issue 1: "Table already exists" Error
**Solution:** The schema is designed to be idempotent. Existing tables won't be dropped. If you need to rebuild, manually drop tables first.

### Issue 2: API Returns 404
**Solution:** Check your Apache `.htaccess` or ensure the API file is accessible at the correct path.

### Issue 3: CSRF Token Validation Fails
**Solution:** Ensure sessions are started properly and the token is being sent with POST requests.

### Issue 4: Foreign Key Constraint Errors
**Solution:** Ensure referenced tables (students, programs, courses) exist and have the correct structure.

## Performance Optimization

### Enable Query Caching
```sql
-- In my.cnf or my.ini
query_cache_type = 1
query_cache_size = 256M
```

### Enable Connection Pooling
```php
// In DatabaseConnection.php
private $config = [
    // ...
    'options' => [
        // ...
        PDO::ATTR_PERSISTENT => true, // Enable persistent connections
    ]
];
```

### Add Additional Indexes
```sql
-- Based on your query patterns, add more indexes
CREATE INDEX idx_student_session ON semester_registration(student_id, academic_session_id);
CREATE INDEX idx_course_student ON course_registration(student_id, course_code);
```

## Monitoring

### Enable Query Logging
```sql
-- Enable slow query log
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 2; -- Log queries taking > 2 seconds
```

### Monitor API Performance
Add logging to API endpoints:
```php
// In api/registration.php
$startTime = microtime(true);
// ... process request ...
$duration = microtime(true) - $startTime;
error_log("API Request: $action, Duration: {$duration}s");
```

## Security Recommendations

1. **Change Default Database Credentials**
   ```sql
   CREATE USER 'wuc_app'@'localhost' IDENTIFIED BY 'strong_password_here';
   GRANT SELECT, INSERT, UPDATE, DELETE ON wucportal.* TO 'wuc_app'@'localhost';
   FLUSH PRIVILEGES;
   ```

2. **Enable HTTPS**
   - Use SSL certificate for production
   - Force HTTPS redirects

3. **Implement Rate Limiting**
   - Limit API requests per user
   - Prevent brute force attacks

4. **Regular Backups**
   - Automate daily database backups
   - Test restore procedures

## Support

For issues or questions:
- Check error logs: `C:\xampp\apache\logs\error.log`
- Check database logs
- Review documentation
- Contact system administrator

## Next Steps

After successful deployment:
1. Monitor system performance
2. Collect user feedback
3. Gradually migrate other modules to use new architecture
4. Implement additional features (email notifications, SMS alerts, etc.)
5. Add comprehensive unit tests
6. Set up continuous integration/deployment

## Conclusion

The redesigned registration system provides a solid foundation for managing student registrations with improved performance, security, and maintainability. Follow this guide carefully and test thoroughly before deploying to production.
