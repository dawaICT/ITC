<?php
require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/staff_role_helpers.php';

wuc_guard_start_session('elearning');

function elearning_require_role(array $allowedRoles): void
{
    global $db;

    $staffId = trim((string) ($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? ''));
    $studentId = trim((string) ($_SESSION['Sid'] ?? ''));
    if ($staffId === '' && $studentId === '') {
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }

    if ($studentId !== '' && in_array('student', $allowedRoles, true)) {
        return;
    }

    if ($staffId !== '') {
        $resolved = wuc_resolve_staff_roles($db, $staffId);
        $assignedRoles = (array) ($resolved['all_roles'] ?? []);
        if (in_array('systems_admin', $assignedRoles, true)) {
            return;
        }

        $allowedCanonical = [];
        foreach ($allowedRoles as $allowedRole) {
            if ($allowedRole !== 'student') {
                $allowedCanonical[] = wuc_normalize_staff_role((string) $allowedRole, false);
            }
        }
        if (array_intersect($assignedRoles, $allowedCanonical)) {
            return;
        }
    }

    http_response_code(403);
    echo 'Forbidden';
    exit;
}
