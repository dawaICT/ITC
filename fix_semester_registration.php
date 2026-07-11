<?php
/**
 * Fix Semester Registration Issues
 * 
 * Issues to fix:
 * 1. Update academic_periods table to current date (Feb 2026)
 * 2. Ensure column compatibility in check_registration_status.php
 * 3. Align academic year formats between tables
 */

require_once __DIR__ . '/db/connect.php';

// connect.php uses $db, not $conn
$conn = $db;

echo "=== Semester Registration Fix Script ===\n\n";

// 1. Check semester_registration table structure
echo "1. Checking semester_registration columns:\n";
$result = mysqli_query($conn, "SHOW COLUMNS FROM semester_registration");
$semRegCols = [];
while ($row = mysqli_fetch_assoc($result)) {
    $semRegCols[] = $row['Field'];
    echo "   - {$row['Field']} ({$row['Type']})\n";
}
echo "\n";

// Check for Sid column (some legacy queries use this)
$hasSid = in_array('Sid', $semRegCols);
$hasStudentId = in_array('student_id', $semRegCols);
echo "Has 'Sid' column: " . ($hasSid ? "Yes" : "No") . "\n";
echo "Has 'student_id' column: " . ($hasStudentId ? "Yes" : "No") . "\n\n";

// 2. Check academic_periods table
echo "2. Checking academic_periods:\n";
$result = mysqli_query($conn, "SELECT * FROM academic_periods ORDER BY id DESC LIMIT 5");
while ($row = mysqli_fetch_assoc($result)) {
    echo "   ID: {$row['id']}, Year: {$row['academic_year']}, Term: {$row['semester_term']}, ";
    echo "Current: {$row['is_current']}, Status: {$row['status']}\n";
}
echo "\n";

// 3. Check current academic session
echo "3. Current academic session:\n";
$result = mysqli_query($conn, "SELECT * FROM academic_periods WHERE is_current = 1");
$current = mysqli_fetch_assoc($result);
if ($current) {
    echo "   Current: {$current['academic_year']} Term {$current['semester_term']}\n";
    echo "   Start: {$current['start_date']}, End: {$current['end_date']}\n";
} else {
    echo "   WARNING: No current academic period set!\n";
}
echo "\n";

// 4. Check semester_registration data
echo "4. Recent semester_registration records:\n";
$result = mysqli_query($conn, "SELECT * FROM semester_registration ORDER BY id DESC LIMIT 5");
while ($row = mysqli_fetch_assoc($result)) {
    $sid = $row['student_id'] ?? $row['Sid'] ?? 'N/A';
    $year = $row['academic_year'] ?? 'N/A';
    $sem = $row['semester'] ?? $row['semester_term'] ?? 'N/A';
    echo "   ID: {$row['id']}, Student: {$sid}, Year: {$year}, Semester: {$sem}\n";
}
echo "\n";

// 5. Fix: Update academic_periods to current date
echo "5. Fixing academic_periods...\n";

// First, unset any current period
mysqli_query($conn, "UPDATE academic_periods SET is_current = 0");
echo "   Reset all is_current flags to 0\n";

// Check if 2025-2026 period exists
$result = mysqli_query($conn, "SELECT id FROM academic_periods WHERE academic_year = '2025-2026' AND semester_term = '2'");
if (mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    // Update existing record
    $sql = "UPDATE academic_periods SET 
            is_current = 1, 
            status = 'active',
            start_date = '2026-01-15',
            end_date = '2026-05-15'
            WHERE id = {$row['id']}";
    mysqli_query($conn, $sql);
    echo "   Updated existing 2025-2026 Term 2 period as current\n";
} else {
    // Check for Term 1
    $result = mysqli_query($conn, "SELECT id FROM academic_periods WHERE academic_year = '2025-2026' AND semester_term = '1'");
    if (mysqli_num_rows($result) > 0) {
        // Insert Term 2
        $sql = "INSERT INTO academic_periods (academic_year, semester_term, start_date, end_date, is_current, status)
                VALUES ('2025-2026', '2', '2026-01-15', '2026-05-15', 1, 'active')";
        mysqli_query($conn, $sql);
        echo "   Created new 2025-2026 Term 2 period\n";
    } else {
        // Insert both terms
        $sql = "INSERT INTO academic_periods (academic_year, semester_term, start_date, end_date, is_current, status)
                VALUES ('2025-2026', '1', '2025-09-01', '2025-12-15', 0, 'completed')";
        mysqli_query($conn, $sql);
        echo "   Created 2025-2026 Term 1 period\n";
        
        $sql = "INSERT INTO academic_periods (academic_year, semester_term, start_date, end_date, is_current, status)
                VALUES ('2025-2026', '2', '2026-01-15', '2026-05-15', 1, 'active')";
        mysqli_query($conn, $sql);
        echo "   Created 2025-2026 Term 2 period as current\n";
    }
}

// Verify the fix
echo "\n6. Verification - Current academic period:\n";
$result = mysqli_query($conn, "SELECT * FROM academic_periods WHERE is_current = 1");
$current = mysqli_fetch_assoc($result);
if ($current) {
    echo "   Current: {$current['academic_year']} Term {$current['semester_term']}\n";
    echo "   Start: {$current['start_date']}, End: {$current['end_date']}\n";
    echo "   Status: {$current['status']}\n";
} else {
    echo "   ERROR: Failed to set current academic period!\n";
}

echo "\n=== Fix Complete ===\n";
