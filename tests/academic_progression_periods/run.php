<?php
declare(strict_types=1);

define('IS_SCRIPT', true);
require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/student_year_progression.php';
require_once dirname(__DIR__, 2) . '/students/includes/StudentAcademicWorkflowService.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$passed = $failed = 0;
function check_progression(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
}
function progression_query(mysqli $db, string $sql, string $types, array $params): array
{
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

foreach (['2098' => '2099', '2098/2099' => '2099', ' 2098 - 2099 ' => '2099',
          '' => '', 'invalid' => '', '2098/2100' => '', '9999' => ''] as $input => $expected) {
    check_progression('academic year normalization: ' . var_export($input, true),
        wuc_progression_next_academic_year((string)$input) === $expected);
}

// This harness never commits fixtures. Refuse legacy nontransactional installs.
foreach (['students', 'student_program', 'programs', 'semester_registration', 'course_registration',
          'student_progression_decisions', 'audit_log', 'audit_logs'] as $table) {
    $rows = progression_query($db, 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', 's', [$table]);
    if ($rows && $rows[0]['ENGINE'] !== 'InnoDB') {
        throw new RuntimeException('Regression tests require InnoDB: ' . $table);
    }
}

$db->begin_transaction();
try {
    $suffix = bin2hex(random_bytes(5));
    foreach ([['BSCS', 'semester'], ['AUTO-003', 'term'], ['ICT-001', 'term']] as [$program, $type]) {
        $sid = 'PROG-' . $program . '-' . $suffix;
        progression_query($db,
            "INSERT INTO students (SID, Fname, Lname, sex, email, mobile, status, program, academic_year, year)
             VALUES (?, 'Period', 'Regression', 'M', ?, '0000000000', 'active', ?, '2098/2099', 1)",
            'sss', [$sid, $sid . '@example.invalid', $program]);
        progression_query($db,
            "INSERT INTO student_program (Sid, program_code, intake, mode, startYear, endYear, status,
                 academic_year, year_of_study, current_year_number, term, semester, current_term_number, current_semester_number)
             VALUES (?, ?, 'January 2098', 'Full Time', 2098, 2101, 'active', '2098/2099', 1, 1, '3', 2, 3, 2)",
            'ss', [$sid, $program]);
        if ($program === 'BSCS') {
            // Canonical structure must win over stale legacy metadata.
            $db->query("UPDATE programs SET period_mode = 'term' WHERE program_code = 'BSCS'");
        }
        if ($program === 'ICT-001') {
            // Future/unrelated registration used to override the audited target year.
            progression_query($db,
                "INSERT INTO semester_registration (student_id, SID, program_code, semester, period_type,
                    year_of_study, Year, academic_year, registration_status, fee_status)
                 VALUES (?, ?, ?, '3', 'term', 1, 1, '2105', 'pending', 'unknown')",
                'sss', [$sid, $sid, $program]);
        }
        $target = $program === 'ICT-001' ? 'ICT-002' : $program;
        // Existing target registrations must retain their registration/fee state.
        progression_query($db,
            "INSERT INTO semester_registration (student_id, SID, program_code, semester, period_type,
                year_of_study, Year, academic_year, registration_status, fee_status)
             VALUES (?, ?, ?, '1', ?, 2, 2, '2099', 'registered', 'eligible')",
            'ssss', [$sid, $sid, $target, $type]);
        $course = $db->query('SELECT course_code FROM courses ORDER BY course_code LIMIT 1')->fetch_row()[0];
        foreach ([1, 2] as $courseYear) {
            progression_query($db,
                "INSERT INTO course_registration (Sid, course_code, semester, Year, academic_year, status, is_active)
                 VALUES (?, ?, 1, ?, ?, 'registered', 1)",
                'ssii', [$sid, $course, $courseYear, 2097 + $courseYear]);
        }
        $result = wuc_progress_student_year($db, $sid, 'test-suite', 'systems_admin', 'administrative', 'REG-' . $sid, '', null, null, false);
        check_progression($program . ' progression succeeds with a ranged academic year', $result['ok']);
        if (!$result['ok']) {
            echo '  ' . $result['message'] . PHP_EOL;
        }
        $courseRows = progression_query($db, 'SELECT Year, is_active FROM course_registration WHERE Sid = ? ORDER BY Year', 's', [$sid]);
        check_progression($program . ' retires previous-year courses while preserving target-year courses',
            (int)$courseRows[0]['is_active'] === 0 && (int)$courseRows[1]['is_active'] === 1);
        $row = progression_query($db,
            'SELECT sp.*, s.year AS student_year, s.academic_year AS student_academic_year FROM student_program sp JOIN students s ON s.SID = sp.Sid WHERE sp.Sid = ?',
            's', [$sid])[0];
        check_progression($program . ' advances both year fields and uses the canonical target year',
            $row['program_code'] === $target && (int)$row['year_of_study'] === 2 && (int)$row['current_year_number'] === 2
            && (int)$row['student_year'] === 2 && $row['academic_year'] === '2099' && $row['student_academic_year'] === '2099');
        check_progression($program . ' starts the correct period type at 1',
            $type === 'term' ? ((int)$row['current_term_number'] === 1 && $row['current_semester_number'] === null)
                : ((int)$row['current_semester_number'] === 1 && $row['current_term_number'] === null));
        $audit = progression_query($db, 'SELECT to_academic_year FROM student_progression_decisions WHERE student_id = ?', 's', [$sid])[0];
        check_progression($program . ' audit matches the resulting academic year', $audit['to_academic_year'] === $row['academic_year']);
        $registration = progression_query($db, 'SELECT * FROM semester_registration WHERE student_id = ? AND academic_year = ?', 'ss', [$sid, '2099'])[0];
        check_progression($program . ' preserves an existing registered and fee-eligible period',
            $registration['registration_status'] === 'registered' && $registration['fee_status'] === 'eligible');
        progression_query($db, "UPDATE semester_registration SET period_type = ? WHERE id = ?", 'si', [$type === 'term' ? 'semester' : 'term', (int)$registration['id']]);
        $updated = progression_query($db, 'SELECT period_type FROM semester_registration WHERE id = ?', 'i', [(int)$registration['id']])[0];
        check_progression($program . ' update trigger follows canonical programme structure', $updated['period_type'] === $type);

        $service = new StudentAcademicWorkflowService($db);
        $sync = new ReflectionMethod($service, 'syncStudentProgramPosition');
        $sync->invoke($service, $sid, $target, 2, 2, $type, '2099');
        if ($type === 'term') {
            $sync->invoke($service, $sid, $target, 2, 3, $type, '2099');
        }
        $position = progression_query($db, 'SELECT * FROM student_program WHERE Sid = ?', 's', [$sid])[0];
        check_progression($program . ' later periods keep the same academic year and year of study',
            $position['academic_year'] === '2099' && (int)$position['year_of_study'] === 2
            && (int)$position[$type === 'term' ? 'current_term_number' : 'current_semester_number'] === ($type === 'term' ? 3 : 2));

        // A conflict must reject the operation, preserving the original row.
        $db->query('SAVEPOINT conflict_test');
        try {
            wuc_progression_prepare_registration($db, $sid, $target, $type, 3, '2099');
            check_progression($program . ' rejects conflicting year-of-study registration', false);
        } catch (RuntimeException $e) {
            check_progression($program . ' rejects conflicting year-of-study registration', str_contains($e->getMessage(), 'different year'));
        } finally {
            $db->query('ROLLBACK TO SAVEPOINT conflict_test');
        }
    }
    check_progression('final year cannot advance beyond programme duration', !wuc_progression_target('BSCS', 4, 4.0)['ok']);
} finally {
    $db->rollback();
}
echo "PASS: {$passed} FAIL: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
