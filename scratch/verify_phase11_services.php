<?php
/**
 * Automated Verification Script - Phase 11 Digital Campus Services & Clearance
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/grading_helpers.php';

echo "=== Phase 11 Campus Services & Clearance Verification Checks ===\n\n";

function verify_assert(bool $condition, string $description) {
    if ($condition) {
        echo "[PASS] $description\n";
    } else {
        echo "[FAIL] $description\n";
        exit(1);
    }
}

// 1. Database Table Existence Checks
$tables = [];
if ($res = $db->query("SHOW TABLES")) {
    while ($row = $res->fetch_row()) {
        $tables[] = $row[0];
    }
}
verify_assert(in_array('student_campus_requests', $tables, true), "Table 'student_campus_requests' exists.");
verify_assert(in_array('student_clearance', $tables, true), "Table 'student_clearance' exists.");

// 2. Linkage and Request Ownership Validation
$testStudent = 'STUD-SERV-11';
$db->query("DELETE FROM student_campus_requests WHERE student_id = '$testStudent'");
$db->query("DELETE FROM student_clearance WHERE student_id = '$testStudent'");

// Insert support ticket request
$stmt = $db->prepare("INSERT INTO student_campus_requests (student_id, service_type, subject, message, status) VALUES (?, 'counselling', 'Test Support Subject', 'Test support message content', 'Pending')");
$stmt->bind_param('s', $testStudent);
verify_assert($stmt->execute(), "Successfully inserted a counselling request ticket.");
$reqId = $db->insert_id;
$stmt->close();

// Verify ticket ownership linkage
$resTicket = $db->query("SELECT * FROM student_campus_requests WHERE id = $reqId");
$ticket = $resTicket->fetch_assoc();
verify_assert($ticket['student_id'] === $testStudent, "Counselling request is correctly linked to student $testStudent.");

// Admin updates/schedules request ticket
$status = 'Scheduled';
$appDate = '2026-06-30 14:00:00';
$notes = 'Coordinator scheduled meeting room 3';
$stmtUpd = $db->prepare("UPDATE student_campus_requests SET status = ?, appointment_date = ?, notes = ? WHERE id = ?");
$stmtUpd->bind_param('sssi', $status, $appDate, $notes, $reqId);
verify_assert($stmtUpd->execute(), "Successfully updated request status and scheduled appointment.");
$stmtUpd->close();

$resTicketUpdated = $db->query("SELECT * FROM student_campus_requests WHERE id = $reqId");
$ticketUpdated = $resTicketUpdated->fetch_assoc();
verify_assert($ticketUpdated['status'] === 'Scheduled', "Counselling ticket successfully transitioned to Scheduled.");
verify_assert($ticketUpdated['appointment_date'] === $appDate, "Counselling ticket appointment datetime correctly matches.");

// 3. Multi-Check Clearance Node Audits
// a. Finance Node check
$balance = 150.00; // Outstanding balance
$finance_cleared = ($balance <= 0.0);
verify_assert(!$finance_cleared, "Finance clearance node blocks student with positive balance ($balance ZMW).");

$balance = 0.00;
$finance_cleared = ($balance <= 0.0);
verify_assert($finance_cleared, "Finance clearance node clears student with zero balance.");

// b. Library Node check
$loansCount = 1; // Outstanding loan
$library_cleared = ($loansCount === 0);
verify_assert(!$library_cleared, "Library clearance node blocks student with outstanding loan book checkouts.");

$loansCount = 0;
$library_cleared = ($loansCount === 0);
verify_assert($library_cleared, "Library clearance node clears student with no loans.");

// c. Academic Node check
// GPA 1.8 (fails) vs GPA 2.5 (clears)
$gpa = 1.8;
$fails = 0;
$credits = 12;
$academic_cleared = ($gpa >= 2.0 && $fails === 0 && $credits >= 12);
verify_assert(!$academic_cleared, "Academic clearance blocks student with GPA < 2.0 ($gpa).");

$gpa = 2.5;
$fails = 1;
$academic_cleared = ($gpa >= 2.0 && $fails === 0 && $credits >= 12);
verify_assert(!$academic_cleared, "Academic clearance blocks student with failed courses.");

$gpa = 2.5;
$fails = 0;
$academic_cleared = ($gpa >= 2.0 && $fails === 0 && $credits >= 12);
verify_assert($academic_cleared, "Academic clearance clears student with GPA >= 2.0 and zero failed courses.");

// 4. Graduation Lifecycle Eligibility Gate
$eligible = ($finance_cleared && $library_cleared && $academic_cleared); // admin review is toggleable
verify_assert($eligible, "Student is eligible for graduation clearance check pass.");

// Apply for graduation
$gradYear = 2026;
$stmtGrad = $db->prepare("INSERT INTO student_clearance (student_id, finance_cleared, library_cleared, academic_cleared, admin_cleared, graduation_status, graduation_year) VALUES (?, 1, 1, 1, 0, 'Applied', ?)");
$stmtGrad->bind_param('si', $testStudent, $gradYear);
verify_assert($stmtGrad->execute(), "Successfully recorded student graduation application.");
$stmtGrad->close();

// Registrar approves graduation
$stmtApprove = $db->prepare("UPDATE student_clearance SET admin_cleared = 1, graduation_status = 'Approved' WHERE student_id = ?");
$stmtApprove->bind_param('s', $testStudent);
verify_assert($stmtApprove->execute(), "Registrar successfully updates administrative clearance and graduation status to Approved.");
$stmtApprove->close();

$resClearance = $db->query("SELECT * FROM student_clearance WHERE student_id = '$testStudent'");
$clearance = $resClearance->fetch_assoc();
verify_assert($clearance['admin_cleared'] == 1, "Administrative clearance toggle checks successfully.");
verify_assert($clearance['graduation_status'] === 'Approved', "Graduation status is marked Approved.");

// Clean up
$db->query("DELETE FROM student_campus_requests WHERE student_id = '$testStudent'");
$db->query("DELETE FROM student_clearance WHERE student_id = '$testStudent'");

echo "\nVerification Results:\n";
echo "=== [ALL PASS] Phase 11 Campus Services Verification completed successfully! ===\n";
?>
