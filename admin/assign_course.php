<?php
require_once "includes/admin.php";
// Set page title early so header can use it later
$page_title = "Assign Course to Lecturer";
// Defer including header until after pre-output logic
error_reporting(0);

// Ensure assignment table exists
if ($res = $db->query("SHOW TABLES LIKE 'course_lecturer'")) {
    if ($res->num_rows === 0) {
        $db->query("CREATE TABLE course_lecturer (id INT AUTO_INCREMENT PRIMARY KEY, course_code VARCHAR(20) NOT NULL, staff_id VARCHAR(20) NOT NULL, UNIQUE KEY uniq_assignment (course_code, staff_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $res->free();
}

// Detect assignment id column name (supports legacy schemas)
$assignmentIdCol = 'id';
if ($res = $db->query("SHOW COLUMNS FROM course_lecturer LIKE 'id'")) {
    if ($res->num_rows === 0) { $assignmentIdCol = 'course_lecturer_id'; }
    $res->free();
}

// Helper: detect existing column on a table
function detectColumn(mysqli $db, string $table, array $candidates): ?string {
    foreach ($candidates as $col) {
        if ($res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '".$db->real_escape_string($col)."'")) {
            if ($res->num_rows > 0) { $res->free(); return $col; }
            $res->free();
        }
    }
    return null;
}

// Helper: get staff department identifiers (numeric id and/or code)
// Defensive: the staff table may not carry any department column in this schema.
function getStaffDepartment(mysqli $db, string $staffId): array {
    $deptNumeric = null; $deptCode = null;
    $numCol = detectColumn($db, 'staff', ['department_id', 'dept_id']);
    $codeCol = detectColumn($db, 'staff', ['deptId', 'deptID', 'DeptID', 'dept_code']);
    $selectCols = array_values(array_filter([$numCol, $codeCol]));
    if (empty($selectCols)) {
        return [null, null]; // no staff->department linkage available
    }
    $selectSql = implode(', ', array_map(fn($c) => "`{$c}`", $selectCols));
    if ($stmt = $db->prepare("SELECT {$selectSql} FROM staff WHERE staff_id = ? LIMIT 1")) {
        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            if ($numCol && isset($row[$numCol]) && $row[$numCol] !== null && $row[$numCol] !== '') {
                $deptNumeric = (int)$row[$numCol];
            }
            if ($codeCol && isset($row[$codeCol]) && $row[$codeCol] !== null && $row[$codeCol] !== '') {
                $deptCode = $row[$codeCol];
            }
        }
        $stmt->close();
    }
    return [$deptNumeric, $deptCode];
}

// Helper: resolve department numeric id from department code (deptId)
function resolveDepartmentIdFromCode(mysqli $db, string $deptCode): ?int {
    // Try common columns on departments table
    $hasId = detectColumn($db, 'departments', ['id']) !== null;
    $hasDeptId = detectColumn($db, 'departments', ['deptId']) !== null;
    if ($hasId && $hasDeptId) {
        if ($stmt = $db->prepare("SELECT id FROM departments WHERE deptId = ? LIMIT 1")) {
            $stmt->bind_param('s', $deptCode);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) { $stmt->close(); return (int)$row['id']; }
            $stmt->close();
        }
    }
    return null;
}

// Helper: fetch allowed courses for a given staff based on their department/program
function fetchAllowedCoursesForStaff(mysqli $db, string $staffId): array {
    [$deptNumeric, $deptCode] = getStaffDepartment($db, $staffId);
    $progDeptCol = detectColumn($db, 'programs', ['department_id', 'deptId', 'dept_id']);
    // If programs carry no department column, or the staff has no resolvable
    // department, list all program courses rather than returning nothing.
    if (!$progDeptCol || ($deptNumeric === null && $deptCode === null)) {
        $sql = "SELECT DISTINCT c.course_code, c.course_name, p.program_code, p.program_name
                FROM program_courses pc
                JOIN programs p ON p.program_code = pc.program_code
                JOIN courses c ON c.course_code = pc.course_code
                ORDER BY p.program_name, c.course_code";
        $rows = [];
        if ($res = $db->query($sql)) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }

    $rows = [];
    $join = '';
    $where = '';
    $params = [];
    $types = '';

    if ($progDeptCol === 'department_id') {
        if ($deptNumeric) {
            $where = 'WHERE p.department_id = ?';
            $params[] = $deptNumeric; $types .= 'i';
        } elseif ($deptCode) {
            // Join departments to translate code to numeric id
            $join = 'JOIN departments d ON p.department_id = d.id';
            $where = 'WHERE d.deptId = ?';
            $params[] = $deptCode; $types .= 's';
        }
    } else { // programs uses deptId/dept_id as string
        if ($deptCode) {
            $where = "WHERE p.`$progDeptCol` = ?";
            $params[] = $deptCode; $types .= 's';
        } elseif ($deptNumeric) {
            // Translate numeric to code via departments
            $join = 'JOIN departments d ON (p.`$progDeptCol` = d.deptId)';
            $where = 'WHERE d.id = ?';
            $params[] = $deptNumeric; $types .= 'i';
        }
    }

    $sql = "SELECT DISTINCT c.course_code, c.course_name, p.program_code, p.program_name
            FROM program_courses pc
            JOIN programs p ON p.program_code = pc.program_code
            JOIN courses c ON c.course_code = pc.course_code
            $join
            $where
            ORDER BY p.program_name, c.course_code";

    if ($where === '') {
        // As a last resort, if we couldn't resolve department, return empty to force explicit choice
        return [];
    }

    if ($stmt = $db->prepare($sql)) {
        if (!empty($params)) { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();
    }
    return $rows;
}

// AJAX endpoint: get courses allowed for a given staff
if (isset($_GET['ajax']) && $_GET['ajax'] === 'staff_courses') {
    header('Content-Type: application/json');
    $staffId = isset($_GET['staff_id']) ? trim($_GET['staff_id']) : '';
    $data = [];
    if ($staffId !== '') {
        $data = fetchAllowedCoursesForStaff($db, $staffId);
    }
    echo json_encode([ 'courses' => $data ]);
    exit();
}

// Process form submission (before any output)
if (!empty($_POST) && isset($_POST["course_code"], $_POST["staff_id"])) {
    $course_code = trim($_POST["course_code"]);
    $staff_id = trim($_POST["staff_id"]);
    
    if (!empty($course_code) && !empty($staff_id)) {
        // Retrieve current academic year
        $curAy = date('Y');
        if ($st = $db->prepare("SELECT setting_value FROM portal_settings WHERE setting_key = 'current_academic_year' LIMIT 1")) {
            if ($st->execute()) {
                $res = $st->get_result();
                if ($res && $res->num_rows) {
                    $curAy = (string)$res->fetch_assoc()['setting_value'];
                }
            }
            $st->close();
        }

        // Retrieve course mappings from program_courses
        $pc_query = "SELECT program_code, year, semester FROM program_courses WHERE course_code = ?";
        $pc_stmt = $db->prepare($pc_query);
        $pc_stmt->bind_param("s", $course_code);
        $pc_stmt->execute();
        $pc_result = $pc_stmt->get_result();
        
        $inserted = 0;
        $existed = 0;
        
        if ($pc_result->num_rows > 0) {
            while ($pc_row = $pc_result->fetch_assoc()) {
                $prog = $pc_row['program_code'];
                $y = (int)$pc_row['year'];
                $sem = (string)$pc_row['semester'];
                
                // Get program academic structure
                $p_info_stmt = $db->prepare("SELECT academic_structure FROM programs WHERE program_code = ? LIMIT 1");
                $p_info_stmt->bind_param("s", $prog);
                $p_info_stmt->execute();
                $p_info = $p_info_stmt->get_result()->fetch_assoc();
                $p_info_stmt->close();
                
                $academic_structure = $p_info['academic_structure'] ?? 'certificate_term';
                
                // Determine appropriate fields based on academic structure
                if ($academic_structure === 'short_course') {
                    // For short course: don't require year of study or semester.
                    // We check uniqueness using program_code and course_code.
                    $check_query = "SELECT 1 FROM course_lecturer WHERE course_code = ? AND staff_id = ? AND program_code = ?";
                    $check_stmt = $db->prepare($check_query);
                    $check_stmt->bind_param("sss", $course_code, $staff_id, $prog);
                    $check_stmt->execute();
                    $has_assignment = $check_stmt->get_result()->num_rows > 0;
                    $check_stmt->close();
                    
                    if (!$has_assignment) {
                        $insert = $db->prepare("INSERT INTO course_lecturer (course_code, staff_id, program_code, academic_year, status) VALUES (?, ?, ?, ?, 'active')");
                        $insert->bind_param("ssss", $course_code, $staff_id, $prog, $curAy);
                        $insert->execute();
                        $insert->close();
                        $inserted++;
                    } else {
                        $existed++;
                    }
                } else {
                    // For standard Certificate/Diploma/Transport exception: require year of study and term/semester
                    $check_query = "SELECT 1 FROM course_lecturer WHERE course_code = ? AND staff_id = ? AND program_code = ? AND year_of_study = ? AND semester = ?";
                    $check_stmt = $db->prepare($check_query);
                    $check_stmt->bind_param("sssis", $course_code, $staff_id, $prog, $y, $sem);
                    $check_stmt->execute();
                    $has_assignment = $check_stmt->get_result()->num_rows > 0;
                    $check_stmt->close();
                    
                    if (!$has_assignment) {
                        $insert = $db->prepare("INSERT INTO course_lecturer (course_code, staff_id, program_code, academic_year, year_of_study, semester, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                        $insert->bind_param("ssssis", $course_code, $staff_id, $prog, $curAy, $y, $sem);
                        $insert->execute();
                        $insert->close();
                        $inserted++;
                    } else {
                        $existed++;
                    }
                }
            }
        } else {
            // Fallback legacy insertion
            $check_query = "SELECT 1 FROM course_lecturer WHERE course_code = ? AND staff_id = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bind_param("ss", $course_code, $staff_id);
            $check_stmt->execute();
            $has_assignment = $check_stmt->get_result()->num_rows > 0;
            $check_stmt->close();
            
            if (!$has_assignment) {
                $insert = $db->prepare("INSERT INTO course_lecturer (course_code, staff_id, academic_year, status) VALUES (?, ?, ?, 'active')");
                $insert->bind_param("sss", $course_code, $staff_id, $curAy);
                $insert->execute();
                $insert->close();
                $inserted++;
            } else {
                $existed++;
            }
        }
        $pc_stmt->close();
        
        if ($inserted > 0) {
            $_SESSION['successMsg'] = "Course has been assigned to the lecturer successfully ($inserted context mappings created)";
        } else {
            $_SESSION['errorMsg'] = 'This course is already assigned to the selected lecturer under all mapping contexts';
        }
        header('Location: assign_course.php');
        exit();
    }
}

// Handle delete assignment (before any output)
if (!empty($_POST) && isset($_POST['delete_assignment'], $_POST['assignment_id'])) {
    $assignmentId = (int)$_POST['assignment_id'];
    if ($assignmentId > 0) {
        // Delete using detected id column
        $stmt = $db->prepare("DELETE FROM course_lecturer WHERE $assignmentIdCol = ?");
        $stmt->bind_param("i", $assignmentId);
        $stmt->execute();
        $_SESSION['successMsg'] = 'Assignment removed successfully';
        header('Location: assign_course.php');
        exit();
    }
}

// Fetch all courses
$courses_query = "SELECT * FROM courses ORDER BY course_name";
$courses_result = $db->query($courses_query);
$courses = array();
if ($courses_result && $courses_result->num_rows > 0) {
    while ($row = $courses_result->fetch_object()) {
        $courses[] = $row;
    }
    $courses_result->free();
}

// Fetch all lecturers (match by position name so it works regardless of how PosID is coded)
$lecturers_query = "SELECT DISTINCT s.staff_id, s.title, s.Fname, s.Lname
                   FROM staff s
                   INNER JOIN staff_positions sp ON s.staff_id = sp.staff_id
                   INNER JOIN positions p ON sp.PosID = p.PosID
                   WHERE LOWER(TRIM(p.PosName)) LIKE '%lecturer%'
                   ORDER BY s.Fname, s.Lname";
$lecturers_result = $db->query($lecturers_query);
$lecturers = array();
if ($lecturers_result && $lecturers_result->num_rows > 0) {
    while ($row = $lecturers_result->fetch_object()) {
        $lecturers[] = $row;
    }
    $lecturers_result->free();
}
// Include header only after all pre-output logic is done
require_once "includes/header.php";
// Apply same CSS styling as course_program_mgmt.php
// Use unified default font sizing from global styles
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Assign Course to Lecturer</h1>
                <p class="text-muted">Assign courses to lecturers for teaching</p>
            </div>
            <div class="col-auto">
                <a href="course_program_mgmt.php" class="btn btn-secondary d-flex align-items-center gap-2">
                    <i class="fas fa-arrow-left"></i> Back to Course Mgmt
                </a>
            </div>
        </div>
    </div>

    <?php if(isset($_SESSION['successMsg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['successMsg']; unset($_SESSION['successMsg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if(isset($_SESSION['errorMsg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['errorMsg']; unset($_SESSION['errorMsg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="data-table-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-plus-circle me-2"></i>Course Assignment Form
                </h5>
            </div>
        </div>
        <div class="card-body">
            <form action="assign_course.php" method="post" class="needs-validation" novalidate>
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="course_code" class="form-label">Course</label>
                            <select class="form-select" name="course_code" id="course_code" required>
                                <option value="" disabled selected>Select a course</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo htmlspecialchars($course->course_code); ?>">
                                        <?php echo htmlspecialchars($course->course_code . ' - ' . $course->course_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">List will filter to staff department after selecting a lecturer.</div>
                            <div class="invalid-feedback">Please select a course</div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="staff_id" class="form-label">Lecturer</label>
                            <select class="form-select" name="staff_id" id="staff_id" required>
                                <option value="" disabled selected>Select a lecturer</option>
                                <?php foreach ($lecturers as $lecturer): ?>
                                    <option value="<?php echo htmlspecialchars($lecturer->staff_id); ?>">
                                        <?php echo htmlspecialchars($lecturer->title . ' ' . $lecturer->Fname . ' ' . $lecturer->Lname . ' (' . $lecturer->staff_id . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a lecturer</div>
                        </div>
                    </div>
                </div>
                
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Assign Course
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="data-table-card mt-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Current Assignments
                </h5>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="assignmentsTable" class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>No.</th>
                            <th>Course Code</th>
                            <th>Course Name</th>
                            <th>Lecturer</th>
                            <th>Staff ID</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $assignments = [];
                    $qry = "SELECT cl.$assignmentIdCol AS id, cl.course_code, COALESCE(c.course_name,'') AS course_name, cl.staff_id,
                                   CONCAT(COALESCE(s.title,''),' ',COALESCE(s.Fname,''),' ',COALESCE(s.Lname,'')) AS lecturer_name
                            FROM course_lecturer cl
                            LEFT JOIN courses c ON c.course_code = cl.course_code
                            LEFT JOIN staff s ON s.staff_id = cl.staff_id
                            ORDER BY cl.course_code, lecturer_name";
                    if ($res = $db->query($qry)) {
                        while ($row = $res->fetch_object()) { $assignments[] = $row; }
                        $res->free();
                    }
                    if (!empty($assignments)) {
                        $rowNumber = 1;
                        foreach ($assignments as $a) {
                            echo '<tr>';
                            echo '<td>' . $rowNumber++ . '</td>';
                            echo '<td>' . htmlspecialchars($a->course_code) . '</td>';
                            echo '<td>' . htmlspecialchars($a->course_name) . '</td>';
                            echo '<td>' . htmlspecialchars($a->lecturer_name) . '</td>';
                            echo '<td>' . htmlspecialchars($a->staff_id) . '</td>';
                            echo '<td class="text-center">'
                                . '<form method="post" class="d-inline" onsubmit="return confirm(\'Remove this assignment?\')">'
                                . '<input type="hidden" name="assignment_id" value="' . (int)$a->id . '">'
                                . '<button type="submit" name="delete_assignment" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>'
                                . '</form>'
                                . '</td>';
                            echo '</tr>';
                        }
                    }
                    ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Form validation
(function() {
    'use strict';
    var forms = document.querySelectorAll('.needs-validation');
    Array.prototype.slice.call(forms).forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();
// Initialize DataTable for Current Assignments
$(document).ready(function(){
    // Dynamically filter courses by selected staff's department/program
    $('#staff_id').on('change', function(){
        const staffId = $(this).val();
        const $course = $('#course_code');
        if (!staffId) { return; }
        $course.prop('disabled', true).html('<option>Loading...</option>');
        $.getJSON('assign_course.php', { ajax: 'staff_courses', staff_id: staffId })
            .done(function(resp){
                $course.empty().append('<option value="" disabled selected>Select a course</option>');
                if (resp && Array.isArray(resp.courses) && resp.courses.length) {
                    resp.courses.forEach(function(row){
                        const code = row.course_code || '';
                        const name = row.course_name || '';
                        const prog = row.program_name ? (' [' + row.program_name + ']') : '';
                        if (code) {
                            $course.append('<option value="'+ code +'">' + code + ' - ' + name + prog + '</option>');
                        }
                    });
                } else {
                    $course.append('<option value="" disabled>No courses available for this lecturer\'s department</option>');
                }
            })
            .fail(function(){
                $course.empty().append('<option value="" disabled>Error loading courses</option>');
            })
            .always(function(){ $course.prop('disabled', false); });
    });

    const $assignmentsTable = $('#assignmentsTable');
    if ($assignmentsTable.length && !$.fn.DataTable.isDataTable($assignmentsTable)) {
        const expectedColumns = $assignmentsTable.find('thead tr:first th').length;
        let hasInvalidRows = false;

        $assignmentsTable.find('tbody tr').each(function() {
            if ($(this).children('td, th').length !== expectedColumns) {
                hasInvalidRows = true;
            }
        });

        if (hasInvalidRows) {
            console.warn('Assignments table was not initialized because one or more rows have an incorrect column count.');
            return;
        }

        $assignmentsTable.DataTable({
            pageLength: 25,
            responsive: true,
            order: [[1, 'asc'], [3, 'asc']],
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
            language: {
                search: "",
                searchPlaceholder: "Search assignments...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ assignments",
                emptyTable: "No assignments yet.",
                paginate: {
                    first: '<i class="fas fa-angle-double-left"></i>',
                    last: '<i class="fas fa-angle-double-right"></i>',
                    next: '<i class="fas fa-angle-right"></i>',
                    previous: '<i class="fas fa-angle-left"></i>'
                }
            },
            columnDefs: [
                { orderable: false, targets: [0, 5] },
                { className: 'text-center', targets: [5] }
            ]
        });
    }
});
</script>

<?php require_once "includes/footer.php"; ?> 
