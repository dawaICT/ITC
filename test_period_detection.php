<?php
// Test add_fee_structure.php period detection logic
require_once 'db/connect.php';

echo "=== Testing Period Detection Logic ===\n\n";

// Test programs with different configurations
$testPrograms = ['BBA101', 'CS101', 'ENG101'];

foreach ($testPrograms as $program_code) {
    echo "Testing program: $program_code\n";
    
    // Get program details
    $res = $db->query("SELECT * FROM programs WHERE program_code = '$program_code'");
    if ($res && $row = $res->fetch_assoc()) {
        echo "  Program Name: " . $row['program_name'] . "\n";
        echo "  study_mode: " . $row['study_mode'] . "\n";
        echo "  period_mode: " . $row['period_mode'] . "\n";
        echo "  term_based: " . $row['term_based'] . "\n";
        
        // Simulate the detection logic
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
        
        echo "  hasStudyMode: " . ($hasStudyMode ? 'YES' : 'NO') . "\n";
        echo "  hasPeriodMode: " . ($hasPeriodMode ? 'YES' : 'NO') . "\n";
        
        if ($hasStudyMode) {
            if ($ptypeStmt = $db->prepare("SELECT study_mode FROM programs WHERE program_code = ? LIMIT 1")) {
                $ptypeStmt->bind_param('s', $program_code);
                $ptypeStmt->execute();
                $ptypeRes = $ptypeStmt->get_result();
                if ($r = $ptypeRes->fetch_assoc()) { 
                    $period_type = $r['study_mode'] ?: 'semester'; 
                }
                $ptypeStmt->close();
            }
        } elseif ($hasPeriodMode) {
            if ($ptypeStmt = $db->prepare("SELECT period_mode FROM programs WHERE program_code = ? LIMIT 1")) {
                $ptypeStmt->bind_param('s', $program_code);
                $ptypeStmt->execute();
                $ptypeRes = $ptypeStmt->get_result();
                if ($r = $ptypeRes->fetch_assoc()) { 
                    $period_type = $r['period_mode'] ?: 'semester'; 
                }
                $ptypeStmt->close();
            }
        }
        
        $max_period = ($period_type === 'term') ? 3 : 2;
        echo "  Detected period_type: $period_type\n";
        echo "  Max periods: $max_period\n";
        echo "  Expected label: " . ($period_type === 'term' ? 'Term 1, Term 2, Term 3' : 'Semester 1, Semester 2') . "\n";
    } else {
        echo "  ERROR: Program not found\n";
    }
    
    echo "\n";
}

echo "=== Testing a term-based program ===\n";
// Let's create a test term-based program
$db->query("INSERT IGNORE INTO programs (program_code, program_name, program_type, study_mode, period_mode, duration_months, term_based) 
            VALUES ('TEST-TERM', 'Test Term-Based Program', 'diploma', 'term', 'term', 36, 1)");

$res = $db->query("SELECT * FROM programs WHERE program_code = 'TEST-TERM'");
if ($res && $row = $res->fetch_assoc()) {
    echo "Created test program: " . $row['program_name'] . "\n";
    echo "  study_mode: " . $row['study_mode'] . "\n";
    echo "  Should show: Term 1, Term 2, Term 3 (max 3)\n";
}

echo "\n=== Test Complete ===\n";
