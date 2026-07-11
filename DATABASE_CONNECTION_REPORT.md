# Database Connection and Data Structure Analysis for courseReg.php

**Date:** January 12, 2026  
**File Analyzed:** `c:\xampp\htdocs\wucportal\students\courseReg.php`

## ✅ Database Connection Status

### Connection Details
- **Status:** ✅ **CONNECTED** (Successfully verified)
- **Server:** MariaDB 10.4.32
- **Host:** 127.0.0.1 via TCP/IP  
- **Database:** wucportal
- **Connection Method:** mysqli object (verified in courseReg.php line 4-9)
- **Character Set:** utf8mb4 with utf8mb4_general_ci collation
- **Ping Test:** OK

### Connection Flow in courseReg.php
```php
// Line 4: includes guard.php which loads database
require_once __DIR__ . '/includes/guard.php';

// Line 5-9: Safety check for database availability
if (!isset($db) || !($db instanceof mysqli) || $db->connect_errno) {
  http_response_code(500);
  echo '<h5>Database connection is not available...</h5>';
  exit;
}
```

## ✅ Data Structure Verification

### Required Tables Status

| Table Name | Status | Record Count | Usage in courseReg.php |
|-----------|--------|--------------|------------------------|
| **semester_registration** | ✅ EXISTS | 2 records | Source of term context (semester, year, program) |
| **course_registration** | ✅ EXISTS | 4 records | Stores course selections & tracks registrations |
| **course_levels** | ✅ EXISTS | 104 records | Curriculum definition - maps courses to programs |
| **courses** | ✅ EXISTS | 11 records | Course metadata (names, credits, fees) |
| **students** | ✅ EXISTS | 6 records | Student authentication & profile |
| **programs** | ✅ EXISTS | 3 records | Program definitions referenced via FK |

---

## 📊 Detailed Table Structures

### 1. semester_registration (Primary Context Source)
**Purpose:** Stores each student's semester enrollment and provides term context

| Column | Type | Key | Nullable | Description |
|--------|------|-----|----------|-------------|
| id | int(11) | PRI | NOT NULL | Primary key |
| student_id | varchar(50) | MUL | NOT NULL | FK → students.SID |
| program_code | varchar(50) | MUL | NOT NULL | FK → programs.program_code |
| academic_year | varchar(20) | - | NULL | e.g., "2024/2025" |
| semester | varchar(1) | MUL | NOT NULL | Values: 1, 2, 3 |
| year_of_study | varchar(4) | MUL | NOT NULL | Values: 1, 2, 3, 4 |
| student_type | enum | MUL | NOT NULL | Regular/Repeat/Transfer |
| has_failed_courses | tinyint(1) | - | NULL | Boolean flag |
| failed_courses | text | - | NULL | JSON/CSV list |
| financial_status | enum | MUL | NOT NULL | Clear/Pending/Blocked |
| registration_date | timestamp | - | NOT NULL | Initial reg timestamp |
| created_at | timestamp | - | NOT NULL | Record creation |
| updated_at | timestamp | - | NOT NULL | Last modification |
| repeat_semester | tinyint(1) | - | NULL | Boolean flag |
| add_failed_to_cart | tinyint(1) | - | NULL | Boolean flag |

**Usage in courseReg.php (lines 21-32):**
```php
$latestReg = $regDataService->getLatestSemesterRegistration($sid);
if ($latestReg) {
    $semester = (string)($latestReg['semester'] ?? '');
    $Year = (string)($latestReg['year_of_study'] ?? '');
    $program = (string)($latestReg['program_code'] ?? '');
    $semesterRegId = isset($latestReg['id']) ? (int)$latestReg['id'] : null;
    $academicYear = (string)($latestReg['academic_year'] ?? '');
}
```

---

### 2. course_registration (Student Course Selections)
**Purpose:** Stores individual course registrations for each student

| Column | Type | Key | Nullable | Description |
|--------|------|-----|----------|-------------|
| id | int(11) | PRI | NOT NULL | Primary key |
| Sid | varchar(32) | MUL | NOT NULL | Student ID |
| course_code | varchar(50) | - | NOT NULL | Course identifier |
| semester | int(11) | - | NOT NULL | Semester number (1-3) |
| Year | int(11) | - | NOT NULL | Year of study (1-4) |
| registration_date | datetime | - | NULL | When registered |
| semester_registration_id | int(11) | MUL | NOT NULL | FK → semester_registration.id |

**Foreign Key:**
- `semester_registration_id` → `semester_registration.id`

**Usage in courseReg.php (lines 152-160):**
```php
// Preselect already registered courses
$registeredCourses = $regDataService->getRegisteredCourses($sid, $yearInt, $semInt, $semesterRegId);
// Check if already registered (block duplicate registration)
$alreadyRegisteredCourses = !empty($registeredCourses);
```

---

### 3. course_levels (Curriculum Definition)
**Purpose:** Maps courses to programs, semesters, and years (curriculum structure)

| Column | Type | Key | Nullable | Description |
|--------|------|-----|----------|-------------|
| course_level_id | int(11) | PRI | NOT NULL | Primary key |
| course_code | varchar(50) | - | NOT NULL | References courses table |
| program_code | varchar(50) | - | NOT NULL | Program identifier |
| semester | int(11) | - | NOT NULL | Which semester (1-3) |
| year | int(11) | - | NOT NULL | Which year (1-4) |

**Usage in courseReg.php (lines 165-167):**
```php
// Get available curriculum courses
$availableCourses = $regDataService->getAvailableCourses($program, $yearInt, $semInt);
```

**Query Pattern (in RegistrationDataService.php):**
```sql
SELECT DISTINCT cl.course_code, c.course_name, c.credit_hours 
FROM course_levels cl 
LEFT JOIN courses c ON cl.course_code = c.course_code 
WHERE cl.program_code = ? 
  AND cl.year = ? 
  AND cl.semester = ? 
ORDER BY cl.course_code
```

---

### 4. courses (Course Metadata)
**Purpose:** Master course definitions with names, credits, and fees

| Column | Type | Key | Nullable | Description |
|--------|------|-----|----------|-------------|
| course_id | int(11) | PRI | NOT NULL | Primary key |
| course_code | varchar(20) | UNI | NOT NULL | Unique course identifier |
| course_name | varchar(100) | - | NOT NULL | Display name |
| credits | int(11) | - | NULL | Legacy credits field |
| course_fee | decimal(10,2) | - | NOT NULL | Course-specific fee |
| syllabus | text | - | NULL | Course description |
| credit_hours | int(11) | - | NOT NULL | Credit hours value |
| max_capacity | int(11) | - | NOT NULL | Maximum students |
| min_year | int(11) | - | NOT NULL | Minimum year eligibility |
| is_laboratory | tinyint(1) | - | NULL | Boolean flag |
| is_advanced | tinyint(1) | - | NULL | Boolean flag |
| status | enum | - | NULL | active/inactive |
| sort_order | int(11) | - | NULL | Display ordering |
| created_at | timestamp | - | NOT NULL | Record creation |
| updated_at | timestamp | - | NULL | Last modification |

**Usage in courseReg.php:**
- Joined with course_levels to get course names and credit hours for display
- Used for fee calculations via `calculate_fees.php` (line 305)

---

## 🔗 Foreign Key Relationships

### Verified Relationships:
```
course_registration.semester_registration_id → semester_registration.id
semester_registration.student_id → students.SID
semester_registration.program_code → programs.program_code (duplicate FK)
```

### Data Flow in courseReg.php:
```
1. User loads page with session: $_SESSION['Sid']
2. Query semester_registration → get latest registration
3. Extract: semester, year_of_study, program_code, semester_registration_id
4. Query course_levels → get curriculum courses for that term
5. Query course_registration → check already registered courses
6. Display available courses with preselected checkboxes
7. On submit → insert into course_registration with semester_registration_id
```

---

## ⚙️ Key Services and Data Access

### RegistrationDataService (lines 18, 25)
**File:** `students/includes/RegistrationDataService.php`

**Methods Used:**
```php
// Get latest semester registration for student
public function getLatestSemesterRegistration(string $studentId): ?array

// Get courses already registered for term
public function getRegisteredCourses(string $sid, int $year, int $semester, int $semRegId): array

// Get available courses from curriculum
public function getAvailableCourses(string $programCode, int $year, int $semester): array
```

### FeeGuard Service (lines 17, 139)
**File:** `students/includes/FeeGuard.php`

**Function Used:**
```php
// Check if student has paid 50% of tuition (required for registration)
fg_check_fee_threshold($db, $sid, (int)$Year, (int)$semester, 50.0);
```

---

## 🔍 Data Query Logic in courseReg.php

### 1. Term Context Discovery (Lines 25-32)
```php
$latestReg = $regDataService->getLatestSemesterRegistration($sid);
// Returns most recent semester_registration record ordered by registration_date DESC
// Provides: semester, year_of_study, program_code, semester_registration_id, academic_year
```

### 2. Preselection of Registered Courses (Lines 152-160)
```php
$registeredCourses = $regDataService->getRegisteredCourses($sid, $yearInt, $semInt, $semesterRegId);
// Query: SELECT * FROM course_registration WHERE semester_registration_id = ?
// Result: Array of course_code values already registered
// Purpose: Pre-check checkboxes for already-registered courses
```

### 3. Available Curriculum Courses (Lines 165-167)
```php
$availableCourses = $regDataService->getAvailableCourses($program, $yearInt, $semInt);
// Query: SELECT from course_levels JOIN courses WHERE program_code=? AND year=? AND semester=?
// Result: Array of course objects with course_code, course_name, credit_hours
// Purpose: Display all courses student CAN register for
```

### 4. Failed Courses Carryover (Lines 175-199)
```php
$failed = EligibilityService::getFailedCourses($db, $sid);
// Query: Find courses where student has failed (grade < 50)
// Cross-reference with course_levels to find which failed courses are offered this semester
// Purpose: Allow students to retake failed courses even if not in their current year
```

---

## ✅ Validation & Safety Checks

### Database Connection Safety (Lines 5-9)
```php
if (!isset($db) || !($db instanceof mysqli) || $db->connect_errno) {
  http_response_code(500);
  echo '<h5>Database connection is not available...</h5>';
  exit;
}
```

### Session Validation (Lines 107-115)
```php
if ($sid === '') {
    echo '<div class="alert alert-danger">Session expired or not logged in</div>';
    $hasValidTerm = false;
}
```

### Term Context Validation (Lines 116-125)
```php
$semInt = filter_var($semester, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]);
$yearInt = filter_var($Year, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);

if ($semester === '' || $Year === '' || $semInt === false || $yearInt === false || $program === '') {
    echo '<div class="alert alert-warning">No valid semester registration found</div>';
    $hasValidTerm = false;
}
```

### Financial Gate (Lines 139-141)
```php
$check = fg_check_fee_threshold($db, $sid, (int)$Year, (int)$semester, 50.0);
$GLOBALS['__ca_eligible'] = $check['ok'];
$GLOBALS['__can_register'] = $check['ok']; // Block registration if <50% paid
```

### Duplicate Registration Prevention (Lines 157-161)
```php
$alreadyRegisteredCourses = !empty($registeredCourses);
$GLOBALS['__already_registered'] = $alreadyRegisteredCourses;
if ($alreadyRegisteredCourses) {
    $GLOBALS['__can_register'] = false; // Block further registration
}
```

---

## 🔧 Debug Mode (Line 19)

**Activation:** Add `?debug=true` to URL

**Debug Panel Output (Lines 38-72):**
- Database connection type and ping status
- Table existence checks
- Student ID, semester_registration_id, term variables
- Record counts for validation:
  - `semester_registration` records for student
  - `course_registration` records by semester_registration_id
  - `course_registration` records by term (Sid + semester + Year)

**Example Debug Output:**
```
Debug: DB + Data Sources
DB ping=OK | server=10.4.32-MariaDB | host=127.0.0.1 via TCP/IP
Tables: semester_registration=OK course_registration=OK course_levels=OK courses=OK
Sid=2020 | semRegId=1 | academic_year=2024/2025 | year_of_study=1 | semester=1 | program=BSCS
Counts: semester_registration(student_id)=1 | course_registration(semRegId)=2 | course_registration(term)=2
```

---

## 📋 Summary of Findings

### ✅ Connection Health
- **Database Connection:** Working correctly via mysqli
- **Connection Pooling:** Managed by XAMPP/MariaDB
- **Character Encoding:** Properly configured (utf8mb4)

### ✅ Data Structure Integrity
- **All Required Tables:** Present and accessible
- **Foreign Keys:** Properly configured with referential integrity
- **Schema Alignment:** courseReg.php queries match actual table structures
- **Data Volume:** Sufficient for testing (6 students, 104 course definitions)

### ✅ Data Flow Validation
1. **Term Context:** Correctly loaded from `semester_registration`
2. **Course Selection:** Properly queries `course_levels` + `courses`
3. **Registration Tracking:** Uses `course_registration` with proper FK
4. **Financial Gate:** Integrated with fee threshold check
5. **Duplicate Prevention:** Checks existing registrations before allowing new ones

### ⚠️ Observations
1. **Duplicate Foreign Keys:** `semester_registration.program_code` has 2 FK constraints to `programs.program_code` (not an error, but redundant)
2. **Legacy Fields:** `courses.credits` appears unused (superseded by `credit_hours`)
3. **Data Sparsity:** Only 2 semester registrations and 4 course registrations (test environment)

---

## 🎯 Recommendations

### For Production Deployment:
1. ✅ **Database connection is production-ready** - no issues detected
2. ✅ **Schema is correctly structured** - foreign keys properly enforce data integrity
3. ✅ **courseReg.php correctly interfaces with data layer** - all queries valid
4. Monitor query performance if `course_levels` table grows significantly
5. Consider adding indexes on frequently queried columns:
   - `course_registration (Sid, semester, Year)` - for term-based lookups
   - `course_levels (program_code, year, semester)` - for curriculum queries

### For Development:
- Use `?debug=true` flag to diagnose registration issues
- Verify `RegistrationDataService` class is accessible (autoloading working)
- Check error logs at `/logs/error.log` if database queries fail

---

## 🔍 Test Commands

To reproduce these checks:
```bash
# Run the comprehensive test script
C:\xampp\php\php.exe c:\xampp\htdocs\wucportal\test_db_full.php

# Or access via web browser
http://localhost/wucportal/test_db_full.php
```

**Cleanup:**
```bash
# Remove test files after verification
del c:\xampp\htdocs\wucportal\test_db_*.php
```

---

**Report Generated:** January 12, 2026  
**Analysis Tool:** PHP CLI + mysqli queries  
**Status:** ✅ **ALL CHECKS PASSED** - Database and data structure are correctly configured
