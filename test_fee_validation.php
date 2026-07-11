<?php
// Test add_fee_structure.php functionality
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once 'db/connect.php';

echo "=== Testing Fee Structure Add Functionality ===\n\n";

// Test 1: Semester-based program (BBA101)
echo "Test 1: Adding fee for semester-based program (BBA101)\n";
$program_code = 'BBA101';
$year = 1;
$semester = 1;

$period_type = 'semester';
$hasStudyMode = false;
$hasPeriodMode = false;

if ($stmt = $db->query("SHOW COLUMNS FROM programs LIKE 'study_mode'")) {
    $hasStudyMode = ($stmt->num_rows > 0);
    $stmt->free();
}
if ($stmt = $db->query("SHOW COLUMNS FROM programs LIKE 'period_mode'")) {
    $hasPeriodMode = ($stmt->num_rows > 0);
    $stmt->free();
}

if ($hasStudyMode) {
    if ($ptypeStmt = $db->prepare("SELECT study_mode FROM programs WHERE program_code = ? LIMIT 1")) {
        $ptypeStmt->bind_param('s', $program_code);
        $ptypeStmt->execute();
        $ptypeRes = $ptypeStmt->get_result();
        if ($row = $ptypeRes->fetch_assoc()) { 
            $period_type = $row['study_mode'] ?: 'semester'; 
        }
        $ptypeStmt->close();
    }
} elseif ($hasPeriodMode) {
    if ($ptypeStmt = $db->prepare("SELECT period_mode FROM programs WHERE program_code = ? LIMIT 1")) {
        $ptypeStmt->bind_param('s', $program_code);
        $ptypeStmt->execute();
        $ptypeRes = $ptypeStmt->get_result();
        if ($row = $ptypeRes->fetch_assoc()) { 
            $period_type = $row['period_mode'] ?: 'semester'; 
        }
        $ptypeStmt->close();
    }
}

$max_period = ($period_type === 'term') ? 3 : 2;
echo "  Program: $program_code\n";
echo "  Detected period_type: $period_type\n";
echo "  Max periods: $max_period\n";
echo "  Validation: Semester $semester is " . ($semester <= $max_period ? 'VALID' : 'INVALID') . "\n\n";

// Test 2: Term-based program (CS101)
echo "Test 2: Adding fee for term-based program (CS101)\n";
$program_code = 'CS101';
$year = 2;
$term = 3;

$period_type = 'semester';
if ($hasStudyMode) {
    if ($ptypeStmt = $db->prepare("SELECT study_mode FROM programs WHERE program_code = ? LIMIT 1")) {
        $ptypeStmt->bind_param('s', $program_code);
        $ptypeStmt->execute();
        $ptypeRes = $ptypeStmt->get_result();
        if ($row = $ptypeRes->fetch_assoc()) { 
            $period_type = $row['study_mode'] ?: 'semester'; 
        }
        $ptypeStmt->close();
    }
}

$max_period = ($period_type === 'term') ? 3 : 2;
echo "  Program: $program_code\n";
echo "  Detected period_type: $period_type\n";
echo "  Max periods: $max_period\n";
echo "  Validation: Term $term is " . ($term <= $max_period ? 'VALID' : 'INVALID') . "\n\n";

// Test 3: Invalid semester for term program
echo "Test 3: Validation - Invalid period for term-based program\n";
$program_code = 'CS101';
$invalid_period = 4;

echo "  Program: $program_code (term-based, max 3 terms)\n";
echo "  Attempting period: $invalid_period\n";
echo "  Expected: INVALID\n";
echo "  Actual: " . ($invalid_period > $max_period ? 'INVALID ✓' : 'VALID ✗') . "\n\n";

// Test 4: Valid third term for term program
echo "Test 4: Valid third term for term-based program\n";
$program_code = 'CS101';
$valid_period = 3;

echo "  Program: $program_code (term-based, max 3 terms)\n";
echo "  Attempting period: $valid_period\n";
echo "  Expected: VALID\n";
echo "  Actual: " . ($valid_period <= $max_period ? 'VALID ✓' : 'INVALID ✗') . "\n\n";

echo "=== All Tests Complete ===\n";
