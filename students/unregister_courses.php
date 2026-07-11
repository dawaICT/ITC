<?php
/**
 * Unregister courses for a student for the specified/current term.
 * Usage (CLI or web):
 *  php unregister_courses.php student_id=SID
 *  or via browser: /students/unregister_courses.php?student_id=SID
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/includes/DatabaseConnection.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/includes/RegistrationDataService.php';
require_once __DIR__ . '/includes/AcademicSessionService.php';

// Allow running via CLI args or GET/POST
parse_str(implode('&', array_slice($argv ?? [], 1)), $cliArgs);
$studentId = $_REQUEST['student_id'] ?? $cliArgs['student_id'] ?? null;

session_start();
if (!$studentId) {
    if (!empty($_SESSION['Sid'])) $studentId = $_SESSION['Sid'];
}

if (empty($studentId)) {
    echo "ERROR: student_id not provided and no session Sid available.\n";
    exit(1);
}

echo "Unregistering courses for student: $studentId\n";

$dbConn = DatabaseConnection::getInstance();
$mysqli = $dbConn->getMysqli();
$pdo = $dbConn->getPdo();

$sessionService = new AcademicSessionService($mysqli);
$currentSession = $sessionService->getCurrentSession();
$academicYear = $currentSession['academic_year'] ?? null;
$semester = $currentSession['semester_term'] ?? null;

$regService = new RegistrationDataService($mysqli);

// Try to locate semester registration for current term first
$semReg = null;
if ($academicYear && $semester) {
    $semReg = $regService->getSemesterRegistrationForTerm($studentId, (string)$academicYear, (string)$semester);
}
if (!$semReg) {
    $semReg = $regService->getLatestSemesterRegistration($studentId);
}

$semRegId = $semReg['id'] ?? null;

// Build selection query similar to registration.php logic
$courseRows = [];
try {
    if ($semRegId) {
        $stmt = $pdo->prepare("SELECT * FROM course_registration WHERE semester_registration_id = ?");
        $stmt->execute([$semRegId]);
        $courseRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($courseRows)) {
        // Discover column names
        $cols = [];
        $meta = $pdo->query('SHOW COLUMNS FROM course_registration');
        while ($c = $meta->fetch(PDO::FETCH_ASSOC)) {
            $cols[strtolower($c['Field'])] = $c['Field'];
        }
        $crSidCol = $cols['sid'] ?? ($cols['student_id'] ?? 'Sid');
        $crYearCol = $cols['year'] ?? ($cols['year_of_study'] ?? 'Year');
        $crSemCol = $cols['semester'] ?? ($cols['semester_term'] ?? 'semester');

        $query = "SELECT * FROM course_registration WHERE `$crSidCol` = ?";
        $params = [$studentId];
        if ($academicYear) { $query .= " AND `$crYearCol` = ?"; $params[] = $academicYear; }
        if ($semester) { $query .= " AND `$crSemCol` = ?"; $params[] = $semester; }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $courseRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    echo "ERROR: Failed to query course_registration: " . $e->getMessage() . "\n";
    exit(1);
}

if (empty($courseRows)) {
    echo "No course registrations found for student $studentId. Nothing to delete.\n";
    exit(0);
}

// Backup rows to file
$timestamp = date('Ymd_His');
$backupPath = __DIR__ . "/unregister_backup_{$studentId}_{$timestamp}.json";
file_put_contents($backupPath, json_encode($courseRows, JSON_PRETTY_PRINT));

echo "Backed up " . count($courseRows) . " registration row(s) to: $backupPath\n";

// Delete rows inside transaction
try {
    $pdo->beginTransaction();

    if ($semRegId) {
        $delStmt = $pdo->prepare('DELETE FROM course_registration WHERE semester_registration_id = ?');
        $delStmt->execute([$semRegId]);
        $deleted = $delStmt->rowCount();
    } else {
        // Reuse the same selection criteria to delete
        $cols = [];
        $meta = $pdo->query('SHOW COLUMNS FROM course_registration');
        while ($c = $meta->fetch(PDO::FETCH_ASSOC)) {
            $cols[strtolower($c['Field'])] = $c['Field'];
        }
        $crSidCol = $cols['sid'] ?? ($cols['student_id'] ?? 'Sid');
        $crYearCol = $cols['year'] ?? ($cols['year_of_study'] ?? 'Year');
        $crSemCol = $cols['semester'] ?? ($cols['semester_term'] ?? 'semester');

        $delQuery = "DELETE FROM course_registration WHERE `$crSidCol` = ?";
        $params = [$studentId];
        if ($academicYear) { $delQuery .= " AND `$crYearCol` = ?"; $params[] = $academicYear; }
        if ($semester) { $delQuery .= " AND `$crSemCol` = ?"; $params[] = $semester; }

        $delStmt = $pdo->prepare($delQuery);
        $delStmt->execute($params);
        $deleted = $delStmt->rowCount();
    }

    $pdo->commit();
    echo "Deleted $deleted course_registration row(s) for student $studentId.\n";
    echo "Backup retained at: $backupPath\n";
    exit(0);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ERROR: Failed to delete registrations: " . $e->getMessage() . "\n";
    exit(1);
}
