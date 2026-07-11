# Session Management - Quick Reference Card

## 🚀 Quick Start

### Include in Your File
```php
<?php
require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';
```

This automatically:
- ✅ Starts session safely
- ✅ Validates fingerprint
- ✅ Sets up security

---

## 🔐 Authentication Functions

### Check Admin
```php
if (!isAdminAuthenticated()) {
    header('Location: /');
    exit;
}
```

### Check Student
```php
if (!isStudentAuthenticated()) {
    header('Location: /');
    exit;
}
```

### Get User Info
```php
$user = getAuthenticatedUser();
// Returns: ['username', 'role', 'is_admin', 'is_student']
```

---

## ⏱️ Timeout Protection

### Check Every Page
```php
if (!checkSessionTimeout(30)) {  // 30 minutes
    setFlashMessage('error', 'Session expired');
    header('Location: login.php');
    exit;
}
```

---

## 📝 Flash Messages

### Set Message
```php
setFlashMessage('success', 'Operation successful');
setFlashMessage('error', 'Something went wrong');
setFlashMessage('warning', 'Please be careful');
setFlashMessage('info', 'FYI: This is new');
```

### Get Message (and clear it)
```php
$msg = getFlashMessage('success');
if ($msg) {
    echo $msg;  // Message displays once, then clears
}
```

### Get All Messages
```php
$messages = getAllFlashMessages();
foreach ($messages as $type => $message) {
    echo "[$type] $message\n";
}
```

---

## 🔑 Login Handler

```php
<?php
require_once __DIR__ . '/includes/session_handler.php';

if ($credentials_valid) {
    $_SESSION['index'] = 'admin';
    $_SESSION['user_name'] = $username;
    regenerateSessionId();
    logActivity($username, 'LOGIN', 'Admin login');
    header('Location: dashboard.php');
    exit;
}
```

---

## 🚪 Logout Handler

```php
<?php
require_once __DIR__ . '/includes/session_handler.php';

$user = getSafeSessionValue('user_name');
logActivity($user, 'LOGOUT', 'User logged out');
logoutUser();
header('Location: /');
exit;
```

---

## 📊 Session Testing

### Access Test Dashboard
```
http://localhost/wucportal/admissions/session_test.php?debug=true
```

### Check Activity Log
```
File: logs/activity.log
Contains: All login/logout events
```

---

## 🛡️ Security Features

| Feature | Status | Details |
|---------|--------|---------|
| Session Fingerprinting | ✅ | USER_AGENT + REMOTE_ADDR |
| Timeout Protection | ✅ | 30-minute inactivity |
| ID Regeneration | ✅ | On every login |
| HTTP-Only Cookies | ✅ | JavaScript safe |
| CSRF Protection | ✅ | SameSite=Lax |
| SQL Injection | ✅ | Prepared statements |
| Audit Logging | ✅ | activity.log |

---

## 🐛 Common Issues

### Session Not Working
```php
// ✓ DO THIS
require_once __DIR__ . '/includes/session_handler.php';

// ✗ DON'T DO THIS
session_start();
```

### Flash Message Not Showing
```php
// ✓ Set BEFORE redirect
setFlashMessage('success', 'Done');
header('Location: next_page.php');

// ✓ Get AFTER session init
$msg = getFlashMessage('success');
```

### Session Expired Early
```php
// Make sure you call this on EVERY protected page
if (!checkSessionTimeout(30)) {
    // Handle timeout
}
```

---

## 📚 Documentation Files

| File | Purpose |
|------|---------|
| session_handler.php | Core implementation |
| SESSION_MANAGEMENT_FIX.md | Complete reference |
| SESSION_FIX_SUMMARY.md | Detailed summary |
| SESSION_MIGRATION_GUIDE.md | Migration patterns |
| session_test.php | Interactive test |

---

## 🎯 Typical Page Structure

```php
<?php
// 1. Include session handler
require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';

// 2. Check timeout
if (!checkSessionTimeout(30)) {
    setFlashMessage('error', 'Session expired');
    header('Location: login.php');
    exit;
}

// 3. Check authentication
if (!isAdminAuthenticated()) {
    header('Location: login.php');
    exit;
}

// 4. Your page code here
echo "Hello, " . getSafeSessionValue('user_name');
?>
```

---

## 🔍 Available Functions

```php
// Session
initializeSession()
validateSessionFingerprint()
regenerateSessionId()

// Authentication
isAdminAuthenticated()
isStudentAuthenticated()
isUserAuthenticated()
getAuthenticatedUser()
requireAdminAuth()
requireStudentAuth()

// Timeout
checkSessionTimeout($minutes)

// Messages
setFlashMessage($type, $message)
getFlashMessage($type)
getAllFlashMessages()

// Utility
getSafeSessionValue($key, $default)
logActivity($user, $action, $details)
logoutUser()
```

---

## 📞 Need Help?

1. **View test page**: `session_test.php?debug=true`
2. **Read docs**: See .md files in admissions folder
3. **Check activity**: `logs/activity.log`
4. **Review examples**: `SESSION_MIGRATION_GUIDE.md`

---

## ✅ Pre-Deployment Checklist

- [ ] Run `session_test.php?debug=true` and verify all green
- [ ] Test login with valid credentials
- [ ] Test logout and verify session cleared
- [ ] Wait 30+ minutes and verify timeout redirect
- [ ] Check `logs/activity.log` for entries
- [ ] Test flash messages display once
- [ ] Verify no PHP errors in error_log
- [ ] Review security checklist above

---

**Created**: January 25, 2026  
**Version**: 1.0  
**Status**: Production Ready ✅
