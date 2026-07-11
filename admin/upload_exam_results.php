<?php
// upload_exam_results.php
// When included from finalExams.php, renders only the upload modal.
// When accessed directly, renders a full page.

if (!isset($db) || !$db) {
    require_once "../db/connect.php";
}
require_once dirname(__DIR__) . '/includes/result_entry_helpers.php';
require_once dirname(__DIR__) . '/includes/grading_helpers.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Determine if included or direct access
$isIncluded = (basename($_SERVER['PHP_SELF']) !== basename(__FILE__));

if ((!$isIncluded || $_SERVER['REQUEST_METHOD'] === 'POST') && function_exists('canEnterExamMarks') && !canEnterExamMarks()) {
    $_SESSION['errorMsg'] = 'Access denied. You do not have permission to enter exam marks.';
    header('Location: index.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_exam_submit'])) {
    $Sid         = trim($_POST["Sid"] ?? '');
    $Course_Code = trim($_POST["Course_Code"] ?? '');
    $Exam_marks  = (int)trim($_POST["Exam_marks"] ?? 0);
    $semester    = (int)trim($_POST["upload_semester"] ?? 0);
    $Year        = (int)trim($_POST["upload_year"] ?? 0);
    $assessment_date = trim((string)($_POST['assessment_date'] ?? date('Y-m-d')));

    if (!empty($Sid) && !empty($Course_Code) && $Exam_marks >= 0 && $semester > 0 && $Year > 0) {
        $eligibility = result_validate_entry($db, $Sid, $Course_Code, (string)$semester, (string)$Year, $assessment_date);
        if (!$eligibility['ok']) {
            $_SESSION['errorMsg'] = htmlspecialchars($eligibility['message'], ENT_QUOTES, 'UTF-8');
            header("Location: finalExams.php");
            exit();
        }

        // Persist the exam mark to the canonical marks table (semester_assessment)
        // via the shared helper, which sets status=Submitted and writes an audit
        // entry. The final mark / grade are derived at read time, so no total is
        // stored. This replaces the previous writes to the non-existent `exams` table.
        $programType = (($eligibility['type'] ?? '') === 'short_course') ? 'short_course' : 'semester';
        $submittedBy = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
        $save = result_save_exam_mark($db, $Sid, $Course_Code, (string)$semester, (string)$Year, (float)$Exam_marks, $programType, $submittedBy);
        $_SESSION[$save['ok'] ? 'successMsg' : 'errorMsg'] = $save['ok']
            ? ($save['message'] . ' for ' . htmlspecialchars($Sid, ENT_QUOTES, 'UTF-8'))
            : $save['message'];
    } else {
        $_SESSION['errorMsg'] = 'All fields are required and marks must be valid.';
    }

    header("Location: finalExams.php");
    exit();
}

// Load courses for dropdown
$Records = [];
if ($Results = $db->query("SELECT course_code, course_name FROM courses ORDER BY course_code")) {
    while ($row = $Results->fetch_object()) {
        $Records[] = $row;
    }
    $Results->free();
}
// Short courses live in their own table (not `courses`) and so were previously
// unreachable from this dropdown — meaning short-course final results could not
// be entered manually. Append the active ones, labelled for clarity. Validation
// (result_validate_entry) detects short courses automatically on submit.
if ($scRes = @$db->query("SELECT course_code, course_name FROM short_courses WHERE status = 'active' ORDER BY course_code")) {
    while ($row = $scRes->fetch_object()) {
        $row->course_name = (string)$row->course_name . ' (Short Course)';
        $Records[] = $row;
    }
    $scRes->free();
}

// If accessed directly, render full page
if (!$isIncluded) {
    require 'includes/admin.php';
    require 'includes/header.php';
}
?>

<?php if (!$isIncluded): ?>
<div class="container-fluid px-4 portal-dashboard">
    <div class="page-header mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-upload me-2 text-primary"></i>Upload Examination Results</h5>
                <p class="page-subtitle mb-0">Submit or update student exam marks by course, semester, and year.</p>
            </div>
            <a href="finalExams.php" class="btn btn-primary">
                <i class="fas fa-arrow-left me-1"></i>Back to Final Exams
            </a>
        </div>
    </div>

    <div class="data-table-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-file-upload me-2"></i>Result Submission Form</h5>
        </div>
        <div class="card-body">
            <form action="upload_exam_results.php" method="post" class="row g-3 needs-validation" novalidate>
                <div class="col-md-6">
                    <label for="upload_Sid" class="form-label fw-semibold">Student ID</label>
                    <input type="text" class="form-control" id="upload_Sid" name="Sid" required autocomplete="off"
                        placeholder="e.g. 2023001">
                    <div class="invalid-feedback">Student ID is required</div>
                </div>

                <div class="col-md-6">
                    <label for="upload_Course_Code" class="form-label fw-semibold">Course</label>
                    <select class="form-select" name="Course_Code" id="upload_Course_Code" required>
                        <option disabled selected value="">Select course</option>
                        <?php foreach($Records as $r): ?>
                            <option value="<?php echo htmlspecialchars($r->course_code); ?>">
                                <?php echo htmlspecialchars($r->course_code . ' - ' . $r->course_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Please select a course</div>
                </div>

                <div class="col-md-4">
                    <label for="upload_Exam_marks" class="form-label fw-semibold">Marks (0-100)</label>
                    <input type="number" class="form-control" id="upload_Exam_marks" name="Exam_marks" min="0" max="100"
                        required placeholder="0-100">
                    <div class="invalid-feedback">Enter marks between 0 and 100</div>
                </div>

                <div class="col-md-4">
                    <label for="upload_semester" class="form-label fw-semibold">Semester</label>
                    <select class="form-select" id="upload_semester" name="upload_semester" required>
                        <option disabled selected value="">Select</option>
                        <option value="1">Semester 1</option>
                        <option value="2">Semester 2</option>
                        <option value="3">Term 3</option>
                    </select>
                    <div class="invalid-feedback">Please select a semester</div>
                </div>

                <div class="col-md-4">
                    <label for="upload_year" class="form-label fw-semibold">Year</label>
                    <select class="form-select" id="upload_year" name="upload_year" required>
                        <option disabled selected value="">Select</option>
                        <?php
                        $cy = date('Y');
                        for ($y = $cy; $y >= $cy - 3; $y--) {
                            echo "<option value='$y'>$y</option>";
                        }
                        ?>
                    </select>
                    <div class="invalid-feedback">Please select year</div>
                </div>
                <div class="col-md-4">
                    <label for="upload_assessment_date" class="form-label fw-semibold">Assessment Date</label>
                    <input type="date" class="form-control" id="upload_assessment_date" name="assessment_date"
                        value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                    <div class="form-text">Used to validate short-course duration.</div>
                </div>

                <div class="col-12 mt-3">
                    <button class="btn btn-primary" type="submit" name="upload_exam_submit">
                        <i class="fas fa-save me-2"></i>Submit Result
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php else: ?>
<div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header admin-modal">
                <h5 class="modal-title" id="uploadModalLabel">
                    <i class="fas fa-upload me-2"></i>Upload Examination Results
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form action="upload_exam_results.php" method="post" class="row g-3 needs-validation" novalidate>
                    <div class="col-md-6">
                        <label for="upload_Sid" class="form-label fw-semibold">Student ID</label>
                        <input type="text" class="form-control" id="upload_Sid" name="Sid" required autocomplete="off"
                            placeholder="e.g. 2023001">
                        <div class="invalid-feedback">Student ID is required</div>
                    </div>

                    <div class="col-md-6">
                        <label for="upload_Course_Code" class="form-label fw-semibold">Course</label>
                        <select class="form-select" name="Course_Code" id="upload_Course_Code" required>
                            <option disabled selected value="">Select course</option>
                            <?php foreach($Records as $r): ?>
                                <option value="<?php echo htmlspecialchars($r->course_code); ?>">
                                    <?php echo htmlspecialchars($r->course_code . ' - ' . $r->course_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Please select a course</div>
                    </div>

                    <div class="col-md-4">
                        <label for="upload_Exam_marks" class="form-label fw-semibold">Marks (0-100)</label>
                        <input type="number" class="form-control" id="upload_Exam_marks" name="Exam_marks" min="0" max="100"
                            required placeholder="0-100">
                        <div class="invalid-feedback">Enter marks between 0 and 100</div>
                    </div>

                    <div class="col-md-4">
                        <label for="upload_semester" class="form-label fw-semibold">Semester</label>
                        <select class="form-select" id="upload_semester" name="upload_semester" required>
                            <option disabled selected value="">Select</option>
                            <option value="1">Semester 1</option>
                            <option value="2">Semester 2</option>
                            <option value="3">Term 3</option>
                        </select>
                        <div class="invalid-feedback">Please select a semester</div>
                    </div>

                    <div class="col-md-4">
                        <label for="upload_year" class="form-label fw-semibold">Year</label>
                        <select class="form-select" id="upload_year" name="upload_year" required>
                            <option disabled selected value="">Select</option>
                            <?php
                            $cy = date('Y');
                            for ($y = $cy; $y >= $cy - 3; $y--) {
                                echo "<option value='$y'>$y</option>";
                            }
                            ?>
                        </select>
                        <div class="invalid-feedback">Please select year</div>
                    </div>
                    <div class="col-md-4">
                        <label for="upload_assessment_date_modal" class="form-label fw-semibold">Assessment Date</label>
                        <input type="date" class="form-control" id="upload_assessment_date_modal" name="assessment_date"
                            value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                        <div class="form-text">Used to validate short-course duration.</div>
                    </div>

                    <div class="col-12 mt-3">
                        <button class="btn btn-primary w-100" type="submit" name="upload_exam_submit">
                            <i class="fas fa-save me-2"></i>Submit Result
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function(){
  var forms = document.querySelectorAll('.needs-validation');
  Array.prototype.slice.call(forms).forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); }
      form.classList.add('was-validated');
    }, false);
  });
})();
</script>

<?php if (!$isIncluded) require 'includes/footer.php'; ?>

