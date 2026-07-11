# Quick Start Guide - Enhanced Sessions System

## What's New?
✅ Course integration with role-based access control  
✅ Enhanced video upload security with MIME validation  
✅ Video deletion functionality  
✅ Better UX with progress bars and file validation  
✅ CSRF protection on all forms  

## Quick Setup (5 Minutes)

### 1. Run Database Setup
```bash
cd c:\xampp\htdocs\wucportal
php apply_sessions_improvements.php
```

### 2. Assign Lecturers to Courses

**Option A: Auto-Test Assignment**
```bash
php assign_lecturers_to_courses.php --auto-assign-test
```

**Option B: Manual Assignment (Recommended for Production)**
```sql
INSERT INTO lecturer_courses (lecturer_id, course_code) VALUES
('YOUR_LECTURER_ID', 'COURSE_CODE_1'),
('YOUR_LECTURER_ID', 'COURSE_CODE_2');
```

### 3. Test the System
1. Login as lecturer → Should see only assigned courses
2. Create a "Recorded Video" session
3. Upload a video file (MP4, < 500MB)
4. Verify upload success and file display

## Key Files Modified/Created

| File | Purpose |
|------|---------|
| `admin/elearning/sessions.php` | **UPDATED** - Main interface with all enhancements |
| `apply_sessions_improvements.php` | **NEW** - Automated database setup |
| `assign_lecturers_to_courses.php` | **NEW** - Helper for course assignments |
| `db/sessions_improvements.sql` | **NEW** - SQL schema changes |
| `SESSIONS_ENHANCEMENT_README.md` | **NEW** - Full documentation |

## Important Configuration

### PHP Settings Required (php.ini)
```ini
upload_max_filesize = 500M
post_max_size = 550M
max_execution_time = 600
```

**Restart Apache after changing php.ini!**

### Directory Permissions
The system auto-creates `uploads/live_sessions/` with proper permissions, but verify:
```bash
# Should be writable by Apache
icacls "c:\xampp\htdocs\wucportal\uploads" /grant Everyone:(OI)(CI)F
```

## Security Features

### What's Protected:
- ✅ Only systems_admin or assigned lecturer can create sessions
- ✅ Only session owner or admin can upload videos
- ✅ MIME type validation prevents fake file extensions
- ✅ Randomized filenames prevent guessing attacks
- ✅ CSRF tokens on all forms
- ✅ `.htaccess` blocks direct directory browsing

### File Upload Rules:
- **Allowed formats**: MP4, WebM, OGG, MOV
- **Max size**: 500MB
- **MIME validation**: Yes (checks actual file content)
- **Duplicate prevention**: Must delete existing video first

## Common Issues & Quick Fixes

### "No courses available"
```sql
-- Check if lecturer has assignments
SELECT * FROM lecturer_courses WHERE lecturer_id = 'YOUR_ID';

-- Assign courses if missing
INSERT INTO lecturer_courses VALUES ('YOUR_ID', 'COURSE_CODE', NOW());
```

### Upload fails with "File too large"
Edit `c:\xampp\php\php.ini`:
```ini
upload_max_filesize = 500M
post_max_size = 550M
```
Restart Apache

### Permission denied on upload
- Verify you created the session (check `created_by` column)
- OR login as systems_admin
- Ensure session `provider = 'internal'`

## Testing Checklist

Quick verification steps:

```
☐ Database script ran successfully
☐ Lecturer sees only assigned courses
☐ Admin sees all courses
☐ Can create recorded session
☐ Can upload video < 500MB
☐ Invalid files rejected (wrong type/size)
☐ Upload shows progress bar
☐ Video shows in list with size
☐ Can delete video with confirmation
☐ Cannot upload to others' sessions
```

## Need Help?

1. **Check errors**: `c:\xampp\apache\logs\error.log`
2. **View README**: See `SESSIONS_ENHANCEMENT_README.md` for full docs
3. **Test scripts**: Run helper scripts to diagnose issues
4. **Verify DB**: Check if all tables/columns exist

## Next Steps

After basic setup works:
1. Assign real lecturers to their actual courses
2. Set appropriate course statuses (active/inactive)
3. Configure video storage limits if needed
4. Consider implementing video transcoding
5. Add monitoring for upload directory size

---

**Quick Reference Commands:**

```bash
# Setup database
php apply_sessions_improvements.php

# View assignments
php assign_lecturers_to_courses.php

# Auto-test assign
php assign_lecturers_to_courses.php --auto-assign-test

# Check PHP settings
php -i | findstr upload

# Restart Apache (XAMPP)
net stop Apache2.4
net start Apache2.4
```
