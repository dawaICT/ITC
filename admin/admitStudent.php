<?php
require_once "includes/admin.php";
$page_title = "Admission Form";
require 'includes/header.php';
if(!empty($_POST)){
      if(isset($_POST["Sid"], $_POST["program_code"], $_POST["intake"], $_POST["mode"], $_POST["startYear"])) {

        $Sid = trim($_POST["Sid"]);
        $program_code = trim($_POST["program_code"]);
        $intake = trim($_POST["intake"]);
        $mode = trim($_POST["mode"]);
        // Only the start YEAR feeds the smallint startYear column; the end year is
        // derived from programme duration inside the canonical helper.
        $entryYear = (int)substr(trim($_POST["startYear"]), 0, 4) ?: (int)date('Y');

        // Single canonical admission path (full enrolment + activation + login +
        // course/invoice) shared with the online-applicant and admin flows.
        require_once __DIR__ . '/../includes/applicant_admission.php';
        $result = admissionsEnrollExistingStudent($db, $Sid, $program_code, $intake, $mode, $entryYear);

        $dest = $result['success'] ? 'students_by_admin.php' : 'admitEnrolled_student.php';
        echo "<script>alert(" . json_encode($result['message']) . ");window.location.href='" . $dest . "';</script>";
    }
}

// Fetch record
$targetSID = $_GET['sid'] ?? null;
$student_record = null;
if ($targetSID) {
    if($stmt = $db->prepare("SELECT SID, Fname, Lname FROM students WHERE SID = ? LIMIT 1")) {
        $stmt->bind_param('s', $targetSID);
        $stmt->execute();
        $student_record = $stmt->get_result()->fetch_object();
        $stmt->close();
    }
}
if (!$student_record) {
    if($res = $db->query("SELECT SID, Fname, Lname FROM students ORDER BY dte_adm DESC LIMIT 1")) {
        $student_record = $res->fetch_object();
        $res->free();
    }
}
?>

<style>
    html,
    body.has-unified-sidebar,
    .main-wrapper,
    .main-content {
        background: #f6f8fb !important;
    }

    .registration-card {
        background: #fff;
        border-radius: 12px;
        padding: 2.25rem;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
        border: 1px solid #e9eef5;
    }

    .registration-card .stat-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 18px;
        box-shadow: none;
    }

    .registration-card .bg-blue-soft { background: #e8f2ff !important; }
    .registration-card .text-blue,
    .registration-card .stat-icon i { color: #2563eb !important; }

    .student-info-strip {
        background: #f8fafc;
        border-radius: 10px;
        padding: 1.25rem;
        margin-bottom: 2rem;
        border: 1px solid #e2e8f0;
    }

    .form-label {
        font-weight: 700;
        color: #64748b;
        font-size: 0.78rem;
        margin-bottom: 0.5rem;
        letter-spacing: 0;
    }

    .registration-card .input-group {
        border: 1px solid #dfe7f1;
        border-radius: 10px;
        overflow: hidden;
        background: #fff;
    }

    .registration-card .input-group-text {
        background: #fff;
        border: 0;
        color: #64748b;
        min-width: 48px;
        justify-content: center;
    }

    .registration-card .form-control,
    .registration-card .form-select {
        border: 0;
        min-height: 44px;
        box-shadow: none;
    }

    .registration-card .form-control:focus,
    .registration-card .form-select:focus { box-shadow: none; }

    .registration-card .input-group:focus-within {
        border-color: #8bb8ff;
        box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.12);
    }

    @media (max-width: 575.98px) {
        .registration-card { padding: 1.25rem; }
    }
</style>

<div class="container-fluid px-4 portal-dashboard">
    <div class="page-header mb-4 mt-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-file-signature me-2 text-primary"></i>Program Admission</h5>
                <p class="page-subtitle mb-0">Assign an academic program and enrollment details to a registered student</p>
            </div>
            <a href="students_by_admin.php" class="btn btn-outline-secondary shadow-sm">
                <i class="fas fa-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-xl-7 col-lg-9 col-md-11">
            <div class="registration-card mb-5">
                <?php if ($student_record): ?>
                <div class="text-center mb-4">
                    <div class="stat-icon bg-blue-soft text-blue mx-auto mb-3" style="width:70px;height:70px;font-size:1.5rem;">
                        <i class="fas fa-file-signature"></i>
                    </div>
                    <h5 class="fw-bold mb-1">Program Admission</h5>
                    <p class="text-muted small">Assign a programme and enrolment details</p>
                </div>

                <div class="student-info-strip d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small text-uppercase fw-bold ls-1 d-block mb-1">Target Student</span>
                        <h5 class="mb-0 text-dark"><?php echo htmlspecialchars($student_record->Fname . ' ' . $student_record->Lname); ?></h5>
                        <p class="mb-0 text-primary fw-bold small"><?php echo htmlspecialchars($student_record->SID); ?></p>
                    </div>
                    <div class="bg-white p-2 px-3 rounded-pill border">
                        <i class="fas fa-id-card me-2 text-muted"></i>Verified ID
                    </div>
                </div>

                <form action="admitStudent.php" method="POST" class="needs-validation" novalidate>
                    <input type="hidden" name="Sid" value="<?php echo htmlspecialchars($student_record->SID); ?>">

                    <div class="mb-4">
                        <label class="form-label text-uppercase small ls-1">Academic Program</label>
                        <div class="input-group shadow-sm rounded-8">
                            <span class="input-group-text border-end-0"><i class="fas fa-graduation-cap"></i></span>
                            <select class="form-select border-start-0" name="program_code" required>
                                <option value="" disabled selected>Select the study program</option>
                                <?php
                                $progs = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_name ASC");
                                while($p = $progs->fetch_object()) {
                                    $sel = (isset($_GET['prog']) && $_GET['prog'] == $p->program_code) ? 'selected' : '';
                                    echo "<option value='".htmlspecialchars($p->program_code)."' $sel>".htmlspecialchars($p->program_name)."</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="invalid-feedback">Select the student's program</div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label text-uppercase small ls-1">Admission Intake</label>
                            <div class="input-group shadow-sm rounded-8">
                                <span class="input-group-text border-end-0"><i class="fas fa-calendar-alt"></i></span>
                                <select class="form-select border-start-0" name="intake" required>
                                    <?php $get_intake = $_GET['intake'] ?? ''; ?>
                                    <option value="" disabled <?php echo !$get_intake ? 'selected' : ''; ?>>Select intake</option>
                                    <option value="January" <?php echo (stripos($get_intake, 'Jan') !== false) ? 'selected' : ''; ?>>January Intake</option>
                                    <option value="May" <?php echo (stripos($get_intake, 'May') !== false) ? 'selected' : ''; ?>>May Intake</option>
                                    <option value="July" <?php echo (stripos($get_intake, 'July') !== false) ? 'selected' : ''; ?>>July Intake</option>
                                    <option value="September" <?php echo (stripos($get_intake, 'Sep') !== false) ? 'selected' : ''; ?>>September Intake</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-uppercase small ls-1">Mode of Study</label>
                            <div class="input-group shadow-sm rounded-8">
                                <span class="input-group-text border-end-0"><i class="fas fa-clock"></i></span>
                                <select class="form-select border-start-0" name="mode" required>
                                    <?php $get_mode = $_GET['mode'] ?? ''; ?>
                                    <option value="" disabled <?php echo !$get_mode ? 'selected' : ''; ?>>Select mode</option>
                                    <option value="Full-Time" <?php echo (stripos($get_mode, 'Full') !== false) ? 'selected' : ''; ?>>Full-Time</option>
                                    <option value="Part-Time(Evening)" <?php echo (stripos($get_mode, 'Part') !== false) ? 'selected' : ''; ?>>Part-Time (Evening)</option>
                                    <option value="Distance" <?php echo (stripos($get_mode, 'Dist') !== false) ? 'selected' : ''; ?>>Distance Learning</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-uppercase small ls-1">Start Year</label>
                        <div class="input-group shadow-sm rounded-8">
                            <span class="input-group-text border-end-0"><i class="far fa-calendar-check"></i></span>
                            <select class="form-select border-start-0" name="startYear" required>
                                <option value="" disabled selected>Select start year…</option>
                                <?php $cy = (int)date('Y'); for ($y = $cy + 1; $y >= $cy - 4; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y === $cy ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <small class="text-muted">Completion year is derived automatically from the programme duration.</small>
                    </div>

                    <div class="alert alert-secondary border-0 mb-4 py-2 px-3 small">
                        <i class="fas fa-info-circle me-1"></i> Ensure the student has no outstanding financial blocks before admitting.
                    </div>

                    <div class="d-grid pt-2">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold shadow-sm">
                            <i class="fas fa-check-circle me-2"></i>Complete Admission
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-user-slash fa-3x text-muted mb-3"></i>
                    <h5>No student selected</h5>
                    <p class="text-muted">Please select a student from the registry first.</p>
                    <a href="students_by_admin.php" class="btn btn-primary mt-3">Browse Students</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Bootstrap validation
(function() {
    'use strict';
    var forms = document.querySelectorAll('.needs-validation');
    Array.prototype.slice.call(forms).forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); }
            form.classList.add('was-validated');
        }, false);
    });
})();
</script>

<?php require 'includes/footer.php'; ?>
