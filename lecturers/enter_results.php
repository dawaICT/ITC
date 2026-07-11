<?php
$page_title = 'Enter Exam Results';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/result_entry_helpers.php';
require_once dirname(__DIR__) . '/includes/grading_helpers.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/academic_settings_helper.php';

// Exam result entry is ADMIN-ONLY, or an account the administrator has granted
// an exam permission to. Plain lecturers (and, on this lecturer page, other
// staff roles) have no access. Gate runs before nav.php is emitted so the
// redirect is clean (no "headers already sent").
if (!function_exists('canEnterLecturerExamResults') || !canEnterLecturerExamResults()) {
    $_SESSION['errorMessage'] = 'Access denied. Exam result entry is available to administrators or accounts granted exam access.';
    wuc_safe_redirect('/wucportal/lecturers/index.php');
}

require "includes/nav.php";

$staffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
$flash = ['type' => '', 'message' => ''];

function lecturer_result_course_assigned(mysqli $db, string $staffId, string $courseCode): bool
{
    if (function_exists('canEnterLecturerExamResults') && canEnterLecturerExamResults()) {
        return true;
    }
    if ($staffId === '' || $courseCode === '' || !result_table_exists($db, 'course_lecturer')) {
        return false;
    }
    $stmt = $db->prepare("SELECT 1 FROM course_lecturer WHERE staff_id COLLATE utf8mb4_general_ci = ? AND course_code COLLATE utf8mb4_general_ci = ? AND COALESCE(status, 'active') <> 'inactive' LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $staffId, $courseCode);
    $stmt->execute();
    $stmt->store_result();
    $ok = $stmt->num_rows > 0;
    $stmt->close();
    return $ok;
}

function lecturer_result_courses(mysqli $db, string $staffId): array
{
    if (function_exists('canEnterLecturerExamResults') && canEnterLecturerExamResults()) {
        $rows = [];
        if ($res = $db->query("SELECT course_code, course_name FROM courses ORDER BY course_code")) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->free();
        }
        return $rows;
    }

    if ($staffId === '' || !result_table_exists($db, 'course_lecturer')) {
        return [];
    }
    $sql = "SELECT DISTINCT c.course_code, c.course_name
            FROM course_lecturer cl
            INNER JOIN courses c ON c.course_code COLLATE utf8mb4_general_ci = cl.course_code COLLATE utf8mb4_general_ci
            WHERE cl.staff_id COLLATE utf8mb4_general_ci = ?
              AND COALESCE(cl.status, 'active') <> 'inactive'
            ORDER BY c.course_code";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $staffId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam_result'])) {
    wuc_verify_csrf();

    $courseCode = trim((string)($_POST['course_code'] ?? ''));
    $studentId = trim((string)($_POST['student_id'] ?? ''));
    $semester = trim((string)($_POST['semester'] ?? ''));
    $year = trim((string)($_POST['year'] ?? ''));
    $examMarks = filter_var($_POST['exam_marks'] ?? null, FILTER_VALIDATE_INT);
    $assessmentDate = trim((string)($_POST['assessment_date'] ?? date('Y-m-d')));

    if ($courseCode === '' || $studentId === '' || $semester === '' || $year === '' || $examMarks === false) {
        $flash = ['type' => 'danger', 'message' => 'All result-entry fields are required.'];
    } elseif ($examMarks < 0 || $examMarks > 100) {
        $flash = ['type' => 'danger', 'message' => 'Exam mark must be between 0 and 100.'];
    } elseif (!lecturer_result_course_assigned($db, $staffId, $courseCode)) {
        $flash = ['type' => 'danger', 'message' => 'You are not assigned to this course.'];
    } else {
        $eligibility = result_validate_entry($db, $studentId, $courseCode, $semester, $year, $assessmentDate);
        if (!$eligibility['ok']) {
            $flash = ['type' => 'warning', 'message' => (string)$eligibility['message']];
        } else {
            // Persist the exam mark to the canonical marks table
            // (semester_assessment) via the shared helper, which sets the
            // workflow status to "Submitted" and writes an audit entry. The
            // final mark / grade are derived at read time, so no total is stored.
            $programType = (($eligibility['type'] ?? '') === 'short_course') ? 'short_course' : 'semester';
            $save = result_save_exam_mark($db, $studentId, $courseCode, $semester, $year, (float)$examMarks, $programType, $staffId);
            $flash = $save['ok']
                ? ['type' => 'success', 'message' => $save['message']]
                : ['type' => 'danger', 'message' => $save['message']];
        }
    }
}

$courses = lecturer_result_courses($db, $staffId);
$years = wuc_academic_year_options($db);
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Enter Exam Results</h1>
                <p class="text-muted">Enter final exam marks for students in your assigned courses.</p>
            </div>
            <div class="col-auto d-flex gap-2 flex-wrap">
                <a class="btn btn-outline-primary" href="upload_ca.php"><i class="fas fa-upload me-2"></i>Upload CA</a>
                <a class="btn btn-outline-primary" href="viewCaRes.php"><i class="fas fa-chart-line me-2"></i>CA Results</a>
            </div>
        </div>
    </div>

    <?php if (!empty($flash['message'])): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-10 mx-auto">
            <div class="data-table-card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-pen-alt me-2"></i>Exam Result Entry</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($courses)): ?>
                        <div class="alert alert-warning mb-0">No assigned courses were found for your lecturer account.</div>
                    <?php else: ?>
                    <form method="post" id="lecturerExamResultForm" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="col-md-3">
                            <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                            <select class="form-select" name="year" id="resultYear" required>
                                <option value="" disabled selected>Select</option>
                                <?php foreach ($years as $year): ?>
                                    <option value="<?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Period <span class="text-danger">*</span></label>
                            <select class="form-select" name="semester" id="resultSemester" required>
                                <option value="" disabled selected>Select</option>
                                <option value="1">Semester/Term 1</option>
                                <option value="2">Semester/Term 2</option>
                                <option value="3">Term 3</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Course <span class="text-danger">*</span></label>
                            <select class="form-select" name="course_code" id="resultCourse" required>
                                <option value="" disabled selected>Select course</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo htmlspecialchars((string)$course['course_code'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string)$course['course_code'] . ' - ' . (string)$course['course_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Student <span class="text-danger">*</span></label>
                            <select class="form-select" name="student_id" id="resultStudent" required disabled>
                                <option value="">Select period, year, and course first</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Exam Mark <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="exam_marks" id="resultMark" min="0" max="100" step="1" required disabled>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Assessment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="assessment_date" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="col-12">
                            <div id="resultEntryStatus" class="alert alert-info py-2 small mb-0">Select period, year, and course to load eligible students.</div>
                        </div>

                        <div class="col-12">
                            <button type="submit" name="submit_exam_result" id="resultSubmit" class="btn btn-success" disabled>
                                <i class="fas fa-save me-2"></i>Save Exam Result
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const semester = document.getElementById('resultSemester');
    const year = document.getElementById('resultYear');
    const course = document.getElementById('resultCourse');
    const student = document.getElementById('resultStudent');
    const mark = document.getElementById('resultMark');
    const submit = document.getElementById('resultSubmit');
    const status = document.getElementById('resultEntryStatus');

    if (!semester || !year || !course || !student) return;

    function setStatus(message, type = 'info') {
        status.className = `alert alert-${type} py-2 small mb-0`;
        status.textContent = message;
    }

    function disableEntry(message = 'Select period, year, and course to load eligible students.', type = 'info') {
        student.disabled = true;
        student.innerHTML = '<option value="">Select period, year, and course first</option>';
        mark.disabled = true;
        submit.disabled = true;
        setStatus(message, type);
    }

    async function loadStudents() {
        const period = semester.value;
        const academicYear = year.value;
        const courseCode = course.value;
        if (!period || !academicYear || !courseCode) {
            disableEntry();
            return;
        }

        student.disabled = true;
        student.innerHTML = '<option value="">Loading students...</option>';
        mark.disabled = true;
        submit.disabled = true;
        setStatus('Checking registrations for this course and period...', 'info');

        try {
            const params = new URLSearchParams({ course_code: courseCode, semester: period, year: academicYear });
            const response = await fetch('ajax_get_exam_students.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Unable to load students.');
            }
            if (!data.students || data.students.length === 0) {
                disableEntry(data.message || 'Results cannot be entered because no students are registered for this term.', 'warning');
                return;
            }

            student.innerHTML = '<option value="" disabled selected>Select student</option>';
            data.students.forEach((item) => {
                const opt = document.createElement('option');
                opt.value = item.Sid;
                const baseLabel = item.Sid + (item.name ? ' - ' + item.name : '');
                if (item.eligible === false) {
                    opt.disabled = true;
                    opt.textContent = baseLabel + (item.reason ? ' (' + item.reason + ')' : ' (Not eligible)');
                    opt.title = item.reason || 'Not eligible for exam result entry.';
                } else {
                    opt.textContent = baseLabel;
                }
                student.appendChild(opt);
            });
            const eligibleCount = Number(data.eligible_count || 0);
            student.disabled = eligibleCount === 0;
            mark.disabled = eligibleCount === 0;
            submit.disabled = eligibleCount === 0;
            setStatus(data.message || `${eligibleCount} eligible student(s) loaded.`, eligibleCount > 0 ? 'success' : 'warning');
        } catch (error) {
            disableEntry(error.message, 'danger');
        }
    }

    semester.addEventListener('change', loadStudents);
    year.addEventListener('change', loadStudents);
    course.addEventListener('change', loadStudents);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
