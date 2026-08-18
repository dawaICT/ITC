<?php
declare(strict_types=1);

/**
 * Test Timetable — shared service (activation, CRUD helpers, conflicts).
 * Lifecycle: draft → scheduling → published → active → closed → archived
 */

require_once __DIR__ . '/schema_guard.php';
require_once __DIR__ . '/lookup_cache.php';

if (!function_exists('tt_h')) {
    function tt_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tt_ensure_schema')) {
    function tt_ensure_schema(mysqli $db): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        if (function_exists('wuc_table_exists')) {
            $ok = wuc_table_exists($db, 'assessment_periods') && wuc_table_exists($db, 'test_timetable');
            return $ok;
        }
        $r = @$db->query("SHOW TABLES LIKE 'assessment_periods'");
        $ok = $r && $r->num_rows > 0;
        if ($r) {
            $r->free();
        }
        return (bool)$ok;
    }
}

if (!function_exists('tt_today')) {
    function tt_today(): string
    {
        return date('Y-m-d');
    }
}

if (!function_exists('tt_normalize_status')) {
    /** Soft-refresh lifecycle based on dates without rewriting unrelated modules. */
    function tt_normalize_status(mysqli $db, array &$period): string
    {
        $status = strtolower((string)($period['status'] ?? 'draft'));
        if (in_array($status, ['archived', 'closed', 'draft'], true)) {
            return $status;
        }
        $today = tt_today();
        $sched = (string)($period['scheduling_start_date'] ?? '');
        $pub = (string)($period['publication_date'] ?? '');
        $end = (string)($period['test_end_date'] ?? '');
        $id = (int)($period['id'] ?? 0);

        $next = $status;
        if ($end !== '' && $today > $end && in_array($status, ['published', 'active', 'scheduling'], true)) {
            $next = 'closed';
        } elseif ($pub !== '' && $today >= $pub && $end !== '' && $today <= $end && in_array($status, ['published', 'active'], true)) {
            $next = 'active';
        } elseif ($sched !== '' && $today >= $sched && $status === 'draft') {
            $next = 'scheduling';
        }

        if ($next !== $status && $id > 0) {
            if ($stmt = $db->prepare('UPDATE assessment_periods SET status = ? WHERE id = ?')) {
                $stmt->bind_param('si', $next, $id);
                $stmt->execute();
                $stmt->close();
            }
            $period['status'] = $next;
        }
        return $next;
    }
}

if (!function_exists('tt_registrar_can_schedule')) {
    function tt_registrar_can_schedule(array $period): bool
    {
        $status = strtolower((string)($period['status'] ?? ''));
        if (in_array($status, ['closed', 'archived'], true)) {
            return false;
        }
        $sched = (string)($period['scheduling_start_date'] ?? '');
        return $sched !== '' && tt_today() >= $sched;
    }
}

if (!function_exists('tt_student_can_view_period')) {
    function tt_student_can_view_period(array $period): bool
    {
        $status = strtolower((string)($period['status'] ?? ''));
        if (!in_array($status, ['published', 'active'], true)) {
            return false;
        }
        $today = tt_today();
        $pub = (string)($period['publication_date'] ?? '');
        $end = (string)($period['test_end_date'] ?? '');
        return $pub !== '' && $end !== '' && $today >= $pub && $today <= $end;
    }
}

if (!function_exists('tt_times_overlap')) {
    function tt_times_overlap(string $s1, string $e1, string $s2, string $e2): bool
    {
        return $s1 < $e2 && $s2 < $e1;
    }
}

if (!function_exists('tt_get_period')) {
    function tt_get_period(mysqli $db, int $id): ?array
    {
        if (!tt_ensure_schema($db) || $id < 1) {
            return null;
        }
        $stmt = $db->prepare('SELECT * FROM assessment_periods WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) {
            tt_normalize_status($db, $row);
        }
        return $row;
    }
}

if (!function_exists('tt_list_periods')) {
    /** @return list<array<string,mixed>> */
    function tt_list_periods(mysqli $db, bool $includeArchived = true): array
    {
        if (!tt_ensure_schema($db)) {
            return [];
        }
        $sql = 'SELECT * FROM assessment_periods';
        if (!$includeArchived) {
            $sql .= " WHERE status <> 'archived'";
        }
        $sql .= ' ORDER BY test_start_date DESC, id DESC LIMIT 50';
        $rows = [];
        if ($res = $db->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                tt_normalize_status($db, $row);
                $rows[] = $row;
            }
            $res->free();
        }
        return $rows;
    }
}

if (!function_exists('tt_current_student_visible_period')) {
    function tt_current_student_visible_period(mysqli $db): ?array
    {
        if (!tt_ensure_schema($db)) {
            return null;
        }
        $today = tt_today();
        $stmt = $db->prepare(
            "SELECT * FROM assessment_periods
             WHERE status IN ('published','active')
               AND publication_date <= ?
               AND test_end_date >= ?
             ORDER BY test_start_date ASC, id ASC
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $today, $today);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) {
            tt_normalize_status($db, $row);
            if (!tt_student_can_view_period($row)) {
                return null;
            }
        }
        return $row;
    }
}

if (!function_exists('tt_create_period')) {
    /** @return array{ok:bool,id?:int,error?:string} */
    function tt_create_period(mysqli $db, array $data, string $createdBy): array
    {
        if (!tt_ensure_schema($db)) {
            return ['ok' => false, 'error' => 'Test timetable tables are missing. Run the migration.'];
        }
        $year = trim((string)($data['academic_year'] ?? ''));
        $term = trim((string)($data['term_label'] ?? ''));
        $sched = trim((string)($data['scheduling_start_date'] ?? ''));
        $pub = trim((string)($data['publication_date'] ?? ''));
        $start = trim((string)($data['test_start_date'] ?? ''));
        $end = trim((string)($data['test_end_date'] ?? ''));
        $apId = isset($data['academic_period_id']) && (string)$data['academic_period_id'] !== ''
            ? (int)$data['academic_period_id'] : 0;

        if ($year === '' || $term === '' || $sched === '' || $pub === '' || $start === '' || $end === '') {
            return ['ok' => false, 'error' => 'All assessment period dates and labels are required.'];
        }
        if ($sched > $pub || $pub > $start || $start > $end) {
            return ['ok' => false, 'error' => 'Dates must follow: scheduling ≤ publication ≤ test start ≤ test end.'];
        }

        $status = tt_today() >= $sched ? 'scheduling' : 'draft';
        try {
            if ($apId > 0) {
                $stmt = $db->prepare(
                    'INSERT INTO assessment_periods
                     (academic_year, term_label, academic_period_id, scheduling_start_date, publication_date,
                      test_start_date, test_end_date, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    return ['ok' => false, 'error' => 'Could not prepare period insert.'];
                }
                $stmt->bind_param('ssissssss', $year, $term, $apId, $sched, $pub, $start, $end, $status, $createdBy);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO assessment_periods
                     (academic_year, term_label, scheduling_start_date, publication_date,
                      test_start_date, test_end_date, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    return ['ok' => false, 'error' => 'Could not prepare period insert.'];
                }
                $stmt->bind_param('ssssssss', $year, $term, $sched, $pub, $start, $end, $status, $createdBy);
            }
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            error_log('tt_create_period: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Failed to create assessment period.'];
        }
    }
}

if (!function_exists('tt_set_period_status')) {
    /** @return array{ok:bool,error?:string} */
    function tt_set_period_status(mysqli $db, int $periodId, string $status): array
    {
        $allowed = ['draft', 'scheduling', 'published', 'active', 'closed', 'archived'];
        $status = strtolower(trim($status));
        if (!in_array($status, $allowed, true)) {
            return ['ok' => false, 'error' => 'Invalid status.'];
        }
        $period = tt_get_period($db, $periodId);
        if (!$period) {
            return ['ok' => false, 'error' => 'Assessment period not found.'];
        }
        if ($status === 'published' || $status === 'active') {
            $conflicts = tt_detect_conflicts($db, $periodId);
            $critical = array_values(array_filter($conflicts, static fn($c) => ($c['severity'] ?? '') === 'critical'));
            if ($critical !== []) {
                return ['ok' => false, 'error' => 'Cannot publish while critical conflicts remain (' . count($critical) . ').'];
            }
            if (!tt_registrar_can_schedule($period) && $status === 'published') {
                // allow publish only from scheduling window; still require scheduling started
            }
            if (tt_today() < (string)$period['scheduling_start_date']) {
                return ['ok' => false, 'error' => 'Scheduling has not started for this period yet.'];
            }
            if ($status === 'published' && tt_today() >= (string)$period['publication_date']
                && tt_today() <= (string)$period['test_end_date']) {
                $status = 'active';
            }
        }
        $stmt = $db->prepare('UPDATE assessment_periods SET status = ? WHERE id = ?');
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Update failed.'];
        }
        $stmt->bind_param('si', $status, $periodId);
        $stmt->execute();
        $stmt->close();
        return ['ok' => true];
    }
}

if (!function_exists('tt_list_entries')) {
    /**
     * @param array{program_codes?:list<string>,course_codes?:list<string>,lecturer_staff_id?:string,limit?:int,offset?:int} $filters
     * @return list<array<string,mixed>>
     */
    function tt_list_entries(mysqli $db, int $periodId, array $filters = []): array
    {
        if (!tt_ensure_schema($db) || $periodId < 1) {
            return [];
        }
        $limit = max(1, min(500, (int)($filters['limit'] ?? 200)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $where = ['t.assessment_period_id = ?', "t.status <> 'cancelled'"];
        $types = 'i';
        $params = [$periodId];

        if (!empty($filters['lecturer_staff_id'])) {
            $where[] = 't.lecturer_staff_id = ?';
            $types .= 's';
            $params[] = (string)$filters['lecturer_staff_id'];
        }
        if (!empty($filters['course_codes']) && is_array($filters['course_codes'])) {
            $codes = array_values(array_filter(array_map('strval', $filters['course_codes'])));
            if ($codes === []) {
                return [];
            }
            $ph = implode(',', array_fill(0, count($codes), '?'));
            $where[] = "t.course_code IN ($ph)";
            $types .= str_repeat('s', count($codes));
            array_push($params, ...$codes);
        }
        if (isset($filters['program_codes']) && is_array($filters['program_codes'])) {
            $pcodes = array_values(array_filter(array_map('strval', $filters['program_codes'])));
            if ($pcodes === []) {
                return [];
            }
            $ph = implode(',', array_fill(0, count($pcodes), '?'));
            $where[] = "t.program_code IN ($ph)";
            $types .= str_repeat('s', count($pcodes));
            array_push($params, ...$pcodes);
        }

        $sql = 'SELECT t.*, p.program_name, c.course_name,
                       cl.room_code, cl.room_name, cl.building,
                       CONCAT(COALESCE(st.Fname, \'\'), \' \', COALESCE(st.Lname, \'\')) AS lecturer_name
                FROM test_timetable t
                LEFT JOIN programs p ON p.program_code = t.program_code
                LEFT JOIN courses c ON c.course_code = t.course_code
                LEFT JOIN classrooms cl ON cl.id = t.classroom_id
                LEFT JOIN staff st ON st.staff_id = t.lecturer_staff_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY t.test_date ASC, t.start_time ASC, t.program_code ASC
                LIMIT ? OFFSET ?';
        $types .= 'ii';
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('tt_save_entry')) {
    /** @return array{ok:bool,id?:int,error?:string} */
    function tt_save_entry(mysqli $db, array $data, string $actor, ?int $id = null): array
    {
        if (!tt_ensure_schema($db)) {
            return ['ok' => false, 'error' => 'Schema missing.'];
        }
        $periodId = (int)($data['assessment_period_id'] ?? 0);
        $period = tt_get_period($db, $periodId);
        if (!$period) {
            return ['ok' => false, 'error' => 'Assessment period not found.'];
        }
        if (!tt_registrar_can_schedule($period)) {
            return ['ok' => false, 'error' => 'Scheduling is not open for this period yet.'];
        }
        if (in_array(strtolower((string)$period['status']), ['closed', 'archived'], true)) {
            return ['ok' => false, 'error' => 'This period is closed and cannot be edited.'];
        }

        $testDate = trim((string)($data['test_date'] ?? ''));
        $start = trim((string)($data['start_time'] ?? ''));
        $end = trim((string)($data['end_time'] ?? ''));
        $program = trim((string)($data['program_code'] ?? ''));
        $year = max(1, min(10, (int)($data['year_of_study'] ?? 1)));
        $course = trim((string)($data['course_code'] ?? ''));
        $section = trim((string)($data['section_label'] ?? ''));
        $roomId = isset($data['classroom_id']) && $data['classroom_id'] !== '' ? (int)$data['classroom_id'] : null;
        $lecturer = trim((string)($data['lecturer_staff_id'] ?? ''));
        $lecturer = $lecturer !== '' ? $lecturer : null;

        if ($testDate === '' || $start === '' || $end === '' || $program === '' || $course === '') {
            return ['ok' => false, 'error' => 'Date, time, programme and course are required.'];
        }
        if ($start >= $end) {
            return ['ok' => false, 'error' => 'End time must be after start time.'];
        }
        if ($testDate < (string)$period['test_start_date'] || $testDate > (string)$period['test_end_date']) {
            return ['ok' => false, 'error' => 'Test date must fall within the assessment period test window.'];
        }

        $yearStr = (string)$period['academic_year'];
        $term = (string)$period['term_label'];
        $sec = $section;
        $rid = $roomId ?? 0;
        $lec = $lecturer ?? '';

        try {
            if ($id) {
                $stmt = $db->prepare(
                    'UPDATE test_timetable
                     SET test_date=?, start_time=?, end_time=?, program_code=?, year_of_study=?,
                         course_code=?, section_label=NULLIF(?, \'\'), classroom_id=NULLIF(?, 0),
                         lecturer_staff_id=NULLIF(?, \'\'), status=\'scheduled\'
                     WHERE id=? AND assessment_period_id=?'
                );
                if (!$stmt) {
                    return ['ok' => false, 'error' => 'Update prepare failed.'];
                }
                // s s s s i s s i s i i
                $stmt->bind_param(
                    'ssssissisii',
                    $testDate,
                    $start,
                    $end,
                    $program,
                    $year,
                    $course,
                    $sec,
                    $rid,
                    $lec,
                    $id,
                    $periodId
                );
                $stmt->execute();
                $stmt->close();
                return ['ok' => true, 'id' => $id];
            }

            $stmt = $db->prepare(
                'INSERT INTO test_timetable
                 (assessment_period_id, academic_year, term_label, test_date, start_time, end_time,
                  program_code, year_of_study, course_code, section_label, classroom_id, lecturer_staff_id,
                  status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, 0), NULLIF(?, \'\'), \'scheduled\', ?)'
            );
            if (!$stmt) {
                return ['ok' => false, 'error' => 'Insert prepare failed.'];
            }
            // i s s s s s s i s s i s s
            $stmt->bind_param(
                'issssssississ',
                $periodId,
                $yearStr,
                $term,
                $testDate,
                $start,
                $end,
                $program,
                $year,
                $course,
                $sec,
                $rid,
                $lec,
                $actor
            );
            $stmt->execute();
            $newId = (int)$stmt->insert_id;
            $stmt->close();
            return ['ok' => true, 'id' => $newId];
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false) {
                return ['ok' => false, 'error' => 'This programme/course/year is already scheduled in this period.'];
            }
            error_log('tt_save_entry: ' . $msg);
            return ['ok' => false, 'error' => 'Could not save timetable entry.'];
        }
    }
}

if (!function_exists('tt_delete_entry')) {
    /** @return array{ok:bool,error?:string} */
    function tt_delete_entry(mysqli $db, int $entryId, int $periodId): array
    {
        $period = tt_get_period($db, $periodId);
        if (!$period) {
            return ['ok' => false, 'error' => 'Period not found.'];
        }
        if (in_array(strtolower((string)$period['status']), ['published', 'active', 'closed', 'archived'], true)) {
            // Allow delete only in draft/scheduling
            if (!in_array(strtolower((string)$period['status']), ['draft', 'scheduling'], true)) {
                return ['ok' => false, 'error' => 'Published or closed entries cannot be deleted. Close/archive the period first or cancel via edit workflow.'];
            }
        }
        $stmt = $db->prepare("UPDATE test_timetable SET status='cancelled' WHERE id=? AND assessment_period_id=?");
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Delete failed.'];
        }
        $stmt->bind_param('ii', $entryId, $periodId);
        $stmt->execute();
        $stmt->close();
        return ['ok' => true];
    }
}

if (!function_exists('tt_detect_conflicts')) {
    /**
     * @return list<array{type:string,severity:string,message:string}>
     */
    function tt_detect_conflicts(mysqli $db, int $periodId): array
    {
        $entries = tt_list_entries($db, $periodId, ['limit' => 500]);
        $conflicts = [];
        $n = count($entries);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $entries[$i];
                $b = $entries[$j];
                if ((string)$a['test_date'] !== (string)$b['test_date']) {
                    continue;
                }
                if (!tt_times_overlap((string)$a['start_time'], (string)$a['end_time'], (string)$b['start_time'], (string)$b['end_time'])) {
                    continue;
                }
                $timeLabel = substr((string)$a['start_time'], 0, 5);
                $dateLabel = (string)$a['test_date'];

                if ((string)$a['program_code'] === (string)$b['program_code']
                    && (int)$a['year_of_study'] === (int)$b['year_of_study']) {
                    $conflicts[] = [
                        'type' => 'class',
                        'severity' => 'critical',
                        'message' => sprintf(
                            'Conflict: %s Year %d has %s and %s scheduled at %s on %s.',
                            (string)($a['program_name'] ?: $a['program_code']),
                            (int)$a['year_of_study'],
                            (string)($a['course_name'] ?: $a['course_code']),
                            (string)($b['course_name'] ?: $b['course_code']),
                            $timeLabel,
                            $dateLabel
                        ),
                    ];
                }
                if (!empty($a['lecturer_staff_id']) && (string)$a['lecturer_staff_id'] === (string)$b['lecturer_staff_id']) {
                    $conflicts[] = [
                        'type' => 'lecturer',
                        'severity' => 'critical',
                        'message' => sprintf(
                            'Conflict: Lecturer %s is allocated to %s and %s overlapping at %s on %s.',
                            trim((string)($a['lecturer_name'] ?: $a['lecturer_staff_id'])),
                            (string)$a['course_code'],
                            (string)$b['course_code'],
                            $timeLabel,
                            $dateLabel
                        ),
                    ];
                }
                if (!empty($a['classroom_id']) && (int)$a['classroom_id'] === (int)$b['classroom_id']) {
                    $room = (string)($a['room_code'] ?: ('Room #' . $a['classroom_id']));
                    $conflicts[] = [
                        'type' => 'room',
                        'severity' => 'critical',
                        'message' => sprintf(
                            'Conflict: Room %s is used by %s and %s overlapping at %s on %s.',
                            $room,
                            (string)$a['course_code'],
                            (string)$b['course_code'],
                            $timeLabel,
                            $dateLabel
                        ),
                    ];
                }
            }
            // Duplicate course already blocked by unique key; still surface if status quirks
        }

        // Duplicate course (same period/program/year/course) — defensive
        $seen = [];
        foreach ($entries as $e) {
            $key = strtolower($e['program_code'] . '|' . $e['year_of_study'] . '|' . $e['course_code']);
            if (isset($seen[$key])) {
                $conflicts[] = [
                    'type' => 'duplicate_course',
                    'severity' => 'critical',
                    'message' => sprintf(
                        'Conflict: %s Year %d course %s is scheduled more than once in this assessment period.',
                        (string)($e['program_name'] ?: $e['program_code']),
                        (int)$e['year_of_study'],
                        (string)$e['course_code']
                    ),
                ];
            }
            $seen[$key] = true;
        }

        return $conflicts;
    }
}

if (!function_exists('tt_period_summary')) {
    /** @return array{total:int,scheduled_courses:int,unscheduled:int,conflicts:int,rooms_used:int} */
    function tt_period_summary(mysqli $db, int $periodId, array $programCodes = []): array
    {
        $filters = ['limit' => 500];
        if ($programCodes !== []) {
            $filters['program_codes'] = $programCodes;
        }
        $entries = tt_list_entries($db, $periodId, $filters);

        $scheduledCourses = [];
        $rooms = [];
        foreach ($entries as $e) {
            $scheduledCourses[$e['course_code'] . '|' . $e['program_code'] . '|' . $e['year_of_study']] = true;
            if (!empty($e['classroom_id'])) {
                $rooms[(int)$e['classroom_id']] = true;
            }
        }
        $conflicts = tt_detect_conflicts($db, $periodId);
        if ($programCodes !== []) {
            $set = array_fill_keys($programCodes, true);
            $entries = array_values(array_filter(
                $entries,
                static fn($e) => isset($set[(string)$e['program_code']])
            ));
            $scheduledCourses = [];
            $rooms = [];
            foreach ($entries as $e) {
                $scheduledCourses[$e['course_code'] . '|' . $e['program_code'] . '|' . $e['year_of_study']] = true;
                if (!empty($e['classroom_id'])) {
                    $rooms[(int)$e['classroom_id']] = true;
                }
            }
            $conflicts = array_values(array_filter($conflicts, static function ($c) use ($set, $entries) {
                foreach ($entries as $e) {
                    if (isset($set[(string)$e['program_code']])
                        && stripos((string)$c['message'], (string)$e['program_code']) !== false) {
                        return true;
                    }
                    if (stripos((string)$c['message'], (string)($e['program_name'] ?? '')) !== false
                        && (string)($e['program_name'] ?? '') !== '') {
                        return true;
                    }
                }
                return ($c['type'] ?? '') === 'room' || ($c['type'] ?? '') === 'lecturer';
            }));
        }
        $unscheduled = tt_unscheduled_courses($db, $periodId, $programCodes);

        return [
            'total' => count($entries),
            'scheduled_courses' => count($scheduledCourses),
            'unscheduled' => count($unscheduled),
            'conflicts' => count($conflicts),
            'rooms_used' => count($rooms),
        ];
    }
}

if (!function_exists('tt_unscheduled_courses')) {
    /**
     * Courses offered on programmes (via program_courses) not yet on the timetable.
     * @param list<string> $programCodes
     * @return list<array<string,mixed>>
     */
    function tt_unscheduled_courses(mysqli $db, int $periodId, array $programCodes = []): array
    {
        if (!tt_ensure_schema($db)) {
            return [];
        }
        $period = tt_get_period($db, $periodId);
        if (!$period) {
            return [];
        }
        $hasPc = function_exists('wuc_table_exists')
            ? wuc_table_exists($db, 'program_courses')
            : (bool)@$db->query("SHOW TABLES LIKE 'program_courses'")->num_rows;
        if (!$hasPc) {
            return [];
        }

        $sql = 'SELECT pc.program_code, p.program_name, pc.course_code, c.course_name,
                       COALESCE(pc.year, 1) AS year_of_study
                FROM program_courses pc
                INNER JOIN programs p ON p.program_code = pc.program_code
                LEFT JOIN courses c ON c.course_code = pc.course_code
                WHERE COALESCE(p.is_active, 1) = 1';
        $types = '';
        $params = [];
        if ($programCodes !== []) {
            $ph = implode(',', array_fill(0, count($programCodes), '?'));
            $sql .= " AND pc.program_code IN ($ph)";
            $types = str_repeat('s', count($programCodes));
            $params = $programCodes;
        }
        $sql .= ' ORDER BY p.program_name, year_of_study, pc.course_code LIMIT 500';

        $offered = [];
        if ($types === '') {
            $res = $db->query($sql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $offered[] = $row;
                }
                $res->free();
            }
        } else {
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $offered[] = $row;
                }
                $stmt->close();
            }
        }

        $scheduled = [];
        foreach (tt_list_entries($db, $periodId, ['limit' => 500]) as $e) {
            $scheduled[strtolower($e['program_code'] . '|' . $e['year_of_study'] . '|' . $e['course_code'])] = true;
        }

        $out = [];
        foreach ($offered as $row) {
            $key = strtolower($row['program_code'] . '|' . $row['year_of_study'] . '|' . $row['course_code']);
            if (!isset($scheduled[$key])) {
                $out[] = $row;
            }
        }
        return $out;
    }
}

if (!function_exists('tt_student_tests')) {
    /**
     * Personalized tests for a student.
     * @param bool $allowHistorical when true, allow closed/archived periods (Previous Timetables).
     */
    function tt_student_tests(mysqli $db, string $studentId, ?int $periodId = null, bool $allowHistorical = false): array
    {
        if (!tt_ensure_schema($db) || $studentId === '') {
            return [];
        }
        $period = $periodId ? tt_get_period($db, $periodId) : tt_current_student_visible_period($db);
        if (!$period) {
            return [];
        }
        $status = strtolower((string)$period['status']);
        if ($allowHistorical) {
            if (!in_array($status, ['closed', 'archived'], true)) {
                return [];
            }
        } elseif (!tt_student_can_view_period($period)) {
            return [];
        }
        $pid = (int)$period['id'];

        // Programme + year from student_program / students
        $program = '';
        $year = 1;
        if ($stmt = $db->prepare(
            "SELECT sp.program_code, COALESCE(s.year, 1) AS yos
             FROM student_program sp
             INNER JOIN students s ON s.SID = sp.Sid
             WHERE sp.Sid = ?
               AND (sp.status IS NULL OR sp.status = '' OR LOWER(sp.status) = 'active')
             ORDER BY sp.id DESC LIMIT 1"
        )) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $program = (string)$row['program_code'];
                $year = max(1, (int)$row['yos']);
            }
            $stmt->close();
        }

        $courseCodes = [];
        if ($stmt = $db->prepare(
            "SELECT DISTINCT course_code FROM course_registration
             WHERE Sid = ?
               AND (status IS NULL OR status = '' OR LOWER(status) IN ('active','registered','approved'))
             LIMIT 200"
        )) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $courseCodes[] = (string)$row['course_code'];
            }
            $stmt->close();
        }

        $entries = tt_list_entries($db, $pid, ['limit' => 300]);
        $out = [];
        foreach ($entries as $e) {
            if ($program !== '' && (string)$e['program_code'] !== $program) {
                continue;
            }
            if ((int)$e['year_of_study'] !== $year && $year > 0) {
                // Still allow if course is registered (retake / mixed)
                if ($courseCodes === [] || !in_array((string)$e['course_code'], $courseCodes, true)) {
                    continue;
                }
            } elseif ($courseCodes !== [] && !in_array((string)$e['course_code'], $courseCodes, true)) {
                continue;
            }
            $e['_period'] = $period;
            $out[] = $e;
        }
        return $out;
    }
}

if (!function_exists('tt_lookup_classrooms')) {
    /** @return list<array{id:int,label:string}> */
    function tt_lookup_classrooms(mysqli $db): array
    {
        $out = [];
        if (!function_exists('wuc_table_exists') || !wuc_table_exists($db, 'classrooms')) {
            return $out;
        }
        $res = @$db->query(
            "SELECT id, room_code, room_name, building FROM classrooms
             WHERE status = 'available' OR status IS NULL
             ORDER BY room_code ASC LIMIT 300"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $out[] = [
                    'id' => (int)$row['id'],
                    'label' => trim(($row['room_code'] ?? '') . ' — ' . ($row['room_name'] ?? '') . ' ' . ($row['building'] ?? '')),
                ];
            }
            $res->free();
        }
        return $out;
    }
}

if (!function_exists('tt_lookup_current_academic_period')) {
    function tt_lookup_current_academic_period(mysqli $db): ?array
    {
        if (!function_exists('wuc_table_exists') || !wuc_table_exists($db, 'academic_periods')) {
            return null;
        }
        $res = @$db->query(
            "SELECT id, academic_year, period_name, semester_term, period_type, period_number, start_date, end_date
             FROM academic_periods WHERE is_current = 1 ORDER BY id DESC LIMIT 1"
        );
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->free();
        }
        return $row ?: null;
    }
}

if (!function_exists('tt_lookup_programs')) {
    /** @return list<array{program_code:string,program_name:string}> */
    function tt_lookup_programs(mysqli $db): array
    {
        $out = [];
        $res = @$db->query(
            "SELECT program_code, program_name FROM programs
             WHERE COALESCE(is_active, 1) = 1
             ORDER BY program_name ASC LIMIT 400"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $out[] = $row;
            }
            $res->free();
        }
        return $out;
    }
}

if (!function_exists('tt_lookup_courses_for_program')) {
    /** @return list<array{course_code:string,course_name:string,year_of_study:int}> */
    function tt_lookup_courses_for_program(mysqli $db, string $programCode): array
    {
        $out = [];
        if ($programCode === '' || !function_exists('wuc_table_exists') || !wuc_table_exists($db, 'program_courses')) {
            return $out;
        }
        $stmt = $db->prepare(
            'SELECT pc.course_code, COALESCE(c.course_name, pc.course_code) AS course_name,
                    COALESCE(pc.year, 1) AS year_of_study
             FROM program_courses pc
             LEFT JOIN courses c ON c.course_code = pc.course_code
             WHERE pc.program_code = ?
             ORDER BY year_of_study, pc.course_code
             LIMIT 300'
        );
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('s', $programCode);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
        $stmt->close();
        return $out;
    }
}

if (!function_exists('tt_lookup_lecturers')) {
    /** @return list<array{staff_id:string,label:string}> */
    function tt_lookup_lecturers(mysqli $db): array
    {
        $out = [];
        $res = @$db->query(
            "SELECT DISTINCT s.staff_id, s.title, s.Fname, s.Lname
             FROM staff s
             INNER JOIN course_lecturer cl ON cl.staff_id = s.staff_id
             ORDER BY s.Lname, s.Fname
             LIMIT 400"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $out[] = [
                    'staff_id' => (string)$row['staff_id'],
                    'label' => trim(($row['title'] ?? '') . ' ' . ($row['Fname'] ?? '') . ' ' . ($row['Lname'] ?? '') . ' (' . $row['staff_id'] . ')'),
                ];
            }
            $res->free();
        }
        return $out;
    }
}

if (!function_exists('tt_lecturer_course_codes')) {
    /** @return list<string> */
    function tt_lecturer_course_codes(mysqli $db, string $staffId): array
    {
        $out = [];
        if ($staffId === '' || !function_exists('wuc_table_exists') || !wuc_table_exists($db, 'course_lecturer')) {
            return $out;
        }
        $stmt = $db->prepare('SELECT DISTINCT course_code FROM course_lecturer WHERE staff_id = ? LIMIT 200');
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $code = trim((string)($row['course_code'] ?? ''));
            if ($code !== '') {
                $out[] = $code;
            }
        }
        $stmt->close();
        return $out;
    }
}

if (!function_exists('tt_lecturer_tests')) {
    /**
     * Tests for a lecturer: assigned as invigilator OR teaching the course.
     * @return list<array<string,mixed>>
     */
    function tt_lecturer_tests(mysqli $db, string $staffId, ?int $periodId = null): array
    {
        if (!tt_ensure_schema($db) || $staffId === '') {
            return [];
        }
        $periods = [];
        if ($periodId) {
            $p = tt_get_period($db, $periodId);
            if ($p) {
                $periods[] = $p;
            }
        } else {
            foreach (tt_list_periods($db, false) as $p) {
                $st = strtolower((string)$p['status']);
                if (in_array($st, ['published', 'active', 'scheduling', 'closed'], true)) {
                    $periods[] = $p;
                }
            }
        }
        $courseCodes = tt_lecturer_course_codes($db, $staffId);
        $out = [];
        foreach ($periods as $period) {
            $pid = (int)$period['id'];
            $byLecturer = tt_list_entries($db, $pid, ['lecturer_staff_id' => $staffId, 'limit' => 200]);
            $byCourse = $courseCodes !== []
                ? tt_list_entries($db, $pid, ['course_codes' => $courseCodes, 'limit' => 200])
                : [];
            $seen = [];
            foreach (array_merge($byLecturer, $byCourse) as $e) {
                $id = (int)$e['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $e['_period'] = $period;
                $e['student_count'] = tt_entry_student_count($db, $e);
                $out[] = $e;
            }
        }
        usort($out, static function ($a, $b) {
            $cmp = strcmp((string)$a['test_date'], (string)$b['test_date']);
            return $cmp !== 0 ? $cmp : strcmp((string)$a['start_time'], (string)$b['start_time']);
        });
        return $out;
    }
}

if (!function_exists('tt_entry_student_count')) {
    function tt_entry_student_count(mysqli $db, array $entry): ?int
    {
        $program = (string)($entry['program_code'] ?? '');
        $course = (string)($entry['course_code'] ?? '');
        $year = (int)($entry['year_of_study'] ?? 0);
        if ($program === '' || $course === '') {
            return null;
        }
        if (!function_exists('wuc_table_exists') || !wuc_table_exists($db, 'course_registration')) {
            return null;
        }
        $sql = "SELECT COUNT(DISTINCT cr.Sid) AS cnt
                FROM course_registration cr
                INNER JOIN student_program sp ON sp.Sid = cr.Sid
                INNER JOIN students s ON s.SID = cr.Sid
                WHERE cr.course_code = ?
                  AND sp.program_code = ?
                  AND (cr.status IS NULL OR cr.status = '' OR LOWER(cr.status) IN ('active','registered','approved'))
                  AND (sp.status IS NULL OR sp.status = '' OR LOWER(sp.status) = 'active')";
        $types = 'ss';
        $params = [$course, $program];
        if ($year > 0) {
            $sql .= ' AND COALESCE(s.year, 0) = ?';
            $types .= 'i';
            $params[] = $year;
        }
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return isset($row['cnt']) ? (int)$row['cnt'] : null;
    }
}

if (!function_exists('tt_student_historical_periods')) {
    /**
     * Closed/archived periods the student may have had tests for (for Previous Timetables).
     * @return list<array<string,mixed>>
     */
    function tt_student_historical_periods(mysqli $db, string $studentId): array
    {
        if (!tt_ensure_schema($db) || $studentId === '') {
            return [];
        }
        $program = '';
        if ($stmt = $db->prepare(
            "SELECT program_code FROM student_program
             WHERE Sid = ?
               AND (status IS NULL OR status = '' OR LOWER(status) = 'active')
             ORDER BY id DESC LIMIT 1"
        )) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $program = (string)($row['program_code'] ?? '');
        }
        if ($program === '') {
            return [];
        }
        $out = [];
        foreach (tt_list_periods($db, true) as $period) {
            $st = strtolower((string)$period['status']);
            if (!in_array($st, ['closed', 'archived'], true)) {
                continue;
            }
            $entries = tt_list_entries($db, (int)$period['id'], [
                'program_codes' => [$program],
                'limit' => 5,
            ]);
            if ($entries !== []) {
                $out[] = $period;
            }
        }
        return $out;
    }
}
