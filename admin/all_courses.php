<?php
require "includes/admin.php";

// Set page title
$page_title = "All Courses";

require "includes/header.php";

// Get all courses with program information
$query = "SELECT 
    c.*,
    GROUP_CONCAT(DISTINCT pc.program_code ORDER BY pc.program_code SEPARATOR ', ') as programs
FROM courses c
LEFT JOIN program_courses pc ON c.course_code = pc.course_code
GROUP BY c.course_code
ORDER BY c.course_code";

$result = $db->query($query);
$courses = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
}

// Count stats
$totalCourses = count($courses);
$activeCount = 0;
foreach ($courses as $course) {
    if (isset($course['status']) && $course['status'] === 'active') {
        $activeCount++;
    }
}
?>

<link rel="stylesheet" href="css/admin-dashboard.css" />
<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="page-title mb-1"><i class="fas fa-book"></i> All Courses</h2>
                <p class="page-subtitle mb-0">Complete list of all courses in the system</p>
            </div>
            <div class="header-actions">
                <a href="courses.php" class="btn btn-outline-primary">
                    <i class="fas fa-sitemap me-1"></i>By Program
                </a>
                <a href="course_program_mgmt.php" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i>Add Course
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3">
                        <i class="fas fa-book text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($totalCourses); ?></h3>
                        <p class="text-muted mb-0">Total Courses</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3">
                        <i class="fas fa-check-circle text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($activeCount); ?></h3>
                        <p class="text-muted mb-0">Active Courses</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3">
                        <i class="fas fa-graduation-cap text-white"></i>
                    </div>
                    <div>
                        <?php
                        $programCount = $db->query("SELECT COUNT(DISTINCT program_code) as count FROM program_courses")->fetch_assoc();
                        ?>
                        <h3 class="mb-1"><?php echo number_format($programCount['count']); ?></h3>
                        <p class="text-muted mb-0">Programs</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Courses Table -->
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Course Directory
                </h5>
                <div>
                    <button class="btn btn-sm btn-success me-2" onclick="exportTableToExcel('coursesTable', 'courses')">
                        <i class="fas fa-file-excel me-1"></i>Export Excel
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="window.print()">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($courses)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    No courses found in the system. Please add courses to get started.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table id="coursesTable" class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Credits</th>
                                <th>Level</th>
                                <th>Programs</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $number = 1;
                            foreach ($courses as $course): 
                            ?>
                                <tr>
                                    <td><?php echo $number++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                    <td><?php echo htmlspecialchars($course['credits'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        $level = $course['level'] ?? '';
                                        if ($level) {
                                            echo '<span class="badge bg-info">' . htmlspecialchars($level) . '</span>';
                                        } else {
                                            echo '<span class="text-muted">—</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (!empty($course['programs'])) {
                                            $programs = explode(', ', $course['programs']);
                                            foreach ($programs as $prog) {
                                                echo '<span class="badge bg-secondary me-1">' . htmlspecialchars($prog) . '</span>';
                                            }
                                        } else {
                                            echo '<span class="text-muted">Not assigned</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $status = $course['status'] ?? 'active';
                                        $badgeClass = $status === 'active' ? 'bg-success' : 'bg-secondary';
                                        echo '<span class="badge ' . $badgeClass . '">' . ucfirst($status) . '</span>';
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group" role="group">
                                            <a href="course_details.php?code=<?php echo urlencode($course['course_code']); ?>" 
                                               class="btn btn-sm btn-info" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="editCourse.php?course=<?php echo urlencode($course['course_code']); ?>" 
                                               class="btn btn-sm btn-primary" title="Edit">
                                                <i class="fas fa-edit"></i>
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

<script>
// Initialize DataTable for better UX
$(document).ready(function() {
    $('#coursesTable').DataTable({
        pageLength: 25,
        order: [[1, 'asc']],
        language: {
            search: "Search courses:",
            lengthMenu: "Show _MENU_ courses per page",
            info: "Showing _START_ to _END_ of _TOTAL_ courses",
            infoEmpty: "No courses available",
            infoFiltered: "(filtered from _MAX_ total courses)"
        }
    });
});

// Export to Excel function
function exportTableToExcel(tableID, filename = '') {
    var downloadLink;
    var dataType = 'application/vnd.ms-excel';
    var tableSelect = document.getElementById(tableID);
    var tableHTML = tableSelect.outerHTML.replace(/ /g, '%20');
    
    filename = filename ? filename + '.xls' : 'excel_data.xls';
    
    downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    
    if (navigator.msSaveOrOpenBlob) {
        var blob = new Blob(['\ufeff', tableHTML], {
            type: dataType
        });
        navigator.msSaveOrOpenBlob(blob, filename);
    } else {
        downloadLink.href = 'data:' + dataType + ', ' + tableHTML;
        downloadLink.download = filename;
        downloadLink.click();
    }
}
</script>

<?php require "includes/footer.php"; ?>

