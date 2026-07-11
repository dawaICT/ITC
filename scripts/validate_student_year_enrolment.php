<?php
/**
 * Validate academic-year course enrolment for a test student.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/validate_student_year_enrolment.php
 *   C:\xampp\php\php.exe scripts/validate_student_year_enrolment.php --student=CSE26456789
 *   C:\xampp\php\php.exe scripts/validate_student_year_enrolment.php --apply --skip-teveta
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/helpers/academic_period_helpers.php';
require_once __DIR__ . '/../students/includes/period_mode_helper.php';
require_once __DIR__ . '/../students/includes/StudentAcademicWorkflowService.php';
require_once __DIR__ . '/../students/includes/RegistrationDataService.php';

$studentId = 'CSE26456789';
$apply = in_array('--apply', $argv ?? [], true);
$skipTeveta = in_array('--skip-teveta', $argv ?? [], true);
$devBackfill = in_array('--dev-backfill', $argv ?? [], true);

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--student=')) {
        $studentId = trim(substr($arg, 10));
    }
}

echo ($apply ? 'APPLY' : 'DRY-RUN') . ": validate year enrolment for {$studentId}\n\n";

$progRow = null;
if ($stmt = $db->prepare(
    'SELECT program_code, year_of_study, academic_year, semester
       FROM student_program
      WHERE Sid = ? AND LOWER(COALESCE(status, \'active\')) = \'active\'
      ORDER BY id DESC LIMIT 1'
)) {
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $progRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$progRow) {
    echo "No active student_program row for {$studentId}.\n";
    exit(1);
}

$programCode = (string)$progRow['program_code'];
$yearOfStudy = (int)($progRow['year_of_study'] ?: 1);
$catalogue = getCoursesForProgramYearOfStudy($db, $programCode, $yearOfStudy);
$catalogueCodes = array_values(array_filter(array_map(
    static fn($r) => trim((string)($r['course_code'] ?? '')),
    $catalogue
)));

echo "Program: {$programCode}, year of study: {$yearOfStudy}\n";
echo 'Year catalogue count: ' . count($catalogueCodes) . "\n";
if ($catalogueCodes !== []) {
    echo '  ' . implode(', ', $catalogueCodes) . "\n";
}

$regService = new RegistrationDataService($db);
$enrolledYear = $regService->getRegisteredCourses($studentId, $yearOfStudy, 1, null, null, 'year');
$enrolledCodes = array_map(static fn($r) => strtoupper(trim((string)($r['course_code'] ?? ''))), $enrolledYear);

echo "\nEnrolled (year scope): " . count($enrolledCodes) . "\n";
$missing = array_diff(array_map('strtoupper', $catalogueCodes), $enrolledCodes);
if ($missing !== []) {
    echo 'Missing from enrolment: ' . implode(', ', $missing) . "\n";
} else {
    echo "All catalogue courses enrolled.\n";
}

$workflow = new StudentAcademicWorkflowService($db);
$period = $workflow->getActiveAcademicPeriod($studentId);
echo "\nActive period: " . ($period['ok'] ? ($period['period_label'] ?? 'ok') : ($period['error'] ?? 'unknown')) . "\n";

$semReg = null;
if ($stmt = $db->prepare(
    'SELECT id FROM semester_registration WHERE student_id = ? ORDER BY id DESC LIMIT 1'
)) {
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $semReg = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
echo 'semester_registration: ' . ($semReg ? 'id=' . $semReg['id'] : 'none') . "\n";

if (!$apply) {
    echo "\nUse --apply to register (requires fee eligibility) or backfill missing courses.\n";
    echo "  --apply --skip-teveta      skip TEVETA validation on new period registration\n";
    echo "  --apply --dev-backfill     CLI only: create semester_registration + year enrol without fee gate\n";
    exit($missing === [] && $semReg ? 0 : 1);
}

if (!$semReg && $devBackfill) {
    if (empty($period['ok'])) {
        echo "\nCannot dev-backfill: no active academic period.\n";
        exit(1);
    }
    $regData = new RegistrationDataService($db);
    $semRegId = $regData->createRegistration([
        'student_id' => $studentId,
        'academic_year' => (string)($period['academic_year'] ?? date('Y')),
        'semester' => (string)($period['period_number'] ?? 1),
        'year_of_study' => $yearOfStudy,
        'program_code' => $programCode,
        'registration_date' => date('Y-m-d H:i:s'),
        'registration_type' => 'Regular',
        'period_type' => wuc_legacy_period_type((string)($period['calendar_type'] ?? 'term')),
    ]);
    echo "\ndev-backfill: created semester_registration id={$semRegId}\n";
    $added = $workflow->ensureYearCoursesEnrolled($studentId, $period);
    echo "ensureYearCoursesEnrolled added: {$added}\n";
} elseif (!$semReg) {
    $result = $workflow->registerStudentForPeriod($studentId, [
        'skip_teveta' => $skipTeveta,
        'registration_type' => 'Regular',
    ]);
    echo "\nregisterStudentForPeriod: " . ($result['ok'] ? 'OK' : 'FAILED') . "\n";
    echo ($result['message'] ?? '') . "\n";
    if (!$result['ok']) {
        exit(1);
    }
} else {
    $added = $workflow->ensureYearCoursesEnrolled($studentId);
    echo "\nensureYearCoursesEnrolled added: {$added}\n";
}

$enrolledAfter = $regService->getRegisteredCourses($studentId, $yearOfStudy, 1, null, null, 'year');
$afterCodes = array_map(static fn($r) => strtoupper(trim((string)($r['course_code'] ?? ''))), $enrolledAfter);
$stillMissing = array_diff(array_map('strtoupper', $catalogueCodes), $afterCodes);

echo "\nAfter apply — enrolled: " . count($afterCodes) . ", missing: " . count($stillMissing) . "\n";
if ($stillMissing !== []) {
    echo 'Still missing: ' . implode(', ', $stillMissing) . "\n";
    exit(1);
}

foreach ([1, 2, 3] as $term) {
    $filtered = getCoursesForProgramYear($db, $programCode, $yearOfStudy, $term);
    echo "Term {$term} filtered catalogue: " . count($filtered) . "\n";
}

echo "\nValidation passed.\n";
exit(0);
