<?php
/**
 * E-Learning Access Control Helper
 * Provides centralized access control for e-learning features
 * ensuring content is only visible to appropriate lecturers and students
 */

// Conditional definition block: a bare second require must not redeclare functions.
// A top-level `return` after define is not enough — PHP still compiles function
// declarations on each include before runtime return runs.
if (!defined('WUC_ELEARNING_ACCESS_LOADED')) {
define('WUC_ELEARNING_ACCESS_LOADED', true);

require_once __DIR__ . '/permissions.php';

function elearningTableExists(mysqli $db, string $tableName): bool {
    $safe = $db->real_escape_string($tableName);
    $result = $db->query("SHOW FULL TABLES LIKE '{$safe}'");
    if (!$result) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function elearningTableColumns(mysqli $db, string $tableName): array {
    static $cache = [];
    $key = strtolower($tableName);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $columns = [];
    if ($result = $db->query("SHOW COLUMNS FROM `{$tableName}`")) {
        while ($row = $result->fetch_assoc()) {
            $columns[strtolower((string) $row['Field'])] = (string) $row['Field'];
        }
        $result->free();
    }

    $cache[$key] = $columns;
    return $columns;
}

function elearningDetectColumn(mysqli $db, string $tableName, array $candidates): ?string {
    $columns = elearningTableColumns($db, $tableName);
    foreach ($candidates as $candidate) {
        $key = strtolower($candidate);
        if (isset($columns[$key])) {
            return $columns[$key];
        }
    }
    return null;
}

function elearningActiveStatusSql(?string $statusCol, string $tableAlias = ''): string {
    if ($statusCol === null) {
        return '';
    }
    $prefix = $tableAlias !== '' ? trim($tableAlias, '`.') . '.' : '';
    return " AND ({$prefix}`{$statusCol}` IS NULL OR TRIM({$prefix}`{$statusCol}`) = '' OR LOWER(TRIM({$prefix}`{$statusCol}`)) IN ('active', 'assigned', 'current'))";
}

function elearningActiveEnrollmentSql(array $cols): string {
    $clauses = [];
    if (isset($cols['is_active'])) {
        $clauses[] = "COALESCE(`{$cols['is_active']}`, 1) = 1";
    }
    if (isset($cols['status'])) {
        $clauses[] = "(`{$cols['status']}` IS NULL OR TRIM(`{$cols['status']}`) = '' OR LOWER(TRIM(`{$cols['status']}`)) IN ('active', 'registered', 'current'))";
    }
    return $clauses ? ' AND ' . implode(' AND ', $clauses) : '';
}

function elearningOfferingStudentTablesReady(mysqli $db): bool {
    foreach (['student_course_registrations', 'student_program', 'course_offerings', 'curriculum_courses'] as $tableName) {
        if (!elearningTableExists($db, $tableName)) {
            return false;
        }
    }
    return true;
}

/**
 * Restrict normalized offering registrations to the student's current
 * programme/curriculum assignment. Legacy migrations can leave historical
 * offerings attached to the same student_program row; those must not grant
 * current dashboard or eLearning access.
 */
function elearningCurrentStudentProgrammeSql(): string {
    return " AND (sp.status IS NULL OR TRIM(sp.status) = '' OR LOWER(TRIM(sp.status)) IN ('active', 'current'))
             AND (sp.curriculum_version_id IS NULL OR sp.curriculum_version_id = cc.curriculum_version_id)
             AND (co.program_code IS NULL OR TRIM(co.program_code) = '' OR co.program_code = sp.program_code)";
}

function elearningOfferingLecturerTablesReady(mysqli $db): bool {
    foreach (['lecturer_course_assignments', 'course_offerings', 'curriculum_courses'] as $tableName) {
        if (!elearningTableExists($db, $tableName)) {
            return false;
        }
    }
    return true;
}

function elearningTableHasCourseOffering(mysqli $db, string $tableName): bool {
    $columns = elearningTableColumns($db, $tableName);
    return isset($columns['course_offering_id']);
}

function getStudentCourseOfferingIds($db, $studentId, ?string $courseCode = null): array {
    if (empty($studentId) || !elearningOfferingStudentTablesReady($db)) {
        return [];
    }

    $ids = [];
    $courseSql = '';
    if ($courseCode !== null && trim($courseCode) !== '') {
        $courseSql = ' AND UPPER(TRIM(cc.course_code)) = UPPER(TRIM(?))';
    }

    $sql = "SELECT DISTINCT co.id
              FROM student_course_registrations scr
              JOIN student_program sp ON sp.id = scr.student_programme_id
              JOIN course_offerings co ON co.id = scr.course_offering_id
              JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
             WHERE sp.Sid = ?
               {$courseSql}
               " . elearningCurrentStudentProgrammeSql() . "
               AND scr.registration_status IN ('REGISTERED','COMPLETED','REPEATING')
               AND co.status IN ('planned','active','completed')
             ORDER BY co.id";
    if ($stmt = $db->prepare($sql)) {
        if ($courseSql !== '') {
            $stmt->bind_param('ss', $studentId, $courseCode);
        } else {
            $stmt->bind_param('s', $studentId);
        }
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ids[] = (int) $row['id'];
            }
        }
        $stmt->close();
    }

    return array_values(array_unique(array_filter($ids, static fn($id) => $id > 0)));
}

function getLecturerCourseOfferingIds($db, $staffId, ?string $courseCode = null): array {
    if (empty($staffId) || !elearningOfferingLecturerTablesReady($db)) {
        return [];
    }

    $ids = [];
    $courseSql = '';
    if ($courseCode !== null && trim($courseCode) !== '') {
        $courseSql = ' AND UPPER(TRIM(cc.course_code)) = UPPER(TRIM(?))';
    }

    $sql = "SELECT DISTINCT co.id
              FROM lecturer_course_assignments lca
              JOIN course_offerings co ON co.id = lca.course_offering_id
              JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
             WHERE lca.staff_id = ?
               {$courseSql}
               AND lca.status = 'active'
               AND co.status IN ('planned','active','completed')
             ORDER BY co.id";
    if ($stmt = $db->prepare($sql)) {
        if ($courseSql !== '') {
            $stmt->bind_param('ss', $staffId, $courseCode);
        } else {
            $stmt->bind_param('s', $staffId);
        }
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ids[] = (int) $row['id'];
            }
        }
        $stmt->close();
    }

    return array_values(array_unique(array_filter($ids, static fn($id) => $id > 0)));
}

function getLecturerCourseOfferingId($db, $staffId, string $courseCode): ?int {
    $ids = getLecturerCourseOfferingIds($db, $staffId, $courseCode);
    return $ids[0] ?? null;
}

function getStudentCourseOfferingId($db, $studentId, string $courseCode): ?int {
    $ids = getStudentCourseOfferingIds($db, $studentId, $courseCode);
    return $ids[0] ?? null;
}

function elearningOfferingScopeCondition(mysqli $db, string $tableName, ?string $alias, array $offeringIds, string &$types, array &$params): string {
    if (!elearningTableHasCourseOffering($db, $tableName) || empty($offeringIds)) {
        return '';
    }

    $offeringIds = array_values(array_unique(array_filter(array_map('intval', $offeringIds), static fn($id) => $id > 0)));
    if (empty($offeringIds)) {
        return '';
    }

    $prefix = $alias !== null && trim($alias) !== '' ? trim($alias) . '.' : '';
    $placeholders = implode(',', array_fill(0, count($offeringIds), '?'));
    $types .= str_repeat('i', count($offeringIds));
    $params = array_merge($params, $offeringIds);

    return " AND ({$prefix}course_offering_id IN ($placeholders) OR {$prefix}course_offering_id IS NULL)";
}

/**
 * Check if a student is enrolled in a specific course
 * Uses multiple table sources for enrollment data
 * 
 * @param mysqli $db Database connection
 * @param string $studentId Student ID (Sid)
 * @param string $courseCode Course code
 * @return bool True if enrolled
 */
function isStudentEnrolledInCourse($db, $studentId, $courseCode) {
    if (empty($studentId) || empty($courseCode)) {
        return false;
    }

    if (elearningOfferingStudentTablesReady($db)) {
        $sql = "SELECT 1
                  FROM student_course_registrations scr
                  JOIN student_program sp ON sp.id = scr.student_programme_id
                  JOIN course_offerings co ON co.id = scr.course_offering_id
                  JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
                 WHERE sp.Sid = ?
                   AND UPPER(TRIM(cc.course_code)) = UPPER(TRIM(?))
                   " . elearningCurrentStudentProgrammeSql() . "
                   AND scr.registration_status IN ('REGISTERED','COMPLETED','REPEATING')
                   AND co.status IN ('planned','active','completed')
                 LIMIT 1";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('ss', $studentId, $courseCode);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $stmt->close();
                    return true;
                }
            }
            $stmt->close();
        }
    }
    
    // Tables that may contain enrollment data
    $tables = ['course_registration', 'registered_courses', 'student_courses'];
    
    foreach ($tables as $tableName) {
        // Check if table exists
        $tblCheck = $db->query("SHOW FULL TABLES LIKE '" . $db->real_escape_string($tableName) . "'");
        if (!$tblCheck || $tblCheck->num_rows === 0) {
            if ($tblCheck) { $tblCheck->free(); }
            continue;
        }
        $tblCheck->free();
        
        // Detect the student ID column name and course_code column
        $sidCol = 'Sid';
        $ccCol = 'course_code';
        $cols = [];
        if ($colsRes = $db->query("SHOW COLUMNS FROM `{$tableName}`")) {
            while ($c = $colsRes->fetch_assoc()) { 
                $cols[strtolower((string)$c['Field'])] = (string)$c['Field']; 
            }
            $colsRes->free();
            $sidCol = $cols['sid'] ?? ($cols['student_id'] ?? ($cols['student'] ?? 'Sid'));
            $ccCol = $cols['course_code'] ?? ($cols['code'] ?? 'course_code');
        }
        $activeSql = elearningActiveEnrollmentSql($cols);
        
        // Use case-insensitive compare on course_code to handle inconsistencies
        $sql = "SELECT 1 FROM `{$tableName}` WHERE `{$sidCol}`=? AND UPPER(TRIM(`{$ccCol}`))=UPPER(TRIM(?)){$activeSql} LIMIT 1";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('ss', $studentId, $courseCode);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $stmt->close();
                    return true;
                }
            }
            $stmt->close();
        }
    }
    
    return false;
}

/**
 * Get all courses a student is enrolled in
 * 
 * @param mysqli $db Database connection
 * @param string $studentId Student ID
 * @return array List of course codes
 */
function getStudentEnrolledCourses($db, $studentId) {
    if (empty($studentId)) {
        return [];
    }
    
    $courses = [];

    if (elearningOfferingStudentTablesReady($db)) {
        $sql = "SELECT DISTINCT TRIM(cc.course_code) AS course_code
                  FROM student_course_registrations scr
                  JOIN student_program sp ON sp.id = scr.student_programme_id
                  JOIN course_offerings co ON co.id = scr.course_offering_id
                  JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
                 WHERE sp.Sid = ?
                   " . elearningCurrentStudentProgrammeSql() . "
                   AND scr.registration_status IN ('REGISTERED','COMPLETED','REPEATING')
                   AND co.status IN ('planned','active','completed')
                 ORDER BY cc.course_code";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $studentId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    if ($row['course_code'] !== null && trim((string) $row['course_code']) !== '') {
                        $courses[] = trim((string) $row['course_code']);
                    }
                }
            }
            $stmt->close();
        }
        if (!empty($courses)) {
            return array_unique($courses);
        }
    }

    // course_registration is the canonical registration store. student_courses
    // is a legacy compatibility table and can contain only a partial period,
    // so consulting it first understates full-year programme enrolments.
    $tables = ['course_registration', 'student_courses', 'registered_courses'];
    
    foreach ($tables as $tableName) {
        $tblCheck = $db->query("SHOW FULL TABLES LIKE '" . $db->real_escape_string($tableName) . "'");
        if (!$tblCheck || $tblCheck->num_rows === 0) {
            if ($tblCheck) { $tblCheck->free(); }
            continue;
        }
        $tblCheck->free();
        
        $sidCol = 'Sid';
        $ccCol = 'course_code';
        $cols = [];
        if ($colsRes = $db->query("SHOW COLUMNS FROM `{$tableName}`")) {
            while ($c = $colsRes->fetch_assoc()) { 
                $cols[strtolower((string)$c['Field'])] = (string)$c['Field']; 
            }
            $colsRes->free();
            $sidCol = $cols['sid'] ?? ($cols['student_id'] ?? ($cols['student'] ?? 'Sid'));
            $ccCol = $cols['course_code'] ?? ($cols['code'] ?? 'course_code');
        }
        $activeSql = elearningActiveEnrollmentSql($cols);
        
        $sql = "SELECT DISTINCT `{$ccCol}` AS course_code FROM `{$tableName}` WHERE `{$sidCol}`=?{$activeSql}";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $studentId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $courses[] = $row['course_code'];
                }
            }
            $stmt->close();
            if (!empty($courses)) {
                break; // Found courses in this table, use this as source of truth
            }
        }
    }

    // 3. Short course enrolments
    if (function_exists('sc_student_enrolments')) {
        $scList = sc_student_enrolments($db, (string)$studentId);
        foreach ($scList as $sc) {
            $cCode = trim((string)($sc['course_code'] ?? ''));
            if ($cCode !== '') {
                $courses[] = $cCode;
            }
        }
    }

    return array_values(array_unique(array_filter($courses)));
}

/**
 * Check if a lecturer is assigned to a specific course
 * 
 * @param mysqli $db Database connection
 * @param string $staffId Staff ID
 * @param string $courseCode Course code
 * @return bool True if assigned
 */
function isLecturerAssignedToCourse($db, $staffId, $courseCode) {
    if (empty($staffId) || empty($courseCode)) {
        return false;
    }

    $assigned = getLecturerAssignedCourses($db, $staffId);
    $needle = strtoupper(trim((string)$courseCode));
    foreach ($assigned as $code) {
        if (strtoupper(trim((string)$code)) === $needle) {
            return true;
        }
    }
    return false;
}

/**
 * Get all courses assigned to a lecturer
 * 
 * @param mysqli $db Database connection
 * @param string $staffId Staff ID
 * @return array List of course codes
 */
function getLecturerAssignedCourses($db, $staffId) {
    if (empty($staffId)) {
        return [];
    }

    // Canonical resolver merges course_lecturer + orphan lecturer_courses.
    require_once __DIR__ . '/helpers/lecturer_course_helpers.php';
    if (function_exists('wuc_sync_legacy_lecturer_courses_table')) {
        wuc_sync_legacy_lecturer_courses_table($db, (string)$staffId);
    }
    $courses = wuc_lecturer_resolved_course_codes($db, (string)$staffId);

    if (elearningOfferingLecturerTablesReady($db)) {
        $sql = "SELECT DISTINCT TRIM(cc.course_code) AS course_code
                  FROM lecturer_course_assignments lca
                  JOIN course_offerings co ON co.id = lca.course_offering_id
                  JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
                 WHERE lca.staff_id = ?
                   AND lca.status = 'active'
                   AND co.status IN ('planned','active','completed')
                 ORDER BY cc.course_code";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if ($row['course_code'] !== null && trim((string) $row['course_code']) !== '') {
                    $courses[] = trim((string) $row['course_code']);
                }
            }
            $stmt->close();
        }
    }

    $courses = array_values(array_unique(array_filter(array_map('strval', $courses))));
    sort($courses);
    return $courses;
}

/**
 * Build a prepared IN(...) filter for a lecturer's assigned course codes.
 * Uses getLecturerAssignedCourses() so both course_lecturer and
 * lecturer_course_assignments sources are respected.
 *
 * @return array{clause: string, types: string, params: string[], has_access: bool}
 */
function elearningLecturerCourseInFilter(mysqli $db, string $staffId, string $courseSqlExpression): array
{
    if ($staffId === '') {
        return ['clause' => ' AND 1=0', 'types' => '', 'params' => [], 'has_access' => false];
    }

    $codes = getLecturerAssignedCourses($db, $staffId);
    if ($codes === []) {
        return ['clause' => ' AND 1=0', 'types' => '', 'params' => [], 'has_access' => false];
    }

    $placeholders = implode(',', array_fill(0, count($codes), 'UPPER(TRIM(?))'));

    return [
        'clause' => " AND UPPER(TRIM({$courseSqlExpression})) IN ({$placeholders})",
        'types' => str_repeat('s', count($codes)),
        'params' => array_values($codes),
        'has_access' => true,
    ];
}

/**
 * Get course details (code + name) for a lecturer's assigned courses.
 * Resolves names from courses table, then program_courses as fallback.
 * 
 * @param mysqli $db Database connection
 * @param string $staffId Staff ID
 * @return array List of ['course_code' => ..., 'course_name' => ...]
 */
function getLecturerCourseDetails($db, $staffId) {
    $codes = getLecturerAssignedCourses($db, $staffId);
    if (empty($codes)) return [];
    
    $details = [];
    $nameMap = [];
    
    // First try: look up from courses table
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));
    
    if (elearningTableExists($db, 'courses')) {
        $courseCodeCol = elearningDetectColumn($db, 'courses', ['course_code', 'code']);
        $courseNameCol = elearningDetectColumn($db, 'courses', ['course_name', 'name', 'title']);
        if ($courseCodeCol !== null && $courseNameCol !== null) {
            $sql = "SELECT `{$courseCodeCol}` AS course_code, `{$courseNameCol}` AS course_name FROM `courses` WHERE `{$courseCodeCol}` IN ($placeholders)";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param($types, ...$codes);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $nameMap[$row['course_code']] = $row['course_name'];
                }
                $stmt->close();
            }
        }
    }
    
    // Second try: look up missing names from program_courses
    $missing = array_diff($codes, array_keys($nameMap));
    if (!empty($missing)) {
        if (elearningTableExists($db, 'program_courses')) {
            $programCourseCodeCol = elearningDetectColumn($db, 'program_courses', ['course_code', 'code']);
            $programCourseNameCol = elearningDetectColumn($db, 'program_courses', ['course_name', 'name', 'title']);
            if ($programCourseCodeCol === null || $programCourseNameCol === null) {
                $programCourseCodeCol = null;
            }
        } else {
            $programCourseCodeCol = null;
        }

        if ($programCourseCodeCol !== null) {
            $ph2 = implode(',', array_fill(0, count($missing), '?'));
            $missingArr = array_values($missing);
            $types2 = str_repeat('s', count($missingArr));
            $sql2 = "SELECT DISTINCT `{$programCourseCodeCol}` AS course_code, `{$programCourseNameCol}` AS course_name FROM `program_courses` WHERE `{$programCourseCodeCol}` IN ($ph2) AND `{$programCourseNameCol}` IS NOT NULL AND `{$programCourseNameCol}` != ''";
            if ($stmt = $db->prepare($sql2)) {
                $stmt->bind_param($types2, ...$missingArr);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    if (!isset($nameMap[$row['course_code']])) {
                        $nameMap[$row['course_code']] = $row['course_name'];
                    }
                }
                $stmt->close();
            }
        }
    }
    
    // Build result array
    foreach ($codes as $code) {
        $details[] = [
            'course_code' => $code,
            'course_name' => $nameMap[$code] ?? $code,
        ];
    }
    
    return $details;
}

/**
 * Check if a lecturer can access e-learning for a specific course
 * Only lecturers assigned to the course can access it.
 * Admin-level access to all courses is handled via admin/elearning/ pages.
 * 
 * @param mysqli $db Database connection
 * @param string $staffId Staff ID
 * @param string $courseCode Course code
 * @return bool True if can access
 */
function canLecturerAccessElearningCourse($db, $staffId, $courseCode) {
    // Only allow access if lecturer is assigned to this specific course
    return isLecturerAssignedToCourse($db, $staffId, $courseCode);
}

/**
 * Check if a student can access e-learning content for a specific course
 * 
 * @param mysqli $db Database connection
 * @param string $studentId Student ID
 * @param string $courseCode Course code
 * @return bool True if can access
 */
function canStudentAccessElearningCourse($db, $studentId, $courseCode) {
    return isStudentEnrolledInCourse($db, $studentId, $courseCode);
}

/**
 * Enforce student course access - dies with error if not enrolled
 * 
 * @param mysqli $db Database connection
 * @param string $studentId Student ID
 * @param string $courseCode Course code
 */
function enforceStudentCourseAccess($db, $studentId, $courseCode) {
    if (!canStudentAccessElearningCourse($db, $studentId, $courseCode)) {
        die('<div style="font-family: Arial; padding: 40px; text-align: center;">
            <h3 style="color: #dc3545;"><i class="fas fa-lock"></i> Access Denied</h3>
            <p>You are not enrolled in this course.</p>
            <p>Course: <strong>' . htmlspecialchars($courseCode) . '</strong></p>
            <a href="index.php" style="color: #2196F3;">← Back to My Courses</a>
        </div>');
    }
}

/**
 * Enforce lecturer course access - dies with error if not assigned
 * 
 * @param mysqli $db Database connection
 * @param string $staffId Staff ID
 * @param string $courseCode Course code
 */
function enforceLecturerCourseAccess($db, $staffId, $courseCode) {
    if (!canLecturerAccessElearningCourse($db, $staffId, $courseCode)) {
        // Silently redirect back to courses list instead of showing access denied
        $coursesUrl = '/wucportal/elearning/courses.php';
        if (!headers_sent()) {
            header('Location: ' . $coursesUrl);
            exit;
        }
        // Fallback if headers already sent
        echo '<script>window.location.href=' . json_encode($coursesUrl) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($coursesUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
}

/**
 * Check if content should be visible based on release date
 * 
 * @param string|null $releaseAt Release datetime
 * @param string|null $closeAt Close datetime
 * @return bool True if visible
 */
function isContentReleased($releaseAt = null, $closeAt = null) {
    $now = time();
    
    if ($releaseAt !== null && strtotime($releaseAt) > $now) {
        return false; // Not yet released
    }
    
    if ($closeAt !== null && strtotime($closeAt) < $now) {
        return false; // Already closed
    }
    
    return true;
}

/**
 * Get user role type for e-learning access display
 * 
 * @param string|null $staffId Staff ID (if lecturer)
 * @param string|null $studentId Student ID (if student)
 * @return string Role type: 'admin', 'lecturer', 'student', or 'unknown'
 */
function getElearningUserRole($staffId = null, $studentId = null) {
    if ($staffId !== null) {
        if (hasPermission($staffId, 'admin_all') || hasPermission($staffId, 'elearn_admin_all')) {
            return 'admin';
        }
        return 'lecturer';
    }
    
    if ($studentId !== null) {
        return 'student';
    }
    
    return 'unknown';
}

/**
 * Get forum visibility based on user role and course enrollment
 * 
 * @param mysqli $db Database connection
 * @param string $courseCode Course code
 * @param string|null $staffId Staff ID
 * @param string|null $studentId Student ID
 * @return array ['can_view' => bool, 'can_post' => bool, 'can_moderate' => bool]
 */
function getForumPermissions($db, $courseCode, $staffId = null, $studentId = null) {
    $perms = [
        'can_view' => false,
        'can_post' => false,
        'can_moderate' => false,
        'can_create_thread' => false
    ];
    
    if ($staffId !== null) {
        // Staff access
        if (canLecturerAccessElearningCourse($db, $staffId, $courseCode)) {
            $perms['can_view'] = true;
            $perms['can_post'] = true;
            $perms['can_moderate'] = true;
            $perms['can_create_thread'] = true;
        }
    } elseif ($studentId !== null) {
        // Student access
        if (canStudentAccessElearningCourse($db, $studentId, $courseCode)) {
            $perms['can_view'] = true;
            $perms['can_post'] = true;
            $perms['can_create_thread'] = false; // Students can only reply, not create threads
        }
    }
    
    return $perms;
}

/**
 * Format access denied message for API responses
 * 
 * @param string $reason Reason for denial
 * @return array JSON-friendly error response
 */
function accessDeniedResponse($reason = 'Access denied') {
    return [
        'success' => false,
        'error' => $reason,
        'code' => 'ACCESS_DENIED'
    ];
}

} // WUC_ELEARNING_ACCESS_LOADED

