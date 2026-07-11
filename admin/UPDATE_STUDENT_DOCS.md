# Update Student Module Documentation

## File: `update_student.php`

### Purpose
Provides a comprehensive interface for updating existing student records in the WUC Portal, including personal information, contact details, academic program assignments, and document uploads.

### Location
`admin/update_student.php`

### Features

#### 1. **Student Information Update**
- Personal details (First Name, Last Name, Gender, Date of Birth)
- Contact information (Email, Phone, NRC/Passport)
- Academic program assignment
- Semester/Term enrollment

#### 2. **Document Management**
- Profile image upload (JPG, PNG, GIF)
- NRC document upload (PDF, JPG, PNG)
- Academic results document upload (PDF, JPG, PNG)
- Auto-replacement of old files with timestamped versions
- File type validation for security

#### 3. **Data Validation**
- Duplicate phone number detection (excluding current student)
- Duplicate NRC detection (excluding current student)
- Required field validation
- Client-side validation for phone (10-13 digits) and email format
- Server-side validation with proper error messages

#### 4. **Database Operations**
- Transaction-based updates (rollback on error)
- Updates `students` table
- Updates/creates `student_program` table records
- Proper prepared statements for SQL injection prevention

### Usage

#### Access the Page
```
http://localhost/wucportal/admin/update_student.php?sid=STUDENT_ID
```

#### URL Parameters
- `sid` (required): The student ID to update

### Form Fields

#### Required Fields
- **First Name**: Student's first name
- **Last Name**: Student's last name
- **Gender**: M (Male) or F (Female)
- **Date of Birth**: Student's birth date (YYYY-MM-DD format)
- **Email**: Valid email address
- **Phone**: Phone number (10-13 digits)
- **NRC/Passport**: National Registration Card or Passport number

#### Optional Fields
- **Program**: Academic program enrollment
- **Semester/Term**: Current semester or term (1, 2, or 3)
- **Profile Image**: Student photo (updates if provided)
- **NRC Document**: Scanned NRC/Passport (updates if provided)
- **Results Document**: Academic results (updates if provided)

### File Upload Specifications

#### Profile Image
- **Allowed types**: JPG, JPEG, PNG, GIF
- **Max size**: 5MB (recommended)
- **Storage**: `admin/uploads/profile_images/`
- **Naming**: `profile_STUDENTID_TIMESTAMP.ext`

#### NRC Document
- **Allowed types**: PDF, JPG, JPEG, PNG
- **Max size**: 5MB (recommended)
- **Storage**: `admin/uploads/nrc/`
- **Naming**: `nrc_STUDENTID_TIMESTAMP.ext`

#### Results Document
- **Allowed types**: PDF, JPG, JPEG, PNG
- **Max size**: 5MB (recommended)
- **Storage**: `admin/uploads/results/`
- **Naming**: `results_STUDENTID_TIMESTAMP.ext`

### Security Features

1. **Session-based authentication**: Requires admin login via `includes/admin.php`
2. **SQL Injection prevention**: All queries use prepared statements
3. **XSS prevention**: All output uses `htmlspecialchars()`
4. **File type validation**: Whitelisted MIME types only
5. **Transaction safety**: Database rollback on any error

### Error Handling

#### Common Errors

**Student Not Found**
```
Error: Student not found.
→ Redirects to students_by_admin.php
```

**Duplicate Phone**
```
Error: Phone number already registered to another student.
→ Form stays open with error message
```

**Duplicate NRC**
```
Error: NRC already registered to another student.
→ Form stays open with error message
```

**Invalid File Type**
```
Error: Invalid image file type. Only JPG, PNG, and GIF allowed.
→ Form stays open with error message
```

**Database Error**
```
Error: Update failed: [specific error message]
→ Transaction rolled back, form stays open
```

### Success Flow

1. User navigates to `update_student.php?sid=STUDENTID`
2. System loads student data from database
3. Form is pre-populated with current values
4. User makes changes
5. User clicks "Update Student"
6. System validates all input
7. Files are uploaded (if provided)
8. Database transaction begins
9. Student record updated
10. Program assignment updated/created
11. Transaction committed
12. Success message displayed
13. Redirect to `students_by_admin.php`

### Database Schema Requirements

#### `students` Table
Required columns:
- `SID` (varchar) - Primary Key
- `Fname` (varchar)
- `Lname` (varchar)
- `sex` (varchar/enum)
- `dob` (date)
- `email` (varchar)
- `mobile` (varchar)
- `nrc_pass` (varchar)
- `profile_image` (varchar)
- `nrc_file` (varchar)
- `results` (varchar)

#### `student_program` Table
Required columns:
- `Sid` (varchar) - Foreign Key to students.SID
- `program_code` (varchar) - Foreign Key to programs
- `intake` (int) - Academic year
- `term` (int) - Semester/Term number

### Integration Points

#### Called By
- [students_by_admin.php](students_by_admin.php) (Edit button)
- [view_student.php](view_student.php) (Edit link)
- [regNewStud.php](regNewStud.php) (Edit after registration)

#### Redirects To
- `students_by_admin.php` (on success or error without student ID)
- Self (on validation error)

#### Includes
- `includes/admin.php` - Admin authentication and DB connection
- `includes/header.php` - Page header and navigation
- `includes/footer.php` - Page footer and scripts

### Testing

#### Manual Testing Steps
1. Navigate to the page with a valid student ID
2. Verify all fields are pre-populated
3. Try updating each field type
4. Test file uploads
5. Test duplicate phone detection
6. Test duplicate NRC detection
7. Verify transaction rollback on error

#### Automated Testing
Run the test script:
```bash
php admin/test_update_student.php
```

### Troubleshooting

**Issue: Page shows "Student ID is required"**
- Solution: Ensure the URL includes `?sid=STUDENTID` parameter

**Issue: Images not uploading**
- Check that `admin/uploads/` directory exists
- Verify write permissions on upload directories
- Check file size limits in php.ini

**Issue: "Failed to load student" error**
- Verify student ID exists in database
- Check database connection in `includes/admin.php`

**Issue: Form validation not working**
- Ensure JavaScript is enabled in browser
- Check browser console for errors

### Changelog

**Version 1.0** (Current)
- Initial creation
- Full CRUD functionality for students
- File upload support
- Duplicate detection
- Transaction safety
- XSS and SQL injection protection

### Future Enhancements
- Bulk student updates
- Student history tracking (audit log)
- Photo cropping functionality
- Advanced search and filtering
- Export student data to PDF
- Email notification on updates

### Related Files
- `regNewStud.php` - Student registration
- `students_by_admin.php` - Student listing
- `view_student.php` - Student profile view
- `editStudent.php` - Legacy edit (may be deprecated)
- `includes/admin.php` - Admin authentication

### Support
For issues or questions, check:
1. Error logs at `logs/error.log`
2. PHP error logs
3. Database query logs
4. Session data in `includes/admin.php`
