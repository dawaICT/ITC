<?php
declare(strict_types=1);

/**
 * One-off data repair for the 2026-08 student-portal fix pass.
 * CLI only. Idempotent: every mutation is guarded by an existence check.
 *
 *  1. Students with active course_registration rows but no matching
 *     semester_registration get the missing registration (registration_status
 *     'registered', fee_status 'eligible') and their course rows are linked.
 *  2. short_course_assessment rows without a matching enrolment get an
 *     'enrolled' row so the marks are visible and editable.
 *  3. Missing portal_settings keys are seeded (current academic year/period,
 *     CA upload feature flags).
 *  4. Report-only: student_program rows pointing at unknown programs, and
 *     active long programmes without any fee_structure rows.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

define('IS_SCRIPT', true);
require_once dirname(__DIR__) . '/db/connect.php';

function rep_out(string $msg): void
{
    echo $msg . PHP_EOL;
}

$insertedRegs = 0;
$linkedCourses = 0;
$createdEnrolments = 0;
$seededSettings = 0;

// ------------------------------------------------------------- 1 -------
rep_out('== 1. Missing semester_registration rows ==');

$groups = $db->query(
    "SELECT cr.Sid AS sid,
            CAST(cr.semester AS UNSIGNED) AS period_number,
            CAST(cr.academic_year AS CHAR) AS academic_year,
            cr.`Year` AS year_of_study,
            sp.program_code,
            COALESCE(p.period_mode, 'semester') AS period_mode,
            MIN(cr.registration_date) AS first_reg
       FROM course_registration cr
       JOIN student_program sp ON sp.Sid = cr.Sid
            AND (sp.status IS NULL OR sp.status = '' OR LOWER(sp.status) = 'active')
       JOIN programs p ON p.program_code = sp.program_code
      WHERE COALESCE(cr.is_active, 1) = 1
        AND cr.academic_year IS NOT NULL
      GROUP BY cr.Sid, cr.semester, cr.academic_year, cr.`Year`, sp.program_code, p.period_mode
      ORDER BY cr.Sid"
);

if (!$groups) {
    rep_out('  ! query failed: ' . $db->error);
} else {
    $checkStmt = $db->prepare(
        'SELECT id FROM semester_registration
          WHERE student_id = ? AND program_code = ? AND period_type = ? AND semester = ? AND academic_year = ?
          LIMIT 1'
    );
    $apStmt = $db->prepare(
        'SELECT id FROM academic_periods WHERE academic_year = ? AND period_type = ? AND period_number = ? LIMIT 1'
    );
    $insStmt = $db->prepare(
        "INSERT INTO semester_registration
            (student_id, SID, program_code, semester, period_type, year_of_study, `Year`,
             academic_year, academic_period_id, registration_date, registration_status, fee_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'registered', 'eligible')"
    );
    $linkStmt = $db->prepare(
        'UPDATE course_registration SET semester_registration_id = ?
          WHERE Sid = ? AND CAST(academic_year AS CHAR) = ? AND semester = ? AND semester_registration_id IS NULL'
    );

    while ($g = $groups->fetch_assoc()) {
        $sid = (string)$g['sid'];
        $program = (string)$g['program_code'];
        $periodType = strtolower((string)$g['period_mode']) === 'term' ? 'term' : 'semester';
        $periodNo = (int)$g['period_number'];
        $yearOfStudy = max(1, (int)$g['year_of_study']);
        $academicYear = (string)$g['academic_year'];
        $firstReg = (string)($g['first_reg'] ?? date('Y-m-d H:i:s'));

        $checkStmt->bind_param('sssss', $sid, $program, $periodType, $periodNo, $academicYear);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();

        $db->begin_transaction();
        try {
            if ($existing) {
                $regId = (int)$existing['id'];
                rep_out("  = {$sid} {$program} {$periodType} {$periodNo}/{$academicYear}: registration exists (id {$regId}), linking courses only");
            } else {
                $apId = null;
                $apStmt->bind_param('ssi', $academicYear, $periodType, $periodNo);
                $apStmt->execute();
                if ($apRow = $apStmt->get_result()->fetch_assoc()) {
                    $apId = (int)$apRow['id'];
                }
                $periodStr = (string)$periodNo;
                $insStmt->bind_param(
                    'sssssiisis',
                    $sid, $sid, $program, $periodStr, $periodType, $yearOfStudy, $yearOfStudy,
                    $academicYear, $apId, $firstReg
                );
                $insStmt->execute();
                $regId = (int)$insStmt->insert_id;
                $insertedRegs++;
                rep_out("  + {$sid} {$program} {$periodType} {$periodNo}/{$academicYear}: registration created (id {$regId}, period_id " . ($apId === null ? 'NULL' : $apId) . ')');
            }

            $linkStmt->bind_param('issi', $regId, $sid, $academicYear, $periodNo);
            $linkStmt->execute();
            $linked = $linkStmt->affected_rows;
            $linkedCourses += $linked;
            $db->commit();
            rep_out("    linked {$linked} course row(s)");
        } catch (Throwable $e) {
            $db->rollback();
            rep_out("  ! {$sid}: failed - " . $e->getMessage());
        }
    }
    $checkStmt->close();
    $apStmt->close();
    $insStmt->close();
    $linkStmt->close();
}

// ------------------------------------------------------------- 2 -------
rep_out('');
rep_out('== 2. Orphan short_course_assessment rows (no enrolment) ==');

$orphans = $db->query(
    'SELECT sca.short_course_id, sca.student_id, sca.created_at
       FROM short_course_assessment sca
       LEFT JOIN short_course_enrollments sce
              ON sce.short_course_id = sca.short_course_id
             AND sce.student_id = sca.student_id
      WHERE sce.id IS NULL'
);
if (!$orphans) {
    rep_out('  ! query failed: ' . $db->error);
} else {
    $insEnrol = $db->prepare(
        "INSERT INTO short_course_enrollments (short_course_id, student_id, enrollment_date, status, notes)
         VALUES (?, ?, ?, 'enrolled', 'Auto-created by repair_student_records_2026: aligns an existing short_course_assessment record.')"
    );
    while ($o = $orphans->fetch_assoc()) {
        $scId = (int)$o['short_course_id'];
        $studentId = (string)$o['student_id'];
        $created = (string)($o['created_at'] ?? date('Y-m-d H:i:s'));
        $insEnrol->bind_param('iss', $scId, $studentId, $created);
        if ($insEnrol->execute()) {
            $createdEnrolments++;
            rep_out("  + enrolment created: student {$studentId} on short_course_id {$scId}");
        } else {
            rep_out("  ! enrolment failed for {$studentId} on {$scId}: " . $insEnrol->error);
        }
    }
    $insEnrol->close();
    if ($createdEnrolments === 0) {
        rep_out('  (none found)');
    }
}

// ------------------------------------------------------------- 3 -------
rep_out('');
rep_out('== 3. portal_settings seeds ==');

$seeds = [
    'current_academic_year' => '2026',
    'current_semester' => '2',
    'ca_upload_manual_enabled' => '1',
    'ca_upload_csv_enabled' => '1',
];
$seedStmt = $db->prepare('INSERT IGNORE INTO portal_settings (setting_key, setting_value) VALUES (?, ?)');
$readStmt = $db->prepare('SELECT setting_value FROM portal_settings WHERE setting_key = ? LIMIT 1');
foreach ($seeds as $key => $value) {
    $seedStmt->bind_param('ss', $key, $value);
    $seedStmt->execute();
    if ($seedStmt->affected_rows > 0) {
        $seededSettings++;
        rep_out("  + seeded {$key} = {$value}");
    } else {
        $readStmt->bind_param('s', $key);
        $readStmt->execute();
        $cur = $readStmt->get_result()->fetch_assoc();
        rep_out("  = {$key} already set to '" . ($cur['setting_value'] ?? '?') . "' (unchanged)");
    }
}
$seedStmt->close();
$readStmt->close();

// ------------------------------------------------------------- 4 -------
rep_out('');
rep_out('== 4. Report-only findings ==');

$badProgram = $db->query(
    'SELECT sp.Sid, sp.program_code
       FROM student_program sp
       LEFT JOIN programs p ON p.program_code = sp.program_code
      WHERE p.program_code IS NULL
      ORDER BY sp.Sid'
);
rep_out('  student_program rows pointing at unknown programs:');
if ($badProgram && $badProgram->num_rows > 0) {
    while ($b = $badProgram->fetch_assoc()) {
        rep_out("    - {$b['Sid']} -> {$b['program_code']}");
    }
} else {
    rep_out('    (none)');
}

$noFee = $db->query(
    'SELECT p.program_code, p.program_name
       FROM programs p
       LEFT JOIN fee_structure f ON f.program_code = p.program_code
      WHERE f.id IS NULL
        AND COALESCE(p.is_active, 1) = 1
        AND COALESCE(p.is_short_course, 0) = 0
      ORDER BY p.program_code'
);
rep_out('  active long programmes without fee_structure rows (fee gates fail open):');
if ($noFee && $noFee->num_rows > 0) {
    while ($n = $noFee->fetch_assoc()) {
        rep_out("    - {$n['program_code']} ({$n['program_name']})");
    }
} else {
    rep_out('    (none)');
}

rep_out('');
rep_out("DONE. registrations inserted: {$insertedRegs}; course rows linked: {$linkedCourses}; enrolments created: {$createdEnrolments}; settings seeded: {$seededSettings}");

