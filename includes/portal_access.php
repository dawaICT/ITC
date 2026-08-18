<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

if (!function_exists('wuc_portal_table_exists')) {
    function wuc_portal_table_exists(mysqli $db, string $table): bool
    {
        static $cache = [];
        $key = strtolower($table);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $safe = $db->real_escape_string($table);
        $res = $db->query("SHOW TABLES LIKE '{$safe}'");
        $cache[$key] = $res && $res->num_rows > 0;
        if ($res) {
            $res->free();
        }
        return $cache[$key];
    }
}

if (!function_exists('wuc_portal_id')) {
    function wuc_portal_id(mysqli $db, string $portalCode): ?int
    {
        if (!wuc_portal_table_exists($db, 'portals')) {
            return null;
        }

        $stmt = $db->prepare("SELECT id FROM portals WHERE portal_code = ? AND status = 'active' LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $portalCode);
        $stmt->execute();
        $stmt->bind_result($portalId);
        $found = $stmt->fetch();
        $stmt->close();

        return $found ? (int)$portalId : null;
    }
}

if (!function_exists('wuc_grant_user_portal_access')) {
    function wuc_grant_user_portal_access(mysqli $db, int $userId, array $portalCodes, string $assignedBy = 'system'): void
    {
        if ($userId <= 0 || !wuc_portal_table_exists($db, 'portals') || !wuc_portal_table_exists($db, 'user_portal_access')) {
            return;
        }

        $portalCodes = array_values(array_unique(array_filter(array_map(
            static fn($code) => strtolower(trim((string)$code)),
            $portalCodes
        ))));

        foreach ($portalCodes as $portalCode) {
            $portalId = wuc_portal_id($db, $portalCode);
            if ($portalId === null) {
                continue;
            }

            $stmt = $db->prepare(
                "INSERT INTO user_portal_access (user_id, portal_id, access_status, start_date, end_date, assigned_by)
                 VALUES (?, ?, 'active', NULL, NULL, ?)
                 ON DUPLICATE KEY UPDATE access_status = 'active', end_date = NULL, assigned_by = VALUES(assigned_by), updated_at = NOW()"
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare portal access assignment.');
            }
            $stmt->bind_param('iis', $userId, $portalId, $assignedBy);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Unable to assign portal access.');
            }
            $stmt->close();
        }
    }
}

if (!function_exists('wuc_user_has_elearning_work')) {
    function wuc_user_has_elearning_work(mysqli $db, int $userId): bool
    {
        $stmt = $db->prepare('SELECT student_id, staff_id, primary_role FROM users WHERE user_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user) {
            return false;
        }

        $studentId = trim((string)($user['student_id'] ?? ''));
        if ($studentId !== '') {
            $checks = [
                "SELECT 1 FROM course_registration WHERE Sid = ? AND COALESCE(is_active, 1) = 1 LIMIT 1",
                "SELECT 1 FROM student_courses WHERE student_id = ? AND status IN ('registered', 'completed', 'incomplete') LIMIT 1",
                "SELECT 1 FROM student_course_registrations scr INNER JOIN student_program sp ON sp.id = scr.student_programme_id WHERE sp.Sid = ? AND scr.registration_status IN ('REGISTERED', 'COMPLETED', 'REPEATING') LIMIT 1",
            ];
            foreach ($checks as $sql) {
                if ($stmt = $db->prepare($sql)) {
                    $stmt->bind_param('s', $studentId);
                    $stmt->execute();
                    $ok = $stmt->get_result()->num_rows > 0;
                    $stmt->close();
                    if ($ok) {
                        return true;
                    }
                }
            }
            return false;
        }

        $staffId = trim((string)($user['staff_id'] ?? ''));
        if ($staffId === '') {
            return false;
        }

        $role = strtolower(trim((string)($user['primary_role'] ?? '')));
        if (in_array($role, ['systems_admin', 'lecturer', 'head_of_department'], true)) {
            return true;
        }

        $checks = [
            "SELECT 1 FROM course_lecturer WHERE staff_id = ? AND COALESCE(status, 'active') = 'active' LIMIT 1",
            "SELECT 1 FROM lecturer_course_assignments WHERE staff_id = ? AND status = 'active' LIMIT 1",
            "SELECT 1
               FROM user_roles ur
               INNER JOIN roles r ON r.role_id = ur.role_id
              WHERE ur.user_id = ?
                AND ur.status = 'active'
                AND r.status = 'active'
                AND r.role_name IN ('systems_admin', 'lecturer', 'head_of_department')
              LIMIT 1",
        ];

        foreach ($checks as $sql) {
            if ($stmt = $db->prepare($sql)) {
                if (strpos($sql, 'ur.user_id') !== false) {
                    $stmt->bind_param('i', $userId);
                } else {
                    $stmt->bind_param('s', $staffId);
                }
                $stmt->execute();
                $ok = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if ($ok) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('wuc_revoke_user_portal_access')) {
    function wuc_revoke_user_portal_access(mysqli $db, int $userId, array $portalCodes, string $assignedBy = 'system'): void
    {
        if ($userId <= 0 || !wuc_portal_table_exists($db, 'portals') || !wuc_portal_table_exists($db, 'user_portal_access')) {
            return;
        }

        $portalCodes = array_values(array_unique(array_filter(array_map(
            static fn($code) => strtolower(trim((string)$code)),
            $portalCodes
        ))));

        foreach ($portalCodes as $portalCode) {
            $portalId = wuc_portal_id($db, $portalCode);
            if ($portalId === null) {
                continue;
            }

            $stmt = $db->prepare(
                "UPDATE user_portal_access
                    SET access_status = 'disabled', end_date = CURDATE(), assigned_by = ?, updated_at = NOW()
                  WHERE user_id = ? AND portal_id = ? AND access_status = 'active'"
            );
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('sii', $assignedBy, $userId, $portalId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('wuc_load_portal_user_row')) {
    function wuc_load_portal_user_row(mysqli $db, int $userId): ?array
    {
        if ($userId <= 0 || !wuc_portal_table_exists($db, 'users')) {
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

if (!function_exists('wuc_inactive_student_statuses')) {
    function wuc_inactive_student_statuses(): array
    {
        return ['inactive', 'suspended', 'blocked', 'disabled', 'withdrawn', 'deleted'];
    }
}

if (!function_exists('wuc_user_is_staff_account')) {
    function wuc_user_is_staff_account(mysqli $db, int $userId, ?array $user = null): bool
    {
        $user = $user ?? wuc_load_portal_user_row($db, $userId);
        if (!$user) {
            return false;
        }

        $staffId = trim((string)($user['staff_id'] ?? ''));
        if ($staffId !== '') {
            return true;
        }

        $role = strtolower(trim((string)($user['primary_role'] ?? '')));
        if ($role === '' || $role === 'student' || $role === 'applicant') {
            return false;
        }

        $staffRoles = [
            'systems_admin', 'lecturer', 'head_of_department', 'registrar', 'accountant',
            'librarian', 'admissions', 'dean', 'admin', 'staff', 'vc', 'hod',
        ];
        if (in_array($role, $staffRoles, true)) {
            return true;
        }

        if (!wuc_portal_table_exists($db, 'user_roles') || !wuc_portal_table_exists($db, 'roles')) {
            return false;
        }

        $stmt = $db->prepare(
            "SELECT 1
               FROM user_roles ur
               INNER JOIN roles r ON r.role_id = ur.role_id
              WHERE ur.user_id = ?
                AND ur.status = 'active'
                AND r.status = 'active'
                AND r.role_name <> 'student'
              LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $isStaff = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $isStaff;
    }
}

if (!function_exists('wuc_user_is_fully_registered_student')) {
    function wuc_user_is_fully_registered_student(mysqli $db, int $userId, ?array $user = null): bool
    {
        $user = $user ?? wuc_load_portal_user_row($db, $userId);
        if (!$user) {
            return false;
        }

        $studentId = trim((string)($user['student_id'] ?? ''));
        if ($studentId === '' || !wuc_portal_table_exists($db, 'students')) {
            return false;
        }

        $stmt = $db->prepare('SELECT status FROM students WHERE SID = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$student) {
            return false;
        }

        $status = strtolower(trim((string)($student['status'] ?? 'active')));
        if (in_array($status, wuc_inactive_student_statuses(), true)) {
            return false;
        }

        if (wuc_portal_table_exists($db, 'student_login')) {
            $loginStmt = $db->prepare('SELECT 1 FROM student_login WHERE Sid = ? LIMIT 1');
            if (!$loginStmt) {
                return false;
            }
            $loginStmt->bind_param('s', $studentId);
            $loginStmt->execute();
            $hasLogin = $loginStmt->get_result()->num_rows > 0;
            $loginStmt->close();
            if (!$hasLogin) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('wuc_user_applicant_lookup_keys')) {
    /**
     * @return list<string> lowercased identifiers that may match applicant rows
     */
    function wuc_user_applicant_lookup_keys(mysqli $db, int $userId, ?array $user = null): array
    {
        $user = $user ?? wuc_load_portal_user_row($db, $userId);
        if (!$user) {
            return [];
        }

        $keys = [];
        foreach (['username', 'student_id'] as $field) {
            $value = strtolower(trim((string)($user[$field] ?? '')));
            if ($value !== '') {
                $keys[] = $value;
            }
        }

        if (wuc_portal_table_exists($db, 'user_profiles')) {
            $stmt = $db->prepare('SELECT applicant_id, student_id FROM user_profiles WHERE user_id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $profile = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($profile) {
                    foreach (['applicant_id', 'student_id'] as $field) {
                        $value = strtolower(trim((string)($profile[$field] ?? '')));
                        if ($value !== '') {
                            $keys[] = $value;
                        }
                    }
                }
            }
        }

        $studentId = trim((string)($user['student_id'] ?? ''));
        if ($studentId !== '' && wuc_portal_table_exists($db, 'students')) {
            $stmt = $db->prepare('SELECT email, nrc_pass, mobile FROM students WHERE SID = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $studentId);
                $stmt->execute();
                $student = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($student) {
                    foreach (['email', 'nrc_pass', 'mobile'] as $field) {
                        $value = strtolower(trim((string)($student[$field] ?? '')));
                        if ($value !== '') {
                            $keys[] = $value;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }
}

if (!function_exists('wuc_user_applicant_stage')) {
    /**
     * Applicant lifecycle for accounts that are not yet fully registered students.
     *
     * @return null|'pending'|'accepted'
     */
    function wuc_user_applicant_stage(mysqli $db, int $userId, ?array $user = null): ?string
    {
        if (wuc_user_is_fully_registered_student($db, $userId, $user)) {
            return null;
        }

        $keys = wuc_user_applicant_lookup_keys($db, $userId, $user);
        if ($keys === []) {
            return null;
        }

        $stage = null;

        if (wuc_portal_table_exists($db, 'processed_applicants')) {
            foreach ($keys as $key) {
                $stmt = $db->prepare(
                    "SELECT status
                       FROM processed_applicants
                      WHERE LOWER(TRIM(email)) = ?
                         OR LOWER(TRIM(nrc_pass)) = ?
                         OR TRIM(mobile) = ?
                      ORDER BY id DESC
                      LIMIT 1"
                );
                if (!$stmt) {
                    continue;
                }
                $stmt->bind_param('sss', $key, $key, $key);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    continue;
                }
                $status = strtolower(trim((string)($row['status'] ?? '')));
                if ($status === 'accepted') {
                    return 'accepted';
                }
                if ($status !== '' && $status !== 'rejected') {
                    $stage = 'pending';
                }
            }
        }

        if ($stage !== null) {
            return $stage;
        }

        if (wuc_portal_table_exists($db, 'online_applicants')) {
            foreach ($keys as $key) {
                $stmt = $db->prepare(
                    "SELECT status
                       FROM online_applicants
                      WHERE LOWER(TRIM(email)) = ?
                         OR LOWER(TRIM(nrc_pass)) = ?
                         OR TRIM(mobile) = ?
                      ORDER BY id DESC
                      LIMIT 1"
                );
                if (!$stmt) {
                    continue;
                }
                $stmt->bind_param('sss', $key, $key, $key);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    continue;
                }
                $status = strtolower(trim((string)($row['status'] ?? 'pending')));
                if ($status === 'accepted') {
                    return 'accepted';
                }
                if ($status === 'pending' || $status === '') {
                    return 'pending';
                }
            }
        }

        if (wuc_portal_table_exists($db, 'user_portal_access') && wuc_portal_table_exists($db, 'portals')) {
            $stmt = $db->prepare(
                "SELECT 1
                   FROM user_portal_access upa
                   INNER JOIN portals p ON p.id = upa.portal_id
                  WHERE upa.user_id = ?
                    AND p.portal_code = 'applicant'
                    AND p.status = 'active'
                    AND upa.access_status = 'active'
                  LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $hasApplicantGrant = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if ($hasApplicantGrant) {
                    return 'pending';
                }
            }
        }

        return null;
    }
}

if (!function_exists('wuc_student_is_alumni_eligible')) {
    /**
     * Alumni eligibility is driven by graduation clearance only.
     * Approved / Graduated → eligible; anything else (or no clearance row) → not.
     */
    function wuc_student_is_alumni_eligible(mysqli $db, string $studentId): bool
    {
        $studentId = trim($studentId);
        if ($studentId === '' || !wuc_portal_table_exists($db, 'student_clearance')) {
            return false;
        }

        $stmt = $db->prepare(
            'SELECT graduation_status FROM student_clearance WHERE student_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $status = (string)($row['graduation_status'] ?? '');
        return in_array($status, ['Approved', 'Graduated'], true);
    }
}

if (!function_exists('wuc_user_is_alumni_eligible')) {
    function wuc_user_is_alumni_eligible(mysqli $db, int $userId, ?array $user = null): bool
    {
        $user = $user ?? wuc_load_portal_user_row($db, $userId);
        if (!$user) {
            return false;
        }

        $studentId = trim((string)($user['student_id'] ?? ''));
        // Non-student accounts are not gated by graduation clearance here.
        if ($studentId === '') {
            return true;
        }

        return wuc_student_is_alumni_eligible($db, $studentId);
    }
}

if (!function_exists('wuc_filter_portals_by_account_lifecycle')) {
    function wuc_filter_portals_by_account_lifecycle(mysqli $db, int $userId, array $portals): array
    {
        if ($portals === []) {
            return [];
        }

        $user = wuc_load_portal_user_row($db, $userId);
        if (!$user) {
            return $portals;
        }

        if (wuc_user_is_staff_account($db, $userId, $user)) {
            return $portals;
        }

        if (wuc_user_is_fully_registered_student($db, $userId, $user)) {
            $portals = array_values(array_filter(
                $portals,
                static fn(array $portal): bool => strtolower((string)$portal['portal_code']) !== 'applicant'
            ));
        } else {
            $applicantStage = wuc_user_applicant_stage($db, $userId, $user);
            if ($applicantStage === 'pending') {
                return array_values(array_filter(
                    $portals,
                    static fn(array $portal): bool => strtolower((string)$portal['portal_code']) === 'applicant'
                ));
            }
            if ($applicantStage !== null) {
                $allowed = ['applicant', 'academic'];
                $portals = array_values(array_filter(
                    $portals,
                    static fn(array $portal): bool => in_array(strtolower((string)$portal['portal_code']), $allowed, true)
                ));
            }
        }

        // Never surface Alumni for a student who is not Approved/Graduated —
        // even if a stale user_portal_access grant remains.
        if (!wuc_user_is_alumni_eligible($db, $userId, $user)) {
            $portals = array_values(array_filter(
                $portals,
                static fn(array $portal): bool => strtolower((string)$portal['portal_code']) !== 'alumni'
            ));
        }

        return $portals;
    }
}

if (!function_exists('wuc_portal_access_allowed_by_lifecycle')) {
    function wuc_portal_access_allowed_by_lifecycle(mysqli $db, int $userId, string $portalCode): bool
    {
        $portalCode = strtolower(trim($portalCode));
        $user = wuc_load_portal_user_row($db, $userId);
        if (!$user) {
            return true;
        }

        if (wuc_user_is_staff_account($db, $userId, $user)) {
            return true;
        }

        if ($portalCode === 'alumni' && !wuc_user_is_alumni_eligible($db, $userId, $user)) {
            return false;
        }

        if ($portalCode === 'applicant' && wuc_user_is_fully_registered_student($db, $userId, $user)) {
            return false;
        }

        $applicantStage = wuc_user_applicant_stage($db, $userId, $user);
        if ($applicantStage === null) {
            return true;
        }

        if ($applicantStage === 'pending') {
            return $portalCode === 'applicant';
        }

        return in_array($portalCode, ['applicant', 'academic', 'elearning'], true);
    }
}

if (!function_exists('wuc_sync_student_portal_access_after_registration')) {
    function wuc_sync_student_portal_access_after_registration(mysqli $db, int $userId): void
    {
        if ($userId <= 0 || !wuc_user_is_fully_registered_student($db, $userId)) {
            return;
        }

        wuc_revoke_user_portal_access($db, $userId, ['applicant'], 'registration');
        wuc_grant_user_portal_access($db, $userId, ['academic'], 'registration');

        if (wuc_user_has_elearning_work($db, $userId)) {
            wuc_grant_user_portal_access($db, $userId, ['elearning'], 'registration');
        }
    }
}

if (!function_exists('wuc_infer_portal_access')) {
    function wuc_infer_portal_access(mysqli $db, int $userId, string $portalCode): bool
    {
        if ($portalCode === 'academic') {
            return true;
        }
        if ($portalCode === 'elearning') {
            return wuc_user_has_elearning_work($db, $userId);
        }
        return false;
    }
}

if (!function_exists('wuc_user_has_portal_access')) {
    function wuc_user_has_portal_access(mysqli $db, int $userId, string $portalCode): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $portalCode = strtolower(trim($portalCode));
        if (!wuc_portal_access_allowed_by_lifecycle($db, $userId, $portalCode)) {
            return false;
        }

        if (!wuc_portal_table_exists($db, 'portals') || !wuc_portal_table_exists($db, 'user_portal_access')) {
            return wuc_infer_portal_access($db, $userId, $portalCode);
        }

        $stmt = $db->prepare("
            SELECT 1
              FROM user_portal_access upa
              INNER JOIN portals p ON p.id = upa.portal_id
             WHERE upa.user_id = ?
               AND p.portal_code = ?
               AND p.status = 'active'
               AND upa.access_status = 'active'
               AND (upa.start_date IS NULL OR upa.start_date <= CURDATE())
               AND (upa.end_date IS NULL OR upa.end_date >= CURDATE())
             LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('is', $userId, $portalCode);
        $stmt->execute();
        $allowed = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($allowed) {
            return true;
        }

        $countStmt = $db->prepare('SELECT COUNT(*) AS total FROM user_portal_access WHERE user_id = ?');
        if (!$countStmt) {
            return false;
        }
        $countStmt->bind_param('i', $userId);
        $countStmt->execute();
        $row = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();

        return (int)($row['total'] ?? 0) === 0 && wuc_infer_portal_access($db, $userId, $portalCode);
    }
}

if (!function_exists('wuc_resolve_session_user_id')) {
    function wuc_resolve_session_user_id(mysqli $db): int
    {
        $sessionUserId = (int)($_SESSION['user_id_db'] ?? 0);
        if ($sessionUserId > 0) {
            return $sessionUserId;
        }

        if (!wuc_portal_table_exists($db, 'users')) {
            return 0;
        }

        $staffId = trim((string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? ''));
        $studentId = trim((string)($_SESSION['Sid'] ?? ''));
        $username = trim((string)($_SESSION['username'] ?? $_SESSION['login_username'] ?? ''));

        $sql = "SELECT user_id, staff_id, student_id, primary_role
                  FROM users
                 WHERE (staff_id <> '' AND staff_id = ?)
                    OR (student_id <> '' AND student_id = ?)
                    OR (username <> '' AND username = ?)
                 LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('sss', $staffId, $studentId, $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if (!$user) {
            return 0;
        }

        $userId = (int)($user['user_id'] ?? 0);
        if ($userId <= 0) {
            return 0;
        }

        $_SESSION['user_id_db'] = $userId;
        if ($staffId === '' && !empty($user['staff_id'])) {
            $_SESSION['staff_id'] = (string)$user['staff_id'];
        }
        if ($studentId === '' && !empty($user['student_id'])) {
            $_SESSION['Sid'] = (string)$user['student_id'];
        }
        if (empty($_SESSION['role']) && !empty($user['primary_role'])) {
            $_SESSION['role'] = (string)$user['primary_role'];
        }

        return $userId;
    }
}

if (!function_exists('wuc_user_active_portals')) {
    function wuc_user_active_portals(mysqli $db, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        if (!wuc_portal_table_exists($db, 'portals') || !wuc_portal_table_exists($db, 'user_portal_access')) {
            $codes = ['academic'];
            if (wuc_infer_portal_access($db, $userId, 'elearning')) {
                $codes[] = 'elearning';
            }
            return array_map(static fn($code) => ['portal_code' => $code, 'portal_name' => ucfirst($code) . ' Portal'], $codes);
        }

        $portals = [];
        $stmt = $db->prepare("
            SELECT p.portal_code, p.portal_name, COALESCE(p.description, '') AS description
              FROM user_portal_access upa
              INNER JOIN portals p ON p.id = upa.portal_id
             WHERE upa.user_id = ?
               AND p.status = 'active'
               AND upa.access_status = 'active'
               AND (upa.start_date IS NULL OR upa.start_date <= CURDATE())
               AND (upa.end_date IS NULL OR upa.end_date >= CURDATE())
             ORDER BY FIELD(p.portal_code, 'academic', 'elearning', 'library', 'applicant', 'alumni', 'employer'), p.portal_name
        ");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $portals[] = $row;
            }
            $stmt->close();
        }

        if (!$portals) {
            foreach (['academic', 'elearning'] as $code) {
                if (wuc_infer_portal_access($db, $userId, $code)) {
                    $portals[] = [
                        'portal_code' => $code,
                        'portal_name' => $code === 'academic' ? 'Academic Portal' : 'eLearning Portal',
                        'description' => '',
                    ];
                }
            }
        }

        return wuc_filter_portals_by_account_lifecycle($db, $userId, $portals);
    }
}

if (!function_exists('wuc_portal_direct_landing_url')) {
    function wuc_portal_direct_landing_url(string $portalCode): ?string
    {
        $portalCode = strtolower(trim($portalCode));
        $map = [
            'library' => '/wucportal/library/index.php',
            'applicant' => '/wucportal/admissions/applicant_portal.php',
            'alumni' => '/wucportal/alumni/index.php',
            'employer' => '/wucportal/employer/index.php',
            'enterprise' => '/wucportal/enterprise/index.php',
        ];

        return $map[$portalCode] ?? null;
    }
}

if (!function_exists('wuc_portal_landing_url')) {
    function wuc_portal_landing_url(string $portalCode, string $userKind): string
    {
        $portalCode = strtolower(trim($portalCode));
        $userKind = strtolower(trim($userKind));

        $directLanding = wuc_portal_direct_landing_url($portalCode);
        if ($directLanding !== null) {
            return $directLanding;
        }

        if ($portalCode === 'elearning') {
            return $userKind === 'student'
                ? '/wucportal/students/elearning/index.php'
                : '/wucportal/elearning/index.php';
        }

        if ($userKind === 'student') {
            return '/wucportal/students/index.php';
        }

        $allRoles = $_SESSION['all_roles'] ?? [];
        if (is_array($allRoles) && count($allRoles) > 1) {
            return '/wucportal/role_selection.php';
        }

        require_once __DIR__ . '/helpers/redirect_helper.php';
        return wuc_staff_landing_url((string)($_SESSION['role'] ?? 'staff'));
    }
}

if (!function_exists('wuc_after_login_portal_url')) {
    function wuc_after_login_portal_url(mysqli $db, int $userId, string $userKind): string
    {
        $portals = wuc_user_active_portals($db, $userId);
        if (count($portals) > 1) {
            return '/wucportal/portal_selection.php';
        }
        if (count($portals) === 1) {
            $_SESSION['current_portal'] = (string)$portals[0]['portal_code'];
            return wuc_portal_landing_url((string)$portals[0]['portal_code'], $userKind);
        }

        return $userKind === 'student' ? '/wucportal/student_login.php' : '/wucportal/staff_login.php';
    }
}

if (!function_exists('wuc_require_portal_access')) {
    function wuc_require_portal_access(mysqli $db, string $portalCode, ?string $message = null): void
    {
        $userId = wuc_resolve_session_user_id($db);
        if ($userId > 0 && wuc_user_has_portal_access($db, $userId, $portalCode)) {
            $_SESSION['current_portal'] = $portalCode;
            return;
        }

        if (!function_exists('audit_log_current_user')) {
            $auditHelper = __DIR__ . '/audit.php';
            if (is_file($auditHelper)) {
                require_once $auditHelper;
            }
        }
        if (function_exists('audit_log_current_user')) {
            audit_log_current_user($db, 'security.portal_access_denied', [
                'portal' => $portalCode,
                'resolved_user_id' => $userId,
                'message' => $message,
            ]);
        }

        $isStudent = !empty($_SESSION['Sid']) || ($_SESSION['user_role'] ?? '') === 'student';
        $flashKey = $isStudent ? 'errorMssg' : 'errorMessage';
        $_SESSION[$flashKey] = $message ?: 'You do not have permission to access this page.';
        $target = $isStudent ? '/wucportal/student_login.php' : '/wucportal/portal_selection.php';

        if ($portalCode === 'elearning') {
            $_SESSION[$flashKey] = $message ?: 'Your account is active, but eLearning access has not been assigned. Please contact the Registrar or eLearning Administrator.';
            $target = $isStudent ? '/wucportal/elearning_login.php' : '/wucportal/portal_selection.php';
        }

        wuc_safe_redirect($target, 302, $target);
    }
}
