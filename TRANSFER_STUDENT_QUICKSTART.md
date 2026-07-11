# Quick Start: Transfer Student Registration

## What Was Added

The existing student registration form (`admissions/regOldStud.php`) now supports transfer students with the following:

### User-Facing Features
1. **Transfer Student Checkbox** - "This is a Transfer Student"
   - Toggles visibility of transfer-specific fields
   - Located after the Sponsorship dropdown

2. **Transfer-Specific Fields** (visible only when checkbox is checked):
   - Previous Institution Name
   - Credits Transferred (number)
   - Previous Program (optional)
   - Reason for Transfer (optional text area)

### Backend Processing
- All transfer student data is validated and stored in the database
- System provides dynamic success message indicating transfer status

## Setup Instructions

### Step 1: Add Database Columns
Run one of these methods to add the required columns to the `students` table:

**Option A - Using the migration script (Recommended):**
```bash
# Open in browser
http://localhost/wucportal/migrations/add_transfer_columns_to_students.php
```

**Option B - Using SQL directly:**
```sql
ALTER TABLE students ADD COLUMN IF NOT EXISTS is_transfer TINYINT(1) DEFAULT 0;
ALTER TABLE students ADD COLUMN IF NOT EXISTS transfer_from VARCHAR(255) NULL;
ALTER TABLE students ADD COLUMN IF NOT EXISTS transfer_credits INT DEFAULT 0;
ALTER TABLE students ADD COLUMN IF NOT EXISTS transfer_program VARCHAR(255) NULL;
ALTER TABLE students ADD COLUMN IF NOT EXISTS transfer_letter TEXT NULL;
```

### Step 2: Clear Browser Cache
Clear your browser cache to ensure the form JavaScript loads correctly.

### Step 3: Test the Form
1. Open `http://localhost/wucportal/admissions/regOldStud.php`
2. Fill out the form normally
3. Notice the new "This is a Transfer Student" checkbox
4. Check/uncheck to see transfer fields appear/disappear

## Usage

### Registering a Non-Transfer Student (Default)
1. Fill out the form as usual
2. Leave the "This is a Transfer Student" checkbox unchecked
3. Submit the form
4. Success message: "New student was successfully registered. Proceed to admit."

### Registering a Transfer Student
1. Fill out the form normally
2. **Check** the "This is a Transfer Student" checkbox
3. Fill in the transfer-specific fields:
   - Previous Institution Name (required when transfer is checked)
   - Credits Transferred (required number field)
   - Previous Program (optional)
   - Reason for Transfer (optional)
4. Submit the form
5. Success message: "New transfer student was successfully registered (Credits to transfer: X). Proceed to admit."

## Database Fields

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| is_transfer | TINYINT(1) | 0 | Flag: 1=transfer, 0=non-transfer |
| transfer_from | VARCHAR(255) | NULL | Previous institution name |
| transfer_credits | INT | 0 | Number of credits to transfer |
| transfer_program | VARCHAR(255) | NULL | Previous program name |
| transfer_letter | TEXT | NULL | Reason for transfer |

## Consistency Notes

This implementation is consistent with:
- **New Student Registration** (`admissions/regNewStud.php`)
  - Uses same `is_transfer` checkbox pattern
  
- **Student Course Registration** (`students/registration.php`)
  - Uses same `is_transfer` flag in fee calculations
  - System already adjusts fees for transfer students

- **Fee Calculation** (`students/calculate_fees.php`)
  - Already supports transfer student fee adjustments
  - Now receives transfer flag from admissions process

## Files Modified
- `admissions/regOldStud.php` - Form UI and JavaScript
- `admissions/processOldForm.php` - Backend processing
- `migrations/add_transfer_columns_to_students.php` - Database migration

## Troubleshooting

### Transfer fields not appearing?
- Clear browser cache (Ctrl+F5 or Cmd+Shift+R)
- Check browser console for JavaScript errors
- Verify JavaScript is enabled

### Form not saving transfer data?
- Run the migration script first to ensure columns exist
- Check server error logs for SQL errors
- Verify file permissions on uploads directory

### Success message shows but no data in database?
- Run `migrations/add_transfer_columns_to_students.php` to verify columns exist
- Check that transfer_from field is filled in when checkbox is checked

## Next Steps

After registering a transfer student:
1. Student proceeds to admission (`admitStudent.php`)
2. During admission, transfer credits should be evaluated
3. Student fee calculation will use transfer flag for adjusted rates
4. Staff can view transfer student details in student records

## Support

For issues or questions, refer to:
- `TRANSFER_STUDENT_UPDATE.md` - Detailed technical documentation
- Database schema: `students` table columns
- Existing code patterns: `admissions/regNewStud.php`, `students/registration.php`
