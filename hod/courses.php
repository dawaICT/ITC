<?php
error_reporting(0);
$page_title = 'Department Courses';
require "includes/nav.php";
require_once __DIR__ . '/includes/hod_schema_helpers.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
$coursesCsrfToken = wuc_csrf_token();

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

$hodStaffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
if ($hodStaffId !== '' && !isset($_SESSION['staff_id'])) {
    $_SESSION['staff_id'] = $hodStaffId;
}

$flash = function(string $kind, string $message): void {
    if ($kind === 'success') {
        $_SESSION['successMsg'] = $message;
    } else {
        $_SESSION['errorMsg'] = $message;
    }
};

$redirectToCourses = static function(): void {
    $target = 'courses.php';
    if (!headers_sent()) {
        header('Location: ' . $target);
        exit;
    }

    $safeTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
    echo '<script>window.location.href=' . json_encode($target) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safeTarget . '"></noscript>';
    exit;
};

$staffDeptCol = $detectColumn($db, 'staff', ['deptId', 'DeptID', 'department_id']);
$deptIdCol = $detectColumn($db, 'departments', ['department_id', 'id', 'DeptID']);
$deptNameCol = $detectColumn($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']);
$deptHodCol = $detectColumn($db, 'departments', ['hod_id', 'HODID', 'hodId']);
$deptIdRaw = '';
$deptName = '';
$deptCandidates = [];
$notes = [];
$usingFallbackAllCourses = false;
$nonAcademicHosSection = false;

if ($hodStaffId !== '' && $deptHodCol && $deptIdCol) {
    $deptNameSelect = $deptNameCol ? "`{$deptNameCol}` AS dept_name" : "'' AS dept_name";
    $deptStmt = @$db->prepare("
        SELECT `{$deptIdCol}` AS dept_value, {$deptNameSelect}
        FROM departments
        WHERE CAST(`{$deptHodCol}` AS CHAR) = CAST(? AS CHAR)
           OR CAST(`{$deptHodCol}` AS CHAR) = (
               SELECT CAST(id AS CHAR) FROM staff WHERE staff_id = ? LIMIT 1
           )
        LIMIT 1
    ");
    if ($deptStmt) {
        $deptStmt->bind_param('ss', $hodStaffId, $hodStaffId);
        $deptStmt->execute();
        $deptRes = $deptStmt->get_result();
        if ($deptRes && ($deptRow = $deptRes->fetch_assoc())) {
            $deptIdRaw = trim((string)($deptRow['dept_value'] ?? ''));
            $deptName = (string)($deptRow['dept_name'] ?? '');
        }
        $deptStmt->close();
    }
}

if ($deptIdRaw === '' && $hodStaffId !== '' && $staffDeptCol) {
    $staffStmt = @$db->prepare("SELECT `{$staffDeptCol}` AS dept_value FROM staff WHERE staff_id = ? LIMIT 1");
    if ($staffStmt) {
        $staffStmt->bind_param('s', $hodStaffId);
        $staffStmt->execute();
        $staffRes = $staffStmt->get_result();
        if ($staffRes && ($staffRow = $staffRes->fetch_assoc())) {
            $deptIdRaw = trim((string)($staffRow['dept_value'] ?? ''));
        }
        $staffStmt->close();
    }
}

if ($deptIdRaw !== '') {
    $deptCandidates[] = $deptIdRaw;
    $_SESSION['dept_id'] = $deptIdRaw;
}

$deptContext = hod_resolve_department($db, $hodStaffId);
$sectionType = (string)($deptContext['section_type'] ?? '');
$nonAcademicHosSection = ($sectionType !== '' && $sectionType !== 'academic');
if ((string)$deptContext['id'] !== '') {
    $deptIdRaw = (string)$deptContext['id'];
    $deptName = (string)$deptContext['name'];
    // Every department in the section, not just the first one.
    $deptCandidates = hod_section_department_ids($deptContext);
    $_SESSION['dept_id'] = $deptIdRaw;
} elseif ($nonAcademicHosSection) {
    $deptName = (string)$deptContext['name'];
}

if ($deptIdRaw !== '' && $tableExists($db, 'departments')) {
    $deptCodeCol = $detectColumn($db, 'departments', ['deptId', 'department_code', 'department_id']);
    $deptNumCol = $detectColumn($db, 'departments', ['id', 'DeptID', 'department_id']);

    $whereParts = [];
    $bindTypes = '';
    $bindValues = [];

    if ($deptCodeCol) {
        $whereParts[] = "`{$deptCodeCol}` = ?";
        $bindTypes .= 's';
        $bindValues[] = $deptIdRaw;
    }
    if ($deptNumCol && ctype_digit($deptIdRaw)) {
        $whereParts[] = "`{$deptNumCol}` = ?";
        $bindTypes .= 'i';
        $bindValues[] = (int)$deptIdRaw;
    }

    if (!empty($whereParts)) {
        $selectCols = [];
        if ($deptNameCol) {
            $selectCols[] = "`{$deptNameCol}` AS dept_name";
        } elseif (!$nonAcademicHosSection) {
            $selectCols[] = "'' AS dept_name";
        }
        if ($deptCodeCol) {
            $selectCols[] = "`{$deptCodeCol}` AS dept_code";
        } else {
            $selectCols[] = "'' AS dept_code";
        }
        if ($deptNumCol) {
            $selectCols[] = "`{$deptNumCol}` AS dept_num";
        } else {
            $selectCols[] = "NULL AS dept_num";
        }

        $deptSql = "SELECT " . implode(', ', $selectCols) . " FROM departments WHERE " . implode(' OR ', $whereParts) . " LIMIT 1";
        $deptStmt = @$db->prepare($deptSql);
        if ($deptStmt) {
            $deptStmt->bind_param($bindTypes, ...$bindValues);
            $deptStmt->execute();
            $deptRes = $deptStmt->get_result();
            if ($deptRes && ($deptRow = $deptRes->fetch_assoc())) {
                $deptName = (string)($deptRow['dept_name'] ?? '');
                $deptCode = trim((string)($deptRow['dept_code'] ?? ''));
                $deptNum = trim((string)($deptRow['dept_num'] ?? ''));
                if ($deptCode !== '') {
                    $deptCandidates[] = $deptCode;
                }
                if ($deptNum !== '') {
                    $deptCandidates[] = $deptNum;
                }
            }
            $deptStmt->close();
        }
    }
}

$deptCandidates = array_values(array_unique(array_filter($deptCandidates, static function($v): bool {
    return $v !== null && $v !== '';
})));

$deptCourseCodes = [];
$programCodes = [];

if (!empty($deptCandidates) && $tableExists($db, 'programs')) {
    $progDeptCol = $detectColumn($db, 'programs', ['department_id', 'deptId', 'DeptID', 'department_code', 'dept_code']);
    if ($progDeptCol) {
        $placeholders = implode(',', array_fill(0, count($deptCandidates), '?'));
        $progSql = "SELECT DISTINCT program_code FROM programs WHERE `{$progDeptCol}` IN ({$placeholders})";
        $progStmt = @$db->prepare($progSql);
        if ($progStmt) {
            $progStmt->bind_param(str_repeat('s', count($deptCandidates)), ...$deptCandidates);
            $progStmt->execute();
            $progRes = $progStmt->get_result();
            while ($progRes && ($progRow = $progRes->fetch_assoc())) {
                $code = trim((string)($progRow['program_code'] ?? ''));
                if ($code !== '') {
                    $programCodes[] = $code;
                }
            }
            $progStmt->close();
        }
    }
}

if (!empty($programCodes) && $tableExists($db, 'program_courses')) {
    $pcProgramCol = $detectColumn($db, 'program_courses', ['program_code', 'programId', 'program']);
    $pcCourseCol = $detectColumn($db, 'program_courses', ['course_code', 'courseId']);
    if ($pcProgramCol && $pcCourseCol) {
        $programCodes = array_values(array_unique($programCodes));
        $placeholders = implode(',', array_fill(0, count($programCodes), '?'));
        $pcSql = "SELECT DISTINCT `{$pcCourseCol}` AS course_code FROM program_courses WHERE `{$pcProgramCol}` IN ({$placeholders})";
        $pcStmt = @$db->prepare($pcSql);
        if ($pcStmt) {
            $pcStmt->bind_param(str_repeat('s', count($programCodes)), ...$programCodes);
            $pcStmt->execute();
            $pcRes = $pcStmt->get_result();
            while ($pcRes && ($pcRow = $pcRes->fetch_assoc())) {
                $code = trim((string)($pcRow['course_code'] ?? ''));
                if ($code !== '') {
                    $deptCourseCodes[] = $code;
                }
            }
            $pcStmt->close();
        }
    }
}

if (!empty($deptCandidates) && $staffDeptCol && $tableExists($db, 'course_lecturer')) {
    $placeholders = implode(',', array_fill(0, count($deptCandidates), '?'));
    $clSql = "
        SELECT DISTINCT cl.course_code
        FROM course_lecturer cl
        INNER JOIN staff s ON s.staff_id = cl.staff_id
        WHERE s.`{$staffDeptCol}` IN ({$placeholders})
    ";
    $clStmt = @$db->prepare($clSql);
    if ($clStmt) {
        $clStmt->bind_param(str_repeat('s', count($deptCandidates)), ...$deptCandidates);
        $clStmt->execute();
        $clRes = $clStmt->get_result();
        while ($clRes && ($clRow = $clRes->fetch_assoc())) {
            $code = trim((string)($clRow['course_code'] ?? ''));
            if ($code !== '') {
                $deptCourseCodes[] = $code;
            }
        }
        $clStmt->close();
    }
}

if (empty($deptCourseCodes) && $hodStaffId !== '' && $tableExists($db, 'course_lecturer')) {
    $selfStmt = @$db->prepare("SELECT DISTINCT course_code FROM course_lecturer WHERE staff_id = ?");
    if ($selfStmt) {
        $selfStmt->bind_param('s', $hodStaffId);
        $selfStmt->execute();
        $selfRes = $selfStmt->get_result();
        while ($selfRes && ($selfRow = $selfRes->fetch_assoc())) {
            $code = trim((string)($selfRow['course_code'] ?? ''));
            if ($code !== '') {
                $deptCourseCodes[] = $code;
            }
        }
        $selfStmt->close();
    }
}

$deptCourseCodes = array_values(array_unique(array_filter($deptCourseCodes)));

if ($hodStaffId === '') {
    $notes[] = 'Unable to identify the logged-in staff account.';
}
if ($nonAcademicHosSection) {
    $notes[] = 'This HOS section is not linked to academic courses.';
} elseif ($deptIdRaw === '') {
    $notes[] = 'No department is linked to your account. Contact the Systems Administrator to assign your section.';
}
if (!$nonAcademicHosSection && $deptIdRaw !== '' && empty($deptCourseCodes)) {
    $notes[] = 'No courses are mapped to your section yet, so the course list is empty.';
}
// Never fall back to showing every course in the college.
$usingFallbackAllCourses = false;

$assignmentIdCol = 'id';
if ($tableExists($db, 'course_lecturer')) {
    $hasId = $detectColumn($db, 'course_lecturer', ['id']);
    if (!$hasId) {
        $legacyId = $detectColumn($db, 'course_lecturer', ['course_lecturer_id']);
        if ($legacyId) {
            $assignmentIdCol = $legacyId;
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        $flash('error', 'Your session token expired. Please retry the action.');
        $redirectToCourses();
    }

    if ($action === 'assign_lecturer') {
        $courseCode = trim((string)($_POST['course_code'] ?? ''));
        $staffId = trim((string)($_POST['staff_id'] ?? ''));

        if ($courseCode === '' || $staffId === '') {
            $flash('error', 'Please select both course and lecturer.');
        } elseif (!$tableExists($db, 'course_lecturer')) {
            $flash('error', 'Course assignment table is not available in this database.');
        } else {
            $courseExists = false;
            if ($stmt = @$db->prepare("SELECT 1 FROM courses WHERE course_code = ? LIMIT 1")) {
                $stmt->bind_param('s', $courseCode);
                $stmt->execute();
                $res = $stmt->get_result();
                $courseExists = $res && $res->num_rows > 0;
                $stmt->close();
            }

            if (!$courseExists) {
                $flash('error', 'Selected course does not exist.');
            } elseif (empty($deptCourseCodes) || !in_array($courseCode, $deptCourseCodes, true)) {
                $flash('error', 'That course does not belong to your section, so you cannot assign lecturers to it.');
            } else {
                $staffAllowed = true;
                if (!empty($deptCandidates) && $staffDeptCol) {
                    $staffAllowed = false;
                    $placeholders = implode(',', array_fill(0, count($deptCandidates), '?'));
                    $sql = "SELECT 1 FROM staff WHERE staff_id = ? AND `{$staffDeptCol}` IN ({$placeholders}) LIMIT 1";
                    $stmt = @$db->prepare($sql);
                    if ($stmt) {
                        $types = 's' . str_repeat('s', count($deptCandidates));
                        $params = array_merge([$staffId], $deptCandidates);
                        $stmt->bind_param($types, ...$params);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $staffAllowed = $res && $res->num_rows > 0;
                        $stmt->close();
                    }
                }

                if (!$staffAllowed) {
                    $flash('error', 'Lecturer must belong to your department.');
                } else {
                    $existsStmt = @$db->prepare("SELECT 1 FROM course_lecturer WHERE course_code = ? AND staff_id = ? LIMIT 1");
                    $exists = false;
                    if ($existsStmt) {
                        $existsStmt->bind_param('ss', $courseCode, $staffId);
                        $existsStmt->execute();
                        $existsRes = $existsStmt->get_result();
                        $exists = $existsRes && $existsRes->num_rows > 0;
                        $existsStmt->close();
                    }

                    if ($exists) {
                        $flash('error', 'This course is already assigned to the selected lecturer.');
                    } else {
                        $insertStmt = @$db->prepare("INSERT INTO course_lecturer (course_code, staff_id) VALUES (?, ?)");
                        if ($insertStmt) {
                            $insertStmt->bind_param('ss', $courseCode, $staffId);
                            if ($insertStmt->execute()) {
                                $flash('success', 'Lecturer assigned to course successfully.');
                            } else {
                                $flash('error', 'Failed to save assignment.');
                            }
                            $insertStmt->close();
                        } else {
                            $flash('error', 'Unable to prepare assignment insert.');
                        }
                    }
                }
            }
        }

        $redirectToCourses();
    }

    if ($action === 'remove_assignment') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        if ($assignmentId <= 0) {
            $flash('error', 'Invalid assignment selected.');
        } elseif (!$tableExists($db, 'course_lecturer')) {
            $flash('error', 'Course assignment table is not available.');
        } else {
            $allowedDelete = false;
            $courseCode = null;
            if (!$usingFallbackAllCourses && !empty($deptCourseCodes)) {
                $placeholders = implode(',', array_fill(0, count($deptCourseCodes), '?'));
                $sql = "SELECT course_code FROM course_lecturer WHERE `{$assignmentIdCol}` = ? AND course_code IN ({$placeholders}) LIMIT 1";
                $stmt = @$db->prepare($sql);
                if ($stmt) {
                    $types = 'i' . str_repeat('s', count($deptCourseCodes));
                    $params = array_merge([$assignmentId], $deptCourseCodes);
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && ($row = $res->fetch_assoc())) {
                        $allowedDelete = true;
                        $courseCode = $row['course_code'] ?? null;
                    }
                    $stmt->close();
                }
            }
            // With no section course scope the deletion stays denied — a HOS
            // must never remove assignments outside their own section.

            if (!$allowedDelete || !$courseCode) {
                $flash('error', 'Assignment was not found or is outside your allowed scope.');
            } else {
                $deleteStmt = @$db->prepare("DELETE FROM course_lecturer WHERE `{$assignmentIdCol}` = ?");
                if ($deleteStmt) {
                    $deleteStmt->bind_param('i', $assignmentId);
                    if ($deleteStmt->execute()) {
                        $flash('success', 'Assignment removed successfully.');
                    } else {
                        $flash('error', 'Failed to remove assignment.');
                    }
                    $deleteStmt->close();
                } else {
                    $flash('error', 'Unable to prepare assignment delete.');
                }
            }
        }

        $redirectToCourses();
    }
}

$successMsg = $_SESSION['successMsg'] ?? '';
$errorMsg = $_SESSION['errorMsg'] ?? '';
unset($_SESSION['successMsg'], $_SESSION['errorMsg']);

$deptLecturers = [];
if ($tableExists($db, 'staff')) {
    $staffNameCols = [
        'title' => $detectColumn($db, 'staff', ['title']),
        'fname' => $detectColumn($db, 'staff', ['Fname', 'first_name']),
        'lname' => $detectColumn($db, 'staff', ['Lname', 'last_name']),
    ];

    if (!empty($deptCandidates) && $staffDeptCol) {
        $placeholders = implode(',', array_fill(0, count($deptCandidates), '?'));
        $sql = "
            SELECT staff_id, " .
                ($staffNameCols['title'] ? "`{$staffNameCols['title']}` AS title" : "'' AS title") . ",
                " . ($staffNameCols['fname'] ? "`{$staffNameCols['fname']}` AS fname" : "'' AS fname") . ",
                " . ($staffNameCols['lname'] ? "`{$staffNameCols['lname']}` AS lname" : "'' AS lname") . "
            FROM staff
            WHERE `{$staffDeptCol}` IN ({$placeholders})
            ORDER BY fname, lname, staff_id
        ";
        $stmt = @$db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($deptCandidates)), ...$deptCandidates);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $name = trim(($row['title'] ?? '') . ' ' . ($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''));
                if ($name === '') {
                    $name = (string)$row['staff_id'];
                }
                $deptLecturers[] = [
                    'staff_id' => (string)$row['staff_id'],
                    'name' => $name,
                ];
            }
            $stmt->close();
        }
    }

    if (empty($deptLecturers)) {
        $sql = "
            SELECT staff_id, " .
                ($staffNameCols['title'] ? "`{$staffNameCols['title']}` AS title" : "'' AS title") . ",
                " . ($staffNameCols['fname'] ? "`{$staffNameCols['fname']}` AS fname" : "'' AS fname") . ",
                " . ($staffNameCols['lname'] ? "`{$staffNameCols['lname']}` AS lname" : "'' AS lname") . "
            FROM staff
            ORDER BY fname, lname, staff_id
        ";
        $res = @$db->query($sql);
        while ($res && ($row = $res->fetch_assoc())) {
            $name = trim(($row['title'] ?? '') . ' ' . ($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''));
            if ($name === '') {
                $name = (string)$row['staff_id'];
            }
            $deptLecturers[] = [
                'staff_id' => (string)$row['staff_id'],
                'name' => $name,
            ];
        }
    }
}

$courseRows = [];
$courseSort = [];

if ($tableExists($db, 'courses')) {
    $courseCodeCol = $detectColumn($db, 'courses', ['course_code']);
    $courseNameCol = $detectColumn($db, 'courses', ['course_name']);
    $courseCreditsCol = $detectColumn($db, 'courses', ['credits', 'credit_hours']);
    $courseStatusCol = $detectColumn($db, 'courses', ['status']);

    if ($courseCodeCol && $courseNameCol) {
        $selectCredits = $courseCreditsCol ? "COALESCE(`{$courseCreditsCol}`, 0) AS credits" : "0 AS credits";
        $selectStatus = $courseStatusCol ? "COALESCE(`{$courseStatusCol}`, 'active') AS status" : "'active' AS status";
        $baseSql = "SELECT `{$courseCodeCol}` AS course_code, `{$courseNameCol}` AS course_name, {$selectCredits}, {$selectStatus} FROM courses";

        if (!$usingFallbackAllCourses && !empty($deptCourseCodes)) {
            $placeholders = implode(',', array_fill(0, count($deptCourseCodes), '?'));
            $baseSql .= " WHERE `{$courseCodeCol}` IN ({$placeholders})";
            $baseSql .= " ORDER BY `{$courseCodeCol}` ASC";
            $stmt = @$db->prepare($baseSql);
            if ($stmt) {
                $stmt->bind_param(str_repeat('s', count($deptCourseCodes)), ...$deptCourseCodes);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $code = trim((string)$row['course_code']);
                    $courseRows[$code] = [
                        'course_code' => $code,
                        'course_name' => (string)$row['course_name'],
                        'credits' => (string)$row['credits'],
                        'status' => (string)$row['status'],
                        'semesters' => [],
                        'programs' => [],
                        'lecturers' => [],
                        'assignment_count' => 0,
                    ];
                }
                $stmt->close();
            }
        }
        // No section scope: the list stays empty (never show all courses).
    } else {
        $notes[] = 'Courses table is missing required columns (course_code/course_name).';
    }
} else {
    $notes[] = 'Courses table is not available in this database.';
}

$courseCodesLoaded = array_keys($courseRows);
sort($courseCodesLoaded);

if (!empty($courseCodesLoaded) && $tableExists($db, 'program_courses') && $tableExists($db, 'programs')) {
    $pcProgramCol = $detectColumn($db, 'program_courses', ['program_code', 'programId', 'program']);
    $pcCourseCol = $detectColumn($db, 'program_courses', ['course_code', 'courseId']);
    $pcSemesterCol = $detectColumn($db, 'program_courses', ['semester']);
    $programNameCol = $detectColumn($db, 'programs', ['program_name', 'name']);

    if ($pcProgramCol && $pcCourseCol) {
        $placeholders = implode(',', array_fill(0, count($courseCodesLoaded), '?'));
        $sql = "
            SELECT
                pc.`{$pcCourseCol}` AS course_code,
                pc.`{$pcProgramCol}` AS program_code,
                " . ($programNameCol ? "COALESCE(p.`{$programNameCol}`, pc.`{$pcProgramCol}`)" : "pc.`{$pcProgramCol}`") . " AS program_name,
                " . ($pcSemesterCol ? "pc.`{$pcSemesterCol}`" : "NULL") . " AS semester
            FROM program_courses pc
            LEFT JOIN programs p ON p.program_code = pc.`{$pcProgramCol}`
            WHERE pc.`{$pcCourseCol}` IN ({$placeholders})
        ";

        $stmt = @$db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($courseCodesLoaded)), ...$courseCodesLoaded);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $code = trim((string)($row['course_code'] ?? ''));
                if (!isset($courseRows[$code])) {
                    continue;
                }
                $programName = trim((string)($row['program_name'] ?? ''));
                $semester = trim((string)($row['semester'] ?? ''));

                if ($programName !== '' && !in_array($programName, $courseRows[$code]['programs'], true)) {
                    $courseRows[$code]['programs'][] = $programName;
                }
                if ($semester !== '' && !in_array($semester, $courseRows[$code]['semesters'], true)) {
                    $courseRows[$code]['semesters'][] = $semester;
                }
            }
            $stmt->close();
        }
    }
}

$assignedLecturerIds = [];

if (!empty($courseCodesLoaded) && $tableExists($db, 'course_lecturer') && $tableExists($db, 'staff')) {
    $clCourseCol = $detectColumn($db, 'course_lecturer', ['course_code']);
    $clStaffCol = $detectColumn($db, 'course_lecturer', ['staff_id']);
    $staffTitleCol = $detectColumn($db, 'staff', ['title']);
    $staffFnameCol = $detectColumn($db, 'staff', ['Fname', 'first_name']);
    $staffLnameCol = $detectColumn($db, 'staff', ['Lname', 'last_name']);

    if ($clCourseCol && $clStaffCol) {
        $placeholders = implode(',', array_fill(0, count($courseCodesLoaded), '?'));
        $sql = "
            SELECT
                cl.`{$assignmentIdCol}` AS assignment_id,
                cl.`{$clCourseCol}` AS course_code,
                cl.`{$clStaffCol}` AS staff_id,
                " . ($staffTitleCol ? "COALESCE(s.`{$staffTitleCol}`, '')" : "''") . " AS title,
                " . ($staffFnameCol ? "COALESCE(s.`{$staffFnameCol}`, '')" : "''") . " AS fname,
                " . ($staffLnameCol ? "COALESCE(s.`{$staffLnameCol}`, '')" : "''") . " AS lname,
                " . ($staffDeptCol ? "COALESCE(s.`{$staffDeptCol}`, '')" : "''") . " AS staff_dept
            FROM course_lecturer cl
            LEFT JOIN staff s ON s.staff_id = cl.`{$clStaffCol}`
            WHERE cl.`{$clCourseCol}` IN ({$placeholders})
                ORDER BY cl.`{$clCourseCol}`, fname, lname, cl.`{$clStaffCol}`
        ";

        $stmt = @$db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($courseCodesLoaded)), ...$courseCodesLoaded);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $code = trim((string)($row['course_code'] ?? ''));
                if (!isset($courseRows[$code])) {
                    continue;
                }
                $name = trim(($row['title'] ?? '') . ' ' . ($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''));
                if ($name === '') {
                    $name = (string)($row['staff_id'] ?? '');
                }
                $staffId = (string)($row['staff_id'] ?? '');
                $inDept = true;
                if (!empty($deptCandidates) && $staffDeptCol) {
                    $inDept = in_array((string)($row['staff_dept'] ?? ''), $deptCandidates, true);
                }
                $courseRows[$code]['lecturers'][] = [
                    'assignment_id' => (int)($row['assignment_id'] ?? 0),
                    'staff_id' => $staffId,
                    'name' => $name,
                    'in_dept' => $inDept,
                ];
                $courseRows[$code]['assignment_count']++;
                if ($staffId !== '') {
                    $assignedLecturerIds[$staffId] = true;
                }
            }
            $stmt->close();
        }
    }
}

foreach ($courseRows as $code => $row) {
    sort($row['semesters']);
    sort($row['programs']);
    $courseRows[$code] = $row;
}
ksort($courseRows);

if (isset($_GET['export_csv']) && $_GET['export_csv'] === '1') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    $filename = 'hod_courses_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Course Code', 'Course Name', 'Credits', 'Status', 'Semesters', 'Programs', 'Lecturers', 'Assignments']);
    $i = 1;
    foreach ($courseRows as $row) {
        $lecturerNames = [];
        foreach ($row['lecturers'] as $lecturer) {
            $lecturerNames[] = $lecturer['name'] . ($lecturer['staff_id'] !== '' ? " ({$lecturer['staff_id']})" : '');
        }
        fputcsv($out, [
            $i++,
            $row['course_code'],
            $row['course_name'],
            $row['credits'],
            $row['status'],
            implode('; ', $row['semesters']),
            implode('; ', $row['programs']),
            implode('; ', $lecturerNames),
            $row['assignment_count'],
        ]);
    }
    fclose($out);
    exit;
}

$programFilterOptions = [];
$semesterFilterOptions = [];
$assignedCourses = 0;

foreach ($courseRows as $row) {
    if ($row['assignment_count'] > 0) {
        $assignedCourses++;
    }
    foreach ($row['programs'] as $programName) {
        $programFilterOptions[$programName] = true;
    }
    foreach ($row['semesters'] as $semesterName) {
        $semesterFilterOptions[$semesterName] = true;
    }
}

$totalCourses = count($courseRows);
$totalPrograms = count($programFilterOptions);
$totalUnassignedCourses = $totalCourses - $assignedCourses;
$totalAssignedLecturers = count(array_keys($assignedLecturerIds));

$programFilterOptions = array_keys($programFilterOptions);
$semesterFilterOptions = array_keys($semesterFilterOptions);
sort($programFilterOptions);
sort($semesterFilterOptions);
?>

<div class="container-fluid px-4 portal-dashboard hod-page courses-page">
    <div class="page-header mt-4 mb-3 d-print-none">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-book me-2 text-primary"></i>Department Courses</h5>
                <p class="page-subtitle mb-0">
                    <i class="fas fa-building me-1"></i>
                    <?php echo htmlspecialchars($deptName !== '' ? $deptName : ($deptIdRaw !== '' ? $deptIdRaw : 'Department not assigned')); ?>
                    <?php if ($deptIdRaw !== ''): ?>
                        <span class="ms-2 badge bg-light text-dark"><?php echo htmlspecialchars($deptIdRaw); ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="?export_csv=1" class="btn btn-success btn-sm">
                    <i class="fas fa-download me-1"></i>Export CSV
                </a>
                <button type="button" class="btn btn-outline-secondary btn-sm js-print-page">
                    <i class="fas fa-print me-1"></i>Print
                </button>
            </div>
        </div>
    </div>

    <?php if ($successMsg !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show d-print-none" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($successMsg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show d-print-none" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($errorMsg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php foreach ($notes as $note): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2 d-print-none">
            <i class="fas fa-exclamation-triangle mt-1"></i>
            <div><?php echo htmlspecialchars($note); ?></div>
        </div>
    <?php endforeach; ?>

    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-courses me-3"><i class="fas fa-book-open text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $totalCourses; ?></h3>
                        <p class="text-muted mb-0">Total Courses</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-assigned me-3"><i class="fas fa-user-check text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $assignedCourses; ?></h3>
                        <p class="text-muted mb-0">Assigned Courses</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-programs me-3"><i class="fas fa-graduation-cap text-white"></i></div>
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
                    <div class="stat-icon stat-icon-lecturers me-3"><i class="fas fa-chalkboard-teacher text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo $totalAssignedLecturers; ?></h3>
                        <p class="text-muted mb-0">Lecturers Assigned</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="data-table-card mb-4 d-print-none">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end course-filters-grid">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted" for="courseSearch">Search</label>
                    <input type="text" id="courseSearch" class="form-control form-control-sm" placeholder="Search by code, name, lecturer...">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted" for="filterProgram">Program</label>
                    <select class="form-select form-select-sm" id="filterProgram">
                        <option value="">All Programs</option>
                        <?php foreach ($programFilterOptions as $programName): ?>
                            <option value="<?php echo htmlspecialchars($programName); ?>"><?php echo htmlspecialchars($programName); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted" for="filterSemester">Semester</label>
                    <select class="form-select form-select-sm" id="filterSemester">
                        <option value="">All</option>
                        <?php foreach ($semesterFilterOptions as $semesterName): ?>
                            <option value="<?php echo htmlspecialchars($semesterName); ?>"><?php echo htmlspecialchars($semesterName); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted" for="filterAssignment">Assignment</label>
                    <select class="form-select form-select-sm" id="filterAssignment">
                        <option value="">All</option>
                        <option value="assigned">Assigned</option>
                        <option value="unassigned">Unassigned</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100" id="clearCourseFilters">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="data-table-card">
        <div class="card-header d-flex justify-content-between align-items-center course-list-header">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Course List
                <span class="badge bg-secondary ms-2" id="visibleCourseCount"><?php echo $totalCourses; ?></span>
            </h5>
            <span class="text-muted small d-print-none">
                Unassigned: <strong id="unassignedCounter"><?php echo $totalUnassignedCourses; ?></strong>
            </span>
        </div>

        <?php if (empty($courseRows)): ?>
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-book-dead fa-3x mb-3 d-block empty-state-icon"></i>
                <h5>No Courses Found</h5>
                <p class="small mb-0">No course records are available for the selected department context.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive course-table-wrap">
                <table class="table table-hover align-middle mb-0" id="coursesTable">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Course</th>
                            <th class="text-center">Credits</th>
                            <th>Semesters</th>
                            <th>Programs</th>
                            <th>Lecturers</th>
                            <th class="text-center d-print-none">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $n = 1; foreach ($courseRows as $row): ?>
                            <?php
                            $programsText = implode(' | ', $row['programs']);
                            $semestersText = implode(' | ', $row['semesters']);
                            $lecturerSearch = [];
                            foreach ($row['lecturers'] as $lecturer) {
                                $lecturerSearch[] = $lecturer['name'] . ' ' . $lecturer['staff_id'];
                            }
                            $searchBlob = strtolower(trim(
                                $row['course_code'] . ' ' .
                                $row['course_name'] . ' ' .
                                implode(' ', $row['programs']) . ' ' .
                                implode(' ', $row['semesters']) . ' ' .
                                implode(' ', $lecturerSearch)
                            ));
                            ?>
                            <tr class="course-row"
                                data-search="<?php echo htmlspecialchars($searchBlob); ?>"
                                data-programs="<?php echo htmlspecialchars(strtolower($programsText)); ?>"
                                data-semesters="<?php echo htmlspecialchars(strtolower($semestersText)); ?>"
                                data-assigned="<?php echo $row['assignment_count'] > 0 ? 'assigned' : 'unassigned'; ?>">
                                <td><?php echo $n++; ?></td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-bold"><?php echo htmlspecialchars($row['course_code']); ?></span>
                                        <span class="text-muted small"><?php echo htmlspecialchars($row['course_name']); ?></span>
                                        <span class="small mt-1">
                                            <span class="badge <?php echo strtolower($row['status']) === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo htmlspecialchars(ucfirst($row['status'])); ?>
                                            </span>
                                        </span>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php echo htmlspecialchars($row['credits'] !== '' ? $row['credits'] : '0'); ?>
                                </td>
                                <td>
                                    <?php if (empty($row['semesters'])): ?>
                                        <span class="text-muted small">-</span>
                                    <?php else: ?>
                                        <div class="chip-wrap">
                                            <?php foreach ($row['semesters'] as $semesterName): ?>
                                                <span class="chip chip-semester">Sem <?php echo htmlspecialchars($semesterName); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($row['programs'])): ?>
                                        <span class="text-muted small">-</span>
                                    <?php else: ?>
                                        <div class="chip-wrap">
                                            <?php foreach ($row['programs'] as $programName): ?>
                                                <span class="chip chip-program"><?php echo htmlspecialchars($programName); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($row['lecturers'])): ?>
                                        <span class="badge bg-light text-dark">Unassigned</span>
                                    <?php else: ?>
                                        <div class="lecturer-wrap">
                                            <?php foreach ($row['lecturers'] as $lecturer): ?>
                                                <div class="lecturer-pill <?php echo $lecturer['in_dept'] ? 'in-dept' : 'out-dept'; ?>">
                                                    <span class="lecturer-name">
                                                        <?php echo htmlspecialchars($lecturer['name']); ?>
                                                        <?php if ($lecturer['staff_id'] !== ''): ?>
                                                            <small class="text-muted">(<?php echo htmlspecialchars($lecturer['staff_id']); ?>)</small>
                                                        <?php endif; ?>
                                                    </span>
                                                    <?php if ($lecturer['assignment_id'] > 0): ?>
                                                        <form method="post" class="d-inline js-remove-assignment-form d-print-none">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($coursesCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="action" value="remove_assignment">
                                                            <input type="hidden" name="assignment_id" value="<?php echo (int)$lecturer['assignment_id']; ?>">
                                                            <button type="submit" class="btn-remove-assignment" title="Remove assignment">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center d-print-none">
                                    <button type="button"
                                            class="btn btn-sm btn-primary js-open-assign-modal"
                                            data-course-code="<?php echo htmlspecialchars($row['course_code']); ?>"
                                            data-course-name="<?php echo htmlspecialchars($row['course_name']); ?>">
                                        <i class="fas fa-user-plus me-1"></i>Assign
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="assignLecturerModal" tabindex="-1" aria-labelledby="assignLecturerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header admin-modal">
                <h5 class="modal-title" id="assignLecturerModalLabel">
                    <i class="fas fa-user-plus me-2"></i>Assign Lecturer
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="assignLecturerForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($coursesCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="assign_lecturer">
                <input type="hidden" name="course_code" id="assignCourseCode">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted">Selected Course</label>
                        <div class="form-control-plaintext fw-bold" id="assignCourseLabel">-</div>
                    </div>
                    <div class="mb-0">
                        <label for="assignStaffId" class="form-label">Lecturer</label>
                        <select class="form-select" id="assignStaffId" name="staff_id" required>
                            <option value="">Select lecturer</option>
                            <?php foreach ($deptLecturers as $lecturer): ?>
                                <option value="<?php echo htmlspecialchars($lecturer['staff_id']); ?>">
                                    <?php echo htmlspecialchars($lecturer['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">
                        <i class="fas fa-save me-1"></i>Save Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const searchInput = document.getElementById('courseSearch');
    const filterProgram = document.getElementById('filterProgram');
    const filterSemester = document.getElementById('filterSemester');
    const filterAssignment = document.getElementById('filterAssignment');
    const clearFilters = document.getElementById('clearCourseFilters');
    const rows = Array.from(document.querySelectorAll('#coursesTable tbody .course-row'));
    const visibleCount = document.getElementById('visibleCourseCount');
    const unassignedCounter = document.getElementById('unassignedCounter');

    function applyFilters() {
        const q = (searchInput ? searchInput.value : '').toLowerCase().trim();
        const selectedProgram = (filterProgram ? filterProgram.value : '').toLowerCase().trim();
        const selectedSemester = (filterSemester ? filterSemester.value : '').toLowerCase().trim();
        const selectedAssignment = (filterAssignment ? filterAssignment.value : '').toLowerCase().trim();

        let shown = 0;
        let shownUnassigned = 0;

        rows.forEach(function (row) {
            const haystack = row.getAttribute('data-search') || '';
            const programs = row.getAttribute('data-programs') || '';
            const semesters = row.getAttribute('data-semesters') || '';
            const assigned = row.getAttribute('data-assigned') || '';

            const matchSearch = !q || haystack.indexOf(q) !== -1;
            const matchProgram = !selectedProgram || programs.indexOf(selectedProgram) !== -1;
            const matchSemester = !selectedSemester || semesters.indexOf(selectedSemester) !== -1;
            const matchAssignment = !selectedAssignment || assigned === selectedAssignment;

            const visible = matchSearch && matchProgram && matchSemester && matchAssignment;
            row.style.display = visible ? '' : 'none';
            if (visible) {
                shown++;
                if (assigned === 'unassigned') {
                    shownUnassigned++;
                }
            }
        });

        if (visibleCount) {
            visibleCount.textContent = String(shown);
        }
        if (unassignedCounter) {
            unassignedCounter.textContent = String(shownUnassigned);
        }
    }

    [searchInput, filterProgram, filterSemester, filterAssignment].forEach(function (el) {
        if (el) {
            el.addEventListener('input', applyFilters);
            el.addEventListener('change', applyFilters);
        }
    });

    if (clearFilters) {
        clearFilters.addEventListener('click', function () {
            if (searchInput) searchInput.value = '';
            if (filterProgram) filterProgram.value = '';
            if (filterSemester) filterSemester.value = '';
            if (filterAssignment) filterAssignment.value = '';
            applyFilters();
        });
    }

    const assignModalEl = document.getElementById('assignLecturerModal');
    const assignCourseCode = document.getElementById('assignCourseCode');
    const assignCourseLabel = document.getElementById('assignCourseLabel');
    let assignModal = null;
    if (assignModalEl && typeof bootstrap !== 'undefined') {
        assignModal = new bootstrap.Modal(assignModalEl);
    }

    document.querySelectorAll('.js-open-assign-modal').forEach(function (button) {
        button.addEventListener('click', function () {
            const courseCode = button.getAttribute('data-course-code') || '';
            const courseName = button.getAttribute('data-course-name') || '';
            if (assignCourseCode) {
                assignCourseCode.value = courseCode;
            }
            if (assignCourseLabel) {
                assignCourseLabel.textContent = courseCode + ' - ' + courseName;
            }
            if (assignModal) {
                assignModal.show();
            }
        });
    });

    document.querySelectorAll('.js-remove-assignment-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            const ok = window.confirm('Remove this lecturer assignment from the course?');
            if (!ok) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('.js-print-page').forEach(function (button) {
        button.addEventListener('click', function () {
            window.print();
        });
    });

    applyFilters();
})();
</script>

<?php require "includes/footer.php"; ?>
