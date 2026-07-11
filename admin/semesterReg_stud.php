<?php
$page_title = "Registration Report";
require_once "includes/admin.php";
require_once "includes/header.php";

// This report covers ALL registration types, not only semester registrations:
//   • Programme registrations  -> student_program (+ semester_registration when present)
//   • Short-course registrations -> short_course_enrollments
// Basing the programme branch on student_program (not semester_registration) means
// every registered student appears, even before a per-semester registration exists.

// Get all programs for dropdown with graceful fallback if 'status' column doesn't exist
try {
    $programs = array();
    $hasStatusColumn = false;
    if ($res = @$db->query("SHOW COLUMNS FROM programs LIKE 'status'")) {
        $hasStatusColumn = $res->num_rows > 0;
        $res->free();
    }
    // period_mode (semester|term) drives the auto-detected Period Type so the
    // operator never has to pick it manually — see the Period filter below.
    $programs_query = $hasStatusColumn
        ? "SELECT program_code, program_name, period_mode FROM programs WHERE status = 'Active' ORDER BY program_name"
        : "SELECT program_code, program_name, period_mode FROM programs ORDER BY program_name";

    if ($stmt = $db->prepare($programs_query)) {
        $stmt->execute();
        $result = $stmt->get_result();
        $programs = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        throw new Exception("Failed to prepare program query: " . $db->error);
    }
} catch (Throwable $e) {
    $_SESSION['error'] = "Error loading programs: " . $e->getMessage();
    $programs = array();
}

// Academic-year options reflect ONLY years that actually have registrations
// (semester registrations + short-course registrations), not a synthetic range
// and not admission years for students who never registered. The derivation
// mirrors the academic-year filter expressions below so every year offered
// returns rows.
$academic_years = array();
$ay_sql = "SELECT DISTINCT y FROM (
        SELECT COALESCE(NULLIF(sr.academic_year, ''), YEAR(COALESCE(sr.registration_date, sr.created_at))) AS y
          FROM semester_registration sr
        UNION
        SELECT YEAR(COALESCE(e.enrollment_date, e.created_at, sc.start_date))
          FROM short_course_enrollments e
          JOIN short_courses sc ON sc.id = e.short_course_id
    ) t
    WHERE y IS NOT NULL AND y <> 0 AND y REGEXP '^[0-9]+$'
    ORDER BY y DESC";
if ($res = @$db->query($ay_sql)) {
    while ($r = $res->fetch_assoc()) {
        $academic_years[] = (int)$r['y'];
    }
    $res->free();
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Registration Report</h1>
                <p class="text-muted">Generate and analyze all student registrations — programmes and short courses</p>
            </div>
            <div class="col-auto">
                <div class="d-flex gap-2">
                    <a href="index.php" class="btn btn-primary d-flex align-items-center gap-2">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php 
            echo $_SESSION['error'];
            unset($_SESSION['error']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Report Header -->
    <div class="data-table-card mb-4">
        <div class="card-body text-center">
            <img src="images/itc_logo.png" alt="Industrial training college Logo" class="mb-3" style="height: 100px;">
            <h4 class="mb-3">Industrial training college</h4>
            <h5 class="text-muted">Registration Report — All Registrations</h5>
        </div>
    </div>

    <!-- Search Form -->
    <div class="data-table-card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-filter me-2"></i>Report Filters
                </h5>
            </div>
        </div>
        <div class="card-body">
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] ?? 'semesterReg_stud.php', ENT_QUOTES, 'UTF-8'); ?>" method="POST" class="row g-3" id="semesterReportForm">
                <input type="hidden" name="generate_report" value="1">
                <div class="col-md-2">
                    <div class="form-group">
                        <label class="form-label">Registration Type</label>
                        <select name="reg_type" id="reg_type" class="form-select">
                            <?php $sel_rt = $_POST['reg_type'] ?? 'all'; ?>
                            <option value="all" <?php echo $sel_rt === 'all' ? 'selected' : ''; ?>>All Registrations</option>
                            <option value="program" <?php echo $sel_rt === 'program' ? 'selected' : ''; ?>>Programmes</option>
                            <option value="short_course" <?php echo $sel_rt === 'short_course' ? 'selected' : ''; ?>>Short Courses</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label class="form-label">Program</label>
                        <select name="program_code" id="program_code" class="form-select">
                            <option value="">Select Program</option>
                            <?php foreach($programs as $program): ?>
                                <?php $selected = (isset($_POST['program_code']) && $_POST['program_code'] == $program['program_code']) ? 'selected' : ''; ?>
                                <option value="<?php echo htmlspecialchars($program['program_code']); ?>"
                                        data-period-mode="<?php echo htmlspecialchars($program['period_mode'] ?? 'semester'); ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($program['program_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label class="form-label">Year of Study</label>
                        <select name="year" id="year" class="form-select">
                            <option value="">Select Year</option>
                            <?php for($i = 1; $i <= 4; $i++): ?>
                                <?php $selected = (isset($_POST['year']) && $_POST['year'] == $i) ? 'selected' : ''; ?>
                                <option value="<?php echo $i; ?>" <?php echo $selected; ?>>Year <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <!-- Period type is auto-detected from the selected program's
                             period_mode; the operator only chooses the number. The
                             label and option text below are relabeled by JS
                             (Semester N / Term N / Period N) to match. -->
                        <label class="form-label" id="periodLabel">Period</label>
                        <select name="semester" id="semester" class="form-select">
                            <option value="">All Periods</option>
                            <?php for($i = 1; $i <= 3; $i++): ?>
                                <?php $selected = (isset($_POST['semester']) && $_POST['semester'] == $i) ? 'selected' : ''; ?>
                                <option value="<?php echo $i; ?>" <?php echo $selected; ?>>Period <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                        <!-- Carries the auto-detected period type to the server.
                             Set by JS from the selected program; empty = no program
                             selected (then the report spans semester & term). -->
                        <input type="hidden" name="period_type" id="period_type"
                               value="<?php echo htmlspecialchars($_POST['period_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="">All</option>
                            <option value="M" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'M') ? 'selected' : ''; ?>>Male</option>
                            <option value="F" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'F') ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label class="form-label">Academic Year</label>
                        <select name="academic_year" id="academic_year" class="form-select">
                            <option value="">All Years</option>
                            <?php foreach ($academic_years as $year): ?>
                                <?php $selected = (isset($_POST['academic_year']) && $_POST['academic_year'] == $year) ? 'selected' : ''; ?>
                                <option value="<?php echo (int)$year; ?>" <?php echo $selected; ?>><?php echo (int)$year; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100" id="generateSemesterReportBtn">
                            <i class="fas fa-sync-alt me-2"></i>Generate Report
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if(isset($_POST['generate_report'])): ?>
        <?php
    $reg_type      = isset($_POST['reg_type']) ? trim($_POST['reg_type']) : 'all';
    $program_code  = isset($_POST['program_code']) ? trim($_POST['program_code']) : '';
    $year          = isset($_POST['year']) ? trim($_POST['year']) : '';
    $period_type   = isset($_POST['period_type']) ? trim($_POST['period_type']) : '';
    $semester      = isset($_POST['semester']) ? trim($_POST['semester']) : '';
    $gender        = isset($_POST['gender']) ? trim($_POST['gender']) : '';
    $academic_year = isset($_POST['academic_year']) ? trim($_POST['academic_year']) : '';

    // Validation checks
    if (!in_array($reg_type, ['all', 'program', 'short_course'], true)) {
        $reg_type = 'all';
    }

    $valid_programs = array_column($programs, 'program_code');
    if ($program_code !== '' && !in_array($program_code, $valid_programs, true)) {
        $program_code = '';
    }

    if ($year !== '' && !in_array((int)$year, [1, 2, 3, 4, 5], true)) {
        $year = '';
    }

    // Period type must be one of the semester_registration.period_type enum values.
    if ($period_type !== '' && !in_array($period_type, ['semester', 'term'], true)) {
        $period_type = '';
    }

    if ($semester !== '' && !in_array((int)$semester, [1, 2, 3], true)) {
        $semester = '';
    }

    if ($gender !== '' && !in_array($gender, ['M', 'F'], true)) {
        $gender = '';
    }

    if ($academic_year !== '' && !in_array((int)$academic_year, $academic_years, true)) {
        $academic_year = '';
    }

    // Programme-only filters are meaningless for short courses (which have no
    // semester/term period — they are measured in duration).
    if ($reg_type === 'short_course') {
        $program_code = '';
        $year = '';
        $period_type = '';
        $semester = '';
    }

    // Build the report as a UNION of every registration type. Each branch yields
    // the SAME 13 columns so they line up under UNION ALL. Text is CONVERTed to
    // utf8mb4 so columns from differently-collated tables can be unioned safely.
    $queries = [];
    $types   = '';
    $params  = [];

    // -- Branch A: programme registrations -------------------------------------
    //    Based on actual registration RECORDS (semester_registration), NOT on
    //    student_program (admission). A student appears only once they have
    //    registered — basing this on student_program previously listed every
    //    admitted student, including those who never did course registration.
    //    course_registration rows are children of a semester_registration (every
    //    registered student has a semester_registration), so this covers them.
    //    student_program / programs are LEFT-joined only for display detail; the
    //    sp join is pinned to a single id to avoid fan-out when a student has
    //    multiple identical student_program rows.
    if ($reg_type === 'all' || $reg_type === 'program') {
        $progSql = "SELECT
                CONVERT(sr.student_id USING utf8mb4) AS Sid,
                CONVERT(COALESCE(s.Fname, '') USING utf8mb4) AS Fname,
                CONVERT(COALESCE(s.Lname, '') USING utf8mb4) AS Lname,
                CONVERT(COALESCE(p.program_name, sr.program_code) USING utf8mb4) AS program_name,
                COALESCE(sr.year_of_study, NULLIF(s.year, 0), 1) AS Year,
                sr.semester AS semester,
                CONVERT(COALESCE(sr.period_type, 'semester') USING utf8mb4) AS period_type,
                CONVERT(sp.term USING utf8mb4) AS term,
                NULL AS duration,
                CONVERT(COALESCE(NULLIF(sp.mode, ''), NULLIF(s.mode, ''), p.study_mode, 'Not set') USING utf8mb4) AS mode,
                CONVERT(COALESCE(s.sex, '') USING utf8mb4) AS sex,
                COALESCE(sr.registration_date, sr.created_at, s.dte_adm) AS reg_date,
                CONVERT('program' USING utf8mb4) AS reg_type
            FROM semester_registration sr
            LEFT JOIN students s ON s.SID = sr.student_id
            LEFT JOIN student_program sp ON sp.id = (
                SELECT MIN(sp2.id) FROM student_program sp2
                 WHERE sp2.Sid = sr.student_id AND sp2.program_code = sr.program_code)
            LEFT JOIN programs p ON p.program_code = sr.program_code
            WHERE 1=1";

        if ($program_code !== '') {
            $progSql .= " AND sr.program_code = ?";
            $types .= 's'; $params[] = $program_code;
        }
        if ($year !== '') {
            $progSql .= " AND COALESCE(sr.year_of_study, NULLIF(s.year, 0), 1) = ?";
            $types .= 'i'; $params[] = (int)$year;
        }
        // No WHERE on period_type: it is auto-derived from the selected program
        // (programs.period_mode) purely to label the Period control. The
        // program_code filter already scopes rows, so filtering period_type too
        // would wrongly hide registrations if a program's mode changed later.
        if ($semester !== '') {
            $progSql .= " AND sr.semester = ?";
            $types .= 'i'; $params[] = (int)$semester;
        }
        if ($gender !== '' && in_array($gender, ['M','F'], true)) {
            $progSql .= " AND s.sex = ?";
            $types .= 's'; $params[] = $gender;
        }
        if ($academic_year !== '') {
            $progSql .= " AND COALESCE(NULLIF(sr.academic_year, ''), YEAR(COALESCE(sr.registration_date, sr.created_at))) = ?";
            $types .= 'i'; $params[] = (int)$academic_year;
        }
        $queries[] = $progSql;
    }

    // -- Branch B: short-course registrations ---------------------------------
    if ($reg_type === 'all' || $reg_type === 'short_course') {
        $shortSql = "SELECT
                CONVERT(e.student_id USING utf8mb4) AS Sid,
                CONVERT(COALESCE(s.Fname, '') USING utf8mb4) AS Fname,
                CONVERT(COALESCE(s.Lname, '') USING utf8mb4) AS Lname,
                CONVERT(sc.course_name USING utf8mb4) AS program_name,
                NULL AS Year,
                NULL AS semester,
                CONVERT('short course' USING utf8mb4) AS period_type,
                NULL AS term,
                CONVERT(NULLIF(TRIM(CONCAT(COALESCE(sc.duration_value, ''), ' ', COALESCE(sc.duration_unit, ''))), '') USING utf8mb4) AS duration,
                CONVERT(COALESCE(NULLIF(sc.delivery_mode, ''), 'Short Course') USING utf8mb4) AS mode,
                CONVERT(COALESCE(s.sex, '') USING utf8mb4) AS sex,
                COALESCE(e.enrollment_date, e.created_at, sc.start_date) AS reg_date,
                CONVERT('short_course' USING utf8mb4) AS reg_type
            FROM short_course_enrollments e
            JOIN short_courses sc ON sc.id = e.short_course_id
            LEFT JOIN students s ON s.SID = e.student_id
            WHERE 1=1";

        if ($gender !== '' && in_array($gender, ['M','F'], true)) {
            $shortSql .= " AND s.sex = ?";
            $types .= 's'; $params[] = $gender;
        }
        if ($academic_year !== '') {
            $shortSql .= " AND YEAR(COALESCE(e.enrollment_date, e.created_at, sc.start_date)) = ?";
            $types .= 'i'; $params[] = (int)$academic_year;
        }
        $queries[] = $shortSql;
    }

    $sql = implode("\nUNION ALL\n", $queries) . "\nORDER BY reg_type, program_name, Lname, Fname, Sid";

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        $_SESSION['error'] = 'Failed to prepare report query: ' . $db->error;
        $result = false;
    } else {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
    }
        if($result && $result->num_rows > 0):
            $report_data = $result->fetch_all(MYSQLI_ASSOC);

            // Calculate statistics
            $total_students = count($report_data);
            $male_students = count(array_filter($report_data, function($row) { return $row['sex'] === 'M'; }));
            $female_students = count(array_filter($report_data, function($row) { return $row['sex'] === 'F'; }));
            $full_time = count(array_filter($report_data, function($row) {
                return strtolower(str_replace('-', ' ', (string)$row['mode'])) === 'full time';
            }));
            $part_time = count(array_filter($report_data, function($row) {
                return str_contains(strtolower(str_replace('-', ' ', (string)$row['mode'])), 'part time');
            }));
        ?>
            <!-- Statistics Summary -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-primary rounded-circle p-3 me-3">
                                <i class="fas fa-users fa-2x text-white"></i>
                            </div>
                            <div>
                                <h3 class="mb-1"><?php echo number_format($total_students); ?></h3>
                                <p class="text-muted mb-0">Total Students</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-info rounded-circle p-3 me-3">
                                <i class="fas fa-mars fa-2x text-white"></i>
                            </div>
                            <div>
                                <h3 class="mb-1"><?php echo number_format($male_students); ?></h3>
                                <p class="text-muted mb-0">Male Students</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-danger rounded-circle p-3 me-3">
                                <i class="fas fa-venus fa-2x text-white"></i>
                            </div>
                            <div>
                                <h3 class="mb-1"><?php echo number_format($female_students); ?></h3>
                                <p class="text-muted mb-0">Female Students</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-success rounded-circle p-3 me-3">
                                <i class="fas fa-graduation-cap fa-2x text-white"></i>
                            </div>
                            <div>
                                <h3 class="mb-1"><?php echo number_format($full_time); ?></h3>
                                <p class="text-muted mb-0">Full Time Students</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Results Table -->
            <div class="data-table-card mb-4" id="registration-list-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Registration List
                        </h5>
                        <div class="header-actions">
                            <button class="btn btn-sm btn-success" onclick="exportToExcel()">
                                <i class="fas fa-file-excel me-2"></i>Export
                            </button>
                            <button class="btn btn-sm btn-danger" type="button" onclick="printContent('print')">
                                <i class="fas fa-print me-2"></i>Print
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                  <div id="print">
                    <div class="text-center mb-3">
                        <img src="/wucportal/images/itc_logo.png" alt="ITC Logo" style="height:72px;width:auto;max-width:100%">
                        <h4 class="mt-2"><strong>Industrial Training Centre</strong></h4>
                        <h5 class="text-muted">Registration Report</h5>
                    </div>

                    <!-- Summary counts — hidden on screen (the stat cards above show
                         them there) but included in the printed/PDF output. -->
                    <div class="registration-print-summary d-none d-print-block mb-3">
                        <h6 class="text-center fw-bold mb-2">Summary</h6>
                        <table class="table table-bordered table-sm w-auto mx-auto text-center align-middle">
                            <thead>
                                <tr>
                                    <th>Total</th>
                                    <th>Male</th>
                                    <th>Female</th>
                                    <th>Full Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php echo number_format($total_students); ?></td>
                                    <td><?php echo number_format($male_students); ?></td>
                                    <td><?php echo number_format($female_students); ?></td>
                                    <td><?php echo number_format($full_time); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-responsive">
                        <table id="registrationTable" class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Program / Course</th>
                                    <th>Year</th>
                                    <th>Period</th>
                                    <th>Mode</th>
                                    <th>Gender</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($report_data as $row): ?>
                                    <?php
                                        $isShort = ($row['reg_type'] ?? 'program') === 'short_course';

                                        // Period: short courses show duration; programmes show the
                                        // registered semester, else the term, else N/A.
                                        if ($isShort) {
                                            $period = trim((string)($row['duration'] ?? ''));
                                            $period = $period !== '' ? ucfirst($period) : 'N/A';
                                        } elseif (($row['semester'] ?? '') !== '' && $row['semester'] !== null) {
                                            $period = ucfirst((string)($row['period_type'] ?: 'semester')) . ' ' . $row['semester'];
                                        } elseif (($row['term'] ?? '') !== '' && $row['term'] !== null) {
                                            $period = 'Term ' . $row['term'];
                                        } else {
                                            $period = 'N/A';
                                        }

                                        // Year of study only applies to programmes.
                                        $yearText = ($isShort || ($row['Year'] ?? '') === '' || $row['Year'] === null)
                                            ? 'N/A' : 'Year ' . $row['Year'];

                                        $modeKey = strtolower(str_replace('-', ' ', (string)$row['mode']));
                                        $modeClass = $modeKey === 'full time' ? 'success' : 'warning';
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['Sid']); ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-circle me-2 bg-primary text-white">
                                                    <?php echo strtoupper(substr((string)($row['Fname'] !== '' ? $row['Fname'] : $row['Sid']), 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <?php echo htmlspecialchars(trim($row['Fname'] . ' ' . $row['Lname']) ?: 'Name not captured'); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $isShort ? 'warning text-dark' : 'primary'; ?>">
                                                <?php echo $isShort ? 'Short Course' : 'Programme'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['program_name']); ?></td>
                                        <td><?php echo htmlspecialchars($yearText); ?></td>
                                        <td><?php echo htmlspecialchars($period); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $modeClass; ?>">
                                                <?php echo htmlspecialchars($row['mode']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $row['sex'] == 'M' ? 'info' : ($row['sex'] == 'F' ? 'danger' : 'secondary'); ?>">
                                                <?php echo $row['sex'] == 'M' ? 'Male' : ($row['sex'] == 'F' ? 'Female' : 'N/A'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                  </div><!-- #print -->
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info" id="registration-list-card">
                <i class="fas fa-info-circle me-2"></i>No records found for the selected criteria.
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
$(document).ready(function(){
    // Registration-type filter: programme-only fields (program, year, semester)
    // don't apply to short courses, so disable + grey them out in that mode.
    var $regType = $('#reg_type');
    var $program = $('#program_code');
    var $period = $('#semester');
    var $periodType = $('#period_type');
    var $periodLabel = $('#periodLabel');
    var $progFields = $('#program_code, #year, #semester');

    // Period Type is auto-detected from the selected program's period_mode; the
    // operator never picks it. Relabel the Period control + its number options
    // (Semester N / Term N / Period N) and stash the type in the hidden input so
    // the server and the after-submit re-render agree.
    function syncPeriodFromProgram() {
        var opt = $program.length ? $program[0].options[$program[0].selectedIndex] : null;
        var mode = (opt && opt.getAttribute('data-period-mode')) || '';
        var label = mode === 'term' ? 'Term' : (mode === 'semester' ? 'Semester' : 'Period');
        var maxNum = mode === 'semester' ? 2 : 3; // semesters run 1–2, terms 1–3

        $periodType.val(mode);
        $periodLabel.text(label);

        var current = $period.val();
        var opts = ['<option value="">All ' + label + 's</option>'];
        for (var i = 1; i <= maxNum; i++) {
            var sel = String(current) === String(i) ? ' selected' : '';
            opts.push('<option value="' + i + '"' + sel + '>' + label + ' ' + i + '</option>');
        }
        $period.html(opts.join(''));
    }
    if ($program.length) {
        syncPeriodFromProgram();
        $program.on('change', syncPeriodFromProgram);
    }

    function syncRegType() {
        var shortOnly = $regType.val() === 'short_course';
        $progFields.prop('disabled', shortOnly);
        if (shortOnly) { $progFields.val(''); $periodType.val(''); }
        $progFields.each(function () {
            $(this).closest('.form-group').toggleClass('opacity-50', shortOnly);
        });
        if (!shortOnly) { syncPeriodFromProgram(); }
    }
    if ($regType.length) {
        syncRegType();
        $regType.on('change', syncRegType);
    }

    // Initialize DataTable
    if ($.fn && $.fn.DataTable && document.getElementById('registrationTable')) {
      $('#registrationTable').DataTable({
        pageLength: 10,
        responsive: true,
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
        language: {
            search: "",
            searchPlaceholder: "Search records...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ records",
            paginate: {
                first: '<i class="fas fa-angle-double-left"></i>',
                last: '<i class="fas fa-angle-double-right"></i>',
                next: '<i class="fas fa-angle-right"></i>',
                previous: '<i class="fas fa-angle-left"></i>'
            }
        }
      });
    }

    <?php if(isset($_POST['generate_report'])): ?>
    const generatedTarget = document.getElementById('registration-list-card');
    if (generatedTarget) {
        const revealGeneratedReport = function () {
            generatedTarget.classList.add('wuc-in');
            generatedTarget.querySelectorAll('.wuc-reveal').forEach(function (el) {
                el.classList.add('wuc-in');
            });
        };

        revealGeneratedReport();
        window.setTimeout(function () {
            revealGeneratedReport();
            if (!window.location.hash) {
                generatedTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 120);
    }
    <?php endif; ?>
});

function exportToExcel() {
    let table = document.querySelector('#registrationTable');
    if (!table) {
        return;
    }
    
    var isDt = window.jQuery && jQuery.fn && jQuery.fn.DataTable && jQuery.fn.DataTable.isDataTable(table);
    var dt, oldLen;
    if (isDt) {
        dt = jQuery(table).DataTable();
        oldLen = dt.page.len();
        dt.page.len(-1).draw(false);
    }
    
    let html = table.outerHTML;
    
    if (isDt) {
        dt.page.len(oldLen).draw(false);
    }
    
    let url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    let downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    downloadLink.href = url;
    downloadLink.download = 'registration_report.xls';
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

function printContent(printId) {
    var source = document.getElementById(printId);
    if (!source) return;

    // DataTables keeps only the current page's rows in the DOM. Expand to ALL
    // rows before capturing innerHTML so the print/PDF includes every record,
    // then restore the on-screen pagination afterwards.
    var dt = null, prevLen = null;
    if (window.jQuery && jQuery.fn && jQuery.fn.DataTable) {
        var tables = source.querySelectorAll('table');
        for (var i = 0; i < tables.length; i++) {
            if (jQuery.fn.DataTable.isDataTable(tables[i])) {
                dt = jQuery(tables[i]).DataTable();
                prevLen = dt.page.len();
                dt.page.len(-1).draw(false);
                break;
            }
        }
    }

    var printHtml = source.innerHTML; // captured AFTER expanding all rows

    if (dt) {
        dt.page.len(prevLen).draw(false);
    }

    if (window.wucPrintElement) {
        var temp = document.createElement('div');
        temp.innerHTML = printHtml;
        window.wucPrintElement(temp, document.title);
        return;
    }

    var printWindow = window.open('', '_blank', 'width=900,height=700');
    if (!printWindow) {
        window.print();
        return;
    }
    var styles = Array.prototype.map.call(
        document.querySelectorAll('link[rel="stylesheet"], style'),
        function (node) { return node.outerHTML; }
    ).join('\n');
    var printStyle =
        '<style>' +
        '@page{size:A4;margin:12mm}' +
        'html,body{background:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
        '@media print{' +
        'button,.d-print-none,.no-print{display:none!important}' +
        '.dataTables_length,.dataTables_filter,.dataTables_info,.dataTables_paginate,.dataTables_processing{display:none!important}' +
        '}' +
        '</style>';
    printWindow.document.open();
    printWindow.document.write('<!DOCTYPE html><html><head><title>' + document.title + '</title>' + styles + printStyle + '</head><body>' + printHtml + '<script>window.onload=function(){window.print();window.onafterprint=function(){window.close();};};<\/script></body></html>');
    printWindow.document.close();
}
</script>

<?php require_once "includes/footer.php"; ?>
