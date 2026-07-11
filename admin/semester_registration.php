<?php
include "includes/admin.php";
require_once __DIR__ . '/../includes/helpers/academic_structure_helpers.php';
error_reporting(0);

$page_title = 'Student Registration';

/*
 * Registration now supports BOTH semester and term programs.
 * The semester_registration table stores the period number in `semester` and
 * the kind of period in `period_type` ('semester' | 'term'). academic_year is
 * pulled from the current academic_periods row of the chosen type.
 *
 * Live column names: student_id, program_code, semester, period_type,
 * year_of_study, academic_year, registration_date.
 */

// The live semester_registration.academic_year column may be varchar(4) in
// older installs. Store the same value consistently for duplicate checks and
// inserts, trimming a "2025/2026" label to "2025" only when the schema requires it.
function reg_storage_academic_year(mysqli $db, string $academicYear): string {
    static $maxLength = null;
    if ($maxLength === null) {
        $maxLength = 20;
        if ($stmt = $db->prepare("SELECT CHARACTER_MAXIMUM_LENGTH
                                  FROM information_schema.COLUMNS
                                  WHERE TABLE_SCHEMA = DATABASE()
                                    AND TABLE_NAME = 'semester_registration'
                                    AND COLUMN_NAME = 'academic_year'
                                  LIMIT 1")) {
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                if ($row && (int)$row['CHARACTER_MAXIMUM_LENGTH'] > 0) {
                    $maxLength = (int)$row['CHARACTER_MAXIMUM_LENGTH'];
                }
            }
            $stmt->close();
        }
    }

    $academicYear = trim($academicYear);
    if ($maxLength <= 4 && preg_match('/\d{4}/', $academicYear, $m)) {
        return $m[0];
    }
    return substr($academicYear, 0, $maxLength);
}

// Helper: current academic year label for a period type (fallback to calendar).
function reg_current_academic_year(mysqli $db, string $periodType): string {
    if ($stmt = $db->prepare("SELECT academic_year FROM academic_periods WHERE period_type = ? AND is_current = 1 ORDER BY id DESC LIMIT 1")) {
        $stmt->bind_param('s', $periodType);
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && !empty($row['academic_year'])) {
                return reg_storage_academic_year($db, (string)$row['academic_year']);
            }
        } else {
            $stmt->close();
        }
    }
    $y = (int) date('Y');
    return reg_storage_academic_year($db, (string)$y);
}

function reg_period_limit(string $periodType): int {
    if ($periodType === 'term' || $periodType === 'trade_test_level') {
        return 3;
    }
    if ($periodType === 'short_course_cycle') {
        return 4;
    }
    return 2;
}

// Handle submission
if (!empty($_POST) && isset($_POST["program_code"], $_POST["student_id"], $_POST["period_number"], $_POST["year_of_study"])) {
    $program_code = trim($_POST["program_code"]);
    $student_id   = trim($_POST["student_id"]);
    $period_type  = strtolower(trim($_POST["period_type"] ?? ''));
    if (!in_array($period_type, ['semester', 'term', 'trade_test_level', 'short_course_cycle'], true)) {
        echo "<script>alert('Please select a valid registration type')</script>";
        echo "<script>window.open('semester_registration.php','_self')</script>";
        exit;
    }
    $period_no    = (int) $_POST["period_number"];
    $year_study   = (int) $_POST["year_of_study"];
    $period_limit = reg_period_limit($period_type);

    if ($period_no < 1 || $period_no > $period_limit) {
        $label = ucfirst($period_type);
        echo "<script>alert('Invalid {$label} number')</script>";
        echo "<script>window.open('semester_registration.php','_self')</script>";
        exit;
    }

    if ($year_study < 1 || $year_study > 8) {
        echo "<script>alert('Invalid year of study')</script>";
        echo "<script>window.open('semester_registration.php','_self')</script>";
        exit;
    }

    // Check the student is enrolled in the selected program.
    $studentExists = false;
    if ($stmt = $db->prepare("SELECT 1 FROM student_program WHERE Sid = ? AND program_code = ? AND LOWER(COALESCE(status, 'active')) = 'active' LIMIT 1")) {
        $stmt->bind_param('ss', $student_id, $program_code);
        if ($stmt->execute()) {
            $studentExists = (bool) $stmt->get_result()->num_rows;
        }
        $stmt->close();
    }

    if (!$studentExists) {
        echo "<script>alert('This student is not actively enrolled in the selected program')</script>";
        echo "<script>window.open('semester_registration.php','_self')</script>";
        exit;
    }

    $guard = wuc_legacy_course_registration_guard($db, $student_id, $program_code, $year_study, $period_no);
    if (!$guard['ok']) {
        echo "<script>alert(" . json_encode($guard['reason']) . ")</script>";
        echo "<script>window.open('semester_registration.php','_self')</script>";
        exit;
    }
    if ($period_type !== $guard['period_type']) {
        echo "<script>alert('The selected registration type does not match the programme structure')</script>";
        echo "<script>window.open('semester_registration.php','_self')</script>";
        exit;
    }
    $period_type = $guard['period_type'];

    // Check duplicate registration for this period
    $academic_year = reg_current_academic_year($db, $period_type);
    $alreadyRegistered = false;
    if ($stmt = $db->prepare("SELECT 1 FROM semester_registration WHERE student_id = ? AND program_code = ? AND semester = ? AND period_type = ? AND year_of_study = ? AND academic_year = ? LIMIT 1")) {
        $stmt->bind_param('ssisis', $student_id, $program_code, $period_no, $period_type, $year_study, $academic_year);
        if ($stmt->execute()) {
            $alreadyRegistered = (bool) $stmt->get_result()->num_rows;
        }
        $stmt->close();
    }

    if ($alreadyRegistered) {
        $label = ucfirst($period_type);
        echo "<script>alert('This student is already registered for {$label} {$period_no}')</script>";
        echo "<script>window.open('semester_registration.php','_self')</script>";
        exit;
    }

    // Insert registration
    if ($program_code !== '' && $student_id !== '' && $period_no > 0 && $year_study > 0) {
        $sql = "INSERT INTO semester_registration
                    (student_id, program_code, semester, period_type, year_of_study, academic_year, registration_date, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('ssisis', $student_id, $program_code, $period_no, $period_type, $year_study, $academic_year);
            if ($stmt->execute()) {
                $label = ucfirst($period_type);
                echo "<script>alert('Student {$label} registration successful')</script>";
                echo "<script>window.open('semester_registration.php','_self')</script>";
                exit;
            }
            $stmt->close();
        }
    }

    echo "<script>alert('Process failed!')</script>";
    echo "<script>window.open('semester_registration.php','_self')</script>";
    exit;
}

// Fetch programs with authoritative period_mode so the form can pre-select type.
$records = [];
$hasPeriodMode = false;
$hasStudyMode = false;
if ($res = $db->query("SHOW COLUMNS FROM programs LIKE 'period_mode'")) {
    $hasPeriodMode = $res->num_rows > 0; $res->free();
}
if (!$hasPeriodMode && $res = $db->query("SHOW COLUMNS FROM programs LIKE 'study_mode'")) {
    $hasStudyMode = $res->num_rows > 0; $res->free();
}
$modeExpr = "''";
$programCols = [];
if ($cols = $db->query("SHOW COLUMNS FROM programs")) {
    while ($col = $cols->fetch_assoc()) {
        $programCols[strtolower((string)$col['Field'])] = (string)$col['Field'];
    }
    $cols->free();
}
if (isset($programCols['structure_type'])) {
    $fallbackMode = $hasPeriodMode ? "COALESCE(period_mode, '')" : ($hasStudyMode ? "COALESCE(study_mode, '')" : "''");
    $modeExpr = "CASE structure_type
        WHEN 'TERM_BASED' THEN 'term'
        WHEN 'SEMESTER_BASED' THEN 'semester'
        WHEN 'TRADE_TEST_LEVEL' THEN 'trade_test_level'
        WHEN 'SHORT_COURSE' THEN 'short_course_cycle'
        ELSE {$fallbackMode}
    END";
} elseif ($hasPeriodMode) {
    $modeExpr = "COALESCE(period_mode, '')";
} elseif ($hasStudyMode) {
    $modeExpr = "COALESCE(study_mode, '')";
}
if ($results = $db->query("SELECT program_code, $modeExpr AS period_mode FROM programs ORDER BY program_code")) {
    while ($row = $results->fetch_object()) { $records[] = $row; }
    $results->free();
}

require 'includes/header.php';
?>
<style>
    html,
    body.has-unified-sidebar,
    .main-wrapper,
    .main-content {
        background: #f6f8fb !important;
    }

    .semester-registration-page {
        min-height: calc(100vh - 3rem);
    }

    .semester-registration-panel {
        max-width: 640px;
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

    .registration-card .bg-blue-soft {
        background: #e8f2ff !important;
    }

    .registration-card .text-blue {
        color: #2563eb !important;
    }

    .registration-card .stat-icon i {
        color: #2563eb !important;
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
    .registration-card .form-select:focus {
        box-shadow: none;
    }

    .registration-card .input-group:focus-within {
        border-color: #8bb8ff;
        box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.12);
    }

    @media (max-width: 575.98px) {
        .semester-registration-page {
            padding-left: 0.75rem !important;
            padding-right: 0.75rem !important;
        }

        .registration-card {
            padding: 1.25rem;
        }
    }
</style>

<div class="container-fluid px-4 portal-dashboard semester-registration-page">
    <div class="page-header mb-4 mt-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-clipboard-check me-2 text-primary"></i>Student Registration</h5>
                <p class="page-subtitle mb-0">Register students for the current academic period (semester or term)</p>
            </div>
            <div class="header-actions">
                <a href="semesterReg_stud.php" class="btn btn-outline-secondary shadow-sm">
                    <i class="fas fa-list me-1"></i>View Registrations
                </a>
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-xl-7 col-lg-8 col-md-10 semester-registration-panel">
            <div class="registration-card mb-5">
                <div class="text-center mb-4">
                    <div class="stat-icon bg-blue-soft text-blue mx-auto mb-3" style="width:70px;height:70px;font-size:1.5rem;">
                        <i class="fas fa-user-edit"></i>
                    </div>
                    <h5 class="fw-bold mb-1">New Registration</h5>
                    <p class="text-muted small">Semester and term programs are both supported</p>
                </div>

                <form action="semester_registration.php" method="post" class="needs-validation" novalidate>
                    <div class="mb-4">
                        <label for="student_id" class="form-label text-uppercase small ls-1">Student Identification</label>
                        <div class="input-group shadow-sm rounded-8">
                            <span class="input-group-text border-end-0"><i class="fas fa-id-card"></i></span>
                            <input type="text" class="form-control border-start-0" name="student_id" id="student_id"
                                   placeholder="Student ID (e.g. 20261001)" autocomplete="off" required>
                        </div>
                        <div class="invalid-feedback">Valid Student ID is required</div>
                        <div id="student-lookup-msg" class="form-text mt-1"></div>
                    </div>

                    <div class="mb-4">
                        <label for="program_code" class="form-label text-uppercase small ls-1">Academic Program</label>
                        <div class="input-group shadow-sm rounded-8">
                            <span class="input-group-text border-end-0"><i class="fas fa-graduation-cap"></i></span>
                            <select class="form-select border-start-0" name="program_code" id="program_code" required>
                                <option disabled selected value="">Select registered program...</option>
                                <?php foreach($records as $r): ?>
                                    <option value="<?php echo htmlspecialchars($r->program_code); ?>"
                                            data-mode="<?php echo htmlspecialchars(strtolower((string)$r->period_mode)); ?>">
                                        <?php echo htmlspecialchars($r->program_code); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="invalid-feedback">Select the student's program</div>
                    </div>

                    <div class="mb-4">
                        <label for="period_type" class="form-label text-uppercase small ls-1">Registration Type</label>
                        <div class="input-group shadow-sm rounded-8">
                            <span class="input-group-text border-end-0"><i class="fas fa-stream"></i></span>
                            <select class="form-select border-start-0" name="period_type" id="period_type" required>
                                <option value="" disabled selected>Select registration type…</option>
                                <option value="semester">Semester-based</option>
                                <option value="term">Term-based</option>
                                <option value="trade_test_level">Trade-test level</option>
                                <option value="short_course_cycle">Short-course cycle</option>
                            </select>
                        </div>
                        <small class="text-muted">Auto-selected only when the program states its mode; otherwise choose explicitly.</small>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="period_number" class="form-label text-uppercase small ls-1"><span id="periodLabel">Semester</span></label>
                            <div class="input-group shadow-sm rounded-8">
                                <span class="input-group-text border-end-0"><i class="fas fa-calendar-alt"></i></span>
                                <select class="form-select border-start-0" name="period_number" id="period_number" required></select>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="year_of_study" class="form-label text-uppercase small ls-1">Year of Study</label>
                            <div class="input-group shadow-sm rounded-8">
                                <span class="input-group-text border-end-0"><i class="far fa-calendar-check"></i></span>
                                <select class="form-select border-start-0" id="year_of_study" name="year_of_study" required>
                                    <option disabled selected value="">Year...</option>
                                    <?php for ($i = 1; $i <= 8; $i++): ?>
                                        <option value="<?php echo $i; ?>">Year <?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-secondary border-0 mb-4 py-2 px-3 small">
                        <i class="fas fa-info-circle me-1"></i> Ensure the student has no outstanding financial blocks before registering.
                    </div>

                    <div class="d-grid pt-2">
                        <button class="btn btn-primary btn-lg fw-bold shadow-sm" type="submit">
                            <i class="fas fa-check-circle me-2"></i>Complete Registration
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var programSel = document.getElementById('program_code');
    var typeSel    = document.getElementById('period_number') ? document.getElementById('period_type') : null;
    var periodSel  = document.getElementById('period_number');
    var periodLbl  = document.getElementById('periodLabel');

    function rebuildPeriods() {
        var pt = document.getElementById('period_type').value;
        periodSel.innerHTML = '';
        if (!['semester', 'term', 'trade_test_level', 'short_course_cycle'].includes(pt)) {
            // No registration type chosen yet — do not assume semester.
            periodLbl.textContent = 'Period';
            var ph = document.createElement('option');
            ph.value = ''; ph.textContent = 'Choose type first'; ph.disabled = true; ph.selected = true;
            periodSel.appendChild(ph);
            return;
        }
        var label = 'Semester';
        var max = 2;
        if (pt === 'term') {
            label = 'Term';
            max = 3;
        } else if (pt === 'trade_test_level') {
            label = 'Trade Test Level';
            max = 3;
        } else if (pt === 'short_course_cycle') {
            label = 'Short Course Cycle';
            max = 4;
        }
        periodLbl.textContent = label;
        for (var i = 1; i <= max; i++) {
            var opt = document.createElement('option');
            opt.value = i;
            opt.textContent = label + ' ' + i;
            periodSel.appendChild(opt);
        }
    }

    // When a program is chosen, pre-select its registration type ONLY if the program states it.
    if (programSel) {
        programSel.addEventListener('change', function () {
            var mode = (this.options[this.selectedIndex].getAttribute('data-mode') || '').toLowerCase();
            var ptSel = document.getElementById('period_type');
            if (['term', 'semester', 'trade_test_level', 'short_course_cycle'].includes(mode)) ptSel.value = mode;
            else ptSel.value = ''; // not stated -> require explicit choice
            rebuildPeriods();
        });
    }
    document.getElementById('period_type').addEventListener('change', rebuildPeriods);
    rebuildPeriods();

    var sidInput = document.getElementById('student_id');
    var lookupMsg = document.getElementById('student-lookup-msg');
    var regForm = document.querySelector('.needs-validation');
    var regBtn = regForm ? regForm.querySelector('button[type="submit"]') : null;
    if (sidInput && lookupMsg) {
        sidInput.addEventListener('blur', function () {
            var sid = sidInput.value.trim();
            if (!sid) {
                lookupMsg.textContent = '';
                if (regBtn) regBtn.disabled = false;
                return;
            }
            fetch('ajax/student_lookup.php?sid=' + encodeURIComponent(sid), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.ok) {
                        lookupMsg.className = 'form-text mt-1 text-success';
                        lookupMsg.textContent = data.name + (data.program_code ? ' — ' + data.program_code : '');
                        if (regBtn) regBtn.disabled = false;
                        var prog = document.getElementById('program_code');
                        if (prog && data.program_code) {
                            for (var i = 0; i < prog.options.length; i++) {
                                if (prog.options[i].value === data.program_code) {
                                    prog.value = data.program_code;
                                    prog.dispatchEvent(new Event('change'));
                                    break;
                                }
                            }
                        }
                    } else {
                        lookupMsg.className = 'form-text mt-1 text-danger';
                        lookupMsg.textContent = data.message || 'Student not found';
                        if (regBtn) regBtn.disabled = true;
                    }
                })
                .catch(function () {});
        });
    }
})();

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
