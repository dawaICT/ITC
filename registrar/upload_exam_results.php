<?php
/**
 * Registrar – Upload Exam Results
 *
 * Supports both standalone access and inclusion.
 *
 * Standalone Mode:
 *   Renders a full modern portal page (portal-dashboard layout,
 *   page-header, card, and footer).
 *
 * Included Mode (e.g. from exams.php):
 *   Renders a compatible W3.CSS modal block (id="exams") to preserve
 *   backward compatibility with legacy JS triggers in exams.php.
 */

if (!isset($db) || !$db) {
    require_once dirname(__DIR__) . '/db/connect.php';
}

require_once dirname(__DIR__) . '/includes/result_entry_helpers.php';
require_once dirname(__DIR__) . '/includes/grading_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isIncluded = (basename($_SERVER['PHP_SELF']) !== 'upload_exam_results.php');

// Standalone mode access control
if (!$isIncluded) {
    if (!isset($_SESSION['staff_id'])) {
        $_SESSION['loginMaster'] = 'Please you need to login!';
        header('Location: /wucportal/index.php');
        exit;
    }

    $allowedRoles = array('Registrar', 'Systems Admin');
    $userRole = null;
    if ($stmt = $db->prepare("SELECT ar.assigned_access FROM access_right ar WHERE ar.staff_id = ? LIMIT 1")) {
        $stmt->bind_param('s', $_SESSION['staff_id']);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows) {
            $row = $res->fetch_assoc();
            $userRole = $row['assigned_access'];
        }
        $stmt->close();
    }
    if ($userRole !== null && !in_array($userRole, $allowedRoles, true)) {
        header('Location: /wucportal/error/404.php');
        exit;
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $Sid         = trim($_POST["Sid"] ?? '');
    $Course_Code = trim($_POST["Course_Code"] ?? '');
    $Exam_marks  = trim($_POST["Exam_marks"] ?? '');
    $semester    = trim($_POST["semester"] ?? '');
    $Year        = trim($_POST["Year"] ?? '');

    if (!empty($Sid) && !empty($Course_Code) && $Exam_marks !== '' && !empty($semester) && !empty($Year)) {
        $eligibility = result_validate_entry($db, $Sid, $Course_Code, $semester, $Year);
        $actor       = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
        $programType = (($eligibility['type'] ?? '') === 'short_course') ? 'short_course' : 'semester';

        $save = ($eligibility['ok'] && is_numeric($Exam_marks) && (float)$Exam_marks >= 0 && (float)$Exam_marks <= 100)
            ? result_save_exam_mark($db, $Sid, $Course_Code, $semester, $Year, (float)$Exam_marks, $programType, $actor)
            : ['ok' => false, 'message' => (string)($eligibility['ok'] ? 'Exam mark must be between 0 and 100.' : $eligibility['message'])];

        echo "<script>alert(" . json_encode($save['message']) . ")</script>";
        echo "<script>window.open('exams.php','_self')</script>";
        exit;
    } else {
        echo "<script>alert('Failed! All fields are required.')</script>";
        echo "<script>window.open('exams.php','_self')</script>";
        exit;
    }
}

// Load courses for dropdown
$Records = [];
if ($Results = $db->query("SELECT course_code, course_name FROM courses ORDER BY course_code")) {
    while ($row = $Results->fetch_object()) {
        $Records[] = $row;
    }
    $Results->free();
}

// Include page header if direct access
if (!$isIncluded) {
    $page_title = 'Upload Exam Results';
    require __DIR__ . '/includes/nav.php';
}
?>

<?php if (!$isIncluded): ?>
<div class="container-fluid px-4 portal-dashboard">

    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-upload me-2 text-primary"></i>Upload Examination Results</h5>
                <p class="page-subtitle mb-0">Record student final examination marks</p>
            </div>
            <a href="exams.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Exams
            </a>
        </div>
    </div>

    <!-- Form Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 text-primary"><i class="fas fa-file-upload me-2"></i>Examination Marks Form</h5>
        </div>
        <div class="card-body p-4">
            <form action="upload_exam_results.php" method="post" class="row g-3 needs-validation" novalidate>
                <div class="col-md-6">
                    <label for="Sid" class="form-label fw-semibold">Student ID</label>
                    <input type="text" class="form-control" id="Sid" name="Sid" placeholder="Enter student ID" autocomplete="off" required>
                    <div class="invalid-feedback">Student ID is required</div>
                </div>

                <div class="col-md-6">
                    <label for="Course_Code" class="form-label fw-semibold">Course Code</label>
                    <select class="form-select" name="Course_Code" id="Course_Code" required>
                        <option disabled selected value="">Select course</option>
                        <?php foreach ($Records as $r): ?>
                            <option value="<?php echo htmlspecialchars($r->course_code, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($r->course_code . ' - ' . $r->course_name, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Please select a course</div>
                </div>

                <div class="col-md-4">
                    <label for="Exam_marks" class="form-label fw-semibold">Marks Obtained (0-100)</label>
                    <input type="number" class="form-control" id="Exam_marks" name="Exam_marks" min="0" max="100" placeholder="Enter marks obtained" required>
                    <div class="invalid-feedback">Please enter valid marks between 0 and 100</div>
                </div>

                <div class="col-md-4">
                    <label for="semester" class="form-label fw-semibold">Semester</label>
                    <select class="form-select" id="semester" name="semester" required>
                        <option disabled selected value="">Select semester</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                    </select>
                    <div class="invalid-feedback">Please select a semester</div>
                </div>

                <div class="col-md-4">
                    <label for="year" class="form-label fw-semibold">Year of Study</label>
                    <select class="form-select" id="year" name="Year" required>
                        <option disabled selected value="">Select year</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="4">4</option>
                    </select>
                    <div class="invalid-feedback">Please select a year</div>
                </div>

                <div class="col-12 mt-4">
                    <button class="btn btn-primary" type="submit" name="submit">
                        <i class="fas fa-save me-2"></i>Submit Results
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
<?php else: ?>
  <!-- Legacy included compatibility modal -->
  <div id="exams" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
      <header class="w3-container w3-purple"> 
        <span onclick="document.getElementById('exams').style.display='none'" class="w3-closebtn">×</span>
        <h3 class="w3-center">Upload Examination results</h3>
      </header>
      <div class="w3-container">
        <form action="upload_exam_results.php" method="post">
            <div class="form-group">
                <label for="Sid_modal">Student ID:</label><br>
                <input type="text" class="form-control" id="Sid_modal" name="Sid" placeholder="Enter student ID" required>
            </div>
            <div class="form-group">
                <label for="Course_Code_modal">Course code:</label><br>
                <select class="form-control" name="Course_Code" id="Course_Code_modal" required>
                    <option disabled selected value="">Select course</option>
                    <?php foreach ($Records as $r): ?>
                        <option value="<?php echo htmlspecialchars($r->course_code, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($r->course_code, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select> 
            </div>
            <div class="form-group">
                <label for="Exam_marks_modal">Marks obtained:</label><br>
                <input type="number" class="form-control" id="Exam_marks_modal" name="Exam_marks" min="0" max="100" placeholder="Enter marks obtained" required>
            </div>
            <div class="form-group">
                <label for="semester_modal">Semester:</label><br>
                <select class="form-control" id="semester_modal" name="semester" required>
                    <option disabled selected value="">Select semester</option>
                    <option value="1">1</option>
                    <option value="2">2</option>
                </select>
            </div>
            <div class="form-group">
                <label for="year_modal">Year: </label><br>
                <select class="form-control" id="year_modal" name="Year" required>
                    <option disabled selected value="">Select year</option>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                </select>
            </div><br>
            <div class="form-group">
                <button class="btn btn-block w3-orange" type="submit" name="submit">SUBMIT</button>
            </div> 
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<script type="text/javascript">
(function() {
    var forms = document.querySelectorAll('.needs-validation');
    Array.prototype.slice.call(forms).forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();
</script>

<?php
if (!$isIncluded) {
    require __DIR__ . '/includes/footer.php';
}
?>