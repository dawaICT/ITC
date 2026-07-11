<?php
declare(strict_types=1);

$page_title = 'Course Enrolment';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/RegistrationDataService.php';
require_once __DIR__ . '/includes/AcademicSessionService.php';
require_once __DIR__ . '/includes/period_mode_helper.php';
require_once dirname(__DIR__) . '/includes/course_recommendation_engine.php';

function course_reg_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$sid = (string)($_SESSION['Sid'] ?? '');
$regData = new RegistrationDataService($db);
$sessionService = new AcademicSessionService($db);

$semRegId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : null;

$periodMode = getStudentProgramPeriodMode($db, $sid);
$normalizedMode = normalizeProgramPeriodMode($periodMode !== '' ? $periodMode : 'semester');
$periodLabel = wuc_period_label_from_structure($normalizedMode);

$session = $sessionService->getCurrentSession(
    in_array($normalizedMode, ['term', 'semester'], true) ? $normalizedMode : null
);
$currentAcademicYear = trim((string)($session['academic_year'] ?? ''));
$currentPeriod = (int)($session['period_number'] ?? $session['semester_term'] ?? $session['semester'] ?? 0);

$termContext = $regData->resolveRegistrationTermContext(
    $sid,
    $semRegId,
    $currentAcademicYear !== '' ? $currentAcademicYear : null,
    $currentPeriod > 0 ? $currentPeriod : null,
    $periodMode !== '' ? $periodMode : null,
    true
);

$selectable = $regData->getSelectableCoursesForTerm($sid, $termContext);
$context = $selectable['context'] ?? $termContext;
$hasPeriodRegistration = $context !== null && (int)($context['semester'] ?? 0) > 0;

if ($hasPeriodRegistration && $sid !== '') {
    require_once __DIR__ . '/includes/StudentAcademicWorkflowService.php';
    $wf = new StudentAcademicWorkflowService($db);
    $wf->ensureYearCoursesEnrolled($sid);
    $selectable = $regData->getSelectableCoursesForTerm($sid, $termContext);
    $context = $selectable['context'] ?? $termContext;
    $hasPeriodRegistration = $context !== null && (int)($context['semester'] ?? 0) > 0;
}
$courses = $selectable['courses'] ?? [];
$alreadyRegistered = !empty($selectable['already_registered']);
$programMissing = !empty($selectable['program_missing']);

$periodLabelFull = '';
$yearOfStudy = 1;
$programCode = '';
if ($context) {
    $yearOfStudy = (int)($context['year_of_study'] ?? 1);
    $periodNum = (int)($context['semester'] ?? 1);
    $programCode = trim((string)($context['program_code'] ?? ''));
    $ay = trim((string)($context['academic_year'] ?? $currentAcademicYear));
    $periodLabelFull = $periodLabel . ' ' . $periodNum . ($ay !== '' ? ' · ' . $ay : '') . ' · Year ' . $yearOfStudy;
}

$totalCredits = 0;
$enrolledCount = 0;
foreach ($courses as $course) {
    if (!empty($course['is_enrolled'])) {
        $enrolledCount++;
        $totalCredits += (int)($course['credit_hours'] ?? $course['credits'] ?? 3);
    }
}
$catalogCount = count($courses);

$courseRecs = null;
if ($sid !== '') {
    try {
        $courseRecs = wuc_course_recommendations($db, $sid);
        if ($courses) {
            $selectableCodes = array_flip(array_map(
                static fn(array $row): string => (string)($row['course_code'] ?? ''),
                $courses
            ));
            $filter = static fn(array $items) => array_values(array_filter(
                $items,
                static fn(array $item): bool => isset($selectableCodes[(string)($item['course_code'] ?? '')])
            ));
            $courseRecs['missing'] = $filter($courseRecs['missing'] ?? []);
            $courseRecs['retake'] = $filter($courseRecs['retake'] ?? []);
            $courseRecs = $regData->applySelectableCourseNamesToRecommendations($courseRecs, $selectable);
        }
    } catch (Throwable $e) {
        error_log('students/courseReg.php recommendations: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= course_reg_h($page_title . ' - ITC') ?></title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="../css/consistent-styles.css">
</head>
<body>
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="content-wrapper pt-3 pb-5">
    <div class="container-fluid px-3 px-lg-4 portal-dashboard">
        <div class="dashboard-header student-section mb-4">
            <div class="row align-items-center g-3">
                <div class="col">
                    <h1 class="dashboard-title">Course Enrolment</h1>
                    <p class="text-muted mb-0">
                        Programme courses for your academic year. <?= course_reg_h($periodLabel) ?> is shown for context only — courses stay enrolled across the full year.
                    </p>
                </div>
                <div class="col-auto">
                    <a href="registration.php" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left me-1"></i> Registration
                    </a>
                </div>
            </div>
        </div>

        <?php if (!$hasPeriodRegistration): ?>
            <div class="alert alert-warning d-flex align-items-start gap-2">
                <i class="fas fa-exclamation-circle mt-1"></i>
                <div>
                    <strong>No active period registration.</strong>
                    Complete <?= course_reg_h(strtolower($periodLabel)) ?> registration first — courses are auto-enrolled when you register.
                    <div class="mt-2">
                        <a href="registration.php" class="btn btn-sm btn-primary">Go to Registration</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info d-flex align-items-start gap-2 mb-4">
                <i class="fas fa-circle-info mt-1"></i>
                <div>
                    Courses are registered automatically when you complete period registration.
                    Contact the registrar if you need to add or drop a course.
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="data-table-card h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase fw-semibold mb-1">Current period</div>
                            <div class="fw-semibold"><?= course_reg_h($periodLabelFull) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="data-table-card h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase fw-semibold mb-1">Programme</div>
                            <div class="fw-semibold"><?= course_reg_h($programCode !== '' ? $programCode : 'Not set') ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="data-table-card h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase fw-semibold mb-1">Year catalogue</div>
                            <div class="fw-semibold"><?= (int)$enrolledCount ?> enrolled / <?= (int)$catalogCount ?> in curriculum · <?= (int)$totalCredits ?> credits</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($programMissing): ?>
                <div class="alert alert-danger">Your programme assignment could not be verified. Contact the registrar.</div>
            <?php elseif ($courses === []): ?>
                <div class="alert alert-warning">
                    No courses are configured for Year <?= (int)$yearOfStudy ?> of programme
                    <?= course_reg_h($programCode) ?>.
                    <?php if (!$alreadyRegistered): ?>
                        They will appear here after period registration auto-enrols your year courses.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <section class="data-table-card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-book me-2"></i>Academic year courses</h5>
                        <span class="badge bg-primary"><?= (int)$enrolledCount ?> / <?= (int)$catalogCount ?> enrolled</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Code</th>
                                    <th scope="col">Course name</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="text-end">Credits</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($courses as $course): ?>
                                <tr class="<?= !empty($course['is_enrolled']) ? '' : 'table-light' ?>">
                                    <td class="fw-semibold"><?= course_reg_h((string)($course['course_code'] ?? '')) ?></td>
                                    <td><?= course_reg_h((string)($course['course_name'] ?? '')) ?></td>
                                    <td>
                                        <?php if (!empty($course['is_enrolled'])): ?>
                                            <span class="badge bg-success">Enrolled</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= (int)($course['credit_hours'] ?? $course['credits'] ?? 3) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <a href="myCourses.php" class="btn btn-primary"><i class="fas fa-book-open me-1"></i> My Courses</a>
                    <a href="continuousAssessment.php" class="btn btn-outline-primary"><i class="fas fa-chart-line me-1"></i> Continuous Assessment</a>
                    <a href="timetable.php" class="btn btn-outline-primary"><i class="fas fa-calendar-alt me-1"></i> Timetable</a>
                    <a href="ai_course_advisor.php" class="btn btn-outline-secondary"><i class="fas fa-wand-magic-sparkles me-1"></i> AI Advisor</a>
                </div>
            <?php endif; ?>

            <?php if (is_array($courseRecs)): ?>
                <?= wuc_course_recommendations_render_card($courseRecs, false) ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
