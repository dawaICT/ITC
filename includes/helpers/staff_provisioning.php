<?php
declare(strict_types=1);

/**
 * Complete staff account provisioning for login, RBAC, portals, and lecturer access.
 *
 * admin/add_staff.php historically created only staff + access_right rows.
 * The portal now requires users, user_roles, staff_positions, and (optionally)
 * user_portal_access for consistent menus, guards, and module visibility.
 */

require_once dirname(__DIR__) . '/staff_role_helpers.php';
require_once dirname(__DIR__) . '/auth_helpers.php';
require_once dirname(__DIR__) . '/schema_guard.php';

if (!function_exists('wuc_role_display_name')) {
    function wuc_role_display_name(string $canonicalRole): string
    {
        $map = [
            'systems_admin' => 'Systems Admin',
            'lecturer' => 'Lecturer',
            'head_of_department' => 'Head of Section',
            'dean' => 'Dean',
            'registrar' => 'Registrar',
            'exams_officer' => 'Exams Officer',
            'admission_officer' => 'Admission Officer',
            'accountant' => 'Accountant',
            'librarian' => 'Librarian',
            'transport_officer' => 'Transport Officer',
            'employer' => 'Employer',
            'staff' => 'Staff',
        ];
        $canonicalRole = wuc_normalize_staff_role($canonicalRole);
        return $map[$canonicalRole] ?? ucwords(str_replace('_', ' ', $canonicalRole));
    }
}

if (!function_exists('wuc_ensure_position_id')) {
    function wuc_ensure_position_id(mysqli $db, string $posName): ?int
    {
        $posName = trim($posName);
        if ($posName === '' || !wuc_table_exists($db, 'positions')) {
            return null;
        }

        $stmt = $db->prepare('SELECT PosID FROM positions WHERE PosName = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $posName);
        $stmt->execute();
        $stmt->bind_result($posId);
        if ($stmt->fetch()) {
            $stmt->close();
            return (int)$posId;
        }
        $stmt->close();

        $desc = 'Auto-provisioned position for ' . $posName;
        $ins = $db->prepare('INSERT INTO positions (PosName, description, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
        if (!$ins) {
            return null;
        }
        $ins->bind_param('ss', $posName, $desc);
        $ins->execute();
        $newId = (int)$db->insert_id;
        $ins->close();
        return $newId > 0 ? $newId : null;
    }
}

if (!function_exists('wuc_ensure_staff_position')) {
    function wuc_ensure_staff_position(mysqli $db, string $staffId, string $posName): bool
    {
        if (!wuc_table_exists($db, 'staff_positions')) {
            return false;
        }
        $posId = wuc_ensure_position_id($db, $posName);
        if ($posId === null) {
            return false;
        }
        $stmt = $db->prepare(
            'INSERT IGNORE INTO staff_positions (staff_id, PosID, created_at, updated_at) VALUES (?, ?, NOW(), NOW())'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $staffId, $posId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('wuc_ensure_user_role')) {
    function wuc_ensure_user_role(mysqli $db, int $userId, string $canonicalRole): bool
    {
        if ($userId <= 0 || !wuc_table_exists($db, 'user_roles') || !wuc_table_exists($db, 'roles')) {
            return false;
        }
        $canonicalRole = wuc_normalize_staff_role($canonicalRole);
        $stmt = $db->prepare('SELECT role_id FROM roles WHERE role_name = ? AND status = "active" LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $canonicalRole);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return false;
        }
        $roleId = (int)$row['role_id'];
        $ins = $db->prepare(
            'INSERT INTO user_roles (user_id, role_id, status) VALUES (?, ?, "active")
             ON DUPLICATE KEY UPDATE status = "active"'
        );
        if (!$ins) {
            return false;
        }
        $ins->bind_param('ii', $userId, $roleId);
        $ok = $ins->execute();
        $ins->close();
        return $ok;
    }
}

if (!function_exists('wuc_ensure_role_permission')) {
    function wuc_ensure_role_permission(mysqli $db, string $canonicalRole, string $permissionKey): bool
    {
        if (!wuc_table_exists($db, 'role_permissions') || !wuc_table_exists($db, 'permissions') || !wuc_table_exists($db, 'modules')) {
            return false;
        }
        $parts = explode('.', $permissionKey, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$moduleKey, $actionKey] = $parts;
        $permKey = $moduleKey . '.' . $actionKey;

        $rStmt = $db->prepare('SELECT role_id FROM roles WHERE role_name = ? LIMIT 1');
        if (!$rStmt) {
            return false;
        }
        $rStmt->bind_param('s', $canonicalRole);
        $rStmt->execute();
        $roleRow = $rStmt->get_result()->fetch_assoc();
        $rStmt->close();
        if (!$roleRow) {
            return false;
        }
        $roleId = (int)$roleRow['role_id'];

        $mStmt = $db->prepare('SELECT module_id FROM modules WHERE module_key = ? LIMIT 1');
        if (!$mStmt) {
            return false;
        }
        $mStmt->bind_param('s', $moduleKey);
        $mStmt->execute();
        $modRow = $mStmt->get_result()->fetch_assoc();
        $mStmt->close();
        if (!$modRow) {
            return false;
        }
        $moduleId = (int)$modRow['module_id'];

        $pStmt = $db->prepare('SELECT permission_id FROM permissions WHERE permission_key = ? LIMIT 1');
        if (!$pStmt) {
            return false;
        }
        $pStmt->bind_param('s', $permKey);
        $pStmt->execute();
        $permRow = $pStmt->get_result()->fetch_assoc();
        $pStmt->close();
        if (!$permRow) {
            return false;
        }
        $permissionId = (int)$permRow['permission_id'];

        $ins = $db->prepare(
            'INSERT INTO role_permissions (role_id, module_id, permission_id, status)
             VALUES (?, ?, ?, "active")
             ON DUPLICATE KEY UPDATE status = "active"'
        );
        if (!$ins) {
            return false;
        }
        $ins->bind_param('iii', $roleId, $moduleId, $permissionId);
        $ok = $ins->execute();
        $ins->close();
        return $ok;
    }
}

if (!function_exists('wuc_ensure_lecturer_role_permissions')) {
    /** Idempotent: grant module keys the lecturer sidebar and dashboard expect. */
    function wuc_ensure_lecturer_role_permissions(mysqli $db): void
    {
        $perms = [
            'dashboard.view',
            'courses.view',
            'student_records.view',
            'ca_upload.view',
            'ca_upload.upload',
            'reports.view',
            'elearning.view',
            'elearning.manage',
            'lecturer_assignment.view',
        ];
        foreach ($perms as $perm) {
            wuc_ensure_role_permission($db, 'lecturer', $perm);
        }
    }
}

if (!function_exists('wuc_default_portals_for_role')) {
    function wuc_default_portals_for_role(string $canonicalRole): array
    {
        $canonicalRole = wuc_normalize_staff_role($canonicalRole);
        if ($canonicalRole === 'employer') {
            return ['employer'];
        }
        if ($canonicalRole === 'alumni') {
            return ['alumni'];
        }
        $portals = ['academic'];
        if (in_array($canonicalRole, ['lecturer', 'head_of_department', 'dean', 'systems_admin', 'registrar'], true)) {
            $portals[] = 'elearning';
        }
        return array_values(array_unique($portals));
    }
}

if (!function_exists('wuc_provision_staff_account')) {
    /**
     * Create or repair all records required for a staff member to use the portal.
     *
     * @return array{ok:bool,user_id:int,messages:string[]}
     */
    function wuc_provision_staff_account(
        mysqli $db,
        string $staffId,
        ?string $canonicalRole = null,
        ?string $plainPassword = null,
        string $assignedBy = 'system'
    ): array {
        $result = ['ok' => false, 'user_id' => 0, 'messages' => []];
        $staffId = trim($staffId);
        if ($staffId === '') {
            $result['messages'][] = 'Empty staff_id.';
            return $result;
        }

        $stmt = $db->prepare('SELECT staff_id, password, role, status FROM staff WHERE staff_id = ? LIMIT 1');
        if (!$stmt) {
            $result['messages'][] = 'Staff lookup failed.';
            return $result;
        }
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $staff = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$staff) {
            $result['messages'][] = "Staff {$staffId} not found.";
            return $result;
        }

        $canonicalRole = wuc_normalize_staff_role($canonicalRole ?? (string)($staff['role'] ?? 'staff'));
        $status = strtolower(trim((string)($staff['status'] ?? 'active')));
        if ($status === '') {
            $status = 'active';
        }

        $hash = (string)($staff['password'] ?? '');
        if ($plainPassword !== null && $plainPassword !== '') {
            $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
            if ($upd = $db->prepare('UPDATE staff SET password = ? WHERE staff_id = ?')) {
                $upd->bind_param('ss', $hash, $staffId);
                $upd->execute();
                $upd->close();
            }
        }
        if ($hash === '') {
            $hash = password_hash($staffId, PASSWORD_DEFAULT);
        }

        wuc_sync_staff_password($db, $staffId, $hash, $canonicalRole, $status);

        $userId = 0;
        $lookup = $db->prepare('SELECT user_id FROM users WHERE staff_id = ? OR username = ? LIMIT 1');
        if ($lookup) {
            $lookup->bind_param('ss', $staffId, $staffId);
            $lookup->execute();
            $lookup->bind_result($userId);
            $lookup->fetch();
            $lookup->close();
        }
        $userId = (int)$userId;
        if ($userId <= 0) {
            $result['messages'][] = 'users row could not be created.';
            return $result;
        }
        $result['user_id'] = $userId;

        if (wuc_table_exists($db, 'user_credentials')) {
            $legacyHash = md5($plainPassword !== null && $plainPassword !== '' ? $plainPassword : $staffId);
            if ($stmt = $db->prepare('INSERT INTO user_credentials (staff_id, pass) VALUES (?, ?) ON DUPLICATE KEY UPDATE pass = VALUES(pass)')) {
                $stmt->bind_param('ss', $staffId, $legacyHash);
                $stmt->execute();
                $stmt->close();
            }
        }

        $displayRole = wuc_role_display_name($canonicalRole);
        if (wuc_ensure_staff_position($db, $staffId, $displayRole)) {
            $result['messages'][] = "staff_positions: {$displayRole}";
        }

        if (wuc_ensure_user_role($db, $userId, $canonicalRole)) {
            $result['messages'][] = "user_roles: {$canonicalRole}";
        }

        if (wuc_table_exists($db, 'access_right')) {
            $rawAccess = $displayRole;
            if ($stmt = $db->prepare(
                'INSERT INTO access_right (staff_id, assigned_access, created_at, updated_at)
                 SELECT ?, ?, NOW(), NOW() FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM access_right WHERE staff_id = ? AND assigned_access IN (?, ?))'
            )) {
                $canon = $canonicalRole;
                $stmt->bind_param('sssss', $staffId, $rawAccess, $staffId, $rawAccess, $canon);
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($canonicalRole === 'lecturer') {
            wuc_ensure_lecturer_role_permissions($db);
        }

        require_once dirname(__DIR__) . '/portal_access.php';
        $portals = wuc_default_portals_for_role($canonicalRole);
        if (function_exists('wuc_grant_user_portal_access')) {
            wuc_grant_user_portal_access($db, $userId, $portals, $assignedBy);
            $result['messages'][] = 'portals: ' . implode(', ', $portals);
        }

        if ($canonicalRole !== wuc_normalize_staff_role((string)($staff['role'] ?? ''))) {
            if ($upd = $db->prepare('UPDATE staff SET role = ? WHERE staff_id = ?')) {
                $upd->bind_param('ss', $canonicalRole, $staffId);
                $upd->execute();
                $upd->close();
            }
        }

        $result['ok'] = true;
        return $result;
    }
}

if (!function_exists('wuc_deprovision_staff_account')) {
    /**
     * Reverse wuc_provision_staff_account(): remove every login, RBAC, portal, and
     * profile record for a staff member. Centralised so any future delete path
     * tears an account down consistently (the old manage_lectures.php delete only
     * removed staff/staff_positions/course_lecturer, orphaning users, credentials,
     * roles, and portal access).
     *
     * Rows linked by ON DELETE CASCADE (user_roles, user_portal_access,
     * user_profiles, user_module_access, employer_profiles ← users;
     * lecturer_course_assignments, staff_section_assignments ← staff) are removed
     * automatically when the users/staff parents are deleted. The RBAC rows are
     * ALSO cleared explicitly so teardown still works if a future migration drops
     * those cascades. Satellite tables with no foreign key (staff_positions,
     * course_lecturer, access_right, user_credentials, password_history) must be
     * deleted explicitly or they orphan.
     *
     * The CALLER must wrap this in a transaction (as the provision side does) so a
     * partial failure rolls back cleanly.
     *
     * @return array{ok:bool,deleted:array<string,int>,messages:string[]}
     */
    function wuc_deprovision_staff_account(mysqli $db, string $staffId): array
    {
        $result = ['ok' => false, 'deleted' => [], 'messages' => []];
        $staffId = trim($staffId);
        if ($staffId === '') {
            $result['messages'][] = 'Empty staff_id.';
            return $result;
        }

        // Resolve the users row first so RBAC rows keyed by user_id can be cleared
        // even if the ON DELETE CASCADE is ever removed.
        $userId = 0;
        if ($lookup = $db->prepare('SELECT user_id FROM users WHERE staff_id = ? OR username = ? LIMIT 1')) {
            $lookup->bind_param('ss', $staffId, $staffId);
            $lookup->execute();
            $lookup->bind_result($uid);
            if ($lookup->fetch()) {
                $userId = (int) $uid;
            }
            $lookup->close();
        }

        if ($userId > 0) {
            foreach (['user_roles', 'user_portal_access'] as $table) {
                if (!wuc_table_exists($db, $table)) {
                    continue;
                }
                if ($stmt = $db->prepare("DELETE FROM `{$table}` WHERE user_id = ?")) {
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $result['deleted'][$table] = max(0, $stmt->affected_rows);
                    $stmt->close();
                }
            }
        }

        // Satellite tables with no foreign key to users/staff — removed explicitly.
        foreach (['staff_positions', 'course_lecturer', 'access_right', 'user_credentials', 'password_history'] as $table) {
            if (!wuc_table_exists($db, $table)) {
                continue;
            }
            if ($stmt = $db->prepare("DELETE FROM `{$table}` WHERE staff_id = ?")) {
                $stmt->bind_param('s', $staffId);
                $stmt->execute();
                $result['deleted'][$table] = max(0, $stmt->affected_rows);
                $stmt->close();
            }
        }

        // Parents last; deleting users/staff cascades to their FK-linked children.
        foreach (['users', 'staff'] as $table) {
            if (!wuc_table_exists($db, $table)) {
                continue;
            }
            if ($stmt = $db->prepare("DELETE FROM `{$table}` WHERE staff_id = ?")) {
                $stmt->bind_param('s', $staffId);
                $stmt->execute();
                $result['deleted'][$table] = max(0, $stmt->affected_rows);
                $stmt->close();
            }
        }

        $result['ok'] = true;
        return $result;
    }
}
