<?php
// JSON API: Academic history for a student; returns latest attempts, failed courses, GPA approximation

declare(strict_types=1);
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/includes/EligibilityService.php';

$sid = wuc_api_require_student();
try {
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : null;
$year = isset($_GET['Year']) ? (int)$_GET['Year'] : null;

$latest = EligibilityService::getLatestCourseAttempts($db, $sid);
$failed = array_values(array_filter($latest, fn($r) => !empty($r['is_failed'])));
$flags = EligibilityService::computeFailureFlags(count($failed));

// GPA approximation using transcript scale mapping to points; simple average of mapped points
function letterToPoints(string $L): float {
    switch ($L) {
        case 'A+': return 4.00; case 'A': return 3.74; case 'B+': return 3.64; case 'B': return 3.49;
        case 'B-': return 3.25; case 'C+': return 2.99; case 'C': return 2.33; case 'D': return 1.99; default: return 1.00;
    }
}
$points = []; foreach ($latest as $att) { $points[] = letterToPoints((string)$att['grade_letter']); }
$gpa = empty($points) ? null : round(array_sum($points) / count($points), 2);

EligibilityService::audit($db, $sid, 'academic_history_view', [ 'semester' => $semester, 'year' => $year, 'failed_count' => count($failed) ]);

wuc_json_response([
    'success' => true,
    'data' => [
        'student_id' => $sid,
        'latest_attempts' => array_values($latest),
        'failed_courses' => $failed,
        'flags' => $flags,
        'gpa' => $gpa,
    ]
]);
} catch (Throwable $e) {
    error_log('Academic history API failed: ' . $e->getMessage());
    wuc_json_error('Unable to load academic history.', 500);
}


