<?php
require_once "includes/admin.php";
require_once "../db/connect.php";
require_once dirname(__DIR__) . '/includes/helpers/academic_structure_helpers.php';
require_once dirname(__DIR__) . '/includes/helpers/course_availability_helpers.php';
error_reporting(0);

// Handle form submission for adding new semester courses
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_course') {
        $course_code = trim($_POST['course_code']);
        $program_code = trim($_POST['program_code']);
        $year = max(1, (int)($_POST['year'] ?? 1));
        $semester = (int)$_POST['semester'];
        $periodSpecific = !empty($_POST['period_specific']);

        if ($periodSpecific) {
            $alignment = wuc_validate_curriculum_period($db, $program_code, $year, $semester);
            if (!$alignment['ok']) {
                echo json_encode(['success' => false, 'message' => $alignment['reason']]);
                exit;
            }
        }

        $result = wuc_insert_program_course_assignment(
            $db,
            $program_code,
            $course_code,
            $year,
            $periodSpecific ? $semester : null,
            $periodSpecific
        );

        if ($result['ok']) {
            $msg = $result['skipped']
                ? 'This course is already assigned to this program for that year.'
                : 'Course added successfully';
            echo json_encode(['success' => true, 'message' => $msg]);
        } else {
            echo json_encode(['success' => false, 'message' => $result['message'] ?: 'Failed to add course']);
        }
        exit;
    }

    if ($_POST['action'] === 'delete_course') {
        $id = (int)$_POST['id'];
        $delete_sql = "DELETE FROM program_courses WHERE id = ?";
        $delete_stmt = $db->prepare($delete_sql);
        $delete_stmt->bind_param("i", $id);

        if ($delete_stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Course removed successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to remove course']);
        }
        exit;
    }

    if ($_POST['action'] === 'update_course') {
        $id = (int)$_POST['id'];
        $semester = (int)$_POST['semester'];
        $credits = (int)$_POST['credits'];

        // Validate the new period against the owning programme's structure.
        $rowProgram = '';
        if ($progStmt = $db->prepare("SELECT program_code FROM program_courses WHERE id = ? LIMIT 1")) {
            $progStmt->bind_param('i', $id);
            $progStmt->execute();
            $progStmt->bind_result($rowProgram);
            $progStmt->fetch();
            $progStmt->close();
        }
        $alignment = wuc_validate_curriculum_period($db, (string)$rowProgram, null, $semester);
        if (!$alignment['ok']) {
            echo json_encode(['success' => false, 'message' => $alignment['reason']]);
            exit;
        }

        $update_sql = "UPDATE program_courses SET semester = ?, credits = ? WHERE id = ?";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param("iii", $semester, $credits, $id);

        if ($update_stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Course updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update course']);
        }
        exit;
    }
}

// Fetch all semester courses with related information
$sql = "SELECT pc.id, pc.program_code, pc.course_code, pc.semester, pc.credits,
               p.program_name, c.course_name
        FROM program_courses pc
        JOIN programs p ON pc.program_code = p.program_code
        JOIN courses c ON pc.course_code = c.course_code
        ORDER BY p.program_name, pc.semester, c.course_name";

$result = $db->query($sql);
$semester_courses = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $semester_courses[] = $row;
    }
}

// Fetch programs for dropdown
$programs_sql = "SELECT program_code, program_name FROM programs WHERE status = 'active' ORDER BY program_name";
$programs_result = $db->query($programs_sql);
$programs = [];
if ($programs_result) {
    while ($row = $programs_result->fetch_assoc()) {
        $programs[] = $row;
    }
}

// Fetch courses for dropdown
$courses_sql = "SELECT course_code, course_name, credits FROM courses WHERE status = 'active' ORDER BY course_name";
$courses_result = $db->query($courses_sql);
$courses = [];
if ($courses_result) {
    while ($row = $courses_result->fetch_assoc()) {
        $courses[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semester Courses - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .admin-dashboard {
            padding: 20px;
        }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        }
        .card-header {
            border-bottom: 1px solid #eee;
            background-color: #f8f9fa;
        }
        .btn-success {
            background-color: #28a745;
            border: none;
            border-radius: 5px;
            padding: 8px 15px;
        }
        .btn-success:hover {
            background-color: #218838;
        }
        .modal.show {
            display: block;
            background-color: rgba(0, 0, 0, 0.5);
        }
        .semester-table {
            width: 100%;
            border-collapse: collapse;
        }
        .semester-table th, .semester-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .semester-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .semester-table tr:hover {
            background-color: #f5f5f5;
        }
        .action-buttons .btn {
            margin-right: 5px;
        }
        .sidebar {
            background-color: #343a40;
            color: white;
            min-height: 100vh;
            padding: 20px 0;
        }
        .sidebar a {
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            display: block;
        }
        .sidebar a:hover {
            background-color: #495057;
        }
        .sidebar .active {
            background-color: #007bff;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            .sidebar {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Navigation -->
    <div class="sidebar position-fixed" style="width: 250px;">
        <h5 class="text-center mb-4">Student Portal</h5>
        <a href="dashboard_admin.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a>
        <a href="students_by_admin.php"><i class="fas fa-users me-2"></i> Students</a>
        <a href="staff.php"><i class="fas fa-user-tie me-2"></i> Staff</a>
        <a href="programs.php"><i class="fas fa-graduation-cap me-2"></i> Programs</a>
        <a href="courses.php"><i class="fas fa-book me-2"></i> Courses</a>
        <a href="semester.php"><i class="fas fa-calendar-alt me-2"></i> Semester Courses</a>
        <a href="modern_semester_courses.php" class="active"><i class="fas fa-calendar me-2"></i> Modern Semester Courses</a>
        <a href="assessments.php"><i class="fas fa-tasks me-2"></i> Assessments</a>
        <a href="exams.php"><i class="fas fa-edit me-2"></i> Exams</a>
        <a href="payments.php"><i class="fas fa-dollar-sign me-2"></i> Payments</a>
        <a href="library.php"><i class="fas fa-book me-2"></i> Library</a>
        <a href="hostels.php"><i class="fas fa-home me-2"></i> Hostels</a>
        <a href="news_events.php"><i class="fas fa-calendar me-2"></i> News & Events</a>
        <hr>
        <a href="staffLogout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
            <div class="container-fluid">
                <span class="navbar-brand mb-0 h1">University Admin System</span>
                <div class="navbar-nav ms-auto">
                    <span class="navbar-text me-3">
                        Welcome, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?>
                    </span>
                </div>
            </div>
        </nav>

        <!-- Semester Courses Content -->
        <div class="container-fluid portal-dashboard">
            <div class="row">
                <div class="col-12">
                    <div class="data-table-card">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h4 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Semester Courses Management</h4>
                                <div class="header-actions">
                                    <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addCourseModal">
                                        <i class="fas fa-plus"></i> Add New Course
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Semester courses table -->
                            <div class="table-responsive">
                                <table class="table table-hover align-middle semester-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Program</th>
                                            <th>Course Code</th>
                                            <th>Course Name</th>
                                            <th>Semester</th>
                                            <th>Credits</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($semester_courses)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4">
                                                    <i class="fas fa-info-circle me-2"></i>No semester courses found. Click "Add New Course" to get started.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($semester_courses as $course): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($course['program_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($course['course_code']); ?></td>
                                                    <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($course['semester']); ?></td>
                                                    <td><?php echo htmlspecialchars($course['credits']); ?></td>
                                                    <td class="action-buttons">
                                                        <button class="btn btn-sm btn-primary edit-btn"
                                                                data-id="<?php echo $course['id']; ?>"
                                                                data-program="<?php echo htmlspecialchars($course['program_code']); ?>"
                                                                data-course="<?php echo htmlspecialchars($course['course_code']); ?>"
                                                                data-semester="<?php echo $course['semester']; ?>"
                                                                data-credits="<?php echo $course['credits']; ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-danger delete-btn"
                                                                data-id="<?php echo $course['id']; ?>"
                                                                data-course="<?php echo htmlspecialchars($course['course_name']); ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Course Modal -->
    <div class="modal fade" id="addCourseModal" tabindex="-1" aria-labelledby="addCourseModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addCourseModalLabel">Add New Semester Course</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addCourseForm">
                        <div class="mb-3">
                            <label for="programSelect" class="form-label">Program</label>
                            <select class="form-control" id="programSelect" required>
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo htmlspecialchars($program['program_code']); ?>">
                                        <?php echo htmlspecialchars($program['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="courseSelect" class="form-label">Course</label>
                            <select class="form-control" id="courseSelect" required>
                                <option value="">Select Course</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo htmlspecialchars($course['course_code']); ?>"
                                            data-credits="<?php echo $course['credits']; ?>">
                                        <?php echo htmlspecialchars($course['course_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="semesterSelect" class="form-label">Semester</label>
                            <select class="form-control" id="semesterSelect" required>
                                <option value="">Select Semester</option>
                                <option value="1">Semester 1</option>
                                <option value="2">Semester 2</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="creditsInput" class="form-label">Credits</label>
                            <input type="number" class="form-control" id="creditsInput" min="1" max="6" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="saveCourseBtn">Add Course</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Course Modal -->
    <div class="modal fade" id="editCourseModal" tabindex="-1" aria-labelledby="editCourseModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editCourseModalLabel">Edit Semester Course</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editCourseForm">
                        <input type="hidden" id="editCourseId">
                        <div class="mb-3">
                            <label class="form-label">Program</label>
                            <input type="text" class="form-control" id="editProgramName" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Course</label>
                            <input type="text" class="form-control" id="editCourseName" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="editSemesterSelect" class="form-label">Semester</label>
                            <select class="form-control" id="editSemesterSelect" required>
                                <option value="1">Semester 1</option>
                                <option value="2">Semester 2</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="editCreditsInput" class="form-label">Credits</label>
                            <input type="number" class="form-control" id="editCreditsInput" min="1" max="6" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="updateCourseBtn">Update Course</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white text-center py-3 mt-4">
        <div class="container">
            <p class="mb-0">University Admin System &copy; 2023. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-fill credits when course is selected
        document.getElementById('courseSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const credits = selectedOption.getAttribute('data-credits');
            if (credits) {
                document.getElementById('creditsInput').value = credits;
            }
        });

        // Add course functionality
        document.getElementById('saveCourseBtn').addEventListener('click', function() {
            const programCode = document.getElementById('programSelect').value;
            const courseCode = document.getElementById('courseSelect').value;
            const semester = document.getElementById('semesterSelect').value;
            const credits = document.getElementById('creditsInput').value;

            if (!programCode || !courseCode || !semester || !credits) {
                alert('Please fill in all fields');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'add_course');
            formData.append('program_code', programCode);
            formData.append('course_code', courseCode);
            formData.append('semester', semester);
            formData.append('credits', credits);

            fetch('modern_semester_courses.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while adding the course');
            });
        });

        // Edit course functionality
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const program = this.getAttribute('data-program');
                const course = this.getAttribute('data-course');
                const semester = this.getAttribute('data-semester');
                const credits = this.getAttribute('data-credits');

                document.getElementById('editCourseId').value = id;
                document.getElementById('editProgramName').value = program;
                document.getElementById('editCourseName').value = course;
                document.getElementById('editSemesterSelect').value = semester;
                document.getElementById('editCreditsInput').value = credits;

                const editModal = new bootstrap.Modal(document.getElementById('editCourseModal'));
                editModal.show();
            });
        });

        // Update course functionality
        document.getElementById('updateCourseBtn').addEventListener('click', function() {
            const id = document.getElementById('editCourseId').value;
            const semester = document.getElementById('editSemesterSelect').value;
            const credits = document.getElementById('editCreditsInput').value;

            if (!semester || !credits) {
                alert('Please fill in all fields');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update_course');
            formData.append('id', id);
            formData.append('semester', semester);
            formData.append('credits', credits);

            fetch('modern_semester_courses.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating the course');
            });
        });

        // Delete course functionality
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const courseName = this.getAttribute('data-course');

                if (confirm(`Are you sure you want to remove "${courseName}" from this semester?`)) {
                    const formData = new FormData();
                    formData.append('action', 'delete_course');
                    formData.append('id', id);

                    fetch('modern_semester_courses.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        alert(data.message);
                        if (data.success) {
                            location.reload();
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while deleting the course');
                    });
                }
            });
        });

        // Modal functionality for Bootstrap
        const addModal = document.getElementById('addCourseModal');
        addModal.addEventListener('show.bs.modal', function() {
            // Reset form when modal is shown
            document.getElementById('addCourseForm').reset();
        });
    </script>
</body>
</html>

