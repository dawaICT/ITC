<?php
error_reporting(0);
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/EligibilityService.php';
require_once __DIR__ . '/includes/AcademicSessionService.php';
require_once __DIR__ . '/../includes/payment_helpers.php';

$sessionService = new AcademicSessionService($db);
$currentSession = $sessionService->getCurrentSession();

// Sanitize and normalize POST inputs
$Sid = trim($_POST["Sid"] ?? '');
$program_code = trim($_POST["program_code"] ?? '');
$semester = trim($_POST["semester"] ?? '');
$Year = trim($_POST["Year"] ?? '');
$balance = trim($_POST["balance"] ?? '0.00');
$academic_year = trim($_POST["academic_year"] ?? ($currentSession['academic_year'] ?? ''));

// Validate academic session
if ($currentSession && $academic_year !== $currentSession['academic_year']) {
    // Attempting to register for a non-current year (unless specifically allowed)
    // For now, we enforce the current one to follow user request
    $academic_year = $currentSession['academic_year'];
}

// Use the session SID if POST is empty (security check)
if (empty($Sid)) { $Sid = $_SESSION['Sid'] ?? ''; }

// Fetch student names for display
$studentName = "Student";
$nameSql = "SELECT Fname, Lname FROM students WHERE SID = ? LIMIT 1";
if ($nStmt = $db->prepare($nameSql)) {
    $nStmt->bind_param('s', $Sid);
    $nStmt->execute();
    $nRes = $nStmt->get_result();
    if ($nr = $nRes->fetch_assoc()) {
        $studentName = ($nr['Fname'] ?? '') . ' ' . ($nr['Lname'] ?? '');
    }
}

// If no data, redirect back
if (empty($Sid) || empty($program_code) || empty($semester) || empty($Year)) {
    header("Location: searchReturning_Stud.php");
    exit;
}

// 1. Check if the student is already registered for this term (semester_registration)
$checkQuery = "SELECT id FROM semester_registration 
               WHERE SID = ? AND program_code = ? AND academic_year = ? AND semester = ?
               LIMIT 1";

$stmt = $db->prepare($checkQuery);
$stmt->bind_param('ssss', $Sid, $program_code, $academic_year, $semester);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows > 0) {
    // Already registered, redirect to course pick page
    header("Location: courseReg.php?semester=$semester&Year=$Year&academic_year=$academic_year");
    exit;
}

// 2. Fetch financial settings and fee amount if registration is restricted by payment
$allowWithArrears = false;
try {
    if ($rs = $db->query("SELECT setting_value FROM portal_settings WHERE setting_key = 'allow_registration_with_arrears' LIMIT 1")) {
        if ($row = $rs->fetch_assoc()) { $allowWithArrears = ($row['setting_value'] === '1'); }
        $rs->free();
    }
} catch (Throwable $e) { /* default to false */ }

// Fetch the fee amount for this term from program_fees
$invoiceAmount = 0.00;
$feeFound = false;

// Fee comes from the live normalized fee_structure table maintained by accounts.
$feeSql = "SELECT COALESCE(SUM(amount),0) AS amount FROM fee_structure
           WHERE program_code = ? AND year_of_study = ? AND semester = ? AND status='active'";
if ($fStmt = $db->prepare($feeSql)) {
    $fStmt->bind_param('sss', $program_code, $Year, $semester);
    $fStmt->execute();
    $fRes = $fStmt->get_result();
    if ($fr = $fRes->fetch_assoc()) {
        $invoiceAmount = (float)($fr['amount'] ?? 0.00);
        $feeFound = true;
    }
}

// 3. Handle Auto-Registration (if Arrears Allowed)
if ($allowWithArrears) {
    // Insert into semester_registration directly
    $failedCourses = EligibilityService::getFailedCourses($db, $Sid);
    $flags = EligibilityService::computeFailureFlags(count($failedCourses));
    $hasFailed = !empty($flags['repeat_semester']) || !empty($flags['auto_append_failed']) ? 1 : 0;
    $failedList = implode(',', array_map(function($f){ return $f['course_code']; }, $failedCourses));
    
    // NOTE: failed-course flags are computed for the redirect logic but not
    // persisted — semester_registration has no has_failed_courses column.
    $insSql = "INSERT INTO semester_registration (program_code, SID, semester, academic_year, registration_date, created_at)
               VALUES (?, ?, ?, ?, NOW(), NOW())";

    if ($ins = $db->prepare($insSql)) {
        $ins->bind_param('ssss', $program_code, $Sid, $semester, $academic_year);
        try {
            if ($ins->execute()) {
                header("Location: courseReg.php?semester=$semester&Year=$Year&academic_year=$academic_year");
                exit;
            }
        } catch (mysqli_sql_exception $e) {
            // Handle duplicate entry error - student already registered
            if (strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getCode(), '1062') !== false) {
                // Already registered, redirect to course pick page
                header("Location: courseReg.php?semester=$semester&Year=$Year&academic_year=$academic_year");
                exit;
            }
            // Re-throw other exceptions
            throw $e;
        }
    }
    // If insertion failed, fall through to show UI
}

// 4. Handle Final Form Submission (from Invoice View)
if (isset($_POST['final_proceed'])) {
    // Process payment record (simulated) and register
    $finalInvoice = $_POST['invoice_amt'] ?? 0.00;
    $finalBalance = $_POST['total_balance'] ?? 0.00;
    
    $created = payment_create_student_invoice($db, $Sid, (float)$finalInvoice, $academic_year, $semester, 'Semester Invoice');
    if (!empty($created['success']) || !empty($created['duplicate'])) {
            // Register for semester
            $regSql = "INSERT INTO semester_registration (program_code, SID, semester, academic_year, registration_date, created_at) 
                       VALUES (?, ?, ?, ?, NOW(), NOW())";
            if ($rStmt = $db->prepare($regSql)) {
                $rStmt->bind_param('ssss', $program_code, $Sid, $semester, $academic_year);
                try {
                    if ($rStmt->execute()) {
                        header("Location: courseReg.php?semester=$semester&Year=$Year&academic_year=$academic_year");
                        exit;
                    }
                } catch (mysqli_sql_exception $e) {
                    // Handle duplicate entry error - student already registered
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getCode(), '1062') !== false) {
                        // Already registered, redirect to course pick page
                        header("Location: courseReg.php?semester=$semester&Year=$Year&academic_year=$academic_year");
                        exit;
                    }
                    // Re-throw other exceptions
                    throw $e;
                }
            }
    }
    $error = "Failed to process registration. Please consult system administrator.";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Registration Invoice | ITC Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-purple: #6f42c1;
            --secondary-purple: #8951ff;
            --light-purple: #f3effb;
        }

        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; color: #333; }
        .page-header { background: white; border-bottom: 1px solid #eee; padding: 1.5rem 0; margin-bottom: 3rem; }
        .invoice-card { border: none; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.06); overflow: hidden; }
        .invoice-header { background: linear-gradient(135deg, var(--primary-purple), var(--secondary-purple)); color: white; padding: 2.5rem; }
        .invoice-body { padding: 3rem; background: white; }
        
        .table-invoice th { color: #6c757d; font-weight: 500; border-bottom: none; }
        .table-invoice td { font-weight: 600; font-size: 1.1rem; padding: 1.25rem 0.75rem; border-bottom: 1px solid #f0f0f0; }

        .total-row td { border-bottom: none; font-size: 1.4rem; color: var(--primary-purple); font-weight: 800; }
        .btn-proceed { background: var(--primary-purple); color: white; border: none; padding: 1rem 3rem; border-radius: 12px; font-weight: 700; width: 100%; transition: 0.3s; }
        .btn-proceed:hover { background: var(--secondary-purple); transform: translateY(-2px); box-shadow: 0 8px 20px rgba(111, 66, 193, 0.2); color: white; }
    </style>
</head>
<body>
    <div class="page-header shadow-sm">
        <div class="container d-flex align-items-center">
            <img src="/wucportal/images/favicon.png" height="40" width="40" class="me-3" alt="Logo">
            <h4 class="mb-0 fw-bold">ITC Student Portal</h4>
        </div>
    </div>

    <div class="container pb-5">
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="invoice-card">
                    <div class="invoice-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="fw-bold mb-1">Registration Invoice</h2>
                                <p class="mb-0 opacity-75">Year <?php echo $Year; ?>, Semester <?php echo $semester; ?></p>
                            </div>
                            <div class="text-end">
                                <i class="fas fa-file-invoice-dollar fa-3x"></i>
                            </div>
                        </div>
                    </div>
                    <div class="invoice-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger rounded-4 border-0 mb-4"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <div class="mb-5 border-bottom pb-4">
                            <h6 class="text-uppercase text-muted fw-bold mb-3 small tracking-wider">Student Details</h6>
                            <div class="row">
                                <div class="col-4">
                                    <p class="text-muted mb-0 small">Name</p>
                                    <p class="fw-bold mb-0"><?php echo htmlspecialchars($studentName); ?></p>
                                </div>
                                <div class="col-4">
                                    <p class="text-muted mb-0 small">Student ID</p>
                                    <p class="fw-bold mb-0"><?php echo htmlspecialchars($Sid); ?></p>
                                </div>
                                <div class="col-4">
                                    <p class="text-muted mb-0 small">Program</p>
                                    <p class="fw-bold mb-0"><?php echo htmlspecialchars($program_code); ?></p>
                                </div>
                            </div>
                        </div>

                        <h6 class="text-uppercase text-muted fw-bold mb-4 small tracking-wider">Fee Summary</h6>
                        <table class="table table-hover align-middle table-invoice mb-5">
                            <thead class="table-light">
                                <tr>
                                    <th>Description</th>
                                    <th class="text-end">Amount (ZMW)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Semester Tuition & Registration</td>
                                    <td class="text-end"><?php echo number_format($invoiceAmount, 2); ?></td>
                                </tr>
                                <tr>
                                    <td>Previous Outstanding Balance</td>
                                    <td class="text-end"><?php echo number_format($balance, 2); ?></td>
                                </tr>
                                <tr class="total-row">
                                    <td class="pt-4">Total Amount Due</td>
                                    <td class="text-end pt-4"><?php echo number_format($invoiceAmount + $balance, 2); ?></td>
                                </tr>
                            </tbody>
                        </table>

                        <form action="" method="POST">
                            <input type="hidden" name="Sid" value="<?php echo htmlspecialchars($Sid); ?>">
                            <input type="hidden" name="program_code" value="<?php echo htmlspecialchars($program_code); ?>">
                            <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                            <input type="hidden" name="Year" value="<?php echo htmlspecialchars($Year); ?>">
                            <input type="hidden" name="invoice_amt" value="<?php echo $invoiceAmount; ?>">
                            <input type="hidden" name="total_balance" value="<?php echo ($invoiceAmount + $balance); ?>">
                            
                            <button type="submit" name="final_proceed" class="btn-proceed">
                                Accept Invoice & Complete Registration <i class="fas fa-arrow-right ms-2"></i>
                            </button>
                        </form>
                        
                        <div class="text-center mt-4">
                            <a href="searchReturning_Stud.php" class="text-muted text-decoration-none small">
                                <i class="fas fa-times me-1"></i> Cancel and go back
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
                                    die();
