<?php
$page_title = 'My Courses';
// Ensure auth and DB are available early
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require "includes/nav.php";

// Schema helpers reused in multiple sections
$tableExists = function(mysqli $db, string $table): bool {
    if ($res = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'")) {
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
    return false;
};

$detectColumn = function(mysqli $db, string $table, array $candidates): ?string {
    foreach ($candidates as $col) {
        $colEsc = $db->real_escape_string($col);
        if ($res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$colEsc}'")) {
            if ($res->num_rows > 0) {
                $res->free();
                return $col;
            }
            $res->free();
        }
    }
    return null;
};

// Fetch courses for the lecturer using centralized helper (resolves names from courses + program_courses)
$records = array();

if(isset($_SESSION['staff_id'])){
    $staffId = $_SESSION['staff_id'];
    
    // Use centralized helper that resolves course names from multiple sources
    $courseDetails = getLecturerCourseDetails($db, $staffId);
    
    foreach ($courseDetails as $cd) {
        $obj = new stdClass();
        $obj->course_code = $cd['course_code'];
        $obj->course_name = $cd['course_name'];
        $records[] = $obj;
    }
}

// Get department details (schema-flexible)
$department = '';
if(isset($_SESSION['staff_id'])) {
    $staffId = $_SESSION['staff_id'];

    // Resolve flexible staff/department linkage
    $staffDeptCol = $detectColumn($db, 'staff', ['department_id', 'deptId', 'DeptID']);
    $deptIdNumericCol = $detectColumn($db, 'departments', ['id', 'DeptID', 'department_id']);
    $deptIdCodeCol = $detectColumn($db, 'departments', ['deptId', 'department_code']);
    $deptNameCol = $detectColumn($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']);

    $join = '';
    if ($staffDeptCol && $deptIdNumericCol) {
        // Generic join - staff department column to departments id column
        $join = "LEFT JOIN departments d ON s.`{$staffDeptCol}` = d.`{$deptIdNumericCol}`";
    } elseif ($staffDeptCol && $deptIdCodeCol) {
        // Alternative: staff department column to departments code column
        $join = "LEFT JOIN departments d ON s.`{$staffDeptCol}` = d.`{$deptIdCodeCol}`";
    }

    // Only reference department name if we have a valid join
    $deptNameExpr = ($deptNameCol && $join !== '') ? "d.`{$deptNameCol}`" : "NULL";

    $sql = "SELECT {$deptNameExpr} AS dept_name FROM staff s {$join} WHERE s.staff_id = ? LIMIT 1";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('s', $staffId);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $department = $row['dept_name'] ?? '';
            }
        }
        $stmt->close();
    }
}

// Get student count by course
$coursesWithStudents = [];

$studentCourseTable = null;
if ($tableExists($db, 'student_course')) { $studentCourseTable = 'student_course'; }
elseif ($tableExists($db, 'student_courses')) { $studentCourseTable = 'student_courses'; }

$scStudentCol = $studentCourseTable ? $detectColumn($db, $studentCourseTable, ['Sid','SID','student_id','studentID']) : null;
$scCourseCol = $studentCourseTable ? $detectColumn($db, $studentCourseTable, ['course_code','code']) : null;

// Fallback registration tables
$fallbackRegTable = null; $fbStudentCol = null; $fbCourseCol = null;
if (!$studentCourseTable) {
    if ($tableExists($db, 'course_registration')) {
        $fallbackRegTable = 'course_registration';
    } elseif ($tableExists($db, 'registered_courses')) {
        $fallbackRegTable = 'registered_courses';
    }
    if ($fallbackRegTable) {
        $fbStudentCol = $detectColumn($db, $fallbackRegTable, ['Sid','SID','student_id']);
        $fbCourseCol = $detectColumn($db, $fallbackRegTable, ['course_code','code']);
    }
}

if (!empty($records)) {
    $courseCodes = array_map(function($course) {
        return $course->course_code;
    }, $records);
    $inPlaceholders = implode(',', array_fill(0, count($courseCodes), '?'));

    $sql = null;
    $params = [];
    $types = '';

    if ($studentCourseTable && $scStudentCol && $scCourseCol) {
        $sql = "SELECT `{$scCourseCol}` AS course_code, COUNT(DISTINCT `{$scStudentCol}`) AS count 
                FROM `{$studentCourseTable}` 
                WHERE `{$scCourseCol}` IN ($inPlaceholders) 
                GROUP BY `{$scCourseCol}`";
        $types = str_repeat('s', count($courseCodes));
        $params = $courseCodes;
    } elseif ($fallbackRegTable && $fbStudentCol && $fbCourseCol) {
        $sql = "SELECT `{$fbCourseCol}` AS course_code, COUNT(DISTINCT `{$fbStudentCol}`) AS count 
                FROM `{$fallbackRegTable}` 
                WHERE `{$fbCourseCol}` IN ($inPlaceholders) 
                GROUP BY `{$fbCourseCol}`";
        $types = str_repeat('s', count($courseCodes));
        $params = $courseCodes;
    }

    if ($sql) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_object()) {
            $coursesWithStudents[$row->course_code] = (int)$row->count;
        }
        $stmt->close();
    }

    foreach ($records as $course) {
        if (!isset($coursesWithStudents[$course->course_code])) {
            $coursesWithStudents[$course->course_code] = 0;
        }
    }
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">My Courses</h1>
                <p class="text-muted">Manage your assigned courses and teaching materials</p>
            </div>
            <div class="col-auto">
                <div class="header-actions d-flex gap-2">
                    <button class="btn btn-outline-secondary" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                    <div class="dropdown">
                        <button class="btn btn-primary dropdown-toggle" type="button" id="dropdownMenuButton" 
                                data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-plus me-2"></i>Actions
                        </button>
                        <ul class="dropdown-menu" aria-labelledby="dropdownMenuButton">
                            <li><a class="dropdown-item" href="#"><i class="fas fa-file-export me-2"></i>Export Course List</a></li>
                            <li><a class="dropdown-item" href="#"><i class="fas fa-calendar me-2"></i>View Schedule</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="materials.php"><i class="fas fa-book me-2"></i>Course Materials</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        </div>

    <!-- Course Overview -->
    <div class="row mb-4">
            <div class="col-md-12">
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-chalkboard-teacher me-2"></i>
                            <?php echo $department ? htmlspecialchars($department) . ' Department' : 'My Department'; ?>
                        </h5>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="data-table-card h-100"><div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="stat-icon bg-lecturer rounded-circle p-3 me-3">
                                        <i class="fas fa-book fa-2x text-white"></i>
                                    </div>
                                    <div>
                                        <h3 class="stat-value mb-0"><?php echo count($records); ?></h3>
                                        <p class="stat-label mb-0">Total Courses</p>
                                    </div>
                                </div>
                            </div></div>
                        </div>
                        <div class="col-md-4">
                            <div class="data-table-card h-100"><div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="stat-icon bg-info rounded-circle p-3 me-3">
                                        <i class="fas fa-users fa-2x text-white"></i>
                                    </div>
                                    <div>
                                        <h3 class="stat-value mb-0"><?php echo array_sum($coursesWithStudents); ?></h3>
                                        <p class="stat-label mb-0">Total Students</p>
                                    </div>
                                </div>
                            </div></div>
                        </div>
                        <div class="col-md-4">
                            <div class="data-table-card h-100"><div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="stat-icon bg-success rounded-circle p-3 me-3">
                                        <i class="fas fa-clock fa-2x text-white"></i>
                                    </div>
                                    <div>
                                        <h3 class="stat-value mb-0"><?php echo date("Y"); ?></h3>
                                        <p class="stat-label mb-0">Academic Year</p>
                                    </div>
                                </div>
                            </div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Courses Table -->
    <div class="data-table-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Course List
                </h5>
                <span class="badge bg-primary rounded-pill">
                    <?php echo count($records) . " " . (count($records) === 1 ? "Course" : "Courses"); ?>
                </span>
            </div>
        </div>
                    <div class="card-body">
                        <?php if (empty($records)): ?>
            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                You currently have no courses assigned to you. Please contact your department head for course assignments.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                    <table id="coursesTable" class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th width="5%">#</th>
                                <th width="15%">Course Code</th>
                                <th width="40%">Course Title</th>
                                <th width="15%" class="text-center">Students</th>
                                <th width="25%" class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($records as $index => $course): ?>
                                            <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <span class="fw-bold"><?php echo htmlspecialchars($course->course_code); ?></span>
                                    </td>
                                                <td><?php echo htmlspecialchars($course->course_name); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-info rounded-pill">
                                            <?php echo isset($coursesWithStudents[$course->course_code]) ? $coursesWithStudents[$course->course_code] : 0; ?> 
                                            Students
                                        </span>
                                    </td>
                                                <td class="text-center">
                                                    <?php 
                                                    $code = trim((string)$course->course_code);
                                                    if ($code === ''): 
                                                    ?>
                                                        <span class="text-muted small">No Code</span>
                                                    <?php else: ?>
                                                        <a href="viewCourse.php?code=<?php echo urlencode($code); ?>" 
                                                           class="btn btn-primary btn-sm" data-bs-toggle="tooltip" title="View Course Details">
                                                            <i class="fas fa-folder-open"></i> Open
                                                        </a>
                                                        <a href="materials.php?course=<?php echo urlencode($code); ?>" 
                                                           class="btn btn-success btn-sm" data-bs-toggle="tooltip" title="Course Materials">
                                                            <i class="fas fa-book"></i> Materials
                                                        </a>
                                                        <a href="upload_ca.php?course=<?php echo urlencode($code); ?>" 
                                                           class="btn btn-warning btn-sm" data-bs-toggle="tooltip" title="Upload Assessment">
                                                            <i class="fas fa-upload"></i> CA
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
    </div>
    
    <!-- Course Cards Section -->
    <?php if (!empty($records)): ?>
    <div class="mt-4">
        <h5 class="text-primary mb-3"><i class="fas fa-th-large me-2"></i>Course Cards</h5>
        <div class="row">
            <?php foreach ($records as $course): ?>
                <div class="col-xl-4 col-md-6 mb-4">
                    <div class="course-card">
                        <div class="course-header">
                            <h5 class="mb-0"><?php echo htmlspecialchars($course->course_code); ?></h5>
                        </div>
                        <div class="course-body">
                            <h6 class="course-title"><?php echo htmlspecialchars($course->course_name); ?></h6>
                            
                            <div class="d-flex justify-content-between mb-3">
                                <span class="badge bg-primary">
                                    <i class="fas fa-users me-1"></i>
                                    <?php echo isset($coursesWithStudents[$course->course_code]) ? $coursesWithStudents[$course->course_code] : 0; ?> Students
                                </span>
                                <span class="badge bg-success">Active</span>
                            </div>
                            
                            <small class="text-muted">Progress tracking coming soon</small>
                            
                            <div class="d-grid gap-2 mt-3">
                                <?php 
                                $cardCode = trim((string)$course->course_code);
                                if ($cardCode === ''): 
                                ?>
                                    <button class="btn btn-secondary btn-sm" disabled>No Code Available</button>
                                <?php else: ?>
                                    <a href="viewCourse.php?code=<?php echo urlencode($cardCode); ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-folder-open me-2"></i>View Course
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
                </div>
    </div>
    <?php endif; ?>
    </div>

<script>
$(document).ready(function() {
    const $table = $('#coursesTable');
    if ($table.length > 0) {
        // Initialize DataTable
        $table.DataTable({
            responsive: true,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search courses...",
                zeroRecords: "No matching courses found",
                info: "Showing _START_ to _END_ of _TOTAL_ courses",
                lengthMenu: "Show _MENU_ courses per page"
            },
            dom: '<"top"lf>rt<"bottom"ip><"clear">',
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            pageLength: 10,
            columnDefs: [
                {orderable: false, targets: [4]}, // Disable sorting on actions column
                {searchable: false, targets: [0, 3, 4]} // Disable search on serial number, students count, and actions columns
            ]
        });
    }

    // Initialize tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
