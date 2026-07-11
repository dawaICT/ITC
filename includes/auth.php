<?php
declare(strict_types=1);

/**
 * Access Control Authorization Helpers (RBAC, Permission-based, Assignment-based).
 */

require_once __DIR__ . '/../db/connect.php';

// Single source of truth for the RBAC permission helpers. getUserPermissions(),
// wuc_has_permission_unified() and hasPermission() live canonically in
// permissions.php (which carries the portal-aware, schema-aware implementations).
// Loading it here means auth.php and permissions.php no longer keep divergent
// copies of the same functions — whichever file a page includes first, it gets
// the one canonical (portal-aware) implementation.
require_once __DIR__ . '/permissions.php';

if (!function_exists('getUserRoles')) {
    /**
     * Retrieve all active roles assigned to a user.
     */
    function getUserRoles($userId): array {
        global $db;
        if (!$db instanceof mysqli || $userId === null || $userId === '') {
            return [];
        }

        $userIdInt = (int)$userId;
        $portalId = function_exists('wuc_permissions_current_portal_id')
            ? wuc_permissions_current_portal_id($db)
            : null;

        // Per-request cache — getUserRoles() is called by canAccessModule()
        // and admin-override checks repeatedly per page for the same user.
        $useCache = function_exists('wuc_permission_cache_store');
        $rolesKey = $userIdInt . '|' . ($portalId ?? 'null');
        if ($useCache) {
            $store = &wuc_permission_cache_store();
            if (array_key_exists($rolesKey, $store['roles'])) {
                return $store['roles'][$rolesKey];
            }
        }

        $portalClause = $portalId !== null ? ' AND (ur.portal_id IS NULL OR ur.portal_id = ?)' : '';

        $sql = "SELECT r.role_name, r.role_label
                FROM user_roles ur
                JOIN roles r ON r.role_id = ur.role_id
                WHERE ur.user_id = ? AND ur.status = 'active' AND r.status = 'active'" . $portalClause;

        $roles = [];
        if ($stmt = $db->prepare($sql)) {
            if ($portalId !== null) {
                $stmt->bind_param('ii', $userIdInt, $portalId);
            } else {
                $stmt->bind_param('i', $userIdInt);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $roles[] = $row;
            }
            $stmt->close();
        }
        if ($useCache) {
            $store['roles'][$rolesKey] = $roles;
        }
        return $roles;
    }
}


if (!function_exists('getUserModules')) {
    /**
     * Retrieve all unique modules the user can access.
     */
    function getUserModules($userId): array {
        $perms = getUserPermissions($userId);
        $modules = [];
        foreach ($perms as $p) {
            $modules[] = $p['module_key'];
        }
        return array_values(array_unique($modules));
    }
}



if (!function_exists('canAccessModule')) {
    /**
     * Check if a user can access a module.
     */
    function canAccessModule($userId, string $moduleKey): bool {
        global $db;
        if (!$db instanceof mysqli || $userId === null || $userId === '') {
            return false;
        }

        // Admin override
        $roles = getUserRoles($userId);
        foreach ($roles as $r) {
            if ($r['role_name'] === 'systems_admin') {
                return true;
            }
        }

        $modules = getUserModules($userId);
        return in_array($moduleKey, $modules, true);
    }
}

if (!function_exists('isAssignedLecturer')) {
    /**
     * Verify if a lecturer is assigned to a course within given context.
     */
    function isAssignedLecturer($lecturerId, string $courseId, ?string $programId = null, ?string $academicPeriod = null): bool {
        global $db;
        if (!$db instanceof mysqli || $lecturerId === null || $lecturerId === '') {
            return false;
        }

        $sql = "SELECT 1 FROM course_lecturer WHERE staff_id = ? AND course_code = ?";
        $params = [(string)$lecturerId, $courseId];
        $types = "ss";

        if ($programId !== null && $programId !== '') {
            $sql .= " AND program_code = ?";
            $params[] = $programId;
            $types .= "s";
        }
        if ($academicPeriod !== null && $academicPeriod !== '') {
            $sql .= " AND semester = ?";
            $params[] = $academicPeriod;
            $types .= "s";
        }
        $sql .= " AND status = 'active' LIMIT 1";

        $stmt = $db->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('isAssignedHOD')) {
    /**
     * Verify if a staff member is HOD of a department.
     */
    function isAssignedHOD($staffId, $departmentId): bool {
        global $db;
        if (!$db instanceof mysqli || $staffId === null || $staffId === '') {
            return false;
        }

        $staffIdStr = (string)$staffId;
        $deptIdInt = (int)$departmentId;

        // 1. Check department_assignments table
        $sql = "SELECT 1 FROM department_assignments WHERE staff_id = ? AND department_id = ? AND assignment_type = 'hod' AND status = 'active' LIMIT 1";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param("si", $staffIdStr, $deptIdInt);
            $stmt->execute();
            $ok = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($ok) return true;
        }

        // 2. Fallback check: check if staff has HOD role and staff.deptId matches
        $sql = "SELECT 1 FROM staff WHERE staff_id = ? AND deptId = ? AND LOWER(role) IN ('head_of_department', 'head of section', 'hos', 'hod') LIMIT 1";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param("si", $staffIdStr, $deptIdInt);
            $stmt->execute();
            $ok = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($ok) return true;
        }

        return false;
    }
}

if (!function_exists('isStudentRegisteredForCourse')) {
    /**
     * Verify if a student is enrolled in a course within a period.
     */
    function isStudentRegisteredForCourse($studentId, string $courseId, ?string $programId = null, ?string $academicPeriod = null): bool {
        global $db;
        if (!$db instanceof mysqli || $studentId === null || $studentId === '') {
            return false;
        }

        $sidStr = (string)$studentId;

        // 1. Try course_registration
        $sql = "SELECT 1 FROM course_registration WHERE Sid = ? AND course_code = ? AND is_active = 1";
        $params = [$sidStr, $courseId];
        $types = "ss";
        if ($academicPeriod !== null && $academicPeriod !== '') {
            $sql .= " AND semester = ?";
            $params[] = $academicPeriod;
            $types .= "s";
        }
        $sql .= " LIMIT 1";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $ok = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($ok) return true;
        }

        // NOTE: a legacy fallback against `student_courses` used to live here —
        // that table has never existed in the live DB, so the prepare() threw a
        // fatal whenever the primary lookup found nothing. course_registration
        // is the single source of truth for enrollment.
        return false;
    }
}
