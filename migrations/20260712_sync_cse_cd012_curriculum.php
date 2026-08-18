<?php
declare(strict_types=1);

/**
 * Synchronize the Computer Systems Engineering curriculum with TEVETA chart
 * CD012 (Certificate Year 1 / Diploma Year 2).
 *
 * Usage:
 *   php migrations/20260712_sync_cse_cd012_curriculum.php
 *
 * Historical registrations are intentionally preserved. DCSE-102 is retained
 * as an inactive catalogue record because it was previously used when the
 * combined Year-1 module was incorrectly split into two courses.
 */

require_once dirname(__DIR__) . '/db/connect.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$courseNames = [
    'DCSE-101' => 'Information Technology and Application Packages',
    'DCSE-103' => 'Computer Hardware Maintenance and Repair I',
    'DCSE-104' => 'Computer Networks and Communication I',
    'DCSE-105' => 'Electronics I',
    'DCSE-106' => 'Engineering Science',
    'DCSE-107' => 'Engineering Mathematics',
    'DCSE-108' => 'Communication Skills',
    'DCSE-109' => 'Foundation of Management',
    'DCSE-201' => 'Computer Hardware Maintenance and Repair II',
    'DCSE-202' => 'Computer Networks and Communication II',
    'DCSE-203' => 'Digital Electronics',
    'DCSE-204' => 'Operating System',
    'DCSE-205' => 'Programming and Database Technology',
    'DCSE-206' => 'Entrepreneurship',
    'DCSE-207' => 'Management Information Systems',
    'DCSE-208' => 'Project',
];

$yearOne = [
    'DCSE-101',
    'DCSE-103',
    'DCSE-104',
    'DCSE-105',
    'DCSE-106',
    'DCSE-107',
    'DCSE-108',
    'DCSE-109',
];

$yearTwo = [
    'DCSE-201',
    'DCSE-202',
    'DCSE-203',
    'DCSE-204',
    'DCSE-205',
    'DCSE-206',
    'DCSE-207',
    'DCSE-208',
];

$programCurricula = [
    // Retired umbrella code: retained for historical references only.
    'CSE' => [],
    // CD012 awards the craft certificate at the end of Year 1.
    'ICT-001' => [1 => $yearOne],
    // The diploma is a progression-only stage and therefore owns Year 2 only.
    'ICT-002' => [2 => $yearTwo],
];

$db->begin_transaction();

try {
    $updateCourse = $db->prepare(
        "UPDATE courses
            SET course_name = ?, status = 'active', course_type = 'diploma'
          WHERE course_code = ?"
    );
    foreach ($courseNames as $courseCode => $courseName) {
        $updateCourse->bind_param('ss', $courseName, $courseCode);
        $updateCourse->execute();
        if ($updateCourse->affected_rows === 0) {
            $checkCourse = $db->prepare('SELECT 1 FROM courses WHERE course_code = ?');
            $checkCourse->bind_param('s', $courseCode);
            $checkCourse->execute();
            if ($checkCourse->get_result()->num_rows === 0) {
                throw new RuntimeException("Required course {$courseCode} does not exist.");
            }
            $checkCourse->close();
        }
    }
    $updateCourse->close();

    $retiredCode = 'DCSE-102';
    $retiredName = 'Application Packages (superseded by DCSE-101)';
    $retireCourse = $db->prepare(
        "UPDATE courses SET course_name = ?, status = 'inactive' WHERE course_code = ?"
    );
    $retireCourse->bind_param('ss', $retiredName, $retiredCode);
    $retireCourse->execute();
    $retireCourse->close();

    $db->query(
        "UPDATE programs
            SET is_active = 0,
                program_description = 'Retired legacy umbrella code. Use ICT-001 for Year 1 and progress eligible students to ICT-002 for Year 2.'
          WHERE program_code = 'CSE'"
    );
    $db->query(
        "UPDATE programs
            SET is_active = 1,
                program_type = 'Certificate',
                qualification_level = 'Certificate',
                program_duration = 1
          WHERE program_code = 'ICT-001'"
    );
    $db->query(
        "UPDATE programs
            SET is_active = 1,
                program_type = 'Diploma',
                qualification_level = 'Diploma',
                program_duration = 2,
                program_description = 'Year-2 progression stage following successful completion of ICT-001.'
          WHERE program_code = 'ICT-002'"
    );

    $programExists = $db->prepare('SELECT 1 FROM programs WHERE program_code = ?');
    $selectAssignments = $db->prepare(
        'SELECT id, course_code, year FROM program_courses WHERE program_code = ? ORDER BY id'
    );
    $deleteAssignment = $db->prepare('DELETE FROM program_courses WHERE id = ?');
    $normalizeAssignment = $db->prepare(
        "UPDATE program_courses
            SET semester = 1,
                is_required = 1,
                is_full_year = 1,
                is_period_specific = 0,
                is_term_specific = 0,
                is_semester_specific = 0,
                delivery_period = 'full_year'
          WHERE id = ?"
    );
    $insertAssignment = $db->prepare(
        "INSERT INTO program_courses
            (program_code, course_code, year, semester, is_required,
             is_full_year, is_period_specific, is_term_specific,
             is_semester_specific, delivery_period)
         VALUES (?, ?, ?, 1, 1, 1, 0, 0, 0, 'full_year')"
    );

    foreach ($programCurricula as $programCode => $years) {
        $programExists->bind_param('s', $programCode);
        $programExists->execute();
        if ($programExists->get_result()->num_rows === 0) {
            throw new RuntimeException("Required program {$programCode} does not exist.");
        }

        $desired = [];
        foreach ($years as $year => $courseCodes) {
            foreach ($courseCodes as $courseCode) {
                $desired[$year . ':' . $courseCode] = true;
            }
        }

        $seen = [];
        $selectAssignments->bind_param('s', $programCode);
        $selectAssignments->execute();
        $existingRows = $selectAssignments->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($existingRows as $row) {
            $assignmentId = (int)$row['id'];
            $key = (int)$row['year'] . ':' . (string)$row['course_code'];
            if (!isset($desired[$key]) || isset($seen[$key])) {
                $deleteAssignment->bind_param('i', $assignmentId);
                $deleteAssignment->execute();
                continue;
            }

            $seen[$key] = true;
            $normalizeAssignment->bind_param('i', $assignmentId);
            $normalizeAssignment->execute();
        }

        foreach ($years as $year => $courseCodes) {
            foreach ($courseCodes as $courseCode) {
                $key = $year . ':' . $courseCode;
                if (isset($seen[$key])) {
                    continue;
                }
                $insertAssignment->bind_param('ssi', $programCode, $courseCode, $year);
                $insertAssignment->execute();
            }
        }
    }

    $programExists->close();
    $selectAssignments->close();
    $deleteAssignment->close();
    $normalizeAssignment->close();
    $insertAssignment->close();

    // Synchronize the normalized curriculum layer used first by student course
    // registration. Preserve historical offerings by archiving bad versions
    // instead of deleting their curriculum rows (which would cascade).
    $db->query("UPDATE curriculum_versions SET status = 'archived' WHERE program_code = 'CSE' AND status = 'active'");
    $db->query(
        "UPDATE curriculum_versions cv
            SET cv.status = 'archived'
          WHERE cv.program_code = 'ICT-002' AND cv.status = 'active'
            AND EXISTS (
                SELECT 1 FROM curriculum_courses cc
                 WHERE cc.curriculum_version_id = cv.id
                   AND (cc.year_number <> 2 OR cc.course_code = 'DCSE-102')
            )"
    );
    $db->query(
        "INSERT INTO curriculum_versions (program_code, version_name, effective_year, status)
         SELECT 'ICT-001', 'CD012 Craft Certificate Year 1', 2026, 'active'
          WHERE NOT EXISTS (
                SELECT 1 FROM curriculum_versions
                 WHERE program_code = 'ICT-001' AND status = 'active'
          )"
    );
    $db->query(
        "INSERT INTO curriculum_versions (program_code, version_name, effective_year, status)
         SELECT 'ICT-002', 'CD012 Diploma Year 2', 2026, 'active'
          WHERE NOT EXISTS (
                SELECT 1 FROM curriculum_versions
                 WHERE program_code = 'ICT-002' AND status = 'active'
          )"
    );

    $versionStmt = $db->prepare(
        "SELECT id FROM curriculum_versions
          WHERE program_code = ? AND status = 'active'
          ORDER BY effective_year DESC, id DESC LIMIT 1"
    );
    $selectCurriculum = $db->prepare(
        'SELECT id, course_code, year_number FROM curriculum_courses WHERE curriculum_version_id = ? ORDER BY id'
    );
    $deleteCurriculum = $db->prepare('DELETE FROM curriculum_courses WHERE id = ?');
    $normalizeCurriculum = $db->prepare(
        "UPDATE curriculum_courses
            SET term_number = 1,
                semester_number = NULL,
                level_number = NULL,
                is_core = 1,
                is_full_year = 1,
                is_period_specific = 0,
                is_term_specific = 0,
                is_semester_specific = 0,
                delivery_period = 'full_year'
          WHERE id = ?"
    );
    $insertCurriculum = $db->prepare(
        "INSERT INTO curriculum_courses
            (curriculum_version_id, course_code, year_number, term_number, is_core,
             is_full_year, is_period_specific, is_term_specific,
             is_semester_specific, delivery_period, display_order)
         VALUES (?, ?, ?, 1, 1, 1, 0, 0, 0, 'full_year', ?)"
    );

    foreach (['ICT-001' => [1 => $yearOne], 'ICT-002' => [2 => $yearTwo]] as $programCode => $years) {
        $versionStmt->bind_param('s', $programCode);
        $versionStmt->execute();
        $versionId = (int)($versionStmt->get_result()->fetch_assoc()['id'] ?? 0);
        if ($versionId < 1) {
            throw new RuntimeException("Active curriculum version missing for {$programCode}.");
        }

        $desired = [];
        foreach ($years as $year => $courseCodes) {
            foreach ($courseCodes as $displayIndex => $courseCode) {
                $desired[$year . ':' . $courseCode] = $displayIndex + 1;
            }
        }

        $seen = [];
        $selectCurriculum->bind_param('i', $versionId);
        $selectCurriculum->execute();
        foreach ($selectCurriculum->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $curriculumId = (int)$row['id'];
            $key = (int)$row['year_number'] . ':' . (string)$row['course_code'];
            if (!isset($desired[$key]) || isset($seen[$key])) {
                $deleteCurriculum->bind_param('i', $curriculumId);
                $deleteCurriculum->execute();
                continue;
            }
            $seen[$key] = true;
            $normalizeCurriculum->bind_param('i', $curriculumId);
            $normalizeCurriculum->execute();
        }

        foreach ($years as $year => $courseCodes) {
            foreach ($courseCodes as $displayIndex => $courseCode) {
                $key = $year . ':' . $courseCode;
                if (isset($seen[$key])) {
                    continue;
                }
                $displayOrder = $displayIndex + 1;
                $insertCurriculum->bind_param('isii', $versionId, $courseCode, $year, $displayOrder);
                $insertCurriculum->execute();
            }
        }
    }

    $versionStmt->close();
    $selectCurriculum->close();
    $deleteCurriculum->close();
    $normalizeCurriculum->close();
    $insertCurriculum->close();

    // Normalize legacy/current student enrolments to the staged programme model.
    $db->query(
        "UPDATE student_program sp
           LEFT JOIN curriculum_versions cv
                  ON cv.program_code = 'ICT-001' AND LOWER(COALESCE(cv.status, 'active')) = 'active'
           SET sp.program_code = 'ICT-001',
               sp.curriculum_version_id = cv.id,
               sp.year_of_study = 1,
               sp.current_year_number = 1
         WHERE (sp.program_code = 'CSE' AND COALESCE(sp.year_of_study, sp.current_year_number, 1) <= 1)
            OR (sp.program_code = 'ICT-002' AND COALESCE(sp.year_of_study, sp.current_year_number, 1) <= 1)"
    );
    $db->query(
        "UPDATE student_program sp
           LEFT JOIN curriculum_versions cv
                  ON cv.program_code = 'ICT-002' AND LOWER(COALESCE(cv.status, 'active')) = 'active'
           SET sp.program_code = 'ICT-002',
               sp.curriculum_version_id = cv.id,
               sp.year_of_study = 2,
               sp.current_year_number = 2
         WHERE sp.program_code = 'CSE'
           AND COALESCE(sp.year_of_study, sp.current_year_number, 1) >= 2"
    );
    $db->query(
        "UPDATE student_program
            SET year_of_study = 1, current_year_number = 1
          WHERE program_code = 'ICT-001'"
    );
    $db->query(
        "UPDATE student_program
            SET year_of_study = 2, current_year_number = 2
          WHERE program_code = 'ICT-002'"
    );

    $db->query(
        "UPDATE students
            SET program = CASE WHEN COALESCE(year, 1) >= 2 THEN 'ICT-002' ELSE 'ICT-001' END,
                year = CASE WHEN COALESCE(year, 1) >= 2 THEN 2 ELSE 1 END
          WHERE program = 'CSE'"
    );
    $db->query("UPDATE students SET year = 1 WHERE program = 'ICT-001'");
    $db->query("UPDATE students SET year = 2 WHERE program = 'ICT-002'");

    $db->query(
        "UPDATE semester_registration
            SET program_code = CASE WHEN COALESCE(year_of_study, Year, 1) >= 2 THEN 'ICT-002' ELSE 'ICT-001' END
          WHERE program_code = 'CSE'"
    );

    // Keep the normalized programme pointer aligned with the latest registered
    // academic period. The dashboard resolves the period from
    // semester_registration, but other backend helpers read these fields.
    $db->query(
        "UPDATE student_program sp
         INNER JOIN semester_registration sr
                 ON sr.id = (
                     SELECT MAX(sr2.id)
                       FROM semester_registration sr2
                      WHERE (sr2.student_id = sp.Sid OR sr2.SID = sp.Sid)
                        AND LOWER(COALESCE(sr2.registration_status, 'registered')) = 'registered'
                 )
            SET sp.academic_year = sr.academic_year,
                sp.year_of_study = COALESCE(sr.year_of_study, sr.Year, sp.year_of_study),
                sp.current_year_number = COALESCE(sr.year_of_study, sr.Year, sp.current_year_number),
                sp.current_term_number = CASE WHEN sr.period_type = 'term' THEN sr.semester ELSE NULL END,
                sp.current_semester_number = CASE WHEN sr.period_type = 'semester' THEN sr.semester ELSE NULL END,
                sp.semester = sr.semester,
                sp.term = CASE WHEN sr.period_type = 'term' THEN sr.semester ELSE sp.term END
          WHERE sp.program_code IN ('ICT-001', 'ICT-002')"
    );

    // DCSE-102 was the duplicate half of the combined DCSE-101 module. Keep
    // the row for history, but do not leave it as a current active registration.
    $db->query(
        "UPDATE course_registration old_cr
           SET old_cr.is_active = 0,
               old_cr.status = 'dropped'
         WHERE old_cr.course_code = 'DCSE-102'
           AND COALESCE(old_cr.is_active, 1) = 1
           AND EXISTS (
               SELECT 1 FROM course_registration combined
                WHERE combined.Sid = old_cr.Sid
                  AND combined.course_code = 'DCSE-101'
                  AND combined.Year = old_cr.Year
                  AND COALESCE(combined.academic_year, 0) = COALESCE(old_cr.academic_year, 0)
           )"
    );

    // A staged student may only have active registrations from the current
    // programme stage/year. Preserve old or test rows, but remove them from the
    // active course catalogue after normalization/progression.
    $db->query(
        "UPDATE course_registration cr
         INNER JOIN student_program sp
                 ON sp.Sid = cr.Sid
                AND LOWER(COALESCE(sp.status, 'active')) = 'active'
                AND sp.program_code IN ('ICT-001', 'ICT-002')
           SET cr.is_active = 0,
               cr.status = 'dropped'
         WHERE COALESCE(cr.is_active, 1) = 1
           AND NOT EXISTS (
               SELECT 1 FROM program_courses pc
                WHERE pc.program_code = sp.program_code
                  AND pc.course_code = cr.course_code
                  AND pc.year = COALESCE(sp.year_of_study, sp.current_year_number, cr.Year)
           )"
    );

    // The normalized registration layer can still point at offerings created
    // for the retired CSE curriculum. Mark those rows historical as well, or
    // the dashboard/eLearning helper will prefer them over the corrected
    // active legacy registrations.
    $db->query(
        "UPDATE student_course_registrations scr
         INNER JOIN student_program sp ON sp.id = scr.student_programme_id
         INNER JOIN course_offerings co ON co.id = scr.course_offering_id
         INNER JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
            SET scr.registration_status = 'DROPPED'
          WHERE sp.program_code IN ('ICT-001', 'ICT-002')
            AND scr.registration_status IN ('REGISTERED', 'REPEATING')
            AND (
                cc.curriculum_version_id <> sp.curriculum_version_id
                OR COALESCE(NULLIF(TRIM(co.program_code), ''), sp.program_code) <> sp.program_code
                OR NOT EXISTS (
                    SELECT 1 FROM program_courses pc
                     WHERE pc.program_code = sp.program_code
                       AND pc.course_code = cc.course_code
                       AND pc.year = COALESCE(sp.year_of_study, sp.current_year_number, cc.year_number)
                )
            )"
    );

    $db->commit();

    foreach ($programCurricula as $programCode => $years) {
        $expected = array_sum(array_map('count', $years));
        echo "{$programCode}: {$expected} curriculum assignments synchronized.\n";
    }
    echo "Retired CSE registrations retained as inactive historical data.\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Curriculum synchronization failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
