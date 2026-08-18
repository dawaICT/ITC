# Lecturer System - Debug & Analysis Report

## Overview
The WUC Portal has a comprehensive lecturer management system with multiple components for course assignments, student tracking, and continuous assessment (CA) management.

---

## Database Structure

### Tables Found

#### 1. **course_lecturer** (Main Assignment Table) ✓
- **Status**: EXISTS - 78 records
- **Columns**: 
  - `course_lecturer_id` (int) - Primary key
  - `course_code` (varchar(50))
  - `staff_id` (varchar(50))
- **Purpose**: Links lecturers (staff) to courses they teach
- **Note**: This is the PRIMARY table being used for lecturer-course assignments

#### 2. **lecturer_courses** (Alternative Table) ✓
- **Status**: EXISTS - 0 records (EMPTY)
- **Columns**:
  - `lecturer_id` (varchar(50))
  - `course_code` (varchar(20))
  - `assigned_date` (datetime)
- **Purpose**: Alternative lecturer-course assignment table (not currently used)
- **Issue**: Has collation mismatch with other tables (utf8mb4_unicode_ci vs utf8mb4_general_ci)

#### 3. **staff** (Lecturer Information) ✓
- **Status**: EXISTS - 15 records
- **Key Columns**: 
  - `staff_id` (varchar(50)) - Primary identifier
  - `title`, `Fname`, `Lname`, `sex`, `email`, `mobile`
  - `deptId` (varchar(50)) - Department reference
  - `qualification`, `profile_image`
- **Sample Staff IDs**: WUC012, WUC013, WUC014, WUC015, WUC016

#### 4. **staff_positions** ✓
- **Status**: EXISTS - 62 records
- **Columns**: 
  - `staff_id` (varchar(50))
  - `PosID` (varchar(50))
  - `status` (enum: active/inactive)
- **Lecturer Position ID**: `LEC001` (used to identify lecturers)

#### 5. **courses** ✓
- **Status**: EXISTS - 11 records
- **Key Columns**: 
  - `course_code` (varchar(20)) - Primary identifier
  - `course_name` (varchar(100))
  - `credits`, `course_fee`, `credit_hours`
  - `max_capacity`, `min_year`
  - `is_laboratory`, `is_advanced`
- **Sample Courses**: CSC101, CSC102, PHY101, MTH201, BIO101

---

## Key Issues Found

### 🔴 Issue 1: Data Inconsistency in course_lecturer Table
**Problem**: The `course_lecturer` table has assignments with invalid staff_ids
- Staff IDs like "024", "023", "LVTC23", "WUC01" are referenced
- These staff IDs don't exist in the `staff` table (returns NULL for Fname, Lname)
- Courses like "MED125", "SSN022I", "MCB018" don't exist in `courses` table (returns NULL for course_name)

**Impact**: Lecturers see courses assigned but can't view proper course details

**Solution**: 
```sql
-- Find orphaned assignments
SELECT cl.*, s.Fname, s.Lname, c.course_name 
FROM course_lecturer cl
LEFT JOIN staff s ON cl.staff_id = s.staff_id
LEFT JOIN courses c ON cl.course_code = c.course_code
WHERE s.staff_id IS NULL OR c.course_code IS NULL;

-- Clean up (if needed)
-- DELETE FROM course_lecturer WHERE staff_id NOT IN (SELECT staff_id FROM staff);
```

### 🟡 Issue 2: Collation Mismatch
**Problem**: `lecturer_courses` table has different collation (utf8mb4_unicode_ci) than other tables (utf8mb4_general_ci)

**Error Message**:
```
Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT) for operation '='
```

**Impact**: Cannot join `lecturer_courses` with `staff` or `courses` tables properly

**Solution**:
```sql
-- Fix collation on lecturer_courses table
ALTER TABLE lecturer_courses CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

### 🟡 Issue 3: Duplicate Assignment Tables
**Problem**: System has TWO tables for lecturer-course assignments:
- `course_lecturer` (actively used, 78 records)
- `lecturer_courses` (empty, 0 records, collation issue)

**Impact**: Confusion about which table to use; code has to check both

**Recommendation**: Standardize on ONE table:
1. Either keep `course_lecturer` (current primary)
2. Or migrate to `lecturer_courses` (after fixing collation)
3. Update all code references consistently

---

## Lecturer Portal Features

### Core Pages (in `/lecturers/` directory)

#### 1. **index.php** - Dashboard
- Shows course count, student count, pending assessments
- Uses flexible schema detection for both table structures
- Handles collation issues with explicit COLLATE clauses

#### 2. **myCourses.php** - Course Management
- Lists all courses assigned to logged-in lecturer
- Uses LEFT JOIN to handle missing courses gracefully
- Joins with both `courses` and `program_courses` tables

#### 3. **myStudent.php** - Student List
- Shows students enrolled in lecturer's courses
- Queries through course assignments

#### 4. **upload_ca.php** - Assessment Upload
- Manual and CSV upload for continuous assessments
- Auto-creates `semester_assessment` table if missing
- Includes term/semester/year tracking
- Has feature flags for enabling/disabling upload methods

#### 5. **materials.php** - Course Materials
- Upload and manage course materials/notes
- Files stored in `/lecturers/uploads/materials/`

#### 6. **assessments.php** - Assessment Review
- View and manage continuous assessments

### Admin Pages (in `/admin/` directory)

#### 1. **lecturers.php** - Lecturer Management Dashboard
- Statistics: total lecturers, courses, gender breakdown
- Lists all lecturers with course assignments
- Handles both `course_lecturer` ID structures (id vs course_lecturer_id)

#### 2. **manage_lectures.php** - CRUD Operations
- Add new lecturers (generates staff_id like 'LEC' + timestamp)
- Edit lecturer details
- Delete lecturers (cascades to course assignments)
- Assigns position PosID = 'LEC001' automatically

#### 3. **assign_course_lecturer.php** - Assignment Management
- Interface to assign courses to lecturers
- Prevents duplicate assignments
- Uses `course_lecturer` table

### Assignment Scripts

#### 1. **assign_lecturers_to_courses.php**
- Bulk assignment utility
- Can auto-assign all courses to a test lecturer

#### 2. **view_lecturer_assignments.php**
- CLI script to view current assignments
- Checks `lecturer_courses` table specifically

---

## Authentication & Security

### Guard System
**File**: `/lecturers/includes/guard.php`
- Session-based authentication
- Requires `$_SESSION['staff_id']` to be set
- 5-minute idle timeout (300 seconds)
- Redirects to `/wucportal/staff_login.php` if not authenticated
- Safe redirect function handles already-sent headers

### Session Variables
- `$_SESSION['staff_id']` - Primary identifier
- `$_SESSION['last_activity']` - For timeout tracking
- `$_SESSION['loginLecturer']` - Login error messages

---

## Code Patterns & Architecture

### Schema Flexibility
The codebase uses **defensive programming** to handle database schema variations:

```php
// Table existence check
$tableExists = function(mysqli $db, string $table): bool {
    $res = $db->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
};

// Column detection
$detectColumn = function(mysqli $db, string $table, array $candidates): ?string {
    foreach ($candidates as $col) {
        $res = $db->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
        if ($res && $res->num_rows > 0) return $col;
    }
    return null;
};
```

This allows the code to work with:
- Either `course_lecturer` or `lecturer_courses` tables
- Different column names (`staff_id` vs `lecturer_id`)
- Different ID column names (`id` vs `course_lecturer_id`)

### Collation Handling
To prevent collation errors, queries use explicit COLLATE clauses:

```php
"SELECT COUNT(DISTINCT s.`{$studentsIdCol}`) AS total 
FROM students s 
INNER JOIN `{$studentCourseTable}` sc 
  ON s.`{$studentsIdCol}` COLLATE utf8mb4_unicode_ci = sc.`{$scStudentCol}` COLLATE utf8mb4_unicode_ci"
```

---

## Recommendations

### High Priority

1. **Clean Invalid Assignments**
   ```sql
   -- Backup first!
   CREATE TABLE course_lecturer_backup AS SELECT * FROM course_lecturer;
   
   -- Remove assignments with non-existent staff
   DELETE FROM course_lecturer 
   WHERE staff_id NOT IN (SELECT staff_id FROM staff);
   
   -- Remove assignments with non-existent courses  
   DELETE FROM course_lecturer 
   WHERE course_code NOT IN (SELECT course_code FROM courses) 
     AND course_code NOT IN (SELECT course_code FROM program_courses);
   ```

2. **Fix Collation on lecturer_courses**
   ```sql
   ALTER TABLE lecturer_courses 
   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   ```

3. **Standardize on One Assignment Table**
   - Decision needed: Keep `course_lecturer` or migrate to `lecturer_courses`
   - Update all references consistently
   - Drop unused table after migration

### Medium Priority

4. **Add Foreign Keys** (for referential integrity)
   ```sql
   ALTER TABLE course_lecturer 
   ADD CONSTRAINT fk_cl_staff 
   FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE;
   
   ALTER TABLE course_lecturer 
   ADD CONSTRAINT fk_cl_course 
   FOREIGN KEY (course_code) REFERENCES courses(course_code) ON DELETE CASCADE;
   ```

5. **Add Indexes** (for performance)
   ```sql
   CREATE INDEX idx_staff_id ON course_lecturer(staff_id);
   CREATE INDEX idx_course_code ON course_lecturer(course_code);
   ```

### Low Priority

6. **Consolidate Staff Identification**
   - System uses various formats: WUC012, LEC20250202... , "024"
   - Consider standardizing to one format

7. **Add Audit Trail**
   - Track who assigned courses and when
   - Add columns: `assigned_by`, `assigned_at`, `updated_at`

---

## Testing Commands

### Check Lecturer Login
```bash
php -r "require 'db/connect.php'; session_start(); \$_SESSION['staff_id'] = 'WUC012'; echo 'Session set for WUC012';"
```

### View Assignments for a Lecturer
```sql
SELECT cl.course_code, c.course_name, s.Fname, s.Lname
FROM course_lecturer cl
LEFT JOIN courses c ON cl.course_code = c.course_code
LEFT JOIN staff s ON cl.staff_id = s.staff_id
WHERE cl.staff_id = 'WUC012';
```

### Check All Lecturers
```sql
SELECT s.staff_id, s.Fname, s.Lname, sp.PosID, sp.status
FROM staff s
INNER JOIN staff_positions sp ON s.staff_id = sp.staff_id
WHERE sp.PosID = 'LEC001';
```

---

## File Structure Summary

```
/wucportal/
├── lecturers/                    # Lecturer portal
│   ├── index.php                 # Dashboard
│   ├── myCourses.php             # Course list
│   ├── myStudent.php             # Student list
│   ├── upload_ca.php             # CA upload (manual/CSV)
│   ├── assessments.php           # View assessments
│   ├── materials.php             # Course materials
│   ├── includes/
│   │   ├── guard.php             # Authentication guard
│   │   └── nav.php               # Navigation
│   └── uploads/
│       └── materials/            # Course material files
│
├── admin/                        # Admin section
│   ├── lecturers.php             # Lecturer management dashboard
│   ├── manage_lectures.php       # CRUD for lecturers
│   └── assign_course_lecturer.php # Course assignment UI
│
├── db/
│   └── connect.php               # Database connection
│
└── assign_lecturers_to_courses.php  # Bulk assignment utility
```

---

## Current System Status

✅ **Working Well**:
- Lecturer authentication and session management
- Dashboard with statistics
- Course listing for lecturers
- CA upload functionality
- Admin management interface
- Flexible schema detection

⚠️ **Needs Attention**:
- Invalid/orphaned course assignments (78 records with issues)
- Collation mismatch on `lecturer_courses` table
- Duplicate table structures causing confusion
- Missing foreign key constraints

🔴 **Critical Issues**:
- Many course assignments reference non-existent staff or courses
- Data integrity not enforced at database level

---

## Next Steps

1. Run data cleanup queries (after backup!)
2. Fix collation on `lecturer_courses` table
3. Decide on single assignment table standard
4. Add foreign key constraints
5. Test lecturer login and course access
6. Verify CA upload functionality

---

**Generated**: 2026-02-02
**Database**: MySQL via XAMPP
**PHP Version**: Check with `php -v`
