<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/production_guards.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        wuc_json_abort('Invalid request method', 405);
    }

    $studentId = wuc_force_session_student_id($_POST['student_id'] ?? null, true);
    $academicYear = trim((string)($_POST['academic_year'] ?? ''));
    $semester = trim((string)($_POST['semester'] ?? ''));

    if ($academicYear === '' || $semester === '') {
        wuc_json_abort('Missing parameters', 400);
    }

    require_once __DIR__ . '/includes/Database.php';
    $db = new Database();
    $conn = $db->getConnection();

    $yearPrefix = substr($academicYear, 0, 4);
    $regQuery = 'SELECT id FROM semester_registration WHERE (student_id = ? OR SID = ?) AND (academic_year = ? OR academic_year LIKE ? OR ? LIKE CONCAT(academic_year, \'%\')) AND semester = ? LIMIT 1';
    $regStmt = $conn->prepare($regQuery);
    $regStmt->execute([$studentId, $studentId, $academicYear, $yearPrefix . '%', $academicYear, $semester]);
    $regRow = $regStmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare(
        'SELECT invoice_number, status FROM invoices WHERE (student_id = ? OR SID = ?) AND (academic_year = ? OR academic_year LIKE ? OR ? LIKE CONCAT(academic_year, \'%\')) AND semester = ? LIMIT 1'
    );
    $stmt->execute([$studentId, $studentId, $academicYear, $yearPrefix . '%', $academicYear, $semester]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($regRow || $row) {
        echo json_encode([
            'success' => true,
            'registered' => true,
            'invoice_number' => $row['invoice_number'] ?? null,
            'status' => $row['status'] ?? ($regRow ? 'Registered' : null),
        ]);
    } else {
        echo json_encode(['success' => true, 'registered' => false]);
    }
} catch (Throwable $e) {
    error_log('check_registration_status failed: ' . $e->getMessage());
    wuc_json_abort('Unable to check registration status.', 500);
}
