# Transfer Student Registration - Quick Reference Guide

## System Overview

The Transfer Student Registration system provides a streamlined 3-step form for quick student entry and a comprehensive management table for all transfer students.

## Quick Start

### For Staff: Register a Transfer Student
1. Go to Admissions → **Transfer Students** (sidebar)
2. Click **Register** button
3. **Step 1:** Enter Full Name, NRC, DOB, Academic Year → Click Next
4. **Step 2:** Enter Mobile, Email, School, Credits → Click Next
5. **Step 3:** Enter Address, Next of Kin, Photo → Click Submit
6. ✅ Student registered! Auto-generated Student ID appears in table

### For Staff: Manage Transfer Students

| Action | How | Result |
|--------|-----|--------|
| **View Details** | Click 👁️ icon | Opens student profile |
| **Edit** | Click ✏️ icon | Edit name, contact, address, etc. |
| **Admit** | Click ✓ icon (yellow badge) | Assigns to program/intake |
| **Delete** | Click 🗑️ icon | 2-step delete confirmation |
| **Refresh** | Click 🔄 button | Reloads table with latest students |

## Form Fields

### Step 1: Personal Information
- **Full Name*** (e.g., "John Doe") → Auto-splits to Fname/Lname
- **NRC Number*** (e.g., "123456/89/1")
- **Date of Birth*** (e.g., "1995-05-15")
- **Academic Year*** (e.g., "2024")

### Step 2: Contact & Transfer
- **Mobile Number*** (e.g., "+260123456789")
- **Email Address** (optional)
- **Previous Institution*** (e.g., "University of Zambia")
- **Transfer Credits*** (e.g., "60")

### Step 3: Additional Details
- **Home Address*** (e.g., "123 Main Street, Lusaka")
- **Next of Kin Name*** (e.g., "Jane Doe")
- **Next of Kin Mobile*** (e.g., "+260987654321")
- **Profile Picture*** (JPG/PNG only)

*Fields marked with * are required

## Status Indicators

| Badge | Meaning | Actions Available |
|-------|---------|-------------------|
| 🟢 **Admitted** | Student enrolled in program | View, Edit, Delete |
| 🟡 **Pending** | Not yet admitted | View, Edit, **Admit**, Delete |

## Automatic Features

- ✅ Student ID auto-generated (10-digit unique code)
- ✅ Full name auto-split into first/last
- ✅ Fields auto-populated: Title, Gender, Country, Status
- ✅ is_transfer flag automatically set to 1
- ✅ Admission date auto-recorded
- ✅ Form resets when modal reopens

## Button Locations

### Top of Page
- **Register** - Open quick registration modal (green button)

### Transfer Students Table
- **Refresh** - Reload table with latest records
- **View** - Display full student details
- **Edit** - Modify student information
- **Admit** - Assign to program (yellow badge only)
- **Delete** - Remove student record

### Student Details Page
- **Back** - Return to Transfer Students list
- **Edit** - Modify student record
- **Admit** - Enroll in program (if pending)

### Edit Student Page
- **Back to Transfer Students** - Return without saving
- **Update Student** - Save changes and return

### Delete Student Page
- **Cancel** - Return to Transfer Students
- **Confirm Delete** - Proceed to final confirmation
- **Yes, Delete Permanently** - Complete deletion (irreversible)

## Database Student ID Format

**Format:** YY + SS + II + SSSS

Example: **2401310001**

- **24** = Year (2024)
- **01** = Month (January)
- **31** = Program code
- **0001** = Student number

## Form Validation Rules

### Step 1 Validation
- Full Name: Required, cannot skip to Step 2
- NRC: Required
- DOB: Required
- Year: Required

### Step 2 Validation
- Mobile: Required
- School: Required
- Credits: Required, must be number
- Email: Optional

### Step 3 Validation
- Address: Required
- Next of Kin: Required
- Next of Kin Mobile: Required
- Photo: Required (JPG/PNG)

**Prevention:** Cannot advance to next step without filling required fields

## Success Messages

After successful actions, you'll see:
- "Student registered successfully!" - After form submission
- "Student record updated successfully!" - After edit
- "Student record deleted successfully!" - After deletion

**Message Location:** Appears at top of page as green alert

## Error Messages

If something goes wrong:
- "Please fill all required fields." - Missing required field
- "Database error: ..." - Database connection issue
- "Student not found." - Invalid Student ID
- "Invalid image format." - Wrong file type for photo

## Sidebar Navigation

**Before Update:**
- Admissions → Register Returning Student

**After Update:**
- Admissions → **Transfer Students** (with 🔄 icon)

Click this menu item to access the transfer student dashboard.

## File Locations

### Main Page
- `/admissions/regOldStud.php` - Quick form & student table

### Helper Pages
- `/admissions/viewStudent.php` - View student details
- `/admissions/editStudent.php` - Edit student record
- `/admissions/admitStudent.php` - Admit to program
- `/admissions/deleteStudent.php` - Delete student

### Backend
- `/admissions/processOldForm.php` - Form processing & ID generation
- `/admissions/includes/nav.php` - Sidebar menu
- `/db/connect.php` - Database connection

### Uploads
- `/uploads/profile/` - Student profile pictures

## Keyboard Shortcuts

- **Enter** in form field - Moves to next field
- **Tab** - Move through form fields
- **Escape** - Close registration modal
- **Ctrl+R** in table - Refresh table

## Known Limitations

1. Transfer student table shows 20 most recent (not paginated)
2. Table doesn't auto-refresh (click Refresh button)
3. Profile photo required (no placeholder)
4. Deletion is permanent (no undo)
5. No bulk action support (admit/delete one at a time)

## FAQ

**Q: Can I change the NRC after registration?**
A: No, NRC field is locked in edit form to prevent duplicate entries.

**Q: What if I delete a student by mistake?**
A: Currently no undo feature. Ensure to confirm twice before deleting.

**Q: How many credits can I transfer?**
A: No limit enforced. Enter the number of credits to transfer.

**Q: What image formats are supported?**
A: JPG and PNG only. Max recommended size: 5MB.

**Q: Can I register without a profile photo?**
A: No, photo is required. You can re-edit later to add one.

**Q: Where are profile photos stored?**
A: In `/uploads/profile/` directory on the server.

**Q: How long does it take for a student to appear in the table?**
A: Instantly after successful registration. Click Refresh if needed.

**Q: Can students register themselves?**
A: No, this is staff-only. Public registration uses different form.

**Q: Is there a limit to how many transfer students can be registered?**
A: No technical limit. Table shows 20 most recent.

**Q: What happens when I admit a transfer student?**
A: Student is enrolled in selected program/intake. Status changes to "Admitted".

**Q: Can I edit a student after admission?**
A: Yes, basic details can be edited anytime except NRC.

## Support & Troubleshooting

**Problem:** "No student ID provided" error
- **Check:** URL should be `editStudent.php?sid=XXXX` not `?edit=XXXX`
- **Fix:** Click correct button from Transfer Students table

**Problem:** Form won't advance to next step
- **Check:** All red-marked required fields filled
- **Fix:** Look for empty fields and complete them

**Problem:** Student doesn't appear in table after registration
- **Check:** Is is_transfer flag set to 1?
- **Fix:** Click Refresh button to reload table

**Problem:** Delete button not working
- **Check:** Do you have permission to delete?
- **Fix:** Confirm deletion in both dialog screens

**Problem:** Can't upload profile photo
- **Check:** Is `/uploads/profile/` directory created?
- **Fix:** Create directory and ensure it's writable (755 permissions)

**Problem:** Sidebar shows old "Register Returning Student" label
- **Check:** Browser cache not cleared
- **Fix:** Hard refresh (Ctrl+Shift+R) or clear browser cache

---

**For Complete Documentation:** See [TRANSFER_STUDENT_IMPLEMENTATION.md](TRANSFER_STUDENT_IMPLEMENTATION.md)
