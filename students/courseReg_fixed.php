<?php
// Enhanced course registration page with robust session handling
// Start session immediately to avoid timing issues
if (session_status() === PHP_SESSION_NONE) { 
    session_start();
}

// Refresh session timestamp right away
$_SESSION['last_activity'] = time();

// Perform auth check early
if (!isset($_SESSION['Sid'])) {
    header('Location: studentLogout.php?expired=1&return=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Include other required files
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/includes/EligibilityService.php';
require_once __DIR__ . '/includes/FeeGuard.php';

// Initialize variables
$records = [];
$semester = '';
$Year = '';
$program = '';
$sid = isset($_SESSION['Sid']) ? (string)$_SESSION['Sid'] : '';

// Detect if the form is being submitted
$isSubmit = isset($_POST['course_submit']) && $_POST['course_submit'] === '1';

// Process form submission
$submitResult = null;
if ($isSubmit) {
    try {
        // Verify required data is present
        if (!isset($_POST['Sid']) || !isset($_POST['semester']) || !isset($_POST['Year']) || !isset($_POST['course_code']) || !is_array($_POST['course_code'])) {
            throw new Exception("Missing required form fields");
        }
        
        // Validate student ID matches session
        if ($_POST['Sid'] !== $sid) {
            throw new Exception("Student ID mismatch");
        }
        
        // Get selected courses
        $selectedCourses = array_filter($_POST['course_code'], 'is_string');
        if (empty($selectedCourses)) {
            throw new Exception("No courses selected");
        }
        
        // Begin transaction
        $db->begin_transaction();
        
        // Register the courses
        $insertCount = 0;
        foreach ($selectedCourses as $course) {
            $course = $db->real_escape_string($course);
            $semester = $db->real_escape_string($_POST['semester']);
            $year = $db->real_escape_string($_POST['Year']);
            
            // Check if already registered
            $checkSql = "SELECT 1 FROM course_registration WHERE Sid = '$sid' AND course_code = '$course' AND semester = '$semester' AND Year = '$year'";
            $checkResult = $db->query($checkSql);
            if ($checkResult && $checkResult->num_rows > 0) {
                $checkResult->free();
                continue;
            }
            
            // Insert the course
            $insertSql = "INSERT INTO course_registration (Sid, course_code, semester, Year) VALUES ('$sid', '$course', '$semester', '$year')";
            if ($db->query($insertSql)) {
                $insertCount++;
            } else {
                throw new Exception("Failed to insert course $course: " . $db->error);
            }
        }
        
        // Calculate fees
        $totalCredits = 0;
        $fees = 0;
        $placeholders = array_fill(0, count($selectedCourses), '?');
        $placeholdersStr = implode(',', $placeholders);
        
        $stmt = $db->prepare("SELECT course_code, credit_hours FROM courses WHERE course_code IN ($placeholdersStr)");
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $db->error);
        }
        
        $types = str_repeat('s', count($selectedCourses));
        $stmt->bind_param($types, ...$selectedCourses);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $credits = isset($row['credit_hours']) ? intval($row['credit_hours']) : 3;
            $totalCredits += $credits;
            $fees += ($credits * 350);
        }
        $stmt->close();
        
        // Add registration fee
        $fees += 150;
        
        // Generate invoice number
        $invoiceNumber = 'CR' . date('Ymd') . rand(1000, 9999);
        
        // Insert invoice
        $narration = "Course Registration Fee - $totalCredits credit hours";
        $insertStmt = $db->prepare("INSERT INTO student_payments (Sid, balance, invoice, semester, narration, Year, dte_time, amount_paid, payment_status) VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 'pending')");
        if (!$insertStmt) {
            throw new Exception("Failed to prepare invoice statement: " . $db->error);
        }
        
        $insertStmt->bind_param('sdssss', $sid, $fees, $invoiceNumber, $semester, $narration, $year);
        if (!$insertStmt->execute()) {
            throw new Exception("Failed to insert invoice: " . $insertStmt->error);
        }
        $insertStmt->close();
        
        // Commit transaction
        $db->commit();
        
        // Store registration info for display
        $_SESSION['last_course_reg'] = [
            'Sid' => $sid,
            'semester' => $semester, 
            'Year' => $year, 
            'courses' => $selectedCourses,
            'credits' => $totalCredits,
            'fees' => $fees,
            'invoice' => $invoiceNumber,
            'ts' => time()
        ];
        
        // Flag to show invoice on fees page
        $_SESSION['show_course_invoice'] = true;
        
        // Set success message and redirect
        $_SESSION['successMessage'] = "Successfully registered $insertCount courses. Invoice #$invoiceNumber created.";
        header("Location: fees.php?invoice=$invoiceNumber");
        exit;
        
    } catch (Exception $e) {
        // Roll back transaction if needed
        try {
            if ($db) {
                $db->rollback();
            }
        } catch (Exception $rollbackError) {
            // Ignore rollback errors
        }
        
        // Store error for display
        $_SESSION['failedMessage'] = 'Registration failed: ' . $e->getMessage();
        $submitResult = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Determine the latest semester registration for this student
if ($sid !== '') {
    // Get semester registration info
    if ($res = $db->query("SELECT semester, Year, program_code FROM semester_registration WHERE Sid='".$db->real_escape_string($sid)."' ORDER BY id DESC LIMIT 1")) {
        if ($row = $res->fetch_assoc()) {
            $semester = (string)$row['semester'];
            $Year = (string)$row['Year'];
            $program = (string)$row['program_code'];
        }
        $res->free();
    }
    
    if ($semester && $Year && $program) {
        // Enforce 50% payment threshold before showing/allowing course registration
        $check = fg_check_fee_threshold($db, $sid, (int)$Year, (int)$semester, 50.0);
        if ($check['ok']) {
            // Get available courses
            $recordsSet = [];
            $sql = "SELECT DISTINCT cl.course_code FROM course_levels cl 
                   WHERE cl.program_code='".$db->real_escape_string($program)."' 
                   AND cl.semester='".$db->real_escape_string($semester)."' 
                   AND cl.Year='".$db->real_escape_string($Year)."' 
                   ORDER BY cl.course_code";
            if ($res = $db->query($sql)) {
                while ($row = $res->fetch_assoc()) {
                    $recordsSet[$row['course_code']] = true;
                }
                $res->free();
            }
            
            // Get already registered courses
            $preselected = [];
            $sql = "SELECT DISTINCT course_code FROM course_registration 
                   WHERE Sid='".$db->real_escape_string($sid)."' 
                   AND semester='".$db->real_escape_string($semester)."' 
                   AND Year='".$db->real_escape_string($Year)."'";
            if ($res = $db->query($sql)) {
                while ($row = $res->fetch_assoc()) {
                    $preselected[$row['course_code']] = true;
                }
                $res->free();
            }
            
            // Failed courses that need to be retaken
            $failed = EligibilityService::getFailedCourses($db, $sid);
            $flags = EligibilityService::computeFailureFlags(count($failed));
            
            // Build records array
            $records = [];
            foreach (array_keys($recordsSet) as $cc) { 
                $records[] = (object)['course_code' => $cc]; 
            }
            
            // Make preselected available to template
            $GLOBALS['__preselected_courses'] = $preselected;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Course Registration (Fixed)</title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
</head>
<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <div class="content-wrapper">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h4 class="mb-0">Course Registration (Fixed Version)</h4>
                        </div>
                        <div class="card-body">
                            <?php if (isset($_SESSION['successMessage'])): ?>
                                <div class="alert alert-success">
                                    <?= htmlspecialchars($_SESSION['successMessage']) ?>
                                    <?php unset($_SESSION['successMessage']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (isset($_SESSION['failedMessage'])): ?>
                                <div class="alert alert-danger">
                                    <?= htmlspecialchars($_SESSION['failedMessage']) ?>
                                    <?php unset($_SESSION['failedMessage']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($submitResult && !$submitResult['success']): ?>
                                <div class="alert alert-danger">
                                    <?= htmlspecialchars($submitResult['error']) ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (empty($semester) || empty($Year)): ?>
                                <div class="alert alert-warning">No active semester registration found. Please complete semester registration first.</div>
                            <?php elseif (!isset($check) || !$check['ok']): ?>
                                <div class="alert alert-danger">
                                    <?= htmlspecialchars($check['message'] ?? 'Please pay at least 50% of your tuition fees before registering courses for this term.') ?>
                                </div>
                            <?php elseif (empty($records)): ?>
                                <div class="alert alert-danger">No courses found for your registered term.</div>
                            <?php else: ?>
                                <div class="alert alert-success text-center">Courses for your registered term.</div>
                                <form action="courseReg_fixed.php" method="POST" id="courseRegistrationForm" class="mt-4">
                                    <input type="hidden" name="course_submit" value="1">
                                    
                                    <div class="mb-3">
                                        <label for="Sid">Student ID:</label>
                                        <input type="text" class="form-control" name="Sid" id="Sid" 
                                            value="<?= htmlspecialchars($sid) ?>" readonly>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="semester">Semester:</label>
                                            <input type="text" class="form-control" name="semester" id="semester"
                                                value="<?= htmlspecialchars($semester) ?>" readonly>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="Year">Year:</label>
                                            <input type="text" class="form-control" name="Year" id="Year"
                                                value="<?= htmlspecialchars($Year) ?>" readonly>
                                        </div>
                                    </div>
                                    
                                    <?php if (isset($flags) && !empty($flags['repeat_semester'])): ?>
                                    <div class="alert alert-danger">
                                        <strong>Repeat Semester Required:</strong> You have failed courses that must be retaken.
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="mb-3">
                                        <label for="course_selector">Select Courses:</label>
                                        <div class="form-control" style="height: auto; max-height: 300px; overflow-y: auto;">
                                            <?php foreach($records as $course): 
                                                $isPreselected = isset($preselected[$course->course_code]);
                                                $isMandatory = isset($flags) && !empty($flags['repeat_semester']) && in_array($course->course_code, array_column($failed, 'course_code'));
                                            ?>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="course_code[]" 
                                                        value="<?= htmlspecialchars($course->course_code) ?>" 
                                                        id="course_<?= htmlspecialchars($course->course_code) ?>"
                                                        <?= $isPreselected ? 'checked' : '' ?>
                                                        <?= $isMandatory ? 'required' : '' ?>>
                                                    <label class="form-check-label <?= $isMandatory ? 'text-danger fw-bold' : '' ?>" 
                                                        for="course_<?= htmlspecialchars($course->course_code) ?>">
                                                        <?= htmlspecialchars($course->course_code) ?>
                                                        <?= $isMandatory ? ' (Required)' : '' ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save"></i> Register Courses
                                        </button>
                                        <a href="fees.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-money-bill"></i> View Fees
                                        </a>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('courseRegistrationForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                const checkboxes = form.querySelectorAll('input[type="checkbox"]:checked');
                if (checkboxes.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one course before submitting.');
                }
                
                // Find required checkboxes
                const required = form.querySelectorAll('input[type="checkbox"][required]:not(:checked)');
                if (required.length > 0) {
                    e.preventDefault();
                    alert('You must select the required courses marked in red.');
                }
            });
        }
        
        // Keep session alive
        setInterval(function() {
            fetch('api_get_eligibility.php?keepAlive=1&_=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store'
            }).catch(console.error);
        }, 240000); // 4 minutes
    });
    </script>
</body>
</html>

