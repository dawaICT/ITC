<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/production_guards.php';

try {
    wuc_require_post();
    $studentId = wuc_force_session_student_id($_POST['student_id'] ?? null, true);
    wuc_require_csrf();

    $academicYear = trim((string)($_POST['academic_year'] ?? ''));
    $semester = trim((string)($_POST['semester'] ?? ''));
    $yearOfStudy = trim((string)($_POST['year_of_study'] ?? ''));

    if ($academicYear === '' || $semester === '') {
        wuc_json_abort('Missing identification parameters for unregistration', 400);
    }

    require_once __DIR__ . '/includes/Database.php';
    $db = new Database();
    $conn = $db->getConnection();
    $conn->beginTransaction();

    $stmtId = $conn->prepare(
        'SELECT id FROM semester_registration WHERE (student_id = ? OR Sid = ?) AND academic_year = ? AND semester = ?'
    );
    $stmtId->execute([$studentId, $studentId, $academicYear, $semester]);
    $srRow = $stmtId->fetch(PDO::FETCH_ASSOC);
    $srId = $srRow ? $srRow['id'] : null;

    $delInv = $conn->prepare(
        'DELETE FROM invoices WHERE (student_id = ? OR SID = ?) AND academic_year = ? AND semester = ?'
    );
    $delInv->execute([$studentId, $studentId, $academicYear, $semester]);

    if ($srId) {
        $delCR = $conn->prepare('DELETE FROM course_registration WHERE semester_registration_id = ?');
        $delCR->execute([$srId]);
    } else {
        $delCR = $conn->prepare('DELETE FROM course_registration WHERE Sid = ? AND semester = ? AND Year = ?');
        $delCR->execute([$studentId, $semester, $yearOfStudy]);
    }

    $delSR = $conn->prepare(
        'DELETE FROM semester_registration WHERE (student_id = ? OR Sid = ?) AND academic_year = ? AND semester = ?'
    );
    $delSR->execute([$studentId, $studentId, $academicYear, $semester]);

    $conn->commit();

    error_log(sprintf(
        'student_unregister sid=%s year=%s semester=%s ip=%s',
        $studentId,
        $academicYear,
        $semester,
        $_SERVER['REMOTE_ADDR'] ?? ''
    ));

    echo json_encode([
        'success' => true,
        'message' => "Unregistered student {$studentId} for {$academicYear} Semester {$semester} successfully.",
    ]);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('process_unregister failed: ' . $e->getMessage());
    wuc_json_abort('Unregistration failed. Please try again.', 500);
}
