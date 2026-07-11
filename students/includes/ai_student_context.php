<?php
declare(strict_types=1);

/**
 * Shared builder for a student's AI context (profile, program, registration,
 * courses, assessments, fees). Used by the AI Personal Assistant page and the
 * conversational chat endpoint so both see the same authoritative data.
 *
 * Privacy: only the student's own records are read (by their session SID).
 */

require_once __DIR__ . '/RegistrationDataService.php';
require_once __DIR__ . '/student_fee_records.php';
require_once __DIR__ . '/period_mode_helper.php';
require_once dirname(__DIR__, 2) . '/includes/elearning_access.php';

/**
 * Fetch a course's actual syllabus/lesson content (lesson_notes + e-learning
 * uploads) so the AI can ground explanations, exam prep, and syllabus
 * guidance in real material instead of only registration/marks data.
 */
if (!function_exists('wuc_ai_course_study_materials')) {
    function wuc_ai_course_study_materials(mysqli $db, string $courseCode, int $limit = 8): array
    {
        $materials = [];
        $courseCode = trim($courseCode);
        if ($courseCode === '') {
            return $materials;
        }

        if (elearningTableExists($db, 'lesson_notes')) {
            $cols = elearningTableColumns($db, 'lesson_notes');
            $courseCol = $cols['course_code'] ?? null;
            if ($courseCol !== null) {
                $topicCol = $cols['topic'] ?? null;
                $notesCol = $cols['notes'] ?? null;
                $dateCol = $cols['dte'] ?? ($cols['created_at'] ?? null);
                $topicSelect = $topicCol ? "`{$topicCol}` AS topic" : "'' AS topic";
                $notesSelect = $notesCol ? "`{$notesCol}` AS notes" : "'' AS notes";
                $dateSelect = $dateCol ? "`{$dateCol}` AS material_date" : "NULL AS material_date";
                $sql = "SELECT {$topicSelect}, {$notesSelect}, {$dateSelect}
                        FROM lesson_notes
                        WHERE UPPER(TRIM(`{$courseCol}`)) = UPPER(TRIM(?))
                        ORDER BY " . ($dateCol ? "`{$dateCol}` DESC, " : '') . "id DESC
                        LIMIT ?";
                if ($stmt = $db->prepare($sql)) {
                    $stmt->bind_param('si', $courseCode, $limit);
                    if ($stmt->execute()) {
                        $res = $stmt->get_result();
                        while ($row = $res->fetch_assoc()) {
                            $materials[] = [
                                'source' => 'lesson_notes',
                                'title' => (string)($row['topic'] ?? ''),
                                'excerpt' => wuc_ai_truncate(strip_tags((string)($row['notes'] ?? '')), 900),
                                'date' => (string)($row['material_date'] ?? ''),
                            ];
                        }
                    }
                    $stmt->close();
                }
            }
        }

        if (elearningTableExists($db, 'el_course_modules') && elearningTableExists($db, 'el_contents')) {
            $sql = "SELECT c.title, c.content_type, c.created_at
                    FROM el_contents c
                    INNER JOIN el_course_modules m ON m.id = c.module_id
                    WHERE UPPER(TRIM(m.course_code)) = UPPER(TRIM(?))
                    ORDER BY c.created_at DESC, c.id DESC
                    LIMIT ?";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('si', $courseCode, $limit);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $materials[] = [
                            'source' => 'el_contents',
                            'title' => (string)($row['title'] ?? ''),
                            'type' => (string)($row['content_type'] ?? ''),
                            'date' => (string)($row['created_at'] ?? ''),
                        ];
                    }
                }
                $stmt->close();
            }
        }

        return $materials;
    }
}

if (!function_exists('wuc_ai_student_context')) {
    function wuc_ai_student_context(mysqli $db, string $sid): array
    {
        $ctx = ['student_id' => $sid];
        $registrationService = new RegistrationDataService($db);
        $currentRegistration = null;

        $stmt = $db->prepare("SELECT Fname, Lname, email, status, program, year, academic_year, mode FROM students WHERE SID = ?");
        if ($stmt) {
            $stmt->bind_param('s', $sid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $ctx['name'] = trim($row['Fname'] . ' ' . $row['Lname']);
                $ctx['email'] = $row['email'];
                $ctx['status'] = $row['status'];
                $ctx['program_code'] = $row['program'];
                $ctx['year_of_study'] = $row['year'];
                $ctx['academic_year'] = $row['academic_year'];
                $ctx['attendance_mode'] = $row['mode'];
            }
        }

        $currentRegistration = $registrationService->getLatestSemesterRegistration($sid);
        if ($currentRegistration) {
            $ctx['program_code'] = (string)($currentRegistration['program_code'] ?? ($ctx['program_code'] ?? ''));
            $ctx['year_of_study'] = $currentRegistration['year_of_study'] ?? ($ctx['year_of_study'] ?? null);
            $ctx['current_period'] = $currentRegistration['semester'] ?? null;
            $ctx['semester_academic_year'] = $currentRegistration['academic_year'] ?? null;
            $ctx['registration_date'] = $currentRegistration['registration_date'] ?? null;
            $ctx['semester_registration_id'] = isset($currentRegistration['id']) ? (int)$currentRegistration['id'] : null;
        }

        if (!empty($ctx['program_code'])) {
            $ctx['period_mode'] = getProgramPeriodMode($db, (string)$ctx['program_code']);
            $stmt = $db->prepare('SELECT program_name, program_type, program_duration FROM programs WHERE program_code = ?');
            if ($stmt) {
                $stmt->bind_param('s', $ctx['program_code']);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $ctx['program_name'] = $row['program_name'];
                    $ctx['program_type'] = $row['program_type'];
                    $ctx['program_duration'] = $row['program_duration'];
                    $rawPm = strtolower(trim((string)$ctx['period_mode']));
                    $ctx['period_label'] = wuc_period_label_from_structure($rawPm !== '' ? $rawPm : 'semester', true);
                }
            }
        }

        $courses = [];
        if ($currentRegistration) {
            $registeredCourses = $registrationService->getRegisteredCourses(
                $sid,
                (int)($currentRegistration['year_of_study'] ?? 0),
                (int)($currentRegistration['semester'] ?? 0),
                isset($currentRegistration['id']) ? (int)$currentRegistration['id'] : null,
                isset($currentRegistration['academic_year']) ? (string)$currentRegistration['academic_year'] : null
            );
            foreach ($registeredCourses as $r) {
                $courses[] = [
                    'code' => (string)$r['course_code'],
                    'name' => (string)($r['course_name'] ?? ''),
                    'period' => $currentRegistration['semester'] ?? null,
                    'year_of_study' => $currentRegistration['year_of_study'] ?? null,
                    'academic_year' => $currentRegistration['academic_year'] ?? null,
                    'status' => 'active',
                ];
            }
        }
        $ctx['registered_courses'] = $courses;

        $marks = [];
        $stmt = $db->prepare(
            "SELECT course_code, semester, assess_type, marks, year
             FROM assessments WHERE SID = ?
             ORDER BY year DESC, semester DESC, id DESC LIMIT 15"
        );
        if ($stmt) {
            $stmt->bind_param('s', $sid);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $marks[] = [
                    'course' => $r['course_code'],
                    'period' => $r['semester'],
                    'type' => $r['assess_type'],
                    'marks' => $r['marks'],
                    'year' => $r['year'],
                ];
            }
            $stmt->close();
        }
        $ctx['assessments'] = $marks;

        $feeSummary = student_fee_current_program_summary($db, $sid);
        $ctx['fees'] = [
            'total_invoiced' => (float)($feeSummary['total_due'] ?? 0),
            'total_paid' => (float)($feeSummary['total_paid'] ?? 0),
            'balance_due' => (float)($feeSummary['balance'] ?? 0),
            'has_fees' => !empty($feeSummary['has_fees']),
            'program_code' => (string)($feeSummary['program_code'] ?? ''),
        ];
        if (!empty($feeSummary['registration'])) {
            $ctx['fee_registration'] = $feeSummary['registration'];
        }

        return $ctx;
    }
}
