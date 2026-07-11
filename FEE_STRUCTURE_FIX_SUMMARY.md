## Fix Summary: add_fee_structure.php

### Issues Fixed

1. **Incorrect column reference**: Changed from checking non-existent `period_type` column to using `study_mode` (primary) or `period_mode` (fallback)
2. **Termly programs support**: Now properly detects and supports term-based programs (3 terms) vs semester-based programs (2 semesters)
3. **Validation logic**: Updated to correctly validate period ranges based on program type

### Changes Made

#### 1. Period Type Detection (Lines 42-78)
**Before:**
```php
$period_type = 'semester';
if ($stmt = $db->query("SHOW COLUMNS FROM programs LIKE 'period_type'")) {
    if ($stmt->num_rows > 0) {
        if ($ptypeStmt = $db->prepare("SELECT period_type FROM programs WHERE program_code = ? LIMIT 1")) {
            // ... using period_type column
        }
    }
}
```

**After:**
```php
$period_type = 'semester';
$hasStudyMode = false;
$hasPeriodMode = false;

if ($stmt = $db->query("SHOW COLUMNS FROM programs LIKE 'study_mode'")) {
    $hasStudyMode = ($stmt->num_rows > 0);
    $stmt->free();
}
if ($stmt = $db->query("SHOW COLUMNS FROM programs LIKE 'period_mode'")) {
    $hasPeriodMode = ($stmt->num_rows > 0);
    $stmt->free();
}

if ($hasStudyMode) {
    // Use study_mode (primary)
    $ptypeStmt = $db->prepare("SELECT study_mode FROM programs WHERE program_code = ? LIMIT 1");
} elseif ($hasPeriodMode) {
    // Fallback to period_mode
    $ptypeStmt = $db->prepare("SELECT period_mode FROM programs WHERE program_code = ? LIMIT 1");
}
```

#### 2. Updated Form Instructions (Line 157)
**Before:**
```
"This program uses semesters (2 per academic year). If a program uses terms, the selector below will adapt automatically."
```

**After:**
```
"Fee structures support both semester-based (2 periods) and term-based (3 periods) programs. Select a program to see the appropriate period selector."
```

### Testing Results

✅ **Semester-based program (BBA101)**
- Detected: semester mode
- Max periods: 2
- Validation: Accepts periods 1-2, rejects 3+

✅ **Term-based program (CS101)**
- Detected: term mode  
- Max periods: 3
- Validation: Accepts periods 1-3, rejects 4+

✅ **Frontend dynamic updates**
- JavaScript correctly fetches period type from AJAX endpoint
- UI dynamically updates labels (Semester → Term)
- Dropdown options adjust (2 → 3 options)

### Database Schema

The programs table has these columns:
- `study_mode` - ENUM('semester','term') - Primary column used
- `period_mode` - ENUM('semester','term') - Fallback column
- `term_based` - TINYINT(1) - Legacy flag

The fix prioritizes `study_mode` for consistency with other parts of the application (particularly the AJAX endpoint at `admin/ajax/get_program_period_type.php`).

### Files Modified
- `accounts/add_fee_structure.php` - Main fix implemented

### Related Files (No changes needed, already compatible)
- `admin/ajax/get_program_period_type.php` - Already uses study_mode
- `accounts/programFees.php` - Displays period labels correctly

### How to Test
1. Navigate to Accounts → Program Fees → Add Fee Structure
2. Select a semester-based program (e.g., BBA101) - should show "Semester 1, Semester 2"
3. Select a term-based program (e.g., CS101) - should show "Term 1, Term 2, Term 3"
4. Try adding fees with period 3 for semester program - should fail validation
5. Try adding fees with period 3 for term program - should succeed
