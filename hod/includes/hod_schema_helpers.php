<?php

require_once dirname(__DIR__, 2) . '/includes/hos_section_helpers.php';

if (!function_exists('hod_bind_params')) {
    function hod_bind_params(mysqli_stmt $stmt, string $types, array $params): void
    {
        if ($types === '') {
            return;
        }
        $refs = [$types];
        foreach ($params as $idx => &$value) {
            $refs[] = &$params[$idx];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

if (!function_exists('hod_query_scalar')) {
    function hod_query_scalar(mysqli $db, string $sql, string $types = '', array $params = [], $default = 0)
    {
        try {
            if ($types === '' && $params === []) {
                if ($res = $db->query($sql)) {
                    $row = $res->fetch_row();
                    $res->free();
                    return $row ? $row[0] : $default;
                }
                return $default;
            }
            if (!$stmt = $db->prepare($sql)) {
                return $default;
            }
            hod_bind_params($stmt, $types, $params);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            return $row ? $row[0] : $default;
        } catch (Throwable $e) {
            error_log('hod_query_scalar failed: ' . $e->getMessage());
            return $default;
        }
    }
}

if (!function_exists('hod_query_rows')) {
    function hod_query_rows(mysqli $db, string $sql, string $types = '', array $params = []): array
    {
        $out = [];
        try {
            if ($types === '' && $params === []) {
                if ($res = $db->query($sql)) {
                    while ($row = $res->fetch_assoc()) {
                        $out[] = $row;
                    }
                    $res->free();
                }
                return $out;
            }
            if (!$stmt = $db->prepare($sql)) {
                return $out;
            }
            hod_bind_params($stmt, $types, $params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $out[] = $row;
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('hod_query_rows failed: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('hod_department_id_placeholders')) {
    /**
     * @param int[] $departmentIds
     * @return array{clause:string,types:string,params:array<int,int>}
     */
    function hod_department_id_placeholders(array $departmentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $departmentIds))));
        if ($ids === []) {
            return ['clause' => '0', 'types' => '', 'params' => []];
        }
        return [
            'clause' => implode(',', array_fill(0, count($ids), '?')),
            'types' => str_repeat('i', count($ids)),
            'params' => $ids,
        ];
    }
}

if (!function_exists('hod_table_exists')) {
    function hod_table_exists(mysqli $db, string $table): bool {
        if ($res = @$db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'")) {
            $exists = $res->num_rows > 0;
            $res->free();
            return $exists;
        }
        return false;
    }
}

if (!function_exists('hod_detect_column')) {
    function hod_detect_column(mysqli $db, string $table, array $candidates): ?string {
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
    }
}

if (!function_exists('hod_resolve_department')) {
    function hod_resolve_department(mysqli $db, string $staffId): array {
        $result = [
            'id' => '',
            'name' => '',
            'section_id' => '',
            'section_type' => '',
            'candidates' => [],
        ];

        if ($staffId === '' || !hod_table_exists($db, 'departments')) {
            return $result;
        }

        $sections = hos_hydrate_section_session($db, $staffId);
        if (!empty($sections)) {
            $primary = $sections[0];
            $sectionId = trim((string)($primary['section_id'] ?? ''));
            $result['section_id'] = $sectionId;
            $result['name'] = trim((string)($primary['section_name'] ?? ''));
            $result['section_type'] = hos_normalize_section_type(
                (string)($primary['section_type'] ?? ''),
                (string)($primary['section_name'] ?? ''),
                $sectionId
            );

            // Fetch all departments linked to this section!
            $stmt = @$db->prepare("SELECT id FROM departments WHERE section_id = ? AND status = 'active'");
            if ($stmt) {
                $stmt->bind_param('s', $sectionId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $result['candidates'][] = $row['id'];
                }
                $stmt->close();
            }

            if (!empty($result['candidates'])) {
                $result['id'] = $result['candidates'][0];
                $_SESSION['dept_id'] = $result['id'];
            }
            $_SESSION['hos_section_id'] = $result['section_id'];
            $_SESSION['hos_section_name'] = $result['name'];
            $_SESSION['hos_section_type'] = $result['section_type'];
            return $result;
        }

        $deptIdCol = hod_detect_column($db, 'departments', ['department_id', 'id', 'DeptID']);
        $deptNameCol = hod_detect_column($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']);
        $deptHodCol = hod_detect_column($db, 'departments', ['hod_id', 'HODID', 'hodId']);

        if ($deptIdCol && $deptHodCol) {
            $deptFacultyCol = hod_detect_column($db, 'departments', ['faculty', 'Faculty']);
            $nameSelect = $deptNameCol ? "`{$deptNameCol}` AS department_name" : "'' AS department_name";
            $facultySelect = $deptFacultyCol ? "`{$deptFacultyCol}` AS faculty" : "'' AS faculty";
            $stmt = @$db->prepare("
                SELECT `{$deptIdCol}` AS department_id, {$nameSelect}, {$facultySelect}
                FROM departments
                WHERE CAST(`{$deptHodCol}` AS CHAR) = CAST(? AS CHAR)
                   OR CAST(`{$deptHodCol}` AS CHAR) = (
                       SELECT CAST(id AS CHAR) FROM staff WHERE staff_id = ? LIMIT 1
                   )
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('ss', $staffId, $staffId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $result['id'] = trim((string)($row['department_id'] ?? ''));
                    $result['name'] = trim((string)($row['department_name'] ?? ''));
                    $result['section_type'] = hos_normalize_section_type('', $result['name'], $result['id'], (string)($row['faculty'] ?? ''));
                }
                $stmt->close();
            }
        }

        if ($result['id'] === '' && hod_table_exists($db, 'staff')) {
            $staffDeptCol = hod_detect_column($db, 'staff', ['department_id', 'DeptID', 'deptId']);
            if ($staffDeptCol) {
                $stmt = @$db->prepare("SELECT `{$staffDeptCol}` AS department_id FROM staff WHERE staff_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $staffId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && ($row = $res->fetch_assoc())) {
                        $result['id'] = trim((string)($row['department_id'] ?? ''));
                    }
                    $stmt->close();
                }
            }
        }

        if ($result['id'] !== '') {
            $result['candidates'][] = $result['id'];
            $_SESSION['dept_id'] = $result['id'];
        }

        if ($result['name'] === '' && $result['id'] !== '' && $deptIdCol && $deptNameCol) {
            $stmt = @$db->prepare("SELECT `{$deptNameCol}` AS department_name FROM departments WHERE CAST(`{$deptIdCol}` AS CHAR) = CAST(? AS CHAR) LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $result['id']);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $result['name'] = trim((string)($row['department_name'] ?? ''));
                }
                $stmt->close();
            }
        }

        $result['candidates'] = array_values(array_unique(array_filter($result['candidates'])));
        return $result;
    }
}

if (!function_exists('hod_section_department_ids')) {
    /**
     * All department ids covered by the HOS's section. A section spans several
     * departments (departments.section_id), so scoping to $deptContext['id']
     * alone silently drops every department after the first.
     *
     * @return string[]
     */
    function hod_section_department_ids(array $deptContext): array
    {
        $ids = [];
        foreach ((array)($deptContext['candidates'] ?? []) as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $primary = trim((string)($deptContext['id'] ?? ''));
        if ($primary !== '') {
            $ids[] = $primary;
        }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('hod_section_program_codes')) {
    /** @return string[] Program codes owned by any department in the section. */
    function hod_section_program_codes(mysqli $db, array $deptContext): array
    {
        $deptIds = hod_section_department_ids($deptContext);
        if ($deptIds === [] || !hod_table_exists($db, 'programs')) {
            return [];
        }
        $progDeptCol = hod_detect_column($db, 'programs', ['department_id', 'deptId', 'DeptID', 'department_code', 'dept_code']);
        if (!$progDeptCol) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($deptIds), '?'));
        $codes = [];
        $stmt = @$db->prepare("SELECT DISTINCT program_code FROM programs WHERE CAST(`{$progDeptCol}` AS CHAR) IN ({$placeholders})");
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($deptIds)), ...$deptIds);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $code = trim((string)($row['program_code'] ?? ''));
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
            $stmt->close();
        }
        return array_values(array_unique($codes));
    }
}

if (!function_exists('hod_section_course_codes')) {
    /** @return string[] Course codes across every department in the section. */
    function hod_section_course_codes(mysqli $db, array $deptContext, string $staffId = ''): array
    {
        $codes = [];
        foreach (hod_section_department_ids($deptContext) as $deptId) {
            $codes = array_merge($codes, hod_department_course_codes($db, $deptId));
        }
        if ($staffId !== '') {
            $codes = array_merge($codes, hod_department_course_codes($db, '', $staffId));
        }
        return array_values(array_unique(array_filter($codes)));
    }
}

if (!function_exists('hod_student_in_section')) {
    /** Whether a student is enrolled in any programme owned by the section. */
    function hod_student_in_section(mysqli $db, array $deptContext, string $sid): bool
    {
        $sid = trim($sid);
        if ($sid === '') {
            return false;
        }
        $programCodes = hod_section_program_codes($db, $deptContext);
        if ($programCodes === [] || !hod_table_exists($db, 'student_program')) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($programCodes), '?'));
        $stmt = @$db->prepare("SELECT 1 FROM student_program WHERE Sid = ? AND program_code IN ({$placeholders}) LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $params = array_merge([$sid], $programCodes);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $found = $res && $res->num_rows > 0;
        $stmt->close();
        return $found;
    }
}

if (!function_exists('hod_require_hos_api_access')) {
    /**
     * Guard for lean AJAX/API handlers that do not include nav.php: the session
     * must belong to a Head of Section (or systems admin). Throws on failure so
     * the caller's JSON error path handles the response.
     */
    function hod_require_hos_api_access(): void
    {
        if (!isset($_SESSION['staff_id'])) {
            throw new Exception('Unauthorized', 401);
        }
        require_once dirname(__DIR__, 2) . '/includes/staff_role_helpers.php';
        $roles = array_merge(
            [(string)($_SESSION['role'] ?? '')],
            array_map('strval', (array)($_SESSION['all_roles'] ?? []))
        );
        foreach ($roles as $role) {
            $normalized = function_exists('wuc_normalize_staff_role')
                ? wuc_normalize_staff_role($role, false)
                : strtolower(trim($role));
            if (in_array($normalized, ['head_of_department', 'systems_admin'], true)) {
                return;
            }
        }
        throw new Exception('Forbidden: Head of Section role required', 403);
    }
}

if (!function_exists('hod_department_course_codes')) {
    function hod_department_course_codes(mysqli $db, string $departmentId, string $staffId = ''): array {
        $codes = [];

        if ($departmentId !== '' && hod_table_exists($db, 'programs') && hod_table_exists($db, 'program_courses')) {
            $progDeptCol = hod_detect_column($db, 'programs', ['department_id', 'DeptID', 'deptId', 'department_code', 'dept_code']);
            $pcProgramCol = hod_detect_column($db, 'program_courses', ['program_code', 'programId', 'program']);
            $pcCourseCol = hod_detect_column($db, 'program_courses', ['course_code', 'courseId']);
            if ($progDeptCol && $pcProgramCol && $pcCourseCol) {
                $stmt = @$db->prepare("
                    SELECT DISTINCT pc.`{$pcCourseCol}` AS course_code
                    FROM program_courses pc
                    INNER JOIN programs p ON p.program_code = pc.`{$pcProgramCol}`
                    WHERE CAST(p.`{$progDeptCol}` AS CHAR) = CAST(? AS CHAR)
                ");
                if ($stmt) {
                    $stmt->bind_param('s', $departmentId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($res && ($row = $res->fetch_assoc())) {
                        $code = trim((string)($row['course_code'] ?? ''));
                        if ($code !== '') {
                            $codes[] = $code;
                        }
                    }
                    $stmt->close();
                }
            }
        }

        if ($staffId !== '' && hod_table_exists($db, 'course_lecturer')) {
            $stmt = @$db->prepare("SELECT DISTINCT course_code FROM course_lecturer WHERE staff_id = ? AND course_code IS NOT NULL AND course_code <> ''");
            if ($stmt) {
                $stmt->bind_param('s', $staffId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $code = trim((string)($row['course_code'] ?? ''));
                    if ($code !== '') {
                        $codes[] = $code;
                    }
                }
                $stmt->close();
            }
        }

        return array_values(array_unique(array_filter($codes)));
    }
}
