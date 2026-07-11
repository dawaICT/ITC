<?php
declare(strict_types=1);

/**
 * Academic Structure Integrity Audit  (Multi-Portal Redesign — Phase 5/10)
 *
 * Read-only, system-wide health check of the academic relationship chain the
 * ITC/TEVETA spec requires to stay intact:
 *
 *   Section → Department → Programme → Course → Lecturer assignment → Student registration
 *
 * Per-operation guards already exist (wuc_registration_guard,
 * wuc_legacy_course_registration_guard, period/structure validation). This adds
 * the missing whole-database view: it surfaces orphaned or misconfigured rows so
 * they can be fixed before they break registration, CA, results or eLearning.
 *
 * wuc_academic_structure_audit() returns an ordered list of checks, each:
 *   ['key','label','severity'('warn'|'fail'),'count','ok'(bool),'hint','sql_ok'(bool)]
 * A check with ok=false and count>0 needs attention; severity ranks the impact.
 */

if (!function_exists('wuc_asa_table_exists')) {
    function wuc_asa_table_exists(mysqli $db, string $table): bool
    {
        static $cache = [];
        $key = strtolower($table);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $res = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
        $cache[$key] = $res && $res->num_rows > 0;
        if ($res) { $res->free(); }
        return $cache[$key];
    }
}

if (!function_exists('wuc_asa_count')) {
    /** Run a COUNT(*) query, returning [count, ranSuccessfully]. */
    function wuc_asa_count(mysqli $db, string $sql): array
    {
        $res = @$db->query($sql);
        if (!$res) {
            return [0, false];
        }
        $row = $res->fetch_row();
        $res->free();
        return [(int)($row[0] ?? 0), true];
    }
}

if (!function_exists('wuc_academic_structure_audit')) {
    function wuc_academic_structure_audit(mysqli $db): array
    {
        // course_code is valid if it exists in the catalogue (courses) or in the
        // short-course catalogue (short_courses). Build the membership clause once.
        $courseCatalogues = [];
        if (wuc_asa_table_exists($db, 'courses')) {
            $courseCatalogues[] = "SELECT course_code FROM courses";
        }
        if (wuc_asa_table_exists($db, 'short_courses')) {
            $courseCatalogues[] = "SELECT course_code FROM short_courses";
        }
        $catalogueUnion = $courseCatalogues ? implode(' UNION ', $courseCatalogues) : '';

        // Each definition: requires the listed tables; otherwise it is skipped.
        $definitions = [
            [
                'key' => 'departments_without_section',
                'label' => 'Departments not linked to an active section',
                'severity' => 'fail',
                'requires' => ['departments', 'sections'],
                'hint' => 'Set departments.section_id to a valid active section (Engineering & ICT or Transport).',
                'sql' => "SELECT COUNT(*) FROM departments d
                          LEFT JOIN sections s ON s.section_id = d.section_id AND s.status = 'active'
                          WHERE d.status = 'active' AND (d.section_id IS NULL OR s.section_id IS NULL)",
            ],
            [
                'key' => 'sections_without_hos',
                'label' => 'Active sections with no active Head of Section assigned',
                'severity' => 'fail',
                'requires' => ['sections', 'staff_section_assignments'],
                'hint' => 'Assign a HOS in staff_section_assignments; HOS dashboards need an active assignment.',
                'sql' => "SELECT COUNT(*) FROM sections s
                          WHERE s.status = 'active'
                            AND NOT EXISTS (SELECT 1 FROM staff_section_assignments a
                                            WHERE a.section_id = s.section_id AND a.status = 'active')",
            ],
            [
                'key' => 'programmes_without_department',
                'label' => 'Programmes not linked to a valid department',
                'severity' => 'fail',
                'requires' => ['programs', 'departments'],
                'hint' => 'Set programs.department_id to an existing departments.id.',
                'sql' => "SELECT COUNT(*) FROM programs p
                          LEFT JOIN departments d ON d.id = p.department_id
                          WHERE p.department_id IS NULL OR d.id IS NULL",
            ],
            [
                'key' => 'programme_courses_orphan_programme',
                'label' => 'Programme-course mappings referencing a missing programme',
                'severity' => 'fail',
                'requires' => ['program_courses', 'programs'],
                'hint' => 'Remove or repair program_courses rows whose program_code has no programme.',
                'sql' => "SELECT COUNT(*) FROM program_courses pc
                          LEFT JOIN programs p ON p.program_code = pc.program_code
                          WHERE p.program_code IS NULL",
            ],
            [
                'key' => 'programme_courses_orphan_course',
                'label' => 'Programme-course mappings referencing a course not in any catalogue',
                'severity' => 'warn',
                'requires' => ['program_courses'],
                'needs_catalogue' => true,
                'hint' => 'Add the course to courses/short_courses, or remove the stale program_courses row.',
                'sql' => "SELECT COUNT(*) FROM program_courses pc
                          WHERE pc.course_code NOT IN ({$catalogueUnion})",
            ],
            [
                'key' => 'lecturer_assignment_orphan_course',
                'label' => 'Lecturer assignments for a course not in any catalogue',
                'severity' => 'warn',
                'requires' => ['course_lecturer'],
                'needs_catalogue' => true,
                'hint' => 'A lecturer is assigned to a course that no longer exists; clean up course_lecturer.',
                'sql' => "SELECT COUNT(*) FROM course_lecturer cl
                          WHERE COALESCE(cl.status,'active') = 'active'
                            AND cl.course_code NOT IN ({$catalogueUnion})",
            ],
            [
                'key' => 'lecturer_assignment_orphan_programme',
                'label' => 'Lecturer assignments referencing a missing programme',
                'severity' => 'warn',
                'requires' => ['course_lecturer', 'programs'],
                'hint' => 'course_lecturer.program_code points to a programme that does not exist.',
                'sql' => "SELECT COUNT(*) FROM course_lecturer cl
                          LEFT JOIN programs p ON p.program_code = cl.program_code
                          WHERE cl.program_code IS NOT NULL AND cl.program_code <> ''
                            AND p.program_code IS NULL",
            ],
            [
                'key' => 'registration_orphan_course',
                'label' => 'Active student registrations for a course not in any catalogue',
                'severity' => 'fail',
                'requires' => ['course_registration'],
                'needs_catalogue' => true,
                'hint' => 'Students are registered against a course that no longer exists; results/eLearning will break.',
                'sql' => "SELECT COUNT(*) FROM course_registration cr
                          WHERE (cr.is_active = 1 OR cr.status = 'active')
                            AND cr.course_code NOT IN ({$catalogueUnion})",
            ],
        ];

        $results = [];
        foreach ($definitions as $def) {
            // Skip checks whose tables are absent, or catalogue checks with no catalogue.
            $missingTable = false;
            foreach ($def['requires'] as $t) {
                if (!wuc_asa_table_exists($db, $t)) { $missingTable = true; break; }
            }
            if ($missingTable || (!empty($def['needs_catalogue']) && $catalogueUnion === '')) {
                $results[] = [
                    'key' => $def['key'],
                    'label' => $def['label'],
                    'severity' => $def['severity'],
                    'count' => 0,
                    'ok' => true,
                    'skipped' => true,
                    'hint' => $def['hint'],
                    'sql_ok' => false,
                ];
                continue;
            }

            [$count, $ranOk] = wuc_asa_count($db, $def['sql']);
            $results[] = [
                'key' => $def['key'],
                'label' => $def['label'],
                'severity' => $def['severity'],
                'count' => $count,
                'ok' => $ranOk ? ($count === 0) : true,
                'skipped' => false,
                'hint' => $def['hint'],
                'sql_ok' => $ranOk,
            ];
        }

        return $results;
    }
}

if (!function_exists('wuc_academic_structure_audit_summary')) {
    /** Roll the audit up to ['fail'=>n,'warn'=>n,'ok'=>n,'skipped'=>n,'clean'=>bool]. */
    function wuc_academic_structure_audit_summary(array $results): array
    {
        $summary = ['fail' => 0, 'warn' => 0, 'ok' => 0, 'skipped' => 0];
        foreach ($results as $r) {
            if (!empty($r['skipped'])) { $summary['skipped']++; continue; }
            if ($r['ok']) { $summary['ok']++; continue; }
            $summary[$r['severity']] = ($summary[$r['severity']] ?? 0) + 1;
        }
        $summary['clean'] = ($summary['fail'] === 0 && $summary['warn'] === 0);
        return $summary;
    }
}
