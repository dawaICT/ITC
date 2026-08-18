<?php
declare(strict_types=1);

require_once __DIR__ . '/ca_helpers.php';

/**
 * Read-only integrity audit for the legacy CA compatibility store and the
 * normalized offering/component/result model.
 *
 * @return array<int,array{key:string,label:string,severity:string,count:int,ok:bool,details:array}>
 */
function ca_integrity_audit(mysqli $db): array
{
    $checks = [];
    $add = static function (string $key, string $label, string $severity, int $count, array $details = []) use (&$checks): void {
        $checks[] = [
            'key' => $key,
            'label' => $label,
            'severity' => $severity,
            'count' => $count,
            'ok' => $count === 0,
            'details' => $details,
        ];
    };
    $count = static function (mysqli $db, string $sql): int {
        $row = $db->query($sql)->fetch_assoc();
        return (int)($row['c'] ?? 0);
    };

    $required = [
        'semester_assessment' => ['id','Sid','Course_Code','A1','A2','A3','T1','T2','Exam','Total_CA','semester','Year','status','program_type','posted_by'],
        'assessment_schemes' => ['id','program_code','course_code','ca_weight','exam_weight','status'],
        'assessment_components' => ['id','assessment_scheme_id','component_name','component_type','weight','max_mark'],
        'student_assessment_marks' => ['student_course_registration_id','assessment_component_id','mark_obtained','max_mark','status'],
        'student_course_results' => ['student_course_registration_id','ca_total','exam_mark','final_mark','result_status'],
    ];
    $missing = [];
    foreach ($required as $table => $columns) {
        if (!ca_table_exists($db, $table)) {
            $missing[] = $table;
            continue;
        }
        foreach ($columns as $column) {
            if (!ca_column_exists($db, $table, $column)) {
                $missing[] = $table . '.' . $column;
            }
        }
    }
    $add('required_schema', 'Required CA tables and columns are present', 'fail', count($missing), $missing);
    if ($missing !== []) {
        return $checks;
    }

    $add('legacy_duplicate_key', 'Duplicate student/course/period CA rows', 'fail', $count($db,
        "SELECT COUNT(*) AS c FROM (
             SELECT Sid, Course_Code, semester, Year
               FROM semester_assessment
              GROUP BY Sid, Course_Code, semester, Year
             HAVING COUNT(*) > 1
         ) duplicates"
    ));
    $add('legacy_orphan_student', 'CA rows referencing a missing student', 'fail', $count($db,
        'SELECT COUNT(*) AS c FROM semester_assessment sa LEFT JOIN students s ON s.SID = sa.Sid WHERE s.SID IS NULL'
    ));
    $add('legacy_orphan_course', 'CA rows referencing a missing course', 'fail', $count($db,
        'SELECT COUNT(*) AS c FROM semester_assessment sa LEFT JOIN courses c ON c.course_code = sa.Course_Code WHERE c.course_code IS NULL'
    ));
    $add('legacy_invalid_marks', 'CA marks outside 0–100', 'fail', $count($db,
        "SELECT COUNT(*) AS c FROM semester_assessment
          WHERE (A1 < 0 OR A1 > 100) OR (A2 < 0 OR A2 > 100) OR (A3 < 0 OR A3 > 100)
             OR (T1 < 0 OR T1 > 100) OR (T2 < 0 OR T2 > 100) OR (Exam < 0 OR Exam > 100)
             OR (Total_CA < 0 OR Total_CA > 100)"
    ));
    $add('legacy_invalid_period', 'CA rows with an invalid academic year or period', 'fail', $count($db,
        "SELECT COUNT(*) AS c FROM semester_assessment
          WHERE Year NOT REGEXP '^[0-9]{4}$'
             OR (program_type <> 'short_course' AND semester NOT IN ('1','2','3'))"
    ));
    $periodMismatchBase = "SELECT COUNT(DISTINCT sa.id) AS c
           FROM semester_assessment sa
           JOIN student_program sp ON sp.Sid = sa.Sid
           JOIN programs p ON p.program_code = sp.program_code AND p.structure_type = 'TERM_BASED'
          WHERE %s
            AND ((sa.semester = '1' AND sa.T2 IS NOT NULL)
              OR (sa.semester = '2' AND sa.T1 IS NOT NULL)
              OR (sa.semester = '3' AND (sa.T1 IS NOT NULL OR sa.T2 IS NOT NULL)))";
    $periodActiveExists = "EXISTS (
        SELECT 1 FROM course_registration cr
         WHERE cr.Sid = sa.Sid AND cr.course_code = sa.Course_Code
           AND CAST(cr.academic_year AS CHAR) = sa.Year
           AND CAST(cr.semester AS CHAR) = sa.semester
           AND (cr.is_active = 1 OR cr.status IN ('active','registered'))
    )";
    $add('active_component_period_mismatch', 'Active term CA rows use a test column from the wrong term', 'fail', $count($db,
        sprintf($periodMismatchBase, $periodActiveExists)
    ));
    $add('historical_component_period_mismatch', 'Historical term CA rows retain a legacy test-column mapping', 'warn', $count($db,
        sprintf($periodMismatchBase, 'NOT ' . $periodActiveExists)
    ));

    $totalMismatches = [];
    $rows = $db->query('SELECT id, Sid, Course_Code, A1, A2, A3, T1, T2, Total_CA FROM semester_assessment ORDER BY id');
    while ($row = $rows->fetch_assoc()) {
        $components = [];
        foreach (['A1','A2','A3','T1','T2'] as $component) {
            $components[$component] = $row[$component] === null ? null : (float)$row[$component];
        }
        $calculated = ca_calculate_course_total($db, (string)$row['Sid'], (string)$row['Course_Code'], $components);
        $stored = $row['Total_CA'] === null ? null : (float)$row['Total_CA'];
        if (($calculated === null) !== ($stored === null)
            || ($calculated !== null && $stored !== null && abs($calculated - $stored) > 0.005)) {
            $totalMismatches[] = [
                'id' => (int)$row['id'],
                'stored' => $stored,
                'expected' => $calculated,
            ];
        }
    }
    $add('legacy_total_mismatch', 'Stored Total_CA differs from canonical component weighting', 'fail', count($totalMismatches), $totalMismatches);

    $add('duplicate_component_name', 'Duplicate component names within an assessment scheme', 'fail', $count($db,
        "SELECT COUNT(*) AS c FROM (
             SELECT assessment_scheme_id, LOWER(TRIM(component_name))
               FROM assessment_components
              GROUP BY assessment_scheme_id, LOWER(TRIM(component_name))
             HAVING COUNT(*) > 1
         ) duplicates"
    ));
    $add('multiple_active_scheme', 'Multiple active schemes for one programme/course', 'fail', $count($db,
        "SELECT COUNT(*) AS c FROM (
             SELECT program_code, course_code
               FROM assessment_schemes
              WHERE status = 'active'
              GROUP BY program_code, course_code
             HAVING COUNT(*) > 1
         ) duplicates"
    ));
    $add('scheme_weight_mismatch', 'Scheme/component weights do not reconcile', 'fail', $count($db,
        "SELECT COUNT(*) AS c FROM (
             SELECT s.id
               FROM assessment_schemes s
          LEFT JOIN assessment_components ac ON ac.assessment_scheme_id = s.id
              GROUP BY s.id, s.ca_weight, s.exam_weight
             HAVING ABS(COALESCE(SUM(CASE WHEN ac.component_type <> 'EXAM' THEN ac.weight ELSE 0 END),0) - s.ca_weight) > 0.005
                 OR ABS(COALESCE(SUM(CASE WHEN ac.component_type = 'EXAM' THEN ac.weight ELSE 0 END),0) - s.exam_weight) > 0.005
         ) bad_weights"
    ));

    $add('active_cross_program_registration', 'Active normalized registrations point to another programme’s offering', 'fail', $count($db,
        "SELECT COUNT(*) AS c
           FROM student_course_registrations scr
           JOIN student_program sp ON sp.id = scr.student_programme_id
           JOIN course_offerings co ON co.id = scr.course_offering_id
          WHERE sp.program_code <> co.program_code
            AND scr.registration_status IN ('REGISTERED','COMPLETED','REPEATING')"
    ));
    $add('legacy_registration_missing_normalized', 'Active legacy course registrations missing the normalized offering bridge', 'fail', $count($db,
        "SELECT COUNT(*) AS c
           FROM course_registration cr
           JOIN student_program sp ON sp.Sid = cr.Sid
          WHERE (cr.is_active = 1 OR cr.status IN ('active','registered'))
            AND NOT EXISTS (
                SELECT 1
                  FROM student_course_registrations scr
                  JOIN course_offerings co ON co.id = scr.course_offering_id AND co.program_code = sp.program_code
                  JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
                  JOIN academic_periods ap ON ap.id = co.academic_period_id
                 WHERE scr.student_programme_id = sp.id
                   AND cc.course_code = cr.course_code
                   AND ap.academic_year = CAST(cr.academic_year AS CHAR)
                   AND ap.period_number = cr.semester
                   AND scr.registration_status IN ('REGISTERED','COMPLETED','REPEATING')
            )"
    ));
    $missingResultSql = "SELECT COUNT(*) AS c
           FROM semester_assessment sa
          WHERE %s
            AND NOT EXISTS (
                SELECT 1
                  FROM student_program sp
                  JOIN student_course_registrations scr ON scr.student_programme_id = sp.id
                  JOIN course_offerings co ON co.id = scr.course_offering_id AND co.program_code = sp.program_code
                  JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id AND cc.course_code = sa.Course_Code
                  JOIN academic_periods ap ON ap.id = co.academic_period_id
                  JOIN student_course_results result_row ON result_row.student_course_registration_id = scr.id
                 WHERE sp.Sid = sa.Sid
                   AND ap.academic_year = sa.Year
                   AND ap.period_number = CAST(sa.semester AS UNSIGNED)
            )";
    $activeRegistrationExists = "EXISTS (
                SELECT 1 FROM course_registration cr
                 WHERE cr.Sid = sa.Sid
                   AND cr.course_code = sa.Course_Code
                   AND CAST(cr.academic_year AS CHAR) = sa.Year
                   AND CAST(cr.semester AS CHAR) = sa.semester
                   AND (cr.is_active = 1 OR cr.status IN ('active','registered'))
            )";
    $add('active_ca_missing_normalized_result', 'Active CA rows missing a normalized course result', 'fail', $count($db,
        sprintf($missingResultSql, $activeRegistrationExists)
    ));
    $add('historical_ca_without_normalized_result', 'Historical/dropped CA rows retained only in the compatibility store', 'warn', $count($db,
        sprintf($missingResultSql, 'NOT ' . $activeRegistrationExists)
    ));
    $add('normalized_ca_points_mismatch', 'Normalized ca_total is not scaled to the scheme CA weight', 'fail', $count($db,
        "SELECT COUNT(*) AS c
           FROM semester_assessment sa
           JOIN student_program sp ON sp.Sid = sa.Sid
           JOIN student_course_registrations scr ON scr.student_programme_id = sp.id
           JOIN course_offerings co ON co.id = scr.course_offering_id AND co.program_code = sp.program_code
           JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id AND cc.course_code = sa.Course_Code
           JOIN academic_periods ap ON ap.id = co.academic_period_id
           JOIN assessment_schemes sch ON sch.program_code = sp.program_code AND sch.course_code = sa.Course_Code AND sch.status = 'active'
           JOIN student_course_results result_row ON result_row.student_course_registration_id = scr.id
          WHERE ap.academic_year = sa.Year
            AND ap.period_number = CAST(sa.semester AS UNSIGNED)
            AND sa.Total_CA IS NOT NULL
            AND ABS(result_row.ca_total - ROUND(sa.Total_CA * sch.ca_weight / 100, 2)) > 0.005"
    ));

    $markMismatches = [];
    $markRows = $db->query(
        "SELECT sa.id, sa.Sid, sa.Course_Code, sa.semester, sa.Year, sa.status,
                sa.A1, sa.A2, sa.A3, sa.T1, sa.T2
           FROM semester_assessment sa
          WHERE EXISTS (
                SELECT 1 FROM course_registration cr
                 WHERE cr.Sid = sa.Sid
                   AND cr.course_code = sa.Course_Code
                   AND CAST(cr.academic_year AS CHAR) = sa.Year
                   AND CAST(cr.semester AS CHAR) = sa.semester
                   AND (cr.is_active = 1 OR cr.status IN ('active','registered'))
          )
          ORDER BY sa.id"
    );
    $markLookup = $db->prepare(
        'SELECT mark_obtained, status FROM student_assessment_marks
          WHERE student_course_registration_id = ? AND assessment_component_id = ? LIMIT 1'
    );
    while ($row = $markRows->fetch_assoc()) {
        foreach (['A1','A2','A3','T1','T2'] as $component) {
            if ($row[$component] === null) {
                continue;
            }
            $target = ca_resolve_normalized_mark_target(
                $db,
                (string)$row['Sid'],
                (string)$row['Course_Code'],
                (string)$row['semester'],
                (string)$row['Year'],
                $component
            );
            if ($target === null) {
                $markMismatches[] = ['id' => (int)$row['id'], 'component' => $component, 'reason' => 'target_missing'];
                continue;
            }
            $registrationId = (int)$target['student_course_registration_id'];
            $componentId = (int)$target['assessment_component_id'];
            $markLookup->bind_param('ii', $registrationId, $componentId);
            $markLookup->execute();
            $normalized = $markLookup->get_result()->fetch_assoc();
            $legacyMark = (float)$row[$component];
            if (!$normalized || abs((float)$normalized['mark_obtained'] - $legacyMark) > 0.005) {
                $markMismatches[] = [
                    'id' => (int)$row['id'],
                    'component' => $component,
                    'reason' => $normalized ? 'value_mismatch' : 'mark_missing',
                    'legacy' => $legacyMark,
                    'normalized' => $normalized ? (float)$normalized['mark_obtained'] : null,
                ];
            }
        }
    }
    $markLookup->close();
    $add('normalized_component_mark_mismatch', 'Active legacy component marks are not mirrored in the normalized store', 'fail', count($markMismatches), $markMismatches);

    return $checks;
}

/** @param array<int,array{severity:string,count:int,ok:bool}> $checks */
function ca_integrity_summary(array $checks): array
{
    $summary = ['fail' => 0, 'warn' => 0, 'ok' => 0, 'clean' => true];
    foreach ($checks as $check) {
        if ($check['ok']) {
            $summary['ok']++;
            continue;
        }
        $severity = $check['severity'] === 'warn' ? 'warn' : 'fail';
        $summary[$severity] += (int)$check['count'];
    }
    $summary['clean'] = $summary['fail'] === 0;
    return $summary;
}
