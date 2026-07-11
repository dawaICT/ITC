# Registration Data Flow & Structure

## Overview

This document describes the data flow and relationships between the student registration pages in the WUC Portal.

## Key Tables

### 1. `semester_registration`
The primary registration record linking a student to a semester/year.

| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment ID |
| student_id | VARCHAR(50) | FK to students.SID |
| program_code | VARCHAR(50) | FK to programs.program_code |
| semester | VARCHAR(1) | Semester (1 or 2) |
| year_of_study | VARCHAR(4) | Year of study (1-4) |
| financial_status | ENUM | 'Clear', 'Pending', 'Blocked' |
| has_failed_courses | TINYINT | 0 or 1 |
| student_type | ENUM | 'Regular', 'Repeat', 'Transfer' |

### 2. `course_registration`
Individual course selections linked to a semester registration.

| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment ID |
| Sid | VARCHAR(32) | Student ID |
| course_code | VARCHAR(50) | Course code |
| semester | INT | Semester number |
| Year | INT | Year of study |
| semester_registration_id | INT (FK) | Links to semester_registration.id |
| registration_date | DATETIME | When registered |

### 3. `invoices`
Financial invoices generated during semester registration.

| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment ID |
| invoice_number | VARCHAR(32) | Unique invoice number |
| student_id | VARCHAR(50) | Student ID |
| program_code | VARCHAR(20) | Program code |
| semester | VARCHAR(1) | Semester |
| Year | VARCHAR(4) | Year of study |
| amount | DECIMAL(10,2) | Total amount due |
| status | ENUM | 'Pending', 'Paid', 'Overdue' |

### 4. `student_payments`
Payment records tracking fee payments.

| Column | Type | Description |
|--------|------|-------------|
| payment_id | INT (PK) | Auto-increment ID |
| Sid | VARCHAR(20) | Student ID |
| amount_paid | DECIMAL(10,2) | Amount paid |
| balance | DECIMAL(10,2) | Remaining balance |
| semester_term | VARCHAR(20) | Semester |
| year_of_study | VARCHAR(10) | Year |
| payment_status | ENUM | 'pending', 'completed', 'failed' |

## Data Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        STUDENT REGISTRATION FLOW                         │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────┐
│   1. REGISTRATION   │  registration.php / semesterReg.php
│                     │
│  Student selects:   │
│  - Program          │
│  - Year of Study    │
│  - Semester         │
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│ semester_registration│  CREATED
│                     │
│ Links student to    │
│ specific term       │
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│     invoices        │  CREATED
│                     │
│ Fee invoice for     │
│ the semester        │
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│ 2. COURSE SELECTION │  courseReg.php
│                     │
│ - Reads semester_registration (current term)
│ - Shows courses from course_levels
│ - Checks 50% fee payment (for CA eligibility)
│ - Displays CA eligibility status
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│ course_registration │  CREATED
│                     │
│ Links student to    │ processCourseReg.php
│ specific courses    │
│                     │
│ semester_registration_id → semester_registration.id
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│  3. CA ELIGIBILITY  │
│                     │
│ Student can receive │
│ CA marks IF 50%     │
│ of fees are paid    │
│                     │
│ Checked by:         │
│ - ca_upload.php     │
│ - upload_ca.php     │
│ - upload_ca_csv.php │
└─────────────────────┘
```

## Page Relationships

### registration.php
**Purpose:** Initial semester registration (new & returning students)
**Creates:** 
- `semester_registration` record
- `invoices` record

**Key Functions:**
- `getStudentDetails()` - Get student profile
- `checkTuitionPaymentStatus()` - Check 50% threshold
- `getSemesterRegisteredCourses()` - Get courses for display
- `hasExistingCourseRegistrations()` - Check if returning student

### courseReg.php
**Purpose:** Course selection for registered semester
**Reads:**
- `semester_registration` (to get current term)
- `course_levels` (available courses)
- `course_registration` (already registered courses)

**Key Checks:**
- Valid semester registration exists
- 50% fee threshold (for CA eligibility display only - doesn't block)

**Outputs:**
- Course list with selection checkboxes
- CA eligibility status banner
- Selected credits counter

### processCourseReg.php
**Purpose:** Process course registration submission
**Creates:**
- `course_registration` records

**Validations:**
- Prerequisites (via EligibilityService)
- Credit limits
- 50% fee threshold (via FeeGuard)
- Student registered for semester

## Centralized Service

The `RegistrationDataService` class in `students/includes/RegistrationDataService.php` provides:

```php
$service = new RegistrationDataService($db);

// Get registration status
$status = $service->getRegistrationStatus($studentId);
// Returns:
// [
//   'has_semester_registration' => bool,
//   'has_course_registration' => bool,
//   'current_term' => ['year_of_study' => int, 'semester' => int, 'program_code' => string],
//   'payment_status' => ['percent_paid' => float, 'can_receive_ca' => bool, ...],
//   'registered_courses' => [...],
//   'can_register_courses' => bool,
//   'can_receive_ca' => bool
// ]

// Get available courses
$courses = $service->getAvailableCourses($programCode, $yearOfStudy, $semester);

// Get registered courses
$courses = $service->getRegisteredCourses($studentId, $yearOfStudy, $semester);

// Check semester registration
$exists = $service->hasSemesterRegistration($studentId, $yearOfStudy, $semester);
$id = $service->getSemesterRegistrationId($studentId, $yearOfStudy, $semester);
```

## CA Eligibility Rule

**Rule:** Lecturers can only add Continuous Assessment (CA) marks for students who have paid at least 50% of their tuition fees.

**Implementation:**
1. `courseReg.php` - Shows warning if < 50% paid, but displays courses
2. `ca_upload.php`, `upload_ca.php`, etc. - Blocks CA upload if < 50% paid
3. `finance_guard.php` - `is_student_allowed_ca()` function
4. `FeeGuard.php` - `fg_check_fee_threshold()` function
