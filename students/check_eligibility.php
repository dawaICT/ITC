<?php
/**
 * check_eligibility.php
 * Endpoint for checking registration eligibility for returning students.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/production_guards.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/EligibilityService.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $studentId = wuc_force_session_student_id($_POST['student_id'] ?? null, true);
    $academicYear = $_POST['academic_year'] ?? null;
    $semester = (int)($_POST['semester'] ?? 0);

    $_SESSION['eligibility_cache'] = $_SESSION['eligibility_cache'] ?? [];
    $eligibilityCache = &$_SESSION['eligibility_cache'];
    $eligibilityCacheTTL = 30; // seconds

    $cacheKey = (string)$studentId;
    if (isset($eligibilityCache[$cacheKey])) {
        $cached = $eligibilityCache[$cacheKey];
        if (isset($cached['timestamp'], $cached['result']) && (time() - $cached['timestamp']) < $eligibilityCacheTTL) {
            echo json_encode($cached['result']);
            exit;
        }
    }

    // Connect via mysqli for EligibilityService
    require_once dirname(__DIR__, 2) . '/db/connect.php';
    if (!isset($db) || !($db instanceof mysqli)) {
        throw new Exception('Database connection failed.');
    }

    $failed = EligibilityService::getFailedCourses($db, $studentId);
    $flags = EligibilityService::computeFailureFlags(count($failed));
    
    $isEligible = true;
    $reason = "";

    if ($flags['repeat_semester']) {
        $isEligible = false;
        $reason = "Academic Policy: You have " . count($failed) . " failed courses. You are required to repeat this semester.";
    } elseif ($flags['auto_append_failed']) {
        $reason = "Note: You have " . count($failed) . " failed courses. These will be added to your registration automatically.";
    }

    // Check financial eligibility (simple check for outstanding balance if table exists)
    $stmt = $db->prepare("SELECT SUM(amount) as balance FROM invoices WHERE student_id = ? AND status != 'Paid'");
    if ($stmt) {
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $res = $stmt->get_result();
        $invoiceData = $res->fetch_assoc();
        if ($invoiceData && $invoiceData['balance'] > 100000) { // e.g., > 100,000 threshold
            $isEligible = false;
            $reason = "Financial Block: You have an outstanding balance of MWK " . number_format((float)$invoiceData['balance'], 2) . ". Please settle your account before registering.";
        }
    }

    $response = [
        'success' => true,
        'eligible' => $isEligible,
        'reason' => $reason,
        'failed_count' => count($failed)
    ];

    $eligibilityCache[$cacheKey] = [
        'timestamp' => time(),
        'result' => $response
    ];

    echo json_encode($response);

} catch (Exception $e) {
    error_log('check_eligibility failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Unable to check eligibility. Please try again.']);
}
