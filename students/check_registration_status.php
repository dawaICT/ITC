<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/Database.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Invalid request method']);
        exit;
    }

    $studentId = $_POST['student_id'] ?? ($_SESSION['Sid'] ?? null);
    $academicYear = $_POST['academic_year'] ?? null;
    $semester = $_POST['semester'] ?? null;
    $yearOfStudy = $_POST['year_of_study'] ?? null;

    if (empty($studentId) || empty($academicYear) || empty($semester)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    // 1. Check semester_registration table using the live SID column.
    // Flexible academic year matching: handles '2025-2026' vs '2025' format mismatch
    $yearPrefix = substr($academicYear, 0, 4);
    $regQuery = "SELECT id FROM semester_registration WHERE SID = ? AND (academic_year = ? OR academic_year LIKE ? OR ? LIKE CONCAT(academic_year, '%')) AND semester = ?";
    $regParams = [$studentId, $academicYear, $yearPrefix . '%', $academicYear, $semester];
    $regQuery .= " LIMIT 1";
    
    $regStmt = $conn->prepare($regQuery);
    $regStmt->execute($regParams);
    $regRow = $regStmt->fetch(PDO::FETCH_ASSOC);

    // 2. Check invoices table (with flexible academic year matching)
    $stmt = $conn->prepare("SELECT invoice_number, status FROM invoices WHERE (student_id = ? OR SID = ?) AND (academic_year = ? OR academic_year LIKE ? OR ? LIKE CONCAT(academic_year, '%')) AND semester = ? LIMIT 1");
    $stmt->execute([$studentId, $studentId, $academicYear, $yearPrefix . '%', $academicYear, $semester]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($regRow || $row) {
        echo json_encode([
            'success' => true,
            'registered' => true,
            'invoice_number' => $row['invoice_number'] ?? null,
            'status' => $row['status'] ?? ($regRow ? 'Registered' : null)
        ]);
    } else {
        echo json_encode(['success' => true, 'registered' => false]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
