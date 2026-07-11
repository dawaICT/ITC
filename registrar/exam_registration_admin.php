<?php
require_once "includes/admin.php";
require_once __DIR__ . '/../students/includes/exam_helpers.php';
require_once __DIR__ . '/../includes/page_meta.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function erx_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$flash = ['type' => '', 'text' => ''];
$searchSid = trim((string)($_GET['sid'] ?? $_GET['SID'] ?? ''));

// --- Handle exam registration submission (on behalf of a student) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_exam'])) {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $flash = ['type' => 'danger', 'text' => 'Request verification failed. Please refresh and try again.'];
    } else {
        $sid = trim((string)($_POST['sid'] ?? ''));
        $courseCode = trim((string)($_POST['course_code'] ?? ''));
        $semester = (int)($_POST['semester'] ?? 0);
        $yearOfStudy = (int)($_POST['year_of_study'] ?? 0);
        if ($sid === '' || $courseCode === '' || $semester <= 0 || $yearOfStudy <= 0) {
            $flash = ['type' => 'danger', 'text' => 'Missing student, course, or academic period details.'];
        } else {
            $result = student_exam_register_courses($db, $sid, [$courseCode], $semester, $yearOfStudy);
            $flash = [
                'type' => !empty($result['ok']) ? 'success' : 'danger',
                'text' => (string)($result['message'] ?? (!empty($result['ok']) ? 'Exam registration recorded.' : 'Exam registration failed.')),
            ];
        }
        $searchSid = $sid !== '' ? $sid : $searchSid;
    }
}

// --- Load student + registered courses for display ---
$student = null;
$courses = [];
$examRegistered = [];
if ($searchSid !== '') {
    if ($stmt = $db->prepare("SELECT SID, title, Fname, Lname, nrc_pass, program FROM students WHERE SID = ? LIMIT 1")) {
        $stmt->bind_param('s', $searchSid);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_object();
        $stmt->close();
    }
    if ($student) {
        if ($stmt = $db->prepare("SELECT cr.course_code, c.course_name, cr.semester, cr.Year, cr.academic_year
                                   FROM course_registration cr
                                   LEFT JOIN courses c ON c.course_code = cr.course_code
                                   WHERE cr.Sid = ? AND COALESCE(cr.is_active, 1) = 1
                                   ORDER BY cr.Year, cr.semester, cr.course_code")) {
            $stmt->bind_param('s', $searchSid);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) { $courses[] = $row; }
            $stmt->close();
        }
        if ($stmt = $db->prepare("SELECT course_code, semester, `Year` FROM exam_registration WHERE Sid = ?")) {
            $stmt->bind_param('s', $searchSid);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $examRegistered[$row['course_code'] . '|' . (int)$row['semester'] . '|' . (int)$row['Year']] = true;
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Exam Registration (Admin)</title>
    <?php wuc_portal_favicon_links(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width: 900px;">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h3 class="mb-0"><i class="fa-solid fa-file-pen me-2"></i>Student Exam Registration</h3>
        <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
    </div>

    <?php if ($flash['text'] !== ''): ?>
        <div class="alert alert-<?php echo erx_h($flash['type'] ?: 'info'); ?>"><?php echo erx_h($flash['text']); ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-2">
                <div class="col-auto flex-grow-1">
                    <input type="text" class="form-control" name="sid" value="<?php echo erx_h($searchSid); ?>" placeholder="Enter student number" required>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass me-1"></i>Search</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($searchSid !== '' && !$student): ?>
        <div class="alert alert-warning">No student found with number <strong><?php echo erx_h($searchSid); ?></strong>.</div>
    <?php elseif ($student): ?>
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <?php echo erx_h(trim(($student->title ?? '') . ' ' . $student->Fname . ' ' . $student->Lname)); ?>
                &mdash; <?php echo erx_h($student->SID); ?>
                <?php if (!empty($student->nrc_pass)): ?>(<?php echo erx_h($student->nrc_pass); ?>)<?php endif; ?>
            </div>
            <div class="card-body">
                <p class="mb-2 text-muted"><?php echo erx_h($student->program ?? ''); ?></p>
                <?php if (empty($courses)): ?>
                    <div class="alert alert-info mb-0">This student has no active course registrations.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead><tr><th>Course</th><th>Name</th><th>Sem/Term</th><th>Year</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($courses as $c):
                                $key = $c['course_code'] . '|' . (int)$c['semester'] . '|' . (int)$c['Year'];
                                $done = isset($examRegistered[$key]); ?>
                                <tr>
                                    <td><?php echo erx_h($c['course_code']); ?></td>
                                    <td><?php echo erx_h($c['course_name'] ?? ''); ?></td>
                                    <td><?php echo (int)$c['semester']; ?></td>
                                    <td><?php echo (int)$c['Year']; ?></td>
                                    <td>
                                        <?php if ($done): ?>
                                            <span class="badge bg-success">Exam registered</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Not registered</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if (!$done): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo erx_h($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="sid" value="<?php echo erx_h($student->SID); ?>">
                                                <input type="hidden" name="course_code" value="<?php echo erx_h($c['course_code']); ?>">
                                                <input type="hidden" name="semester" value="<?php echo (int)$c['semester']; ?>">
                                                <input type="hidden" name="year_of_study" value="<?php echo (int)$c['Year']; ?>">
                                                <button type="submit" name="register_exam" value="1" class="btn btn-sm btn-success">
                                                    <i class="fa-solid fa-check me-1"></i>Register for exam
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted small mb-0">Registration applies the standard eligibility rules (fee thresholds, duplicates, assessment periods).</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
