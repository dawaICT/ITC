<?php
declare(strict_types=1);

/**
 * Teaching Planner — Live provisioning seed (CLI only, idempotent).
 *
 * Populates every dependency the lecturer Teaching Planner page needs so that
 * lecturers can actually generate, save and review scheme-of-work plans:
 *
 *   Step A  Backfill offering-based lecturer assignments from the legacy
 *           `course_lecturer` table (same join as
 *           migrations/20260629_itc_foundation_gap_closure.sql).
 *   Step B  Resolve a deterministic demo lecturer/offering/course.
 *   Step C  Ensure a usable `course_schedule` row (numeric staff id, times).
 *   Step D  Register an active scheme-of-work DOCX template + v1 in protected
 *           storage (reusing the tested fixture from tests/teaching_planner).
 *   Step E  Register an approved syllabus version + topics.
 *   Step F  Generate one real plan via TeachingPlannerService (the exact same
 *           path the UI uses) so "My teaching plans" has a row to open.
 *
 * Safe to run repeatedly: every step re-checks before writing.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts\seed_teaching_planner_live.php
 */

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/teaching_planner/init.php';

const SEED_ACTOR = 'EXH-LEC-001';            // Real lecturer with teaching assignments
const SEED_COURSE = 'DCSE-101';              // Computer Systems core course
const SEED_PROGRAM = 'ICT-001';              // Diploma in ICT (TERM_BASED)
const SEED_TEMPLATE_NAME = 'Scheme of Work (Term) — Portal Seed';
const SEED_SYLLABUS_LABEL = '2026 Portal Seed (Approved)';

$_SESSION['staff_id'] = SEED_ACTOR;

$seedActor = SEED_ACTOR;
$seedCourse = SEED_COURSE;
$seedProgram = SEED_PROGRAM;
$seedTemplateName = SEED_TEMPLATE_NAME;
$seedSyllabusLabel = SEED_SYLLABUS_LABEL;

function seed_count(mysqli $db, string $table): int
{
    $r = $db->query("SELECT COUNT(*) AS c FROM `{$table}`");
    return (int)(($r ? $r->fetch_assoc() : [])['c'] ?? 0);
}

function seed_exit_success(mysqli $db, array $stats): never
{
    echo "\n== seed summary ==\n";
    foreach ($stats as $label => $value) {
        echo str_pad((string)$label, 34) . $value . PHP_EOL;
    }
    echo str_pad('teaching_plans (total)', 34) . seed_count($db, 'teaching_plans') . PHP_EOL;
    exit(0);
}

try {
    // ── Step A: backfill offering-based lecturer assignments ──────────────
    echo "== Step A: backfill lecturer_course_assignments ==\n";
    $beforeAssignments = seed_count($db, 'lecturer_course_assignments');
    $db->query(
        "INSERT INTO lecturer_course_assignments (staff_id, course_offering_id, assignment_role, status)
         SELECT DISTINCT cl.staff_id, co.id, 'Main Lecturer',
                CASE WHEN LOWER(COALESCE(cl.status, 'active')) IN ('inactive','ended') THEN 'inactive' ELSE 'active' END
           FROM course_lecturer cl
           JOIN curriculum_courses cc ON cc.course_code = cl.course_code
           JOIN curriculum_versions cv ON cv.id = cc.curriculum_version_id
           JOIN course_offerings co ON co.curriculum_course_id = cc.id
          WHERE (cl.program_code IS NULL OR cl.program_code = '' OR cl.program_code = cv.program_code)
            AND co.status IN ('planned', 'active')
          ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = CURRENT_TIMESTAMP"
    );
    $afterAssignments = seed_count($db, 'lecturer_course_assignments');
    echo "  lecturer_course_assignments: {$beforeAssignments} -> {$afterAssignments}\n";

    // ── Step B: resolve the deterministic demo assignment ────────────────
    echo "== Step B: resolve demo assignment ==\n";
    $demoSql =
        "SELECT lca.id AS assignment_id, co.id AS course_offering_id, cc.course_code, co.program_code,
                c.course_name, p.program_name, p.structure_type,
                ay.academic_year_name, ap.period_name, ap.start_date AS period_start, ap.end_date AS period_end
           FROM lecturer_course_assignments lca
           JOIN course_offerings co ON co.id = lca.course_offering_id
           JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
           JOIN courses c ON c.course_code = cc.course_code
           JOIN programs p ON p.program_code = co.program_code
           LEFT JOIN academic_years ay ON ay.id = co.academic_year_id
           LEFT JOIN academic_periods ap ON ap.id = co.academic_period_id";
    $stmt = $db->prepare($demoSql .
        " WHERE lca.staff_id = ? AND cc.course_code = ? AND lca.status = 'active' AND co.status IN ('planned','active')
          ORDER BY ap.start_date DESC
          LIMIT 1");
    $stmt->bind_param('ss', $seedActor, $seedCourse);
    $stmt->execute();
    $demo = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
if (!$demo) {
        // Fallback: first active assignment for the seed lecturer (useful if the
        // targeted DCSE-101 row is ever removed).
        $rows = (new TeachingPlannerService($db))->lecturerAssignments(SEED_ACTOR);
        if ($rows === []) {
            throw new RuntimeException('No teaching assignments exist after backfill for ' . SEED_ACTOR . '. Refusing to continue.');
        }
        $first = $rows[0];
        $stmt = $db->prepare($demoSql . ' WHERE lca.id = ? LIMIT 1');
        $firstId = (int)$first['assignment_id'];
        $stmt->bind_param('i', $firstId);
        $stmt->execute();
        $demo = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }

    if (!$demo) {
        throw new RuntimeException('Could not resolve a demo assignment to provision.');
    }
    echo "  demo: {$demo['course_code']} / {$demo['program_name']} / {$demo['academic_year_name']} {$demo['period_name']}\n";

    // ── Step C: ensure a usable course_schedule row ──────────────────────
    echo "== Step C: course_schedule ==\n";
    $stmt = $db->prepare('SELECT id FROM staff WHERE staff_id = ? LIMIT 1');
    $stmt->bind_param('s', $seedActor);
    $stmt->execute();
    $staffNumeric = (int)(($stmt->get_result()->fetch_assoc() ?? [])['id'] ?? 0);
    $stmt->close();
    if ($staffNumeric <= 0) {
        throw new RuntimeException('Numeric staff id for ' . SEED_ACTOR . ' could not be resolved.');
    }

    $stmt = $db->prepare(
        "SELECT id FROM course_schedule
           WHERE course_code = ? AND lecturer_id = ? AND status = 'active' AND COALESCE(is_active,1) = 1
             AND start_time IS NOT NULL AND end_time IS NOT NULL
           ORDER BY day_of_week LIMIT 1"
    );
    $stmt->bind_param('si', $seedCourse, $staffNumeric);
    $stmt->execute();
    $scheduleId = (int)(($stmt->get_result()->fetch_assoc() ?? [])['id'] ?? 0);
    $stmt->close();

    if ($scheduleId <= 0) {
        $dayOfWeek = date('l', strtotime((string)$demo['period_start']));
        $academicYear = (int)$demo['academic_year_name'] ?: 2026;
        $stmt = $db->prepare(
            "INSERT INTO course_schedule (course_code, lecturer_id, day_of_week, start_time, end_time, academic_year, semester, status, schedule_type, recurrence, is_active, created_by)
             VALUES (?, ?, ?, '08:00:00', '10:00:00', ?, 2, 'active', 'lecture', 'weekly', 1, ?)"
        );
        $stmt->bind_param('sisii', $seedCourse, $staffNumeric, $dayOfWeek, $academicYear, $staffNumeric);
        $stmt->execute();
        $scheduleId = (int)$db->insert_id;
        $stmt->close();
        echo "  inserted schedule #{$scheduleId} ({$dayOfWeek} 08:00-10:00)\n";
    } else {
        echo "  already at id {$scheduleId}\n";
    }

    // ── Step D: active scheme-of-work template + v1 ─────────────────────────
    echo "== Step D: template ==\n";
    $fixture = __DIR__ . '/../tests/teaching_planner/fixtures/sample_scheme_template.docx';
    if (! is_file($fixture)) {
        throw new RuntimeException('Missing template fixture: ' . $fixture);
    }
    $stmt = $db->prepare(
        "SELECT tv.id FROM document_template_versions tv JOIN document_templates t ON t.id = tv.template_id
            WHERE t.name = ? AND tv.version_number = 1
            LIMIT 1"
    );
    $stmt->bind_param('s', $seedTemplateName);
    $stmt->execute();
    $templateVersionId = (int)(($stmt->get_result()->fetch_assoc() ?? [])['id'] ?? 0);
    $stmt->close();

    if ($templateVersionId <= 0) {
        $report = (new TeachingPlannerTemplateValidator())->validate($fixture, 'scheme_of_work');
        if (empty($report['valid'])) {
            throw new RuntimeException('The installed template fixture is not a valid scheme_of_work: ' . tp_json($report));
        }
        $stmt = $db->prepare(
            "INSERT INTO document_templates (name, document_type, department_id, program_type, structure_type, status, created_by)
             VALUES (?, 'scheme_of_work', NULL, NULL, 'term', 'active', ?)"
        );
        $stmt->bind_param('ss', $seedTemplateName, $seedActor);
        $stmt->execute();
        $templateId = (int)$db->insert_id;
        $stmt->close();

        $checksum = hash_file('sha256', $fixture);
        if ($checksum === false) {
            throw new RuntimeException('Could not hash the template fixture.');
        }
        $root = tp_storage_root();
        $relative = 'templates/' . date('Y') . '/' . $templateId . '/v1-' . substr($checksum, 0, 16) . '.docx';
        $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0770, true);
        }
        if (!copy($fixture, $target)) {
            throw new RuntimeException('Could not copy the template fixture into protected storage.');
        }
        $reportJson = tp_json($report);
        $stmt = $db->prepare(
            "INSERT INTO document_template_versions (template_id, version_number, effective_date, status, original_filename, storage_path, mime_type, file_size, checksum_sha256, validation_report, uploaded_by, activated_at)
             VALUES (?, 1, CURDATE(), 'active', 'sample_scheme_template.docx', ?, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ?, ?, ?, ?, NOW())"
        );
        $size = (int)filesize($target);
        $stmt->bind_param('isisss', $templateId, $relative, $size, $checksum, $reportJson, $seedActor);
        $stmt->execute();
        $templateVersionId = (int)$db->insert_id;
        $stmt->close();
        echo "  registered template v{$templateVersionId}\n";
    } else {
        echo "  template v{$templateVersionId} already active\n";
    }
// ── Step E: approved syllabus + topics ────────────────────────────────
    echo "== Step E: approved syllabus ==\n";
    $stmt = $db->prepare(
        'SELECT id FROM syllabus_versions WHERE program_code = ? AND course_code = ? AND version_label = ? LIMIT 1'
    );
    $stmt->bind_param('sss', $seedProgram, $seedCourse, $seedSyllabusLabel);
    $stmt->execute();
    $syllabusId = (int)(($stmt->get_result()->fetch_assoc() ?? [])['id'] ?? 0);
    $stmt->close();

    if ($syllabusId <= 0) {
        $stmt = $db->prepare(
            "INSERT INTO syllabus_versions (program_code, course_code, version_label, credits, total_recommended_hours, assessment_criteria, resources, source_type, status, created_by, approved_by, approved_at)
             VALUES (?, ?, ?, 5.00, 12.00, 'Formative questioning and task observation', 'ITC computing labs', 'manual', 'approved', ?, ?, NOW())"
        );
        $stmt->bind_param('sssss', $seedProgram, $seedCourse, $seedSyllabusLabel, $seedActor, $seedActor);
        $stmt->execute();
        $syllabusId = (int)$db->insert_id;
        $stmt->close();
        echo "  registered syllabus v{$syllabusId}\n";
    } else {
        echo "  syllabus v{$syllabusId} already approved\n";
    }

    // Ensure the syllabus has topics (re-run safe when a partial run left it bare).
    $r = $db->query("SELECT COUNT(*) c FROM syllabus_topics WHERE syllabus_version_id = {$syllabusId}");
    $topicCount = $r ? (int)$r->fetch_assoc()['c'] : 0;
    echo "  topics: {$topicCount}\n";
    if ($topicCount === 0) {
        $topics = [
            ['title' => 'Introduction to Computer Systems', 'hours' => 3.0, 'outcome' => 'Explain computer system components and their functions.'],
            ['title' => 'Operating Systems Fundamentals', 'hours' => 3.0, 'outcome' => 'Describe operating system roles, processes and file management.'],
            ['title' => 'Practical Applications and Review', 'hours' => 2.0, 'outcome' => 'Apply core concepts in guided practical exercises.'],
        ];
        $stmt = $db->prepare(
            'INSERT INTO syllabus_topics (syllabus_version_id, topic_title, recommended_hours, learning_outcomes, display_order) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($topics as $order => $topic) {
            $displayOrder = $order + 1;
            $stmt->bind_param('isdsi', $syllabusId, $topic['title'], $topic['hours'], $topic['outcome'], $displayOrder);
            $stmt->execute();
        }
        $stmt->close();
        echo '  inserted ' . count($topics) . " topics\n";
    }

    // ── Step F: generate a demonstration plan (only if none exists) ──────
    echo "== Step F: demonstration plan ==\n";
    $offeringId = (int)$demo['course_offering_id'];
    $stmt = $db->prepare(
        "SELECT id FROM teaching_plans WHERE lecturer_staff_id = ? AND course_offering_id = ? AND status NOT IN ('archived') LIMIT 1"
    );
    $stmt->bind_param('si', $seedActor, $offeringId);
    $stmt->execute();
    $existingPlan = (int)(($stmt->get_result()->fetch_assoc() ?? [])['id'] ?? 0);
    $stmt->close();

    if ($existingPlan > 0) {
        echo "  plan #{$existingPlan} already exists\n";
    } else {
        $service = new TeachingPlannerService($db);
        $preview = $service->generatePreview(SEED_ACTOR, [
            'assignment_id' => (int)$demo['assignment_id'],
            'template_version_id' => $templateVersionId,
            'syllabus_version_id' => $syllabusId,
            'start_date' => (string)$demo['period_start'],
            'end_date' => (string)$demo['period_end'],
            'assessment_weeks' => '5,9',
            'revision_weeks' => '13',
            'generation_scope' => 'full_period',
        ]);
        $planId = $service->savePreview(SEED_ACTOR, $preview);
        echo "  generated plan #{$planId} (" . count($preview['schedule']['items']) . " items)\n";
    }

    seed_exit_success($db, [
        'lecturer_course_assignments' => $afterAssignments,
        'demo_offering_id' => $demo['course_offering_id'],
        'course_schedule_id' => $scheduleId,
        'template_version_id' => $templateVersionId,
        'syllabus_version_id' => $syllabusId,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "SEED ERROR: " . $e->getMessage() . "\n");
    exit(1);
}