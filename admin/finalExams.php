<?php
// 1. BACKEND LOGIC MUST COME FIRST
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ob_start();

require "includes/admin.php"; 
require_once dirname(__DIR__) . '/includes/result_entry_helpers.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/finance_guard.php';

if (function_exists('canEnterExamMarks') && !canEnterExamMarks()) {
    $_SESSION['errorMssg'] = 'Access denied. You do not have permission to enter exam marks.';
    header('Location: index.php');
    exit();
}

// Check for DB connection
global $db;
if (!isset($db) || !$db) {
    require_once "../db/connect.php";
    if (!isset($db) || !$db) {
        die("Database connection failed");
    }
}

// Input validation function
if (!function_exists('validateInput')) {
    function validateInput($input, $type = 'string') {
        $input = trim($input);
        switch($type) {
            case 'int':
                return filter_var($input, FILTER_VALIDATE_INT);
            case 'float':
                return filter_var($input, FILTER_VALIDATE_FLOAT);
            case 'string':
            default:
                return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        }
    }
}

// --- Handle Manual Exam Result Submission ---
if (isset($_POST['submit_exam_result'])) {

    // Validate required fields
    $required = ['semester', 'year', 'course_code', 'student_id', 'exam_marks'];
    foreach ($required as $field) {
        if (!isset($_POST[$field]) || $_POST[$field] === '') {
            $_SESSION['errorMsg'] = "Missing required field: " . $field;
            header("Location: finalExams.php");
            exit();
        }
    }
    
    $semester   = (int)$_POST['semester'];
    $year       = (int)$_POST['year'];
    $course_code = trim($_POST['course_code']);
    $student_id  = trim($_POST['student_id']);
    $exam_marks  = (int)$_POST['exam_marks'];
    $assessment_date = trim((string)($_POST['assessment_date'] ?? date('Y-m-d')));
    
    // Validate mark range
    if ($exam_marks < 0 || $exam_marks > 100) {
        $_SESSION['errorMsg'] = "Invalid mark. Must be between 0 and 100.";
        header("Location: finalExams.php");
        exit();
    }
    
    $eligibility = result_validate_entry($db, $student_id, $course_code, (string)$semester, (string)$year, $assessment_date);
    if (!$eligibility['ok']) {
        $_SESSION['errorMsg'] = htmlspecialchars($eligibility['message'], ENT_QUOTES, 'UTF-8');
        header("Location: finalExams.php");
        exit();
    }

    $examPayment = is_student_allowed_exam($db, $student_id, (string)$year, (string)$semester);
    if (empty($examPayment['allowed'])) {
        $_SESSION['errorMsg'] = 'Exam result not saved: student is '
            . htmlspecialchars(rtrim(rtrim(number_format((float)($examPayment['percent'] ?? 0), 2), '0'), '.'), ENT_QUOTES, 'UTF-8')
            . '% paid for this term and full payment is required before exam marks can be entered.';
        header("Location: finalExams.php");
        exit();
    }

    // Total_marks = Exam_marks (matching existing data pattern)
    $total_marks = $exam_marks;

    try {
        $db->begin_transaction();

        // Check if record already exists
        $check_sql = "SELECT id FROM exams WHERE Sid = ? AND Course_Code = ? AND semester = ? AND Year = ? FOR UPDATE";
        $check_stmt = $db->prepare($check_sql);
        if (!$check_stmt) {
            throw new RuntimeException($db->error);
        }
        $check_stmt->bind_param("ssii", $student_id, $course_code, $semester, $year);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            // UPDATE existing
            $update_sql = "UPDATE exams SET Exam_marks = ?, Total_marks = ? WHERE Sid = ? AND Course_Code = ? AND semester = ? AND Year = ?";
            $update_stmt = $db->prepare($update_sql);
            if (!$update_stmt) {
                throw new RuntimeException($db->error);
            }
            $update_stmt->bind_param("iissii", $exam_marks, $total_marks, $student_id, $course_code, $semester, $year);
            if (!$update_stmt->execute()) {
                throw new RuntimeException($update_stmt->error);
            }
            $update_stmt->close();
            wuc_academic_risk_after_student_activity($db, $student_id, 'legacy_exam_updated');
            $_SESSION['successMsg'] = "Exam result updated for <strong>" . htmlspecialchars($student_id, ENT_QUOTES, 'UTF-8') . "</strong>";
        } else {
            // INSERT new
            $insert_sql = "INSERT INTO exams (Sid, Course_Code, Exam_marks, Total_marks, semester, Year) VALUES (?, ?, ?, ?, ?, ?)";
            $insert_stmt = $db->prepare($insert_sql);
            if (!$insert_stmt) {
                throw new RuntimeException($db->error);
            }
            $insert_stmt->bind_param("ssiiii", $student_id, $course_code, $exam_marks, $total_marks, $semester, $year);
            if (!$insert_stmt->execute()) {
                throw new RuntimeException($insert_stmt->error);
            }
            $insert_stmt->close();
            wuc_academic_risk_after_student_activity($db, $student_id, 'legacy_exam_inserted');
            $_SESSION['successMsg'] = "Exam result saved for <strong>" . htmlspecialchars($student_id, ENT_QUOTES, 'UTF-8') . "</strong>";
        }
        $check_stmt->close();
        result_update_exam_metadata($db, $student_id, $course_code, (string)$semester, (string)$year, (string)$eligibility['type'], $assessment_date);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        error_log('finalExams result save failed: ' . $e->getMessage());
        $_SESSION['errorMsg'] = "Unable to save result. Please verify the student registration and try again.";
    }
    
    header("Location: finalExams.php");
    exit();
}

// --- Initialize Filter Variables ---
$selected_semester = $_GET['semester'] ?? $_POST['semester'] ?? '';
$selected_year     = $_GET['year'] ?? $_POST['year'] ?? '';
$selected_course   = $_GET['course_code'] ?? $_POST['course_code'] ?? '';

// 2. FRONTEND OUTPUT STARTS HERE
ob_end_clean();
require "includes/header.php";
?>

<style>
    .admin-dashboard { background-color: #f8f9fa; min-height: 100vh; padding-bottom: 3rem; }
    .dashboard-header { background: white; padding: 1.5rem; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-left: 5px solid #cb0c9f; }
    
    .form-card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); width: 100%; }
    .card-header { background-color: white; border-bottom: 1px solid #eee; padding: 1rem 1.5rem; }
    .btn-primary { background-color: #cb0c9f; border-color: #cb0c9f; }
    .btn-primary:hover { background-color: #ad0a87; border-color: #ad0a87; }
    .form-label { font-size: 0.85rem; font-weight: 600; color: #6c757d; letter-spacing: 0.5px; }
    
    /* Loading Overlay */
    #loading-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(255,255,255,0.8);
        z-index: 9999;
        flex-direction: column;
        justify-content: center;
        align-items: center;
    }

    /* Print Styles */
    @media print {
        body { background: white; }
        .d-print-none, .sidebar, header, nav, footer, .modal { display: none !important; }
        .container-fluid { padding: 0 !important; margin: 0 !important; width: 100% !important; max-width: 100% !important; }
        .card { box-shadow: none !important; border: none !important; margin: 0 !important; }
        .card-body { padding: 0 !important; }
        #printableArea { display: block; position: absolute; top: 0; left: 0; width: 100%; }
        .table { width: 100% !important; border-collapse: collapse !important; }
        .table td, .table th { border: 1px solid #000 !important; padding: 5px !important; color: #000 !important; }
        .badge { border: 1px solid #000; color: #000; }
    }
</style>

<div id="loading-overlay">
    <div class="spinner-border text-primary" role="status"></div>
    <div class="mt-2 fw-bold">Loading...</div>
</div>

<div class="container-fluid px-4 portal-dashboard">
    
    <div class="dashboard-header mb-4 mt-4 d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="h3 mb-1 text-gray-800">Final Examinations</h1>
                <p class="text-muted mb-0 small">Manage marks, upload CSVs, and publish results.</p>
            </div>
            <div class="col-auto">
                <div class="d-flex gap-2">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#PublishExams">
                        <i class="fas fa-paper-plane me-2"></i>Publish
                    </button>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#uploadModal">
                        <i class="fas fa-file-excel me-2"></i>Upload CSV
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($_SESSION['successMsg'])): ?>
        <div class="alert alert-success alert-dismissible fade show d-print-none shadow-sm" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['successMsg']; unset($_SESSION['successMsg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if(isset($_SESSION['errorMsg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show d-print-none shadow-sm" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['errorMsg']; unset($_SESSION['errorMsg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row d-print-none justify-content-center">
        <div class="col-12 col-xl-9 mb-4">
            <div class="card form-card">
                <div class="card-header bg-white">
                    <h5 class="mb-0 text-primary"><i class="fas fa-pen-alt me-2"></i>Exam Result Entry</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="entryForm" action="finalExams.php" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Semester <span class="text-danger">*</span></label>
                            <select class="form-select" id="semester_dropdown" name="semester" onchange="onSemesterChange()" required>
                                <option value="" disabled <?php echo empty($selected_semester) ? 'selected' : ''; ?>>Select...</option>
                                <option value="1" <?php echo $selected_semester == '1' ? 'selected' : ''; ?>>Semester 1</option>
                                <option value="2" <?php echo $selected_semester == '2' ? 'selected' : ''; ?>>Semester 2</option>
                                <option value="3" <?php echo $selected_semester == '3' ? 'selected' : ''; ?>>Term 3</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Year <span class="text-danger">*</span></label>
                            <select class="form-select" id="year_dropdown" name="year" onchange="fetchCourses()" <?php echo empty($selected_semester) ? 'disabled' : ''; ?> required>
                                <option value="" disabled <?php echo empty($selected_year) ? 'selected' : ''; ?>>Select...</option>
                                <?php
                                $current_year = date('Y');
                                for ($y = $current_year; $y >= $current_year - 3; $y--) {
                                    $sel = ($selected_year == $y) ? 'selected' : '';
                                    echo "<option value='$y' $sel>$y</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Course <span class="text-danger">*</span></label>
                            <select class="form-select" id="course_dropdown" name="course_code" onchange="fetchStudents()" disabled required>
                                <option value="" disabled selected>Select Semester & Year First...</option>
                                <?php
                                if (!empty($selected_course)) {
                                    // Pre-populate courses if a course was previously selected
                                    $courseRows = (!empty($selected_semester) && !empty($selected_year))
                                        ? result_exam_courses_for_period($db, (string)$selected_semester, (string)$selected_year)
                                        : [];
                                    foreach ($courseRows as $c) {
                                        $code = htmlspecialchars((string)$c['course_code'], ENT_QUOTES, 'UTF-8');
                                        $name = htmlspecialchars((string)$c['course_name'], ENT_QUOTES, 'UTF-8');
                                        $sel = ($selected_course == $c['course_code']) ? 'selected' : '';
                                        echo "<option value='{$code}' {$sel}>{$code} - {$name}</option>";
                                    }
                                }
                                ?>
                            </select>
                            <div id="course_error" class="text-danger small mt-1"></div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Student <span class="text-danger">*</span></label>
                            <select class="form-select" id="student_dropdown" name="student_id" required disabled>
                                <option value="">Select Course First...</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Exam Mark (0-100) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="exam_marks" min="0" max="100" step="1" placeholder="Enter mark" required disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Assessment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="assessment_date" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                            <div class="form-text">Used to validate short-course duration.</div>
                        </div>
                        <div class="col-12">
                            <div id="entry_status" class="alert alert-info py-2 small mb-0">
                                Select a semester, academic year, course, and student to enter results.
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <button type="submit" name="submit_exam_result" id="submit_btn" class="btn btn-success w-100 fw-bold" disabled>
                                <i class="fas fa-save me-2"></i>Save Mark
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-3 mb-4">
            <div class="card form-card h-100">
                <div class="card-header bg-white">
                    <h5 class="mb-0 text-primary"><i class="fas fa-search me-2"></i>Generate Report</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="finalExams.php" class="row g-3">
                        <div class="col-12">
                            <div class="alert alert-info py-2 small mb-3">
                                <i class="fas fa-info-circle me-1"></i> Select parameters to generate a printable results sheet.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Program Type</label>
                            <select class="form-select" id="progType" onchange="updatePeriods()">
                                <option value="semester">Semester Based</option>
                                <option value="term">Term Based</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" id="periodLabel">Semester</label>
                            <select class="form-select" name="report_semester" id="periodSelect" required>
                                <option value="1">Semester 1</option>
                                <option value="2">Semester 2</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Year</label>
                            <select class="form-select" name="report_year" required>
                                <?php
                                $current_year = date('Y');
                                for ($y = $current_year; $y >= $current_year - 3; $y--) {
                                    echo "<option value='$y'>$y</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-12 mt-4">
                            <button type="submit" name="submit_search" class="btn btn-primary w-100 fw-bold">
                                <i class="fas fa-list-alt me-2"></i>View Results
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>


    <?php if (isset($_POST['submit_search'])): ?>
    <?php
        $report_semester = (int)($_POST['report_semester'] ?? 1);
        $report_year     = (int)($_POST['report_year'] ?? date('Y'));
        $pLabel = ($report_semester > 2) ? "TERM" : "SEMESTER";
    ?>
    <div class="card mb-4" id="printableArea">
        <div class="card-header d-print-none d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-primary"><i class="fas fa-table me-2"></i>Official Result Sheet</h5>
            <button class="btn btn-secondary btn-sm" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Print
            </button>
        </div>
        <div class="card-body">
            <div class="text-center mb-4">
                <img src="images/itc_logo.png" style="width: 150px; height: auto;" class="mb-2">
                <h3 class="fw-bold text-uppercase mb-0">Industrial Training Centre</h3>
                <p class="text-muted small">Official Examination Results</p>
                <hr>
                
                <div class="d-flex justify-content-center gap-4 mt-3">
                    <div class="border px-4 py-2 rounded">
                        <strong><?php echo $pLabel; ?>:</strong> <?php echo $report_semester; ?>
                    </div>
                    <div class="border px-4 py-2 rounded">
                        <strong>YEAR:</strong> <?php echo $report_year; ?>
                    </div>
                </div>
            </div>

            <?php
            $number = 1;

            // Query using actual exams table columns
            $sql = "SELECT e.Sid, e.Course_Code, c.course_name, 
                           e.Exam_marks, e.Total_marks, e.Year 
                    FROM exams e
                    LEFT JOIN courses c ON e.Course_Code = c.course_code
                    WHERE e.semester = ? AND e.Year = ?
                    ORDER BY e.Sid ASC, e.Course_Code ASC";
            
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("ii", $report_semester, $report_year);
                $stmt->execute();
                $results = $stmt->get_result();

                if ($results && $results->num_rows > 0):
            ?>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th width="5%">#</th>
                            <th>Student ID</th>
                            <th>Course Code</th>
                            <th>Course Name</th>
                            <th class="text-center">Marks</th>
                            <th class="text-center">Grade</th>
                            <th class="text-center">Year</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($r = $results->fetch_object()): ?>
                        <?php 
                            $total = $r->Total_marks;
                            
                            // Determine Grade Letter
                            if ($total >= 90) $grade = "A+";
                            elseif ($total >= 80) $grade = "A";
                            elseif ($total >= 75) $grade = "B+";
                            elseif ($total >= 70) $grade = "B";
                            elseif ($total >= 65) $grade = "B-";
                            elseif ($total >= 60) $grade = "C+";
                            elseif ($total >= 50) $grade = "C";
                            elseif ($total >= 45) $grade = "D";
                            else $grade = "E";
                            
                            // Color coding for Fail
                            $rowClass = ($grade == 'E') ? 'table-danger' : '';
                        ?>
                        <tr class="<?php echo $rowClass; ?>">
                            <td><?php echo $number++; ?></td>
                            <td class="fw-bold"><?php echo htmlspecialchars($r->Sid); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($r->Course_Code); ?></span></td>
                            <td><?php echo htmlspecialchars($r->course_name ?? 'N/A'); ?></td>
                            <td class="text-center"><?php echo $total; ?></td>
                            <td class="text-center fw-bold"><?php echo $grade; ?></td>
                            <td class="text-center"><?php echo htmlspecialchars($r->Year); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="mt-5 pt-3 border-top">
                <p class="small text-muted fw-bold">GRADING SYSTEM KEY</p>
                <div class="row small text-muted">
                    <div class="col-6">A+ (90-100) - Distinction</div>
                    <div class="col-6">C+ (60-64) - Credit</div>
                    <div class="col-6">A  (80-89) - Distinction</div>
                    <div class="col-6">C  (50-59) - Pass</div>
                    <div class="col-6">B+ (75-79) - Merit</div>
                    <div class="col-6">D  (45-49) - Pass</div>
                    <div class="col-6">B  (70-74) - Merit</div>
                    <div class="col-6">E  (0-44) - Fail</div>
                    <div class="col-6">B- (65-69) - Credit</div>
                </div>
            </div>

            <?php else: ?>
                <div class="alert alert-warning text-center">
                    <i class="fas fa-exclamation-triangle me-2"></i> No results found for Semester <?php echo $report_semester; ?>, Year <?php echo $report_year; ?>.
                </div>
            <?php endif; $stmt->close(); } else { error_log('finalExams report query failed: ' . $db->error); ?>
                <div class="alert alert-danger">A database error occurred while loading the report. Please try again or contact the administrator.</div>
            <?php } ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php 
// Include upload modal (modified to work as included modal)
include_once "upload_exam_results.php"; 
include_once "publishResults.php"; 
require_once "includes/footer.php"; 
?>

<script>
function showLoading() {
    document.getElementById('loading-overlay').style.display = 'flex';
}

function hideLoading() {
    document.getElementById('loading-overlay').style.display = 'none';
}

function updatePeriods() {
    const type = document.getElementById('progType').value;
    const select = document.getElementById('periodSelect');
    const label = document.getElementById('periodLabel');
    select.innerHTML = '';
    
    if (type === 'semester') {
        label.innerText = 'Semester';
        select.add(new Option('Semester 1', '1'));
        select.add(new Option('Semester 2', '2'));
    } else {
        label.innerText = 'Term';
        select.add(new Option('Term 1', '1'));
        select.add(new Option('Term 2', '2'));
        select.add(new Option('Term 3', '3'));
    }
}

function onSemesterChange() {
    const yearDropdown = document.getElementById('year_dropdown');
    yearDropdown.disabled = false;
    // Reset downstream
    resetCourseDropdown();
    resetStudentDropdown();
    disableInputs();
}

function resetCourseDropdown() {
    const courseSelect = document.getElementById('course_dropdown');
    courseSelect.innerHTML = '<option value="" disabled selected>Select Semester & Year First...</option>';
    courseSelect.disabled = true;
    setEntryStatus('Select a semester and academic year to load courses.', 'info');
}

function resetStudentDropdown() {
    const studentSelect = document.getElementById('student_dropdown');
    studentSelect.innerHTML = '<option value="">Select Course First...</option>';
    studentSelect.disabled = true;
}

function setEntryStatus(message, type = 'info') {
    const status = document.getElementById('entry_status');
    if (!status) return;
    status.className = `alert alert-${type} py-2 small mb-0`;
    status.textContent = message;
}

function hasUsableOptions(select) {
    return Array.from(select.options).some(option => option.value && !option.disabled);
}

function disableInputs() {
    const markInput = document.querySelector('input[name="exam_marks"]');
    const submitBtn = document.getElementById('submit_btn');
    if (markInput) markInput.disabled = true;
    if (submitBtn) submitBtn.disabled = true;
}

function enableInputs() {
    const markInput = document.querySelector('input[name="exam_marks"]');
    const submitBtn = document.getElementById('submit_btn');
    if (markInput) markInput.disabled = false;
    if (submitBtn) submitBtn.disabled = false;
}

function fetchCourses() {
    const semester = document.getElementById('semester_dropdown').value;
    const year = document.getElementById('year_dropdown').value;
    const courseSelect = document.getElementById('course_dropdown');
    const courseError = document.getElementById('course_error');

    if (!semester || !year) {
        resetCourseDropdown();
        return;
    }

    // Show loading state
    courseSelect.disabled = true;
    courseSelect.innerHTML = '<option>Loading courses...</option>';
    courseError.textContent = '';
    resetStudentDropdown();
    disableInputs();
    setEntryStatus('Loading registered courses for the selected term...', 'info');

    fetch('get_exam_courses.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `semester=${encodeURIComponent(semester)}&year=${encodeURIComponent(year)}`
    })
    .then(response => {
        if (!response.ok) throw new Error('Network response was not ok (' + response.status + ')');
        return response.text();
    })
    .then(data => {
        courseSelect.innerHTML = data;
        const canSelectCourse = hasUsableOptions(courseSelect);
        courseSelect.disabled = !canSelectCourse;
        const message = canSelectCourse
            ? 'Select a course to load eligible students.'
            : (courseSelect.options[0]?.textContent || 'No assessment setup found for the selected term.');
        setEntryStatus(message, canSelectCourse ? 'info' : 'warning');
        courseError.textContent = canSelectCourse ? '' : message;
    })
    .catch(error => {
        courseError.textContent = 'Error loading courses: ' + error.message;
        console.error('Error:', error);
        courseSelect.innerHTML = '<option value="">Error loading courses</option>';
        courseSelect.disabled = false;
        setEntryStatus('Error loading courses. Check the backend logs if this continues.', 'danger');
    });
}

function fetchStudents() {
    const course = document.getElementById('course_dropdown').value;
    const semester = document.getElementById('semester_dropdown').value;
    const year = document.getElementById('year_dropdown').value;
    const studentSelect = document.getElementById('student_dropdown');

    if (!course || !semester || !year) {
        resetStudentDropdown();
        disableInputs();
        return;
    }

    // Disable dropdown and show loading
    studentSelect.disabled = true;
    studentSelect.innerHTML = '<option>Loading students...</option>';
    disableInputs();
    setEntryStatus('Checking student registration for the selected term...', 'info');

    fetch('get_students.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `course_code=${encodeURIComponent(course)}&semester=${encodeURIComponent(semester)}&year=${encodeURIComponent(year)}`
    })
    .then(response => {
        if (!response.ok) throw new Error('Network response was not ok (' + response.status + ')');
        return response.text();
    })
    .then(data => {
        studentSelect.innerHTML = data;
        const canSelectStudent = hasUsableOptions(studentSelect);
        studentSelect.disabled = !canSelectStudent;
        if (canSelectStudent) {
            enableInputs();
            setEntryStatus('Eligible students loaded. Select a student and enter the exam mark.', 'success');
        } else {
            disableInputs();
            setEntryStatus(studentSelect.options[0]?.textContent || 'Student not enrolled.', 'warning');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        studentSelect.innerHTML = '<option>Error loading students</option>';
        studentSelect.disabled = false;
        disableInputs();
        setEntryStatus('Error loading students. Check the backend logs if this continues.', 'danger');
    });
}
</script>

