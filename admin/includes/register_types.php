<?php
/**
 * register_types.php
 *
 * Single source of truth for the "Print Registers" feature. Shared by:
 *   - admin/print_registers.php            (form + UI behaviour)
 *   - admin/ajax/get_program_courses.php   (course list + counts)
 *   - admin/ajax/get_short_courses.php     (short-course list + counts)
 *   - admin/generate_register.php          (PDF roster)
 *
 * Each register type declares where its roster comes from and how the period is
 * labelled, so adding a new type is a one-entry change here.
 *
 * Sources / scopes:
 *   scope 'program'      -> program + year + period + course (program_courses driven)
 *       source 'course'  -> roster from course_registration (enrolled class list)
 *       source 'exam'    -> roster from exam_registration   (assessment sitting list)
 *   scope 'short_course' -> a single short course (short_course_enrollments driven)
 *
 * Collation note: exam_registration.Sid/.course_code are utf8mb4_general_ci while
 * the rest of the schema is utf8mb4_unicode_ci, so exam queries force a collation.
 */

if (!function_exists('wuc_register_types')) {

    function wuc_register_types(): array
    {
        return [
            'semester' => [
                'label'       => 'Semester Register',
                'title'       => 'SEMESTER REGISTER',
                'scope'       => 'program',
                'source'      => 'course',
                'period_word' => 'Semester',
                'max_period'  => 2,
            ],
            'term' => [
                'label'       => 'Term Register',
                'title'       => 'TERM REGISTER',
                'scope'       => 'program',
                'source'      => 'course',
                'period_word' => 'Term',
                'max_period'  => 3,
            ],
            'test' => [
                'label'       => 'Test / CAT Register',
                'title'       => 'CONTINUOUS ASSESSMENT (TEST) REGISTER',
                'scope'       => 'program',
                'source'      => 'course',
                'period_word' => 'Period',
                'max_period'  => 3,
            ],
            'exam' => [
                'label'       => 'Exam Register',
                'title'       => 'EXAMINATION REGISTER',
                'scope'       => 'program',
                'source'      => 'exam',
                'period_word' => 'Period',
                'max_period'  => 3,
            ],
            'short_course' => [
                'label'       => 'Short Course Register',
                'title'       => 'SHORT COURSE REGISTER',
                'scope'       => 'short_course',
                'source'      => 'short_course',
                'period_word' => '',
                'max_period'  => 0,
            ],
        ];
    }

    /** Return the config for a register type, or null if unknown. */
    function wuc_register_type_config(string $type): ?array
    {
        $types = wuc_register_types();
        return $types[$type] ?? null;
    }

    /** Normalise an arbitrary input to a known register type, defaulting to 'semester'. */
    function wuc_register_normalise_type(?string $type): string
    {
        $type = (string)$type;
        return wuc_register_type_config($type) ? $type : 'semester';
    }

    /**
     * Correlated subquery (referencing alias `pc` from program_courses) that counts
     * the students on the roster for a given course/period. Used by the course list
     * endpoint. $source is 'course' or 'exam'.
     */
    function wuc_register_count_subquery(string $source): string
    {
        if ($source === 'exam') {
            return "(SELECT COUNT(DISTINCT reg.Sid)
                       FROM exam_registration reg
                       JOIN students st
                         ON TRIM(UPPER(CONVERT(st.SID USING utf8mb4) COLLATE utf8mb4_unicode_ci))
                          = TRIM(UPPER(CONVERT(reg.Sid USING utf8mb4) COLLATE utf8mb4_unicode_ci))
                      WHERE TRIM(UPPER(CONVERT(reg.course_code USING utf8mb4) COLLATE utf8mb4_unicode_ci))
                          = TRIM(UPPER(CONVERT(pc.course_code USING utf8mb4) COLLATE utf8mb4_unicode_ci))
                        AND reg.semester = pc.semester
                        AND reg.Year = pc.year
                        AND (EXISTS (SELECT 1 FROM student_program sp
                                      WHERE TRIM(UPPER(sp.Sid)) = TRIM(UPPER(st.SID))
                                        AND TRIM(UPPER(sp.program_code)) = TRIM(UPPER(pc.program_code)))
                             OR TRIM(UPPER(st.program)) = TRIM(UPPER(pc.program_code))))";
        }
        return "(SELECT COUNT(DISTINCT reg.Sid)
                   FROM course_registration reg
                   JOIN students st ON TRIM(UPPER(st.SID)) = TRIM(UPPER(reg.Sid))
                  WHERE TRIM(UPPER(reg.course_code)) = TRIM(UPPER(pc.course_code))
                    AND reg.semester = pc.semester
                    AND reg.Year = pc.year
                    AND reg.is_active = 1
                    AND (EXISTS (SELECT 1 FROM student_program sp
                                  WHERE TRIM(UPPER(sp.Sid)) = TRIM(UPPER(st.SID))
                                    AND TRIM(UPPER(sp.program_code)) = TRIM(UPPER(pc.program_code)))
                         OR TRIM(UPPER(st.program)) = TRIM(UPPER(pc.program_code))))";
    }

    /**
     * Fetch the student roster for a program-scoped register.
     *
     * @return array<int,array{SID:string,full_name:string,student_program:?string,student_year:?int}>
     */
    function wuc_register_program_roster(
        mysqli $db,
        string $source,
        string $program_code,
        string $course_code,
        int $period,
        int $year
    ): array {
        $membership = "(EXISTS (SELECT 1 FROM student_program sp
                                 WHERE TRIM(UPPER(sp.Sid)) = TRIM(UPPER(s.SID))
                                   AND TRIM(UPPER(sp.program_code)) = TRIM(UPPER(?)))
                        OR TRIM(UPPER(s.program)) = TRIM(UPPER(?)))";

        if ($source === 'exam') {
            $sql = "SELECT s.SID,
                           TRIM(CONCAT_WS(' ', s.Fname, s.Lname)) AS full_name,
                           s.program AS student_program,
                           s.year AS student_year
                      FROM exam_registration reg
                      JOIN students s
                        ON TRIM(UPPER(CONVERT(s.SID USING utf8mb4) COLLATE utf8mb4_unicode_ci))
                         = TRIM(UPPER(CONVERT(reg.Sid USING utf8mb4) COLLATE utf8mb4_unicode_ci))
                     WHERE TRIM(UPPER(CONVERT(reg.course_code USING utf8mb4) COLLATE utf8mb4_unicode_ci))
                         = TRIM(UPPER(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci))
                       AND reg.semester = ?
                       AND reg.Year = ?
                       AND {$membership}
                     ORDER BY s.Lname, s.Fname";
        } else {
            $sql = "SELECT s.SID,
                           TRIM(CONCAT_WS(' ', s.Fname, s.Lname)) AS full_name,
                           s.program AS student_program,
                           s.year AS student_year
                      FROM course_registration reg
                      JOIN students s ON TRIM(UPPER(s.SID)) = TRIM(UPPER(reg.Sid))
                     WHERE TRIM(UPPER(reg.course_code)) = TRIM(UPPER(?))
                       AND reg.semester = ?
                       AND reg.Year = ?
                       AND reg.is_active = 1
                       AND {$membership}
                     ORDER BY s.Lname, s.Fname";
        }

        $stmt = $db->prepare($sql);
        $stmt->bind_param('siiss', $course_code, $period, $year, $program_code, $program_code);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Fetch the enrolled-student roster for a short course.
     *
     * @return array<int,array{SID:string,full_name:string,enroll_status:?string,student_program:?string,student_year:?int}>
     */
    function wuc_register_short_course_roster(mysqli $db, int $short_course_id): array
    {
        // LEFT JOIN so an enrollee that is not (yet) in students is still listed by ID.
        $sql = "SELECT e.student_id AS SID,
                       TRIM(CONCAT_WS(' ', s.Fname, s.Lname)) AS full_name,
                       e.status AS enroll_status,
                       s.program AS student_program,
                       s.year AS student_year
                  FROM short_course_enrollments e
                  LEFT JOIN students s ON s.SID = e.student_id
                 WHERE e.short_course_id = ?
                 ORDER BY (s.Lname IS NULL), s.Lname, s.Fname, e.student_id";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $short_course_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * List short courses (optionally filtered) with their enrollment counts.
     *
     * @return array<int,array{id:int,course_code:string,course_name:string,status:string,enrolled:int}>
     */
    function wuc_register_short_courses(mysqli $db, string $search = ''): array
    {
        $sql = "SELECT sc.id, sc.course_code, sc.course_name, sc.status,
                       (SELECT COUNT(*) FROM short_course_enrollments e
                         WHERE e.short_course_id = sc.id) AS enrolled
                  FROM short_courses sc";
        $types  = '';
        $params = [];
        if ($search !== '') {
            $sql   .= " WHERE (sc.course_code LIKE ? OR sc.course_name LIKE ?)";
            $like   = '%' . $search . '%';
            $types  = 'ss';
            $params = [$like, $like];
        }
        $sql .= " ORDER BY sc.course_name";

        $stmt = $db->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(static function (array $r): array {
            return [
                'id'          => (int)$r['id'],
                'course_code' => $r['course_code'],
                'course_name' => $r['course_name'],
                'status'      => $r['status'],
                'enrolled'    => (int)$r['enrolled'],
            ];
        }, $rows);
    }
}
