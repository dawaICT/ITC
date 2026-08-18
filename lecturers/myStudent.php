<?php
$page_title = 'My Students';
require_once __DIR__ . '/includes/guard.php';
// DB connection ($db) is already loaded by guard.php

function lecturer_table_exists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    if (!$res) { return false; }
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
}

function lecturer_column_exists(mysqli $db, string $table, string $column): bool {
    $safeTable = $db->real_escape_string($table);
    $safeColumn = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    if (!$res) { return false; }
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
}

// Fetch courses assigned to this lecturer (with program info for period_mode)
$records = [];
$courseProgramMap = []; // course_code => [{program_code, period_mode}]

if (isset($_SESSION['staff_id'])) {
    $staffId = (string)$_SESSION['staff_id'];

    // Courses assigned to this lecturer (canonical + orphan lecturer_courses sync)
    require_once __DIR__ . '/../includes/helpers/lecturer_course_helpers.php';
    require_once __DIR__ . '/../includes/elearning_access.php';
    wuc_sync_legacy_lecturer_courses_table($db, $staffId);
    $assignedCodes = getLecturerAssignedCourses($db, $staffId);
    foreach ($assignedCodes as $code) {
        $obj = new stdClass();
        $obj->course_code = $code;
        $obj->course_name = $code;
        if ($nameStmt = $db->prepare('SELECT course_name FROM courses WHERE course_code = ? LIMIT 1')) {
            $nameStmt->bind_param('s', $code);
            $nameStmt->execute();
            $nameRes = $nameStmt->get_result();
            if ($nameRes && ($nameRow = $nameRes->fetch_assoc())) {
                $obj->course_name = (string)($nameRow['course_name'] ?? $code);
            }
            $nameStmt->close();
        }
        $records[] = $obj;
    }

    $programPeriodExpr = "'semester'";
    if (lecturer_table_exists($db, 'programs')) {
        if (lecturer_column_exists($db, 'programs', 'period_mode')) {
            $programPeriodExpr = "COALESCE(NULLIF(p.period_mode, ''), 'semester')";
        } elseif (lecturer_column_exists($db, 'programs', 'program_type')) {
            $programPeriodExpr = "CASE WHEN LOWER(COALESCE(p.program_type, '')) LIKE '%term%' THEN 'term' ELSE 'semester' END";
        }
    }

    // Resolve period mode per course via two sources:
    // 1) course_lecturer.program_code (often NULL) and
    // 2) program_courses canonical mapping (more reliable).
    // Executed as two separate prepared statements and merged in PHP so the
    // dynamic $programPeriodExpr expression can be used safely in both.
    $mapQueries = [
        "SELECT DISTINCT cl.course_code, cl.program_code,
                {$programPeriodExpr} AS period_mode
         FROM course_lecturer cl
         LEFT JOIN programs p ON cl.program_code = p.program_code
         WHERE cl.staff_id = ?"
    ];
    if (lecturer_table_exists($db, 'program_courses')) {
        $mapQueries[] =
            "SELECT DISTINCT cl.course_code, pc.program_code,
                    {$programPeriodExpr} AS period_mode
             FROM course_lecturer cl
             INNER JOIN program_courses pc ON pc.course_code = cl.course_code
             LEFT JOIN programs p ON pc.program_code = p.program_code
             WHERE cl.staff_id = ?";
    }
    foreach ($mapQueries as $mapSql) {
        $stmt = $db->prepare($mapSql);
        if ($stmt) {
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $courseProgramMap[$row['course_code']][] = [
                    'program_code' => $row['program_code'],
                    'period_mode'  => $row['period_mode'] ?: 'semester',
                ];
            }
            $stmt->close();
        }
    }
}

// Determine period mode per course (first match wins) for JS
$coursePeriodModes = [];
foreach ($courseProgramMap as $cc => $programs) {
    $mode = 'semester';
    foreach ($programs as $p) {
        if ($p['period_mode'] === 'term') { $mode = 'term'; break; }
    }
    $coursePeriodModes[$cc] = $mode;
}

// Compute stats
$totalStudentCount = 0;
$activeProgramCount = 0;
$currentSemLabel = 'N/A';
$currentYearLabel = '';

if (isset($_SESSION['staff_id'])) {
    $staffId = (string)$_SESSION['staff_id'];

    // Total students in lecturer's courses
    $stmt = $db->prepare("SELECT COUNT(DISTINCT cr.Sid) AS total
              FROM course_registration cr
              INNER JOIN course_lecturer lec ON cr.course_code = lec.course_code
              WHERE lec.staff_id = ?");
    if ($stmt) {
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_object();
        if ($row) { $totalStudentCount = (int)$row->total; }
        $stmt->close();
    }

    // Active programs
    $stmt = $db->prepare("SELECT COUNT(DISTINCT COALESCE(sp.program_code, st.program, lec.program_code)) AS total
              FROM course_registration cr
              INNER JOIN course_lecturer lec ON cr.course_code = lec.course_code
              INNER JOIN students st ON cr.Sid = st.SID
              LEFT JOIN student_program sp ON st.SID = sp.Sid AND sp.program_code = st.program
              WHERE lec.staff_id = ?");
    if ($stmt) {
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_object();
        if ($row) { $activeProgramCount = (int)$row->total; }
        $stmt->close();
    }

    // Current period (no user input — no bind needed)
    $stmt = $db->prepare("SELECT semester, Year AS academic_year FROM course_registration ORDER BY Year DESC, semester DESC LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $row = $stmt->get_result()->fetch_object();
        if ($row) { $currentSemLabel = $row->semester; $currentYearLabel = $row->academic_year; }
        $stmt->close();
    }
}
?>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="container-fluid px-4 portal-dashboard">
        <!-- Welcome Section -->
        <div class="dashboard-header">
            <h2><i class="fas fa-users"></i> My Students</h2>
            <hr>
            <p class="welcome-subtitle">Welcome back, <?php echo htmlspecialchars($_SESSION['staff_name'] ?? ($_SESSION['username'] ?? 'Lecturer')); ?>!</p>
        </div>

        <!-- Stats Grid -->
        <div class="row g-3 mb-4">
            <!-- Total Students Card -->
            <div class="col-md-3">
                <div class="data-table-card h-100"><div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-info rounded-circle p-3 me-3"><i class="fas fa-users text-white"></i></div>
                    <div>
                        <h6 class="mb-1">Total Students</h6>
                        <div class="fw-bold"><?php echo $totalStudentCount; ?></div>
                        <small class="text-muted">Enrolled in your courses</small>
                    </div>
                </div></div>
            </div>

            <!-- Total Courses Card -->
            <div class="col-md-3">
                <div class="data-table-card h-100"><div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-primary rounded-circle p-3 me-3"><i class="fas fa-book text-white"></i></div>
                    <div>
                        <h6 class="mb-1">Total Courses</h6>
                        <div class="fw-bold"><?php echo count($records); ?></div>
                        <small class="text-muted">Active courses this semester</small>
                    </div>
                </div></div>
            </div>

            <!-- Programs Card -->
            <div class="col-md-3">
                <div class="data-table-card h-100"><div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-success rounded-circle p-3 me-3"><i class="fas fa-graduation-cap text-white"></i></div>
                    <div>
                        <h6 class="mb-1">Active Programs</h6>
                        <div class="fw-bold"><?php echo $activeProgramCount; ?></div>
                        <small class="text-muted">Different programs taught</small>
                    </div>
                </div></div>
            </div>

            <!-- Current Semester Card -->
            <div class="col-md-3">
                <div class="data-table-card h-100"><div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-warning rounded-circle p-3 me-3"><i class="fas fa-calendar text-white"></i></div>
                    <div>
                        <h6 class="mb-1">Current Period</h6>
                        <div class="fw-bold">
                            <?php echo htmlspecialchars($currentSemLabel); ?>
                            <?php if ($currentYearLabel): ?>
                                <div class="text-muted small"><?php echo htmlspecialchars($currentYearLabel); ?></div>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted">Academic period</small>
                    </div>
                </div></div>
            </div>
        </div>

        <!-- Search Form Card -->
        <div class="card search-form-card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-filter me-2"></i>
                    Filter Students
                </h5>
            </div>
            <div class="card-body">
                <form method="GET" action="myStudent.php" class="needs-validation" novalidate>
                    <div class="row g-3">
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="academic_year" class="form-label">Academic Year</label>
                                <select class="form-select" id="academic_year" name="academic_year" required>
                                    <option value="" disabled <?php echo !isset($_GET['academic_year']) ? 'selected' : ''; ?>>Select Year</option>
                                    <?php 
                                    $currentYear = (int)date('Y');
                                    for ($y = $currentYear; $y >= $currentYear - 5; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo (isset($_GET['academic_year']) && $_GET['academic_year'] == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                                <div class="invalid-feedback">Please select an academic year</div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="course_code" class="form-label">Course (Subject)</label>
                                <select class="form-select" name="course_code" id="course_code" required>
                                    <option value="" disabled <?php echo !isset($_GET['course_code']) ? 'selected' : ''; ?>>Select Subject</option>
                                    <?php foreach($records as $r): ?>
                                    <option value="<?php echo htmlspecialchars($r->course_code); ?>" <?php echo (isset($_GET['course_code']) && $_GET['course_code'] == $r->course_code) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($r->course_code . ' - ' . ($r->course_name ?: 'Unknown')); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select a course</div>
                            </div>
                        </div>

                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="study_mode" class="form-label">Study Mode</label>
                                <select class="form-select" id="study_mode" name="study_mode">
                                    <option value="" <?php echo !isset($_GET['study_mode']) ? 'selected' : ''; ?>>All Modes</option>
                                    <option value="Full-Time" <?php echo (isset($_GET['study_mode']) && $_GET['study_mode'] == 'Full-Time') ? 'selected' : ''; ?>>Full-Time</option>
                                    <option value="Online" <?php echo (isset($_GET['study_mode']) && $_GET['study_mode'] == 'Online') ? 'selected' : ''; ?>>Online</option>
                                    <option value="semester" <?php echo (isset($_GET['study_mode']) && $_GET['study_mode'] == 'semester') ? 'selected' : ''; ?>>Semester</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="semester" class="form-label" id="semester_label">Semester</label>
                                <select class="form-select" id="semester" name="semester" required>
                                    <option value="" disabled <?php echo !isset($_GET['semester']) ? 'selected' : ''; ?>>Select</option>
                                    <!-- Options populated by JS based on course period_mode -->
                                    <option value="1" <?php echo (isset($_GET['semester']) && $_GET['semester'] == '1') ? 'selected' : ''; ?>>1</option>
                                    <option value="2" <?php echo (isset($_GET['semester']) && $_GET['semester'] == '2') ? 'selected' : ''; ?>>2</option>
                                </select>
                                <div class="invalid-feedback">Please select a semester/term</div>
                            </div>
                        </div>

                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="Year" class="form-label">Year of Study</label>
                                <select class="form-select" id="Year" name="Year" required>
                                    <option value="" disabled <?php echo !isset($_GET['Year']) ? 'selected' : ''; ?>>Select YOS</option>
                                    <option value="1" <?php echo (isset($_GET['Year']) && $_GET['Year'] == '1') ? 'selected' : ''; ?>>1</option>
                                    <option value="2" <?php echo (isset($_GET['Year']) && $_GET['Year'] == '2') ? 'selected' : ''; ?>>2</option>
                                    <option value="3" <?php echo (isset($_GET['Year']) && $_GET['Year'] == '3') ? 'selected' : ''; ?>>3</option>
                                    <option value="4" <?php echo (isset($_GET['Year']) && $_GET['Year'] == '4') ? 'selected' : ''; ?>>4</option>
                                </select>
                                <div class="invalid-feedback">Please select a year</div>
                            </div>
                        </div>

                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" name="submit" class="btn btn-success w-100">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if (isset($_GET['submit'])): 
            $course_code   = trim($_GET['course_code'] ?? '');
            $semester      = trim($_GET['semester'] ?? '');
            $Year          = trim($_GET['Year'] ?? '');
            $academic_year = trim($_GET['academic_year'] ?? '');
            $study_mode    = trim($_GET['study_mode'] ?? '');
            $number = 1;
            $records_1 = [];

            // Look up the course name for header display
            $courseDisplayName = $course_code;
            foreach ($records as $r) {
                if ($r->course_code === $course_code && !empty($r->course_name)) {
                    $courseDisplayName = $r->course_code . ' - ' . $r->course_name;
                    break;
                }
            }

            // Determine period label for this course
            $periodLabel = 'Semester';
            if (isset($coursePeriodModes[$course_code]) && $coursePeriodModes[$course_code] === 'term') {
                $periodLabel = 'Term';
            }

            // Build dynamic WHERE clause with prepared-statement placeholders.
            $staffIdVal    = (string)($_SESSION['staff_id'] ?? '');
            $bindTypes     = 'ss';
            $bindParams    = [$staffIdVal, $course_code];
            $whereConditions = ["lec.staff_id = ?", "cr.course_code = ?"];

            if ($Year !== '') {
                // cr.Year is year-of-study in current registration writers; keep
                // the lecturer/student fields as the display filter.
                $whereConditions[] = "COALESCE(lec.year_of_study, st.year) = ?";
                $bindParams[] = $Year;
                $bindTypes .= 's';
            }
            if ($academic_year !== '') {
                // Lenient match: yearly course registrations may store an integer
                // academic_year, while lecturer assignments sometimes store spans
                // such as "2025/2026".
                $likeYear = '%' . $academic_year . '%';
                $academicYearParts = [];
                if (lecturer_column_exists($db, 'course_registration', 'academic_year')) {
                    $academicYearParts[] = "CAST(cr.academic_year AS CHAR) = ?";
                    $bindParams[] = $academic_year;
                    $bindTypes .= 's';
                }
                if (lecturer_column_exists($db, 'students', 'academic_year')) {
                    $academicYearParts[] = "st.academic_year = ?";
                    $bindParams[] = $academic_year;
                    $bindTypes .= 's';
                }
                if (lecturer_column_exists($db, 'course_lecturer', 'academic_year')) {
                    $academicYearParts[] = "lec.academic_year = ?";
                    $academicYearParts[] = "lec.academic_year LIKE ?";
                    $bindParams[] = $academic_year;
                    $bindParams[] = $likeYear;
                    $bindTypes .= 'ss';
                }
                if ($academicYearParts) {
                    $whereConditions[] = '(' . implode(' OR ', $academicYearParts) . ')';
                }
            }
            if ($study_mode !== '') {
                $whereConditions[] = "COALESCE(sp.mode, st.mode, '') = ?";
                $bindParams[] = $study_mode;
                $bindTypes .= 's';
            }

            $sql1 = "SELECT DISTINCT st.SID AS Sid, st.Fname, st.Lname, st.sex,
                            COALESCE(sp.program_code, st.program, lec.program_code, '') AS program_code,
                            cr.semester,
                            COALESCE(lec.year_of_study, st.year) AS Year,
                            COALESCE(sp.mode, st.mode, '') AS study_mode
                     FROM course_registration cr
                     INNER JOIN students st ON cr.Sid = st.SID
                     LEFT JOIN student_program sp ON st.SID = sp.Sid AND sp.program_code = st.program
                     INNER JOIN course_lecturer lec ON cr.course_code = lec.course_code
                     WHERE " . implode(' AND ', $whereConditions) . "
                     ORDER BY st.Fname, st.Lname";

            $stmt1 = $db->prepare($sql1);
            if ($stmt1) {
                $stmt1->bind_param($bindTypes, ...$bindParams);
                $stmt1->execute();
                $res1 = $stmt1->get_result();
                while ($row = $res1->fetch_object()) { $records_1[] = $row; }
                $stmt1->close();
            }
        ?>
        <!-- Results Card -->
        <div class="card results-card shadow mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-table me-2"></i>
                    Generated Class List
                </h5>
                <button class="btn btn-dark btn-sm" onClick="printContent('print')">
                    <i class="fas fa-print me-2"></i>Print Records
                </button>
            </div>
            <div class="card-body">
                <div id="print">
                    <!-- Header Section (always visible, centred) -->
                    <div class="text-center mb-4">
                        <!-- display:block + margin:0 auto centres the banner regardless of
                             the global "img { display: block; }" rule in assets/css/main.css -->
                        <img src="/wucportal/images/itc_logo.png"
                             alt="Industrial Training Centre"
                             class="mb-3 official-logo"
                             style="display:block;margin:0 auto;max-width:380px;width:100%;height:auto;">
                        <h4 class="mb-1"><strong>Industrial Training Centre</strong></h4>
                        <h5 class="mb-3 text-muted">Official Class List</h5>
                        <hr>
                        <div class="row justify-content-center text-start" style="max-width: 600px; margin: 0 auto;">
                            <div class="col-6">
                                <p class="mb-1"><strong>Course:</strong> <?php echo htmlspecialchars($courseDisplayName); ?></p>
                                <?php if (!empty($academic_year)): ?>
                                    <p class="mb-1"><strong>Academic Year:</strong> <?php echo htmlspecialchars($academic_year); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($study_mode)): ?>
                                    <p class="mb-1"><strong>Study Mode:</strong> <?php echo htmlspecialchars($study_mode); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="col-6">
                                <?php if (!empty($semester)): ?>
                                    <p class="mb-1"><strong><?php echo $periodLabel; ?>:</strong> <?php echo htmlspecialchars($semester); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($Year)): ?>
                                    <p class="mb-1"><strong>Year of Study:</strong> <?php echo htmlspecialchars($Year); ?></p>
                                <?php endif; ?>
                                <p class="mb-1"><strong>Total Students:</strong> <?php echo count($records_1); ?></p>
                            </div>
                        </div>
                        <!-- Date Generated — standalone row so it is always visible -->
                        <p class="mt-2 mb-0"><strong>Date Generated:</strong> <?php echo htmlspecialchars(date('d F Y')); ?></p>
                    </div>

                    <!-- Class List Table -->
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Student ID</th>
                                    <th>Full Name</th>
                                    <th>Gender</th>
                                    <th>Program</th>
                                    <th><?php echo $periodLabel; ?></th>
                                    <th>Year</th>
                                    <th>Date</th>
                                    <th>Sign</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($records_1)): ?>
                                    <?php foreach($records_1 as $r): ?>
                                    <tr>
                                        <td><?php echo $number++; ?></td>
                                        <td><strong><?php echo htmlspecialchars($r->Sid); ?></strong></td>
                                        <td><?php echo htmlspecialchars($r->Fname . ' ' . $r->Lname); ?></td>
                                        <td><?php echo htmlspecialchars((string)($r->sex ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string)($r->program_code ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string)($r->semester ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string)($r->Year ?? '')); ?></td>
                                        <td></td>
                                        <td></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted">No students found for the selected criteria.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Signature Section -->
                    <div class="signature-section mt-4">
                        <p>Lecturer's Signature: _____________________ Date: _____________________</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Course period mode map (from PHP)
const coursePeriodModes = <?php echo json_encode($coursePeriodModes); ?>;

// Update the semester/term dropdown when the course changes
const courseSelect = document.getElementById('course_code');
const semesterSelect = document.getElementById('semester');
const semesterLabel = document.getElementById('semester_label');

function updatePeriodFilter() {
    const selectedCourse = courseSelect.value;
    const mode = coursePeriodModes[selectedCourse] || 'semester';
    const currentVal = semesterSelect.value;

    // Clear existing options (keep the disabled placeholder)
    semesterSelect.innerHTML = '';

    if (mode === 'term') {
        semesterLabel.textContent = 'Term';
        semesterSelect.innerHTML = `
            <option value="" disabled>Select Term</option>
            <option value="1" ${currentVal === '1' ? 'selected' : ''}>Term 1</option>
            <option value="2" ${currentVal === '2' ? 'selected' : ''}>Term 2</option>
            <option value="3" ${currentVal === '3' ? 'selected' : ''}>Term 3</option>
        `;
    } else {
        semesterLabel.textContent = 'Semester';
        semesterSelect.innerHTML = `
            <option value="" disabled>Select Sem</option>
            <option value="1" ${currentVal === '1' ? 'selected' : ''}>Semester 1</option>
            <option value="2" ${currentVal === '2' ? 'selected' : ''}>Semester 2</option>
        `;
    }

    // If no value was set, select the placeholder
    if (!currentVal) {
        semesterSelect.querySelector('option[disabled]').selected = true;
    }
}

if (courseSelect) {
    courseSelect.addEventListener('change', updatePeriodFilter);
    // Run on page load to set correct filter for pre-selected course
    if (courseSelect.value) {
        updatePeriodFilter();
    }
}

// Form validation
(function() {
    'use strict';
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();
</script>

<style>
    @media print {
        .search-form-card, 
        .btn, 
        .dashboard-header, 
        nav, 
        .navbar,
        .sidebar,
        .footer,
        .card-header button,
        .data-table-card,
        .row.g-3.mb-4 { 
            display: none !important; 
        }
        .card { 
            border: none !important; 
            box-shadow: none !important;
        }
        body { 
            background: white !important; 
        }
        .table {
            page-break-inside: auto;
        }
        .table tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }
        .table thead {
            display: table-header-group;
        }
        .results-card {
            margin-top: 0 !important;
        }
        .results-card .card-header {
            display: none !important;
        }
    }
</style>

<script>
function printContent(elementId) {
    const content = document.getElementById(elementId);
    if (!content) return;
    if (window.wucPrintElement) {
        window.wucPrintElement(content, document.title);
        return;
    }
    const originalContents = document.body.innerHTML;

    document.body.innerHTML = content.innerHTML;
    window.print();
    document.body.innerHTML = originalContents;

    // Reattach event listeners after printing
    location.reload();
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
