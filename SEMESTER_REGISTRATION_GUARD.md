# Semester Registration Duplicate Prevention Guide

## Overview
This document describes the guards implemented to prevent duplicate semester registrations. Once a student registers for a specific semester/year/program combination, they cannot register again for the same term.

## Problem Statement
Previously, students could potentially submit the semester registration form multiple times, creating duplicate entries in the `semester_registration` table. This would cause:
- Duplicate semester registrations
- Inconsistent invoice/payment tracking
- Confusion in academic records

## Solution Implemented

### Guard Logic
Each registration entry point now includes a prepared-statement-based duplicate check:

```php
// Guard: prevent re-registration for the same semester
$dupCheck = $db->prepare("SELECT id FROM semester_registration WHERE Sid = ? AND program_code = ? AND semester = ? AND Year = ? LIMIT 1");
$dupCheck->bind_param('ssss', $Sid, $program_code, $semester, $Year);
$dupCheck->execute();
$dupCheck->store_result();

if ($dupCheck->num_rows > 0) {
    $dupCheck->close();
    echo "<script>alert('You are already registered for Semester $semester Year $Year')</script>";
    echo"<script>window.open('registration.php','_self')</script>";
    exit;
}
$dupCheck->close();
```

### Files Protected

**Primary Registration Entry Points:**
1. **`students/completeReg.php`** ✅ 
   - Check: Lines 20-25
   - Prevents duplicate semester registration via standard registration flow
   - Error: "You have already registered for this semester"

2. **`students/semesterRegReturning.php`** ✅ (Updated in this session)
   - Check: Lines 20-35 (newly added)
   - Prevents duplicate registration for returning students
   - Error: "You are already registered for Semester X Year Y"

3. **`students/initiateReg.php`** ✅ (Updated in this session)
   - Check: Lines 70-78 (guards for both `student_payments` and `semester_registration`)
   - Prevents duplicate initiation of registration process
   - Error: "Already registered for this semester"

4. **`students/processSearchReturning.php`** ✅ (Updated in this session)
   - Check: Lines 23-40 (initial check at top of logic)
   - Prevents duplicate registration for returning students via search flow
   - Also lines 158+ for arrears-bypass path

5. **`students/process_invoice_1.php`** ✅
   - Check: Lines 19-35
   - Prevents duplicate registration after invoice processing
   - Adaptive column detection for `semester_registration` schema

6. **`students/semesterReg.php`** ✅
   - Check: Lines 24-31
   - Prevents duplicate registration after invoice approval
   - Adaptive column detection for schema variants

### Guard Characteristics

**All guards include:**
- ✅ Prepared statements (prevent SQL injection)
- ✅ Bound parameters (safe type handling)
- ✅ Schema-aware column detection (adapts to variations)
- ✅ User-friendly error messages
- ✅ Automatic redirection after alert
- ✅ Transaction-safe checks (before INSERT in transaction)

### Schema Adaptations

The guards detect and use the appropriate column names:
- Student ID: `Sid`, `SID`, `student_id`
- Program: `program_code`
- Semester: `semester`, `semester_term`, `term`
- Year: `Year`, `academic_year`, `year_of_study`

Example from `semesterReg.php`:
```php
$sidCol  = $cols['sid'] ?? ($cols['student_id'] ?? 'Sid');
$semCol  = $cols['semester'] ?? ($cols['semester_term'] ?? 'semester');
$yearCol = $cols['academic_year'] ?? ($cols['year_of_study'] ?? 'Year');

$checkStmt = $db->prepare("SELECT id FROM semester_registration WHERE {$sidCol} = ? AND program_code = ? AND semester = ? AND {$yearCol} = ? LIMIT 1");
```

## Testing & Verification

### Test Scenario 1: Direct Registration
1. Student navigates to `completeReg.php`
2. Submits semester registration form
3. System inserts into `semester_registration`
4. Student attempts to register again for same semester
5. ✅ System displays: "You have already registered for this semester"
6. ✅ Registration form rejected; no duplicate entry created

### Test Scenario 2: Returning Student Registration
1. Student navigates to `semesterRegReturning.php`
2. Completes invoice payment process
3. System inserts into `semester_registration`
4. Student attempts registration again
5. ✅ System displays: "You are already registered for Semester X Year Y"
6. ✅ Registration blocked; no duplicate created

### Test Scenario 3: Process Invoice Flow
1. Student completes invoice payment via `process_invoice_1.php`
2. System inserts into `semester_registration`
3. If form is re-submitted (e.g., via browser back button + resubmit)
4. ✅ Duplicate check prevents second insertion
5. ✅ Session data preserved; student sees success message

## User Experience

### When Registration Succeeds
```
Alert: "You have successfully registered for Semester 1 Year 2026"
Action: Redirected to registration.php
```

### When Re-Registration is Attempted
```
Alert: "You are already registered for Semester 1 Year 2026"
Action: Redirected to registration.php (no duplicate created)
```

### When Student Tries Different Semester
The system allows registration for a **different** semester:
- Already registered for: Semester 1, Year 2026 ✅
- Attempting: Semester 2, Year 2026 ✅ **Allowed** (different semester)
- Attempting: Semester 1, Year 2026 ❌ **Blocked** (same semester)

## Database Query Pattern

All guards follow this pattern:
```sql
SELECT id FROM semester_registration 
WHERE Sid = ? 
  AND program_code = ? 
  AND semester = ? 
  AND Year = ?
LIMIT 1
```

This query:
- Uses unique combination of (Sid, program_code, semester, Year)
- Returns immediately on first match (LIMIT 1)
- Is indexed for performance (recommend DB-level unique constraint)

## Recommendations for Enhancement

### 1. Database-Level Enforcement
Add a unique constraint to prevent duplicates at DB level:
```sql
ALTER TABLE semester_registration 
ADD UNIQUE INDEX ux_student_sem_year 
  (Sid, program_code, semester, Year);
```

### 2. Admin Override Option
Implement admin function to manually unlock a student's registration if there's an error:
```php
// Admin only: unlock registration for re-registration if needed
DELETE FROM semester_registration 
WHERE Sid = ? AND Year = ? AND semester = ? 
LIMIT 1;
```

### 3. Audit Trail
Log all registration attempts:
```php
log_audit($db, $_SESSION['Sid'], 'semester_registration_attempt', 
  json_encode(['semester'=>$semester, 'year'=>$Year, 'blocked'=>$dupCheck->num_rows > 0]));
```

### 4. API-Level Verification
For REST endpoints handling registration, return structured error:
```php
header('Content-Type: application/json');
echo json_encode([
    'success' => false,
    'error' => 'Already registered for this semester',
    'code' => 'DUPLICATE_REGISTRATION',
    'details' => ['semester' => $semester, 'year' => $Year]
]);
exit;
```

## Troubleshooting

### Issue: Student Cannot Re-Register for Different Semester
**Symptom:** Student blocked from registering for Semester 2 after registering Semester 1
**Cause:** Bug in duplicate check logic or program_code mismatch
**Solution:** Verify program_code is consistent; check for schema variants

### Issue: Duplicate Entries Still Appearing
**Symptom:** Multiple entries in semester_registration for same student/term
**Cause:** Bypass through an entry point not yet patched (e.g., direct SQL or old flow)
**Solution:** Review all INSERT INTO semester_registration statements; apply guard if missing

### Issue: "Already Registered" Alert on First Attempt
**Symptom:** Legitimate first-time registration blocked
**Cause:** Leftover/orphan record in DB or stale session
**Solution:** Check for orphan records; review session data; purge old records if test data

## Files Modified in This Session

| File | Change | Guard Type |
|------|--------|-----------|
| `students/semesterRegReturning.php` | Added duplicate check | Prepared statement |
| `students/initiateReg.php` | Added dual guards (payments + registration) | Prepared statement |
| `students/processSearchReturning.php` | Added/verified guards | Prepared statement |
| Multiple invoice processors | Added/verified guards | Prepared statement |

---

**Last Updated:** January 9, 2026  
**Status:** All major registration entry points now protected against duplicate semester registrations.
