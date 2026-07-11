<?php
/**
 * Automated Verification Script - Phase 7 Competency & Practical Tracking
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('WUC_DB_USER=root');
putenv('WUC_DB_PASSWORD=');
putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';

echo "=== Phase 7 Practical and Competency Tracking Verification Checks ===\n\n";

function verify_assert(bool $condition, string $description) {
    if ($condition) {
        echo "[PASS] $description\n";
    } else {
        echo "[FAIL] $description\n";
        exit(1);
    }
}

// 1. Schema Integrity Checks
$tables = [];
if ($res = $db->query("SHOW TABLES LIKE 'el_%'")) {
    while ($row = $res->fetch_row()) {
        $tables[] = $row[0];
    }
}
verify_assert(in_array('el_competencies', $tables, true), "Table 'el_competencies' exists in schema.");
verify_assert(in_array('el_student_competencies', $tables, true), "Table 'el_student_competencies' exists in schema.");

// Check columns of el_student_competencies
$columns = [];
if ($res = $db->query("DESCRIBE el_student_competencies")) {
    while ($row = $res->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
}
verify_assert(in_array('status', $columns, true), "Column 'status' exists in student competencies.");
verify_assert(in_array('evidence_path', $columns, true), "Column 'evidence_path' exists in student competencies.");
verify_assert(in_array('lecturer_id', $columns, true), "Column 'lecturer_id' exists in student competencies.");
verify_assert(in_array('trainer_id', $columns, true), "Column 'trainer_id' exists in student competencies.");
verify_assert(in_array('industry_supervisor_name', $columns, true), "Column 'industry_supervisor_name' exists in student competencies.");

// 2. Navigation Gating Integration
$ui_file = file_get_contents('includes/elearning_ui.php');
verify_assert(stripos($ui_file, 'competencies.php') !== false, "Competencies tab added to elearning_ui.php.");

$student_course_file = file_get_contents('students/elearning/course.php');
verify_assert(stripos($student_course_file, 'competencies.php?course_code=') !== false, "Competency Checklist quick link added to students/elearning/course.php.");

// 3. Workflow Simulation (Add Competency, Submit Evidence, Multi-party Verification)
$testCourse = 'TEST-COMP-77';
$testStudent = 'STUD-COMP-77';
$testLecturer = 'LEC-COMP-77';
$testTrainer = 'TRAIN-COMP-77';

// Clean any previous test data
$db->query("DELETE FROM el_student_competencies WHERE student_id = '$testStudent'");
$db->query("DELETE FROM el_competencies WHERE course_code = '$testCourse'");

// a. Add Competency Task
$stmt = $db->prepare("INSERT INTO el_competencies (course_code, title, description) VALUES (?, ?, ?)");
$title = "Safety Assessment Demonstration";
$desc = "Demonstrate safe pre-start inspection protocol for heavy vehicles.";
$stmt->bind_param('sss', $testCourse, $title, $desc);
verify_assert($stmt->execute(), "Successfully created test competency standard.");
$competencyId = $db->insert_id;
$stmt->close();

// b. Student Uploads Evidence
$evidencePath = 'uploads/evidence/test_checklist.pdf';
$stmt = $db->prepare("INSERT INTO el_student_competencies (student_id, competency_id, status, evidence_path) VALUES (?, ?, 'in_progress', ?)");
$stmt->bind_param('sis', $testStudent, $competencyId, $evidencePath);
verify_assert($stmt->execute(), "Successfully logged student evidence upload (Status: In Progress).");
$stmt->close();

// c. Lecturer Verification
$notes = "Demonstrated excellent safety awareness. Ready for coordinator review.";
$stmt = $db->prepare("UPDATE el_student_competencies SET status = 'competent', lecturer_id = ?, lecturer_verified_at = NOW(), notes = ? WHERE student_id = ? AND competency_id = ?");
$stmt->bind_param('sssi', $testLecturer, $notes, $testStudent, $competencyId);
verify_assert($stmt->execute(), "Lecturer successfully evaluated and marked student as Competent.");
$stmt->close();

// Verify updated record
$res = $db->query("SELECT * FROM el_student_competencies WHERE student_id = '$testStudent' AND competency_id = $competencyId");
$row = $res->fetch_assoc();
verify_assert($row['status'] === 'competent', "Verification status matches 'competent'.");
verify_assert($row['lecturer_id'] === $testLecturer, "Lecturer ID matches evaluator.");
verify_assert(!empty($row['lecturer_verified_at']), "Lecturer verification timestamp set.");

// d. Trainer Verification (Final Sign-off)
$finalNotes = "Fully verified and approved for TEVETA practical certification.";
$stmt = $db->prepare("UPDATE el_student_competencies SET status = 'verified', trainer_id = ?, trainer_verified_at = NOW(), notes = ? WHERE student_id = ? AND competency_id = ?");
$stmt->bind_param('sssi', $testTrainer, $finalNotes, $testStudent, $competencyId);
verify_assert($stmt->execute(), "Trainer/Coordinator successfully verified student competency.");
$stmt->close();

// Verify updated record
$res = $db->query("SELECT * FROM el_student_competencies WHERE student_id = '$testStudent' AND competency_id = $competencyId");
$row = $res->fetch_assoc();
verify_assert($row['status'] === 'verified', "Verification status matches 'verified'.");
verify_assert($row['trainer_id'] === $testTrainer, "Trainer ID matches evaluator.");
verify_assert(!empty($row['trainer_verified_at']), "Trainer verification timestamp set.");

// Clean up
$db->query("DELETE FROM el_student_competencies WHERE student_id = '$testStudent'");
$db->query("DELETE FROM el_competencies WHERE course_code = '$testCourse'");

echo "\nVerification Results:\n";
echo "=== [ALL PASS] Phase 7 Competency Tracking Verification completed successfully! ===\n";
?>
