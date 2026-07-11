<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Debug logging
define('DEBUG_MODE', filter_var(getenv('DEBUG'), FILTER_VALIDATE_BOOLEAN));
if (DEBUG_MODE) {
    error_log("=== DEBUG: process.php - Request: " . json_encode($_GET));
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security guard
require_once __DIR__ . '/includes/guard.php';

// Include services
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/StudentRegistrationSystem.php';
require_once __DIR__ . '/includes/StudentDataService.php';

// Basic validation of GET parameters
$studentId = $_GET['id'] ?? $_SESSION['Sid'] ?? '';
$academicYear = $_GET['academicYear'] ?? '';
$yearOfStudy = $_GET['year'] ?? '1';
$semester = $_GET['term'] ?? '';
$modeOfStudy = $_GET['modeOfStady'] ?? ''; // Legacy typo param name

// Verify user authority
if (empty($studentId) || $studentId !== $_SESSION['Sid']) {
    die("Security Violation: Invalid Student ID.");
}

if (empty($academicYear) || empty($semester)) {
    die("Missing required registration parameters.");
}

// Check database connection
$db = new Database(); 
// StudentRegistrationSystem needs the Database object (PDO wrapper)

// --- 50% THRESHOLD CHECK (Robust validation) ---
// We must verify they paid 50% before allowing processing.
// Use default constructor to leverage DatabaseConnection singleton for mixed mysqli/PDO support
$studentDataService = new StudentDataService();

try {
    $payment = $studentDataService->getPaymentStatus($studentId, $academicYear, $semester);
    $tuitionFee = $payment['total_due'] ?? 0;
    $amountPaid = $payment['total_paid'] ?? 0;
    
    // Threshold is 50%
    $threshold = $tuitionFee * 0.50;
    
    if ($amountPaid < $threshold) {
        // Payment not met. Redirect back to registration with a message or block.
        // For security, strict block.
         die("<h1>Registration Denied</h1><p>You have not met the minimum payment threshold of 50%. Please make a payment first.</p><a href='registration.php'>Back</a>");
    }
} catch (Exception $e) {
    die("Error verifying payment status: " . $e->getMessage());
}

// --- PROCESS SEMESTER REGISTRATION ---
try {
    $regSystem = new StudentRegistrationSystem($db);
    
    // Register for the semester with EMPTY courses list.
    // This creates the semester_registration record (if missing) and marks active.
    $regSystem->processRegistration($studentId, [], $semester, $yearOfStudy, $academicYear);
    
    if (DEBUG_MODE) {
        error_log("process.php: Semester registration initialized for $studentId, Year $academicYear, Sem $semester");
    }

    // --- REDIRECT TO COURSE SELECTION ---
    // User instruction: "You will be redirected to course selection after processing."
    header("Location: courseReg.php");
    exit;

} catch (Exception $e) {
    error_log("process.php Error: " . $e->getMessage());
    die("An error occurred during processing: " . htmlspecialchars($e->getMessage()));
}
?>
