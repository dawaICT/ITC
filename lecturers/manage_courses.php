<?php
$page_title = "Manage Courses";
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/nav.php';
require_once dirname(__DIR__) . "/includes/permissions.php";

// Check if user has permission to view courses
enforcePermission($_SESSION['staff_id'], 'view_courses');

// Get lecturer's courses using prepared statement
$courses = [];
$stmt = $db->prepare("SELECT c.course_code, c.course_name, cl.section
          FROM courses c
          INNER JOIN course_lecturer cl ON c.course_code = cl.course_code
          WHERE cl.staff_id = ?
          ORDER BY c.course_name");
if ($stmt) {
    $stmt->bind_param("s", $_SESSION['staff_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_object()) {
        $courses[] = $row;
    }
    $stmt->close();
}

// Show flash messages
$flash = wuc_get_flash();
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Manage Your Courses</h1>
                <p class="text-muted">View and manage your assigned courses</p>
            </div>
            <?php if (hasPermission($_SESSION['staff_id'], 'edit_courses')): ?>
            <div class="col-auto">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editCourseModal">
                    <i class="fas fa-edit me-2"></i>Update Course Details
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($flash['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="data-table-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-book me-2"></i>Your Courses</h5>
        </div>
        <div class="card-body">
            <?php if (empty($courses)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>No courses are currently assigned to you.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table id="coursesTable" class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Course Code</th>
                            <th>Course Name</th>
                            <th>Section</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $course): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($course->course_code); ?></strong></td>
                            <td><?php echo htmlspecialchars($course->course_name); ?></td>
                            <td><?php echo htmlspecialchars($course->section ?? ''); ?></td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="viewCourse.php?code=<?php echo urlencode($course->course_code); ?>" 
                                       class="btn btn-outline-primary" title="View Course">
                                        <i class="fas fa-folder-open me-1"></i>Open
                                    </a>
                                    <a href="materials.php?course=<?php echo urlencode($course->course_code); ?>" 
                                       class="btn btn-outline-success" title="Materials">
                                        <i class="fas fa-book me-1"></i>Materials
                                    </a>
                                    <a href="upload_ca.php?course=<?php echo urlencode($course->course_code); ?>" 
                                       class="btn btn-outline-warning" title="Upload CA">
                                        <i class="fas fa-upload me-1"></i>CA
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (hasPermission($_SESSION['staff_id'], 'edit_courses')): ?>
<!-- Edit Course Modal — BEFORE footer so it's inside the DOM properly -->
<div class="modal fade" id="editCourseModal" tabindex="-1" aria-labelledby="editCourseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editCourseModalLabel">Update Course Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editCourseForm" action="update_course.php" method="post">
                    <div class="mb-3">
                        <label for="modal_course_code" class="form-label">Select Course:</label>
                        <select class="form-select" name="course_code" id="modal_course_code" required>
                            <option value="" disabled selected>--select course--</option>
                            <?php foreach ($courses as $course): ?>
                            <option value="<?php echo htmlspecialchars($course->course_code); ?>">
                                <?php echo htmlspecialchars($course->course_code . ' - ' . $course->course_name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="section" class="form-label">Section:</label>
                        <input type="text" class="form-control" name="section" id="section" required>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Course</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function() {
    var $table = $('#coursesTable');
    if ($table.length > 0 && $table.find('tbody tr').length > 0) {
        $table.DataTable({
            "pageLength": 10,
            "order": [[1, "asc"]],
            "responsive": true,
            "columnDefs": [{ "orderable": false, "targets": [-1] }]
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
