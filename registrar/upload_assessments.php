<?php
/**
 * Registrar - Upload Assessments
 *
 * Rewritten to use modern portal styling: container-fluid layout,
 * page-header, standard Bootstrap 5 inputs, client-side validation,
 * and purple brand accents.
 */

require dirname(__DIR__) . '/db/connect.php';
$page_title = 'Upload Assessments';
require __DIR__ . '/includes/nav.php';

// Access control: Registrar or Systems Admin (canonical RBAC).
// Nav already gates via canAccessRegistrar(); keep an explicit check for direct hits.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['staff_id']) && !isset($_SESSION['user_id'])) {
    $_SESSION['loginMaster'] = 'Please you need to login!';
    header('Location: /wucportal/staff_login.php');
    exit;
}
if (!function_exists('canAccessRegistrar')) {
    require_once dirname(__DIR__) . '/includes/role_helpers.php';
}
if (!canAccessRegistrar()) {
    header('Location: /wucportal/portal_selection.php');
    exit;
}

// Handle form submission
if (!empty($_POST) && isset($_POST['SID'], $_POST['course_code'], $_POST['semester'], $_POST['assess_type'], $_POST['assess_num'], $_POST['marks'], $_POST['year'])) {
    $SID         = trim($_POST['SID']);
    $course_code = trim($_POST['course_code']);
    $semester    = trim($_POST['semester']);
    $assess_type = trim($_POST['assess_type']);
    $assess_num  = trim($_POST['assess_num']);
    $marks       = trim($_POST['marks']);
    $year        = trim($_POST['year']);

    $regCheckSql = "SELECT 1 FROM course_registration WHERE Sid=? AND course_code=? AND semester=? AND Year=? LIMIT 1";
    $regExists = false;
    if ($regStmt = $db->prepare($regCheckSql)) {
        $regStmt->bind_param('ssss', $SID, $course_code, $semester, $year);
        $regStmt->execute();
        $regStmt->store_result();
        $regExists = $regStmt->num_rows > 0;
        $regStmt->close();
    }

    if (!$regExists) {
        echo "<script>alert('Student is not registered for this course in the selected term.')</script>";
        echo "<script>window.open('assessments.php','_self')</script>";
        exit;
    }

    if (!empty($SID) && !empty($course_code) && !empty($semester) && !empty($assess_type) && !empty($assess_num) && !empty($marks) && !empty($year)) {
        $insert = $db->prepare("INSERT INTO assessments (SID, course_code, semester, assess_type, assess_num, marks, year) VALUE(?,?,?,?,?,?,?)");
        if ($insert) {
            $insert->bind_param('sssssss', $SID, $course_code, $semester, $assess_type, $assess_num, $marks, $year);
            if ($insert->execute()) {
                echo "<script>alert('Results uploaded submitted successfully')</script>";
                echo "<script>window.open('assessments.php','_self')</script>";
                exit;
            }
        }
    }
}

// Load courses for dropdown
$courses = [];
if ($results = $db->query("SELECT DISTINCT course_code FROM courses ORDER BY course_code")) {
    while ($row = $results->fetch_object()) {
        $courses[] = $row->course_code;
    }
    $results->free();
}
?>

<div class="container-fluid px-4 portal-dashboard">

    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-upload me-2 text-primary"></i>Upload Assessment Results</h5>
                <p class="page-subtitle mb-0">Record student continuous assessment (CA) marks</p>
            </div>
            <a href="assessments.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Assessments
            </a>
        </div>
    </div>

    <!-- Form Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 text-primary"><i class="fas fa-file-upload me-2"></i>Continuous Assessment Form</h5>
        </div>
        <div class="card-body p-4">
            <form action="upload_assessments.php" method="post" class="row g-3 needs-validation" novalidate>
                <div class="col-md-6">
                    <label for="SID" class="form-label fw-semibold">Student ID</label>
                    <input type="text" class="form-control" name="SID" id="SID" placeholder="Enter student number" autocomplete="off" required value="<?php echo isset($_GET['SID']) ? htmlspecialchars($_GET['SID'], ENT_QUOTES, 'UTF-8') : ''; ?>" autofocus>
                    <div class="invalid-feedback">Student number is required</div>
                </div>

                <div class="col-md-6">
                    <label for="course_code" class="form-label fw-semibold">Course Code</label>
                    <select class="form-select" name="course_code" id="course_code" required>
                        <option selected disabled value="">Select course code</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Please select a course code</div>
                </div>

                <div class="col-md-4">
                    <label for="semester" class="form-label fw-semibold">Semester</label>
                    <select class="form-select" name="semester" id="semester" required>
                        <option selected disabled value="">Select semester</option>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                            <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                    <div class="invalid-feedback">Please select a semester</div>
                </div>

                <div class="col-md-4">
                    <label for="assess_type" class="form-label fw-semibold">Assessment Type</label>
                    <select class="form-select" name="assess_type" id="assess_type" required>
                        <option selected disabled value="">Select type</option>
                        <option value="Assignment">Assignment</option>
                        <option value="Test">Test</option>
                        <option value="Make-up">Make-up</option>
                    </select>
                    <div class="invalid-feedback">Please select assessment type</div>
                </div>

                <div class="col-md-4">
                    <label for="assess_num" class="form-label fw-semibold">Assessment Number</label>
                    <select class="form-select" name="assess_num" id="assess_num" required>
                        <option selected disabled value="">Select number</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="4">4</option>
                    </select>
                    <div class="invalid-feedback">Please select assessment number</div>
                </div>

                <div class="col-md-6">
                    <label for="marks" class="form-label fw-semibold">Marks Obtained (0-100)</label>
                    <input type="number" class="form-control" name="marks" min="0" max="100" id="marks" placeholder="Enter marks obtained" autocomplete="off" required>
                    <div class="invalid-feedback">Please enter valid marks between 0 and 100</div>
                </div>

                <div class="col-md-6">
                    <label for="year" class="form-label fw-semibold">Year of Study</label>
                    <select class="form-select" id="year" name="year" required>
                        <option disabled selected value="">Select year of study</option>
                    </select>
                    <div class="invalid-feedback">Please select year of study</div>
                </div>

                <div class="col-12 mt-4">
                    <button class="btn btn-primary" type="submit">
                        <i class="fas fa-save me-2"></i>Submit Results
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

<script type="text/javascript">
(function() {
    // Populate year dropdown
    let startYear = 2000;
    let endYear = new Date().getFullYear();
    let yearSelect = document.getElementById('year');
    if (yearSelect) {
        for (let i = endYear; i > startYear; i--) {
            let opt = document.createElement('option');
            opt.value = i;
            opt.innerHTML = i;
            yearSelect.appendChild(opt);
        }
    }

    // Bootstrap validation trigger
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

<?php require __DIR__ . '/includes/footer.php'; ?>
