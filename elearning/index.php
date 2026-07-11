<?php
error_reporting(0);
// Use the canonical lecturer guard (matches every other page in this module).
// The path `/../includes/guard.php` resolves to wucportal/includes/guard.php,
// which does not exist and was causing a fatal 500.
require_once __DIR__ . '/../lecturers/includes/guard.php'; // ensures staff session + $db
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/portal_access.php';

$staffId = $_SESSION['staff_id'] ?? null;
if (!$staffId) { die('Unauthorized'); }
wuc_require_portal_access($db, 'elearning');

// Enforce basic permission for module access (lecturer/admin/system admin)
if (!canManageElearningCourses($staffId)) {
    // Fallback: allow any staff with teaching assignments
    $hasTeachingAssignment = false;
    if ($st = $db->prepare("SELECT 1 FROM course_lecturer WHERE staff_id = ? LIMIT 1")) {
        $st->bind_param('s', $staffId);
        $st->execute();
        $rs = $st->get_result();
        $hasTeachingAssignment = ($rs && $rs->num_rows > 0);
        $st->close();
    }
    if (!$hasTeachingAssignment) { die("Access Denied"); }
}

// Check if user has admin permissions (includes system administrators)
$isAdmin = (function_exists('isSystemsAdmin') && isSystemsAdmin())
    || isAdministrator($staffId)
    || hasPermission($staffId, 'elearn_admin_all');
$userRole = getElearningUserRole($staffId, null);

// Lecturer portal: assigned courses only (admins use admin/elearning/ for full catalogue).
$courses = getLecturerAssignedCourses($db, $staffId);

// The shared chrome (lecturers/includes/nav.php → nav_unified.php) emits the
// full HTML document head + opens the layout wrappers, and footer.php closes
// them. This page must NOT declare its own <!DOCTYPE>/<head>/<body> or it ends
// up as two nested documents (duplicate <head>/<title>) with the chrome's
// main-wrapper left unclosed. Set the page title, then render content between
// the nav and footer includes — the same pattern as manage.php.
$page_title = 'eLearning';
require_once __DIR__ . '/../lecturers/includes/nav.php';
?>

<div class="container-fluid py-4">
    <h2 class="mb-3"><i class="fas fa-chalkboard"></i> eLearning</h2>

    <div class="alert alert-info d-flex align-items-center" role="alert">
        <i class="fas fa-user-graduate me-2"></i>
        You are viewing your assigned courses.
        <?php if ($isAdmin): ?>
            <span class="ms-2 small">Administrators manage the full catalogue from the admin eLearning area.</span>
        <?php endif; ?>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <p class="text-muted">Select a course to manage modules, content, live sessions, and assessments.</p>
            <?php if (count($courses) === 0): ?>
                <div class="alert alert-light border mb-0">No courses found.</div>
            <?php else: ?>
            <div class="list-group">
                <?php foreach ($courses as $cc): ?>
                    <a class="list-group-item list-group-item-action" href="manage.php?course_code=<?php echo urlencode($cc); ?>">
                        <i class="fas fa-book me-2 text-primary"></i> <?php echo htmlspecialchars($cc); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../lecturers/includes/footer.php'; ?>
