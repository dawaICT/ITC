EXPANDED REGISTRATION FORM - SUMMARY

Updated File: admissions/regNewStud.php

=== NEW SECTIONS ADDED ===

The student registration form has been expanded from 2 steps to 6 steps:

STEP 1: Personal Information
--------
Fields:
  - First Name (required)
  - Last Name (required)
  - Gender (required)
  - Date of Birth (required, min 16 years)
  - Email Address (required)
  - Phone Number (required, min 9 digits)
  - NRC Number (required, min 6 digits)
  - Residential Address (optional)

STEP 2: Next of Kin Information
--------
Fields:
  - First Name (required)
  - Last Name (required)
  - Relationship (required) - Parent, Sibling, Spouse, Guardian, Other
  - Phone Number (required, min 9 digits)
  - Email Address (optional)
  - Address (optional)

STEP 3: Academic Information
--------
Fields:
  - Program (required)
  - Academic Year (readonly, auto-filled with current year)
  - Semester/Term (required, dynamically populated based on program)
  - Previous School/Institution (optional)
  - Entry Qualification (optional) - GCSE, A-Level, Diploma, Certificate, Other
  - Year of Entry (optional)

STEP 4: Bursary Information
--------
Fields:
  - Bursary Type (optional) - Full, Partial, Merit-Based, None
  - Bursary Sponsor/Organization (optional)
  - Additional Bursary Notes (optional, textarea)
  - Employment Status (optional) - Employed, Self-Employed, Unemployed, Full-Time Student
  - Monthly Income in ZMW (optional, numeric)

STEP 5: Document Uploads
--------
Fields:
  - Profile Photo (required) - JPG/PNG, 5MB max
  - Academic Results/Transcript (required) - PDF/JPG/PNG, 5MB max
  - National ID / Passport Copy (optional) - PDF/JPG/PNG, 5MB max
  - Entry Certificate/Qualification (optional) - PDF/JPG/PNG, 5MB max

STEP 6: Review Information
--------
Displays comprehensive review with:
  - Personal Information Card
  - Next of Kin Card
  - Academic Information Card
  - Bursary Information Card
  - Documents Status (with upload badges)
  - Selected Courses Table with totals
  - Final confirmation message

=== VALIDATION FEATURES ===

✓ Real-time validation for:
  - NRC: minimum 6 digits
  - Phone numbers: minimum 9 digits
  - Age: must be at least 16 years old
  - Email: valid email format
  - File uploads: maximum 5MB per file
  - Required fields: checked on each step

✓ Dynamic behavior:
  - Semester/Term dropdown changes based on program period_mode
  - Courses load automatically when program and semester selected
  - Document upload status shown with badges
  - Phone number formatting (numeric + signs allowed)

=== FORM FEATURES ===

✓ Multi-step with progress indicators (6 steps)
✓ Next/Previous navigation buttons
✓ Form validation before proceeding
✓ File upload support with size validation
✓ Comprehensive review section
✓ Dynamic course loading based on program/semester
✓ Mobile responsive design
✓ Bootstrap styling

=== BACKEND CHANGES NEEDED ===

NOTE: The following backend updates are still needed to fully handle the new fields:

1. Database Schema Updates:
   - Add columns to students table for next of kin info
   - Add columns for academic history (previous school, qualification, entry year)
   - Add bursary related columns
   - Add file upload storage path columns

2. File Upload Handling:
   - Create upload directory: /uploads/student_documents/
   - Handle file storage and validation in POST handler
   - Link uploaded files to student records

3. Form Submission Handler:
   - Update POST handler (line 167+) to process and store all new fields
   - Add file upload processing
   - Update database INSERT statements for additional columns
   - Add error handling for file uploads

4. Database Migrations:
   - Create migration script to add new columns to students table
   - Update invoice/course association logic if needed

=== TESTING NOTES ===

The form has been tested for:
✓ PHP syntax errors
✓ Form structure and layout
✓ Client-side validation functions
✓ Step navigation logic
✓ Review information population
✓ File upload field presence

Next steps for full functionality:
- Implement POST handler for new fields
- Create database migration for additional columns
- Set up file upload directory and handlers
- Test complete registration workflow

=== FORM DISPLAY ===

Modal Title: "New Student Registration"
Form Enctype: multipart/form-data (required for file uploads)
Progress Bar: Shows 6 steps with visual indicators
Current Date: Uses server date (January 25, 2026)
