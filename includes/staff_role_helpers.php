<?php
/**
 * Canonical staff-role normalization and hydration.
 *
 * Every authentication and authorization path must use this map so the same
 * database title always produces the same session role.
 */

if (!function_exists('wuc_staff_role_map')) {
    function wuc_staff_role_map(): array
    {
        return [
            'systems_admin' => 'systems_admin',
            'systems admin' => 'systems_admin',
            'system admin' => 'systems_admin',
            'system administrator' => 'systems_admin',
            'super_admin' => 'systems_admin',
            'superadmin' => 'systems_admin',
            'super admin' => 'systems_admin',
            'admin' => 'systems_admin',
            'administrator' => 'systems_admin',
            'administration' => 'systems_admin',
            'administrative' => 'systems_admin',
            // Job titles must not silently become Systems Admin.
            // Assign systems_admin explicitly via staff_positions / roles UI.
            'manager' => 'staff',
            'director' => 'staff',

            'lecturer' => 'lecturer',
            'assistant lecturer' => 'lecturer',
            'part time lecturer' => 'lecturer',
            'part-time lecturer' => 'lecturer',
            'tutor' => 'lecturer',
            'instructor' => 'lecturer',

            'head_of_department' => 'head_of_department',
            'head of department' => 'head_of_department',
            'head of section' => 'head_of_department',
            'hod' => 'head_of_department',
            'hos' => 'head_of_department',

            'dean' => 'dean',
            'registrar' => 'registrar',

            'exams_officer' => 'exams_officer',
            'exams' => 'exams_officer',
            'exam officer' => 'exams_officer',
            'exams officer' => 'exams_officer',
            'examination officer' => 'exams_officer',
            'examinations officer' => 'exams_officer',

            'admission_officer' => 'admission_officer',
            'admission officer' => 'admission_officer',
            'admissions officer' => 'admission_officer',
            'admission' => 'admission_officer',
            'admissions' => 'admission_officer',

            'accountant' => 'accountant',
            'accounts' => 'accountant',
            'finance' => 'accountant',
            'finance officer' => 'accountant',
            'bursar' => 'accountant',

            'librarian' => 'librarian',
            'library staff' => 'librarian',

            'transport_officer' => 'transport_officer',
            'transport officer' => 'transport_officer',
            'transport' => 'transport_officer',
            'driver' => 'transport_officer',
            'driving instructor' => 'transport_officer',

            'employer' => 'employer',
            'alumni' => 'alumni',
            'student' => 'student',

            'staff' => 'staff',
        ];
    }
}

if (!function_exists('wuc_normalize_staff_role')) {
    function wuc_normalize_staff_role(string $role, bool $preserveUnknown = true): string
    {
        $normalized = strtolower(trim($role));
        if ($normalized === '') {
            return 'staff';
        }

        $map = wuc_staff_role_map();
        return $map[$normalized] ?? ($preserveUnknown ? $normalized : 'staff');
    }
}

if (!function_exists('wuc_staff_role_priority')) {
    function wuc_staff_role_priority(): array
    {
        return [
            'systems_admin', 'registrar', 'exams_officer', 'dean',
            'head_of_department', 'accountant', 'admission_officer',
            'librarian', 'lecturer', 'transport_officer', 'employer',
            'alumni', 'student', 'staff',
        ];
    }
}

if (!function_exists('wuc_primary_staff_role')) {
    function wuc_primary_staff_role(array $roles): string
    {
        $roles = array_values(array_unique(array_map(
            static fn($role) => wuc_normalize_staff_role((string) $role),
            $roles
        )));

        foreach (wuc_staff_role_priority() as $candidate) {
            if (in_array($candidate, $roles, true)) {
                return $candidate;
            }
        }
        return $roles[0] ?? 'staff';
    }
}

if (!function_exists('wuc_resolve_staff_roles')) {
    function wuc_resolve_staff_roles(mysqli $db, string $staffId): array
    {
        $staffId = trim($staffId);
        if ($staffId === '') {
            return [];
        }

        require_once __DIR__ . '/schema_guard.php';

        $rawRoles = [];
        $canonicalRoles = [];

        $stmt = $db->prepare(
            'SELECT r.role_name, r.role_label
             FROM user_roles ur
             INNER JOIN roles r ON r.role_id = ur.role_id
             INNER JOIN users u ON u.user_id = ur.user_id
             WHERE u.staff_id = ? AND ur.status = "active" AND r.status = "active"'
        );
        if ($stmt) {
            $stmt->bind_param('s', $staffId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $rawRole = trim((string) ($row['role_label'] ?? ''));
                $canonical = trim((string) ($row['role_name'] ?? ''));
                if ($canonical === '') {
                    continue;
                }
                $rawRoles[] = $rawRole !== '' ? $rawRole : $canonical;
                if (!in_array($canonical, $canonicalRoles, true)) {
                    $canonicalRoles[] = $canonical;
                }
            }
            $stmt->close();
        }

        // Fallback for accounts created before RBAC migration (staff_positions,
        // access_right, staff.role) when user_roles rows were never provisioned.
        if (!$canonicalRoles && wuc_table_exists($db, 'staff_positions') && wuc_table_exists($db, 'positions')) {
            $spStmt = $db->prepare(
                'SELECT p.PosName
                 FROM staff_positions sp
                 INNER JOIN positions p ON p.PosID = sp.PosID
                 WHERE sp.staff_id = ?'
            );
            if ($spStmt) {
                $spStmt->bind_param('s', $staffId);
                $spStmt->execute();
                $spRes = $spStmt->get_result();
                while ($spRow = $spRes->fetch_assoc()) {
                    $rawRole = trim((string)($spRow['PosName'] ?? ''));
                    if ($rawRole === '') {
                        continue;
                    }
                    $canonical = wuc_normalize_staff_role($rawRole);
                    $rawRoles[] = $rawRole;
                    if (!in_array($canonical, $canonicalRoles, true)) {
                        $canonicalRoles[] = $canonical;
                    }
                }
                $spStmt->close();
            }
        }

        if (!$canonicalRoles && wuc_table_exists($db, 'access_right')) {
            $arStmt = $db->prepare('SELECT assigned_access FROM access_right WHERE staff_id = ?');
            if ($arStmt) {
                $arStmt->bind_param('s', $staffId);
                $arStmt->execute();
                $arRes = $arStmt->get_result();
                while ($arRow = $arRes->fetch_assoc()) {
                    $rawRole = trim((string)($arRow['assigned_access'] ?? ''));
                    if ($rawRole === '') {
                        continue;
                    }
                    $canonical = wuc_normalize_staff_role($rawRole);
                    $rawRoles[] = $rawRole;
                    if (!in_array($canonical, $canonicalRoles, true)) {
                        $canonicalRoles[] = $canonical;
                    }
                }
                $arStmt->close();
            }
        }

        if (!$canonicalRoles) {
            $primaryRaw = '';
            $primaryCanonical = '';
            if ($stmt = $db->prepare(
                'SELECT s.role AS staff_role, u.primary_role AS user_role
                 FROM staff s
                 LEFT JOIN users u ON u.staff_id = s.staff_id
                 WHERE s.staff_id = ?
                 LIMIT 1'
            )) {
                $stmt->bind_param('s', $staffId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $primaryRaw = trim((string)($row['staff_role'] ?? ''));
                    if ($primaryRaw === '') {
                        $primaryRaw = trim((string)($row['user_role'] ?? ''));
                    }
                    $primaryCanonical = wuc_normalize_staff_role($primaryRaw);
                }
            }
            if ($primaryCanonical !== '' && $primaryCanonical !== 'staff') {
                $canonicalRoles[] = $primaryCanonical;
                $rawRoles[] = $primaryRaw !== '' ? $primaryRaw : $primaryCanonical;
            }
        }

        if (!$canonicalRoles) {
            return [];
        }

        $primaryRole = wuc_primary_staff_role($canonicalRoles);
        $primaryRaw = $rawRoles[0] ?? 'Staff';
        foreach ($rawRoles as $index => $rawRole) {
            if (isset($canonicalRoles[$index]) && $canonicalRoles[$index] === $primaryRole) {
                $primaryRaw = $rawRole;
                break;
            }
        }

        return [
            'all_roles' => $canonicalRoles,
            'all_roles_raw' => $rawRoles,
            'role' => $primaryRole,
            'role_raw' => $primaryRaw,
        ];
    }
}

if (!function_exists('wuc_hydrate_staff_roles')) {
    function wuc_hydrate_staff_roles(mysqli $db, string $staffId): bool
    {
        $resolved = wuc_resolve_staff_roles($db, $staffId);
        if (!$resolved) {
            return false;
        }

        // Preserve the viewer's ACTIVE role (picked at role_selection.php) as
        // long as the database still grants it. Hydration used to overwrite
        // $_SESSION['role'] with the highest-priority granted role on every
        // page render (nav_unified, guards, notifications), so a multi-role
        // user working as Admissions/HOS was silently flipped back to
        // systems_admin mid-session. Revoked roles still fall back to the
        // resolved primary.
        $currentRole = wuc_normalize_staff_role((string)($_SESSION['role'] ?? ''), false);
        $allRoles = (array)($resolved['all_roles'] ?? []);
        if ($currentRole !== '' && in_array($currentRole, $allRoles, true)) {
            $resolved['role'] = $currentRole;
            foreach ($allRoles as $idx => $canonical) {
                if ($canonical === $currentRole && isset($resolved['all_roles_raw'][$idx])) {
                    $resolved['role_raw'] = $resolved['all_roles_raw'][$idx];
                    break;
                }
            }
        }

        foreach ($resolved as $key => $value) {
            $_SESSION[$key] = $value;
        }
        return true;
    }
}

if (!function_exists('wuc_staff_has_role')) {
    function wuc_staff_has_role(mysqli $db, string $staffId, string $requiredRole): bool
    {
        $resolved = wuc_resolve_staff_roles($db, $staffId);
        $requiredRole = wuc_normalize_staff_role($requiredRole, false);
        return in_array($requiredRole, (array) ($resolved['all_roles'] ?? []), true);
    }
}
