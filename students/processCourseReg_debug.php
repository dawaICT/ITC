<?php
// INSTRUMENTED VERSION OF processCourseReg.php WITH SESSION DEBUGGING
require_once __DIR__ . '/../includes/manual_entry_guards.php';
wuc_gate_debug_endpoint();

// Enable error reporting to see all issues
ini_set('display_errors', '0');
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Create logs directory if it doesn't exist
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Set up a specific log file for this script
ini_set('error_log', $logDir . '/course_reg_debug.log');
error_log('----------- PROCESS COURSE REG DEBUG START -----------');

// Debug function to log session state at various points
function debug_log($message, $data = []) {
    $sessionInfo = [
        'time' => date('Y-m-d H:i:s'),
        'message' => $message,
        'session_id' => session_id() ?: 'none',
        'session_active' => session_status() === PHP_SESSION_ACTIVE ? 'yes' : 'no',
        'sid_in_session' => isset($_SESSION['Sid']) ? $_SESSION['Sid'] : 'not set',
        'last_activity' => isset($_SESSION['last_activity']) ? date('Y-m-d H:i:s', $_SESSION['last_activity']) : 'not set',
        'memory_usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
    ];
    
    $logData = array_merge($sessionInfo, $data);
    error_log('DEBUG: ' . json_encode($logData));
    return $logData;
}

// Include session tracer for more detailed debugging
require_once __DIR__ . '/session_trace.php';

// 1. Start session immediately before any other operations
// This is critical - if the session isn't started here, it might be too late
debug_log('Starting script execution');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
    debug_log('Session started manually');
} else {
    debug_log('Session already active');
}

// 2. Immediately refresh the session timestamp
$_SESSION['last_activity'] = time();
debug_log('Session timestamp refreshed', ['timestamp' => $_SESSION['last_activity']]);

// 3. Check if session is valid before proceeding
if (!isset($_SESSION['Sid'])) {
    debug_log('No student ID in session - redirecting to login');
    
    // Save POST data in temporary session for debugging
    $_SESSION['failed_submission'] = [
        'time' => time(),
        'post_count' => count($_POST),
        'post_keys' => array_keys($_POST)
    ];
    
    // Redirect to login with clear error message
    header('Location: studentLogout.php?expired=1&src=process_course&return=' . urlencode('courseReg_fixed.php'));
    exit;
}

// 4. Now that we've validated the session, continue with other includes
debug_log('Session valid, including other files', ['student_id' => $_SESSION['Sid']]);

try {
    // Include other required files
    require_once __DIR__ . '/../db/connect.php';
    require_once __DIR__ . '/includes/EligibilityService.php';
    require_once __DIR__ . '/includes/FeeGuard.php';
    
    debug_log('Required files included');
    
    // 5. Validate student ID from form matches session
    $sid = $_SESSION['Sid'];
    $formSid = $_POST['Sid'] ?? '';
    
    if ($formSid !== $sid) {
        debug_log('Form student ID mismatch', [
            'session_sid' => $sid,
            'form_sid' => $formSid
        ]);
        throw new Exception('Student ID mismatch');
    }
    
    debug_log('Student ID validated', ['sid' => $sid]);
    
    // 6. Extract and validate other form fields
    $semester = (int)($_POST['semester'] ?? 0);
    $year = (int)($_POST['Year'] ?? 0);
    $selected = isset($_POST['course_code']) ? (array)$_POST['course_code'] : [];
    
    if ($semester === 0 || $year === 0 || empty($selected)) {
        debug_log('Missing required fields', [
            'semester' => $semester,
            'year' => $year,
            'course_count' => count($selected)
        ]);
        throw new Exception('Missing semester/year or no courses selected.');
    }
    
    debug_log('Form data validated', [
        'semester' => $semester,
        'year' => $year,
        'course_count' => count($selected)
    ]);
    
    // 7. Check if database connection is valid
    if (!$db || !($db instanceof mysqli) || $db->connect_error) {
        debug_log('Database connection invalid', [
            'db_exists' => isset($db) ? 'yes' : 'no',
            'db_error' => $db->connect_error ?? 'unknown'
        ]);
        throw new Exception('Database connection failed');
    }
    
    debug_log('Database connection valid');
    
    // 8. Get program code for student
    $program = null;
    if ($res = $db->query("SELECT program_code FROM student_program WHERE Sid='" . $db->real_escape_string($sid) . "' ORDER BY id DESC LIMIT 1")) {
        if ($row = $res->fetch_assoc()) { $program = (string)$row['program_code']; }
        $res->free();
    }
    
    if ($program === null) {
        debug_log('No program found for student');
        throw new Exception('No program found for your account.');
    }
    
    debug_log('Program found', ['program_code' => $program]);
    
    // 9. Compute failures and flags with timeout protection
    debug_log('Getting failed courses');
    $startTime = microtime(true);
    
    $failed = EligibilityService::getFailedCourses($db, $sid);
    $flags = EligibilityService::computeFailureFlags(count($failed));
    
    $endTime = microtime(true);
    $executionTime = $endTime - $startTime;
    
    debug_log('Failed courses retrieved', [
        'failed_count' => count($failed),
        'execution_time' => $executionTime,
    ]);
    
    // 10. Start transaction with explicit session refresh
    $_SESSION['last_activity'] = time();
    debug_log('Refreshing session before transaction', ['timestamp' => $_SESSION['last_activity']]);
    
    $db->begin_transaction();
    debug_log('Transaction started');
    
    // 11. Process course registrations with progress tracking
    $insertCount = 0;
    foreach ($selected as $index => $code) {
        $codeEsc = $db->real_escape_string($code);
        
        // Check if already registered
        $check = $db->query("SELECT 1 FROM course_registration 
                           WHERE Sid='$sid' 
                           AND course_code='$codeEsc' 
                           AND semester='$semester' 
                           AND Year='$year' 
                           LIMIT 1");
        
        if ($check && $check->num_rows > 0) {
            $check->free();
            debug_log('Course already registered', ['course_code' => $code]);
            continue;
        }
        
        // Insert the course
        $insert = "INSERT INTO course_registration (Sid, course_code, semester, Year) 
                 VALUES ('$sid','$codeEsc','$semester','$year')";
                 
        if ($db->query($insert)) {
            $insertCount++;
            debug_log('Course registered', [
                'course_code' => $code,
                'index' => $index,
                'total_inserted' => $insertCount
            ]);
        } else {
            debug_log('Failed to insert course', [
                'course_code' => $code, 
                'error' => $db->error
            ]);
            throw new Exception("Failed to register course $code: " . $db->error);
        }
        
        // Refresh session every few operations to prevent timeout
        if ($index % 3 === 0) {
            $_SESSION['last_activity'] = time();
            debug_log('Session refreshed during processing', [
                'at_index' => $index,
                'timestamp' => $_SESSION['last_activity']
            ]);
        }
    }
    
    // 12. Calculate fees for invoice
    debug_log('Calculating fees');
    
    $totalCredits = 0;
    $fees = 0;
    
    // Get credit hours for all selected courses
    $placeholders = implode(',', array_fill(0, count($selected), '?'));
    
    $stmt = $db->prepare("SELECT course_code, credit_hours FROM courses WHERE course_code IN ($placeholders)");
    if (!$stmt) {
        debug_log('Failed to prepare statement', ['error' => $db->error]);
        throw new Exception("Failed to prepare statement: " . $db->error);
    }
    
    $types = str_repeat('s', count($selected));
    $stmt->bind_param($types, ...$selected);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $credit = isset($row['credit_hours']) ? intval($row['credit_hours']) : 3;
        $totalCredits += $credit;
        $fees += ($credit * 350);
        debug_log('Course fee calculated', [
            'course_code' => $row['course_code'],
            'credits' => $credit,
            'running_total' => $fees
        ]);
    }
    $stmt->close();
    
    // Add registration fee
    $fees += 150;
    debug_log('Fees calculated', [
        'total_credits' => $totalCredits,
        'base_fees' => $fees - 150,
        'registration_fee' => 150,
        'total_fees' => $fees
    ]);
    
    // 13. Generate invoice with session refresh
    $_SESSION['last_activity'] = time();
    debug_log('Session refreshed before invoice generation');
    
    $invoiceNumber = 'CR' . date('Ymd') . rand(1000, 9999);
    debug_log('Invoice number generated', ['invoice' => $invoiceNumber]);
    
    // 14. Insert invoice into student_payments
    $narration = "Course Registration Fee - $totalCredits credits";
    
    $insertStmt = $db->prepare("INSERT INTO student_payments 
                              (Sid, balance, invoice, semester, narration, Year, dte_time, amount_paid, payment_status) 
                              VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 'pending')");
                              
    if (!$insertStmt) {
        debug_log('Failed to prepare invoice statement', ['error' => $db->error]);
        throw new Exception("Failed to prepare invoice statement: " . $db->error);
    }
    
    $insertStmt->bind_param('sdssss', $sid, $fees, $invoiceNumber, $semester, $narration, $year);
    
    if (!$insertStmt->execute()) {
        debug_log('Failed to insert invoice', ['error' => $insertStmt->error]);
        throw new Exception("Failed to insert invoice: " . $insertStmt->error);
    }
    
    $insertStmt->close();
    debug_log('Invoice inserted successfully');
    
    // 15. Commit transaction and refresh session
    $db->commit();
    debug_log('Transaction committed');
    
    $_SESSION['last_activity'] = time();
    debug_log('Session refreshed after transaction');
    
    // 16. Store registration info in session
    $_SESSION['last_course_reg'] = [
        'Sid' => $sid,
        'semester' => $semester, 
        'Year' => $year, 
        'courses' => array_values($selected),
        'credits' => $totalCredits,
        'fees' => $fees,
        'invoice' => $invoiceNumber,
        'ts' => time()
    ];
    
    // 17. Flag to show invoice on fees page and set success message
    $_SESSION['show_course_invoice'] = true;
    $_SESSION['successMessage'] = "Successfully registered $insertCount courses. Invoice #$invoiceNumber created.";
    
    debug_log('Registration successful, redirecting to fees page', [
        'inserted_courses' => $insertCount,
        'invoice' => $invoiceNumber
    ]);
    
    // 18. Redirect to fees page
    header("Location: fees.php?invoice=$invoiceNumber");
    exit;
    
} catch (Throwable $e) {
    // 19. Handle errors with detailed logging
    debug_log('Error occurred', [
        'error_message' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'error_file' => $e->getFile(),
        'error_line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // Rollback any pending transaction
    try {
        if (isset($db) && $db instanceof mysqli) {
            $db->rollback();
            debug_log('Transaction rolled back');
        }
    } catch (Exception $rollbackError) {
        debug_log('Rollback failed', ['error' => $rollbackError->getMessage()]);
    }
    
    // Store error for display
    $_SESSION['failedMessage'] = 'Registration failed: ' . $e->getMessage();
    
    debug_log('Redirecting to course registration page with error');
    header("Location: courseReg_fixed.php?error=1&msg=" . urlencode($e->getMessage()));
    exit;
}
?>
