<?php
/**
 * Add Test Fee Data for Exam Registration Testing
 * This script adds fee structure and student payments so students meet the 75% threshold
 */

require_once __DIR__ . '/db/connect.php';

echo "=== Adding Test Fee Data for Exam Registration ===\n\n";

// Step 1: Check student_program table
echo "Step 1: Checking student programs...\n";
$spResult = $db->query("SELECT * FROM student_program");
if (!$spResult || $spResult->num_rows === 0) {
    echo "No student_program records found. Creating...\n";
    
    // Get students and assign them to programs
    $students = $db->query("SELECT SID FROM students");
    $programs = $db->query("SELECT program_code FROM programs LIMIT 1");
    
    if ($programs && $programs->num_rows > 0) {
        $program = $programs->fetch_assoc()['program_code'];
        
        while ($student = $students->fetch_assoc()) {
            $sid = $student['SID'];
            $check = $db->prepare("SELECT id FROM student_program WHERE Sid = ?");
            $check->bind_param("s", $sid);
            $check->execute();
            if ($check->get_result()->num_rows === 0) {
                $stmt = $db->prepare("INSERT INTO student_program (Sid, program_code, intake, mode, semester, startYear, endYear) VALUES (?, ?, '2024', 'Full-Time', 1, 2024, 2028)");
                $stmt->bind_param("ss", $sid, $program);
                $stmt->execute();
                echo "  Added student_program for $sid -> $program\n";
                $stmt->close();
            }
            $check->close();
        }
    }
} else {
    echo "Found " . $spResult->num_rows . " student_program records\n";
}

// Step 2: Add fee_structure entries
echo "\nStep 2: Adding fee structure...\n";

$programs = $db->query("SELECT DISTINCT program_code FROM programs");
$semesterFee = 15000.00;

while ($prog = $programs->fetch_assoc()) {
    $programCode = $prog['program_code'];
    
    for ($year = 1; $year <= 4; $year++) {
        for ($semester = 1; $semester <= 2; $semester++) {
            // Check if already exists
            $check = $db->prepare("SELECT id FROM fee_structure WHERE program_code = ? AND year_of_study = ? AND semester = ?");
            $check->bind_param("sii", $programCode, $year, $semester);
            $check->execute();
            
            if ($check->get_result()->num_rows === 0) {
                $stmt = $db->prepare("INSERT INTO fee_structure (program_code, year_of_study, semester, fee_description, amount, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $description = "Tuition Fee - Year $year Semester $semester";
                $stmt->bind_param("siids", $programCode, $year, $semester, $description, $semesterFee);
                $stmt->execute();
                echo "  Added fee_structure: $programCode Y$year S$semester - K" . number_format($semesterFee, 2) . "\n";
                $stmt->close();
            }
            $check->close();
        }
    }
}

// Step 3: Add student_payments (80% of fees)
echo "\nStep 3: Adding student payments (80% to meet 75% threshold)...\n";

$paymentAmount = $semesterFee * 0.80;
$balance = $semesterFee - $paymentAmount;
$paymentDate = date('Y-m-d H:i:s');
$academicYear = '2025/2026';

$studentPrograms = $db->query("SELECT sp.Sid, sp.program_code FROM student_program sp");

while ($sp = $studentPrograms->fetch_assoc()) {
    $sid = $sp['Sid'];
    $programCode = $sp['program_code'];
    
    for ($year = 1; $year <= 4; $year++) {
        for ($semester = 1; $semester <= 2; $semester++) {
            // Check if payment exists
            $yearStr = (string)$year;
            $semStr = (string)$semester;
            
            $check = $db->prepare("SELECT payment_id FROM student_payments WHERE Sid = ? AND year_of_study = ? AND semester_term = ?");
            $check->bind_param("sss", $sid, $yearStr, $semStr);
            $check->execute();
            
            if ($check->get_result()->num_rows === 0) {
                $refNo = 'PAY-' . strtoupper(substr(md5($sid . $year . $semester . time()), 0, 8));
                $desc = "Tuition payment for Year $year Semester $semester";
                
                $stmt = $db->prepare("INSERT INTO student_payments (Sid, amount_paid, balance, channel, payment_date, academic_year, year_of_study, semester_term, Year, payment_status, reference_number, description, created_at) VALUES (?, ?, ?, 'Bank Transfer', ?, ?, ?, ?, ?, 'completed', ?, ?, NOW())");
                $stmt->bind_param("sddsssssss", $sid, $paymentAmount, $balance, $paymentDate, $academicYear, $yearStr, $semStr, $yearStr, $refNo, $desc);
                $stmt->execute();
                echo "  Added payment: $sid Y$year S$semester - K" . number_format($paymentAmount, 2) . " (80%)\n";
                $stmt->close();
            }
            $check->close();
        }
    }
}

// Summary
echo "\n=== Summary ===\n\n";

$feeCount = $db->query("SELECT COUNT(*) as cnt FROM fee_structure WHERE status = 'active'")->fetch_assoc()['cnt'];
$paymentCount = $db->query("SELECT COUNT(*) as cnt FROM student_payments WHERE payment_status = 'completed'")->fetch_assoc()['cnt'];

echo "Fee Structure records: $feeCount\n";
echo "Student Payment records: $paymentCount\n";

// Test eligibility
echo "\n=== Eligibility Test (Year 1, Semester 1) ===\n\n";

$testQuery = "
    SELECT 
        sp.Sid,
        s.Fname,
        s.Lname,
        sp.program_code,
        COALESCE(fs.amount, 0) as total_fee,
        COALESCE(pay.amount_paid, 0) as total_paid,
        CASE 
            WHEN fs.amount > 0 THEN ROUND((COALESCE(pay.amount_paid, 0) / fs.amount) * 100, 1)
            ELSE 0 
        END as percentage
    FROM student_program sp
    JOIN students s ON sp.Sid = s.SID
    LEFT JOIN fee_structure fs ON sp.program_code = fs.program_code 
        AND fs.year_of_study = 1 AND fs.semester = 1 AND fs.status = 'active'
    LEFT JOIN student_payments pay ON sp.Sid = pay.Sid 
        AND pay.year_of_study = '1' AND pay.semester_term = '1' AND pay.payment_status = 'completed'
";

$testResult = $db->query($testQuery);
if ($testResult) {
    while ($row = $testResult->fetch_assoc()) {
        $eligible = $row['percentage'] >= 75 ? '✓ ELIGIBLE' : '✗ NOT ELIGIBLE';
        echo $row['Sid'] . " - " . trim($row['Fname'] . ' ' . $row['Lname']) . ": " . $row['percentage'] . "% paid - $eligible\n";
    }
}

echo "\n=== Test Fee Data Complete ===\n";
echo "Students can now register for exams!\n";
