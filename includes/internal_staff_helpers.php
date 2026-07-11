<?php
declare(strict_types=1);

/**
 * Internal staff / lecturer account detection for users_roles.php and related
 * access-control surfaces. Uses positive validation: a user must be linked to a
 * real staff record and must not be classified as student, applicant, alumni, or
 * employer.
 */

require_once __DIR__ . '/schema_guard.php';

if (!function_exists('wuc_external_account_roles')) {
    function wuc_external_account_roles(): array
    {
        return ['student', 'applicant', 'alumni', 'employer'];
    }
}

if (!function_exists('wuc_internal_staff_role_names')) {
    function wuc_internal_staff_role_names(): array
    {
        return [
            'systems_admin',
            'registrar',
            'exams_officer',
            'dean',
            'head_of_department',
            'accountant',
            'admission_officer',
            'librarian',
            'lecturer',
            'transport_officer',
            'staff',
        ];
    }
}

if (!function_exists('wuc_users_roles_block_message')) {
    function wuc_users_roles_block_message(): string
    {
        return 'This page only manages staff and lecturer roles. Student accounts must be managed from the student management module.';
    }
}

if (!function_exists('wuc_is_external_account_role')) {
    function wuc_is_external_account_role(?string $role): bool
    {
        return in_array(strtolower(trim((string)$role)), wuc_external_account_roles(), true);
    }
}

if (!function_exists('wuc_is_internal_staff_role')) {
    function wuc_is_internal_staff_role(?string $roleName): bool
    {
        return in_array(strtolower(trim((string)$roleName)), wuc_internal_staff_role_names(), true);
    }
}

if (!function_exists('wuc_load_user_row_for_roles')) {
    function wuc_load_user_row_for_roles(mysqli $db, int $userId): ?array
    {
        if ($userId <= 0 || !wuc_table_exists($db, 'users')) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT user_id, username, primary_role, staff_id, student_id, status
               FROM users
              WHERE user_id = ?
              LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $user;
    }
}

if (!function_exists('wuc_staff_record_exists')) {
    function wuc_staff_record_exists(mysqli $db, string $staffId): bool
    {
        $staffId = trim($staffId);
        if ($staffId === '' || !wuc_table_exists($db, 'staff')) {
            return false;
        }

        $stmt = $db->prepare('SELECT 1 FROM staff WHERE staff_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $exists;
    }
}

if (!function_exists('wuc_is_internal_staff_user')) {
    /**
     * Positive validation: linked staff record + not an external account type.
     */
    function wuc_is_internal_staff_user(mysqli $db, int $userId, ?array $user = null): bool
    {
        $user = $user ?? wuc_load_user_row_for_roles($db, $userId);
        if (!$user) {
            return false;
        }

        if (wuc_is_external_account_role($user['primary_role'] ?? '')) {
            return false;
        }

        $staffId = trim((string)($user['staff_id'] ?? ''));
        if ($staffId === '' || !wuc_staff_record_exists($db, $staffId)) {
            return false;
        }

        $studentId = trim((string)($user['student_id'] ?? ''));
        if ($studentId !== '' && wuc_table_exists($db, 'students')) {
            $stmt = $db->prepare('SELECT 1 FROM students WHERE SID = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $studentId);
                $stmt->execute();
                $hasStudent = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if ($hasStudent) {
                    return false;
                }
            }
        }

        return true;
    }
}

if (!function_exists('wuc_role_id_is_internal_staff')) {
    function wuc_role_id_is_internal_staff(mysqli $db, int $roleId): bool
    {
        if ($roleId <= 0 || !wuc_table_exists($db, 'roles')) {
            return false;
        }

        $stmt = $db->prepare('SELECT role_name FROM roles WHERE role_id = ? AND status = "active" LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && wuc_is_internal_staff_role($row['role_name'] ?? '');
    }
}

if (!function_exists('wuc_assert_internal_staff_user_for_roles_mgmt')) {
    function wuc_assert_internal_staff_user_for_roles_mgmt(mysqli $db, int $userId): ?string
    {
        if (!wuc_is_internal_staff_user($db, $userId)) {
            return wuc_users_roles_block_message();
        }

        return null;
    }
}

if (!function_exists('wuc_assert_internal_staff_role_for_roles_mgmt')) {
    function wuc_assert_internal_staff_role_for_roles_mgmt(mysqli $db, int $roleId): ?string
    {
        if (!wuc_role_id_is_internal_staff($db, $roleId)) {
            return 'Only internal staff and lecturer roles can be assigned from this page.';
        }

        return null;
    }
}

if (!function_exists('wuc_fetch_internal_staff_roles')) {
    function wuc_fetch_internal_staff_roles(mysqli $db): array
    {
        if (!wuc_table_exists($db, 'roles')) {
            return [];
        }

        $internal = wuc_internal_staff_role_names();
        $placeholders = implode(',', array_fill(0, count($internal), '?'));
        $types = str_repeat('s', count($internal));
        $sql = "SELECT role_id, role_name, role_label, status
                  FROM roles
                 WHERE status = 'active'
                   AND role_name IN ($placeholders)
                 ORDER BY role_label ASC";

        $roles = [];
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$internal);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $roles[] = $row;
        }
        $stmt->close();

        return $roles;
    }
}

if (!function_exists('wuc_fetch_internal_staff_users')) {
    /**
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    function wuc_fetch_internal_staff_users(mysqli $db, array $filters = []): array
    {
        if (!wuc_table_exists($db, 'users') || !wuc_table_exists($db, 'staff')) {
            return ['rows' => [], 'total' => 0];
        }

        $external = wuc_external_account_roles();
        $conditions = [
            'u.staff_id IS NOT NULL',
            "TRIM(u.staff_id) <> ''",
            's.staff_id IS NOT NULL',
        ];
        $params = [];
        $types = '';

        $extPlaceholders = implode(',', array_fill(0, count($external), '?'));
        $conditions[] = "(u.primary_role IS NULL OR u.primary_role NOT IN ($extPlaceholders))";
        foreach ($external as $role) {
            $params[] = $role;
            $types .= 's';
        }

        $conditions[] = '(u.student_id IS NULL OR TRIM(u.student_id) = \'\')';

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $conditions[] = '(u.username LIKE ? OR u.staff_id LIKE ? OR s.Fname LIKE ? OR s.Lname LIKE ? OR s.email LIKE ? OR CONCAT(s.Fname, \' \', s.Lname) LIKE ?)';
            for ($i = 0; $i < 6; $i++) {
                $params[] = $like;
                $types .= 's';
            }
        }

        $roleFilter = trim((string)($filters['role'] ?? ''));
        if ($roleFilter !== '' && wuc_is_internal_staff_role($roleFilter)) {
            $conditions[] = 'u.primary_role = ?';
            $params[] = $roleFilter;
            $types .= 's';
        }

        $statusFilter = trim((string)($filters['status'] ?? ''));
        if ($statusFilter !== '') {
            $conditions[] = 'u.status = ?';
            $params[] = $statusFilter;
            $types .= 's';
        }

        $deptFilter = (int)($filters['department_id'] ?? 0);
        if ($deptFilter > 0 && wuc_table_exists($db, 'departments')) {
            $conditions[] = 's.deptId = ?';
            $params[] = $deptFilter;
            $types .= 'i';
        }

        $where = implode(' AND ', $conditions);

        $countSql = "SELECT COUNT(*) AS cnt
                       FROM users u
                 INNER JOIN staff s ON s.staff_id = u.staff_id
                      WHERE $where";
        $total = 0;
        if ($stmt = $db->prepare($countSql)) {
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $stmt->close();
        }

        $deptSelect = wuc_table_exists($db, 'departments')
            ? ', d.department_name AS department_name'
            : ', NULL AS department_name';
        $deptJoin = wuc_table_exists($db, 'departments')
            ? ' LEFT JOIN departments d ON d.id = s.deptId'
            : '';

        $sectionSelect = '';
        $sectionJoin = '';
        if (wuc_table_exists($db, 'staff_section_assignments') && wuc_table_exists($db, 'sections')) {
            $sectionSelect = ', sec.section_name AS section_name';
            $sectionJoin = " LEFT JOIN (
                SELECT ssa.staff_id, GROUP_CONCAT(DISTINCT sec.section_name ORDER BY sec.section_name SEPARATOR ', ') AS section_name
                  FROM staff_section_assignments ssa
                  INNER JOIN sections sec ON sec.section_id = ssa.section_id
                 WHERE ssa.status = 'active'
                 GROUP BY ssa.staff_id
            ) sec ON sec.staff_id = s.staff_id";
        } else {
            $sectionSelect = ', NULL AS section_name';
        }

        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = max(10, min(100, (int)($filters['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT u.user_id, u.username, u.primary_role, u.staff_id, u.status AS account_status,
                       s.Fname, s.Lname, s.role AS designation, s.email, s.last_login
                       $deptSelect
                       $sectionSelect
                  FROM users u
            INNER JOIN staff s ON s.staff_id = u.staff_id
                $deptJoin
                $sectionJoin
                 WHERE $where
              ORDER BY s.Fname ASC, s.Lname ASC, u.username ASC
                 LIMIT ? OFFSET ?";

        $rows = [];
        $listTypes = $types . 'ii';
        $listParams = array_merge($params, [$perPage, $offset]);

        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param($listTypes, ...$listParams);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }
}

if (!function_exists('wuc_detect_staff_role_inconsistencies')) {
    function wuc_detect_staff_role_inconsistencies(mysqli $db): array
    {
        $issues = [];
        if (!wuc_table_exists($db, 'users')) {
            return $issues;
        }

        $external = wuc_external_account_roles();
        $extList = "'" . implode("','", array_map(static fn($r) => $db->real_escape_string($r), $external)) . "'";

        // External accounts (employer/alumni/applicant) legitimately carry a
        // staff_id + staff record: admin/employer_accounts.php deliberately creates
        // an EMP-#### staff row (role='employer') so the account can sign in through
        // the shared staff login and land in its own portal only. That is by design,
        // NOT an inconsistency. Only flag when the linked staff record actually holds
        // an INTERNAL staff role — i.e. an external identity fronting real staff
        // access — which is the genuine data problem worth correcting.
        if (wuc_table_exists($db, 'staff')) {
            $res = $db->query(
                "SELECT u.user_id, u.username, u.primary_role, u.staff_id, s.role AS staff_role
                   FROM users u
                   INNER JOIN staff s ON s.staff_id = u.staff_id
                  WHERE u.primary_role IN ($extList)
                    AND u.staff_id IS NOT NULL
                    AND TRIM(u.staff_id) <> ''
                  ORDER BY u.username ASC
                  LIMIT 50"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $staffRole = (string)($row['staff_role'] ?? '');
                    if (function_exists('wuc_normalize_staff_role')) {
                        $staffRole = wuc_normalize_staff_role($staffRole);
                    }
                    // Matching external / non-internal staff role => legitimate login shim, skip.
                    if (!wuc_is_internal_staff_role($staffRole)) {
                        continue;
                    }
                    $issues[] = [
                        'type' => 'external_with_internal_staff_record',
                        'message' => sprintf(
                            'Account %s (%s) is classified as %s but is linked to an internal staff record (%s, role: %s). Reclassify the account or detach the staff link in the appropriate module.',
                            $row['username'],
                            $row['user_id'],
                            $row['primary_role'],
                            $row['staff_id'],
                            (string)($row['staff_role'] ?? '')
                        ),
                    ];
                }
                $res->free();
            }
        }

        if (wuc_table_exists($db, 'user_roles') && wuc_table_exists($db, 'roles')) {
            $res = $db->query(
                "SELECT u.user_id, u.username, u.primary_role, GROUP_CONCAT(r.role_name) AS assigned_roles
                   FROM users u
                   INNER JOIN user_roles ur ON ur.user_id = u.user_id AND ur.status = 'active'
                   INNER JOIN roles r ON r.role_id = ur.role_id AND r.status = 'active'
                  WHERE u.primary_role IN ($extList)
                    AND r.role_name NOT IN ($extList)
                  GROUP BY u.user_id, u.username, u.primary_role
                  ORDER BY u.username ASC
                  LIMIT 50"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $issues[] = [
                        'type' => 'external_with_internal_role',
                        'message' => sprintf(
                            'Account %s (%s) has primary role %s but is assigned internal role(s): %s.',
                            $row['username'],
                            $row['user_id'],
                            $row['primary_role'],
                            $row['assigned_roles']
                        ),
                    ];
                }
                $res->free();
            }
        }

        $res = $db->query(
            "SELECT u.user_id, u.username, u.staff_id
               FROM users u
               LEFT JOIN staff s ON s.staff_id = u.staff_id
              WHERE u.staff_id IS NOT NULL
                AND TRIM(u.staff_id) <> ''
                AND s.staff_id IS NULL
              ORDER BY u.username ASC
              LIMIT 50"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $issues[] = [
                    'type' => 'missing_staff_profile',
                    'message' => sprintf(
                        'User %s (%s) references staff ID %s but no matching staff profile exists.',
                        $row['username'],
                        $row['user_id'],
                        $row['staff_id']
                    ),
                ];
            }
            $res->free();
        }

        if (wuc_table_exists($db, 'staff')) {
            $res = $db->query(
                "SELECT s.staff_id, s.Fname, s.Lname
                   FROM staff s
                   LEFT JOIN users u ON u.staff_id = s.staff_id
                  WHERE u.user_id IS NULL
                    AND s.status = 'active'
                  ORDER BY s.Fname ASC
                  LIMIT 50"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $issues[] = [
                        'type' => 'staff_without_user',
                        'message' => sprintf(
                            'Staff member %s %s (%s) has no linked portal user account.',
                            $row['Fname'],
                            $row['Lname'],
                            $row['staff_id']
                        ),
                    ];
                }
                $res->free();
            }
        }

        return $issues;
    }
}

if (!function_exists('wuc_internal_staff_user_type_label')) {
    function wuc_internal_staff_user_type_label(?string $primaryRole): string
    {
        if (!function_exists('getRoleDisplayName')) {
            require_once __DIR__ . '/role_helpers.php';
        }

        return getRoleDisplayName($primaryRole);
    }
}
