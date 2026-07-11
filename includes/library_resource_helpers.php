<?php
/**
 * Academic Resource Centre — resource scoping helpers (lr_*).
 *
 * These functions translate "who is asking" (a student's registered courses /
 * programme / department, or a lecturer's assigned courses) into "which library
 * resources they should see", using the additive library_resource_links table
 * (see migrations/20260630_library_resource_links.sql). They are the backbone of
 * the course-based / programme-based / department library views described in the
 * Academic Resource Centre spec.
 *
 * Design rules followed from the codebase conventions:
 *  - Every table touched is checked with wuc_table_exists() first, so a portal
 *    that has not yet applied the migration degrades gracefully (returns empty)
 *    instead of throwing.
 *  - All borrower/scope values are bound via prepared statements.
 *  - Nothing here mutates existing tables; reads reuse library_items,
 *    library_digital_resources and lesson_notes.
 */

require_once __DIR__ . '/schema_guard.php';

if (!function_exists('lr_distinct_strings')) {
    /** Run a single-column SELECT and return the column as a de-duplicated string list. */
    function lr_distinct_strings(mysqli $db, string $sql, string $types, array $params): array
    {
        $out = [];
        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_row())) {
                $v = (string) $row[0];
                if ($v !== '') {
                    $out[$v] = true;
                }
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('lr_distinct_strings failed: ' . $e->getMessage());
            return [];
        }
        return array_keys($out);
    }
}

if (!function_exists('lr_student_scope')) {
    /**
     * Resolve a student's academic scope.
     *
     * @return array{courses:string[],programmes:string[],departments:string[]}
     */
    function lr_student_scope(mysqli $db, string $Sid): array
    {
        $scope = ['courses' => [], 'programmes' => [], 'departments' => []];
        if ($Sid === '') {
            return $scope;
        }

        if (wuc_table_exists($db, 'course_registration')) {
            $scope['courses'] = lr_distinct_strings(
                $db,
                'SELECT DISTINCT course_code FROM course_registration WHERE Sid = ?',
                's',
                [$Sid]
            );
        }

        if (wuc_table_exists($db, 'student_program')) {
            $scope['programmes'] = lr_distinct_strings(
                $db,
                'SELECT DISTINCT program_code FROM student_program WHERE Sid = ?',
                's',
                [$Sid]
            );
        }

        $scope['departments'] = lr_departments_for_programmes($db, $scope['programmes']);
        return $scope;
    }
}

if (!function_exists('lr_lecturer_scope')) {
    /**
     * Resolve a lecturer's academic scope from their active course assignments.
     *
     * @return array{courses:string[],programmes:string[],departments:string[]}
     */
    function lr_lecturer_scope(mysqli $db, string $staffId): array
    {
        $scope = ['courses' => [], 'programmes' => [], 'departments' => []];
        if ($staffId === '' || !wuc_table_exists($db, 'course_lecturer')) {
            return $scope;
        }
        $scope['courses'] = lr_distinct_strings(
            $db,
            "SELECT DISTINCT course_code FROM course_lecturer
             WHERE staff_id = ? AND COALESCE(status,'active') = 'active'",
            's',
            [$staffId]
        );
        $scope['programmes'] = lr_distinct_strings(
            $db,
            "SELECT DISTINCT program_code FROM course_lecturer
             WHERE staff_id = ? AND COALESCE(status,'active') = 'active' AND program_code IS NOT NULL AND program_code <> ''",
            's',
            [$staffId]
        );
        $scope['departments'] = lr_departments_for_programmes($db, $scope['programmes']);
        return $scope;
    }
}

if (!function_exists('lr_departments_for_programmes')) {
    /** Map a set of programme codes to their department ids (as strings). */
    function lr_departments_for_programmes(mysqli $db, array $programmes): array
    {
        $programmes = array_values(array_filter(array_map('strval', $programmes), static fn($p) => $p !== ''));
        if (!$programmes || !wuc_table_exists($db, 'programs')) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($programmes), '?'));
        return lr_distinct_strings(
            $db,
            "SELECT DISTINCT department_id FROM programs
             WHERE program_code IN ($ph) AND department_id IS NOT NULL",
            str_repeat('s', count($programmes)),
            $programmes
        );
    }
}

if (!function_exists('lr_visible_resource_ids')) {
    /**
     * Resource ids of $kind ('item'|'digital') visible to the given scope.
     * Always includes 'public' resources. $audience selects which visibility
     * bands apply: 'students' => students+all; anything else (staff) => all bands.
     *
     * @param array{courses:string[],programmes:string[],departments:string[]} $scope
     * @return int[]
     */
    function lr_visible_resource_ids(mysqli $db, array $scope, string $kind = 'item', string $audience = 'students'): array
    {
        if (!wuc_table_exists($db, 'library_resource_links')) {
            return [];
        }
        $kind = $kind === 'digital' ? 'digital' : 'item';

        $where = ["resource_kind = ?"];
        $types = 's';
        $params = [$kind];

        // Visibility band filter.
        if ($audience === 'students') {
            $where[] = "visibility IN ('students','all')";
        } // staff see every band — no extra filter.

        // Scope filter: public OR a matching course/programme/department.
        $scopeClauses = ["scope_type = 'public'"];
        foreach (['course' => 'courses', 'programme' => 'programmes', 'department' => 'departments'] as $scopeType => $key) {
            $vals = array_values(array_filter(array_map('strval', $scope[$key] ?? []), static fn($v) => $v !== ''));
            if (!$vals) {
                continue;
            }
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $scopeClauses[] = "(scope_type = ? AND scope_ref IN ($ph))";
            $types .= 's' . str_repeat('s', count($vals));
            $params[] = $scopeType;
            foreach ($vals as $v) {
                $params[] = $v;
            }
        }
        $where[] = '(' . implode(' OR ', $scopeClauses) . ')';

        $sql = 'SELECT DISTINCT resource_id FROM library_resource_links WHERE ' . implode(' AND ', $where);
        $ids = [];
        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_row())) {
                $ids[] = (int) $row[0];
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('lr_visible_resource_ids failed: ' . $e->getMessage());
            return [];
        }
        return $ids;
    }
}

if (!function_exists('lr_course_resources')) {
    /**
     * The unified resource list for a single course: linked catalogue items,
     * linked digital resources, and any lesson_notes carrying that course_code.
     * This is the per-course library the spec asks for.
     *
     * @return array{items:array,digital:array,notes:array}
     */
    function lr_course_resources(mysqli $db, string $courseCode, string $audience = 'students'): array
    {
        $bundle = ['items' => [], 'digital' => [], 'notes' => []];
        if ($courseCode === '') {
            return $bundle;
        }
        $scope = ['courses' => [$courseCode], 'programmes' => [], 'departments' => []];

        // Catalogue items linked to this course.
        if (wuc_table_exists($db, 'library_items')) {
            $itemIds = lr_visible_resource_ids($db, $scope, 'item', $audience);
            // Drop the implicit 'public' matches for a *course* view: we only want
            // items explicitly tied to this course here.
            $itemIds = lr_filter_ids_for_course($db, $itemIds, 'item', $courseCode);
            if ($itemIds) {
                $ph = implode(',', array_fill(0, count($itemIds), '?'));
                $bundle['items'] = lr_fetch_rows(
                    $db,
                    "SELECT id, title, authors, item_type, pub_year, subject
                     FROM library_items WHERE id IN ($ph) ORDER BY title",
                    str_repeat('i', count($itemIds)),
                    $itemIds
                );
            }
        }

        // Digital resources linked to this course.
        if (wuc_table_exists($db, 'library_digital_resources')) {
            $digIds = lr_visible_resource_ids($db, $scope, 'digital', $audience);
            $digIds = lr_filter_ids_for_course($db, $digIds, 'digital', $courseCode);
            if ($digIds) {
                $ph = implode(',', array_fill(0, count($digIds), '?'));
                $bundle['digital'] = lr_fetch_rows(
                    $db,
                    "SELECT id, title, resource_type, url, access_level, subject
                     FROM library_digital_resources WHERE id IN ($ph) ORDER BY title",
                    str_repeat('i', count($digIds)),
                    $digIds
                );
            }
        }

        // Lecturer notes already keyed by course_code (eLearning integration).
        if (wuc_table_exists($db, 'lesson_notes')) {
            $bundle['notes'] = lr_fetch_rows(
                $db,
                "SELECT id, topic, url, dte, notes FROM lesson_notes
                 WHERE course_code = ? ORDER BY COALESCE(dte, created_at) DESC, id DESC LIMIT 50",
                's',
                [$courseCode]
            );
        }

        return $bundle;
    }
}

if (!function_exists('lr_filter_ids_for_course')) {
    /** Restrict a candidate id list to those explicitly linked to one course. */
    function lr_filter_ids_for_course(mysqli $db, array $ids, string $kind, string $courseCode): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids || !wuc_table_exists($db, 'library_resource_links')) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = lr_distinct_strings(
            $db,
            "SELECT resource_id FROM library_resource_links
             WHERE resource_kind = ? AND scope_type = 'course' AND scope_ref = ? AND resource_id IN ($ph)",
            's' . 's' . str_repeat('i', count($ids)),
            array_merge([$kind, $courseCode], $ids)
        );
        return array_map('intval', $rows);
    }
}

if (!function_exists('lr_fetch_rows')) {
    /** Generic prepared SELECT → array of assoc rows. */
    function lr_fetch_rows(mysqli $db, string $sql, string $types, array $params): array
    {
        $out = [];
        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $out[] = $row;
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('lr_fetch_rows failed: ' . $e->getMessage());
            return [];
        }
        return $out;
    }
}

if (!function_exists('lr_link_resource')) {
    /**
     * Link a resource to an academic scope (idempotent via the unique key).
     * Used by the seed and by the (Phase 2) librarian/lecturer linking UI.
     */
    function lr_link_resource(
        mysqli $db,
        string $kind,
        int $resourceId,
        string $scopeType,
        ?string $scopeRef,
        string $visibility = 'all',
        ?string $createdBy = null
    ): bool {
        if (!wuc_table_exists($db, 'library_resource_links')) {
            return false;
        }
        $kind = $kind === 'digital' ? 'digital' : 'item';
        $scopeType = in_array($scopeType, ['course', 'programme', 'department', 'public'], true) ? $scopeType : 'public';
        $visibility = in_array($visibility, ['students', 'staff', 'all'], true) ? $visibility : 'all';
        if ($scopeType === 'public') {
            $scopeRef = null;
        }
        try {
            $stmt = $db->prepare(
                'INSERT INTO library_resource_links
                    (resource_kind, resource_id, scope_type, scope_ref, visibility, created_by)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE visibility = VALUES(visibility), created_by = VALUES(created_by)'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sissss', $kind, $resourceId, $scopeType, $scopeRef, $visibility, $createdBy);
            $ok = $stmt->execute();
            $stmt->close();
            return (bool) $ok;
        } catch (Throwable $e) {
            error_log('lr_link_resource failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('lr_unlink_resource')) {
    /** Remove a specific resource→scope link. */
    function lr_unlink_resource(mysqli $db, int $linkId): bool
    {
        if (!wuc_table_exists($db, 'library_resource_links')) {
            return false;
        }
        try {
            $stmt = $db->prepare('DELETE FROM library_resource_links WHERE id = ?');
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('i', $linkId);
            $ok = $stmt->execute();
            $stmt->close();
            return (bool) $ok;
        } catch (Throwable $e) {
            error_log('lr_unlink_resource failed: ' . $e->getMessage());
            return false;
        }
    }
}
