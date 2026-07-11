<?php
declare(strict_types=1);

/**
 * Role-scoped keyword portal search (zero-cost AI, Sprint 8).
 *
 * Plain LIKE search across students, staff, programmes, courses and
 * departments, filtered by what the caller's role may see:
 *   - staff scope: all entity types
 *   - student scope: programmes and courses only
 *
 * Recent queries are logged to search_recent for the "recent searches" UX and
 * later analytics. Optional embedding search (ai/match.php pattern) can layer
 * on top later without changing this contract.
 */

require_once __DIR__ . '/schema_guard.php';

if (!function_exists('wuc_portal_search')) {
    /**
     * @param array $opts ['scope' => 'staff'|'student', 'limit_per_type' => int]
     * @return array ['query' =>, 'total' =>, 'groups' => [type => [ ['label','sublabel','entity_id'], … ]], 'suggestions' => []]
     */
    function wuc_portal_search(mysqli $db, string $query, array $opts = []): array
    {
        $scope = ($opts['scope'] ?? 'staff') === 'student' ? 'student' : 'staff';
        $limit = max(1, min(20, (int)($opts['limit_per_type'] ?? 8)));
        $query = trim($query);

        $out = ['query' => $query, 'total' => 0, 'groups' => [], 'suggestions' => []];
        if (mb_strlen($query) < 2) {
            $out['suggestions'][] = 'Type at least 2 characters to search.';
            return $out;
        }
        $like = '%' . $query . '%';

        $run = static function (string $sql, string $types, array $params) use ($db): array {
            $rows = [];
            try {
                if ($stmt = $db->prepare($sql)) {
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $rows[] = $row;
                    }
                    $stmt->close();
                }
            } catch (Throwable $e) {
                error_log('wuc_portal_search query failed: ' . $e->getMessage());
            }
            return $rows;
        };

        // Programmes and courses are visible to every scope.
        if (wuc_table_exists($db, 'programs')) {
            $rows = $run(
                "SELECT program_code, program_name, program_type FROM programs
                 WHERE (program_code LIKE ? OR program_name LIKE ?) AND (is_active = 1 OR is_active IS NULL)
                 ORDER BY program_name LIMIT {$limit}",
                'ss',
                [$like, $like]
            );
            foreach ($rows as $r) {
                $out['groups']['programs'][] = [
                    'label' => (string)$r['program_name'],
                    'sublabel' => (string)$r['program_code'] . ' · ' . (string)$r['program_type'],
                    'entity_id' => (string)$r['program_code'],
                ];
            }
        }
        if (wuc_table_exists($db, 'courses')) {
            $rows = $run(
                "SELECT course_code, course_name, course_type FROM courses
                 WHERE (course_code LIKE ? OR course_name LIKE ?) AND COALESCE(status, 'active') = 'active'
                 ORDER BY course_name LIMIT {$limit}",
                'ss',
                [$like, $like]
            );
            foreach ($rows as $r) {
                $out['groups']['courses'][] = [
                    'label' => (string)$r['course_name'],
                    'sublabel' => (string)$r['course_code'] . ' · ' . (string)$r['course_type'],
                    'entity_id' => (string)$r['course_code'],
                ];
            }
        }

        if ($scope === 'staff') {
            if (wuc_table_exists($db, 'students')) {
                $rows = $run(
                    "SELECT SID, Fname, Lname, program, status FROM students
                     WHERE SID LIKE ? OR CONCAT(COALESCE(Fname, ''), ' ', COALESCE(Lname, '')) LIKE ?
                     ORDER BY Fname, Lname LIMIT {$limit}",
                    'ss',
                    [$like, $like]
                );
                foreach ($rows as $r) {
                    $out['groups']['students'][] = [
                        'label' => trim((string)$r['Fname'] . ' ' . (string)$r['Lname']),
                        'sublabel' => (string)$r['SID'] . ' · ' . (string)($r['program'] ?? '') . ' · ' . (string)($r['status'] ?? ''),
                        'entity_id' => (string)$r['SID'],
                    ];
                }
            }
            if (wuc_table_exists($db, 'staff')) {
                $rows = $run(
                    "SELECT staff_id, Fname, Lname, role, status FROM staff
                     WHERE staff_id LIKE ? OR CONCAT(COALESCE(Fname, ''), ' ', COALESCE(Lname, '')) LIKE ?
                     ORDER BY Fname, Lname LIMIT {$limit}",
                    'ss',
                    [$like, $like]
                );
                foreach ($rows as $r) {
                    $out['groups']['staff'][] = [
                        'label' => trim((string)$r['Fname'] . ' ' . (string)$r['Lname']),
                        'sublabel' => (string)$r['staff_id'] . ' · ' . (string)($r['role'] ?? ''),
                        'entity_id' => (string)$r['staff_id'],
                    ];
                }
            }
            if (wuc_table_exists($db, 'departments')) {
                $rows = $run(
                    "SELECT id, department_name, department_code FROM departments
                     WHERE (department_name LIKE ? OR department_code LIKE ?) AND COALESCE(status, 'active') = 'active'
                     ORDER BY department_name LIMIT {$limit}",
                    'ss',
                    [$like, $like]
                );
                foreach ($rows as $r) {
                    $out['groups']['departments'][] = [
                        'label' => (string)$r['department_name'],
                        'sublabel' => (string)($r['department_code'] ?? ''),
                        'entity_id' => (string)$r['id'],
                    ];
                }
            }
        }

        foreach ($out['groups'] as $items) {
            $out['total'] += count($items);
        }
        if ($out['total'] === 0) {
            $out['suggestions'][] = 'No matches for "' . $query . '". Try a shorter keyword, a code (e.g. ICT), or check the spelling.';
        }

        return $out;
    }
}

if (!function_exists('wuc_search_log_recent')) {
    function wuc_search_log_recent(mysqli $db, string $userId, string $userRole, string $query, int $resultCount): void
    {
        $userId = trim($userId);
        $query = trim(mb_substr($query, 0, 500));
        if ($userId === '' || $query === '' || !wuc_table_exists($db, 'search_recent')) {
            return;
        }
        try {
            if ($stmt = $db->prepare('INSERT INTO search_recent (user_id, user_role, query_text, result_count) VALUES (?, ?, ?, ?)')) {
                $stmt->bind_param('sssi', $userId, $userRole, $query, $resultCount);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('wuc_search_log_recent failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wuc_search_recent_queries')) {
    function wuc_search_recent_queries(mysqli $db, string $userId, int $limit = 5): array
    {
        $userId = trim($userId);
        if ($userId === '' || !wuc_table_exists($db, 'search_recent')) {
            return [];
        }
        $limit = max(1, min(15, $limit));
        try {
            $sql = "SELECT query_text, MAX(searched_at) AS last_at
                    FROM search_recent WHERE user_id = ?
                    GROUP BY query_text ORDER BY last_at DESC LIMIT {$limit}";
            if (!$stmt = $db->prepare($sql)) {
                return [];
            }
            $stmt->bind_param('s', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $queries = [];
            while ($row = $res->fetch_assoc()) {
                $queries[] = (string)$row['query_text'];
            }
            $stmt->close();
            return $queries;
        } catch (Throwable $e) {
            error_log('wuc_search_recent_queries failed: ' . $e->getMessage());
            return [];
        }
    }
}
