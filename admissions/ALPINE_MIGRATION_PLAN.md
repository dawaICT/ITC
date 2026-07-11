# 🚀 Alpine.js Migration Roadmap

This project is transitioning from a mix of PHP Server-Side Rendering (SSR) and jQuery to a modern **Alpine.js + Axios** architecture. This improves interactivity, reduces server load, and provides a cleaner codebase.

## ✅ Completed

### 1. **Student Records** (`admissions/students.php`)
- **Status:** ✅ Complete
- **Changes:**
  - Replaced PHP loops with `x-for`.
  - Implemented `student_handlers.php` for API endpoints.
  - Added "Admit Student" modal with Alpine logic.
  - Fixed Axios loading order issues.
  - Fixed Database column mismatches.

### 2. **Online Applications** (`admissions/applicants.php`)
- **Status:** ✅ Complete
- **Changes:**
  - Removed jQuery DataTables.
  - Implemented client-side filtering/searching with Alpine.
  - Moved backend logic to `includes/applicant_handlers.php`.
  - Standardized Modal logic using Alpine state.

---

## 🚧 Pending Migration (Priority Order)

### 3. **New Student Registration** (`admissions/regNewStud.php`)
- **Status:** ✅ Complete
- **Changes:**
  - Created `includes/registration_handlers.php` for backend logic.
  - Implemented `includes/registration_modal_alpine.php` for the form.
  - Converted `regNewStud.php` to use Alpine.js + Axios.
  - Added real-time validation and multi-step form state management.

### 4. **Returning Student Registration** (`admissions/regOldStud.php`)
- **Status:** ✅ Complete
- **Changes:**
  - Standardized as "Transfer Student Registration" (based on actual code logic).
  - Created `includes/transfer_handlers.php` for backend logic.
  - Implemented `includes/transfer_modal_alpine.php`.
  - Refactored `regOldStud.php` to use Alpine.js `transferApp`.
  - Implemented Client-side CSV processing for Bulk Imports.

### 5. **Dashboard** (`admissions/index.php`)
- **Current State:** PHP-rendered stats.
- **Goal:** Dynamic dashboard with live updates.
- **Tasks:**
  - Create `includes/dashboard_handlers.php` (get stats).
  - Use `x-init` to fetch stats on load.

### 6. **Reports** (`admissions/reports.php`)
- **Current State:** Likely PHP-rendered tables.
- **Goal:** Dynamic sorting and filtering.
- **Tasks:**
  - Implement dynamic report generation APIs.
  - Use Alpine for report parameter selection and display.

---

## 🛠 Migration Pattern Checklist

For every file being converted:

1.  **Extract Backend Logic**
    - Move PHP logic (queries, processing) to `includes/filename_handlers.php`.
    - Ensure logical separation (Service layer).
    - Return format: JSON `['success' => true, 'data' => ...]`.

2.  **Frontend Structure**
    - Remove PHP `foreach` loops.
    - Add `<div x-data="componentName()">`.
    - Use `<template x-for="...">` for lists.
    - Use `x-model` for inputs.

3.  **Dependencies**
    - Ensure Axios and SweetAlert2 are loaded *synchronously* (no defer).
    - Ensure Alpine.js is loaded *deferred*.

4.  **State Management**
    - Define state: `loading`, `data`, `search`, `modalState`.
    - Use `init()` to fetch initial data.

5.  **Clean Up**
    - Remove unused jQuery scripts.
    - Remove unused PHP variables/logic from the view file.

---
**Last Updated:** 2026-02-08
