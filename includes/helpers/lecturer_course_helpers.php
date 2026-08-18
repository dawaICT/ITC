<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/schema_guard.php';

if (!function_exists('wuc_lecturer_current_academic_year')) {
    function wuc_lecturer_current_academic_year(mysqli $db): string
    {
        $year = (string)date('Y');
        if (!wuc_table_exists($db, 'portal_settings')) {
            return $year;
        }
        if ($st = $db->prepare("SELECT setting_value FROM portal_settings WHERE setting_key = 'current_academic_year' LIMIT 1")) {
            if ($st->execute()) {
                $res = $st->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $value = trim((string)($row['setting_value'] ?? ''));
                    if ($value !== '') {
                        $year = $value;
                    }
                }
            }
            $st->close();
        }
        return $year;
    }
}

if (!function_exists('wuc_sync_legacy_lecturer_courses_table')) {
    /**
     * Promote orphan lecturer_courses rows into canonical course_lecturer.
     * Optional $onlyStaffId limits work to one lecturer (preferred on page load).
     */
    function wuc_sync_legacy_lecturer_courses_table(mysqli $db, ?string $onlyStaffId = null): int
    {
        if (!wuc_table_exists($db, 'lecturer_courses') || !wuc_table_exists($db, 'course_lecturer')) {
            return 0;
        }

        static $syncedStaff = [];
        $onlyStaffId = $onlyStaffId !== null ? trim($onlyStaffId) : null;
        if ($onlyStaffId !== null && $onlyStaffId !== '') {
            if (isset($syncedStaff[$onlyStaffId])) {
                return 0;
            }
            $syncedStaff[$onlyStaffId] = true;
        }

        $ay = wuc_lecturer_current_academic_year($db);
        $changed = 0;
        $stmt = null;

        if ($onlyStaffId !== null && $onlyStaffId !== '') {
            $stmt = $db->prepare('SELECT lecturer_id, course_code FROM lecturer_courses WHERE lecturer_id = ?');
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('s', $onlyStaffId);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $db->query('SELECT lecturer_id, course_code FROM lecturer_courses');
        }
        if (!$res) {
            if ($stmt) {
                $stmt->close();
            }
            return 0;
        }

        while ($row = $res->fetch_assoc()) {
            $staffId = trim((string)($row['lecturer_id'] ?? ''));
            $courseCode = trim((string)($row['course_code'] ?? ''));
            if ($staffId === '' || $courseCode === '') {
                continue;
            }
            $exists = false;
            if ($chk = $db->prepare('SELECT 1 FROM course_lecturer WHERE staff_id = ? AND course_code = ? LIMIT 1')) {
                $chk->bind_param('ss', $staffId, $courseCode);
                $chk->execute();
                $exists = (bool)$chk->get_result()->num_rows;
                $chk->close();
            }
            if ($exists) {
                continue;
            }
            $result = wuc_assign_lecturer_to_course($db, $staffId, $courseCode, [
                'academic_year' => $ay,
                'allow_unmapped' => true,
            ]);
            $changed += (int)($result['inserted'] ?? 0) + (int)($result['updated'] ?? 0);
        }
        if ($stmt) {
            $stmt->close();
        } else {
            $res->free();
        }
        return $changed;
    }
}

if (!function_exists('wuc_assign_lecturer_to_course')) {
    /**
     * Assign a lecturer to a course with program/year/semester context.
     *
     * Expands via program_courses (optionally filtered). Upserts into
     * course_lecturer so null-context legacy rows are repaired instead of
     * colliding with uq_lecturer_course_period (staff, course, year, semester).
     *
     * @param array{
     *   academic_year?: string,
     *   program_codes?: string[],
     *   allow_unmapped?: bool,
     *   quiet?: bool
     * } $options
     * @return array{success:bool,inserted:int,updated:int,existed:int,message:string}
     */
    function wuc_assign_lecturer_to_course(mysqli $db, string $staffId, string $courseCode, array $options = []): array
    {
        $staffId = trim($staffId);
        $courseCode = trim($courseCode);
        $empty = [
            'success' => false,
            'inserted' => 0,
            'updated' => 0,
            'existed' => 0,
            'message' => 'Invalid staff or course.',
        ];
        if ($staffId === '' || $courseCode === '' || !wuc_table_exists($db, 'course_lecturer')) {
            return $empty;
        }

        $academicYear = trim((string)($options['academic_year'] ?? wuc_lecturer_current_academic_year($db)));
        if ($academicYear === '') {
            $academicYear = (string)date('Y');
        }
        $programFilter = [];
        foreach ((array)($options['program_codes'] ?? []) as $code) {
            $code = trim((string)$code);
            if ($code !== '') {
                $programFilter[$code] = true;
            }
        }
        $programFilter = array_keys($programFilter);
        $allowUnmapped = !empty($options['allow_unmapped']);

        $courseOk = false;
        if (wuc_table_exists($db, 'courses')) {
            if ($st = $db->prepare('SELECT 1 FROM courses WHERE course_code = ? LIMIT 1')) {
                $st->bind_param('s', $courseCode);
                $st->execute();
                $courseOk = (bool)$st->get_result()->num_rows;
                $st->close();
            }
        }
        if (!$courseOk) {
            return array_merge($empty, ['message' => 'Selected course does not exist.']);
        }

        /** @var list<array{program_code:string,year:int,semester:string,short:bool}> $contexts */
        $contexts = [];
        if (wuc_table_exists($db, 'program_courses')) {
            $sql = 'SELECT pc.program_code, pc.year, pc.semester, COALESCE(p.academic_structure, \'\') AS academic_structure
                    FROM program_courses pc
                    LEFT JOIN programs p ON p.program_code = pc.program_code
                    WHERE pc.course_code = ?';
            if ($st = $db->prepare($sql)) {
                $st->bind_param('s', $courseCode);
                $st->execute();
                $res = $st->get_result();
                while ($row = $res->fetch_assoc()) {
                    $prog = trim((string)($row['program_code'] ?? ''));
                    if ($prog === '') {
                        continue;
                    }
                    if ($programFilter !== [] && !in_array($prog, $programFilter, true)) {
                        continue;
                    }
                    $contexts[] = [
                        'program_code' => $prog,
                        'year' => (int)($row['year'] ?? 0),
                        'semester' => trim((string)($row['semester'] ?? '1')),
                        'short' => strtolower((string)($row['academic_structure'] ?? '')) === 'short_course',
                    ];
                }
                $st->close();
            }
        }

        if ($contexts === []) {
            if ($programFilter !== [] && !$allowUnmapped) {
                return array_merge($empty, [
                    'message' => 'This course is not mapped to any program in your section.',
                ]);
            }
            if (!$allowUnmapped && $programFilter === [] && wuc_table_exists($db, 'program_courses')) {
                // Still allow legacy unmapped catalogue courses so assignment is not blocked.
                $allowUnmapped = true;
            }
            if ($allowUnmapped) {
                $contexts[] = [
                    'program_code' => '',
                    'year' => 0,
                    'semester' => '1',
                    'short' => true,
                ];
            } else {
                return array_merge($empty, [
                    'message' => 'Course has no program_courses mapping; cannot create contextual assignment.',
                ]);
            }
        }

        $inserted = 0;
        $updated = 0;
        $existed = 0;

        foreach ($contexts as $ctx) {
            $prog = $ctx['program_code'];
            $year = (int)$ctx['year'];
            $sem = $ctx['semester'] !== '' ? $ctx['semester'] : '1';
            $short = !empty($ctx['short']);

            // Prefer an exact contextual match first.
            if ($prog !== '') {
                if ($short) {
                    $check = $db->prepare(
                        'SELECT id, program_code, year_of_study, semester, status
                         FROM course_lecturer
                         WHERE staff_id = ? AND course_code = ? AND program_code = ?
                         LIMIT 1'
                    );
                    if (!$check) {
                        continue;
                    }
                    $check->bind_param('sss', $staffId, $courseCode, $prog);
                } else {
                    $check = $db->prepare(
                        'SELECT id, program_code, year_of_study, semester, status
                         FROM course_lecturer
                         WHERE staff_id = ? AND course_code = ? AND program_code = ?
                           AND year_of_study = ? AND semester = ?
                         LIMIT 1'
                    );
                    if (!$check) {
                        continue;
                    }
                    $check->bind_param('sssis', $staffId, $courseCode, $prog, $year, $sem);
                }
                $check->execute();
                $exact = $check->get_result()->fetch_assoc();
                $check->close();
                if ($exact) {
                    $existed++;
                    // Reactivate if needed.
                    if (strtolower((string)($exact['status'] ?? '')) === 'inactive') {
                        $id = (int)$exact['id'];
                        if ($up = $db->prepare("UPDATE course_lecturer SET status = 'active', academic_year = ? WHERE id = ?")) {
                            $up->bind_param('si', $academicYear, $id);
                            if ($up->execute()) {
                                $updated++;
                                $existed--;
                            }
                            $up->close();
                        }
                    }
                    continue;
                }
            }

            // Repair null-context row that occupies the unique period key.
            $legacy = $db->prepare(
                'SELECT id, program_code, year_of_study, semester, status
                 FROM course_lecturer
                 WHERE staff_id = ? AND course_code = ? AND academic_year = ? AND semester = ?
                 LIMIT 1'
            );
            if ($legacy) {
                $legacy->bind_param('ssss', $staffId, $courseCode, $academicYear, $sem);
                $legacy->execute();
                $legacyRow = $legacy->get_result()->fetch_assoc();
                $legacy->close();
                if ($legacyRow) {
                    $legacyProg = trim((string)($legacyRow['program_code'] ?? ''));
                    $id = (int)$legacyRow['id'];
                    if ($legacyProg === '' && $prog !== '') {
                        if ($up = $db->prepare(
                            "UPDATE course_lecturer
                             SET program_code = ?, year_of_study = NULLIF(?, 0), status = 'active', academic_year = ?
                             WHERE id = ?"
                        )) {
                            $up->bind_param('sisi', $prog, $year, $academicYear, $id);
                            if ($up->execute()) {
                                $updated++;
                            } else {
                                $existed++;
                            }
                            $up->close();
                        } else {
                            $existed++;
                        }
                    } elseif ($legacyProg === '' || $legacyProg === $prog) {
                        $existed++;
                    } else {
                        // Unique key historically omitted program_code; leave existing
                        // row alone and try a contextual insert below if the index allows.
                        // Fall through by not continuing when programs differ.
                        if ($prog === '') {
                            $existed++;
                            continue;
                        }
                        // Attempt contextual insert despite occupied period key.
                    }
                    if ($legacyProg === '' || $legacyProg === $prog || $prog === '') {
                        continue;
                    }
                }
            }

            // Fresh insert.
            if ($prog === '' || $short) {
                if ($prog === '') {
                    $ins = $db->prepare(
                        "INSERT INTO course_lecturer (course_code, staff_id, academic_year, semester, status)
                         VALUES (?, ?, ?, ?, 'active')"
                    );
                    if ($ins) {
                        $ins->bind_param('ssss', $courseCode, $staffId, $academicYear, $sem);
                        if ($ins->execute()) {
                            $inserted++;
                        }
                        $ins->close();
                    }
                } else {
                    $ins = $db->prepare(
                        "INSERT INTO course_lecturer (course_code, staff_id, program_code, academic_year, status)
                         VALUES (?, ?, ?, ?, 'active')"
                    );
                    if ($ins) {
                        $ins->bind_param('ssss', $courseCode, $staffId, $prog, $academicYear);
                        if ($ins->execute()) {
                            $inserted++;
                        }
                        $ins->close();
                    }
                }
            } else {
                $ins = $db->prepare(
                    "INSERT INTO course_lecturer
                        (course_code, staff_id, program_code, academic_year, year_of_study, semester, status)
                     VALUES (?, ?, ?, ?, ?, ?, 'active')"
                );
                if ($ins) {
                    $ins->bind_param('ssssis', $courseCode, $staffId, $prog, $academicYear, $year, $sem);
                    if ($ins->execute()) {
                        $inserted++;
                    }
                    $ins->close();
                }
            }
        }

        $success = ($inserted + $updated) > 0 || $existed > 0;
        if ($inserted > 0 || $updated > 0) {
            $message = sprintf(
                'Lecturer assigned (%d created, %d updated, %d already present).',
                $inserted,
                $updated,
                $existed
            );
        } elseif ($existed > 0) {
            $message = 'This course is already assigned to the selected lecturer.';
        } else {
            $message = 'Failed to save assignment.';
            $success = false;
        }

        return [
            'success' => $success,
            'inserted' => $inserted,
            'updated' => $updated,
            'existed' => $existed,
            'message' => $message,
        ];
    }
}

if (!function_exists('wuc_lecturer_resolved_course_codes')) {
    /**
     * Assigned course codes that exist in the courses catalogue.
     * Merges canonical course_lecturer with orphan lecturer_courses rows.
     */
    function wuc_lecturer_resolved_course_codes(mysqli $db, string $staffId): array
    {
        $staffId = trim($staffId);
        if ($staffId === '' || !wuc_table_exists($db, 'courses')) {
            return [];
        }

        $codes = [];

        if (wuc_table_exists($db, 'course_lecturer')) {
            $sql = "SELECT DISTINCT cl.course_code AS course_code
                    FROM course_lecturer cl
                    INNER JOIN courses c ON c.course_code = cl.course_code
                    WHERE cl.staff_id = ?
                      AND COALESCE(cl.status, 'active') <> 'inactive'
                    ORDER BY cl.course_code";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('s', $staffId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $code = trim((string)($row['course_code'] ?? ''));
                    if ($code !== '') {
                        $codes[$code] = true;
                    }
                }
                $stmt->close();
            }
        }

        // Orphan compatibility table still used by some seed scripts.
        if (wuc_table_exists($db, 'lecturer_courses')) {
            $sql = "SELECT DISTINCT lc.course_code AS course_code
                    FROM lecturer_courses lc
                    INNER JOIN courses c ON c.course_code = lc.course_code
                    WHERE lc.lecturer_id = ?";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('s', $staffId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $code = trim((string)($row['course_code'] ?? ''));
                    if ($code !== '') {
                        $codes[$code] = true;
                    }
                }
                $stmt->close();
            }
        }

        $list = array_keys($codes);
        sort($list);
        return $list;
    }
}

if (!function_exists('wuc_count_students_for_courses')) {
    /**
     * Count distinct enrolled students per course code using the best available store.
     *
     * @param string[] $courseCodes
     * @return array<string,int>
     */
    function wuc_count_students_for_courses(mysqli $db, array $courseCodes): array
    {
        $counts = [];
        $courseCodes = array_values(array_unique(array_filter(array_map(
            static fn($c) => trim((string)$c),
            $courseCodes
        ), static fn($c) => $c !== '')));
        if ($courseCodes === []) {
            return $counts;
        }

        $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
        $types = str_repeat('s', count($courseCodes));

        $accumulate = static function (mysqli $db, string $sql, string $types, array $params) use (&$counts): void {
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $code = trim((string)($row['course_code'] ?? ''));
                    if ($code === '') {
                        continue;
                    }
                    $counts[$code] = max((int)($counts[$code] ?? 0), (int)($row['cnt'] ?? 0));
                }
                $stmt->close();
            }
        };

        if (wuc_table_exists($db, 'course_registration')) {
            $active = '';
            if (wuc_column_exists($db, 'course_registration', 'is_active')) {
                $active = ' AND COALESCE(is_active, 1) = 1';
            } elseif (wuc_column_exists($db, 'course_registration', 'status')) {
                $active = " AND LOWER(COALESCE(status, 'active')) NOT IN ('cancelled','withdrawn','inactive')";
            }
            $accumulate(
                $db,
                "SELECT course_code, COUNT(DISTINCT Sid) AS cnt
                 FROM course_registration
                 WHERE course_code IN ({$placeholders}){$active}
                 GROUP BY course_code",
                $types,
                $courseCodes
            );
        }

        if (wuc_table_exists($db, 'student_course_registrations')
            && wuc_table_exists($db, 'course_offerings')
            && wuc_table_exists($db, 'curriculum_courses')
            && wuc_table_exists($db, 'student_program')
        ) {
            $accumulate(
                $db,
                "SELECT TRIM(cc.course_code) AS course_code, COUNT(DISTINCT sp.Sid) AS cnt
                 FROM student_course_registrations scr
                 JOIN student_program sp ON sp.id = scr.student_programme_id
                 JOIN course_offerings co ON co.id = scr.course_offering_id
                 JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
                 WHERE cc.course_code IN ({$placeholders})
                   AND scr.registration_status IN ('REGISTERED','COMPLETED','REPEATING')
                   AND co.status IN ('planned','active','completed')
                 GROUP BY TRIM(cc.course_code)",
                $types,
                $courseCodes
            );
        }

        // Only use sparse student_courses mirror when nothing else answered.
        if ($counts === [] && wuc_table_exists($db, 'student_courses')) {
            $sidCol = wuc_column_exists($db, 'student_courses', 'student_id')
                ? 'student_id'
                : (wuc_column_exists($db, 'student_courses', 'Sid') ? 'Sid' : 'SID');
            $accumulate(
                $db,
                "SELECT course_code, COUNT(DISTINCT `{$sidCol}`) AS cnt
                 FROM student_courses
                 WHERE course_code IN ({$placeholders})
                 GROUP BY course_code",
                $types,
                $courseCodes
            );
        }

        foreach ($courseCodes as $code) {
            if (!isset($counts[$code])) {
                $counts[$code] = 0;
            }
        }
        return $counts;
    }
}

if (!function_exists('wuc_lecturer_setup_notice')) {
    function wuc_lecturer_setup_notice(mysqli $db, string $staffId): string
    {
        $staffId = trim($staffId);
        if ($staffId === '') {
            return '';
        }
        // Best-effort promote orphan seed rows so lecturers become visible.
        if (wuc_table_exists($db, 'lecturer_courses')) {
            wuc_sync_legacy_lecturer_courses_table($db, $staffId);
        }
        $codes = wuc_lecturer_resolved_course_codes($db, $staffId);
        if ($codes !== []) {
            return '';
        }
        return '<div class="alert alert-info border-0 shadow-sm mb-4">'
            . '<i class="fas fa-info-circle me-2"></i>'
            . '<strong>No courses assigned yet.</strong> '
            . 'Your lecturer dashboard, CA upload, student lists, and course reports will populate '
            . 'after an administrator assigns courses to your account. '
            . 'Contact your Head of Section or the Registrar if you expect assignments.'
            . '</div>';
    }
}
