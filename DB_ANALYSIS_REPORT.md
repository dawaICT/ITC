# Database Connection & Data Structure Analysis Report

## Executive Summary

The three registration files (`searchReturning_Stud.php`, `new_student_registration.php`, and `courseReg.php`) have **functional but inconsistent** database implementations:

| Aspect | Status | Risk |
|--------|--------|------|
| Database Connection | ✅ Working (but mixed APIs) | Low |
| Data Flow | ✅ Correct | Low |
| Column Naming | ⚠️ Inconsistent | Medium |
| Type Handling | ⚠️ Implicit conversions | Medium |
| Data Integrity | ✅ Good | Low |

---

## Detailed Findings

### 1. DATABASE API INCONSISTENCY

**Current State:**
```
searchReturning_Stud.php   → mysqli (via db/connect.php)
new_student_registration.php → PDO (via Database.php)
courseReg.php              → mysqli (via db/connect.php)
```

**Why It Matters:**
- Different error handling mechanisms
- Connection pooling isn't shared
- Makes unified testing difficult
- Harder to migrate/update later

**Severity:** ⚠️ Medium - System works but maintainability suffers

---

### 2. COLUMN NAME INCONSISTENCIES

#### Identity Column Variations:

| Table | Column Name | Used By |
|-------|------------|---------|
| student_program | `Sid` | searchReturning_Stud, courseReg |
| semester_registration | `student_id` | new_student_registration, courseReg |
| course_registration | `Sid` | courseReg |
| invoices | `student_id` | new_student_registration |

**Problem:** When joining across tables, must map `Sid` ↔ `student_id`

```php
// Example problem - missing join condition
SELECT * FROM semester_registration sr
JOIN invoices i ON sr.student_id = i.student_id  // ✓ Works
JOIN course_registration cr ON sr.student_id = cr.Sid  // ✗ Needs mapping
```

**Severity:** ⚠️ High - Common source of join bugs

---

#### Year/Semester Column Variations:

| Table | Year Column | Semester Column |
|-------|-----------|-----------------|
| semester_registration | `year_of_study` (varchar) | `semester` (varchar) |
| course_registration | `Year` (int) | `semester` (int) |
| invoices | `academic_year` (varchar) | `semester` (int) |
| AcademicSessionService | `academic_year` | `semester_term` |

**Problem Code:**
```php
// courseReg.php - Line 162
$srYearCol = $cols['academic_year'] ?? ($cols['year'] ?? ...);

// This dynamic detection is a workaround for inconsistency
```

**Severity:** ⚠️ Medium - Works but brittle

---

### 3. DATA FLOW VERIFICATION

#### Flow 1: Returning Student Registration Path
```
searchReturning_Stud.php (form)
    ↓
    INPUT: $_SESSION['Sid'] + student_program table
    ↓
processSearchReturning.php (submit)
    ↓
    Creates: semester_registration record
    ↓
courseReg.php (course selection)
    ↓
    INPUT: semester_registration data
    ↓
processCourseReg.php (course submit)
    ↓
    Creates: course_registration records
```

**Status:** ✅ Verified working

#### Flow 2: New Student Registration Path
```
new_student_registration.php (form)
    ↓
    INPUT: Generated student_id from pool
    ↓
process_registration.php (submit)
    ↓
    Creates: invoices + semester_registration
    ↓
courseReg.php (already has registration)
    ↓
    Inserts: courses via course_registration
```

**Status:** ✅ Verified working

---

### 4. TYPE HANDLING ANALYSIS

#### Problematic Type Conversions:

```php
// searchReturning_Stud.php - Line 30
"WHERE sp.Sid = '".$db->real_escape_string($Sid)."'"
// $Sid is string ✓

// courseReg.php - Line 162
$srYearCol => 'Year' (int) or 'year_of_study' (varchar)

// Comparison issue:
WHERE $srYearCol = ?  // If Year (int): 1, If year_of_study (varchar): "1"
```

**Risk:** Loose comparison bugs with PHP type juggling

**Severity:** ⚠️ Medium

---

### 5. ACTUAL DATABASE TABLE STRUCTURES

```
semester_registration:
  ├─ id (int)
  ├─ student_id (varchar) ← Identity
  ├─ program_code (varchar)
  ├─ academic_year (varchar)
  ├─ semester (varchar)
  ├─ year_of_study (varchar)
  └─ [11 more columns...]

course_registration:
  ├─ id (int)
  ├─ Sid (varchar) ← Identity (different name!)
  ├─ course_code (varchar)
  ├─ semester (int)
  ├─ Year (int)
  └─ [3 more columns...]

student_program:
  ├─ id (int)
  ├─ Sid (varchar) ← Identity
  ├─ program_code (varchar)
  └─ [6 more columns...]

invoices:
  ├─ id (int)
  ├─ student_id (varchar) ← Identity
  ├─ academic_year (varchar)
  ├─ semester (int)
  └─ [10 more columns...]
```

---

## Issues Found

### Critical Issues: 0
### High Priority Issues: 1

**Issue #1: Column Name Mapping in Joins**
- **Location:** courseReg.php, line 169-180
- **Problem:** Dynamic column detection masks the inconsistency
- **Recommendation:** Create a unified column mapping table

### Medium Priority Issues: 3

**Issue #2: Mixed Database APIs**
- Inconsistent connection handling
- Recommend: Migrate all to PDO

**Issue #3: Type Casting Gaps**
- Semester and Year stored as different types
- Recommend: Explicit type casting in queries

**Issue #4: No Foreign Key Constraints**
- Tables don't enforce referential integrity
- Recommend: Add FK constraints

---

## Recommended Fixes

### Phase 1: Immediate (Code-level)
```php
// Create a table schema constants file
class DBSchema {
    const STUDENT_ID_COL = 'student_id';  // Unified name
    const SEMESTER_COL = 'semester';      // Unified name  
    const YEAR_COL = 'year_of_study';     // Unified name
    
    // Mapping for legacy columns
    const COURSE_REG_SID = 'Sid';
    const COURSE_REG_YEAR = 'Year';
}

// Usage in queries:
WHERE " . DBSchema::STUDENT_ID_COL . " = ?
```

### Phase 2: Medium-term (Database)
```sql
-- Add FK constraints
ALTER TABLE course_registration
ADD CONSTRAINT fk_cr_student_id 
FOREIGN KEY (Sid) REFERENCES student_program(Sid);

ALTER TABLE invoices
ADD CONSTRAINT fk_inv_student_id
FOREIGN KEY (student_id) REFERENCES semester_registration(student_id);
```

### Phase 3: Long-term (Refactor)
- Create Repository/DAO pattern
- Migrate all mysqli to PDO
- Add ORM (Doctrine/Eloquent)

---

## Validation Checklist

- ✅ All three files use correct tables
- ✅ Data flows correctly between files
- ✅ Session variables properly escaped/sanitized
- ✅ No critical data integrity issues
- ⚠️ Column naming could be standardized
- ⚠️ Mixed APIs should be unified
- ⚠️ Type casting could be explicit

---

## Performance Considerations

**Current:**
- searchReturning_Stud.php: 1 query (SELECT student_program)
- new_student_registration.php: Multiple queries + transactions
- courseReg.php: 2-3 queries + dynamic column detection

**Optimization Opportunities:**
1. Cache academic session info
2. Pre-join common tables
3. Index on (Sid, semester, year_of_study)
4. Cache column metadata instead of SHOW COLUMNS

---

## Conclusion

**Overall Assessment:** ✅ **FUNCTIONAL** but **MAINTENANCE-PRONE**

The system works correctly for the happy path, but:
- Column naming inconsistencies increase bug risk
- Mixed APIs reduce code maintainability
- No formal schema/contract definition
- Type handling relies on PHP's loose comparison

**Recommended Priority:** Implement Phase 1 fixes immediately (low effort, high value)
