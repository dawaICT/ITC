<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'includes/Database.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $studentId = $_POST['student_id'] ?? ($_SESSION['Sid'] ?? null);
    $academicYear = $_POST['academic_year'] ?? null;
    $semester = $_POST['semester'] ?? null;
    $yearOfStudy = $_POST['year_of_study'] ?? null;

    if (empty($studentId) || empty($academicYear) || empty($semester)) {
        throw new Exception('Missing identification parameters for unregistration');
    }

    $db = new Database();
    $conn = $db->getConnection();
    $conn->beginTransaction();

    // 1. Fetch semester_registration ID if it exists (to clean course_registration more accurately)
    $stmtId = $conn->prepare("SELECT id FROM semester_registration WHERE (student_id = ? OR Sid = ?) AND academic_year = ? AND semester = ?");
    $stmtId->execute([$studentId, $studentId, $academicYear, $semester]);
    $srRow = $stmtId->fetch(PDO::FETCH_ASSOC);
    $srId = $srRow ? $srRow['id'] : null;

    // 2. Delete from invoices
    $delInv = $conn->prepare("DELETE FROM invoices WHERE (student_id = ? OR SID = ?) AND academic_year = ? AND semester = ?");
    $delInv->execute([$studentId, $studentId, $academicYear, $semester]);

    // 3. Delete from course_registration
    if ($srId) {
        $delCR = $conn->prepare("DELETE FROM course_registration WHERE semester_registration_id = ?");
        $delCR->execute([$srId]);
    } else {
        // Fallback to SID/Semester/Year
        $delCR = $conn->prepare("DELETE FROM course_registration WHERE Sid = ? AND semester = ? AND Year = ?");
        $delCR->execute([$studentId, $semester, $yearOfStudy]);
    }

    // 4. Delete from semester_registration
    $delSR = $conn->prepare("DELETE FROM semester_registration WHERE (student_id = ? OR Sid = ?) AND academic_year = ? AND semester = ?");
    $delSR->execute([$studentId, $studentId, $academicYear, $semester]);

    $conn->commit();

    echo json_encode([
        'success' => true, 
        'message' => "Unregistered student $studentId for $academicYear Semester $semester successfully."
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
