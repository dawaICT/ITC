# Live Session Video System - Quick Start Guide

## What's New?

The WUC Portal now supports **internal video hosting** for live sessions, with automatic payment verification. Students must have paid at least 50% of their fees to access session videos.

## 🚀 Quick Setup (5 minutes)

### 1. Run Database Migration

Open phpMyAdmin or MySQL command line:

```sql
-- Copy and paste contents from:
-- db/update_sessions_for_internal_video.sql
```

### 2. Run Setup Script

In your browser, visit:
```
http://localhost/wucportal/setup_live_sessions.php
```

This will:
- ✅ Check database tables
- ✅ Create upload directories
- ✅ Verify PHP settings
- ✅ Test all components

### 3. Adjust PHP Settings (if needed)

If setup script shows warnings, edit `php.ini`:

```ini
upload_max_filesize = 500M
post_max_size = 500M
max_execution_time = 600
memory_limit = 512M
```

Restart Apache after changes.

---

## 📹 Creating a Session (Admins)

### Option A: Internal Video

1. Go to **Admin Panel → eLearning → Sessions**
2. Click "Create New Session"
3. Select **"Internal Video (Upload)"**
4. Fill in:
   ```
   Course Code: CS101
   Topic: Introduction to Programming
   Start Time: [Select date/time]
   Min Payment %: 50
   ☑ Require payment for access
   ```
5. Click **"Create Session"**
6. In the sessions list, click **"Upload Video"**
7. Select video file (MP4, WebM recommended)
8. Wait for upload to complete
9. Status changes to **"Available"** ✅

### Option B: External Link (Zoom/Teams)

1. Select **"External (Zoom/Teams)"**
2. Fill in meeting details
3. Paste Join URL from Zoom/Teams
4. Click **"Create Session"**

---

## 🎓 Student Access Flow

### Step 1: Check Payment Status

Students see their payment percentage:

```
┌─────────────────────────────────┐
│ Your Payment Status             │
├─────────────────────────────────┤
│    [✅ 75% Paid]                │
│                                  │
│  Paid: K 3,750.00               │
│  Due:  K 5,000.00               │
└─────────────────────────────────┘
```

### Step 2: Browse Sessions

Sessions display with access status:

```
┌──────────────────────────────────┐
│  Introduction to Programming     │
│  [Internal Video]                │
│                                   │
│  📚 CS101                        │
│  📅 Jan 29, 2026 - 10:00 AM     │
│  🔒 Requires 50% payment         │
│                                   │
│  [Watch Now] ← If eligible       │
│  [Access Denied] ← If not paid   │
└──────────────────────────────────┘
```

### Step 3: Watch Video

If eligible, student clicks "Watch Now":

```
┌─────────────────────────────────────────┐
│  ▶️ Video Player                        │
│  [==================>-------] 65%       │
│                                          │
│  Introduction to Programming            │
│  CS101 • 60 minutes                     │
│                                          │
│  [Your access: 75% paid ✅]             │
└─────────────────────────────────────────┘
```

### If Access Denied:

```
┌─────────────────────────────────┐
│  🔒 Access Denied                │
├─────────────────────────────────┤
│  Insufficient payment            │
│  (25% paid, 50% required)        │
│                                   │
│  [Back to Sessions]              │
│  [Make Payment] ← Direct link    │
└─────────────────────────────────┘
```

---

## 📊 Payment Logic

### How It Works:

```
Total Invoice Amount:     K 5,000.00
Student Has Paid:         K 2,500.00
─────────────────────────────────────
Payment Percentage:       50%
─────────────────────────────────────
Session Requires:         50%
Result:                   ✅ GRANTED
```

### Access Rules:

| Payment % | Status | Can Access? |
|-----------|--------|-------------|
| 0-49%     | ❌ Insufficient | No |
| 50-99%    | ⚠️ Partial | **Yes** |
| 100%      | ✅ Full | **Yes** |

### Special Cases:

- **Not Registered**: Always denied (regardless of payment)
- **No Invoice**: If no invoice exists, access granted
- **Payment Not Required**: Admin can disable payment check per session

---

## 🔧 Troubleshooting

### "Video won't upload"

**Check:**
1. File size < 500MB
2. Format: MP4, WebM, OGG, AVI, or MOV
3. PHP upload limits in `php.ini`
4. Disk space available
5. Directory permissions (755)

**Quick Fix:**
```bash
# Check available space
df -h

# Fix permissions (Linux)
chmod 755 uploads/live_sessions
```

### "Student can't access despite payment"

**Verify:**
1. Payment recorded in `student_payments` table
2. Invoice exists in `invoices` table
3. Academic year & semester match
4. Student registered for course

**Check Logs:**
```sql
SELECT * FROM lms_session_access_log 
WHERE student_id = 'STUDENT_ID' 
ORDER BY access_time DESC LIMIT 10;
```

### "Video won't play"

**Try:**
1. Different browser (Chrome recommended)
2. Convert video to MP4:
   ```bash
   ffmpeg -i input.avi -c:v libx264 -c:a aac output.mp4
   ```
3. Check file exists on disk
4. Verify path in database

---

## 📈 Analytics & Reports

### View Access Logs:

```sql
-- Who accessed what
SELECT 
  l.student_id,
  s.topic,
  l.access_granted,
  l.payment_percentage,
  l.access_time
FROM lms_session_access_log l
JOIN lms_sessions s ON l.session_id = s.id
ORDER BY l.access_time DESC;
```

### Popular Sessions:

```sql
-- Most watched sessions
SELECT 
  s.topic,
  s.course_code,
  COUNT(l.id) as views
FROM lms_sessions s
LEFT JOIN lms_session_access_log l ON s.id = l.session_id
WHERE l.access_granted = 1
GROUP BY s.id
ORDER BY views DESC;
```

### Payment Impact:

```sql
-- Access denied due to payment
SELECT 
  COUNT(*) as denied_count,
  AVG(payment_percentage) as avg_payment
FROM lms_session_access_log
WHERE access_granted = 0
AND denial_reason LIKE '%payment%';
```

---

## 🎯 Best Practices

### For Administrators:

1. **Pre-process videos**: Convert to MP4 before uploading
2. **Set realistic dates**: Schedule sessions in advance
3. **Monitor storage**: Clean up old videos periodically
4. **Test uploads**: Try small file first
5. **Document changes**: Note session topics clearly

### For Students:

1. **Check payment status** before exam period
2. **Use good internet connection** for video streaming
3. **Watch during off-peak hours** for better speed
4. **Take notes** while watching
5. **Contact finance** if payment status is incorrect

### For IT Staff:

1. **Backup database** before migrations
2. **Monitor disk space** regularly
3. **Review access logs** weekly
4. **Update PHP** to latest stable version
5. **Test video playback** on multiple browsers

---

## 🔐 Security Features

✅ **Authentication**: Session-based login required  
✅ **Authorization**: Course registration verified  
✅ **Payment Check**: Real-time verification  
✅ **Access Logging**: All attempts recorded  
✅ **Download Prevention**: Video controls protected  
✅ **SQL Injection**: Prepared statements used  
✅ **XSS Protection**: All output escaped  

---

## 📞 Support

**Common Issues:**
- Payment not reflecting? → Contact Finance Office
- Can't upload video? → Check file format & size
- Video won't play? → Try Chrome or Firefox
- Access denied error? → Verify registration

**Technical Support:**
- Systems Administrator: ext. 1234
- Finance Office: ext. 5678
- IT Helpdesk: helpdesk@wuc.edu.zm

---

## 🎓 Training Resources

**For Admins:**
- Video: "Creating Internal Sessions" (10 min)
- PDF: "Session Management Guide"
- Live Demo: Every Monday, 2 PM

**For Students:**
- Video: "Accessing Live Sessions" (5 min)
- FAQ: students.wuc.edu.zm/elearning-faq
- Help Desk: Available 8 AM - 5 PM

---

## ✅ System Status

After setup, you should see:

```
DATABASE:           ✅ Connected
TABLES:             ✅ All present
COLUMNS:            ✅ Schema updated
UPLOAD DIRECTORY:   ✅ Created & writable
PHP SETTINGS:       ✅ Adequate
PAYMENT FUNCTION:   ✅ Working
ADMIN INTERFACE:    ✅ Ready
STUDENT INTERFACE:  ✅ Ready
```

**System Ready!** 🎉

Start creating internal sessions and uploading videos.

---

*Last updated: January 2026*  
*WUC Portal - Live Session Video System v1.0*
