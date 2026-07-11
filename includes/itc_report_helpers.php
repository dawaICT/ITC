<?php
/**
 * Normalized ITC academic report helpers.
 *
 * Reports are generated from the TEVETA-aligned hierarchy and logged in
 * report_logs/audit_logs. No report data is duplicated.
 */

function itc_report_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function itc_report_column_exists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function itc_report_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function itc_report_fetch_options(mysqli $db): array
{
    $options = [
        'sections' => [],
        'departments' => [],
        'programs' => [],
        'academic_years' => [],
        'academic_periods' => [],
        'intakes' => [],
        'courses' => [],
        'lecturers' => [],
    ];

    $queries = [
        'sections' => "SELECT section_id AS id, section_name AS label FROM sections WHERE status = 'active' ORDER BY section_name",
        'departments' => "SELECT id, department_name AS label, section_id FROM departments WHERE COALESCE(status, 'active') = 'active' ORDER BY department_name",
        'programs' => "SELECT program_code AS id, program_name AS label, department_id FROM programs WHERE COALESCE(is_active, 1) = 1 ORDER BY program_name",
        'academic_years' => "SELECT id, academic_year_name AS label FROM academic_years ORDER BY academic_year_name DESC",
        'academic_periods' => "SELECT id, CONCAT(COALESCE(period_name, semester_term), ' (', period_type, ')') AS label, academic_year_id FROM academic_periods ORDER BY academic_year DESC, period_type, period_number",
        'intakes' => "SELECT id, intake_name AS label, academic_year_id FROM intakes ORDER BY intake_year DESC, intake_name",
        'courses' => "SELECT DISTINCT
                c.course_code AS id,
                CONCAT(c.course_code, ' - ', c.course_name) AS label,
                GROUP_CONCAT(DISTINCT d.id) AS department_ids,
                GROUP_CONCAT(DISTINCT d.section_id) AS section_ids
            FROM course_offerings co
            INNER JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
            INNER JOIN courses c ON c.course_code = cc.course_code
            INNER JOIN programs p ON p.program_code = co.program_code
            LEFT JOIN departments d ON d.id = p.department_id
            GROUP BY c.course_code, c.course_name
            ORDER BY c.course_code",
        'lecturers' => "SELECT DISTINCT
                s.staff_id AS id,
                CONCAT(s.staff_id, ' - ', COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, '')) AS label,
                GROUP_CONCAT(DISTINCT d.id) AS department_ids,
                GROUP_CONCAT(DISTINCT d.section_id) AS section_ids
            FROM lecturer_course_assignments lca
            INNER JOIN staff s ON s.staff_id = lca.staff_id
            INNER JOIN course_offerings co ON co.id = lca.course_offering_id
            INNER JOIN programs p ON p.program_code = co.program_code
            LEFT JOIN departments d ON d.id = p.department_id
            WHERE lca.status = 'active'
            GROUP BY s.staff_id, s.Fname, s.Lname
            ORDER BY s.Fname, s.Lname, s.staff_id",
    ];

    foreach ($queries as $key => $sql) {
        if ($res = $db->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $options[$key][] = $row;
            }
            $res->free();
        }
    }

    return $options;
}

function itc_report_resolve_scope(mysqli $db, string $staffId, bool $unrestricted = false): array
{
    $scope = [
        'unrestricted' => $unrestricted,
        'section_ids' => [],
        'department_ids' => [],
    ];
    if ($unrestricted || $staffId === '') {
        return $scope;
    }

    if (itc_report_table_exists($db, 'staff_section_assignments')) {
        $stmt = $db->prepare("SELECT DISTINCT section_id
                              FROM staff_section_assignments
                              WHERE staff_id = ? AND status = 'active'");
        if ($stmt) {
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $sectionId = trim((string)($row['section_id'] ?? ''));
                if ($sectionId !== '') {
                    $scope['section_ids'][] = $sectionId;
                }
            }
            $stmt->close();
        }
    }

    if (itc_report_table_exists($db, 'department_assignments')) {
        $stmt = $db->prepare("SELECT DISTINCT department_id
                              FROM department_assignments
                              WHERE staff_id = ? AND COALESCE(status, 'active') = 'active'");
        if ($stmt) {
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $departmentId = (int)($row['department_id'] ?? 0);
                if ($departmentId > 0) {
                    $scope['department_ids'][] = $departmentId;
                }
            }
            $stmt->close();
        }
    }

    $stmt = $db->prepare('SELECT deptId FROM staff WHERE staff_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $departmentId = (int)($row['deptId'] ?? 0);
        if ($departmentId > 0) {
            $scope['department_ids'][] = $departmentId;
        }
    }

    if ($scope['section_ids']) {
        $placeholders = implode(',', array_fill(0, count($scope['section_ids']), '?'));
        $types = str_repeat('s', count($scope['section_ids']));
        $stmt = $db->prepare("SELECT id FROM departments WHERE section_id IN ({$placeholders}) AND COALESCE(status, 'active') = 'active'");
        if ($stmt) {
            $stmt->bind_param($types, ...$scope['section_ids']);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $departmentId = (int)($row['id'] ?? 0);
                if ($departmentId > 0) {
                    $scope['department_ids'][] = $departmentId;
                }
            }
            $stmt->close();
        }
    }

    $scope['section_ids'] = array_values(array_unique($scope['section_ids']));
    $scope['department_ids'] = array_values(array_unique(array_map('intval', $scope['department_ids'])));
    sort($scope['section_ids']);
    sort($scope['department_ids']);

    return $scope;
}

function itc_report_filter_options_by_scope(array $options, array $scope): array
{
    if (!empty($scope['unrestricted'])) {
        return $options;
    }

    $sectionIds = array_flip(array_map('strval', $scope['section_ids'] ?? []));
    $departmentIds = array_flip(array_map('intval', $scope['department_ids'] ?? []));

    $options['sections'] = array_values(array_filter($options['sections'], static function (array $row) use ($sectionIds): bool {
        return isset($sectionIds[(string)($row['id'] ?? '')]);
    }));
    $options['departments'] = array_values(array_filter($options['departments'], static function (array $row) use ($departmentIds): bool {
        return isset($departmentIds[(int)($row['id'] ?? 0)]);
    }));
    $options['programs'] = array_values(array_filter($options['programs'], static function (array $row) use ($departmentIds): bool {
        return isset($departmentIds[(int)($row['department_id'] ?? 0)]);
    }));
    $matchesScope = static function (array $row) use ($sectionIds, $departmentIds): bool {
        $rowDepartmentIds = array_filter(array_map('intval', explode(',', (string)($row['department_ids'] ?? ''))));
        foreach ($rowDepartmentIds as $departmentId) {
            if (isset($departmentIds[$departmentId])) {
                return true;
            }
        }
        $rowSectionIds = array_filter(explode(',', (string)($row['section_ids'] ?? '')));
        foreach ($rowSectionIds as $sectionId) {
            if (isset($sectionIds[$sectionId])) {
                return true;
            }
        }
        return false;
    };
    $options['courses'] = array_values(array_filter($options['courses'], $matchesScope));
    $options['lecturers'] = array_values(array_filter($options['lecturers'], $matchesScope));

    return $options;
}

function itc_report_normalize_filters(array $source): array
{
    $filters = [
        'report_type' => strtoupper(trim((string)($source['report_type'] ?? 'DEPARTMENT_REPORT'))),
        'section_id' => trim((string)($source['section_id'] ?? '')),
        'department_id' => (int)($source['department_id'] ?? 0),
        'program_code' => strtoupper(trim((string)($source['program_code'] ?? ''))),
        'academic_year_id' => (int)($source['academic_year_id'] ?? 0),
        'academic_period_id' => (int)($source['academic_period_id'] ?? 0),
        'intake_id' => (int)($source['intake_id'] ?? 0),
        'course_code' => strtoupper(trim((string)($source['course_code'] ?? ''))),
        'level_number' => (int)($source['level_number'] ?? 0),
        'lecturer_id' => trim((string)($source['lecturer_id'] ?? '')),
        'student_id' => trim((string)($source['student_id'] ?? '')),
        'gender' => strtoupper(trim((string)($source['gender'] ?? ''))),
        'registration_status' => strtoupper(trim((string)($source['registration_status'] ?? ''))),
        'assessment_status' => strtoupper(trim((string)($source['assessment_status'] ?? ''))),
        'result_status' => strtoupper(trim((string)($source['result_status'] ?? ''))),
    ];

    $allowedReportTypes = [
        'SECTION_REPORT',
        'DEPARTMENT_REPORT',
        'PROGRAMME_REPORT',
        'LECTURER_WORKLOAD_REPORT',
        'CA_SUBMISSION_REPORT',
        'STUDENT_REGISTRATION_REPORT',
        'EXAMINATION_REPORT',
        'TRANSPORT_SECTION_REPORT',
        'ELEARNING_ACTIVITY_REPORT',
    ];
    if (!in_array($filters['report_type'], $allowedReportTypes, true)) {
        $filters['report_type'] = 'DEPARTMENT_REPORT';
    }

    if (!in_array($filters['gender'], ['', 'M', 'F'], true)) {
        $filters['gender'] = '';
    }

    $allowedRegistration = ['', 'REGISTERED', 'DROPPED', 'DEFERRED', 'COMPLETED', 'REPEATING'];
    if (!in_array($filters['registration_status'], $allowedRegistration, true)) {
        $filters['registration_status'] = '';
    }

    $allowedAssessment = ['', 'DRAFT', 'SUBMITTED', 'APPROVED_BY_HOD', 'LOCKED', 'RETURNED_FOR_CORRECTION'];
    if (!in_array($filters['assessment_status'], $allowedAssessment, true)) {
        $filters['assessment_status'] = '';
    }

    $allowedResult = ['', 'PASS', 'FAIL', 'DEFERRED', 'INCOMPLETE', 'EXEMPTED', 'REFERRED'];
    if (!in_array($filters['result_status'], $allowedResult, true)) {
        $filters['result_status'] = '';
    }

    return $filters;
}

function itc_report_add_in_filter(string $column, array $values, string $type, string &$types, array &$params): string
{
    $values = array_values(array_filter($values, static function ($value): bool {
        return $value !== '' && $value !== null && $value !== 0;
    }));
    if (!$values) {
        return '';
    }
    $types .= str_repeat($type, count($values));
    foreach ($values as $value) {
        $params[] = $type === 'i' ? (int)$value : (string)$value;
    }
    return $column . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
}

function itc_report_add_where(array $filters, string &$types, array &$params, array $scope = []): string
{
    $where = ["1=1"];

    $map = [
        'section_id' => ['sql' => 'd.section_id = ?', 'type' => 's'],
        'department_id' => ['sql' => 'd.id = ?', 'type' => 'i'],
        'program_code' => ['sql' => 'p.program_code = ?', 'type' => 's'],
        'academic_year_id' => ['sql' => 'co.academic_year_id = ?', 'type' => 'i'],
        'academic_period_id' => ['sql' => 'co.academic_period_id = ?', 'type' => 'i'],
        'intake_id' => ['sql' => 'co.intake_id = ?', 'type' => 'i'],
        'course_code' => ['sql' => 'cc.course_code = ?', 'type' => 's'],
        'level_number' => ['sql' => 'cc.level_number = ?', 'type' => 'i'],
        'lecturer_id' => ['sql' => 'lca.staff_id = ?', 'type' => 's'],
        'student_id' => ['sql' => 'sp.Sid = ?', 'type' => 's'],
        'gender' => ['sql' => 'st.sex = ?', 'type' => 's'],
        'registration_status' => ['sql' => 'scr.registration_status = ?', 'type' => 's'],
        'assessment_status' => ['sql' => 'sam.status = ?', 'type' => 's'],
        'result_status' => ['sql' => 'scrs.result_status = ?', 'type' => 's'],
    ];

    foreach ($map as $key => $rule) {
        $value = $filters[$key] ?? '';
        if ($value === '' || $value === 0) {
            continue;
        }
        $where[] = $rule['sql'];
        $types .= $rule['type'];
        $params[] = $value;
    }

    if ($filters['report_type'] === 'TRANSPORT_SECTION_REPORT') {
        $where[] = "d.section_id = 'TRANSPORT'";
    }

    if (!empty($scope) && empty($scope['unrestricted'])) {
        $scopeClauses = [];
        $sectionClause = itc_report_add_in_filter('d.section_id', $scope['section_ids'] ?? [], 's', $types, $params);
        if ($sectionClause !== '') {
            $scopeClauses[] = $sectionClause;
        }
        $departmentClause = itc_report_add_in_filter('d.id', $scope['department_ids'] ?? [], 'i', $types, $params);
        if ($departmentClause !== '') {
            $scopeClauses[] = $departmentClause;
        }
        $where[] = $scopeClauses ? '(' . implode(' OR ', $scopeClauses) . ')' : '1=0';
    }

    return implode(' AND ', $where);
}

function itc_report_base_from(): string
{
    return " FROM course_offerings co
             JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
             JOIN programs p ON p.program_code = co.program_code
             LEFT JOIN departments d ON d.id = p.department_id
             LEFT JOIN sections sec ON sec.section_id = d.section_id
             LEFT JOIN academic_years ay ON ay.id = co.academic_year_id
             LEFT JOIN academic_periods ap ON ap.id = co.academic_period_id
             LEFT JOIN intakes i ON i.id = co.intake_id
             LEFT JOIN student_course_registrations scr ON scr.course_offering_id = co.id
             LEFT JOIN student_program sp ON sp.id = scr.student_programme_id
             LEFT JOIN students st ON st.SID = sp.Sid
             LEFT JOIN lecturer_course_assignments lca ON lca.course_offering_id = co.id AND lca.status = 'active'
             LEFT JOIN staff sf ON sf.staff_id = lca.staff_id
             LEFT JOIN student_assessment_marks sam ON sam.student_course_registration_id = scr.id
             LEFT JOIN student_course_results scrs ON scrs.student_course_registration_id = scr.id";
}

function itc_report_query_all(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $rows = [];
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Report query prepare failed: ' . $db->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function itc_report_generate(mysqli $db, array $filters, array $scope = []): array
{
    $types = '';
    $params = [];
    $where = itc_report_add_where($filters, $types, $params, $scope);
    $base = itc_report_base_from();

    $summarySql = "SELECT
            COUNT(DISTINCT d.section_id) AS sections,
            COUNT(DISTINCT d.id) AS departments,
            COUNT(DISTINCT p.program_code) AS programmes,
            COUNT(DISTINCT co.id) AS course_offerings,
            COUNT(DISTINCT scr.id) AS registrations,
            COUNT(DISTINCT sp.Sid) AS students,
            COUNT(DISTINCT lca.staff_id) AS lecturers,
            COUNT(DISTINCT CASE WHEN sam.status IN ('SUBMITTED','APPROVED_BY_HOD','LOCKED') THEN sam.id END) AS submitted_ca_marks,
            COUNT(DISTINCT CASE WHEN sam.status = 'LOCKED' THEN sam.id END) AS locked_ca_marks,
            COUNT(DISTINCT scrs.id) AS result_rows,
            COUNT(DISTINCT CASE WHEN scrs.published_at IS NOT NULL THEN scrs.id END) AS published_results
        {$base}
        WHERE {$where}";
    $summary = itc_report_query_all($db, $summarySql, $types, $params)[0] ?? [];

    $departmentSql = "SELECT
            COALESCE(sec.section_name, 'Unassigned Section') AS section_name,
            COALESCE(d.department_name, 'Unassigned Department') AS department_name,
            COUNT(DISTINCT p.program_code) AS programmes,
            COUNT(DISTINCT co.id) AS offerings,
            COUNT(DISTINCT sp.Sid) AS students,
            COUNT(DISTINCT lca.staff_id) AS lecturers,
            COUNT(DISTINCT scrs.id) AS results
        {$base}
        WHERE {$where}
        GROUP BY sec.section_name, d.department_name
        ORDER BY sec.section_name, d.department_name";

    $programSql = "SELECT
            p.program_code,
            p.program_name,
            COALESCE(p.structure_type, 'UNSET') AS structure_type,
            COALESCE(d.department_name, 'Unassigned Department') AS department_name,
            COUNT(DISTINCT co.id) AS offerings,
            COUNT(DISTINCT scr.id) AS registrations,
            COUNT(DISTINCT sp.Sid) AS students,
            COUNT(DISTINCT lca.staff_id) AS lecturers
        {$base}
        WHERE {$where}
        GROUP BY p.program_code, p.program_name, p.structure_type, d.department_name
        ORDER BY d.department_name, p.program_name";

    $lecturerSql = "SELECT
            COALESCE(lca.staff_id, 'Unassigned') AS staff_id,
            TRIM(CONCAT(COALESCE(sf.Fname, ''), ' ', COALESCE(sf.Lname, ''))) AS lecturer_name,
            COUNT(DISTINCT co.id) AS offerings,
            COUNT(DISTINCT cc.course_code) AS courses,
            COUNT(DISTINCT scr.id) AS registered_students
        {$base}
        WHERE {$where}
        GROUP BY lca.staff_id, sf.Fname, sf.Lname
        ORDER BY lecturer_name, staff_id";

    $statusSql = "SELECT
            COALESCE(scr.registration_status, 'NO_REGISTRATION') AS status_label,
            COUNT(DISTINCT scr.id) AS total
        {$base}
        WHERE {$where}
        GROUP BY scr.registration_status
        ORDER BY status_label";

    $resultSql = "SELECT
            COALESCE(scrs.result_status, 'NO_RESULT') AS status_label,
            COUNT(DISTINCT scrs.id) AS total
        {$base}
        WHERE {$where}
        GROUP BY scrs.result_status
        ORDER BY status_label";

    $elearning = [];
    if (itc_report_table_exists($db, 'el_analytics_events')) {
        $elTypes = '';
        $elParams = [];
        $elWhere = itc_report_add_where($filters, $elTypes, $elParams, $scope);
        $elearningSql = "SELECT
                e.event_type,
                COUNT(DISTINCT e.id) AS total_events,
                COUNT(DISTINCT e.actor_id) AS active_users
            FROM el_analytics_events e
            LEFT JOIN course_offerings co ON co.id = e.course_offering_id
            LEFT JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
            LEFT JOIN programs p ON p.program_code = co.program_code
            LEFT JOIN departments d ON d.id = p.department_id
            LEFT JOIN sections sec ON sec.section_id = d.section_id
            LEFT JOIN academic_years ay ON ay.id = co.academic_year_id
            LEFT JOIN academic_periods ap ON ap.id = co.academic_period_id
            LEFT JOIN intakes i ON i.id = co.intake_id
            LEFT JOIN student_course_registrations scr ON scr.course_offering_id = co.id
            LEFT JOIN student_program sp ON sp.id = scr.student_programme_id
            LEFT JOIN students st ON st.SID = sp.Sid
            LEFT JOIN lecturer_course_assignments lca ON lca.course_offering_id = co.id AND lca.status = 'active'
            LEFT JOIN student_assessment_marks sam ON sam.student_course_registration_id = scr.id
            LEFT JOIN student_course_results scrs ON scrs.student_course_registration_id = scr.id
            WHERE {$elWhere}
            GROUP BY e.event_type
            ORDER BY total_events DESC, e.event_type";
        $elearning = itc_report_query_all($db, $elearningSql, $elTypes, $elParams);
    }

    return [
        'summary' => $summary,
        'departments' => itc_report_query_all($db, $departmentSql, $types, $params),
        'programs' => itc_report_query_all($db, $programSql, $types, $params),
        'lecturers' => itc_report_query_all($db, $lecturerSql, $types, $params),
        'registration_statuses' => itc_report_query_all($db, $statusSql, $types, $params),
        'result_statuses' => itc_report_query_all($db, $resultSql, $types, $params),
        'elearning_activity' => $elearning,
    ];
}

function itc_report_export_rows(array $report): array
{
    $rows = [];
    foreach ($report['departments'] ?? [] as $row) {
        $rows[] = [
            'section' => 'Department Summary',
            'item' => (string)($row['department_name'] ?? ''),
            'parent' => (string)($row['section_name'] ?? ''),
            'metric_a' => (string)($row['programmes'] ?? 0),
            'metric_b' => (string)($row['students'] ?? 0),
            'metric_c' => (string)($row['results'] ?? 0),
        ];
    }
    foreach ($report['programs'] ?? [] as $row) {
        $rows[] = [
            'section' => 'Programme Breakdown',
            'item' => (string)($row['program_code'] ?? ''),
            'parent' => (string)($row['department_name'] ?? ''),
            'metric_a' => (string)($row['program_name'] ?? ''),
            'metric_b' => (string)($row['structure_type'] ?? ''),
            'metric_c' => (string)($row['offerings'] ?? 0),
        ];
    }
    foreach ($report['lecturers'] ?? [] as $row) {
        $rows[] = [
            'section' => 'Lecturer Workload',
            'item' => (string)($row['staff_id'] ?? ''),
            'parent' => (string)($row['lecturer_name'] ?? ''),
            'metric_a' => (string)($row['offerings'] ?? 0),
            'metric_b' => (string)($row['courses'] ?? 0),
            'metric_c' => (string)($row['registered_students'] ?? 0),
        ];
    }
    foreach ($report['registration_statuses'] ?? [] as $row) {
        $rows[] = [
            'section' => 'Registration Status',
            'item' => (string)($row['status_label'] ?? ''),
            'parent' => '',
            'metric_a' => (string)($row['total'] ?? 0),
            'metric_b' => '',
            'metric_c' => '',
        ];
    }
    foreach ($report['result_statuses'] ?? [] as $row) {
        $rows[] = [
            'section' => 'Result Status',
            'item' => (string)($row['status_label'] ?? ''),
            'parent' => '',
            'metric_a' => (string)($row['total'] ?? 0),
            'metric_b' => '',
            'metric_c' => '',
        ];
    }
    foreach ($report['elearning_activity'] ?? [] as $row) {
        $rows[] = [
            'section' => 'eLearning Activity',
            'item' => (string)($row['event_type'] ?? ''),
            'parent' => '',
            'metric_a' => (string)($row['total_events'] ?? 0),
            'metric_b' => (string)($row['active_users'] ?? 0),
            'metric_c' => '',
        ];
    }
    return $rows;
}

function itc_report_log_generation(mysqli $db, array $filters, string $generatedBy, array $scope = [], string $action = 'Report generated'): ?int
{
    $logId = null;
    if (itc_report_table_exists($db, 'report_logs')) {
        $hasFilterSnapshot = itc_report_column_exists($db, 'report_logs', 'filter_snapshot_json');
        $hasScopeSnapshot = itc_report_column_exists($db, 'report_logs', 'scope_snapshot_json');
        $insertColumns = 'report_type, generated_by, section_id, department_id, programme_id, academic_year_id, academic_period_id';
        $insertValues = '?, ?, ?, ?, ?, ?, ?';
        $bindTypes = 'sssisii';

        $filterSnapshot = json_encode($filters, JSON_UNESCAPED_SLASHES);
        $scopeSnapshot = json_encode($scope, JSON_UNESCAPED_SLASHES);
        if ($hasFilterSnapshot) {
            $insertColumns .= ', filter_snapshot_json';
            $insertValues .= ', ?';
            $bindTypes .= 's';
        }
        if ($hasScopeSnapshot) {
            $insertColumns .= ', scope_snapshot_json';
            $insertValues .= ', ?';
            $bindTypes .= 's';
        }

        $stmt = $db->prepare("INSERT INTO report_logs
                ({$insertColumns}, generated_at, created_at)
                VALUES ({$insertValues}, NOW(), NOW())");
        if ($stmt) {
            $sectionId = $filters['section_id'] !== '' ? $filters['section_id'] : null;
            $departmentId = $filters['department_id'] > 0 ? $filters['department_id'] : null;
            $programmeId = $filters['program_code'] !== '' ? $filters['program_code'] : null;
            $yearId = $filters['academic_year_id'] > 0 ? $filters['academic_year_id'] : null;
            $periodId = $filters['academic_period_id'] > 0 ? $filters['academic_period_id'] : null;
            $bindValues = [
                $filters['report_type'],
                $generatedBy,
                $sectionId,
                $departmentId,
                $programmeId,
                $yearId,
                $periodId,
            ];
            if ($hasFilterSnapshot) {
                $bindValues[] = $filterSnapshot;
            }
            if ($hasScopeSnapshot) {
                $bindValues[] = $scopeSnapshot;
            }
            $stmt->bind_param($bindTypes, ...$bindValues);
            if ($stmt->execute()) {
                $logId = (int)$stmt->insert_id;
            }
            $stmt->close();
        }
    }

    if (itc_report_table_exists($db, 'audit_logs')) {
        $module = 'reports';
        $recordId = $logId !== null ? (string)$logId : null;
        $newValue = json_encode(['filters' => $filters, 'scope' => $scope], JSON_UNESCAPED_SLASHES);
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, old_value, new_value, ip_address, user_agent)
                              VALUES (?, ?, ?, ?, NULL, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('sssssss', $generatedBy, $action, $module, $recordId, $newValue, $ip, $ua);
            $stmt->execute();
            $stmt->close();
        }
    }

    return $logId;
}
