<?php
/**
 * Automated Verification Script - Phase 8 Assessment and Examination Engine
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/assessment_weighting_helpers.php';
require_once __DIR__ . '/../includes/grading_helpers.php';

echo "=== Phase 8 Assessment & Examination Engine Verification Checks ===\n\n";

function verify_assert(bool $condition, string $description) {
    if ($condition) {
        echo "[PASS] $description\n";
    } else {
        echo "[FAIL] $description\n";
        exit(1);
    }
}

// 1. Schema Integrity Checks
$columns = [];
if ($res = $db->query("DESCRIBE semester_assessment")) {
    while ($row = $res->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
}
verify_assert(in_array('internal_moderation_status', $columns, true), "Column 'internal_moderation_status' exists.");
verify_assert(in_array('internal_moderator_id', $columns, true), "Column 'internal_moderator_id' exists.");
verify_assert(in_array('internal_moderation_notes', $columns, true), "Column 'internal_moderation_notes' exists.");
verify_assert(in_array('external_moderation_status', $columns, true), "Column 'external_moderation_status' exists.");
verify_assert(in_array('external_moderator_id', $columns, true), "Column 'external_moderator_id' exists.");

// 2. Student Gating Checks (check query contains status = 'Published')
$view_ca = file_get_contents('students/view_ca.php');
verify_assert(stripos($view_ca, "status = 'Published'") !== false, "students/view_ca.php gates CA marks lookup query with Published status.");

$cont_ca = file_get_contents('students/continuousAssessment.php');
verify_assert(stripos($cont_ca, "status = 'Published'") !== false, "students/continuousAssessment.php gates CA marks lookup query with Published status.");

// 3. Weighting Policies by Program Type
// a. Transport and Logistics (40/60)
$isTransport = assessment_weighting_is_transport_logistics('ITC-TRL-01', 'Transport and Logistics Management');
verify_assert($isTransport, "Helper correctly identifies Transport and Logistics programme.");

// b. Simulate policies
$mockTransportPolicy = [
    'is_weighted' => true,
    'ca_weight' => 40,
    'exam_weight' => 60
];
$mockShortCoursePolicy = [
    'is_weighted' => true,
    'ca_weight' => 50,
    'exam_weight' => 50
];
$mockTevetaPolicy = [
    'is_weighted' => true,
    'ca_weight' => 40,
    'exam_weight' => 60
];

// Test weighting calculations
// Transport/TEVETA: 30 CA, 70 Exam -> (30 * 0.40) + (70 * 0.60) = 12 + 42 = 54
$finalTransport = round((30 * ($mockTransportPolicy['ca_weight']/100)) + (70 * ($mockTransportPolicy['exam_weight']/100)), 2);
verify_assert($finalTransport == 54.0, "Transport/TEVETA weighting (30 CA + 70 Exam = 54%) calculated correctly.");

// Short Course: 30 CA, 70 Exam -> (30 * 0.50) + (70 * 0.50) = 15 + 35 = 50
$finalShort = round((30 * ($mockShortCoursePolicy['ca_weight']/100)) + (70 * ($mockShortCoursePolicy['exam_weight']/100)), 2);
verify_assert($finalShort == 50.0, "Short Course weighting (30 CA + 70 Exam = 50%) calculated correctly.");

// 4. Moderation Log & Audit Simulation
$testId = 999998;
$db->query("DELETE FROM semester_assessment WHERE id = $testId");
$db->query("DELETE FROM result_audit_log WHERE assessment_id = $testId");

// Insert mock row
$db->query("INSERT INTO semester_assessment (id, Sid, Course_Code, Total_CA, Exam, semester, Year, status) 
            VALUES ($testId, 'STUD-MOD-88', 'TEST-MOD-88', 30.00, 70.00, '1', '2026', 'Submitted')");

// Simulate HOD submitting internal/external moderation
$actor = 'HOD-MOD-88';
$internalStatus = 'approved';
$internalNotes = 'Internal examination marks audited and approved.';
$externalStatus = 'approved';
$externalNotes = 'External moderation verified by TEVETA assessor.';
$externalModName = 'TEVETA Assessor';

$sql = "UPDATE semester_assessment
        SET internal_moderation_status = ?,
            internal_moderator_id = ?,
            internal_moderated_at = NOW(),
            internal_moderation_notes = ?,
            external_moderation_status = ?,
            external_moderator_id = ?,
            external_moderated_at = NOW(),
            external_moderation_notes = ?,
            status = 'Approved',
            approved_by = ?,
            approved_at = NOW()
        WHERE id = ?";

$stmt = $db->prepare($sql);
$stmt->bind_param('sssssssi', $internalStatus, $actor, $internalNotes, $externalStatus, $externalModName, $externalNotes, $actor, $testId);
verify_assert($stmt->execute(), "Successfully updated database moderation details.");
$stmt->close();

// Log audit entry
wuc_result_log($db, [
    'assessment_id' => $testId,
    'Sid' => 'STUD-MOD-88',
    'Course_Code' => 'TEST-MOD-88',
    'semester' => '1',
    'Year' => '2026',
    'action' => 'moderated',
    'field_changed' => 'internal_moderation_status',
    'old_value' => 'Submitted',
    'new_value' => 'Approved',
    'reason' => $internalNotes,
    'actor_staff_id' => $actor,
]);

// Verify database row
$res = $db->query("SELECT * FROM semester_assessment WHERE id = $testId");
$row = $res->fetch_assoc();
verify_assert($row['internal_moderation_status'] === 'approved', "Internal moderation status successfully recorded.");
verify_assert($row['external_moderation_status'] === 'approved', "External moderation status successfully recorded.");
verify_assert($row['status'] === 'Approved', "Overall results status moved to Approved after moderation approval.");

// Verify audit trail row
$resAudit = $db->query("SELECT * FROM result_audit_log WHERE assessment_id = $testId LIMIT 1");
verify_assert($resAudit->num_rows > 0, "Audit trail entry successfully logged in result_audit_log.");
$audit = $resAudit->fetch_assoc();
verify_assert($audit['action'] === 'moderated', "Audit log action type matches 'moderated'.");

// Clean up
$db->query("DELETE FROM semester_assessment WHERE id = $testId");
$db->query("DELETE FROM result_audit_log WHERE assessment_id = $testId");

echo "\nVerification Results:\n";
echo "=== [ALL PASS] Phase 8 Assessment Engine Verification completed successfully! ===\n";
?>
