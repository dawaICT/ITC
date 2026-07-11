# WUC Portal - Student Data Structure

## Overview
The `students` table has been redesigned to provide a comprehensive data structure for storing complete student information in the WUC Portal system.

## Table Structure: `students`

### Primary Key
- `SID` (VARCHAR(50)) - Unique Student Identification Number

### Personal Information
- `Fname` (VARCHAR(100)) - First Name (Required)
- `Lname` (VARCHAR(100)) - Last Name (Required)
- `sex` (ENUM('M', 'F')) - Gender (Required)
- `dob` (DATE) - Date of Birth
- `country` (VARCHAR(100)) - Country of Origin

### Identification & Contact
- `nrc_pass` (VARCHAR(50)) - NRC/Passport Number
- `mobile` (VARCHAR(20)) - Mobile Phone Number
- `email` (VARCHAR(100)) - Email Address

### Address Information
- `h_addre` (TEXT) - Home Address
- `p_addre` (TEXT) - Postal Address

### Academic & Administrative
- `status` (VARCHAR(50)) - Student Status (Default: 'Active')
  - Possible values: 'Active', 'Inactive', 'Suspended', 'Graduated'
- `dte_adm` (DATE) - Date of Admission

### Family/Guardian Information
- `sponsor` (VARCHAR(100)) - Sponsor Name
- `next_kin` (VARCHAR(100)) - Next of Kin Name
- `next_kin_mobile` (VARCHAR(20)) - Next of Kin Contact Number
- `relat` (VARCHAR(50)) - Relationship to Next of Kin

### System Fields
- `profile_image` (VARCHAR(255)) - Profile Image Path (Default: 'default.jpg')
- `created_at` (TIMESTAMP) - Record Creation Timestamp
- `updated_at` (TIMESTAMP) - Last Update Timestamp

## Indexes
- `PRIMARY KEY (SID)`
- `idx_students_name (Fname, Lname)` - For name-based searches
- `idx_students_email (email)` - For email lookups
- `idx_students_mobile (mobile)` - For mobile number searches
- `idx_students_status (status)` - For status-based filtering

## Data Validation Rules

### Required Fields
- SID (Student ID)
- Fname (First Name)
- Lname (Last Name)
- sex (Gender)

### Format Validation
- Email: Must be valid email format
- Mobile: Phone number format
- Date fields: Valid date format
- SID: Alphanumeric characters only

### Business Rules
- SID must be unique across all students
- Gender must be either 'M' or 'F'
- Status must be one of the predefined values

## Relationships
- **student_program**: Links students to their program enrollments
  - Foreign Key: `students.SID` → `student_program.Sid`
- **Potential future relationships**:
  - Fee payments
  - Course enrollments
  - Examination results
  - Library records

## Usage Examples

### Insert New Student
```sql
INSERT INTO students (SID, Fname, Lname, sex, email, mobile, status)
VALUES ('2024001', 'John', 'Doe', 'M', 'john.doe@example.com', '26097123456', 'Active');
```

### Update Student Information
```sql
UPDATE students
SET email = 'new.email@example.com',
    mobile = '26097987654',
    updated_at = CURRENT_TIMESTAMP
WHERE SID = '2024001';
```

### Search Students
```sql
SELECT * FROM students
WHERE Fname LIKE '%John%'
   OR Lname LIKE '%Doe%'
   OR email = 'john.doe@example.com';
```

## Security Considerations
- All database operations use prepared statements to prevent SQL injection
- Input validation is performed both client-side and server-side
- Sensitive data (if any) should be encrypted at rest
- Access controls should be implemented at the application level

## Maintenance Notes
- Regular backups of the students table are recommended
- Monitor index performance and adjust as needed
- Consider partitioning for large datasets (if applicable)
- Audit trail for updates can be implemented using triggers