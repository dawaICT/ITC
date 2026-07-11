<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Keep the student session alive during AJAX operations
$_SESSION['last_activity'] = time();
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/StudentRegistrationSystem.php';

try {
    // Log request details
    error_log("process_registration.php - Request received: " . json_encode($_POST));

    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Get form data
    $studentId = $_POST['student_id'] ?? '';
    $academicYear = $_POST['academic_year'] ?? '';
    $yearOfStudy = (int)($_POST['year_of_study'] ?? 1);
    $semester = (int)($_POST['semester'] ?? 0);
    // Optional intake period model ('semester'|'term'); when omitted the
    // registration engine derives it from the student's program (programs.period_mode).
    $periodType = isset($_POST['period_type']) ? (string)$_POST['period_type'] : null;
    $isTransfer = isset($_POST['is_transfer']) && ($_POST['is_transfer'] === 'on' || $_POST['is_transfer'] == '1');
    // pay: '1' => register and pay (default), '0' => register only
    $pay = (isset($_POST['pay']) ? (string)$_POST['pay'] : '1') === '1';
    $selectedCourses = isset($_POST['courses']) ? explode(',', trim($_POST['courses'])) : [];
    $selectedCourses = array_filter($selectedCourses, 'strlen'); // Remove empty strings

    // Log processed parameters
    error_log("process_registration.php - Parameters: studentId=$studentId, academicYear=$academicYear, yearOfStudy=$yearOfStudy, semester=$semester, isTransfer=$isTransfer, courses=" . implode(',', $selectedCourses));

    // Validate required fields with detailed error messages
    $missingFields = [];
    if (empty($studentId)) { $missingFields[] = 'student_id'; }
    if (empty($academicYear)) { $missingFields[] = 'academic_year'; }
    if ($semester === 0 || $semester === '0' || empty($semester)) { $missingFields[] = 'semester'; }
    
    if (!empty($missingFields)) {
        throw new Exception('Missing required fields: ' . implode(', ', $missingFields));
    }

    // Initialize registration system
    $db = new Database();
    $registrationSystem = new StudentRegistrationSystem($db);

    // Validate course registration
    $validation = $registrationSystem->validateCourseRegistration($studentId, $selectedCourses, !$isTransfer);
    if (!$validation['isValid']) {
        throw new Exception('Course validation failed: ' . implode(', ', $validation['errors']));
    }

    // Process registration
    $registration = $registrationSystem->processRegistration($studentId, $selectedCourses, $semester, (string)$yearOfStudy, $academicYear, $periodType);

    $conn = $db->getConnection();

    // Fetch student's program code for fee lookup and invoice creation
    $programCode = '';
    try {
        $progStmt = $conn->prepare("SELECT program_code FROM student_program WHERE Sid = ? LIMIT 1");
        $progStmt->execute([$studentId]);
        $programCode = $progStmt->fetchColumn() ?: '';
        if (empty($programCode) && isset($_POST['program_code'])) {
            $programCode = $_POST['program_code'];
        }
        if (empty($programCode)) {
            $fallbackStmt = $conn->query("SELECT program_code FROM programs LIMIT 1");
            $programCode = $fallbackStmt->fetchColumn() ?: 'BSCS';
        }
    } catch (Throwable $e) {
        error_log("process_registration.php - Failed to fetch program_code: " . $e->getMessage());
        $programCode = 'BSCS';
    }

    // Calculate fees (preview already shown client-side); enforce here too
    $fees = $registrationSystem->calculateRegistrationFees($selectedCourses, $isTransfer, $programCode ?? null, $yearOfStudy, $semester);

    // Compute bursary coverage if assigned
    $bursaryPercent = 0.0;
    try {
        $pdo = $db->getConnection();
        $st = $pdo->prepare("SELECT fsd.coverage_percent 
                              FROM finance_student_sponsors fsd
                              JOIN finance_sponsors s ON s.id = fsd.sponsor_id AND s.status = 'active'
                              WHERE fsd.student_id = ?
                              ORDER BY fsd.id DESC LIMIT 1");
        $st->execute([$studentId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['coverage_percent'])) { $bursaryPercent = (float)$row['coverage_percent']; }
    } catch (Throwable $ignored) {}

    $bursaryAmount = round(($fees['total'] * ($bursaryPercent/100)), 2);
    $netAmount = max(0, $fees['total'] - $bursaryAmount);

    // Invoice handling: only create or reuse invoice when pay=true
    $invoice = [];
    $createdNewInvoice = false;
    $invoiceNumber = null;

    if ($pay) {
        // Generate invoice number candidate
        $invoiceNumber = date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // Check if an invoice already exists for this student/year/semester
        // Flexible academic year matching to handle format mismatch (e.g., '2025' vs '2025-2026')
        $yearPrefix = substr($academicYear, 0, 4);
        $invoiceColsStmt = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'");
        $invoiceColumns = $invoiceColsStmt ? $invoiceColsStmt->fetchAll(PDO::FETCH_COLUMN, 0) : [];
        $invoiceNumberCol = in_array('invoice_number', $invoiceColumns, true) ? 'invoice_number' : (in_array('invoice_no', $invoiceColumns, true) ? 'invoice_no' : (in_array('invoice', $invoiceColumns, true) ? 'invoice' : null));
        if ($invoiceNumberCol === null) {
            throw new Exception('Invoices table is missing a recognizable invoice number column.');
        }

        $whereParts = ['student_id = ?'];
        $whereParams = [$studentId];
        if (in_array('academic_year', $invoiceColumns, true)) {
            $whereParts[] = '(academic_year = ? OR academic_year LIKE ? OR ? LIKE CONCAT(academic_year, "%"))';
            array_push($whereParams, $academicYear, $yearPrefix . '%', $academicYear);
        }
        if (in_array('semester', $invoiceColumns, true)) {
            $whereParts[] = 'semester = ?';
            $whereParams[] = $semester;
        }
        $existingStmt = $conn->prepare("SELECT id, `{$invoiceNumberCol}` AS invoice_number, amount FROM invoices WHERE " . implode(' AND ', $whereParts) . " LIMIT 1");
        $existingStmt->execute($whereParams);
        $existingInvoice = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingInvoice) {
            // Reuse existing invoice; do not create duplicates
            $invoice['invoice_number'] = $existingInvoice['invoice_number'];
            $invoice['student_id'] = $studentId;
            $invoice['academic_year'] = $academicYear;
            $invoice['semester'] = $semester;
            $invoice['amount'] = (float)$existingInvoice['amount'];
            $invoice['status'] = 'Pending';
            $invoiceNumber = $invoice['invoice_number'];
        } else {
            // Generate invoice with all required fields
            $invoice = [
                'invoice_number' => $invoiceNumber,
                'student_id' => $studentId,
                'academic_year' => $academicYear,
                'semester' => $semester,
                'amount' => $netAmount,
                'status' => 'Pending',
                'program_code' => $programCode,
                'year_of_study' => (string)$yearOfStudy
            ];

            $fieldList = [];
            $placeholders = [];
            $params = [];
            $addInvoiceField = function(string $column, $value, bool $raw = false) use (&$fieldList, &$placeholders, &$params, $invoiceColumns) {
                if (!in_array($column, $invoiceColumns, true)) {
                    return;
                }
                $fieldList[] = "`$column`";
                if ($raw) {
                    $placeholders[] = (string)$value;
                } else {
                    $placeholders[] = '?';
                    $params[] = $value;
                }
            };

            $addInvoiceField($invoiceNumberCol, $invoice['invoice_number']);
            $addInvoiceField('student_id', $invoice['student_id']);
            $addInvoiceField('SID', $invoice['student_id']);
            $addInvoiceField('program_code', $invoice['program_code']);
            $addInvoiceField('semester', $invoice['semester']);
            $addInvoiceField('status', $invoice['status']);
            $addInvoiceField('Year', $invoice['year_of_study']);
            $addInvoiceField('amount', $invoice['amount']);
            $addInvoiceField('total_amount', $invoice['amount']);
            $addInvoiceField('balance', $invoice['amount']);
            $addInvoiceField('academic_year', $invoice['academic_year']);
            $addInvoiceField('invoice_date', 'NOW()', true);
            $addInvoiceField('created_at', 'NOW()', true);
            $addInvoiceField('updated_at', 'NOW()', true);

            $stmt = $conn->prepare("INSERT INTO invoices (" . implode(', ', $fieldList) . ") VALUES (" . implode(', ', $placeholders) . ")");
            $stmt->execute($params);
            $createdNewInvoice = true;
        }
    } else {
        // Register-only: do not create invoice; set placeholder values
        $invoice = [
            'invoice_number' => null,
            'student_id' => $studentId,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'amount' => 0,
            'status' => 'Not Invoiced'
        ];
    }

    // Ensure $invoiceNumber reflects the actual invoice we will use (reused or newly created)
    $invoiceNumber = $invoice['invoice_number'];

    // Also insert into student_payments table for balanceStatement.php compatibility only if we created a new invoice
    $referenceID = 'INV-' . date('YmdHis') . '-' . $invoice['invoice_number'];
    $narration = 'Registration Fee - Year ' . $academicYear . ' Semester ' . $semester;

    if ($createdNewInvoice) {
        // Build a resilient INSERT that adapts to existing columns
        $colsStmt = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_payments'");
        $colsStmt->execute();
        $spColumns = $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0);

        $fieldList = [];
        $placeholders = [];
        $params = [];

        $add = function(string $col, $value, bool $useNow = false) use (&$fieldList, &$placeholders, &$params, $spColumns) {
            if (in_array($col, $spColumns, true)) {
                $fieldList[] = "`$col`";
                if ($useNow) {
                    $placeholders[] = 'NOW()';
                } else {
                    $placeholders[] = '?';
                    $params[] = $value;
                }
            }
        };

        $add('Sid', $invoice['student_id']);
        $add('amount_paid', 0);
        $add('balance', $invoice['amount']);
        $add('channel', 'invoice');
        $add('payment_date', null, true); // use NOW() if column exists
        $add('academic_year', $academicYear);
        // Prefer semester_term; fallback to semester if present
        if (in_array('semester_term', $spColumns, true)) {
            $add('semester_term', (string)$semester);
        } else {
            $add('semester', (string)$semester);
        }
        $add('payment_status', 'pending');
        $add('reference_number', $referenceID);
        $add('description', $narration);

        // Optional legacy fields if they exist
        $add('narration', $narration);
        $add('Year', $academicYear);
        $add('dte_time', null, true); // use NOW() if present
        // Do NOT require 'invoice' column; skip if missing
        if (in_array('invoice', $spColumns, true)) {
            $add('invoice', $invoice['invoice_number']);
        }

        if (!empty($fieldList)) {
            $sql = 'INSERT INTO student_payments (' . implode(', ', $fieldList) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $paymentStmt = $conn->prepare($sql);
            $paymentStmt->execute($params);
        }
    }

    // Update student ID pool status from 'reserved' to 'assigned'
    try {
        $poolStmt = $conn->prepare("
            UPDATE student_id_pool
            SET status = 'assigned',
                assigned_at = NOW(),
                assigned_to = ?
            WHERE student_id = ? AND status = 'reserved'
        ");
        // Use session Sid (if available) as assigned_to, fallback to studentId
        $assignedTo = $_SESSION['Sid'] ?? $studentId;
        $poolStmt->execute([$assignedTo, $studentId]);
        $poolUpdated = $poolStmt->rowCount() > 0;

        if ($poolUpdated) {
            error_log("process_registration.php - Student ID $studentId marked as assigned in pool");
        } else {
            error_log("process_registration.php - Warning: Student ID $studentId not found in pool or not in reserved state");
        }
    } catch (Exception $poolError) {
        error_log("process_registration.php - Warning: Failed to update student ID pool: " . $poolError->getMessage());
        // Don't fail the registration if pool update fails
    }

    // Log success
    error_log("process_registration.php - Registration successful: invoice=" . ($invoiceNumber ?? 'NULL') . ", gross={$fees['total']}, bursary={$bursaryPercent}%, net={$invoice['amount']}");

    // Persist invoice/registration details for the student Fees page banner
    try {
        $_SESSION['show_course_invoice'] = true;
        $_SESSION['last_course_reg'] = [
            'Sid' => (string)$studentId,
            'invoice' => (string)$invoice['invoice_number'],
            'semester' => (string)$semester,
            'Year' => (string)$academicYear,
            'fees' => (float)$invoice['amount'],
            'courses' => array_values($selectedCourses),
            'credits' => (int)($validation['totalCredits'] ?? 0),
        ];
    } catch (Throwable $ignored) {}

    // Return success response. If pay=false, omit next_url to prevent automatic redirect to payment.
    $response = [
        'success' => true,
        'message' => 'Registration successful',
        'registration_id' => $registration['registration_id'],
        'invoice_number' => $invoice['invoice_number'],
        'amount' => $invoice['amount'],
        'bursary_percent' => $bursaryPercent,
        'gross_amount' => $fees['total']
    ];
    if ($pay && !empty($invoice['invoice_number'])) {
        $response['next_url'] = 'fees.php?invoice=' . urlencode($invoice['invoice_number']);
    } else {
        $response['next_url'] = null;
    }

    echo json_encode($response);

} catch (Exception $e) {
    // Log error
    error_log("process_registration.php - Error: " . $e->getMessage());

    // If student ID was reserved but registration failed, release it back to available
    if (isset($studentId) && !empty($studentId)) {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            $releaseStmt = $conn->prepare("
                UPDATE student_id_pool
                SET status = 'available',
                    assigned_at = NULL,
                    assigned_to = NULL
                WHERE student_id = ? AND status = 'reserved'
            ");
            $releaseStmt->execute([$studentId]);
            $released = $releaseStmt->rowCount() > 0;

            if ($released) {
                error_log("process_registration.php - Student ID $studentId released back to available pool due to registration failure");
            }
        } catch (Exception $releaseError) {
            error_log("process_registration.php - Warning: Failed to release student ID $studentId: " . $releaseError->getMessage());
        }
    }

    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} 
