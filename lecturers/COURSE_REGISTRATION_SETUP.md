# Course Registration & Fee Tracking Setup Guide

## Overview

The CA Upload Module requires proper course registration tracking with fee validation. This ensures that only students who have paid at least 50% of their tuition fees can receive continuous assessment marks.

## Database Structure

### course_registration Table Schema

The table uses **your existing column naming convention** (Sid, course_code) rather than foreign key integers:

```sql
CREATE TABLE IF NOT EXISTS `course_registration` (
  `registration_id` INT AUTO_INCREMENT PRIMARY KEY,
  `Sid` VARCHAR(50) NOT NULL COMMENT 'Student ID (matches existing schema)',
  `course_code` VARCHAR(50) NOT NULL COMMENT 'Course code (matches courses table)',
  `semester` VARCHAR(20) NOT NULL COMMENT 'Semester/Term (1, 2, or 3)',
  `Year` VARCHAR(10) NOT NULL COMMENT 'Academic year of study (1, 2, 3, 4)',
  `program_type` VARCHAR(20) DEFAULT 'semester' COMMENT 'semester or term',
  `study_mode` ENUM('Full-time', 'Part-time', 'Distance') DEFAULT 'Full-time',
  `tuition_total` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Total tuition due',
  `amount_paid` DECIMAL(10, 2) DEFAULT 0.00 COMMENT 'Amount paid by student',
  `is_active` TINYINT(1) DEFAULT 1 COMMENT '1=active, 0=deferred/suspended',
  `registration_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  
  -- Indexes for performance
  INDEX idx_student (`Sid`),
  INDEX idx_course (`course_code`),
  INDEX idx_student_course (`Sid`, `course_code`, `semester`, `Year`),
  UNIQUE KEY unique_registration (`Sid`, `course_code`, `semester`, `Year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Key Fields Explained

| Field | Purpose | Used By CA Module |
|-------|---------|-------------------|
| `Sid` | Student ID (string format like "S001234") | ✅ Matches student to registration |
| `course_code` | Course identifier (like "CSC101") | ✅ Validates student is registered for course |
| `semester` | Term identifier (1, 2, or 3) | ✅ Ensures marks are for correct period |
| `Year` | Academic year of study | ✅ Matches year level |
| `tuition_total` | Total fees due for this course | ✅ Used in 50% calculation |
| `amount_paid` | Amount student has paid | ✅ Used in 50% calculation |
| `is_active` | Registration status | ✅ Blocks CA upload for inactive students |

## Installation Steps

### Step 1: Run the Migration Script

Via command line (recommended):
```bash
cd c:\xampp\htdocs\wucportal\lecturers
php ensure_course_registration_table.php
```

Or via browser:
```
http://localhost/wucportal/lecturers/ensure_course_registration_table.php
```

Expected output:
```
Starting course_registration table migration...

Creating course_registration table...
✓ Table created successfully.

Checking indexes...
  Index idx_student exists ✓
  Index idx_course exists ✓
  Index idx_student_course exists ✓

Migration completed successfully!
```

### Step 2: Generate Sample Test Data (Optional)

To test the 50% payment rule:

```bash
php sample_course_registration_data.php
```

This creates registrations with different payment levels:
- 0% payment (should be blocked)
- 25% payment (should be blocked)
- 50% payment (should be allowed)
- 75% payment (should be allowed)
- 100% payment (should be allowed)

### Step 3: Verify Setup

Navigate to:
```
http://localhost/wucportal/lecturers/view_course_registrations.php
```

You should see:
- Total registrations count
- Number of CA-eligible students (≥50% paid)
- Number of CA-ineligible students (<50% paid)
- Recent registration details

## How the 50% Validation Works

### Backend Logic (finance_guard.php)

The `is_student_allowed_ca()` function:

1. **Checks if enforcement is enabled** (portal_settings: `enforce_ca_payment`)
2. **Retrieves minimum threshold** (portal_settings: `min_ca_paid_percent`, default 50%)
3. **Queries course_registration** for the student's fee data
4. **Calculates percentage**: `(amount_paid / tuition_total) * 100`
5. **Returns eligibility**:
   - `allowed = true` if percentage ≥ 50%
   - `allowed = false` if percentage < 50%

### Upload Flow (upload_ca.php)

When a lecturer tries to upload a CA mark:

```php
// After validating course registration
$elig = is_student_allowed_ca($db, $sid, $year, $semester);

if (!$elig['allowed']) {
    $manualMessage = '<div class="alert alert-danger">
        This student is not eligible to receive CA marks. 
        Only '.$elig['percent'].'% of tuition fees paid (minimum 50% required).
    </div>';
    // Upload is blocked
}
```

## Populating Registration Data

### Manual Entry (via SQL)

```sql
INSERT INTO course_registration 
(Sid, course_code, semester, Year, program_type, tuition_total, amount_paid, is_active)
VALUES 
('S001234', 'CSC101', '1', '1', 'semester', 500.00, 250.00, 1);
```

### Bulk Import (via CSV)

Create a CSV with columns:
```
Sid,course_code,semester,Year,tuition_total,amount_paid
S001234,CSC101,1,1,500.00,250.00
S001235,CSC101,1,1,500.00,500.00
```

Then import via:
```bash
mysqlimport --local --fields-terminated-by=',' \
  --lines-terminated-by='\n' \
  wuc_database course_registration.csv
```

### Automated Registration (Recommended)

Integrate with your student enrollment system:

```php
// When a student enrolls for a course
$stmt = $db->prepare("
    INSERT INTO course_registration 
    (Sid, course_code, semester, Year, program_type, tuition_total, amount_paid, is_active)
    VALUES (?, ?, ?, ?, ?, ?, 0.00, 1)
    ON DUPLICATE KEY UPDATE tuition_total = VALUES(tuition_total)
");
$stmt->bind_param('ssssd', $sid, $course_code, $semester, $year, $program_type, $fee_amount);
$stmt->execute();
```

### Update Payment Records

When a student makes a payment:

```php
$stmt = $db->prepare("
    UPDATE course_registration 
    SET amount_paid = amount_paid + ?
    WHERE Sid = ? AND Year = ? AND semester = ?
");
$stmt->bind_param('dsss', $payment_amount, $sid, $year, $semester);
$stmt->execute();
```

## Configuration

### Enable/Disable Fee Enforcement

Via `portal_settings` table:

```sql
-- Disable enforcement (allow all uploads regardless of payment)
INSERT INTO portal_settings (setting_key, setting_value) 
VALUES ('enforce_ca_payment', '0')
ON DUPLICATE KEY UPDATE setting_value = '0';

-- Enable enforcement (default)
INSERT INTO portal_settings (setting_key, setting_value) 
VALUES ('enforce_ca_payment', '1')
ON DUPLICATE KEY UPDATE setting_value = '1';
```

### Change Minimum Payment Threshold

```sql
-- Require 75% payment instead of 50%
INSERT INTO portal_settings (setting_key, setting_value) 
VALUES ('min_ca_paid_percent', '75')
ON DUPLICATE KEY UPDATE setting_value = '75';

-- Set back to 50% (default)
INSERT INTO portal_settings (setting_key, setting_value) 
VALUES ('min_ca_paid_percent', '50')
ON DUPLICATE KEY UPDATE setting_value = '50';
```

## Testing the CA Upload

### Test Case 1: Student with 0% Payment (Should Fail)

1. Navigate to `upload_ca.php`
2. Select a course with a student who has `amount_paid = 0.00`
3. Try to upload a CA mark
4. Expected result: ❌ **Error message**: "Only 0% of tuition fees paid (minimum 50% required)"

### Test Case 2: Student with 50% Payment (Should Pass)

1. Select a course with a student who has `amount_paid = tuition_total * 0.5`
2. Upload a CA mark
3. Expected result: ✅ **Success**: "CA mark saved successfully"

### Test Case 3: Inactive Student (Should Fail)

1. Set a student's registration to `is_active = 0`
2. Try to upload a CA mark
3. Expected result: ❌ **Error**: "Student is not registered for this course"

## Troubleshooting

### Issue: All students show 0% payment

**Cause**: `amount_paid` column is NULL or not populated

**Fix**:
```sql
UPDATE course_registration SET amount_paid = 0.00 WHERE amount_paid IS NULL;
```

### Issue: Students with 100% payment still blocked

**Cause**: Data type mismatch or floating-point precision

**Fix**: Check that `tuition_total` and `amount_paid` are both DECIMAL(10,2)

### Issue: "Student is not registered for this course"

**Cause**: No matching record in `course_registration`

**Fix**: Ensure the student is registered for the course/semester/year combination

### Issue: Migration script fails

**Cause**: Existing table with incompatible structure

**Fix**: Backup and recreate:
```sql
RENAME TABLE course_registration TO course_registration_backup;
-- Then run migration script again
```

## Files Created

| File | Purpose |
|------|---------|
| `ensure_course_registration_table.php` | Migration script (creates/updates table) |
| `sample_course_registration_data.php` | Test data generator |
| `view_course_registrations.php` | Admin dashboard for registration overview |
| `finance_guard.php` (updated) | Fee validation logic |

## Next Steps

1. ✅ Run the migration script
2. ✅ Generate sample data for testing
3. ✅ Test CA upload with different payment scenarios
4. 🔲 Integrate with your student enrollment workflow
5. 🔲 Connect payment processing to update `amount_paid`
6. 🔲 Train staff on the 50% payment rule

## Support

If you encounter issues:

1. Check error logs: `C:\xampp\apache\logs\error.log`
2. Enable PHP errors: `error_reporting(E_ALL);`
3. Verify database connection: `db/connect.php`
4. Run diagnostics: `view_course_registrations.php`
