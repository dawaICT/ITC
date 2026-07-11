# Internal Live Session Video System - Implementation Guide

## Overview
This implementation replaces external video links (Zoom/Teams) with an internal video hosting system that enforces payment-based access control. Only students who are registered and have paid at least 50% of their fees can access live session videos.

## Key Features

### 1. **Dual Session Types**
   - **Internal Videos**: Upload and host videos directly in the system
   - **External Links**: Continue supporting Zoom/Teams for live sessions

### 2. **Payment-Based Access Control**
   - Configurable minimum payment percentage (default: 50%)
   - Real-time verification against student payment records
   - Access logging for audit trails

### 3. **Video Management**
   - Secure video file storage
   - Support for multiple video formats (MP4, WebM, OGG, AVI, MOV)
   - File size tracking and status management
   - Thumbnail support for better UI

### 4. **Student Experience**
   - Clean, card-based session listing
   - Real-time payment status display
   - Secure video player with download protection
   - Clear denial reasons when access is restricted

## Installation Steps

### Step 1: Apply Database Schema Updates

Run the SQL migration script to add necessary columns and tables:

```bash
# From your MySQL client or phpMyAdmin
mysql -u root -p wucportal < db/update_sessions_for_internal_video.sql
```

Or manually execute the SQL in phpMyAdmin.

### Step 2: Create Upload Directory

Create the directory for storing video files:

```bash
mkdir -p uploads/live_sessions
chmod 755 uploads/live_sessions
```

On Windows (XAMPP):
```cmd
mkdir uploads\live_sessions
```

### Step 3: Configure PHP Upload Limits

Edit your `php.ini` file (usually in `C:\xampp\php\php.ini`):

```ini
upload_max_filesize = 500M
post_max_size = 500M
max_execution_time = 600
memory_limit = 512M
```

Restart Apache after making these changes.

### Step 4: Verify File Permissions

Ensure the web server can write to the uploads directory:
- Windows: Right-click → Properties → Security → Edit
- Linux: `chown -R www-data:www-data uploads/live_sessions`

## Usage Guide

### For Administrators/Lecturers

#### Creating an Internal Video Session:

1. Navigate to **Admin → eLearning → Sessions** ([sessions.php](admin/elearning/sessions.php))
2. Select **"Internal Video (Upload)"** as session type
3. Fill in:
   - Course Code
   - Topic
   - Start Time
   - Duration
   - Minimum Payment Percentage (default 50%)
   - Check "Require payment for access" if needed
4. Click **"Create Session"**
5. After creation, click **"Upload Video"** button
6. Select video file (max 500MB)
7. Upload completes → status changes to "Available"

#### Creating an External Session (Zoom/Teams):

1. Select **"External (Zoom/Teams)"** as session type
2. Fill in external meeting details
3. Students with sufficient payment can join via the external link

### For Students

#### Accessing Live Sessions:

1. Navigate to **Students → eLearning → Live Sessions** ([live_sessions.php](students/elearning/live_sessions.php))
2. View your payment status at the top
3. Browse available sessions for your registered courses
4. Click **"Watch Now"** on sessions you can access
5. Video player opens with secure playback

#### Payment Requirements:

- **Green Badge**: You have sufficient payment (≥50%)
- **Red Badge**: Insufficient payment (<50%)
- Sessions show required payment percentage
- Access denied messages explain why

## Database Schema Changes

### New Columns in `lms_sessions`:
- `session_type`: ENUM('external','internal','recording')
- `video_file_path`: Path to uploaded video file
- `video_file_size`: File size in bytes
- `video_duration_seconds`: Video duration
- `video_format`: File extension (mp4, webm, etc.)
- `thumbnail_path`: Path to thumbnail image
- `is_recorded`: Boolean flag
- `recording_url`: URL for recordings
- `access_requires_payment`: Boolean flag
- `min_payment_percentage`: Decimal (0-100)
- `status`: ENUM('scheduled','live','ended','processing','available')

### New Tables:

#### `lms_session_access_log`:
Tracks all access attempts for audit and analytics
- session_id, student_id, access_time
- access_granted, denial_reason
- payment_percentage

#### `lms_video_variants`:
For future adaptive streaming support
- session_id, quality, file_path
- file_size, bitrate

## File Structure

```
wucportal/
├── admin/
│   └── elearning/
│       └── sessions.php (✓ Updated - Admin interface)
├── students/
│   └── elearning/
│       ├── live_sessions.php (✓ New - Session listing)
│       └── view_session.php (✓ New - Video player)
├── includes/
│   └── payment_verification.php (✓ New - Payment functions)
├── db/
│   └── update_sessions_for_internal_video.sql (✓ New - Migration)
└── uploads/
    └── live_sessions/ (✓ New - Video storage)
```

## Security Features

### 1. **Access Control**
- Session-based authentication
- Course registration verification
- Payment percentage checking
- Access attempt logging

### 2. **Video Protection**
- Disabled right-click on video player
- `controlsList="nodownload"` attribute
- CSS pointer-events manipulation
- Files stored outside public web root (recommended)

### 3. **Input Validation**
- File type restrictions
- Size limits enforcement
- SQL injection prevention (prepared statements)
- XSS protection (htmlspecialchars)

## Payment Verification Logic

The system checks:

1. **Is student registered?** → Check `student_courses` table
2. **What's the total due?** → Sum from `invoices` table
3. **What's paid so far?** → Sum from `student_payments` table
4. **Calculate percentage**: `(paid / due) × 100`
5. **Compare to threshold**: `percentage >= min_payment_percentage`

Example:
- Total Due: K 5,000
- Total Paid: K 2,500
- Percentage: 50% → **Access Granted**

## Troubleshooting

### Videos Won't Upload
- Check `php.ini` upload limits
- Verify directory permissions
- Check available disk space
- Review Apache error logs

### Students Can't Access Despite Payment
- Verify payment records in `student_payments` table
- Check invoice records in `invoices` table
- Ensure `academic_year` and `semester` match
- Review access logs in `lms_session_access_log`

### Video Won't Play
- Verify file path in database
- Check file exists on disk
- Try different video format
- Ensure browser supports codec

## Future Enhancements

1. **Adaptive Streaming**: Multiple quality variants
2. **Video Analytics**: Track watch time, completion rate
3. **Live Streaming**: RTMP integration for real-time classes
4. **Subtitles/Captions**: Support for .vtt/.srt files
5. **Mobile App**: Native video player for mobile
6. **CDN Integration**: For better performance

## Configuration Options

### Minimum Payment Percentage

Change the default in [sessions.php](admin/elearning/sessions.php#L70):
```php
$minPaymentPct = (float)($_POST['min_payment_percentage'] ?? 50.00);
```

### Video File Size Limit

In `php.ini`:
```ini
upload_max_filesize = 500M  # Adjust as needed
```

### Supported Video Formats

In [sessions.php](admin/elearning/sessions.php#L95):
```php
$allowedTypes = ['video/mp4', 'video/webm', 'video/ogg', 'video/avi', 'video/mov'];
```

## Support & Maintenance

- **Database Backups**: Schedule regular backups before large uploads
- **Disk Space**: Monitor `uploads/live_sessions/` directory size
- **Access Logs**: Review `lms_session_access_log` for unusual patterns
- **Payment Records**: Keep `student_payments` and `invoices` up to date

## Testing Checklist

- [ ] Database migration runs successfully
- [ ] Upload directory exists and is writable
- [ ] PHP upload limits configured correctly
- [ ] Admin can create internal session
- [ ] Admin can upload video file
- [ ] Student with 50%+ payment can access video
- [ ] Student with <50% payment is denied
- [ ] External sessions still work (Zoom/Teams)
- [ ] Access attempts are logged
- [ ] Video player works in all browsers

## Contact

For issues or questions about this implementation, contact your systems administrator or refer to the WUC Portal documentation.
