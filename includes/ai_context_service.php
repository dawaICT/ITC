<?php
declare(strict_types=1);

/**
 * AI context resolver.
 *
 * Keeps portal/module/role behavior in the database (`ai_contexts`) while
 * allowing existing AI pages to continue calling wuc_ai_generate().
 */

if (!function_exists('wuc_ai_context_table_exists')) {
    function wuc_ai_context_table_exists(mysqli $db, string $table): bool
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

if (!function_exists('wuc_ai_context_clean_key')) {
    function wuc_ai_context_clean_key(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_ -]/', '', $value) ?? '';
        $value = str_replace([' ', '-'], '_', $value);
        return $value !== '' ? $value : $fallback;
    }
}

if (!function_exists('wuc_ai_context_infer_portal')) {
    function wuc_ai_context_infer_portal(array $options): string
    {
        $explicit = wuc_ai_context_clean_key((string)($options['portal_code'] ?? ''), '');
        if ($explicit !== '') {
            return $explicit;
        }

        $sessionPortal = session_status() === PHP_SESSION_ACTIVE
            ? wuc_ai_context_clean_key((string)($_SESSION['current_portal'] ?? ''), '')
            : '';
        if ($sessionPortal !== '') {
            return $sessionPortal;
        }

        $feature = strtolower((string)($options['feature'] ?? ''));
        $module = strtolower((string)($options['module_name'] ?? ''));
        $haystack = $feature . ' ' . $module;
        if (str_contains($haystack, 'elearning')
            || str_contains($haystack, 'learning')
            || str_contains($haystack, 'tutor')
            || str_contains($haystack, 'quiz')
            || str_contains($haystack, 'assignment')) {
            return 'elearning';
        }

        return 'academic';
    }
}

if (!function_exists('wuc_ai_context_infer_module')) {
    function wuc_ai_context_infer_module(array $options, string $portalCode, string $userRole): string
    {
        $explicit = wuc_ai_context_clean_key((string)($options['module_name'] ?? ''), '');
        if ($explicit !== '') {
            return $explicit;
        }

        $feature = strtolower((string)($options['feature'] ?? ''));
        if (str_contains($feature, 'transport_hos') || str_contains($feature, 'hos_') || str_contains($feature, '_hos')) {
            return 'hos';
        }
        if (str_contains($feature, 'finance') || str_contains($feature, 'fee')) {
            return 'finance';
        }
        if (str_contains($feature, 'library')) {
            return 'library';
        }
        if ($portalCode === 'elearning') {
            return in_array($userRole, ['lecturer', 'teacher', 'instructor', 'systems_admin'], true) ? 'teaching' : 'learning';
        }
        return 'academic';
    }
}

if (!function_exists('wuc_ai_context_normalize_role')) {
    function wuc_ai_context_normalize_role(array $options): string
    {
        $role = (string)($options['user_role'] ?? '');
        if ($role === '' && session_status() === PHP_SESSION_ACTIVE) {
            $role = (string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');
        }
        $role = wuc_ai_context_clean_key($role, 'unknown');

        $map = [
            'student' => 'student',
            'lecturer' => 'lecturer',
            'teacher' => 'lecturer',
            'instructor' => 'lecturer',
            'admin' => 'systems_admin',
            'administrator' => 'systems_admin',
            'systems_admin' => 'systems_admin',
            'systems admin' => 'systems_admin',
            'system admin' => 'systems_admin',
            'system administrator' => 'systems_admin',
            'transport_hos' => 'head_of_department',
            'academic_hos' => 'head_of_department',
            'hos' => 'head_of_department',
            'hod' => 'head_of_department',
            'head_of_section' => 'head_of_department',
            'head_of_department' => 'head_of_department',
            'dean' => 'dean',
            'registrar' => 'registrar',
            'exams' => 'exams_officer',
            'exam_officer' => 'exams_officer',
            'exam officer' => 'exams_officer',
            'exams_officer' => 'exams_officer',
            'exams officer' => 'exams_officer',
            'examination_officer' => 'exams_officer',
            'examination officer' => 'exams_officer',
            'admission_officer' => 'admission_officer',
            'admission officer' => 'admission_officer',
            'admissions_officer' => 'admission_officer',
            'admissions officer' => 'admission_officer',
            'finance' => 'accountant',
            'accountant' => 'accountant',
            'librarian' => 'librarian',
            'library_staff' => 'librarian',
            'transport' => 'transport_officer',
            'transport_officer' => 'transport_officer',
            'transport officer' => 'transport_officer',
            'driver' => 'transport_officer',
            'driving_instructor' => 'transport_officer',
            'driving instructor' => 'transport_officer',
        ];

        return $map[$role] ?? $role;
    }
}

if (!function_exists('wuc_ai_resolve_context')) {
    function wuc_ai_resolve_context(mysqli $db, array $options): array
    {
        if (!wuc_ai_context_table_exists($db, 'ai_contexts') || !wuc_ai_context_table_exists($db, 'portals')) {
            return [
                'portal_code' => wuc_ai_context_infer_portal($options),
                'module_name' => '',
                'user_role' => wuc_ai_context_normalize_role($options),
                'contexts' => [],
                'rules_text' => '',
            ];
        }

        $role = wuc_ai_context_normalize_role($options);
        $portalCode = wuc_ai_context_infer_portal($options);
        $moduleName = wuc_ai_context_infer_module($options, $portalCode, $role);

        $sql = "
            SELECT ac.module_name, ac.user_role, ac.context_type, ac.rules
              FROM ai_contexts ac
              INNER JOIN portals p ON p.id = ac.portal_id
             WHERE p.portal_code = ?
               AND p.status = 'active'
               AND ac.status = 'active'
               AND (ac.module_name = ? OR ac.module_name = 'academic')
               AND (ac.user_role = ? OR ac.user_role = 'staff' OR ac.user_role = '*')
             ORDER BY
               CASE WHEN ac.module_name = ? THEN 0 ELSE 1 END,
               CASE WHEN ac.user_role = ? THEN 0 ELSE 1 END,
               ac.context_type
             LIMIT 3
        ";

        $contexts = [];
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('sssss', $portalCode, $moduleName, $role, $moduleName, $role);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $contexts[] = $row;
            }
            $stmt->close();
        }

        $rules = [];
        foreach ($contexts as $context) {
            $label = trim((string)($context['context_type'] ?? 'AI Context'));
            $body = trim((string)($context['rules'] ?? ''));
            if ($body !== '') {
                $rules[] = $label . ': ' . $body;
            }
        }

        return [
            'portal_code' => $portalCode,
            'module_name' => $moduleName,
            'user_role' => $role,
            'contexts' => $contexts,
            'rules_text' => implode("\n", $rules),
        ];
    }
}
