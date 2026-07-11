<?php
include "includes/admin.php";

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo '<div class="alert alert-danger">Invalid program ID</div>';
    exit;
}

$program_code = trim($_GET['id']);
$hasIsActive = false;
$hasStatus = false;
if ($programColumns = $db->query("SHOW COLUMNS FROM programs")) {
    while ($column = $programColumns->fetch_assoc()) {
        $hasIsActive = $hasIsActive || $column['Field'] === 'is_active';
        $hasStatus = $hasStatus || $column['Field'] === 'status';
    }
    $programColumns->free();
}
$programStatusFilter = $hasStatus ? "AND p.status != 'deleted'" : "";

// Get program details with course count and total students
$sql = "SELECT p.*, 
        COUNT(DISTINCT pc.course_code) as total_courses,
        COUNT(DISTINCT sp.Sid) as total_students
        FROM programs p 
        LEFT JOIN program_courses pc ON p.program_code = pc.program_code
        LEFT JOIN student_program sp ON p.program_code = sp.program_code
        WHERE p.program_code = ? {$programStatusFilter}
        GROUP BY p.program_code";

$stmt = $db->prepare($sql);
$stmt->bind_param("s", $program_code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<div class="alert alert-danger">Program not found</div>';
    exit;
}

$program = $result->fetch_object();
$programType = trim((string)($program->program_type ?? ''));
$studyMode = trim((string)($program->study_mode ?? ''));

// Get recent enrollments
$enrollment_sql = "SELECT s.SID AS student_id, s.Fname AS fname, s.Lname AS lname, sp.id AS enrollment_order
                  FROM student_program sp
                  JOIN students s ON sp.Sid COLLATE utf8mb4_general_ci = s.SID COLLATE utf8mb4_general_ci
                  WHERE sp.program_code = ?
                  ORDER BY sp.id DESC
                  LIMIT 5";

$enrollment_stmt = $db->prepare($enrollment_sql);
$enrollment_stmt->bind_param("s", $program_code);
$enrollment_stmt->execute();
$enrollments = $enrollment_stmt->get_result();
?>

<div class="program-details">
    <div class="row mb-4">
        <div class="col-md-6">
            <h5 class="text-primary mb-3">Program Information</h5>
            <table class="table table-hover align-middle">
                <tr>
                    <th width="35%">Program Code:</th>
                    <td><?php echo htmlspecialchars($program->program_code); ?></td>
                </tr>
                <tr>
                    <th>Program Name:</th>
                    <td><?php echo htmlspecialchars($program->program_name); ?></td>
                </tr>
                <tr>
                    <th>Type:</th>
                    <td>
                        <span class="badge rounded-pill <?php echo htmlspecialchars($programType !== '' ? $programType : 'degree'); ?>-badge">
                            <?php echo htmlspecialchars($programType !== '' ? ucfirst($programType) : 'N/A'); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Duration:</th>
                    <td><?php echo htmlspecialchars((string)($program->program_duration ?? 'N/A')); ?></td>
                </tr>
                <tr>
                    <th>Study Mode:</th>
                    <td><?php echo htmlspecialchars($studyMode !== '' ? ucfirst($studyMode) : 'N/A'); ?></td>
                </tr>
            </table>
        </div>
        <div class="col-md-6">
            <h5 class="text-primary mb-3">Statistics</h5>
            <table class="table table-hover align-middle">
                <tr>
                    <th width="35%">Total Courses:</th>
                    <td>
                        <span class="badge bg-info">
                            <?php echo (int)$program->total_courses; ?> Courses
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Total Students:</th>
                    <td>
                        <span class="badge bg-success">
                            <?php echo $program->total_students; ?> Students
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Maximum Courses:</th>
                    <td><?php echo htmlspecialchars((string)($program->program_duration ?? 'N/A')); ?></td>
                </tr>
                <tr>
                    <th>Total Fees:</th>
                    <td>N/A</td>
                </tr>
                <tr>
                    <th>Status:</th>
                    <td>
                        <span class="badge bg-<?php echo ((int)($program->is_active ?? 1) === 1) ? 'success' : 'danger'; ?>">
                            <?php echo ((int)($program->is_active ?? 1) === 1) ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <?php if ($enrollments->num_rows > 0): ?>
    <div class="recent-enrollments">
        <h5 class="text-primary mb-3">Recent Enrollments</h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Enrollment Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($enrollment = $enrollments->fetch_object()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($enrollment->student_id); ?></td>
                        <td><?php echo htmlspecialchars($enrollment->fname . ' ' . $enrollment->lname); ?></td>
                        <td>#<?php echo (int)$enrollment->enrollment_order; ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.program-details {
    padding: 20px;
}
.program-details .table th {
    font-weight: 600;
    color: #666;
}
.program-details .badge {
    font-size: 0.875rem;
    padding: 0.4em 0.8em;
}
.recent-enrollments {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #eee;
}
.degree-badge {
    background-color: #28a745;
    color: white;
}
.diploma-badge {
    background-color: #007bff;
    color: white;
}
.certificate-badge {
    background-color: #ffc107;
    color: black;
}
</style> 
