<?php
// Start session immediately
if (session_status() === PHP_SESSION_NONE) { 
    session_start();
}

// Refresh session timestamp right away
$_SESSION['last_activity'] = time();

// Set error reporting
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Include session tracker for debugging
require_once __DIR__ . '/session_trace.php';
log_session_state('course_reg_with_debug.php start');

// Check if the user is logged in
if (!isset($_SESSION['Sid'])) {
    header('Location: studentLogout.php?expired=1&return=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Log user info
log_session_state('user authenticated', [
    'student_id' => $_SESSION['Sid']
]);

// Include database connection
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/includes/EligibilityService.php';
require_once __DIR__ . '/includes/FeeGuard.php';

// Initialize variables
$records = [];
$semester = '';
$Year = '';
$program = '';
$sid = isset($_SESSION['Sid']) ? (string)$_SESSION['Sid'] : '';

// Determine the latest semester registration for this student
if ($sid !== '') {
    // Discover semester_registration column names robustly
    $cols = [];
    if ($meta = $db->query("SHOW COLUMNS FROM semester_registration")) {
        while ($c = $meta->fetch_assoc()) { $cols[strtolower((string)$c['Field'])] = (string)$c['Field']; }
        $meta->free();
    }
    // Use correct column names
    $srSidCol  = $cols['student_id'] ?? ($cols['sid'] ?? ($cols['student'] ?? 'student_id'));
    $srSemCol  = $cols['semester'] ?? ($cols['semester_term'] ?? ($cols['term'] ?? 'semester'));
    $srYearCol = $cols['year_of_study'] ?? ($cols['year'] ?? ($cols['academic_year'] ?? 'year_of_study'));
    
    // Get semester registration info
    if ($res = $db->query("SELECT `{$srSemCol}` AS semester, `{$srYearCol}` AS Year, program_code FROM semester_registration WHERE `{$srSidCol}`='".$db->real_escape_string($sid)."' ORDER BY id DESC LIMIT 1")) {
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

// Log course data
log_session_state('data loaded', [
    'semester' => $semester,
    'year' => $Year,
    'program' => $program,
    'course_count' => count($records),
    'preselected_count' => count($preselected ?? [])
]);

// Function to create a session debug report
function generateSessionReport() {
    $report = [];
    
    // Basic session info
    $report['session_id'] = session_id();
    $report['session_status'] = session_status() === PHP_SESSION_ACTIVE ? 'active' : 'inactive';
    $report['session_name'] = session_name();
    
    // Session timeout calculation
    if (isset($_SESSION['last_activity'])) {
        $elapsed = time() - $_SESSION['last_activity'];
        $remaining = 300 - $elapsed; // 5 minutes timeout
        $report['last_activity_time'] = date('H:i:s', $_SESSION['last_activity']);
        $report['elapsed_seconds'] = $elapsed;
        $report['remaining_seconds'] = $remaining;
        $report['will_timeout_soon'] = $remaining < 60;
    } else {
        $report['last_activity'] = 'not set';
    }
    
    // Cookie info
    $report['session_cookie_exists'] = isset($_COOKIE[session_name()]);
    $report['session_cookie_value'] = isset($_COOKIE[session_name()]) ? substr($_COOKIE[session_name()], 0, 10) . '...' : 'not set';
    
    return $report;
}

// Get session report
$sessionReport = generateSessionReport();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Course Registration (Debug)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <style>
        .session-info {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }
        .session-timer {
            font-weight: bold;
        }
        .session-timer.warning {
            color: #dc3545;
        }
        .mandatory-course {
            background-color: #f8d7da;
        }
        .check-column {
            width: 50px;
        }
        .debug-panel {
            position: fixed;
            bottom: 0;
            right: 0;
            width: 300px;
            background: rgba(0, 0, 0, 0.7);
            color: #00ff00;
            font-family: monospace;
            font-size: 12px;
            padding: 10px;
            z-index: 9999;
            max-height: 200px;
            overflow-y: auto;
            border-top-left-radius: 5px;
        }
        .debug-line {
            margin-bottom: 5px;
            word-break: break-all;
        }
    </style>
</head>
<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <!-- Debug Panel -->
    <div class="debug-panel">
        <div class="debug-line">Session ID: <?= session_id() ?></div>
        <div class="debug-line">Last Activity: <?= isset($_SESSION['last_activity']) ? date('H:i:s', $_SESSION['last_activity']) : 'Not set' ?></div>
        <div class="debug-line">Time Remaining: <span id="session-timer">calculating...</span></div>
        <div class="debug-line">Student ID: <?= $_SESSION['Sid'] ?? 'Not set' ?></div>
    </div>
    
    <div class="content-wrapper">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                            <h4 class="mb-0">Course Registration (Debug Version)</h4>
                            
                            <!-- Session Timer -->
                            <?php if (isset($sessionReport['remaining_seconds'])): ?>
                                <div class="session-timer <?= $sessionReport['will_timeout_soon'] ? 'warning' : '' ?>">
                                    Session: <?= floor($sessionReport['remaining_seconds'] / 60) ?>:<?= str_pad($sessionReport['remaining_seconds'] % 60, 2, '0', STR_PAD_LEFT) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="card-body">
                            <!-- Session Info -->
                            <div class="session-info">
                                <div class="row">
                                    <div class="col-md-4">
                                        <strong>Session ID:</strong> <?= $sessionReport['session_id'] ?><br>
                                        <strong>Status:</strong> <?= $sessionReport['session_status'] ?>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Last Activity:</strong> <?= $sessionReport['last_activity_time'] ?? 'Not set' ?><br>
                                        <strong>Student ID:</strong> <?= $_SESSION['Sid'] ?? 'Not set' ?>
                                    </div>
                                    <div class="col-md-4">
                                        <button id="refresh-session" class="btn btn-sm btn-info">Refresh Session</button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Messages -->
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
                            
                            <?php if (isset($_GET['error'])): ?>
                                <div class="alert alert-danger">
                                    Error: <?= htmlspecialchars($_GET['msg'] ?? 'Unknown error') ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Main Content -->
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
                                
                                <!-- Registration Form with Debug Submission -->
                                <form action="processCourseReg_debug.php" method="POST" id="courseRegistrationForm" class="mt-4">
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
                                        
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th class="check-column">Select</th>
                                                        <th>Course Code</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    foreach($records as $course): 
                                                        $code = $course->course_code;
                                                        $isPreselected = isset($preselected[$code]);
                                                        $isMandatory = isset($flags) && !empty($flags['repeat_semester']) && in_array($code, array_column($failed, 'course_code'));
                                                    ?>
                                                        <tr class="<?= $isMandatory ? 'mandatory-course' : '' ?>">
                                                            <td>
                                                                <div class="form-check">
                                                                    <input class="form-check-input" type="checkbox" name="course_code[]" 
                                                                        value="<?= htmlspecialchars($code) ?>" 
                                                                        id="course_<?= htmlspecialchars($code) ?>"
                                                                        <?= $isPreselected ? 'checked' : '' ?>
                                                                        <?= $isMandatory ? 'required' : '' ?>>
                                                                </div>
                                                            </td>
                                                            <td><?= htmlspecialchars($code) ?></td>
                                                            <td>
                                                                <?php if ($isPreselected): ?>
                                                                    <span class="badge bg-success">Registered</span>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($isMandatory): ?>
                                                                    <span class="badge bg-danger">Required</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="alert alert-info" id="selected-count">
                                            No courses selected
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between">
                                        <button type="submit" class="btn btn-primary" id="submit-btn">
                                            <i class="fas fa-save me-1"></i> Register Courses (Debug Mode)
                                        </button>
                                        
                                        <a href="session_trace.php" class="btn btn-secondary">
                                            <i class="fas fa-bug me-1"></i> Session Debug Tool
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
        // Handle checkboxes and form submission
        const form = document.getElementById('courseRegistrationForm');
        const checkboxes = form ? form.querySelectorAll('input[type="checkbox"]') : [];
        const selectedCount = document.getElementById('selected-count');
        const submitBtn = document.getElementById('submit-btn');
        
        // Required checkboxes (failed courses)
        const requiredBoxes = form ? form.querySelectorAll('input[type="checkbox"][required]') : [];
        
        // Update selected count
        function updateCount() {
            let count = 0;
            checkboxes.forEach(box => {
                if (box.checked) count++;
            });
            
            if (selectedCount) {
                if (count === 0) {
                    selectedCount.textContent = 'No courses selected';
                    selectedCount.className = 'alert alert-warning';
                } else {
                    selectedCount.textContent = `${count} course(s) selected`;
                    selectedCount.className = 'alert alert-info';
                }
            }
            
            if (submitBtn) {
                submitBtn.disabled = count === 0;
            }
        }
        
        // Add listeners to checkboxes
        checkboxes.forEach(box => {
            box.addEventListener('change', updateCount);
            
            // Make sure required checkboxes can't be unchecked
            if (box.hasAttribute('required')) {
                box.addEventListener('click', function(e) {
                    if (this.checked === false) {
                        e.preventDefault();
                        alert('This course is required and cannot be deselected.');
                    }
                });
            }
        });
        
        // Initialize count
        updateCount();
        
        // Form validation
        if (form) {
            form.addEventListener('submit', function(e) {
                // Check if any courses selected
                let hasSelected = false;
                checkboxes.forEach(box => {
                    if (box.checked) hasSelected = true;
                });
                
                if (!hasSelected) {
                    e.preventDefault();
                    alert('Please select at least one course before submitting.');
                    return false;
                }
                
                // Check required courses
                let allRequiredChecked = true;
                requiredBoxes.forEach(box => {
                    if (!box.checked) allRequiredChecked = false;
                });
                
                if (!allRequiredChecked) {
                    e.preventDefault();
                    alert('You must select all required courses.');
                    return false;
                }
                
                // Add loading state
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Submitting...';
                submitBtn.disabled = true;
                
                // Add timestamp to prevent caching
                const hiddenTimestamp = document.createElement('input');
                hiddenTimestamp.type = 'hidden';
                hiddenTimestamp.name = 'timestamp';
                hiddenTimestamp.value = Date.now();
                form.appendChild(hiddenTimestamp);
                
                return true;
            });
        }
        
        // Session timer
        const timerElement = document.getElementById('session-timer');
        let lastActivity = <?= isset($_SESSION['last_activity']) ? $_SESSION['last_activity'] : 'null' ?>;
        
        function updateTimer() {
            if (lastActivity && timerElement) {
                const now = Math.floor(Date.now() / 1000);
                const elapsed = now - lastActivity;
                const remaining = 300 - elapsed; // 5 minute timeout
                
                if (remaining <= 0) {
                    timerElement.textContent = 'Expired!';
                    timerElement.style.color = '#ff0000';
                } else {
                    const minutes = Math.floor(remaining / 60);
                    const seconds = remaining % 60;
                    timerElement.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                    
                    // Add warning style when less than 1 minute remains
                    if (remaining < 60) {
                        timerElement.style.color = '#ff0000';
                    }
                }
                
                // Auto refresh session when less than 2 minutes remain
                if (remaining < 120 && remaining > 0) {
                    refreshSession();
                }
            }
        }
        
        // Update timer every second
        setInterval(updateTimer, 1000);
        updateTimer();
        
        // Session refresh
        const refreshButton = document.getElementById('refresh-session');
        
        function refreshSession() {
            fetch('session_trace.php?action=refresh&silent=1&_=' + Date.now(), {
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.session_started === 'yes' && data.sid_exists === 'yes') {
                    lastActivity = Math.floor(Date.now() / 1000);
                    console.log('Session refreshed:', data);
                    
                    // Visual feedback
                    if (refreshButton) {
                        refreshButton.textContent = 'Session Refreshed';
                        setTimeout(() => {
                            refreshButton.textContent = 'Refresh Session';
                        }, 2000);
                    }
                } else {
                    console.warn('Session refresh failed:', data);
                }
            })
            .catch(error => {
                console.error('Error refreshing session:', error);
            });
        }
        
        // Refresh session on button click
        if (refreshButton) {
            refreshButton.addEventListener('click', function(e) {
                e.preventDefault();
                refreshSession();
            });
        }
        
        // Keep-alive ping every 3 minutes
        setInterval(refreshSession, 3 * 60 * 1000);
    });
    </script>
</body>
</html>
