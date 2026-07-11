<?php
/**
 * hod/students.php â€” Department Students List
 * â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
 * Fixes:
 *  - error_reporting(0) moved BEFORE nav.php
 *  - Dept resolution supports departments.hod_id and legacy staff department columns
 *  - Broken staff.deptId assumptions removed from department filtering
 *  - SELECT * replaced with explicit columns to avoid ambiguous field collisions
 *  - program_levels joined properly (LEFT JOIN, avoids INNER JOIN failure)
 *  - Intake/mode sourced from student_program (correct table)
 *  - Filter dropdowns populated from PHP, not broken DataTables column scan
 *  - Export replaced with real CSV download link
 *  - Swal dependency removed (used Bootstrap confirm or plain confirm)
 *  - View-details modal loads real data from DOM (no broken AJAX placeholder)
 *  - DataTables loaded via CDN inside the page to guarantee availability
 */
error_reporting(0);
$page_title = 'Department Students';
require "includes/nav.php";
require_once __DIR__ . '/includes/hod_schema_helpers.php';

/* â”€â”€ 1. Resolve HOS department â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
$hod_staff_id = $_SESSION['staff_id'] ?? '';
$dept_id      = '';
$dept_name    = '';
$nonAcademicHosSection = false;

$detectColumn = function(mysqli $db, string $table, array $candidates): ?string {
    foreach ($candidates as $col) {
        if ($res = @$db->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $db->real_escape_string($col) . "'")) {
            if ($res->num_rows > 0) {
                $res->free();
                return $col;
            }
            $res->free();
        }
    }
    return null;
};

$tableExists = function(mysqli $db, string $table): bool {
    if ($res = @$db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'")) {
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
    return false;
};

$staffDeptCol = $detectColumn($db, 'staff', ['department_id', 'DeptID', 'deptId']);
$deptIdCol = $detectColumn($db, 'departments', ['department_id', 'id', 'DeptID']);
$deptNameCol = $detectColumn($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']);
$deptHodCol = $detectColumn($db, 'departments', ['hod_id', 'HODID', 'hodId']);
$programDeptCol = $detectColumn($db, 'programs', ['department_id', 'DeptID', 'deptId', 'department_code']);

if ($hod_staff_id !== '') {
    if ($deptHodCol && $deptIdCol) {
        $deptNameSelect = $deptNameCol ? "`{$deptNameCol}` AS department_name" : "'' AS department_name";
        $stmt = @$db->prepare("
            SELECT `{$deptIdCol}` AS dept_id, {$deptNameSelect}
            FROM departments
            WHERE CAST(`{$deptHodCol}` AS CHAR) = CAST(? AS CHAR)
               OR CAST(`{$deptHodCol}` AS CHAR) = (
                   SELECT CAST(id AS CHAR) FROM staff WHERE staff_id = ? LIMIT 1
               )
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('ss', $hod_staff_id, $hod_staff_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_object()) {
                $dept_id   = (string)($row->dept_id ?? '');
                $dept_name = (string)($row->department_name ?? $dept_id);
            }
            $stmt->close();
        }
    }

    if ($dept_id === '' && $staffDeptCol && $deptIdCol) {
        $deptNameSelect = $deptNameCol ? "d.`{$deptNameCol}` AS department_name" : "'' AS department_name";
        $stmt = @$db->prepare("
            SELECT s.`{$staffDeptCol}` AS dept_id, {$deptNameSelect}
            FROM staff s
            LEFT JOIN departments d ON CAST(s.`{$staffDeptCol}` AS CHAR) = CAST(d.`{$deptIdCol}` AS CHAR)
            WHERE s.staff_id = ? LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('s', $hod_staff_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_object()) {
                $dept_id   = (string)($row->dept_id ?? '');
                $dept_name = (string)($row->department_name ?? $dept_id);
            }
            $stmt->close();
        }
    }
}

if ($dept_id !== '') {
    $_SESSION['dept_id'] = $dept_id;
}

$deptContext = hod_resolve_department($db, (string)$hod_staff_id);
$sectionType = (string)($deptContext['section_type'] ?? '');
$nonAcademicHosSection = ($sectionType !== '' && $sectionType !== 'academic');
if ((string)$deptContext['id'] !== '') {
    $dept_id = (string)$deptContext['id'];
    $dept_name = (string)$deptContext['name'];
    $_SESSION['dept_id'] = $dept_id;
} elseif ($nonAcademicHosSection) {
    $dept_name = (string)$deptContext['name'];
}

if ($dept_name === '' && $dept_id !== '' && $deptIdCol && $deptNameCol) {
    $stmt = @$db->prepare("SELECT `{$deptNameCol}` AS department_name FROM departments WHERE CAST(`{$deptIdCol}` AS CHAR) = CAST(? AS CHAR) LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $dept_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_object()) {
            $dept_name = (string)($row->department_name ?? $dept_id);
        }
        $stmt->close();
    }
}

/* â”€â”€ 2. Get program codes for this section (ALL departments in it) â”€â”€â”€â”€â”€â”€â”€â”€ */
$deptProgramCodes = [];

// Path A: programs owned by every department in the HOS's section — scoping to
// $dept_id alone would silently drop all departments after the first.
$deptProgramCodes = hod_section_program_codes($db, $deptContext);

// Path B: include programs attached to courses personally assigned to this HOS.
if ($hod_staff_id !== '' && $tableExists($db, 'course_lecturer') && $detectColumn($db, 'course_lecturer', ['program_code'])) {
    $stmt = @$db->prepare("
        SELECT DISTINCT program_code
        FROM course_lecturer
        WHERE staff_id = ?
          AND program_code IS NOT NULL
          AND program_code <> ''
    ");
    if ($stmt) {
        $stmt->bind_param('s', $hod_staff_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $r = $res->fetch_assoc()) {
            $deptProgramCodes[] = $r['program_code'];
        }
        $stmt->close();
    }
}

$deptProgramCodes = array_values(array_unique(array_filter($deptProgramCodes)));

/* â”€â”€ 3. CSV Export â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
if (isset($_GET['export_csv']) && $_GET['export_csv'] === '1') {
    while (ob_get_level()) ob_end_clean();
    $filename = 'students_dept_' . preg_replace('/[^a-z0-9_-]/i', '_', $dept_id) . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Student ID', 'First Name', 'Last Name', 'Gender', 'Program', 'Intake', 'Mode', 'Start Year', 'Status', 'Email', 'Mobile']);

    // Export ONLY this section's students. Never fall back to the whole
    // college — an empty scope produces an empty (header-only) CSV.
    if (!empty($deptProgramCodes)) {
        $placeholders = implode(',', array_fill(0, count($deptProgramCodes), '?'));
        $sql = "
            SELECT st.SID, st.Fname, st.Lname, st.sex, st.email, st.mobile,
                   p.program_name, sp.intake, sp.mode, sp.startYear, sp.status AS enroll_status
            FROM students st
            INNER JOIN student_program sp ON sp.Sid = st.SID
            INNER JOIN programs p ON sp.program_code = p.program_code
            WHERE sp.program_code IN ({$placeholders})
            ORDER BY st.Lname, st.Fname
        ";
        $stmt = @$db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($deptProgramCodes)), ...$deptProgramCodes);
            $stmt->execute();
            $res = $stmt->get_result();
            $n = 1;
            while ($res && ($r = $res->fetch_object())) {
                fputcsv($out, [
                    $n++, $r->SID, $r->Fname, $r->Lname, $r->sex,
                    $r->program_name, $r->intake, $r->mode, $r->startYear,
                    $r->enroll_status, $r->email, $r->mobile
                ]);
            }
            $stmt->close();
        }
    }
    fclose($out);
    exit;
}

/* â”€â”€ 4. Fetch students â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
$records    = [];
$programs   = [];  // for filter dropdown
$intakes    = [];
$modes      = [];

// Check program_levels table exists
$plExists = false;
$chk = @$db->query("SHOW TABLES LIKE 'program_levels'");
if ($chk && $chk->num_rows > 0) $plExists = true;

$levelJoin   = $plExists ? "LEFT JOIN program_levels pl ON sp.program_code = pl.program_code" : "";
$levelSelect = $plExists ? "COALESCE(pl.level, 'â€”') AS level" : "'â€”' AS level";

// Build student query â€” use ALL students if dept mapping is broken, otherwise filter by program
if (!empty($deptProgramCodes)) {
    $placeholders = implode(',', array_fill(0, count($deptProgramCodes), '?'));
    $types = str_repeat('s', count($deptProgramCodes));
    $sql = "
        SELECT st.SID, st.Fname, st.Lname, st.sex, st.email, st.mobile,
               st.status AS student_status,
               sp.program_code, sp.intake, sp.mode, sp.startYear, sp.status AS enroll_status,
               p.program_name,
               {$levelSelect}
        FROM students st
        INNER JOIN student_program sp ON sp.Sid = st.SID
        INNER JOIN programs p ON sp.program_code = p.program_code
        {$levelJoin}
        WHERE sp.program_code IN ({$placeholders})
          AND sp.status = 'active'
        ORDER BY st.Lname ASC, st.Fname ASC
    ";
    $stmt = @$db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$deptProgramCodes);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_object()) $records[] = $r;
        $stmt->close();
    }
}
// No section→programme mapping resolved: show a clean empty state rather than
// leaking every student in the college.

// Build filter options from actual data
foreach ($records as $r) {
    if ($r->program_name && !in_array($r->program_name, $programs)) $programs[] = $r->program_name;
    if ($r->intake      && !in_array($r->intake,       $intakes))  $intakes[]  = $r->intake;
    if ($r->mode        && !in_array($r->mode,         $modes))    $modes[]    = $r->mode;
}
sort($programs); sort($intakes); sort($modes);

$totalStudents = count($records);
$totalActiveStudents = 0;
foreach ($records as $r) {
    if (strtolower((string)($r->student_status ?? '')) === 'active') $totalActiveStudents++;
}
$totalPrograms = count($programs);
$totalIntakes = count($intakes);
?>
<div class="container-fluid px-4 portal-dashboard hod-page student-page">

    <!-- Page Header -->
    <div class="page-header mb-3 mt-2 d-print-none">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-user-graduate me-2 text-primary"></i>Department Students</h5>
                <p class="page-subtitle mb-0">
                    <i class="fas fa-building me-1"></i>
                    <?php echo htmlspecialchars($dept_name ?: ($dept_id ?: 'All Departments')); ?>
                    <?php if ($dept_id): ?>
                        <span class="ms-2 badge bg-light text-dark"><?php echo htmlspecialchars($dept_id); ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="?export_csv=1" class="btn btn-success btn-sm">
                    <i class="fas fa-download me-1"></i>Export CSV
                </a>
                <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                    <i class="fas fa-print me-1"></i>Print
                </button>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3"><i class="fas fa-users text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $totalStudents; ?></h3>
                        <p class="text-muted mb-0">Total Students</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3"><i class="fas fa-user-check text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $totalActiveStudents; ?></h3>
                        <p class="text-muted mb-0">Active Students</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-program me-3"><i class="fas fa-graduation-cap text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $totalPrograms; ?></h3>
                        <p class="text-muted mb-0">Programs</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-intake me-3"><i class="fas fa-calendar-alt text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $totalIntakes; ?></h3>
                        <p class="text-muted mb-0">Intakes</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Warning -->
    <?php if (!$dept_id): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2 mb-4 d-print-none">
        <i class="fas fa-exclamation-triangle mt-1"></i>
        <div>
            <?php if ($nonAcademicHosSection): ?>
                <strong><?php echo htmlspecialchars($dept_name ?: 'Non-academic section'); ?>.</strong><br>
                <small>This HOS section is not linked to academic student records, so no department students are shown.</small>
            <?php else: ?>
                <strong>No department assigned.</strong><br>
                <small>Your staff account (<code><?php echo htmlspecialchars($hod_staff_id); ?></code>) has no department linked.
                Showing all active students as a fallback. Ask an administrator to set your department ID.</small>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="data-table-card mb-4 d-print-none">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Program</label>
                    <select class="form-select form-select-sm" id="filterProgram">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted">Intake</label>
                    <select class="form-select form-select-sm" id="filterIntake">
                        <option value="">All Intakes</option>
                        <?php foreach ($intakes as $i): ?>
                            <option value="<?php echo htmlspecialchars($i); ?>"><?php echo htmlspecialchars($i); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted">Mode</label>
                    <select class="form-select form-select-sm" id="filterMode">
                        <option value="">All Modes</option>
                        <?php foreach ($modes as $m): ?>
                            <option value="<?php echo htmlspecialchars($m); ?>"><?php echo htmlspecialchars($m); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted">Gender</label>
                    <select class="form-select form-select-sm" id="filterGender">
                        <option value="">All</option>
                        <option value="M">Male</option>
                        <option value="F">Female</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-sm btn-outline-secondary w-100" id="clearFilters">
                        <i class="fas fa-times me-1"></i>Clear Filters
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Students Table -->
    <div class="data-table-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-users me-2"></i>Student List <span class="badge bg-secondary ms-2" id="rowCount"><?php echo $totalStudents; ?></span></h5>
            <input type="text" id="globalSearch" class="form-control form-control-sm d-print-none header-search-sm"
                   placeholder="Search students...">
        </div>

        <?php if (empty($records)): ?>
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-user-slash fa-3x mb-3 d-block text-muted empty-state-icon"></i>
                <h5>No Students Found</h5>
                <p class="small">
                    <?php if ($dept_id): ?>
                        No active enrolled students found for department <strong><?php echo htmlspecialchars($dept_id); ?></strong>.
                    <?php else: ?>
                        No active student records in the database.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="studentsTable">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Full Name</th>
                        <th class="text-center">Gender</th>
                        <th>Program</th>
                        <th>Intake</th>
                        <th>Mode</th>
                        <th class="text-center">Start Yr</th>
                        <th>Status</th>
                        <th class="text-center d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $n = 1; foreach ($records as $r):
                        $fullName   = trim(htmlspecialchars($r->Fname . ' ' . $r->Lname));
                        $statusKey  = strtolower((string)($r->enroll_status ?? 'inactive'));
                        $statusDot  = $statusKey === 'active' ? 'active' : 'inactive';
                        $genderIcon = strtoupper($r->sex ?? '') === 'M'
                                      ? '<i class="fas fa-mars gender-m" title="Male"></i>'
                                      : (strtoupper($r->sex ?? '') === 'F'
                                         ? '<i class="fas fa-venus gender-f" title="Female"></i>'
                                         : '-');
                    ?>
                    <tr data-program="<?php echo htmlspecialchars($r->program_name ?? ''); ?>"
                        data-intake="<?php echo htmlspecialchars($r->intake ?? ''); ?>"
                        data-mode="<?php echo htmlspecialchars($r->mode ?? ''); ?>"
                        data-gender="<?php echo htmlspecialchars(strtoupper($r->sex ?? '')); ?>"
                        data-sid="<?php echo htmlspecialchars($r->SID); ?>"
                        data-email="<?php echo htmlspecialchars($r->email ?? ''); ?>"
                        data-mobile="<?php echo htmlspecialchars($r->mobile ?? ''); ?>">
                        <td class="text-muted"><?php echo $n++; ?></td>
                        <td class="fw-bold font-monospace small"><?php echo htmlspecialchars($r->SID); ?></td>
                        <td><?php echo $fullName; ?></td>
                        <td class="text-center"><?php echo $genderIcon; ?></td>
                        <td><?php echo htmlspecialchars($r->program_name ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($r->intake ?? '-'); ?></td>
                        <td><span class="mode-tag"><?php echo htmlspecialchars($r->mode ?? '-'); ?></span></td>
                        <td class="text-center"><?php echo $r->startYear ? (int)$r->startYear : '-'; ?></td>
                        <td>
                            <span class="status-dot <?php echo $statusDot; ?>"></span>
                            <span class="status-text"><?php echo htmlspecialchars(ucfirst($r->enroll_status ?? 'Unknown')); ?></span>
                        </td>
                        <td class="text-center text-nowrap d-print-none">
                            <button class="btn btn-sm btn-outline-primary view-detail-btn"
                                    data-sid="<?php echo htmlspecialchars($r->SID); ?>"
                                    data-name="<?php echo $fullName; ?>"
                                    data-program="<?php echo htmlspecialchars($r->program_name ?? ''); ?>"
                                    data-intake="<?php echo htmlspecialchars($r->intake ?? ''); ?>"
                                    data-mode="<?php echo htmlspecialchars($r->mode ?? ''); ?>"
                                    data-email="<?php echo htmlspecialchars($r->email ?? ''); ?>"
                                    data-mobile="<?php echo htmlspecialchars($r->mobile ?? ''); ?>"
                                    data-status="<?php echo htmlspecialchars(ucfirst($r->enroll_status ?? '')); ?>"
                                    data-gender="<?php echo htmlspecialchars($r->sex ?? ''); ?>"
                                    title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <a href="adminSlip.php?view=<?php echo urlencode($r->SID); ?>"
                               class="btn btn-sm btn-outline-success" title="Admin Slip">
                                <i class="fas fa-print"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination & info bar -->
        <div class="table-wrap-footer d-flex justify-content-between align-items-center px-3 py-2 d-print-none">
            <small class="text-muted" id="tableInfo">Showing <?php echo $totalStudents; ?> students</small>
            <div class="d-flex gap-2 align-items-center">
                <label class="small text-muted mb-0">Show:
                    <select id="perPage" class="form-select form-select-sm d-inline-block ms-1 select-auto-width">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="0">All</option>
                    </select>
                </label>
                <div id="pagination" class="d-flex gap-1"></div>
            </div>
        </div>

        <?php endif; ?>
    </div>

</div><!-- /container-fluid -->

<!-- Student Details Modal -->
<div class="modal fade" id="studentModal" tabindex="-1" aria-labelledby="studentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header admin-modal">
                <h5 class="modal-title" id="studentModalLabel">
                    <i class="fas fa-user-graduate me-2"></i>Student Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="studentModalBody">
                <!-- filled by JS -->
            </div>
            <div class="modal-footer">
                <a href="#" id="modalAdminSlipLink" class="btn btn-success rounded-pill px-4">
                    <i class="fas fa-print me-1"></i>Print Admin Slip
                </a>
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    'use strict';

    /* â”€â”€ References â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    var tbody     = document.querySelector('#studentsTable tbody');
    var allRows   = tbody ? Array.from(tbody.querySelectorAll('tr')) : [];
    var rowCount  = document.getElementById('rowCount');
    var tableInfo = document.getElementById('tableInfo');
    var perPageEl = document.getElementById('perPage');
    var paginEl   = document.getElementById('pagination');

    /* â”€â”€ Filter state â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    var filters = { program:'', intake:'', mode:'', gender:'', search:'' };
    var perPage = 10;
    var currentPage = 1;

    function applyFilters() {
        var visible = allRows.filter(function(row) {
            if (filters.program && row.dataset.program !== filters.program) return false;
            if (filters.intake  && row.dataset.intake  !== filters.intake)  return false;
            if (filters.mode    && row.dataset.mode    !== filters.mode)    return false;
            if (filters.gender  && row.dataset.gender  !== filters.gender)  return false;
            if (filters.search) {
                var txt = row.textContent.toLowerCase();
                if (!txt.includes(filters.search)) return false;
            }
            return true;
        });

        // Hide all
        allRows.forEach(function(r){ r.style.display='none'; r.style.removeProperty('display'); r.hidden = true; });

        // Paginate visible
        var total = visible.length;
        var pages = perPage === 0 ? 1 : Math.max(1, Math.ceil(total / perPage));
        if (currentPage > pages) currentPage = 1;

        var start = perPage === 0 ? 0 : (currentPage - 1) * perPage;
        var end   = perPage === 0 ? total : Math.min(start + perPage, total);
        var pageRows = visible.slice(start, end);

        visible.forEach(function(r){ r.hidden = true; });
        pageRows.forEach(function(r){ r.hidden = false; });

        // Update counter
        if (rowCount) rowCount.textContent = total;
        if (tableInfo) {
            var s = total === 0 ? 'No students found' : 'Showing ' + (start+1) + '-' + end + ' of ' + total + ' students';
            tableInfo.textContent = s;
        }

        // Re-number visible rows
        pageRows.forEach(function(r, i){
            var firstTd = r.querySelector('td');
            if (firstTd) firstTd.textContent = start + i + 1;
        });

        // Build pagination
        buildPagination(pages);
    }

    function buildPagination(pages) {
        if (!paginEl) return;
        paginEl.innerHTML = '';
        if (pages <= 1) return;

        var prev = document.createElement('button');
        prev.className = 'btn btn-sm ' + (currentPage === 1 ? 'btn-outline-secondary disabled' : 'btn-outline-primary');
        prev.innerHTML = '<i class="fas fa-chevron-left"></i>';
        prev.disabled  = currentPage === 1;
        prev.onclick   = function(){ if (currentPage > 1) { currentPage--; applyFilters(); } };
        paginEl.appendChild(prev);

        var maxPgs = Math.min(pages, 5);
        var start = Math.max(1, currentPage - 2);
        var end   = Math.min(pages, start + maxPgs - 1);
        for (var i = start; i <= end; i++) {
            (function(pg) {
                var btn = document.createElement('button');
                btn.className = 'btn btn-sm ' + (pg === currentPage ? 'btn-primary' : 'btn-outline-primary');
                btn.textContent = pg;
                btn.onclick = function(){ currentPage = pg; applyFilters(); };
                paginEl.appendChild(btn);
            })(i);
        }

        var next = document.createElement('button');
        next.className = 'btn btn-sm ' + (currentPage === pages ? 'btn-outline-secondary disabled' : 'btn-outline-primary');
        next.innerHTML = '<i class="fas fa-chevron-right"></i>';
        next.disabled  = currentPage === pages;
        next.onclick   = function(){ if (currentPage < pages) { currentPage++; applyFilters(); } };
        paginEl.appendChild(next);
    }

    /* â”€â”€ Filter listeners â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function bindFilter(id, key) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', function(){
            filters[key] = this.value;
            currentPage = 1;
            applyFilters();
        });
    }
    bindFilter('filterProgram', 'program');
    bindFilter('filterIntake',  'intake');
    bindFilter('filterMode',    'mode');
    bindFilter('filterGender',  'gender');

    var searchEl = document.getElementById('globalSearch');
    if (searchEl) {
        searchEl.addEventListener('input', function(){
            filters.search = this.value.toLowerCase().trim();
            currentPage = 1;
            applyFilters();
        });
    }

    if (perPageEl) {
        perPageEl.addEventListener('change', function(){
            perPage = parseInt(this.value, 10);
            currentPage = 1;
            applyFilters();
        });
    }

    var clearBtn = document.getElementById('clearFilters');
    if (clearBtn) {
        clearBtn.addEventListener('click', function(){
            filters = { program:'', intake:'', mode:'', gender:'', search:'' };
            ['filterProgram','filterIntake','filterMode','filterGender'].forEach(function(id){
                var el = document.getElementById(id); if (el) el.value = '';
            });
            if (searchEl) searchEl.value = '';
            currentPage = 1;
            applyFilters();
        });
    }

    /* â”€â”€ View Detail Modal (data from row attributes â€” no AJAX needed) â”€ */
    document.querySelectorAll('.view-detail-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            var gIcon = d.gender === 'M'
                ? '<i class="fas fa-mars text-primary"></i> Male'
                : (d.gender === 'F' ? '<i class="fas fa-venus text-danger"></i> Female' : d.gender || 'â€”');

            var html = '<div class="row g-3">'
                + '<div class="col-md-3 text-center">'
                +   '<div class="student-modal-avatar">'
                +     '<i class="fas fa-user-graduate text-white fa-2x"></i></div><br>'
                +   '<span class="badge bg-secondary small">' + d.sid + '</span>'
                + '</div>'
                + '<div class="col-md-9">'
                +   '<h5 class="fw-bold mb-1">' + d.name + '</h5>'
                +   '<p class="text-muted small mb-3">' + gIcon + '</p>'
                +   '<div class="row g-2 small">'
                +     '<div class="col-6"><strong>Program:</strong><br>' + (d.program || 'â€”') + '</div>'
                +     '<div class="col-6"><strong>Intake:</strong><br>' + (d.intake || 'â€”') + '</div>'
                +     '<div class="col-6"><strong>Study Mode:</strong><br>' + (d.mode || 'â€”') + '</div>'
                +     '<div class="col-6"><strong>Status:</strong><br>' + (d.status || 'â€”') + '</div>'
                +     '<div class="col-6"><strong>Email:</strong><br><a href="mailto:' + d.email + '">' + (d.email || 'â€”') + '</a></div>'
                +     '<div class="col-6"><strong>Mobile:</strong><br>' + (d.mobile || 'â€”') + '</div>'
                +   '</div>'
                + '</div>'
                + '</div>';

            document.getElementById('studentModalBody').innerHTML = html;
            document.getElementById('modalAdminSlipLink').href = 'adminSlip.php?view=' + encodeURIComponent(d.sid);
            var modal = new bootstrap.Modal(document.getElementById('studentModal'));
            modal.show();
        });
    });

    /* â”€â”€ Initial render â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    applyFilters();

})();
</script>

<?php require "includes/footer.php"; ?>



