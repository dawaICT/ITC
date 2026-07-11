<?php
declare(strict_types=1);

/**
 * Complete student account provisioning: login stores, RBAC, and portal access.
 *
 * admissionsEnsureStudentLogin() duplicated partial logic; this is the single
 * entry point for student login/RBAC repair after registration or admin action.
 */

require_once dirname(__DIR__) . '/auth_helpers.php';
require_once dirname(__DIR__) . '/schema_guard.php';

if (!function_exists('wuc_ensure_student_role_permissions')) {
    function wuc_ensure_student_role_permissions(mysqli $db): void
    {
        if (!function_exists('wuc_ensure_role_permission')) {
            require_once __DIR__ . '/staff_provisioning.php';
        }
        foreach (['dashboard.view', 'elearning.view'] as $perm) {
            wuc_ensure_role_permission($db, 'student', $perm);
        }
    }
}

if (!function_exists('wuc_provision_student_account')) {
    /**
     * Create or repair student login, users, user_roles, and portal access.
     *
     * @param array{
     *   plain_password?:string|null,
     *   email?:string|null,
     *   only_create_login?:bool,
     *   assigned_by?:string
     * } $options
     * @return array{ok:bool,user_id:int,messages:string[],has_program:bool}
     */
    function wuc_provision_student_account(mysqli $db, string $studentId, array $options = []): array
    {
        $result = [
            'ok' => false,
            'user_id' => 0,
            'messages' => [],
            'has_program' => false,
        ];

        $studentId = trim($studentId);
        if ($studentId === '') {
            $result['messages'][] = 'Empty student ID.';
            return $result;
        }

        $plainPassword = $options['plain_password'] ?? null;
        $email = isset($options['email']) ? trim((string)$options['email']) : null;
        $onlyCreateLogin = (bool)($options['only_create_login'] ?? true);
        $assignedBy = (string)($options['assigned_by'] ?? 'system');

        if (!wuc_table_exists($db, 'students')) {
            $result['messages'][] = 'students table missing.';
            return $result;
        }

        $stmt = $db->prepare('SELECT SID, nrc_pass, email, status FROM students WHERE SID = ? LIMIT 1');
        if (!$stmt) {
            $result['messages'][] = 'Student lookup failed.';
            return $result;
        }
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$student) {
            $result['messages'][] = "Student {$studentId} not found.";
            return $result;
        }

        if ($email === null || $email === '') {
            $email = trim((string)($student['email'] ?? ''));
        }

        if (wuc_table_exists($db, 'student_program')) {
            $sp = $db->prepare('SELECT 1 FROM student_program WHERE Sid = ? AND COALESCE(status, "active") = "active" LIMIT 1');
            if ($sp) {
                $sp->bind_param('s', $studentId);
                $sp->execute();
                $result['has_program'] = $sp->get_result()->num_rows > 0;
                $sp->close();
            }
        }

        $loginExists = false;
        if (wuc_table_exists($db, 'student_login')) {
            $chk = $db->prepare('SELECT 1 FROM student_login WHERE Sid = ? LIMIT 1');
            if ($chk) {
                $chk->bind_param('s', $studentId);
                $chk->execute();
                $loginExists = $chk->get_result()->num_rows > 0;
                $chk->close();
            }
        }

        if ($plainPassword === null || $plainPassword === '') {
            $nrc = trim((string)($student['nrc_pass'] ?? ''));
            $plainPassword = $nrc !== '' ? $nrc : $studentId;
        }

        $hashForUsers = null;
        if (!$loginExists) {
            $hashForUsers = password_hash((string)$plainPassword, PASSWORD_DEFAULT);

            if (wuc_table_exists($db, 'student_login')) {
                $columns = [];
                $res = $db->query('SHOW COLUMNS FROM student_login');
                while ($row = $res->fetch_assoc()) {
                    $columns[$row['Field']] = true;
                }
                $fields = ['Sid' => $studentId, 'Password' => $hashForUsers];
                if (isset($columns['Email']) && $email !== '') {
                    $fields['Email'] = $email;
                }
                if (isset($columns['must_change_password'])) {
                    $fields['must_change_password'] = '1';
                }
                $colSql = implode(', ', array_map(static fn($c) => "`{$c}`", array_keys($fields)));
                $placeholders = implode(', ', array_fill(0, count($fields), '?'));
                $sql = "INSERT INTO student_login ({$colSql}) VALUES ({$placeholders})";
                if ($ins = $db->prepare($sql)) {
                    $types = str_repeat('s', count($fields));
                    $vals = array_values($fields);
                    $ins->bind_param($types, ...$vals);
                    if ($ins->execute()) {
                        $result['messages'][] = 'student_login created';
                        $loginExists = true;
                    }
                    $ins->close();
                }
            }
        } else {
            $result['messages'][] = 'student_login preserved';
            if ($onlyCreateLogin && wuc_table_exists($db, 'student_login')) {
                $pwStmt = $db->prepare('SELECT Password FROM student_login WHERE Sid = ? LIMIT 1');
                if ($pwStmt) {
                    $pwStmt->bind_param('s', $studentId);
                    $pwStmt->execute();
                    $pwStmt->bind_result($existingHash);
                    if ($pwStmt->fetch() && is_string($existingHash) && $existingHash !== '') {
                        $hashForUsers = $existingHash;
                    }
                    $pwStmt->close();
                }
            }
            if ($hashForUsers === null) {
                $hashForUsers = password_hash((string)$plainPassword, PASSWORD_DEFAULT);
            }
        }

        if ($hashForUsers !== null && $hashForUsers !== '') {
            wuc_sync_student_password($db, $studentId, $hashForUsers);
            $result['messages'][] = 'users/RBAC synced';
        }

        $userId = 0;
        if ($lookup = $db->prepare('SELECT user_id FROM users WHERE student_id = ? OR username = ? LIMIT 1')) {
            $lookup->bind_param('ss', $studentId, $studentId);
            $lookup->execute();
            $lookup->bind_result($userId);
            $lookup->fetch();
            $lookup->close();
        }
        $userId = (int)$userId;
        if ($userId <= 0) {
            $result['messages'][] = 'users row missing after sync';
            return $result;
        }
        $result['user_id'] = $userId;

        wuc_ensure_student_role_permissions($db);

        require_once dirname(__DIR__) . '/portal_access.php';
        if (function_exists('wuc_sync_student_portal_access_after_registration')) {
            wuc_sync_student_portal_access_after_registration($db, $userId);
            $result['messages'][] = 'portal access synced';
        }

        $result['ok'] = true;
        if (!$result['has_program']) {
            $result['messages'][] = 'warning: no active student_program row';
        }

        return $result;
    }
}

if (!function_exists('wuc_repair_student_account_on_login')) {
    function wuc_repair_student_account_on_login(mysqli $db, string $studentId, int $userId): void
    {
        if ($studentId === '' || $userId <= 0) {
            return;
        }
        $hasRole = false;
        if (wuc_table_exists($db, 'user_roles') && wuc_table_exists($db, 'roles')) {
            $stmt = $db->prepare(
                'SELECT 1 FROM user_roles ur
                 INNER JOIN roles r ON r.role_id = ur.role_id
                 WHERE ur.user_id = ? AND r.role_name = "student" AND ur.status = "active"
                 LIMIT 1'
            );
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $hasRole = $stmt->get_result()->num_rows > 0;
                $stmt->close();
            }
        }
        if ($hasRole) {
            return;
        }
        wuc_provision_student_account($db, $studentId, [
            'only_create_login' => true,
            'assigned_by' => 'login_repair',
        ]);
    }
}
