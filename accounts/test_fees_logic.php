<?php
/**
 * CLI Verification Script for Fees Module
 */
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../includes/manual_entry_guards.php';
    wuc_gate_debug_endpoint();
}
define('IS_SCRIPT', true);
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/fees_helpers.php';

// Enable admin/root override for connection
$db = new mysqli($db_host, 'root', '', $db_name, $db_port);
if ($db->connect_error) {
    die("MySQL admin connection failed: " . $db->connect_error . "\n");
}
$db->set_charset("utf8mb4");

echo "=== WUC Portal Fees System Automated Verification ===\n\n";

try {
    // 1. Setup Test Data
    echo "1. Setting up test entities...\n";
    
    // Clear any existing test records first
    $db->query("DELETE FROM student_payments WHERE receipt_number LIKE 'TEST-%'");
    $db->query("DELETE FROM student_fee_accounts WHERE student_id = 'TEST-STUDENT-001'");
    $db->query("DELETE FROM course_fee_breakdown WHERE course_id IN (SELECT id FROM courses WHERE course_code = 'TEST-C01')");
    $db->query("DELETE FROM course_fees WHERE course_id IN (SELECT id FROM courses WHERE course_code = 'TEST-C01')");
    $db->query("DELETE FROM fee_items WHERE academic_year = '2099'");
    $db->query("DELETE FROM courses WHERE course_code = 'TEST-C01'");
    $db->query("DELETE FROM departments WHERE department_code = 'TEST-D'");
    $db->query("DELETE FROM students WHERE SID = 'TEST-STUDENT-001'");

    // Create a test student so foreign keys/lookups succeed
    $db->query("INSERT INTO students (SID, Fname, Lname, nrc_pass, sponsor) 
                VALUES ('TEST-STUDENT-001', 'Test', 'Student', '1111/11/1', 'TEVETA')");
    
    // Create Department
    $db->query("INSERT INTO departments (department_name, department_code, status) VALUES ('Test Department', 'TEST-D', 'active')");
    $deptId = $db->insert_id;
    echo "   - Test Department ID: $deptId\n";

    // Create Course
    $db->query("INSERT INTO courses (course_name, course_code, department_id, duration, duration_unit, course_type, status) 
                VALUES ('Test Craft Certificate', 'TEST-C01', $deptId, '6', 'months', 'certificate', 'active')");
    $courseId = $db->insert_id;
    echo "   - Test Course ID: $courseId\n";

    // Get a Training Mode ID
    $modeRes = $db->query("SELECT id FROM training_modes WHERE mode_name = 'Full Time' LIMIT 1");
    if ($modeRes && $modeRow = $modeRes->fetch_assoc()) {
        $trainingModeId = (int)$modeRow['id'];
    } else {
        $db->query("INSERT INTO training_modes (mode_name, status) VALUES ('Full Time', 'active')");
        $trainingModeId = $db->insert_id;
    }
    echo "   - Training Mode ID: $trainingModeId\n";

    // Configure Base Fee
    $db->query("INSERT INTO course_fees (course_id, training_mode_id, academic_year, currency, base_fee, effective_start_date, status) 
                VALUES ($courseId, $trainingModeId, '2099', 'ZMW', 5000.00, '2026-01-01', 'active')");
    echo "   - Base Course Fee: ZMW 5000.00\n";

    // Create Fee Items for academic year 2099
    $db->query("INSERT INTO fee_items (name, description, amount, mandatory_status, collection_type, academic_year, status) 
                VALUES ('TEST-Registration Fee', 'Reg charge', 150.00, 'mandatory', 'Institution Collected', '2099', 'active')");
    $regFeeId = $db->insert_id;

    $db->query("INSERT INTO fee_items (name, description, amount, mandatory_status, collection_type, academic_year, status) 
                VALUES ('TEST-ID Card Fee', 'ID issue', 50.00, 'mandatory', 'Institution Collected', '2099', 'active')");
    $idFeeId = $db->insert_id;

    $db->query("INSERT INTO fee_items (name, description, amount, mandatory_status, collection_type, academic_year, status) 
                VALUES ('TEST-RTSA Prov License', 'RTSA fee', 100.00, 'mandatory', 'External Payment', '2099', 'active')");
    $rtsaFeeId = $db->insert_id;

    // Link Fee Items to Course
    $db->query("INSERT INTO course_fee_breakdown (course_id, fee_item_id) VALUES ($courseId, $regFeeId)");
    $db->query("INSERT INTO course_fee_breakdown (course_id, fee_item_id) VALUES ($courseId, $idFeeId)");
    $db->query("INSERT INTO course_fee_breakdown (course_id, fee_item_id) VALUES ($courseId, $rtsaFeeId)");
    echo "   - Linked fee items (Registration: 150.00, ID: 50.00, RTSA: 100.00)\n";


    // 2. Test Fee Calculation
    echo "\n2. Testing fee calculations...\n";
    $fees = fees_calculate_payable($db, $courseId, $trainingModeId, '2099');
    
    echo "   - Base Fee: ZMW " . number_format($fees['base_fee'], 2) . "\n";
    echo "   - Additional Fee Total: ZMW " . number_format($fees['additional_fee_total'], 2) . "\n";
    echo "   - Total Payable: ZMW " . number_format($fees['total_payable'], 2) . "\n";
    
    // Assert calculations
    if ($fees['base_fee'] !== 5000.00) throw new Exception("Base fee calculation mismatch.");
    if ($fees['additional_fee_total'] !== 200.00) throw new Exception("Additional fees total mismatch (only Institution Collected mandatory fees should sum here).");
    if ($fees['total_payable'] !== 5200.00) throw new Exception("Total payable calculation mismatch.");
    echo "   [PASS] Fee calculations verified successfully.\n";


    // 3. Test Fee Account Generation
    echo "\n3. Testing student fee account generation...\n";
    $feeAccountId = fees_generate_student_account($db, 'TEST-STUDENT-001', $courseId, $trainingModeId, '2099', 'January');
    if (!$feeAccountId) throw new Exception("Failed to generate student fee account.");
    
    // Check row in DB
    $res = $db->query("SELECT * FROM student_fee_accounts WHERE id = $feeAccountId");
    $accRow = $res->fetch_assoc();
    $res->free();
    
    echo "   - Generated Account ID: {$accRow['id']}\n";
    echo "   - Student ID: {$accRow['student_id']}\n";
    echo "   - Sponsor: {$accRow['sponsor']}\n";
    echo "   - Bursary: ZMW " . number_format($accRow['bursary_amount'], 2) . "\n";
    echo "   - Total Payable: ZMW " . number_format($accRow['total_payable'], 2) . "\n";
    echo "   - Balance: ZMW " . number_format($accRow['balance'], 2) . "\n";
    echo "   - Payment Status: {$accRow['payment_status']}\n";
    
    if ($accRow['sponsor'] !== 'TEVETA') throw new Exception("Saved sponsor mismatch.");
    if ($accRow['bursary_amount'] != 5000.00) throw new Exception("Saved bursary mismatch.");
    if ($accRow['total_payable'] != 200.00) throw new Exception("Saved total payable mismatch.");
    if ($accRow['balance'] != 200.00) throw new Exception("Saved initial balance mismatch.");
    if ($accRow['payment_status'] !== 'Unpaid') throw new Exception("Initial status should be Unpaid.");
    echo "   [PASS] Student fee account generation verified.\n";


    // 4. Test Payment Posting and Recalculation
    echo "\n4. Testing payment posting and balance recalculations...\n";
    
    // Post partial payment
    $db->query("INSERT INTO student_payments 
        (student_fee_account_id, Sid, amount_paid, channel, payment_date, academic_year, semester_term, payment_status, reference_number, description, status, recorded_by, receipt_number) 
        VALUES ($feeAccountId, 'TEST-STUDENT-001', 120.00, 'Cash', NOW(), '2099', 'January', 'completed', 'TEST-RCPT-001', 'Partial payment', 'approved', 'verifier', 'TEST-RCPT-001')");
    
    // Recalculate
    fees_recalculate_student_balance($db, $feeAccountId);
    
    $res = $db->query("SELECT * FROM student_fee_accounts WHERE id = $feeAccountId");
    $accRow = $res->fetch_assoc();
    $res->free();
    
    echo "   - Recorded Payment: ZMW 120.00\n";
    echo "   - Recalculated Paid Amount: ZMW " . number_format($accRow['amount_paid'], 2) . "\n";
    echo "   - Recalculated Balance: ZMW " . number_format($accRow['balance'], 2) . "\n";
    echo "   - Recalculated Payment Status: {$accRow['payment_status']}\n";
    
    if ($accRow['amount_paid'] != 120.00) throw new Exception("Recalculated amount paid mismatch.");
    if ($accRow['balance'] != 80.00) throw new Exception("Recalculated balance mismatch.");
    if ($accRow['payment_status'] !== 'Partially Paid') throw new Exception("Payment status should be Partially Paid.");
    echo "   [PASS] Payment posting and balance recalculation verified.\n";


    // 5. Test Receipt Uniqueness Constraint
    echo "\n5. Testing receipt uniqueness constraint...\n";
    $stmt = $db->prepare("INSERT INTO student_payments 
        (student_fee_account_id, Sid, amount_paid, channel, payment_date, academic_year, semester_term, payment_status, reference_number, description, status, recorded_by, receipt_number) 
        VALUES (?, 'TEST-STUDENT-001', 500.00, 'Cash', NOW(), '2099', 'January', 'completed', 'TEST-RCPT-001', 'Duplicate receipt', 'approved', 'verifier', 'TEST-RCPT-001')");
    
    $dupFailed = false;
    try {
        $stmt->bind_param('i', $feeAccountId);
        $stmt->execute();
    } catch (Exception $dupEx) {
        $dupFailed = true;
        echo "   - Caught duplicate error: " . $dupEx->getMessage() . "\n";
    }
    $stmt->close();
    
    if (!$dupFailed) {
        throw new Exception("Database allowed duplicate receipt number: TEST-RCPT-001.");
    }
    echo "   [PASS] Receipt uniqueness constraint verified.\n";


    // 6. Test Payment Reversal and Recalculation
    echo "\n6. Testing payment reversal and balance rollback...\n";
    
    // Get the payment ID
    $pRes = $db->query("SELECT payment_id FROM student_payments WHERE receipt_number = 'TEST-RCPT-001'");
    $pRow = $pRes->fetch_assoc();
    $paymentId = (int)$pRow['payment_id'];
    $pRes->free();
    
    // Perform reversal
    $db->query("UPDATE student_payments 
                SET status = 'reversed', reversal_reason = 'Clerical typo', payment_status = 'failed' 
                WHERE payment_id = $paymentId");
                
    fees_recalculate_student_balance($db, $feeAccountId);
    
    $res = $db->query("SELECT * FROM student_fee_accounts WHERE id = $feeAccountId");
    $accRow = $res->fetch_assoc();
    $res->free();
    
    echo "   - Reversed Payment ID: $paymentId\n";
    echo "   - Recalculated Paid Amount: ZMW " . number_format($accRow['amount_paid'], 2) . "\n";
    echo "   - Recalculated Balance: ZMW " . number_format($accRow['balance'], 2) . "\n";
    echo "   - Recalculated Payment Status: {$accRow['payment_status']}\n";
    
    if ($accRow['amount_paid'] != 0.00) throw new Exception("Reversal amount paid mismatch.");
    if ($accRow['balance'] != 200.00) throw new Exception("Reversal balance mismatch.");
    if ($accRow['payment_status'] !== 'Unpaid') throw new Exception("Reversal status should roll back to Unpaid.");
    echo "   [PASS] Payment reversal and balance rollback verified.\n";


    // 7. Cleanup Test Data
    echo "\n7. Cleaning up test database records...\n";
    $db->query("DELETE FROM student_payments WHERE receipt_number LIKE 'TEST-%'");
    $db->query("DELETE FROM student_fee_accounts WHERE student_id = 'TEST-STUDENT-001'");
    $db->query("DELETE FROM course_fee_breakdown WHERE course_id = $courseId");
    $db->query("DELETE FROM course_fees WHERE course_id = $courseId");
    $db->query("DELETE FROM fee_items WHERE academic_year = '2099'");
    $db->query("DELETE FROM courses WHERE course_code = 'TEST-C01'");
    $db->query("DELETE FROM departments WHERE department_code = 'TEST-D'");
    $db->query("DELETE FROM students WHERE SID = 'TEST-STUDENT-001'");
    echo "   - Test data cleaned up successfully.\n";

    echo "\n=== ALL TESTS PASSED SUCCESSFULLY! ===\n";

} catch (Exception $ex) {
    echo "\n!!! TEST FAILURE: " . $ex->getMessage() . "\n";
    exit(1);
}
?>
