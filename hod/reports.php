<?php
error_reporting(0);
$page_title = 'Department Reports';
require "includes/nav.php";
require_once __DIR__ . '/includes/hod_schema_helpers.php';
require_once __DIR__ . '/../includes/academic_risk_engine.php';
require_once __DIR__ . '/../includes/report_print.php';

$tableExists = function(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $res = @$db->query("SHOW TABLES LIKE '{$safe}'");
    if (!$res) {
        return false;
    }
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
};

$detectColumn = function(mysqli $db, string $table, array $candidates): ?string {
    foreach ($candidates as $col) {
        $safeTable = $db->real_escape_string($table);
        $safeCol = $db->real_escape_string($col);
        $res = @$db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
        if ($res && $res->num_rows > 0) {
            $res->free();
            return $col;
        }
        if ($res) {
            $res->free();
        }
    }
    return null;
};

$runPreparedRows = function(string $sql, string $types = '', array $params = []) use ($db): array {
    $out = [];
    $stmt = @$db->prepare($sql);
    if (!$stmt) {
        return $out;
    }
    if ($types !== '') {
        $bind = [];
        $bind[] = $types;
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        @call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $out[] = $row;
        }
    }
    $stmt->close();
    return $out;
};

$runPreparedScalar = function(string $sql, string $types = '', array $params = [], $default = 0) use ($runPreparedRows) {
    $rows = $runPreparedRows($sql, $types, $params);
    if (empty($rows)) {
        return $default;
    }
    $first = $rows[0];
    if (empty($first)) {
        return $default;
    }
    $value = reset($first);
    return $value !== null ? $value : $default;
};

$hodStaffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
if ($hodStaffId !== '' && !isset($_SESSION['staff_id'])) {
    $_SESSION['staff_id'] = $hodStaffId;
}

$notes = [];
$staffDeptCol = $detectColumn($db, 'staff', ['deptId', 'DeptID', 'department_id']);
$deptIdRaw = '';
$deptName = '';
$deptCandidates = [];
$usingFallbackAll = false;

$deptContext = hod_resolve_department($db, $hodStaffId);
$deptIdRaw = (string)$deptContext['id'];
$deptName = (string)$deptContext['name'];
$nonAcademicHosSection = !empty($deptContext['section_type']) && (string)$deptContext['section_type'] !== 'academic';

if ($deptIdRaw === '' && $hodStaffId !== '' && $staffDeptCol) {
    $rows = $runPreparedRows("SELECT `{$staffDeptCol}` AS dept_value FROM staff WHERE staff_id = ? LIMIT 1", 's', [$hodStaffId]);
    if (!empty($rows)) {
        $deptIdRaw = trim((string)($rows[0]['dept_value'] ?? ''));
    }
}

// A section spans several departments (departments.section_id); scope the
// report to ALL of them, not just the first one, mirroring hod/index.php.
foreach ((array)($deptContext['candidates'] ?? []) as $sectionDeptId) {
    $sectionDeptId = trim((string)$sectionDeptId);
    if ($sectionDeptId !== '') {
        $deptCandidates[] = $sectionDeptId;
    }
}

if ($deptIdRaw !== '') {
    $deptCandidates[] = $deptIdRaw;
}

if ($deptIdRaw !== '' && $tableExists($db, 'departments')) {
    $deptNameCol = $detectColumn($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']);
    $deptCodeCol = $detectColumn($db, 'departments', ['deptId', 'department_code', 'department_id']);
    $deptNumCol = $detectColumn($db, 'departments', ['id', 'DeptID', 'department_id']);

    $whereParts = [];
    $types = '';
    $params = [];
    if ($deptCodeCol) {
        $whereParts[] = "`{$deptCodeCol}` = ?";
        $types .= 's';
        $params[] = $deptIdRaw;
    }
    if ($deptNumCol && ctype_digit($deptIdRaw)) {
        $whereParts[] = "`{$deptNumCol}` = ?";
        $types .= 'i';
        $params[] = (int)$deptIdRaw;
    }
    if (!empty($whereParts)) {
        $selectCols = [];
        $selectCols[] = $deptNameCol ? "`{$deptNameCol}` AS dept_name" : "'' AS dept_name";
        $selectCols[] = $deptCodeCol ? "`{$deptCodeCol}` AS dept_code" : "'' AS dept_code";
        $selectCols[] = $deptNumCol ? "`{$deptNumCol}` AS dept_num" : "NULL AS dept_num";
        $sql = "SELECT " . implode(', ', $selectCols) . " FROM departments WHERE " . implode(' OR ', $whereParts) . " LIMIT 1";
        $rows = $runPreparedRows($sql, $types, $params);
        if (!empty($rows)) {
            $deptName = (string)($rows[0]['dept_name'] ?? '');
            $deptCode = trim((string)($rows[0]['dept_code'] ?? ''));
            $deptNum = trim((string)($rows[0]['dept_num'] ?? ''));
            if ($deptCode !== '') {
                $deptCandidates[] = $deptCode;
            }
            if ($deptNum !== '') {
                $deptCandidates[] = $deptNum;
            }
        }
    }
}

$deptCandidates = array_values(array_unique(array_filter($deptCandidates, static function($v): bool {
    return $v !== null && $v !== '';
})));

// Department filter: a section spans several departments, so let the HOS
// narrow the report to one of them. Only section departments are accepted.
$departmentOptions = [];
if (!empty($deptCandidates) && $tableExists($db, 'departments')) {
    $placeholders = implode(',', array_fill(0, count($deptCandidates), '?'));
    $deptRows = $runPreparedRows(
        "SELECT id, department_name FROM departments WHERE CAST(id AS CHAR) IN ({$placeholders}) AND COALESCE(status, 'active') = 'active' ORDER BY department_name",
        str_repeat('s', count($deptCandidates)),
        $deptCandidates
    );
    foreach ($deptRows as $row) {
        $departmentOptions[(string)($row['id'] ?? '')] = (string)($row['department_name'] ?? '');
    }
}
$filterDepartment = trim((string)($_GET['department'] ?? ''));
if ($filterDepartment !== '' && !isset($departmentOptions[$filterDepartment])) {
    $filterDepartment = '';
}
$scopeDeptCandidates = $filterDepartment !== '' ? [$filterDepartment] : $deptCandidates;

$deptProgramCodes = [];
$deptCourseCodes = [];
$hasProgramsTable = $tableExists($db, 'programs');
$programCodeCol = $hasProgramsTable ? $detectColumn($db, 'programs', ['program_code', 'programId', 'program']) : null;

if (!empty($scopeDeptCandidates) && $hasProgramsTable && $programCodeCol) {
    $progDeptCol = $detectColumn($db, 'programs', ['department_id', 'deptId', 'DeptID', 'department_code', 'dept_code']);
    if ($progDeptCol) {
        $placeholders = implode(',', array_fill(0, count($scopeDeptCandidates), '?'));
        $sql = "SELECT DISTINCT `{$programCodeCol}` AS program_code FROM programs WHERE `{$progDeptCol}` IN ({$placeholders})";
        $rows = $runPreparedRows($sql, str_repeat('s', count($scopeDeptCandidates)), $scopeDeptCandidates);
        foreach ($rows as $row) {
            $code = trim((string)($row['program_code'] ?? ''));
            if ($code !== '') {
                $deptProgramCodes[] = $code;
            }
        }
    }
}

if (!empty($deptProgramCodes) && $tableExists($db, 'program_courses')) {
    $pcProgramCol = $detectColumn($db, 'program_courses', ['program_code', 'programId', 'program']);
    $pcCourseCol = $detectColumn($db, 'program_courses', ['course_code', 'courseId']);
    if ($pcProgramCol && $pcCourseCol) {
        $deptProgramCodes = array_values(array_unique($deptProgramCodes));
        $placeholders = implode(',', array_fill(0, count($deptProgramCodes), '?'));
        $sql = "SELECT DISTINCT `{$pcCourseCol}` AS course_code FROM program_courses WHERE `{$pcProgramCol}` IN ({$placeholders})";
        $rows = $runPreparedRows($sql, str_repeat('s', count($deptProgramCodes)), $deptProgramCodes);
        foreach ($rows as $row) {
            $code = trim((string)($row['course_code'] ?? ''));
            if ($code !== '') {
                $deptCourseCodes[] = $code;
            }
        }
    }
}

if (!empty($scopeDeptCandidates) && $staffDeptCol && $tableExists($db, 'course_lecturer')) {
    $placeholders = implode(',', array_fill(0, count($scopeDeptCandidates), '?'));
    $sql = "
        SELECT DISTINCT cl.course_code
        FROM course_lecturer cl
        INNER JOIN staff s ON s.staff_id = cl.staff_id
        WHERE s.`{$staffDeptCol}` IN ({$placeholders})
    ";
    $rows = $runPreparedRows($sql, str_repeat('s', count($scopeDeptCandidates)), $scopeDeptCandidates);
    foreach ($rows as $row) {
        $code = trim((string)($row['course_code'] ?? ''));
        if ($code !== '') {
            $deptCourseCodes[] = $code;
        }
    }
}

if (empty($deptCourseCodes) && $hodStaffId !== '' && $tableExists($db, 'course_lecturer')) {
    $rows = $runPreparedRows("SELECT DISTINCT course_code FROM course_lecturer WHERE staff_id = ?", 's', [$hodStaffId]);
    foreach ($rows as $row) {
        $code = trim((string)($row['course_code'] ?? ''));
        if ($code !== '') {
            $deptCourseCodes[] = $code;
        }
    }
}

$deptCourseCodes = array_values(array_unique(array_filter($deptCourseCodes)));
$deptProgramCodes = array_values(array_unique(array_filter($deptProgramCodes)));

if ($hodStaffId === '') {
    $notes[] = 'Unable to identify the logged-in staff account.';
}
if ($nonAcademicHosSection) {
    $notes[] = 'This HOS section is not linked to academic reports.';
} elseif ($deptIdRaw === '') {
    $notes[] = 'No department is linked to your account. Attempting course-based scope fallback.';
}
if (!$nonAcademicHosSection && empty($deptProgramCodes) && empty($deptCourseCodes)) {
    $notes[] = 'No programmes or courses are mapped to your section yet, so this report is empty. Contact the Systems Administrator if this looks wrong.';
}
// Never fall back to an unscoped (all sections) report.
$usingFallbackAll = false;

$studentIdCol = $detectColumn($db, 'students', ['SID', 'Sid', 'student_id']);
$studentFnameCol = $detectColumn($db, 'students', ['Fname', 'first_name']);
$studentLnameCol = $detectColumn($db, 'students', ['Lname', 'last_name']);
$studentSexCol = $detectColumn($db, 'students', ['sex', 'gender']);
$studentEmailCol = $detectColumn($db, 'students', ['email']);
$studentMobileCol = $detectColumn($db, 'students', ['mobile', 'phone']);

$spSidCol = $detectColumn($db, 'student_program', ['Sid', 'SID', 'student_id']);
$spProgramCol = $detectColumn($db, 'student_program', ['program_code']);
$spModeCol = $detectColumn($db, 'student_program', ['mode']);
$spYearCol = $detectColumn($db, 'student_program', ['startYear', 'start_year', 'academic_year']);
$spTermCol = $detectColumn($db, 'student_program', ['term', 'semester']);
$spStatusCol = $detectColumn($db, 'student_program', ['status']);
$spIntakeCol = $detectColumn($db, 'student_program', ['intake']);
$programNameCol = $hasProgramsTable ? $detectColumn($db, 'programs', ['program_name', 'name']) : null;

if (!$studentIdCol || !$spSidCol || !$spProgramCol) {
    $notes[] = 'Required columns for student reporting are missing (students/student_program linkage).';
}

$baseFrom = '';
$scopeWhereParts = [];
$scopeTypes = '';
$scopeParams = [];

if ($studentIdCol && $spSidCol && $spProgramCol) {
    $programJoin = '';
    if ($hasProgramsTable && $programCodeCol) {
        $programJoin = "LEFT JOIN programs p ON p.`{$programCodeCol}` = sp.`{$spProgramCol}`";
    }
    $baseFrom = "
        FROM students st
        INNER JOIN student_program sp ON sp.`{$spSidCol}` = st.`{$studentIdCol}`
        {$programJoin}
    ";

    if (!empty($deptProgramCodes)) {
        $placeholders = implode(',', array_fill(0, count($deptProgramCodes), '?'));
        $scopeWhereParts[] = "sp.`{$spProgramCol}` IN ({$placeholders})";
        $scopeTypes .= str_repeat('s', count($deptProgramCodes));
        $scopeParams = array_merge($scopeParams, $deptProgramCodes);
    } elseif (!empty($deptCourseCodes) && $tableExists($db, 'course_registration')) {
        $scSidCol = $detectColumn($db, 'course_registration', ['Sid', 'SID', 'student_id']);
        $scCourseCol = $detectColumn($db, 'course_registration', ['course_code', 'Course_Code']);
        if ($scSidCol && $scCourseCol) {
            $placeholders = implode(',', array_fill(0, count($deptCourseCodes), '?'));
            $scopeWhereParts[] = "EXISTS (
                SELECT 1
                FROM course_registration sc
                WHERE sc.`{$scSidCol}` = sp.`{$spSidCol}`
                  AND sc.`{$scCourseCol}` IN ({$placeholders})
                  AND COALESCE(sc.is_active, 1) = 1
            )";
            $scopeTypes .= str_repeat('s', count($deptCourseCodes));
            $scopeParams = array_merge($scopeParams, $deptCourseCodes);
        }
    }
}

if (empty($scopeWhereParts)) {
    // No section scope resolved — return no rows rather than every student.
    $scopeWhereParts[] = '1=0';
}

$modeExpr = $spModeCol ? "COALESCE(sp.`{$spModeCol}`, '')" : "''";
$yearExpr = $spYearCol ? "COALESCE(sp.`{$spYearCol}`, '')" : "''";
$termExpr = $spTermCol ? "COALESCE(sp.`{$spTermCol}`, '')" : "''";
$statusExpr = $spStatusCol ? "COALESCE(sp.`{$spStatusCol}`, '')" : "''";
$intakeExpr = $spIntakeCol ? "COALESCE(sp.`{$spIntakeCol}`, '')" : "''";
$programNameExpr = ($hasProgramsTable && $programCodeCol && $programNameCol)
    ? "COALESCE(p.`{$programNameCol}`, sp.`{$spProgramCol}`)"
    : "sp.`{$spProgramCol}`";

$programOptions = [];
$modeOptions = [];
$yearOptions = [];
$termOptions = [];
$statusOptions = [];

if ($baseFrom !== '') {
    $optionsSql = "
        SELECT DISTINCT
            sp.`{$spProgramCol}` AS program_code,
            {$programNameExpr} AS program_name,
            {$modeExpr} AS mode_value,
            {$yearExpr} AS year_value,
            {$termExpr} AS term_value,
            {$statusExpr} AS status_value
        {$baseFrom}
        WHERE " . implode(' AND ', $scopeWhereParts) . "
        ORDER BY program_name
    ";
    $optionRows = $runPreparedRows($optionsSql, $scopeTypes, $scopeParams);
    foreach ($optionRows as $row) {
        $progCode = (string)($row['program_code'] ?? '');
        $progName = (string)($row['program_name'] ?? $progCode);
        if ($progCode !== '') {
            $programOptions[$progCode] = $progName;
        }
        $modeVal = trim((string)($row['mode_value'] ?? ''));
        if ($modeVal !== '') {
            $modeOptions[$modeVal] = $modeVal;
        }
        $yearVal = trim((string)($row['year_value'] ?? ''));
        if ($yearVal !== '') {
            $yearOptions[$yearVal] = $yearVal;
        }
        $termVal = trim((string)($row['term_value'] ?? ''));
        if ($termVal !== '') {
            $termOptions[$termVal] = $termVal;
        }
        $statusVal = trim((string)($row['status_value'] ?? ''));
        if ($statusVal !== '') {
            $statusOptions[$statusVal] = $statusVal;
        }
    }
}

asort($programOptions);
ksort($modeOptions);
ksort($yearOptions);
ksort($termOptions);
ksort($statusOptions);

$filterProgram = trim((string)($_GET['program_code'] ?? ''));
$filterMode = trim((string)($_GET['mode'] ?? ''));
$filterYear = trim((string)($_GET['year'] ?? ''));
$filterTerm = trim((string)($_GET['term'] ?? ''));
$defaultStatus = 'all';
if ($spStatusCol) {
    if (isset($statusOptions['Active'])) {
        $defaultStatus = 'Active';
    } elseif (isset($statusOptions['active'])) {
        $defaultStatus = 'active';
    } elseif (!empty($statusOptions)) {
        $defaultStatus = array_key_first($statusOptions);
    }
}
$filterStatus = trim((string)($_GET['status'] ?? $defaultStatus));

if ($filterProgram !== '' && !isset($programOptions[$filterProgram])) {
    $filterProgram = '';
}
if ($filterMode !== '' && !isset($modeOptions[$filterMode])) {
    $filterMode = '';
}
if ($filterYear !== '' && !isset($yearOptions[$filterYear])) {
    $filterYear = '';
}
if ($filterTerm !== '' && !isset($termOptions[$filterTerm])) {
    $filterTerm = '';
}
if ($filterStatus !== 'all' && $filterStatus !== '' && !isset($statusOptions[$filterStatus])) {
    $matchedStatus = '';
    foreach ($statusOptions as $statusOpt => $_) {
        if (strcasecmp($statusOpt, $filterStatus) === 0) {
            $matchedStatus = $statusOpt;
            break;
        }
    }
    $filterStatus = $matchedStatus !== '' ? $matchedStatus : $defaultStatus;
}
if (!$spStatusCol) {
    $filterStatus = 'all';
}

$reportRows = [];

if ($baseFrom !== '') {
    $reportWhere = $scopeWhereParts;
    $reportTypes = $scopeTypes;
    $reportParams = $scopeParams;

    if ($filterProgram !== '') {
        $reportWhere[] = "sp.`{$spProgramCol}` = ?";
        $reportTypes .= 's';
        $reportParams[] = $filterProgram;
    }
    if ($spModeCol && $filterMode !== '') {
        $reportWhere[] = "sp.`{$spModeCol}` = ?";
        $reportTypes .= 's';
        $reportParams[] = $filterMode;
    }
    if ($spYearCol && $filterYear !== '') {
        $reportWhere[] = "sp.`{$spYearCol}` = ?";
        $reportTypes .= 's';
        $reportParams[] = $filterYear;
    }
    if ($spTermCol && $filterTerm !== '') {
        $reportWhere[] = "sp.`{$spTermCol}` = ?";
        $reportTypes .= 's';
        $reportParams[] = $filterTerm;
    }
    if ($spStatusCol && $filterStatus !== '' && strtolower($filterStatus) !== 'all') {
        $reportWhere[] = "sp.`{$spStatusCol}` = ?";
        $reportTypes .= 's';
        $reportParams[] = $filterStatus;
    }

    $fnameExpr = $studentFnameCol ? "COALESCE(st.`{$studentFnameCol}`, '')" : "''";
    $lnameExpr = $studentLnameCol ? "COALESCE(st.`{$studentLnameCol}`, '')" : "''";
    $sexValueExpr = $studentSexCol ? "COALESCE(st.`{$studentSexCol}`, '')" : "''";
    $emailValueExpr = $studentEmailCol ? "COALESCE(st.`{$studentEmailCol}`, '')" : "''";
    $mobileValueExpr = $studentMobileCol ? "COALESCE(st.`{$studentMobileCol}`, '')" : "''";

    $reportSql = "
        SELECT
            st.`{$studentIdCol}` AS sid,
            {$fnameExpr} AS fname,
            {$lnameExpr} AS lname,
            {$sexValueExpr} AS sex_value,
            {$emailValueExpr} AS email_value,
            {$mobileValueExpr} AS mobile_value,
            sp.`{$spProgramCol}` AS program_code,
            {$programNameExpr} AS program_name,
            {$modeExpr} AS mode_value,
            {$intakeExpr} AS intake_value,
            {$yearExpr} AS year_value,
            {$termExpr} AS term_value,
            {$statusExpr} AS status_value
        {$baseFrom}
        WHERE " . implode(' AND ', $reportWhere) . "
        ORDER BY program_name, lname, fname, sid
    ";
    $reportRows = $runPreparedRows($reportSql, $reportTypes, $reportParams);
}

$filteredStudents = count($reportRows);
$maleCount = 0;
$femaleCount = 0;
$activeCount = 0;
$programDist = [];
$modeDist = [];
$statusDist = [];

foreach ($reportRows as $row) {
    $sex = strtoupper(substr(trim((string)($row['sex_value'] ?? '')), 0, 1));
    if ($sex === 'M') {
        $maleCount++;
    } elseif ($sex === 'F') {
        $femaleCount++;
    }

    $status = trim((string)($row['status_value'] ?? ''));
    if (strtolower($status) === 'active') {
        $activeCount++;
    }

    $program = trim((string)($row['program_name'] ?? 'Unknown'));
    if ($program === '') {
        $program = 'Unknown';
    }
    if (!isset($programDist[$program])) {
        $programDist[$program] = 0;
    }
    $programDist[$program]++;

    $mode = trim((string)($row['mode_value'] ?? ''));
    if ($mode === '') {
        $mode = 'Not Specified';
    }
    if (!isset($modeDist[$mode])) {
        $modeDist[$mode] = 0;
    }
    $modeDist[$mode]++;

    $statusLabel = $status !== '' ? $status : 'Unknown';
    if (!isset($statusDist[$statusLabel])) {
        $statusDist[$statusLabel] = 0;
    }
    $statusDist[$statusLabel]++;
}

arsort($programDist);
arsort($modeDist);
arsort($statusDist);

$aiReportSummary = wuc_academic_risk_report_summary(
    $db,
    array_column($reportRows, 'sid'),
    'hos_department_report',
    md5(json_encode($_GET, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
    $filteredStudents > 0
);

$malePercent = $filteredStudents > 0 ? round(($maleCount / $filteredStudents) * 100, 1) : 0;
$femalePercent = $filteredStudents > 0 ? round(($femaleCount / $filteredStudents) * 100, 1) : 0;

$deptCourseCount = count($deptCourseCodes);
$coursePerformance = [];
$examRecordCount = 0;
$approvedExamCount = 0;
$pendingExamCount = 0;
$rejectedExamCount = 0;
$avgExamMark = 0;

if (!empty($deptCourseCodes) && $tableExists($db, 'exams')) {
    $examCourseCol = $detectColumn($db, 'exams', ['Course_Code', 'course_code']);
    $examTotalCol = $detectColumn($db, 'exams', ['Total_marks', 'total_marks']);
    $examStatusCol = $detectColumn($db, 'exams', ['status']);
    $examYearCol = $detectColumn($db, 'exams', ['Year', 'year', 'academic_year']);
    $hasCoursesTable = $tableExists($db, 'courses');
    $coursesCodeCol = $hasCoursesTable ? $detectColumn($db, 'courses', ['course_code', 'Course_Code']) : null;
    $coursesNameCol = $hasCoursesTable ? $detectColumn($db, 'courses', ['course_name', 'name']) : null;

    if ($examCourseCol) {
        $placeholders = implode(',', array_fill(0, count($deptCourseCodes), '?'));
        $types = str_repeat('s', count($deptCourseCodes));
        $params = $deptCourseCodes;

        $avgExpr = $examTotalCol
            ? "ROUND(AVG(CAST(e.`{$examTotalCol}` AS DECIMAL(10,2))), 2) AS avg_total"
            : "NULL AS avg_total";
        $approvedExpr = $examStatusCol
            ? "SUM(CASE WHEN LOWER(COALESCE(e.`{$examStatusCol}`, '')) = 'approved' THEN 1 ELSE 0 END) AS approved_count"
            : "0 AS approved_count";
        $pendingExpr = $examStatusCol
            ? "SUM(CASE WHEN LOWER(COALESCE(e.`{$examStatusCol}`, '')) = 'pending' THEN 1 ELSE 0 END) AS pending_count"
            : "0 AS pending_count";
        $rejectedExpr = $examStatusCol
            ? "SUM(CASE WHEN LOWER(COALESCE(e.`{$examStatusCol}`, '')) = 'rejected' THEN 1 ELSE 0 END) AS rejected_count"
            : "0 AS rejected_count";
        $examCourseNameExpr = ($hasCoursesTable && $coursesCodeCol && $coursesNameCol)
            ? "COALESCE(c.`{$coursesNameCol}`, e.`{$examCourseCol}`) AS course_name"
            : "e.`{$examCourseCol}` AS course_name";
        $examCourseJoin = ($hasCoursesTable && $coursesCodeCol && $coursesNameCol)
            ? "LEFT JOIN courses c ON c.`{$coursesCodeCol}` = e.`{$examCourseCol}`"
            : "";

        $examSql = "
            SELECT
                e.`{$examCourseCol}` AS course_code,
                {$examCourseNameExpr},
                COUNT(*) AS total_records,
                {$avgExpr},
                {$approvedExpr},
                {$pendingExpr},
                {$rejectedExpr}
            FROM exams e
            {$examCourseJoin}
            WHERE e.`{$examCourseCol}` IN ({$placeholders})
        ";

        if ($examYearCol && $filterYear !== '') {
            $examSql .= " AND e.`{$examYearCol}` = ?";
            $types .= 's';
            $params[] = $filterYear;
        }

        $examSql .= " GROUP BY e.`{$examCourseCol}` ORDER BY total_records DESC, course_code ASC";
        $coursePerformance = $runPreparedRows($examSql, $types, $params);

        $avgAccumulator = 0.0;
        $avgCounter = 0;
        foreach ($coursePerformance as $row) {
            $examRecordCount += (int)($row['total_records'] ?? 0);
            $approvedExamCount += (int)($row['approved_count'] ?? 0);
            $pendingExamCount += (int)($row['pending_count'] ?? 0);
            $rejectedExamCount += (int)($row['rejected_count'] ?? 0);
            if ($row['avg_total'] !== null && $row['avg_total'] !== '') {
                $avgAccumulator += (float)$row['avg_total'];
                $avgCounter++;
            }
        }
        if ($avgCounter > 0) {
            $avgExamMark = round($avgAccumulator / $avgCounter, 2);
        }
    }
}

$approvedRate = $examRecordCount > 0 ? round(($approvedExamCount / $examRecordCount) * 100, 1) : 0;

$scopeStudentCount = 0;
if ($baseFrom !== '') {
    $scopeSql = "SELECT COUNT(DISTINCT st.`{$studentIdCol}`) AS total {$baseFrom} WHERE " . implode(' AND ', $scopeWhereParts);
    $scopeStudentCount = (int)$runPreparedScalar($scopeSql, $scopeTypes, $scopeParams, 0);
}

$exportParams = $_GET;
$exportParams['export_csv'] = '1';
$exportUrl = 'reports.php?' . http_build_query($exportParams);

if (isset($_GET['export_csv']) && $_GET['export_csv'] === '1') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    $filename = 'hod_reports_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Section', $deptName !== '' ? $deptName : ($deptIdRaw !== '' ? $deptIdRaw : 'N/A')]);
    fputcsv($out, ['Department', $filterDepartment !== '' ? ($departmentOptions[$filterDepartment] ?? $filterDepartment) : 'All section departments']);
    fputcsv($out, ['Generated By', (string)($_SESSION['staff_id'] ?? '')]);
    fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
    fputcsv($out, []);
    fputcsv($out, ['Summary']);
    fputcsv($out, ['Scoped Students', $scopeStudentCount]);
    fputcsv($out, ['Filtered Students', $filteredStudents]);
    fputcsv($out, ['Department Courses', $deptCourseCount]);
    fputcsv($out, ['Exam Records', $examRecordCount]);
    fputcsv($out, ['Approved Exam Records', $approvedExamCount]);
    fputcsv($out, ['Approval Rate (%)', $approvedRate]);
    fputcsv($out, ['AI Summary', $aiReportSummary['summary_text'] ?? '']);
    fputcsv($out, []);

    fputcsv($out, ['Student Report']);
    fputcsv($out, ['#', 'Student ID', 'First Name', 'Last Name', 'Gender', 'Program', 'Mode', 'Intake', 'Year', 'Term', 'Status', 'Email', 'Mobile']);
    $n = 1;
    foreach ($reportRows as $row) {
        fputcsv($out, [
            $n++,
            $row['sid'] ?? '',
            $row['fname'] ?? '',
            $row['lname'] ?? '',
            $row['sex_value'] ?? '',
            $row['program_name'] ?? '',
            $row['mode_value'] ?? '',
            $row['intake_value'] ?? '',
            $row['year_value'] ?? '',
            $row['term_value'] ?? '',
            $row['status_value'] ?? '',
            $row['email_value'] ?? '',
            $row['mobile_value'] ?? '',
        ]);
    }

    if (!empty($coursePerformance)) {
        fputcsv($out, []);
        fputcsv($out, ['Course Performance']);
        fputcsv($out, ['Course Code', 'Course Name', 'Exam Records', 'Avg Total', 'Approved', 'Pending', 'Rejected']);
        foreach ($coursePerformance as $row) {
            fputcsv($out, [
                $row['course_code'] ?? '',
                $row['course_name'] ?? '',
                $row['total_records'] ?? '',
                $row['avg_total'] ?? '',
                $row['approved_count'] ?? '',
                $row['pending_count'] ?? '',
                $row['rejected_count'] ?? '',
            ]);
        }
    }

    fclose($out);
    exit;
}

// Printable report chrome: institution logo, section, filters, generated-by.
render_report_print_styles();
render_report_print_script();
render_report_print_header(
    'Head of Section Department Report',
    $deptName !== '' ? $deptName : 'Section not assigned',
    [
        'Section' => $deptName !== '' ? $deptName : 'Not assigned',
        'Department' => $filterDepartment !== '' ? ($departmentOptions[$filterDepartment] ?? $filterDepartment) : 'All section departments',
        'Academic Year' => $filterYear !== '' ? $filterYear : 'All',
        'Term/Semester' => $filterTerm !== '' ? $filterTerm : 'All',
        'Generated By' => (string)($_SESSION['staff_id'] ?? ''),
    ]
);
?>

<div class="container-fluid px-4 portal-dashboard hod-page reports-page">
    <div class="page-header mt-4 mb-3 d-print-none">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-chart-bar me-2 text-primary"></i>Department Reports</h5>
                <p class="page-subtitle mb-0">
                    <i class="fas fa-building me-1"></i>
                    <?php echo htmlspecialchars($deptName !== '' ? $deptName : ($deptIdRaw !== '' ? $deptIdRaw : 'Department not assigned')); ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo htmlspecialchars($exportUrl); ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-csv me-1"></i>Export CSV
                </a>
                <button type="button" class="btn btn-outline-secondary btn-sm js-print-report">
                    <i class="fas fa-print me-1"></i>Print
                </button>
            </div>
        </div>
    </div>

    <?php foreach ($notes as $note): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2 d-print-none">
            <i class="fas fa-exclamation-triangle mt-1"></i>
            <div><?php echo htmlspecialchars($note); ?></div>
        </div>
    <?php endforeach; ?>

    <?php echo wuc_academic_risk_render_report_summary($aiReportSummary); ?>

    <div class="data-table-card mb-4 d-print-none">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Report Filters</h5>
        </div>
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <?php if (count($departmentOptions) > 1): ?>
                <div class="col-md-3">
                    <label for="department" class="form-label small fw-semibold text-muted">Department</label>
                    <select id="department" name="department" class="form-select form-select-sm">
                        <option value="">All Departments</option>
                        <?php foreach ($departmentOptions as $deptOptId => $deptOptName): ?>
                            <option value="<?php echo htmlspecialchars((string)$deptOptId); ?>" <?php echo $filterDepartment === (string)$deptOptId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($deptOptName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <label for="program_code" class="form-label small fw-semibold text-muted">Program</label>
                    <select id="program_code" name="program_code" class="form-select form-select-sm">
                        <option value="">All Programs</option>
                        <?php foreach ($programOptions as $code => $name): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $filterProgram === $code ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="mode" class="form-label small fw-semibold text-muted">Mode</label>
                    <select id="mode" name="mode" class="form-select form-select-sm">
                        <option value="">All Modes</option>
                        <?php foreach ($modeOptions as $mode): ?>
                            <option value="<?php echo htmlspecialchars($mode); ?>" <?php echo $filterMode === $mode ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($mode); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="year" class="form-label small fw-semibold text-muted">Year</label>
                    <select id="year" name="year" class="form-select form-select-sm">
                        <option value="">All Years</option>
                        <?php foreach ($yearOptions as $year): ?>
                            <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $filterYear === (string)$year ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="term" class="form-label small fw-semibold text-muted">Term/Sem</label>
                    <select id="term" name="term" class="form-select form-select-sm">
                        <option value="">All Terms</option>
                        <?php foreach ($termOptions as $term): ?>
                            <option value="<?php echo htmlspecialchars($term); ?>" <?php echo $filterTerm === $term ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($term); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="status" class="form-label small fw-semibold text-muted">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="all" <?php echo strtolower($filterStatus) === 'all' ? 'selected' : ''; ?>>All</option>
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $filterStatus === $status ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 d-grid">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <div class="col-md-12 d-flex gap-2">
                    <a href="reports.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-undo me-1"></i>Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-students me-3"><i class="fas fa-user-graduate text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $filteredStudents; ?></h3>
                        <p class="text-muted mb-0">Filtered Students</p>
                        <small class="text-muted">of <?php echo $scopeStudentCount; ?> scoped</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-courses me-3"><i class="fas fa-book text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $deptCourseCount; ?></h3>
                        <p class="text-muted mb-0">Department Courses</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-exams me-3"><i class="fas fa-clipboard-check text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $examRecordCount; ?></h3>
                        <p class="text-muted mb-0">Exam Records</p>
                        <small class="text-muted">Avg <?php echo $avgExamMark > 0 ? $avgExamMark : '-'; ?></small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-rate me-3"><i class="fas fa-percent text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $approvedRate; ?>%</h3>
                        <p class="text-muted mb-0">Approval Rate</p>
                        <small class="text-muted"><?php echo $approvedExamCount; ?> approved</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="data-table-card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-venus-mars me-2 text-hod-primary"></i>Gender Split</h5>
                </div>
                <div class="card-body">
                    <div class="report-metric-row">
                        <div class="d-flex justify-content-between">
                            <span>Male</span>
                            <strong><?php echo $maleCount; ?> (<?php echo $malePercent; ?>%)</strong>
                        </div>
                        <div class="progress report-progress">
                            <div class="progress-bar bg-primary js-progress-width" data-width="<?php echo $malePercent; ?>"></div>
                        </div>
                    </div>
                    <div class="report-metric-row mt-3">
                        <div class="d-flex justify-content-between">
                            <span>Female</span>
                            <strong><?php echo $femaleCount; ?> (<?php echo $femalePercent; ?>%)</strong>
                        </div>
                        <div class="progress report-progress">
                            <div class="progress-bar bg-danger js-progress-width" data-width="<?php echo $femalePercent; ?>"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="data-table-card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-layer-group me-2 text-hod-primary"></i>Study Modes</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($modeDist)): ?>
                        <div class="text-muted small">No mode data available.</div>
                    <?php else: ?>
                        <div class="report-list">
                            <?php foreach ($modeDist as $mode => $count): ?>
                                <?php $pct = $filteredStudents > 0 ? round(($count / $filteredStudents) * 100, 1) : 0; ?>
                                <div class="report-list-item">
                                    <div class="d-flex justify-content-between">
                                        <span><?php echo htmlspecialchars($mode); ?></span>
                                        <strong><?php echo $count; ?></strong>
                                    </div>
                                    <div class="progress report-progress">
                                        <div class="progress-bar bg-info js-progress-width" data-width="<?php echo $pct; ?>"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="data-table-card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-sitemap me-2 text-hod-primary"></i>Top Programs</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($programDist)): ?>
                        <div class="text-muted small">No program data available.</div>
                    <?php else: ?>
                        <div class="report-list">
                            <?php foreach (array_slice($programDist, 0, 8, true) as $program => $count): ?>
                                <?php $pct = $filteredStudents > 0 ? round(($count / $filteredStudents) * 100, 1) : 0; ?>
                                <div class="report-list-item">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-truncate me-2"><?php echo htmlspecialchars($program); ?></span>
                                        <strong><?php echo $count; ?></strong>
                                    </div>
                                    <div class="progress report-progress">
                                        <div class="progress-bar bg-success js-progress-width" data-width="<?php echo $pct; ?>"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="data-table-card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center gap-2">
            <h5 class="mb-0"><i class="fas fa-users me-2"></i>Student Report Table</h5>
            <input type="text" id="studentSearch" class="form-control form-control-sm report-search d-print-none" placeholder="Search student table...">
        </div>
        <?php if (empty($reportRows)): ?>
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-user-slash fa-3x mb-3 d-block empty-state-icon"></i>
                <h5>No Records Found</h5>
                <p class="small mb-0">No students matched the selected filters.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="studentReportTable">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th class="text-center">Gender</th>
                            <th>Program</th>
                            <th>Mode</th>
                            <th>Intake</th>
                            <th>Year</th>
                            <th>Term</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $n = 1; foreach ($reportRows as $row): ?>
                            <tr class="report-student-row">
                                <td><?php echo $n++; ?></td>
                                <td class="fw-semibold"><?php echo htmlspecialchars($row['sid'] ?? ''); ?></td>
                                <td>
                                    <?php echo htmlspecialchars(trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''))); ?>
                                    <?php if (!empty($row['email_value']) || !empty($row['mobile_value'])): ?>
                                        <div class="small text-muted">
                                            <?php echo htmlspecialchars($row['email_value'] ?? ''); ?>
                                            <?php if (!empty($row['email_value']) && !empty($row['mobile_value'])): ?> | <?php endif; ?>
                                            <?php echo htmlspecialchars($row['mobile_value'] ?? ''); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo htmlspecialchars($row['sex_value'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['program_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['mode_value'] !== '' ? $row['mode_value'] : '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['intake_value'] !== '' ? $row['intake_value'] : '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['year_value'] !== '' ? $row['year_value'] : '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['term_value'] !== '' ? $row['term_value'] : '-'); ?></td>
                                <td>
                                    <span class="badge report-status <?php echo strtolower((string)($row['status_value'] ?? '')) === 'active' ? 'is-active' : ''; ?>">
                                        <?php echo htmlspecialchars($row['status_value'] !== '' ? $row['status_value'] : 'Unknown'); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="data-table-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Course Performance (Exam Records)</h5>
        </div>
        <?php if (empty($coursePerformance)): ?>
            <div class="card-body text-muted">
                No exam performance rows available for the current scope/filter.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Course</th>
                            <th class="text-center">Records</th>
                            <th class="text-center">Avg Total</th>
                            <th class="text-center">Approved</th>
                            <th class="text-center">Pending</th>
                            <th class="text-center">Rejected</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($coursePerformance as $row): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['course_code'] ?? ''); ?></strong>
                                    <div class="small text-muted"><?php echo htmlspecialchars($row['course_name'] ?? ''); ?></div>
                                </td>
                                <td class="text-center"><?php echo (int)($row['total_records'] ?? 0); ?></td>
                                <td class="text-center"><?php echo $row['avg_total'] !== null && $row['avg_total'] !== '' ? htmlspecialchars($row['avg_total']) : '-'; ?></td>
                                <td class="text-center"><span class="badge bg-success"><?php echo (int)($row['approved_count'] ?? 0); ?></span></td>
                                <td class="text-center"><span class="badge bg-warning text-dark"><?php echo (int)($row['pending_count'] ?? 0); ?></span></td>
                                <td class="text-center"><span class="badge bg-danger"><?php echo (int)($row['rejected_count'] ?? 0); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('.js-print-report').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.print();
        });
    });

    document.querySelectorAll('.js-progress-width').forEach(function (bar) {
        var width = parseFloat(bar.getAttribute('data-width') || '0');
        if (isNaN(width)) {
            width = 0;
        }
        width = Math.max(0, Math.min(100, width));
        bar.style.width = width + '%';
        bar.setAttribute('aria-valuenow', String(Math.round(width)));
    });

    var search = document.getElementById('studentSearch');
    var rows = Array.from(document.querySelectorAll('#studentReportTable tbody .report-student-row'));
    if (search && rows.length) {
        search.addEventListener('input', function () {
            var q = search.value.toLowerCase().trim();
            rows.forEach(function (row) {
                var visible = !q || row.textContent.toLowerCase().indexOf(q) !== -1;
                row.style.display = visible ? '' : 'none';
            });
        });
    }
})();
</script>

<?php require "includes/footer.php"; ?>
