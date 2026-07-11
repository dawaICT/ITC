# Registration System - Quick Reference Guide

## Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                     Frontend Layer                       │
│  (registration_redesigned.php + JavaScript)             │
└──────────────────┬──────────────────────────────────────┘
                   │ AJAX Requests
                   ▼
┌─────────────────────────────────────────────────────────┐
│                      API Layer                           │
│              (api/registration.php)                      │
│  - Route handling                                        │
│  - Authentication & CSRF protection                      │
│  - Input validation                                      │
└──────────────────┬──────────────────────────────────────┘
                   │ Method calls
                   ▼
┌─────────────────────────────────────────────────────────┐
│                   Service Layer                          │
│         (includes/RegistrationService.php)               │
│  - Business logic                                        │
│  - Validation rules                                      │
│  - Transaction management                                │
└──────────────────┬──────────────────────────────────────┘
                   │ Database queries
                   ▼
┌─────────────────────────────────────────────────────────┐
│                  Data Access Layer                       │
│        (includes/DatabaseConnection.php)                 │
│  - PDO connection management                             │
│  - Prepared statements                                   │
│  - Transaction support                                   │
└──────────────────┬──────────────────────────────────────┘
                   │ SQL queries
                   ▼
┌─────────────────────────────────────────────────────────┐
│                   Database Layer                         │
│              (MySQL with InnoDB)                         │
│  - Tables, Views, Procedures, Triggers                   │
└─────────────────────────────────────────────────────────┘
```

## Database Schema

### Core Tables

#### 1. academic_sessions
Manages academic sessions and registration periods.
```sql
id, session_name, start_date, end_date, 
is_active, is_registration_open,
registration_start_date, registration_end_date
```

#### 2. semester_registration
Main registration tracking table.
```sql
id, registration_number, student_id, program_code,
academic_session_id, semester, year_of_study,
student_type, registration_status, financial_status,
total_courses, total_credits, total_fees
```

#### 3. course_registration
Individual course enrollments.
```sql
id, semester_registration_id, student_id, course_code,
course_type, credits, course_fee, registration_status,
is_repeat, grade
```

#### 4. registration_audit_log
Audit trail for all registration changes.
```sql
id, semester_registration_id, action_type,
field_changed, old_value, new_value, changed_by
```

#### 5. registration_fees
Fee breakdown for registrations.
```sql
id, semester_registration_id, fee_type, fee_name,
amount, is_paid, payment_date
```

## API Endpoints

Base URL: `/api/registration.php`

### GET /session
Get current active academic session.
```javascript
fetch('/api/registration.php/session')
  .then(res => res.json())
  .then(data => console.log(data));
```

### GET /check-status
Check if registration is currently open.
```javascript
fetch('/api/registration.php/check-status')
  .then(res => res.json())
  .then(data => console.log(data.is_open));
```

### POST /create
Create a new semester registration.
```javascript
fetch('/api/registration.php/create', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  body: JSON.stringify({
    student_id: 'SID001',
    program_code: 'CS101',
    semester: 1,
    year_of_study: 1,
    student_type: 'New'
  })
}).then(res => res.json());
```

### POST /{id}/courses
Register courses for a semester registration.
```javascript
fetch('/api/registration.php/courses', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  body: JSON.stringify({
    registration_id: 123,
    courses: ['CS101', 'CS102', 'MATH101']
  })
}).then(res => res.json());
```

### POST /{id}/submit
Submit registration for approval.
```javascript
fetch('/api/registration.php/submit', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  body: JSON.stringify({
    registration_id: 123
  })
}).then(res => res.json());
```

### GET /{id}/details
Get complete registration details.
```javascript
fetch('/api/registration.php/details?id=123')
  .then(res => res.json())
  .then(data => console.log(data));
```

### GET /history
Get student's registration history.
```javascript
fetch('/api/registration.php/history?student_id=SID001')
  .then(res => res.json())
  .then(data => console.log(data));
```

### GET /available-courses
Get courses available for a program and semester.
```javascript
fetch('/api/registration.php/available-courses?program_code=CS101&semester=1')
  .then(res => res.json())
  .then(data => console.log(data));
```

### POST /drop-course
Drop a course from registration.
```javascript
fetch('/api/registration.php/drop-course', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  body: JSON.stringify({
    course_registration_id: 456
  })
}).then(res => res.json());
```

## Service Layer Usage

### PHP Backend Example

```php
<?php
require_once 'includes/RegistrationService.php';

$service = new RegistrationService();

// Check if registration is open
if ($service->isRegistrationOpen()) {
    // Create semester registration
    $result = $service->createSemesterRegistration([
        'student_id' => 'SID001',
        'program_code' => 'CS101',
        'semester' => 1,
        'year_of_study' => 1,
        'student_type' => 'New'
    ]);
    
    if ($result['success']) {
        $regId = $result['registration_id'];
        
        // Register courses
        $courseResult = $service->registerCourses($regId, [
            'CS101', 'CS102', 'MATH101', 'ENG101'
        ]);
        
        if ($courseResult['success']) {
            // Submit registration
            $submitResult = $service->submitRegistration($regId);
            
            if ($submitResult['success']) {
                echo "Registration completed successfully!";
            }
        }
    }
}
?>
```

## Database Connection Usage

### Basic Queries

```php
<?php
require_once 'includes/DatabaseConnection.php';

$db = DatabaseConnection::getInstance();

// Fetch one row
$student = $db->fetchOne(
    "SELECT * FROM students WHERE SID = ?",
    ['SID001']
);

// Fetch all rows
$courses = $db->fetchAll(
    "SELECT * FROM courses WHERE status = ?",
    ['active']
);

// Insert
$id = $db->insert('students', [
    'SID' => 'SID002',
    'Fname' => 'John',
    'Lname' => 'Doe',
    'sex' => 'M'
]);

// Update
$affected = $db->update(
    'students',
    ['email' => 'john@example.com'],
    'SID = :sid',
    [':sid' => 'SID002']
);

// Delete
$deleted = $db->delete(
    'course_registration',
    'id = ?',
    [123]
);
?>
```

### Transactions

```php
<?php
$db = DatabaseConnection::getInstance();

// Manual transaction
$db->beginTransaction();
try {
    $db->insert('table1', $data1);
    $db->update('table2', $data2, 'id = ?', [1]);
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
}

// Automatic transaction
$result = $db->transaction(function($db) {
    $db->insert('table1', $data1);
    $db->update('table2', $data2, 'id = ?', [1]);
    return ['success' => true];
});
?>
```

## Common Workflows

### New Student Registration
1. Student visits `/students/registration_redesigned.php`
2. Clicks "Register as New Student"
3. System creates draft semester registration
4. Student selects courses
5. System validates (12-21 credits)
6. Student submits registration
7. System changes status to "Pending"
8. Admin reviews and approves

### Returning Student Registration
1. Student visits `/students/registration_redesigned.php`
2. Clicks "Continue Registration"
3. System checks financial status
4. If clear, creates new semester registration
5. System loads available courses for semester
6. Student selects courses
7. System validates prerequisites and credits
8. Student submits registration

## Status Flow

```
Draft → Pending → Approved
  ↓        ↓         ↓
Cancelled  Rejected  (Final)
```

- **Draft**: Being created, can be edited
- **Pending**: Submitted, awaiting approval
- **Approved**: Accepted, finalized
- **Rejected**: Denied, can resubmit
- **Cancelled**: Student cancelled

## Validation Rules

### Semester Registration
- Student must have active session
- Registration must be within allowed dates
- Financial status must be "Clear" for returning students
- Cannot register for same semester twice

### Course Registration
- Minimum 12 credits per semester
- Maximum 21 credits per semester
- Course must be in program curriculum
- Prerequisites must be satisfied
- Cannot register for same course twice (unless repeat)

## Security Features

1. **CSRF Protection**: All POST/PUT/DELETE requests require valid token
2. **Authentication**: Session-based authentication required
3. **Prepared Statements**: All queries use parameter binding
4. **Input Validation**: Server-side validation on all inputs
5. **Audit Logging**: All changes tracked in audit log

## Performance Tips

1. Use indexes for frequently queried columns
2. Enable query caching in MySQL
3. Use connection pooling for production
4. Cache session data on frontend
5. Use pagination for large result sets
6. Monitor slow query log

## Troubleshooting

### Registration Won't Submit
- Check if session is active and open
- Verify minimum credit requirements met
- Check financial status
- Review validation errors in response

### API Returns 401
- Session expired or not authenticated
- Redirect to login page

### API Returns 403
- Invalid CSRF token
- Refresh page to get new token

### Database Connection Fails
- Check MySQL service is running
- Verify credentials in DatabaseConnection.php
- Check database exists

## File Locations

```
wucportal/
├── api/
│   └── registration.php              # API endpoints
├── db/
│   ├── registration_schema.sql       # Database schema
│   └── apply_registration_schema.php # Migration script
├── includes/
│   ├── DatabaseConnection.php        # DB connection
│   └── RegistrationService.php       # Business logic
├── students/
│   ├── registration.php              # Old system
│   └── registration_redesigned.php   # New UI
├── REGISTRATION_REDESIGN_GUIDE.md    # Full guide
└── REGISTRATION_QUICK_REFERENCE.md   # This file
```

## Testing Commands

```bash
# Apply schema
php db/apply_registration_schema.php

# Test API
curl http://localhost/wucportal/api/registration.php

# Check tables
mysql -u root wucportal -e "SHOW TABLES LIKE '%registration%'"

# Check data
mysql -u root wucportal -e "SELECT * FROM academic_sessions"
```

## Quick Links

- Frontend: `http://localhost/wucportal/students/registration_redesigned.php`
- API Base: `http://localhost/wucportal/api/registration.php`
- Old System: `http://localhost/wucportal/students/registration.php`

---

For detailed information, see [REGISTRATION_REDESIGN_GUIDE.md](REGISTRATION_REDESIGN_GUIDE.md)
