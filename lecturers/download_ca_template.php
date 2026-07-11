<?php
require_once __DIR__ . '/includes/guard.php';
/**
 * CA Upload CSV Template Generator
 * Generates a pre-formatted CSV template for bulk CA uploads
 * Prevents header mismatch errors and guides lecturers on proper formatting
 */

// Define the filename with timestamp
$filename = "CA_Upload_Template_" . date('Ymd_His') . ".csv";

// Set headers to force download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Pragma: no-cache');
header('Expires: 0');

// Open the output stream
$output = fopen('php://output', 'w');

// Write BOM for Excel UTF-8 compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Set the Column Headers
// These MUST match the structure expected by upload_ca_csv.php
fputcsv($output, [
    'SID',          // Student ID (e.g., S123456)
    'Course_Code',  // Course code (e.g., CSC101)
    'A1',           // Assignment 1 local CA mark out of 100
    'A2',           // Assignment 2 local CA mark out of 100
    'A3',           // Assignment 3 mark (term-based only, out of 100)
    'T1',           // Test mark out of 100
    'T2',           // Test 2 mark (optional)
    'semester',     // Semester/Term (1, 2, or 3)
    'Year'          // Academic year (e.g., 2026)
]);

// Add sample rows for SEMESTER-based courses
fputcsv($output, [
    'S202401',      // Example student ID
    'CSC101',       // Example course code
    '82.50',        // A1: local CA mark out of 100
    '75.00',        // A2: local CA mark out of 100
    '',             // A3: Leave blank for semester courses
    '70.00',        // T1: local CA mark out of 100
    '',             // T2: Leave blank if not used
    '1',            // Semester 1
    date('Y')       // Academic year
]);

fputcsv($output, [
    'S202402',
    'CSC101',
    '68.00',
    '72.50',
    '',
    '80.00',
    '',
    '1',
    date('Y')
]);

// Add separator comment row (will be ignored by most CSV parsers if skipped)
fputcsv($output, ['--- TERM-BASED EXAMPLE ---', '', '', '', '', '', '', '', '', '']);

// Add sample rows for TERM-based courses
fputcsv($output, [
    'S202403',
    'ENG201',       // Term-based course
    '75',           // A1: Direct entry (0-100)
    '82',           // A2: Direct entry (0-100)
    '68',           // A3: Direct entry (0-100, term-based only)
    '78',           // T1: Direct entry (0-100)
    '',             // T2: Leave blank if not used
    '2',            // Term 2
    date('Y')       // Academic year
]);

// Add instructions as comment rows (lecturers should delete these before upload)
fputcsv($output, []);
fputcsv($output, ['INSTRUCTIONS:', '', '', '', '', '', '', '', '', '']);
fputcsv($output, ['1. Delete all example rows and instruction rows before uploading', '', '', '', '', '', '', '', '', '']);
fputcsv($output, ['2. All CA components are local marks out of 100. Do not enter external examination marks here.', '', '', '', '', '', '', '', '']);
fputcsv($output, ['3. TERM courses: use A1, A2, A3, T1, and T2 only where they apply.', '', '', '', '', '', '', '', '']);
fputcsv($output, ['4. Leave unused CA component cells blank.', '', '', '', '', '', '', '', '']);
fputcsv($output, ['5. Use semester values: 1, 2, or 3 (for terms)', '', '', '', '', '', '', '', '', '']);
fputcsv($output, ['6. Use the academic year used in course registration, for example ' . date('Y'), '', '', '', '', '', '', '', '', '']);
fputcsv($output, ['7. Do NOT include % symbols or text in score columns', '', '', '', '', '', '', '', '', '']);
fputcsv($output, ['8. Ensure Student IDs match exactly as in the system', '', '', '', '', '', '', '', '', '']);

fclose($output);
exit;
