<?php
require_once __DIR__ . '/staff_role_helpers.php';

if (!function_exists('hos_table_exists')) {
    function hos_table_exists(mysqli $db, string $table): bool
    {
        $escaped = $db->real_escape_string($table);
        $res = $db->query("SHOW TABLES LIKE '{$escaped}'");
        if (!$res) {
            return false;
        }
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
}

if (!function_exists('hos_column_exists')) {
    function hos_column_exists(mysqli $db, string $table, string $column): bool
    {
        if (!hos_table_exists($db, $table)) {
            return false;
        }
        $escaped = $db->real_escape_string($column);
        $res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$escaped}'");
        if (!$res) {
            return false;
        }
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
}

if (!function_exists('hos_normalize_section_type')) {
    function hos_normalize_section_type(?string $type, string $name = '', string $id = '', string $faculty = ''): string
    {
        $typeValue = strtolower(trim((string)$type));
        $haystack = strtolower(trim($id . ' ' . $name . ' ' . $faculty . ' ' . $typeValue));

        foreach (['transport', 'fleet', 'driver training', 'driving school', 'rtsa', 'vehicle'] as $needle) {
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                return 'transport';
            }
        }

        foreach (['engineering', 'ict', 'information technology', 'information communication', 'academic'] as $needle) {
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                return 'academic';
            }
        }

        if ($typeValue !== '') {
            return preg_replace('/[^a-z0-9_ -]/', '', $typeValue) ?: 'academic';
        }

        return 'academic';
    }
}

if (!function_exists('hos_is_transport_section')) {
    function hos_is_transport_section($section): bool
    {
        if (is_array($section)) {
            return hos_normalize_section_type(
                (string)($section['section_type'] ?? ''),
                (string)($section['section_name'] ?? ''),
                (string)($section['section_id'] ?? ''),
                (string)($section['faculty'] ?? '')
            ) === 'transport';
        }

        return hos_normalize_section_type('', (string)$section, (string)$section) === 'transport';
    }
}

if (!function_exists('hos_get_staff_sections')) {
    function hos_get_staff_sections(mysqli $db, string $staffId): array
    {
        $staffId = trim($staffId);
        if ($staffId === '') {
            return [];
        }

        $sections = [];
        if (hos_table_exists($db, 'sections') && hos_table_exists($db, 'staff_section_assignments')) {
            $stmt = $db->prepare("
                SELECT
                    s.section_id,
                    s.section_name,
                    s.section_type,
                    s.department_id,
                    s.status,
                    ssa.is_primary,
                    ssa.role_key
                FROM staff_section_assignments ssa
                INNER JOIN sections s ON s.section_id = ssa.section_id
                WHERE ssa.staff_id = ?
                  AND ssa.status = 'active'
                  AND s.status = 'active'
                ORDER BY ssa.is_primary DESC, s.section_name ASC
            ");
            if ($stmt) {
                $stmt->bind_param('s', $staffId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $sectionType = hos_normalize_section_type(
                        (string)($row['section_type'] ?? ''),
                        (string)($row['section_name'] ?? ''),
                        (string)($row['section_id'] ?? '')
                    );
                    $sections[] = [
                        'section_id' => (string)($row['section_id'] ?? ''),
                        'section_name' => (string)($row['section_name'] ?? ''),
                        'section_type' => $sectionType,
                        'department_id' => (string)($row['department_id'] ?? ''),
                        'role_key' => (string)($row['role_key'] ?? 'head_of_department'),
                        'is_primary' => (int)($row['is_primary'] ?? 0),
                    ];
                }
                $stmt->close();
            }
        }

        if (!empty($sections)) {
            return hos_resolve_single_hos_assignment($sections);
        }

        if (
            hos_table_exists($db, 'departments')
            && hos_column_exists($db, 'departments', 'hod_id')
        ) {
            $departmentIdCol = hos_column_exists($db, 'departments', 'department_id') ? 'department_id' : 'id';
            $facultyExpr = hos_column_exists($db, 'departments', 'faculty') ? 'faculty' : "'' AS faculty";
            $stmt = $db->prepare("
                SELECT `{$departmentIdCol}` AS department_id, department_name, {$facultyExpr}
                FROM departments
                WHERE CAST(hod_id AS CHAR) = CAST(? AS CHAR)
                   OR CAST(hod_id AS CHAR) = (
                       SELECT CAST(id AS CHAR) FROM staff WHERE staff_id = ? LIMIT 1
                   )
                ORDER BY department_name ASC
            ");
            if ($stmt) {
                $stmt->bind_param('ss', $staffId, $staffId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $departmentId = (string)($row['department_id'] ?? '');
                    $departmentName = (string)($row['department_name'] ?? $departmentId);
                    $faculty = (string)($row['faculty'] ?? '');
                    $sections[] = [
                        'section_id' => 'DEPT_' . $departmentId,
                        'section_name' => $departmentName,
                        'section_type' => hos_normalize_section_type('', $departmentName, $departmentId, $faculty),
                        'department_id' => $departmentId,
                        'role_key' => 'head_of_department',
                        'is_primary' => 1,
                    ];
                }
                $stmt->close();
            }
        }

        return hos_resolve_single_hos_assignment($sections);
    }
}

if (!function_exists('hos_resolve_single_hos_assignment')) {
    function hos_resolve_single_hos_assignment(array $sections): array
    {
        if (count($sections) <= 1) {
            return $sections;
        }

        $primaries = array_values(array_filter(
            $sections,
            static fn(array $section): bool => (int)($section['is_primary'] ?? 0) === 1
        ));
        if (count($primaries) === 1) {
            return [$primaries[0]];
        }
        if (count($primaries) > 1) {
            error_log('HOS account has multiple primary section assignments; using the first primary only.');
            return [$primaries[0]];
        }

        error_log('HOS account has multiple section assignments without a primary flag; using the first assignment only.');
        return [$sections[0]];
    }
}

if (!function_exists('hos_active_session_role')) {
    function hos_active_session_role(): string
    {
        return wuc_normalize_staff_role((string)($_SESSION['role'] ?? ''), false);
    }
}

if (!function_exists('hos_can_switch_sections')) {
    function hos_can_switch_sections(mysqli $db, string $staffId): bool
    {
        unset($db, $staffId);

        // Section switching is only for the active systems-admin workspace.
        // Do not treat dormant systems_admin entries in all_roles as switch rights
        // while the user is working as Head of Section.
        return hos_active_session_role() === 'systems_admin';
    }
}

if (!function_exists('hos_set_section_session')) {
    function hos_set_section_session(array $section, array $visibleSections): array
    {
        $_SESSION['hos_section_id'] = (string)($section['section_id'] ?? '');
        $_SESSION['hos_section_name'] = (string)($section['section_name'] ?? '');
        $_SESSION['hos_section_type'] = (string)($section['section_type'] ?? '');
        $_SESSION['hos_department_id'] = (string)($section['department_id'] ?? '');
        $_SESSION['hos_sections'] = $visibleSections;

        if ($_SESSION['hos_department_id'] !== '') {
            $_SESSION['dept_id'] = $_SESSION['hos_department_id'];
        }

        return $visibleSections;
    }
}

if (!function_exists('hos_log_untrusted_section_request')) {
    function hos_log_untrusted_section_request(mysqli $db, string $staffId, string $requestedSectionId, string $assignedSectionId): void
    {
        if ($requestedSectionId === '' || $requestedSectionId === $assignedSectionId) {
            return;
        }

        if (!function_exists('audit_log_current_user')) {
            $auditHelper = __DIR__ . '/audit.php';
            if (is_file($auditHelper)) {
                require_once $auditHelper;
            }
        }
        if (function_exists('audit_log_current_user')) {
            audit_log_current_user($db, 'security.hos_section_denied', [
                'staff_id' => $staffId,
                'requested_section_id' => $requestedSectionId,
                'assigned_section_id' => $assignedSectionId,
            ]);
        }
    }
}

if (!function_exists('hos_get_all_active_sections')) {
    function hos_get_all_active_sections(mysqli $db): array
    {
        if (!hos_table_exists($db, 'sections')) {
            return [];
        }

        $sections = [];
        $result = $db->query("
            SELECT section_id, section_name, section_type, department_id
            FROM sections
            WHERE status = 'active'
            ORDER BY FIELD(section_id, 'ENGICT', 'TRANSPORT') ASC, section_name ASC
        ");
        while ($result && ($row = $result->fetch_assoc())) {
            $sectionType = hos_normalize_section_type(
                (string)($row['section_type'] ?? ''),
                (string)($row['section_name'] ?? ''),
                (string)($row['section_id'] ?? '')
            );
            $sections[] = [
                'section_id' => (string)($row['section_id'] ?? ''),
                'section_name' => (string)($row['section_name'] ?? ''),
                'section_type' => $sectionType,
                'department_id' => (string)($row['department_id'] ?? ''),
                'role_key' => 'head_of_department',
                'is_primary' => 0,
            ];
        }
        if ($result) {
            $result->free();
        }

        return $sections;
    }
}

if (!function_exists('hos_staff_is_systems_admin')) {
    function hos_staff_is_systems_admin(mysqli $db, string $staffId): bool
    {
        $sessionRoles = array_merge(
            [$_SESSION['role'] ?? '', $_SESSION['role_raw'] ?? ''],
            $_SESSION['all_roles'] ?? [],
            $_SESSION['all_roles_raw'] ?? []
        );
        foreach ($sessionRoles as $role) {
            if (wuc_normalize_staff_role((string) $role, false) === 'systems_admin') {
                return true;
            }
        }

        return $staffId !== '' && wuc_staff_has_role($db, $staffId, 'systems_admin');
    }
}

if (!function_exists('hos_get_visible_staff_sections')) {
    function hos_get_visible_staff_sections(mysqli $db, string $staffId): array
    {
        if (hos_can_switch_sections($db, $staffId)) {
            $sections = hos_get_all_active_sections($db);
            if (!empty($sections)) {
                return $sections;
            }
        }

        return hos_get_staff_sections($db, $staffId);
    }
}

if (!function_exists('hos_hydrate_section_session')) {
    function hos_hydrate_section_session(mysqli $db, string $staffId): array
    {
        // Callers normally run behind a session guard, but CLI scripts and
        // misordered includes reach here with no session at all — in strict
        // mode that makes every $_SESSION access a fatal ErrorException.
        if (session_status() === PHP_SESSION_NONE) {
            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                session_start();
            } elseif (!isset($_SESSION)) {
                $_SESSION = [];
            }
        }
        $canSwitch = hos_can_switch_sections($db, $staffId);
        $previousSectionId = trim((string)($_SESSION['hos_section_id'] ?? ''));
        unset($_SESSION['hos_section_id'], $_SESSION['hos_section_name'], $_SESSION['hos_section_type'], $_SESSION['hos_department_id']);

        if (!$canSwitch) {
            $assignedSections = hos_get_staff_sections($db, $staffId);
            if (empty($assignedSections)) {
                $_SESSION['hos_sections'] = [];
                return [];
            }

            $primary = $assignedSections[0];
            $requestedSectionId = trim((string)($_GET['section'] ?? $_POST['section_id'] ?? ''));
            if ($requestedSectionId !== '') {
                hos_log_untrusted_section_request(
                    $db,
                    $staffId,
                    $requestedSectionId,
                    (string)($primary['section_id'] ?? '')
                );
            }

            return hos_set_section_session($primary, [$primary]);
        }

        $sections = hos_get_all_active_sections($db);
        if (empty($sections)) {
            $sections = hos_get_staff_sections($db, $staffId);
        }
        if (empty($sections)) {
            $_SESSION['hos_sections'] = [];
            return [];
        }

        $preferredSectionId = trim((string)($_GET['section'] ?? ''));
        $primary = $sections[0];

        if ($preferredSectionId !== '') {
            foreach ($sections as $section) {
                if ((string)($section['section_id'] ?? '') === $preferredSectionId) {
                    $primary = $section;
                    break;
                }
            }
        } elseif ($previousSectionId !== '') {
            foreach ($sections as $section) {
                if ((string)($section['section_id'] ?? '') === $previousSectionId) {
                    $primary = $section;
                    break;
                }
            }
        }

        $orderedSections = array_values(array_merge(
            [$primary],
            array_filter($sections, static function (array $section) use ($primary): bool {
                return (string)($section['section_id'] ?? '') !== (string)($primary['section_id'] ?? '');
            })
        ));

        return hos_set_section_session($primary, $orderedSections);
    }
}

if (!function_exists('hos_require_assigned_section')) {
    function hos_require_assigned_section(mysqli $db, string $staffId, ?string $redirectPath = null): void
    {
        unset($staffId);
        $sectionId = trim((string)($_SESSION['hos_section_id'] ?? ''));
        if ($sectionId !== '' || hos_can_switch_sections($db, '')) {
            return;
        }

        $_SESSION['errorMessage'] = 'No section has been assigned to your Head of Section account. '
            . 'Please contact the Systems Administrator or Registrar before accessing Head of Section pages.';
        wuc_safe_redirect($redirectPath ?? '/wucportal/hod/index.php');
    }
}

if (!function_exists('hos_enforce_section_url_policy')) {
    function hos_enforce_section_url_policy(mysqli $db, string $staffId): void
    {
        if (hos_can_switch_sections($db, $staffId)) {
            return;
        }

        $requestedSectionId = trim((string)($_GET['section'] ?? ''));
        if ($requestedSectionId === '') {
            return;
        }

        $assignedSectionId = trim((string)($_SESSION['hos_section_id'] ?? ''));
        hos_log_untrusted_section_request($db, $staffId, $requestedSectionId, $assignedSectionId);

        $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/wucportal/hod/index.php');
        $query = $_GET;
        unset($query['section']);
        $target = $path . ($query !== [] ? '?' . http_build_query($query) : '');

        if (!headers_sent()) {
            header('Location: ' . $target, true, 302);
        }
        exit;
    }
}
