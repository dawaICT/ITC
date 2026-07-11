<?php
/**
 * Quality Assurance - Course & Lecturer Evaluations
 */

require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/RegistrationDataService.php';

$studentId = $_SESSION['Sid'] ?? '';

$registrationService = new RegistrationDataService($db);
$currentRegistration = $registrationService->getLatestSemesterRegistration($studentId);

$registeredCourses = [];
if ($currentRegistration) {
    $registeredCourses = $registrationService->getRegisteredCourses(
        $studentId,
        (int)($currentRegistration['year_of_study'] ?? 0),
        (int)($currentRegistration['semester'] ?? 0),
        isset($currentRegistration['id']) ? (int)$currentRegistration['id'] : null,
        isset($currentRegistration['academic_year']) ? (string)$currentRegistration['academic_year'] : null
    );
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_eval') {
    // CSRF Check
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Invalid security token. Refresh and try again.';
    } else {
        $courseCode = trim($_POST['course_code'] ?? '');
        $lecturerId = trim($_POST['lecturer_id'] ?? 'unknown');
        
        $rateLecturer = (int)($_POST['rating_lecturer'] ?? 0);
        $rateContent = (int)($_POST['rating_content'] ?? 0);
        $rateFacilities = (int)($_POST['rating_facilities'] ?? 0);
        $rateServices = (int)($_POST['rating_services'] ?? 0);
        $comments = trim($_POST['comments'] ?? '');

        // Validation checks
        $isValidCourse = false;
        foreach ($registeredCourses as $c) {
            if ($c['course_code'] === $courseCode) {
                $isValidCourse = true;
                break;
            }
        }

        if (!$isValidCourse) {
            $error = 'You can only evaluate active registered courses.';
        } elseif ($rateLecturer < 1 || $rateLecturer > 5 || $rateContent < 1 || $rateContent > 5 || $rateFacilities < 1 || $rateFacilities > 5 || $rateServices < 1 || $rateServices > 5) {
            $error = 'Please provide ratings between 1 and 5 for all categories.';
        } else {
            // Check if already evaluated
            $stmtCheck = $db->prepare("SELECT 1 FROM student_evaluated_courses WHERE student_id = ? AND course_code = ?");
            $stmtCheck->bind_param('ss', $studentId, $courseCode);
            $stmtCheck->execute();
            if ($stmtCheck->get_result()->fetch_assoc()) {
                $error = 'You have already evaluated this course.';
            }
            $stmtCheck->close();
        }

        if (empty($error)) {
            // Begin Transaction for atomic anonymous submission
            $db->begin_transaction();
            try {
                // 1. Insert into student_evaluated_courses (student ID, for single submission check)
                $stmtMap = $db->prepare("INSERT INTO student_evaluated_courses (student_id, course_code) VALUES (?, ?)");
                $stmtMap->bind_param('ss', $studentId, $courseCode);
                $stmtMap->execute();
                $stmtMap->close();

                // 2. Insert into course_evaluations (ANONYMOUS - no student ID!)
                $stmtEval = $db->prepare("INSERT INTO course_evaluations (course_code, lecturer_id, rating_lecturer, rating_content, rating_facilities, rating_services, comments) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmtEval->bind_param('ssiiiis', $courseCode, $lecturerId, $rateLecturer, $rateContent, $rateFacilities, $rateServices, $comments);
                $stmtEval->execute();
                $stmtEval->close();

                $db->commit();
                $message = 'Thank you! Your anonymous evaluation was submitted successfully.';
            } catch (Exception $e) {
                $db->rollback();
                $error = 'Failed to submit evaluation: ' . $e->getMessage();
            }
        }
    }
}

// Hydrate evaluation states for student
$evaluatedMap = [];
if (!empty($registeredCourses)) {
    $stmtMapList = $db->prepare("SELECT course_code FROM student_evaluated_courses WHERE student_id = ?");
    $stmtMapList->bind_param('s', $studentId);
    $stmtMapList->execute();
    $resMap = $stmtMapList->get_result();
    while ($row = $resMap->fetch_assoc()) {
        $evaluatedMap[$row['course_code']] = true;
    }
    $stmtMapList->close();
}

$page_title = 'Course Evaluations';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title . ' - ITC', ENT_QUOTES, 'UTF-8') ?></title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="content-wrapper pt-3 pb-5">
    <div class="container-fluid px-3 px-lg-4 portal-dashboard">
        
        <div class="dashboard-header student-section mb-4">
            <h1 class="dashboard-title"><i class="fas fa-clipboard-list me-2 text-purple"></i>Course Evaluations</h1>
            <p class="text-muted mb-0">Help us maintain high academic standards. All course evaluations are strictly anonymous.</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="mb-0 fw-bold text-purple"><i class="fas fa-book me-2"></i>Your Registered Courses</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($registeredCourses)): ?>
                            <div class="p-4 text-center text-muted">
                                <i class="fas fa-folder-open fa-3x mb-3 text-light"></i>
                                <p class="mb-0">No active course registrations found for evaluation.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Course Code</th>
                                            <th>Course Name</th>
                                            <th>Assigned Lecturer</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($registeredCourses as $course): 
                                            $cCode = $course['course_code'];
                                            $isEvaluated = isset($evaluatedMap[$cCode]);
                                            
                                            // Get Lecturer
                                            $lecturerName = 'Unassigned';
                                            $lecturerId = 'unknown';
                                            $stmtL = $db->prepare("
                                                SELECT cl.staff_id, s.Fname, s.Lname 
                                                FROM course_lecturer cl 
                                                INNER JOIN staff s ON cl.staff_id = s.staff_id 
                                                WHERE cl.course_code = ? AND cl.status = 'active' 
                                                LIMIT 1
                                            ");
                                            if ($stmtL) {
                                                $stmtL->bind_param('s', $cCode);
                                                $stmtL->execute();
                                                $lRes = $stmtL->get_result()->fetch_assoc();
                                                if ($lRes) {
                                                    $lecturerName = $lRes['Fname'] . ' ' . $lRes['Lname'];
                                                    $lecturerId = $lRes['staff_id'];
                                                }
                                                $stmtL->close();
                                            }
                                        ?>
                                            <tr>
                                                <td><code><?= htmlspecialchars($cCode) ?></code></td>
                                                <td><strong><?= htmlspecialchars($course['course_name'] ?? '') ?></strong></td>
                                                <td><?= htmlspecialchars($lecturerName) ?></td>
                                                <td class="text-center">
                                                    <?php if ($isEvaluated): ?>
                                                        <span class="badge bg-success-soft text-success"><i class="fas fa-check me-1"></i>Completed</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning-soft text-warning"><i class="fas fa-clock me-1"></i>Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($isEvaluated): ?>
                                                        <button class="btn btn-sm btn-outline-secondary" disabled>Submitted</button>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-purple" data-bs-toggle="modal" data-bs-target="#evalModal<?= htmlspecialchars($cCode) ?>"><i class="fas fa-star me-1"></i>Evaluate</button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>

                                            <!-- Evaluation Modal for <?= htmlspecialchars($cCode) ?> -->
                                            <?php if (!$isEvaluated): ?>
                                                <div class="modal fade" id="evalModal<?= htmlspecialchars($cCode) ?>" tabindex="-1">
                                                    <div class="modal-dialog modal-dialog-centered">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title fw-bold text-purple"><i class="fas fa-star me-2"></i>Evaluate <?= htmlspecialchars($cCode) ?></h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <form action="" method="post">
                                                                <input type="hidden" name="action" value="submit_eval">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                                                <input type="hidden" name="course_code" value="<?= htmlspecialchars($cCode) ?>">
                                                                <input type="hidden" name="lecturer_id" value="<?= htmlspecialchars($lecturerId) ?>">
                                                                
                                                                <div class="modal-body">
                                                                    <p class="text-muted small mb-3">Rate each category from 1 (Poor) to 5 (Excellent).</p>

                                                                    <!-- Lecturer Quality -->
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-semibold">Lecturer Performance</label>
                                                                        <div class="d-flex justify-content-between">
                                                                            <?php for ($r = 1; $r <= 5; $r++): ?>
                                                                                <div class="form-check form-check-inline">
                                                                                    <input class="form-check-input" type="radio" name="rating_lecturer" id="rl_<?= $cCode ?>_<?= $r ?>" value="<?= $r ?>" required>
                                                                                    <label class="form-check-label" for="rl_<?= $cCode ?>_<?= $r ?>"><?= $r ?></label>
                                                                                </div>
                                                                            <?php endfor; ?>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Course Content -->
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-semibold">Course Content &amp; Notes</label>
                                                                        <div class="d-flex justify-content-between">
                                                                            <?php for ($r = 1; $r <= 5; $r++): ?>
                                                                                <div class="form-check form-check-inline">
                                                                                    <input class="form-check-input" type="radio" name="rating_content" id="rc_<?= $cCode ?>_<?= $r ?>" value="<?= $r ?>" required>
                                                                                    <label class="form-check-label" for="rc_<?= $cCode ?>_<?= $r ?>"><?= $r ?></label>
                                                                                </div>
                                                                            <?php endfor; ?>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Facilities -->
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-semibold">Campus &amp; Lab Facilities</label>
                                                                        <div class="d-flex justify-content-between">
                                                                            <?php for ($r = 1; $r <= 5; $r++): ?>
                                                                                <div class="form-check form-check-inline">
                                                                                    <input class="form-check-input" type="radio" name="rating_facilities" id="rf_<?= $cCode ?>_<?= $r ?>" value="<?= $r ?>" required>
                                                                                    <label class="form-check-label" for="rf_<?= $cCode ?>_<?= $r ?>"><?= $r ?></label>
                                                                                </div>
                                                                            <?php endfor; ?>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Support Services -->
                                                                    <div class="mb-3">
                                                                        <label class="form-label fw-semibold">Student Support &amp; Services</label>
                                                                        <div class="d-flex justify-content-between">
                                                                            <?php for ($r = 1; $r <= 5; $r++): ?>
                                                                                <div class="form-check form-check-inline">
                                                                                    <input class="form-check-input" type="radio" name="rating_services" id="rs_<?= $cCode ?>_<?= $r ?>" value="<?= $r ?>" required>
                                                                                    <label class="form-check-label" for="rs_<?= $cCode ?>_<?= $r ?>"><?= $r ?></label>
                                                                                </div>
                                                                            <?php endfor; ?>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Open Comments -->
                                                                    <div class="mb-2">
                                                                        <label class="form-label fw-semibold">Suggestions or General Comments</label>
                                                                        <textarea name="comments" class="form-control" rows="3" placeholder="Provide any constructive feedback..."></textarea>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" class="btn btn-purple btn-sm"><i class="fas fa-paper-plane me-1"></i>Submit Evaluation</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
