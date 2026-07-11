<?php
require_once __DIR__ . '/staff_role_helpers.php';
/**
 * Role-Based Access Control (RBAC) Helper Functions
 * 
 * This file provides centralized role management functions for the WUC Portal.
 * It integrates with the access_right table to manage user permissions.
 * 
 * @version 1.0
 * @updated 2026-02-03
 */

// Define all available roles in the system
define('ROLE_SYSTEMS_ADMIN', 'systems_admin');
define('ROLE_LECTURER', 'lecturer');
define('ROLE_HEAD_OF_DEPARTMENT', 'head_of_department');
define('ROLE_DEAN', 'dean');
define('ROLE_REGISTRAR', 'registrar');
define('ROLE_EXAMS_OFFICER', 'exams_officer');
define('ROLE_ADMISSION_OFFICER', 'admission_officer');
define('ROLE_ACCOUNTANT', 'accountant');
define('ROLE_LIBRARIAN', 'librarian');
define('ROLE_TRANSPORT_OFFICER', 'transport_officer');
define('ROLE_EMPLOYER', 'employer');
define('ROLE_ALUMNI', 'alumni');
define('ROLE_STAFF', 'staff');

/**
 * Role mapping from database values to canonical format
 */
function getRoleMap(): array {
    return wuc_staff_role_map();
}

/**
 * Get display-friendly role names
 */
function getRoleDisplayNames(): array {
    return [
        ROLE_SYSTEMS_ADMIN => 'Systems Administrator',
        ROLE_LECTURER => 'Lecturer',
        ROLE_HEAD_OF_DEPARTMENT => 'Head of Section',
        ROLE_DEAN => 'Dean',
        ROLE_REGISTRAR => 'Registrar',
        ROLE_EXAMS_OFFICER => 'Exams Officer',
        ROLE_ADMISSION_OFFICER => 'Admission Officer',
        ROLE_ACCOUNTANT => 'Accountant',
        ROLE_LIBRARIAN => 'Librarian',
        ROLE_TRANSPORT_OFFICER => 'Transport Officer',
        ROLE_EMPLOYER => 'Employer',
        ROLE_ALUMNI => 'Alumni',
        ROLE_STAFF => 'Staff',
    ];
}

/**
 * Get all available roles for dropdown selection
 */
function getAvailableRoles(): array {
    return [
        'Systems Admin' => ROLE_SYSTEMS_ADMIN,
        'Lecturer' => ROLE_LECTURER,
        'Head of Section' => ROLE_HEAD_OF_DEPARTMENT,
        'Dean' => ROLE_DEAN,
        'Registrar' => ROLE_REGISTRAR,
        'Exams Officer' => ROLE_EXAMS_OFFICER,
        'Admission Officer' => ROLE_ADMISSION_OFFICER,
        'Accountant' => ROLE_ACCOUNTANT,
        'Librarian' => ROLE_LIBRARIAN,
        'Transport Officer' => ROLE_TRANSPORT_OFFICER,
        'Employer' => ROLE_EMPLOYER,
        'Alumni' => ROLE_ALUMNI,
        'Staff' => ROLE_STAFF,
    ];
}

/**
 * Normalize a role string to canonical format
 */
function normalizeRole(?string $role): string {
    return wuc_normalize_staff_role((string) $role, false);
}

/**
 * Canonical role values allowed for staff assignment.
 */
function getAvailableRoleValues(): array {
    return array_values(getAvailableRoles());
}

/**
 * Whether a submitted role (display label or canonical value) is allowed.
 * getAvailableRoles() is keyed [displayName => roleValue]. Admin forms submit the
 * canonical value; legacy/registrar forms may still post the display label.
 */
function isValidStaffRole(string $role): bool {
    $trimmed = trim($role);
    if ($trimmed === '') {
        return true;
    }

    $roles = getAvailableRoles();
    $allowedValues = getAvailableRoleValues();

    // Canonical value from admin/staff.php (e.g. "lecturer", "systems_admin").
    if (in_array($trimmed, $allowedValues, true)) {
        return true;
    }

    // Display label from legacy forms (e.g. "Lecturer", "Systems Admin").
    if (array_key_exists($trimmed, $roles)) {
        return true;
    }

    $roleCanonical = wuc_normalize_staff_role($trimmed, true);
    return in_array($roleCanonical, $allowedValues, true);
}

/**
 * Get display name for a role
 */
function getRoleDisplayName(?string $role): string {
    $normalizedRole = normalizeRole($role);
    $displayNames = getRoleDisplayNames();
    return $displayNames[$normalizedRole] ?? 'Staff';
}

/**
 * Check if the current user has a specific role
 */
function hasRole(string $requiredRole): bool {
    // Check primary role
    $userRole = $_SESSION['role'] ?? '';
    if ($userRole === $requiredRole) return true;
    // Check all assigned roles (multi-role support)
    $allRoles = $_SESSION['all_roles'] ?? [];
    return in_array($requiredRole, $allRoles);
}

/**
 * Check if the current user has any of the specified roles
 */
function hasAnyRole(array $allowedRoles): bool {
    // Check primary role
    $userRole = $_SESSION['role'] ?? '';
    if (in_array($userRole, $allowedRoles)) return true;
    // Check all assigned roles (multi-role support)
    $allRoles = $_SESSION['all_roles'] ?? [];
    return !empty(array_intersect($allowedRoles, $allRoles));
}

/**
 * Check permission assignments from role_permissions/staff_positions.
 * Kept local so role checks work even when includes/permissions.php is not loaded.
 */
require_once __DIR__ . '/auth.php';

function currentStaffHasPermission(string $permissionName): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    if ($userIdDb === null) {
        return false;
    }

    $map = [
        'elearn_manage_course' => ['elearning', 'manage'],
        'elearn_schedule_sessions' => ['elearning', 'manage'],
        'elearn_assessments' => ['elearning', 'manage'],
        'elearn_grade' => ['elearning', 'manage'],
        'elearn_forum_moderate' => ['elearning', 'manage'],
        'elearn_reporting' => ['reports', 'view'],
        'library_manage' => ['library', 'manage'],
        'library_catalog' => ['library', 'manage'],
        'library_circulation' => ['library', 'manage'],
        'library_fines' => ['library', 'manage'],
        'library_digital' => ['library', 'manage'],
        'view_courses' => ['courses', 'view'],
        'edit_courses' => ['courses', 'edit'],
        'manage_grades' => ['ca_upload', 'upload'],
        'view_students' => ['student_records', 'view'],
        'upload_materials' => ['elearning', 'upload'],
        'manage_assignments' => ['elearning', 'manage'],
        'view_profile' => ['dashboard', 'view'],
        'view_schedule' => ['dashboard', 'view'],
        'transport_view' => ['transport', 'view'],
        'transport_manage' => ['transport', 'manage'],
        'exam_view' => ['ca_upload', 'view'],
        'exam_enter_marks' => ['ca_upload', 'upload'],
        'exam_upload_results' => ['ca_upload', 'upload'],
        'exam_manage' => ['ca_approval', 'approve'],
        'city_guilds_manage' => ['student_records', 'manage']
    ];

    if (isset($map[$permissionName])) {
        return hasPermission($userIdDb, $map[$permissionName][0], $map[$permissionName][1]);
    }

    if (strpos($permissionName, '.') !== false) {
        $parts = explode('.', $permissionName, 2);
        return hasPermission($userIdDb, $parts[0], $parts[1]);
    }

    return false;
}

/**
 * Check if user is admin
 */
function isSystemsAdmin(): bool {
    return hasRole(ROLE_SYSTEMS_ADMIN);
}

/**
 * Check if user can access admin panel
 */
function canAccessAdmin(): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    return $userIdDb !== null && (hasPermission($userIdDb, 'settings', 'manage') || hasRole(ROLE_SYSTEMS_ADMIN));
}

/**
 * Check if user can access finance section
 */
function canAccessFinance(): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    return $userIdDb !== null && canAccessModule($userIdDb, 'fees');
}

/**
 * Check if user can access admissions section
 */
function canAccessAdmissions(): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    return $userIdDb !== null && canAccessModule($userIdDb, 'admissions');
}

/**
 * Check if user can access the registrar portal.
 *
 * Registrar is a staff role rather than a standalone module_key in the current
 * RBAC schema; page-level actions still check their module permissions.
 */
function canAccessRegistrar(): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    return $userIdDb !== null && (hasRole(ROLE_REGISTRAR) || hasRole(ROLE_SYSTEMS_ADMIN));
}

/**
 * Check if user can access academics section
 */
function canAccessAcademics(): bool {
    if (hasAnyRole([
        ROLE_LECTURER,
        ROLE_HEAD_OF_DEPARTMENT,
        ROLE_DEAN,
        ROLE_REGISTRAR,
        ROLE_EXAMS_OFFICER,
        ROLE_SYSTEMS_ADMIN,
    ])) {
        return true;
    }
    $sessionRole = wuc_normalize_staff_role((string)($_SESSION['role'] ?? ''), false);
    if (in_array($sessionRole, [
        'lecturer', 'head_of_department', 'dean', 'registrar', 'exams_officer', 'systems_admin',
    ], true)) {
        return true;
    }
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    return $userIdDb !== null && (canAccessModule($userIdDb, 'programmes') || canAccessModule($userIdDb, 'courses') || canAccessModule($userIdDb, 'lecturer_assignment'));
}

/**
 * Check if user can control transport operations.
 *
 * Access comes from either a transport module grant (systems_admin /
 * transport_officer roles, or a direct user_module_access row) or from
 * heading the Transport section: the head_of_department role carries no
 * transport module grant and assign_hod.php does not add one, so the
 * section assignment itself is the source of truth for the Transport HOS.
 */
function canAccessTransport(): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    if ($userIdDb !== null && canAccessModule($userIdDb, 'transport')) {
        return true;
    }
    return wuc_staff_heads_transport_section();
}

/**
 * Whether the logged-in staff member is the active Head of Section of a
 * transport-type section.
 */
function wuc_staff_heads_transport_section(): bool {
    static $cache = [];

    if (!hasRole(ROLE_HEAD_OF_DEPARTMENT)) {
        return false;
    }

    $staffId = trim((string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? ''));
    if ($staffId === '') {
        return false;
    }
    if (array_key_exists($staffId, $cache)) {
        return $cache[$staffId];
    }

    global $db;
    if (!$db instanceof mysqli) {
        return false;
    }

    $heads = false;
    if ($stmt = @$db->prepare("
        SELECT 1
        FROM staff_section_assignments ssa
        INNER JOIN sections s ON s.section_id = ssa.section_id
        WHERE ssa.staff_id = ?
          AND ssa.role_key = 'head_of_department'
          AND ssa.status = 'active'
          AND s.status = 'active'
          AND s.section_type = 'transport'
        LIMIT 1
    ")) {
        $stmt->bind_param('s', $staffId);
        if ($stmt->execute()) {
            $stmt->store_result();
            $heads = $stmt->num_rows > 0;
        }
        $stmt->close();
    }

    return $cache[$staffId] = $heads;
}

/**
 * Check whether the current user's access is limited to the Transport Section.
 */
function isTransportOnlyUser(): bool {
    if (!canAccessTransport()) {
        return false;
    }
    if (isSystemsAdmin()
        || canAccessAdmissions()
        || canAccessAcademics()
        || canAccessFinance()
        || canAccessExams()
        || canAccessLibrary()
        || canManageStudents()
        || canManageStaff()
        || canAccessReports()
        || canAccessSettings()
        || (function_exists('canAccessElearning') && canAccessElearning())) {
        return false;
    }
    return true;
}

/**
 * Check if user can access library section
 */
function canAccessLibrary(): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    return $userIdDb !== null && canAccessModule($userIdDb, 'library');
}

/**
 * Check if user can access e-learning section.
 */
if (!function_exists('canAccessElearning')) {
    function canAccessElearning(): bool {
        $userIdDb = $_SESSION['user_id_db'] ?? null;
        return $userIdDb !== null && canAccessModule($userIdDb, 'elearning');
    }
}

/**
 * Check if user can access exams section
 */
function canAccessExams(): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    return $userIdDb !== null && (canAccessModule($userIdDb, 'ca_upload') || canAccessModule($userIdDb, 'ca_approval'));
}

/**
 * Check if the current staff user can enter or upload final exam marks.
 */
function canEnterExamMarks(): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    return $userIdDb !== null && hasPermission($userIdDb, 'ca_upload', 'upload');
}

/**
 * Stricter gate for the lecturer-module exam result entry page.
 */
function canEnterLecturerExamResults(): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    if ($userIdDb === null) return false;
    if (isSystemsAdmin()) return true;
    return hasPermission($userIdDb, 'ca_upload', 'upload');
}

/**
 * Check if user can access staff management
 */
function canManageStaff(): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    return $userIdDb !== null && hasPermission($userIdDb, 'users_roles', 'manage');
}

/**
 * Check if user can access students management
 */
function canManageStudents(): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    return $userIdDb !== null && canAccessModule($userIdDb, 'student_records');
}

/**
 * Check if user can manage City & Guilds candidate, assessment, support, QA,
 * and Walled Garden export records.
 */
function canManageCityGuilds(): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    return $userIdDb !== null && hasPermission($userIdDb, 'student_records', 'manage');
}

/**
 * Check if user can access settings section
 */
function canAccessSettings(): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    return $userIdDb !== null && hasPermission($userIdDb, 'settings', 'manage');
}

/**
 * Check if user can access reports section
 */
function canAccessReports(): bool {
    $userIdDb = $_SESSION['user_id_db'] ?? null;
    return $userIdDb !== null && canAccessModule($userIdDb, 'reports');
}

/**
 * Get role from database for a staff member
 */
function getStaffRole(mysqli $db, string $staff_id): ?string {
    $stmt = $db->prepare("SELECT assigned_access FROM access_right WHERE staff_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $staff_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['assigned_access'] ?? null;
}

/**
 * Set or update role for a staff member
 */
function setStaffRole(mysqli $db, string $staff_id, string $role): bool {
    $hasUserId = false;
    if ($res = $db->query("SHOW COLUMNS FROM access_right LIKE 'UserID'")) {
        $hasUserId = $res->num_rows > 0;
        $res->free();
    }

    // Check if entry exists
    $check = $db->prepare("SELECT id FROM access_right WHERE staff_id = ? LIMIT 1");
    if (!$check) {
        return false;
    }
    $check->bind_param('s', $staff_id);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    if ($exists) {
        $stmt = $hasUserId
            ? $db->prepare("UPDATE access_right SET assigned_access = ?, UserID = ?, updated_at = NOW() WHERE staff_id = ?")
            : $db->prepare("UPDATE access_right SET assigned_access = ?, updated_at = NOW() WHERE staff_id = ?");
        if (!$stmt) {
            return false;
        }
        if ($hasUserId) {
            $stmt->bind_param('sss', $role, $staff_id, $staff_id);
        } else {
            $stmt->bind_param('ss', $role, $staff_id);
        }
    } else {
        $stmt = $hasUserId
            ? $db->prepare("INSERT INTO access_right (staff_id, UserID, assigned_access, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())")
            : $db->prepare("INSERT INTO access_right (staff_id, assigned_access, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
        if (!$stmt) {
            return false;
        }
        if ($hasUserId) {
            $stmt->bind_param('sss', $staff_id, $staff_id, $role);
        } else {
            $stmt->bind_param('ss', $staff_id, $role);
        }
    }

    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

/**
 * Require specific role(s) to access a page.
 *
 * Redirects with an error message if the current user lacks every allowed role.
 * The default target is the absolute staff hub — NOT a relative '../index.php',
 * which resolves to the student portal from staff module folders (e.g. admin/)
 * and wrongly bounced unauthorized staff out of the staff area.
 */
function requireRole(array $allowedRoles, string $redirectUrl = '/wucportal/portal_selection.php'): void {
    if (!hasAnyRole($allowedRoles)) {
        $_SESSION['errorMssg'] = 'Access denied. You do not have permission to access this page.';
        header("Location: $redirectUrl");
        exit();
    }
}

/**
 * Restrict student/staff account and portal management to systems administrators.
 */
function wuc_require_systems_admin(string $redirectUrl = '/wucportal/portal_selection.php'): void {
    if (hasRole(ROLE_SYSTEMS_ADMIN)) {
        return;
    }
    $message = 'Account and portal management is restricted to system administrators.';
    $_SESSION['errorMssg'] = $message;
    $_SESSION['errorMessage'] = $message;
    if (!function_exists('wuc_permission_denied')) {
        require_once __DIR__ . '/permissions.php';
    }
    wuc_permission_denied($message, $redirectUrl);
}

/**
 * Get badge color for a role
 */
function getRoleBadgeClass(?string $role): string {
    $normalizedRole = normalizeRole($role);
    $classes = [
        ROLE_SYSTEMS_ADMIN => 'bg-danger',
        ROLE_LECTURER => 'bg-primary',
        ROLE_HEAD_OF_DEPARTMENT => 'bg-info',
        ROLE_DEAN => 'bg-warning text-dark',
        ROLE_REGISTRAR => 'bg-success',
        ROLE_ADMISSION_OFFICER => 'bg-secondary',
        ROLE_ACCOUNTANT => 'bg-dark',
        ROLE_LIBRARIAN => 'bg-info',
        ROLE_STAFF => 'bg-light text-dark',
    ];
    return $classes[$normalizedRole] ?? 'bg-secondary';
}
