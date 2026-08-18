# Google Meet Integration - Student-Specific Links

## Overview

The system now generates **personalized, non-shareable Google Meet links** for each student. This prevents unauthorized access and ensures only registered, paid-up students can join live sessions.

## How It Works

### Security Flow:

```
1. Admin creates Google Meet session
   ↓
2. Student requests to join
   ↓
3. System verifies:
   - ✅ Student is registered for course
   - ✅ Payment ≥ 50% (or configured %)
   - ✅ No existing valid link
   ↓
4. System generates unique link:
   - SHA-256 hash of session + student + timestamp
   - Link expires after 120 minutes (configurable)
   - Stored with student ID binding
   ↓
5. Student clicks link
   ↓
6. System validates:
   - ✅ Link belongs to THIS student
   - ✅ Link not expired
   - ✅ Link not already used (if single-use)
   - ✅ Link not revoked
   ↓
7. Redirect to actual Google Meet
   ↓
8. Mark link as used + log access
```

## Key Features

### 🔒 **Student-Specific Links**
- Each student gets a unique meeting code
- Code is cryptographically bound to student ID
- Cannot be shared or copied

### ⏱️ **Time-Limited Access**
- Links expire after configured time (default: 120 min)
- Prevents old links from being reused
- Auto-cleanup of expired links

### 🚫 **Single-Use Links**
- Link becomes invalid after first use (configurable)
- Prevents multiple simultaneous accesses
- Can regenerate if student needs to rejoin

### 📊 **Comprehensive Logging**
- Every access attempt logged
- IP address and user agent tracked
- Denial reasons recorded
- Audit trail for security review

## Database Schema

### New Tables:

#### `lms_student_meeting_links`
Stores personalized meeting links for each student.

```sql
CREATE TABLE lms_student_meeting_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,              -- Which session
  student_id VARCHAR(64) NOT NULL,      -- Which student
  meeting_link VARCHAR(1000) NOT NULL,  -- Unique proxy link
  meeting_code VARCHAR(128) NOT NULL,   -- SHA-256 hash code
  generated_at DATETIME NOT NULL,       -- When created
  expires_at DATETIME NOT NULL,         -- Expiration time
  is_used TINYINT(1) DEFAULT 0,         -- Already used?
  used_at DATETIME NULL,                -- When used
  ip_address VARCHAR(45) NULL,          -- User's IP
  user_agent TEXT NULL,                 -- Browser info
  is_revoked TINYINT(1) DEFAULT 0,      -- Manually revoked?
  revoked_at DATETIME NULL,             -- When revoked
  UNIQUE KEY (session_id, student_id)   -- One link per student per session
);
```

#### `lms_meeting_access_attempts`
Logs all meeting access attempts (granted and denied).

```sql
CREATE TABLE lms_meeting_access_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  student_id VARCHAR(64) NOT NULL,
  meeting_link_id INT NULL,
  attempt_time DATETIME NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent TEXT NULL,
  access_granted TINYINT(1) NOT NULL,
  denial_reason VARCHAR(255) NULL
);
```

### Updated Columns in `lms_sessions`:

```sql
ALTER TABLE lms_sessions ADD COLUMN:
  provider ENUM(..., 'google_meet'),     -- New provider option
  google_meet_id VARCHAR(255),           -- Google Meet room ID
  google_calendar_event_id VARCHAR(255), -- Calendar event ID
  link_expiry_minutes INT DEFAULT 120,   -- How long links last
  allow_link_sharing TINYINT(1) DEFAULT 0, -- Allow reuse (always 0 for Google Meet)
  max_link_uses INT DEFAULT 1            -- Max uses per link
```

## Usage Guide

### For Administrators:

#### Creating a Google Meet Session:

1. Go to **Admin → eLearning → Sessions**
2. Click **"Create New Session"**
3. Select:
   - **Session Type**: External
   - **Provider**: Google Meet (Secure)
4. Fill in:
   ```
   Course Code: CS101
   Topic: Introduction to Programming
   Start Time: 2026-01-30 10:00
   Duration: 60 minutes
   Min Payment %: 50
   Link Expiry: 120 minutes
   ☑ Require payment for access
   ```
5. Click **"Create Session"**
6. Notice: "Student Links Auto-Generated" badge

**No manual link entry needed!** The system handles everything.

### For Students:

#### Joining a Google Meet Session:

1. Navigate to **Students → eLearning → Live Sessions**
2. Find the session with 🛡️ **"Secure"** badge
3. Click **"Generate Meeting Link"**
4. System verifies:
   - ✅ You're registered for the course
   - ✅ You've paid ≥50% of fees
   - ✅ You're accessing from valid device
5. Personalized link generated
6. Click **"Join Your Meeting"**
7. Automatically redirected to Google Meet
8. Link expires after 120 minutes

#### What Students See:

```
┌─────────────────────────────────────┐
│  Introduction to Programming        │
│  [🛡️ Secure]                        │
│                                      │
│  📚 CS101                           │
│  📅 Jan 30, 2026 - 10:00 AM        │
│  🔒 Requires 50% payment            │
│                                      │
│  [Generate Meeting Link]            │
│  ℹ️ Personalized link               │
└─────────────────────────────────────┘

After generation:
┌─────────────────────────────────────┐
│  [Join Your Meeting]                │
│  🕐 Expires: 12:00 PM               │
└─────────────────────────────────────┘
```

## Security Features

### 1. **Link Binding**
```php
// Each link is cryptographically bound to student
$meeting_code = hash('sha256', 
    $session_id . '-' . 
    $student_id . '-' . 
    time() . '-' . 
    random_bytes(8)
);
```

### 2. **Validation Chain**
```php
function validateMeetingAccess($db, $meeting_code, $student_id) {
    // 1. Does link exist?
    // 2. Does it belong to THIS student?
    // 3. Is it expired?
    // 4. Is it revoked?
    // 5. Has it been used already?
    // 6. Log the attempt
    // 7. Mark as used
    // 8. Return actual Google Meet link
}
```

### 3. **Access Logging**
Every attempt is logged with:
- Timestamp
- IP address
- User agent (browser)
- Success/failure
- Denial reason if denied

### 4. **Automatic Cleanup**
```php
// Run periodically (cron job)
cleanupExpiredLinks($db);
// Revokes all expired links
// Returns count of cleaned up links
```

## Configuration

### Link Expiry Time

Change default in admin interface or code:

```php
// In sessions.php form
<input type="number" name="link_expiry_minutes" value="120" />

// Options: 30, 60, 120, 180, 240, 480 minutes
```

### Single-Use vs Multi-Use

```sql
-- Allow student to rejoin multiple times
UPDATE lms_sessions 
SET allow_link_sharing = 1, max_link_uses = 5 
WHERE id = 123;

-- Strict single-use (default for Google Meet)
UPDATE lms_sessions 
SET allow_link_sharing = 0, max_link_uses = 1 
WHERE id = 123;
```

### Payment Percentage

```php
// Per session configuration
min_payment_percentage = 50.00  // 50% minimum
min_payment_percentage = 75.00  // 75% minimum
min_payment_percentage = 100.00 // 100% (full payment)
```

## API Functions

### Generate Link
```php
$result = generateStudentMeetingLink($db, $session_id, $student_id, $expiry_minutes);

// Returns:
// [
//   'success' => true,
//   'link' => 'https://portal.com/join?code=abc123...',
//   'code' => 'abc123...',
//   'expires_at' => '2026-01-30 12:00:00'
// ]
```

### Validate Access
```php
$validation = validateMeetingAccess($db, $meeting_code, $student_id);

// Returns:
// [
//   'valid' => true,
//   'session_id' => 123,
//   'reason' => 'Access granted',
//   'actual_link' => 'https://meet.google.com/abc-defg-hij'
// ]
```

### Revoke Link
```php
$success = revokeMeetingLink($db, $session_id, $student_id);
// Immediately invalidates the link
```

### Get Student's Links
```php
$links = getStudentMeetingLinks($db, $student_id);
// Returns all active links for this student
```

## Audit & Reports

### View Access Logs:

```sql
-- Recent access attempts
SELECT 
    s.topic,
    a.student_id,
    a.attempt_time,
    a.access_granted,
    a.denial_reason,
    a.ip_address
FROM lms_meeting_access_attempts a
JOIN lms_sessions s ON a.session_id = s.id
ORDER BY a.attempt_time DESC
LIMIT 50;
```

### Detect Link Sharing:

```sql
-- Multiple IPs using same link (potential sharing)
SELECT 
    l.meeting_code,
    l.student_id,
    COUNT(DISTINCT a.ip_address) as ip_count,
    GROUP_CONCAT(DISTINCT a.ip_address) as ips
FROM lms_student_meeting_links l
JOIN lms_meeting_access_attempts a ON l.id = a.meeting_link_id
GROUP BY l.id
HAVING ip_count > 1;
```

### Student Access History:

```sql
-- What sessions did a student access?
SELECT 
    s.course_code,
    s.topic,
    s.start_time,
    a.attempt_time,
    a.access_granted
FROM lms_meeting_access_attempts a
JOIN lms_sessions s ON a.session_id = s.id
WHERE a.student_id = 'STUDENT_ID'
ORDER BY a.attempt_time DESC;
```

## Troubleshooting

### "Link has expired"

**Cause**: Link older than configured expiry time.

**Solution**: 
1. Return to sessions page
2. Click "Generate Meeting Link" again
3. New link created with fresh expiry

### "Invalid or unauthorized meeting link"

**Cause**: 
- Link copied from another student
- Wrong student trying to use link
- Link code corrupted

**Solution**: 
- Each student must generate their own link
- Cannot share links between students
- Generate fresh link from sessions page

### "Link has already been used"

**Cause**: Single-use link accessed once already.

**Solution**:
1. Contact admin if need to rejoin
2. Admin can enable multi-use: `allow_link_sharing = 1`
3. Or generate new link

### "Access denied - Insufficient payment"

**Cause**: Payment percentage below threshold.

**Solution**:
1. Check payment status on dashboard
2. Make payment to reach 50% or configured %
3. Contact finance office if payment not reflecting

## Best Practices

### For Admins:

✅ **DO:**
- Use Google Meet for sensitive sessions
- Set appropriate expiry times (2 hours typical)
- Monitor access logs weekly
- Review denied access attempts
- Keep payment records updated

❌ **DON'T:**
- Share actual Google Meet links directly
- Set expiry too long (security risk)
- Ignore access logs
- Disable payment checking without reason

### For Students:

✅ **DO:**
- Generate your own unique link
- Join meetings on time
- Ensure payment is up to date
- Use one device per session

❌ **DON'T:**
- Share your meeting link with others
- Screenshot or copy link for later
- Try to reuse expired links
- Access from multiple devices simultaneously

## Integration with Google Meet API

### Phase 1 (Current): Proxy Links
- System generates unique codes
- Redirects through internal validation
- Uses configured Google Meet base link

### Phase 2 (Future): Full API Integration
```php
// Using Google Meet API
require_once 'vendor/autoload.php';
use Google\Service\Calendar;

function createGoogleMeetSession($session_data) {
    $client = new Google_Client();
    $client->setAuthConfig('credentials.json');
    $service = new Calendar($client);
    
    $event = new Google_Service_Calendar_Event([
        'summary' => $session_data['topic'],
        'conferenceData' => [
            'createRequest' => [
                'requestId' => uniqid()
            ]
        ]
    ]);
    
    $event = $service->events->insert('primary', $event, [
        'conferenceDataVersion' => 1
    ]);
    
    return $event->hangoutLink;
}
```

## Performance Optimization

### Indexes:
```sql
-- Fast link lookups
INDEX idx_meeting_code (meeting_code)

-- Fast expiry checks
INDEX idx_expiry (expires_at)

-- Fast student lookups
INDEX idx_student_links (student_id, session_id)
```

### Caching:
```php
// Cache validation results briefly
$cache_key = "meeting_access_{$meeting_code}_{$student_id}";
$cached = apcu_fetch($cache_key);
if ($cached !== false) {
    return $cached;
}
```

### Cleanup Cron:
```bash
# Add to crontab: Run every hour
0 * * * * php /path/to/cleanup_expired_links.php
```

## Migration from External Links

### Before:
```
Session Type: External
Provider: Zoom
Join URL: https://zoom.us/j/123456789 (SHARED LINK)
```

### After:
```
Session Type: External
Provider: Google Meet (Secure)
[No manual URL - system generates per student]
```

## Summary

🎯 **Problem Solved**: Links cannot be shared or copied between students

🔐 **Security**: 
- Cryptographic binding to student ID
- Time-based expiration
- Single-use enforcement
- Comprehensive audit logs

✅ **Benefits**:
- Prevents unauthorized access
- Ensures only paid students join
- Tracks all access attempts
- Scalable and maintainable

---

**Installation**: Run `db/update_sessions_for_google_meet.sql` to apply schema changes.

**Testing**: Create a Google Meet session, generate student link, verify validation works.

**Support**: Check access logs for issues, review documentation for common problems.
