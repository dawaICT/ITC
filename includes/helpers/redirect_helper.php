<?php
declare(strict_types=1);
/**
 * Redirect & post-login routing helpers.
 *
 * Centralises two things that were previously hardcoded or scattered:
 *   1. wuc_redirect_to()       — a safe, header-based redirect that uses the
 *                                app's absolute URL constants rather than
 *                                fragile relative ../ paths.
 *   2. wuc_staff_landing_url() — resolves a staff member's canonical role to
 *                                the module dashboard whose guard is GUARANTEED
 *                                to accept that role, falling back to the
 *                                universal staff hub for module-less roles.
 *
 * The role -> module map below mirrors the access rules each module's
 * includes/nav.php enforces (via nav_unified.php -> canAccess*()), so a user is
 * never redirected into a module that would immediately bounce them back to the
 * login page (which would create a redirect loop).
 *
 *   Module guard (required_access -> check)  | Accepts canonical role
 *   -----------------------------------------+------------------------------
 *   admin       -> canAccessAdmin            | systems_admin
 *   admissions  -> canAccessAdmissions       | systems_admin, admission_officer, registrar
 *   registrar   -> canAccessRegistrar        | systems_admin, registrar
 *   lecturers   -> canAccessAcademics        | systems_admin, lecturer, dean, registrar
 *   dean        -> canAccessAcademics        | systems_admin, lecturer, dean, registrar
 *   hod         -> (role check)              | systems_admin, head_of_department, dean
 *   accounts    -> canAccessFinance          | systems_admin, accountant, registrar
 *   transport   -> canAccessTransport        | systems_admin, transport_officer (+module perm)
 *   library     -> per library_* permission  | systems_admin, librarian (+library perm)
 *
 * NOTE: library gates on per-staff library_* PERMISSION rows
 * (canManageLibrary/canCirculate/...), not on the 'librarian' role. It is still
 * a safe direct target because library/index.php does NOT redirect on missing
 * access — it renders an in-page "access not granted" card — so there is no
 * loop. A librarian with library permissions lands on the full dashboard; one
 * without sees the card with a link back to the portal.
 *
 * Module-less roles (exams_officer, vc, dvc, ...) fall through to STAFF_DASHBOARD,
 * which now points at portal_selection.php (the generic staff dashboard hub was
 * retired). portal_selection.php refuses to loop: if the academic landing for a
 * role resolves back to itself, it renders an in-page "no workspace assigned"
 * error instead of redirecting.
 */

require_once __DIR__ . '/../../config/auth_constants.php';

if (!function_exists('wuc_redirect_to')) {
    /**
     * Issue a Location redirect and stop. Defaults to 303 (See Other), the
     * correct status after a successful POST so the browser re-requests with GET.
     */
    function wuc_redirect_to(string $url, int $status = 303): void
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, $status);
        }
        exit;
    }
}

if (!function_exists('wuc_staff_landing_url')) {
    /**
     * Map a canonical staff role to the dashboard its own module guard accepts.
     * Module-less / multi-purpose roles fall back to STAFF_DASHBOARD, i.e. the
     * portal selection router (the generic staff dashboard hub was retired).
     */
    function wuc_staff_landing_url(string $canonicalRole): string
    {
        $role = strtolower(trim($canonicalRole));

        $map = [
            'systems_admin'      => ADMIN_DASHBOARD,
            'admission_officer'  => ADMISSIONS_DASHBOARD,
            'registrar'          => REGISTRAR_DASHBOARD,
            'lecturer'           => LECTURERS_DASHBOARD,
            'head_of_department' => HOD_DASHBOARD,
            'dean'               => DEAN_DASHBOARD,
            'accountant'         => ACCOUNTS_DASHBOARD,
            'transport_officer'  => TRANSPORT_DASHBOARD,
            'librarian'          => LIBRARY_DASHBOARD,
        ];

        $target = $map[$role] ?? STAFF_DASHBOARD;

        // For accounts that carry systems_admin (e.g. ITC900 "AllRoles"), never fall back
        // to the generic portal selection router when a dedicated admin portal exists.
        // This ensures "lead direct in the portal" for privileged multi-role users.
        if ($target === STAFF_DASHBOARD) {
            $allRoles = $_SESSION['all_roles'] ?? [];
            if (in_array('systems_admin', (array)$allRoles, true)) {
                return ADMIN_DASHBOARD;
            }
        }

        return $target;
    }
}
