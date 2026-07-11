# Course Levels Table Debug Report

**Date:** February 2, 2026  
**Status:** ✓ FIXED

## Issues Found

### 1. Column Name Mismatch (CRITICAL)
- **Problem:** Two files were referencing a column named `year_level` which doesn't exist
- **Actual column name:** `year`
- **Affected files:**
  - [admin/courseReg.php](admin/courseReg.php#L102)
  - [admin/semesterReg.php](admin/semesterReg.php#L307)

### 2. Missing Performance Indexes
- **Problem:** No indexes on frequently queried columns
- **Impact:** Slow query performance on course registration lookups

## Fixes Applied

### 1. Column Name Corrections ✓
- **File:** `admin/courseReg.php` (line 102)
  - Changed: `cl.year_level = '$Year'`
  - To: `cl.year = '$Year'`

- **File:** `admin/semesterReg.php` (line 307)
  - Changed: `cl.year_level = ?`
  - To: `cl.year = ?`

### 2. Performance Indexes Added ✓
```sql
ALTER TABLE course_levels ADD INDEX idx_program_semester_year (program_code, semester, year);
ALTER TABLE course_levels ADD INDEX idx_course_code (course_code);
```

## Table Structure

```
course_levels
├── course_level_id (PK, auto_increment)
├── course_code (varchar(50))
├── program_code (varchar(50))
├── semester (int)
└── year (int)

Indexes:
├── PRIMARY (course_level_id)
├── idx_program_semester_year (program_code, semester, year)
└── idx_course_code (course_code)
```

## Data Summary

- **Total records:** 98
- **Programs with courses:**
  - DCMSG: 41 courses
  - DRN: 37 courses
  - BscPH: 8 courses
  - BSCS: 8 courses
  - CS101: 4 courses

## Verification Tests

All critical tests passed:
- ✓ Column structure correct
- ✓ Indexes in place
- ✓ courseReg.php query works
- ✓ semesterReg.php query works
- ✓ Data integrity maintained
- ⚠ 86 orphaned records found (course_levels reference courses that don't exist in courses table)

## Orphaned Records Warning

86 course_levels entries reference courses that don't exist in the `courses` table. This won't cause errors but means students won't see those courses. Consider:
1. Adding the missing courses to the `courses` table, OR
2. Removing the orphaned course_levels entries

To identify orphaned records:
```sql
SELECT cl.* 
FROM course_levels cl 
LEFT JOIN courses c ON cl.course_code = c.course_code 
WHERE c.course_code IS NULL;
```

## Scripts Created

1. **debug_course_levels.php** - Comprehensive table diagnostic
2. **fix_course_levels.php** - Issue analysis and recommendations
3. **add_course_levels_indexes.php** - Adds performance indexes
4. **verify_course_levels_fix.php** - Verification test suite

## Testing Recommendations

1. Test course registration for BSCS Year 1, Semester 1
2. Test semester registration for multiple programs
3. Monitor query performance with the new indexes
4. Address orphaned records if needed

## Next Steps

- ✓ Fixes applied and verified
- ⚠ Consider cleaning up orphaned records
- ⚠ Add foreign key constraints to prevent future orphaned records:
  ```sql
  ALTER TABLE course_levels 
  ADD CONSTRAINT fk_course_levels_course 
  FOREIGN KEY (course_code) REFERENCES courses(course_code) 
  ON DELETE CASCADE;
  ```

---
*Debug completed successfully. All critical issues resolved.*
