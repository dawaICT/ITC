<?php
/**
 * Reconcile CA uploads with the normalized marks/results structure.
 *
 * Idempotent. It creates missing offering/registration bridges for active
 * legacy registrations, recalculates legacy Total_CA, backfills normalized
 * component marks/results, and installs integrity indexes/checks.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/ca_helpers.php';
require_once __DIR__ . '/../includes/grading_helpers.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$indexExists = static function (mysqli $db, string $table, string $index): bool {
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $found;
};

$constraintExists = static function (mysqli $db, string $table, string $constraint): bool {
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.table_constraints
          WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $constraint);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $found;
};

$bridged = 0;
$totalsUpdated = 0;
$testSlotsNormalized = 0;
$marksSynced = 0;
$resultsSynced = 0;

$db->begin_transaction();
try {
    // Some legacy student programmes predate curriculum-version assignment.
    // Attach the single active version before building course offerings.
    $db->query(
        "UPDATE student_program sp
          JOIN curriculum_versions cv ON cv.program_code = sp.program_code AND cv.status = 'active'
           SET sp.curriculum_version_id = cv.id,
               sp.updated_at = CURRENT_TIMESTAMP
         WHERE sp.curriculum_version_id IS NULL
           AND COALESCE(sp.status, 'active') NOT IN ('inactive','withdrawn','suspended')
           AND NOT EXISTS (
               SELECT 1 FROM curriculum_versions other
                WHERE other.program_code = sp.program_code
                  AND other.status = 'active'
                  AND other.id <> cv.id
           )"
    );

    // Backfill normalized curriculum rows only for programmes that currently
    // have active legacy registrations and no equivalent curriculum row.
    $db->query(
        "INSERT INTO curriculum_courses
            (curriculum_version_id, course_code, year_number, term_number, semester_number,
             level_number, is_core, is_full_year, is_period_specific, is_term_specific,
             is_semester_specific, delivery_period, display_order)
         SELECT cv.id,
                pc.course_code,
                pc.year,
                CASE WHEN p.structure_type = 'TERM_BASED' THEN pc.semester ELSE NULL END,
                CASE WHEN p.structure_type = 'SEMESTER_BASED' THEN pc.semester ELSE NULL END,
                CASE WHEN p.structure_type = 'TRADE_TEST_LEVEL' THEN pc.year ELSE NULL END,
                pc.is_required,
                pc.is_full_year,
                pc.is_period_specific,
                pc.is_term_specific,
                pc.is_semester_specific,
                COALESCE(NULLIF(pc.delivery_period, ''), CASE WHEN pc.is_full_year = 1 THEN 'full_year' ELSE NULL END),
                pc.id
           FROM curriculum_versions cv
           JOIN programs p ON p.program_code = cv.program_code
           JOIN program_courses pc ON pc.program_code = cv.program_code
          WHERE cv.status = 'active'
            AND EXISTS (
                SELECT 1
                  FROM student_program sp
                  JOIN course_registration cr ON cr.Sid = sp.Sid
                 WHERE sp.program_code = cv.program_code
                   AND cr.course_code = pc.course_code
                   AND (cr.is_active = 1 OR cr.status IN ('active','registered'))
            )
            AND NOT EXISTS (
                SELECT 1 FROM curriculum_courses cc
                 WHERE cc.curriculum_version_id = cv.id
                   AND cc.course_code = pc.course_code
            )"
    );

    // Curricula added after the foundation migration also need an assessment
    // scheme. Create only missing programme/course schemes; never overwrite a
    // configured active scheme.
    $missingSchemes = $db->query(
        "SELECT DISTINCT p.program_code, cc.course_code, p.structure_type
           FROM curriculum_versions cv
           JOIN programs p ON p.program_code = cv.program_code
           JOIN curriculum_courses cc ON cc.curriculum_version_id = cv.id
      LEFT JOIN assessment_schemes existing
             ON existing.program_code = p.program_code
            AND existing.course_code = cc.course_code
            AND existing.status = 'active'
          WHERE cv.status = 'active'
            AND existing.id IS NULL"
    );
    $insertScheme = $db->prepare(
        "INSERT INTO assessment_schemes
            (program_code, course_code, scheme_name, ca_weight, exam_weight, pass_mark, status)
         VALUES (?, ?, 'Default TEVETA Scheme', ?, ?, 50, 'active')"
    );
    $insertComponent = $db->prepare(
        'INSERT INTO assessment_components
            (assessment_scheme_id, component_name, component_type, weight, max_mark, display_order)
         VALUES (?, ?, ?, ?, 100, ?)'
    );
    while ($schemeRow = $missingSchemes->fetch_assoc()) {
        $structureType = (string)$schemeRow['structure_type'];
        $caWeight = in_array($structureType, ['TRADE_TEST_LEVEL','SHORT_COURSE'], true) ? 100.0 : 40.0;
        $examWeight = in_array($structureType, ['TRADE_TEST_LEVEL','SHORT_COURSE'], true) ? 0.0 : 60.0;
        $programCode = (string)$schemeRow['program_code'];
        $courseCode = (string)$schemeRow['course_code'];
        $insertScheme->bind_param('ssdd', $programCode, $courseCode, $caWeight, $examWeight);
        $insertScheme->execute();
        $schemeId = (int)$insertScheme->insert_id;

        $components = in_array($structureType, ['TRADE_TEST_LEVEL','SHORT_COURSE'], true)
            ? [['Practical Competency Assessment', 'PRACTICAL', 100.0, 10]]
            : [
                ['Assignment 1', 'CA', 10.0, 10],
                ['Assignment 2', 'CA', 10.0, 20],
                ['Assignment 3', 'CA', 0.0, 25],
                ['Practical Test', 'PRACTICAL', 20.0, 30],
                ['Test 1', 'CA', 0.0, 31],
                ['Test 2', 'CA', 0.0, 32],
                ['Final Exam', 'EXAM', 60.0, 40],
            ];
        foreach ($components as [$componentName, $componentType, $componentWeight, $displayOrder]) {
            $insertComponent->bind_param('issdi', $schemeId, $componentName, $componentType, $componentWeight, $displayOrder);
            $insertComponent->execute();
        }
    }
    $insertScheme->close();
    $insertComponent->close();

    $registrations = $db->query(
        "SELECT DISTINCT Sid, course_code, CAST(semester AS CHAR) AS period_value,
                CAST(academic_year AS CHAR) AS academic_year
           FROM course_registration
          WHERE (is_active = 1 OR status IN ('active','registered'))
            AND academic_year IS NOT NULL
            AND academic_year <> ''
          ORDER BY Sid, course_code"
    );
    while ($row = $registrations->fetch_assoc()) {
        $registrationId = ca_ensure_normalized_registration_bridge(
            $db,
            (string)$row['Sid'],
            (string)$row['course_code'],
            (string)$row['period_value'],
            (string)$row['academic_year']
        );
        if ($registrationId !== null) {
            $bridged++;
        }
    }

    $assessments = $db->query(
        'SELECT id, Sid, Course_Code, A1, A2, A3, T1, T2, Total_CA, semester, Year, posted_by
           FROM semester_assessment
          ORDER BY id'
    );
    $updateTotal = $db->prepare('UPDATE semester_assessment SET T1 = ?, T2 = ?, Total_CA = ? WHERE id = ?');
    while ($row = $assessments->fetch_assoc()) {
        $components = [];
        foreach (['A1','A2','A3','T1','T2'] as $component) {
            $components[$component] = $row[$component] === null ? null : (float)$row[$component];
        }
        $testSlotsChanged = false;
        if (ca_student_structure_type($db, (string)$row['Sid'], (string)$row['Course_Code']) === 'TERM_BASED') {
            $period = (string)$row['semester'];
            if ($period === '1') {
                if ($components['T1'] === null && $components['T2'] !== null) {
                    $components['T1'] = $components['T2'];
                }
                $testSlotsChanged = $components['T2'] !== null;
                $components['T2'] = null;
            } elseif ($period === '2') {
                if ($components['T2'] === null && $components['T1'] !== null) {
                    $components['T2'] = $components['T1'];
                }
                $testSlotsChanged = $components['T1'] !== null;
                $components['T1'] = null;
            } elseif ($period === '3') {
                $testSlotsChanged = $components['T1'] !== null || $components['T2'] !== null;
                $components['T1'] = null;
                $components['T2'] = null;
            }
        }
        $total = ca_calculate_course_total($db, (string)$row['Sid'], (string)$row['Course_Code'], $components);
        $stored = $row['Total_CA'] === null ? null : (float)$row['Total_CA'];
        if ($testSlotsChanged
            || ($stored === null) !== ($total === null)
            || ($stored !== null && $total !== null && abs($stored - $total) > 0.005)) {
            $id = (int)$row['id'];
            $updateTotal->bind_param('dddi', $components['T1'], $components['T2'], $total, $id);
            $updateTotal->execute();
            $totalsUpdated++;
            if ($testSlotsChanged) {
                $testSlotsNormalized++;
            }
        }

        $actor = trim((string)($row['posted_by'] ?? '')) ?: 'migration:20260804';
        $registrationIds = [];
        foreach ($components as $component => $mark) {
            if ($mark === null) {
                continue;
            }
            $registrationId = ca_sync_normalized_component(
                $db,
                (string)$row['Sid'],
                (string)$row['Course_Code'],
                (string)$row['semester'],
                (string)$row['Year'],
                $component,
                $mark,
                $actor
            );
            if ($registrationId !== null) {
                $registrationIds[$registrationId] = true;
                $marksSynced++;
            }
        }
        foreach (array_keys($registrationIds) as $registrationId) {
            ca_sync_normalized_result($db, (int)$registrationId, $total);
        }
        if (wuc_result_sync_normalized(
            $db,
            (string)$row['Sid'],
            (string)$row['Course_Code'],
            (string)$row['semester'],
            (string)$row['Year'],
            $actor
        ) !== null) {
            $resultsSynced++;
        }
    }
    $updateTotal->close();
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'CA data reconciliation failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

try {
    if (!$indexExists($db, 'assessment_components', 'uq_assessment_component_name')) {
        $db->query('ALTER TABLE assessment_components ADD UNIQUE KEY uq_assessment_component_name (assessment_scheme_id, component_name)');
    }
    if (!$indexExists($db, 'semester_assessment', 'idx_ca_course_period_status')) {
        $db->query('ALTER TABLE semester_assessment ADD KEY idx_ca_course_period_status (Course_Code, Year, semester, status)');
    }
    if ($indexExists($db, 'semester_assessment', 'uq_ca_student_course_period')
        && $indexExists($db, 'semester_assessment', 'uq_semester_assessment_student_course_period')) {
        $db->query('ALTER TABLE semester_assessment DROP INDEX uq_ca_student_course_period');
    }
    if (!$constraintExists($db, 'semester_assessment', 'chk_ca_component_ranges')) {
        $db->query("ALTER TABLE semester_assessment
            ADD CONSTRAINT chk_ca_component_ranges CHECK (
                (A1 IS NULL OR A1 BETWEEN 0 AND 100)
                AND (A2 IS NULL OR A2 BETWEEN 0 AND 100)
                AND (A3 IS NULL OR A3 BETWEEN 0 AND 100)
                AND (T1 IS NULL OR T1 BETWEEN 0 AND 100)
                AND (T2 IS NULL OR T2 BETWEEN 0 AND 100)
                AND (Exam IS NULL OR Exam BETWEEN 0 AND 100)
                AND (Total_CA IS NULL OR Total_CA BETWEEN 0 AND 100)
            )");
    }
    if (!$constraintExists($db, 'semester_assessment', 'chk_ca_workflow_status')) {
        $db->query("ALTER TABLE semester_assessment
            ADD CONSTRAINT chk_ca_workflow_status CHECK (
                status IN ('Pending','Draft','Submitted','Approved','Published','Rejected')
            )");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'CA constraint installation failed after data reconciliation: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "CA data integrity migration complete.\n";
echo "Normalized registration bridges: {$bridged}\n";
echo "Legacy totals recalculated: {$totalsUpdated}\n";
echo "Term test slots normalized: {$testSlotsNormalized}\n";
echo "Normalized component marks synced: {$marksSynced}\n";
echo "Normalized course results synced: {$resultsSynced}\n";
