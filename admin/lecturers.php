<?php
$page_title = "Lecturer Management";
require_once "includes/admin.php";
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
require_once "includes/header.php";
?>
<?php
// CRITICAL: Check database connection
if (!isset($db) || !$db || $db->connect_error) {
    echo '<div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Database Error:</strong> Unable to connect to database.<br>
            ' . (isset($db) && $db ? 'Error: ' . htmlspecialchars($db->connect_error) : 'Connection not established') . '
          </div>';
    require_once "includes/footer.php";
    exit;
}

// Verify database is responsive
if (!$db->ping()) {
    echo '<div class="alert alert-danger">
            <i class="fas fa-database me-2"></i>
            <strong>Connection Lost:</strong> Database connection lost. Please check your database server.
          </div>';
    require_once "includes/footer.php";
    exit;
}

// Resolve the Lecturer position id. staff_positions.PosID is a numeric FK to
// positions.PosID — NOT a string code like "LEC001" (that legacy assumption made
// every query below match zero rows). Resolve it dynamically, defaulting to 7.
$lecPosId = 7;
if ($posRes = $db->query("SELECT PosID FROM positions WHERE PosName = 'Lecturer' LIMIT 1")) {
    if ($posRow = $posRes->fetch_object()) { $lecPosId = (int)$posRow->PosID; }
    $posRes->free();
}

// Fetch statistics with error handling
try {
    // Total lecturers
    $total_stmt = $db->prepare("SELECT COUNT(DISTINCT staff_id) as total FROM staff_positions WHERE PosID = ?");
    $total_stmt->bind_param("i", $lecPosId);
    $total_stmt->execute();
    $total_result = $total_stmt->get_result();
    $total_lecturers = $total_result->fetch_object()->total ?? 0;
    $total_stmt->close();

    // Total distinct courses assigned (course_lecturer has no status column;
    // an assignment row existing IS the assignment, matching what the table shows)
    $courses_result = $db->query("SELECT COUNT(DISTINCT course_code) as total FROM course_lecturer");
    if (!$courses_result) {
        throw new Exception("Courses query failed: " . $db->error);
    }
    $total_courses = $courses_result->fetch_object()->total ?? 0;
    $courses_result->free();

    // Gender statistics
    $gender_stats = array('M' => 0, 'F' => 0);
    $gender_stmt = $db->prepare("SELECT sex, COUNT(*) as count FROM staff 
                                  INNER JOIN staff_positions ON staff.staff_id = staff_positions.staff_id 
                                  WHERE staff_positions.PosID = ?
                                  GROUP BY sex");
    $gender_stmt->bind_param("i", $lecPosId);
    $gender_stmt->execute();
    $gender_result = $gender_stmt->get_result();
    while($row = $gender_result->fetch_object()) {
        $gender_stats[$row->sex] = $row->count;
    }
    $gender_stmt->close();

} catch (Exception $e) {
    error_log('lecturers stats error: ' . $e->getMessage());
    echo '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>Unable to load lecturer statistics. Please try again later.</div>';
    $total_lecturers = $total_courses = 0;
    $gender_stats = array('M' => 0, 'F' => 0);
}

// Detect assignment ID column for course_lecturer table
$assignmentIdCol = 'id';
if ($res = $db->query("SHOW COLUMNS FROM course_lecturer LIKE 'id'")) {
    if ($res->num_rows === 0) { $assignmentIdCol = 'course_lecturer_id'; }
    $res->free();
}

// Fetch lecturer data with paired assignments (assignmentId::courseCode)
$records = array();
try {
    $query = "SELECT s.staff_id, s.title, s.Fname, s.Lname, s.sex,
                     GROUP_CONCAT(CONCAT_WS('::', cl.`".$assignmentIdCol."`, c.course_code) SEPARATOR '||') AS assignments
              FROM staff s
              INNER JOIN staff_positions sp ON s.staff_id = sp.staff_id AND sp.PosID = ?
              LEFT JOIN course_lecturer cl ON s.staff_id = cl.staff_id
              LEFT JOIN courses c ON cl.course_code = c.course_code
              GROUP BY s.staff_id, s.title, s.Fname, s.Lname, s.sex
              ORDER BY s.Fname ASC";

    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }

    $stmt->bind_param("i", $lecPosId);
    $stmt->execute();
    $results = $stmt->get_result();
    
    if ($results->num_rows > 0) {
        while($row = $results->fetch_object()) { 
            $records[] = $row; 
        }
    }
    $stmt->close();
    
} catch (Exception $e) {
    error_log('lecturers list error: ' . $e->getMessage());
    echo '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>Unable to load lecturer records. Please try again later.</div>';
    $records = array();
}
?>

<style>
    /* avatar-circle, stat-icon → assets/css/dashboard.css */
    .badge .btn-close.btn-close-sm { width: .5em; height: .5em; opacity: .55; }
    .badge .btn-close.btn-close-sm:hover { opacity: 1; }
</style>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-chalkboard-teacher me-2 text-primary"></i>Lecturer Management</h5>
                <p class="page-subtitle mb-0">Manage academic assignments, faculty profiles, and course allocations</p>
            </div>
            <div class="header-actions d-flex gap-2">
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>

    <!-- Flash messages (e.g. after removing a course assignment) -->
    <?php if (!empty($_SESSION['successMssg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['successMssg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['successMssg']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['errorMssg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($_SESSION['errorMssg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['errorMssg']); ?>
    <?php endif; ?>



    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3"><i class="fas fa-chalkboard-teacher text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($total_lecturers) ?></h3>
                        <p class="text-muted mb-0">Total Lecturers</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3"><i class="fas fa-book text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($total_courses) ?></h3>
                        <p class="text-muted mb-0">Assigned Courses</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-male me-3"><i class="fas fa-mars text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($gender_stats['M'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Male Faculty</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-female me-3"><i class="fas fa-venus text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($gender_stats['F'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Female Faculty</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Lecturers Data Table -->
    <div class="data-table-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-users me-2"></i>Lecturer Directory
                </h5>
                <div class="header-actions">
                    <button class="btn btn-success btn-sm" id="exportExcel">
                        <i class="fas fa-file-excel me-2"></i>Export
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" id="printTable">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($records)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-chalkboard-teacher fa-3x text-muted mb-3 d-block empty-state-icon"></i>
                    <h5 class="text-muted">No lecturers found</h5>
                    <p class="text-muted mb-0">No lecturer records match the current criteria.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table id="lecturerTable" class="table table-hover align-middle table-full-width">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Staff ID</th>
                            <th>Full Name</th>
                            <th>Gender</th>
                            <th>Assigned Courses</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $n = 1; foreach($records as $r):
                            $sexCode = strtoupper(trim((string)($r->sex ?? '')));
                            $isMale = ($sexCode === 'M' || $sexCode === 'MALE');
                            $isFemale = ($sexCode === 'F' || $sexCode === 'FEMALE');
                            $genderLabel = $isMale ? 'Male' : ($isFemale ? 'Female' : 'Unspecified');
                        ?>
                        <tr>
                            <td class="text-muted"><?php echo $n++; ?></td>
                            <td><span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($r->staff_id); ?></span></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle me-3">
                                        <?php echo strtoupper(substr($r->Fname, 0, 1) . substr($r->Lname, 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars(trim($r->title) . ' ' . trim($r->Fname) . ' ' . trim($r->Lname)); ?></div>
                                        <small class="text-muted">Academic Staff</small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($isMale): ?>
                                    <span class="badge bg-blue-subtle text-primary border border-primary-subtle rounded-pill">
                                        <i class="fas fa-mars me-1"></i>Male
                                    </span>
                                <?php elseif ($isFemale): ?>
                                    <span class="badge bg-pink-subtle text-danger border border-danger-subtle rounded-pill">
                                        <i class="fas fa-venus me-1"></i>Female
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark border rounded-pill">
                                        <i class="fas fa-genderless me-1"></i><?php echo htmlspecialchars($genderLabel); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $pairs = array_filter(explode('||', (string)($r->assignments ?? '')));
                                if (empty($pairs)) {
                                    echo '<span class="text-muted small fst-italic">No courses</span>';
                                } else {
                                    foreach ($pairs as $p) {
                                        [$aid, $code] = array_pad(explode('::', $p, 2), 2, '');
                                        if ($code === '') { continue; }
                                        echo '<span class="badge bg-light text-dark border me-1 mb-1 d-inline-flex align-items-center">'
                                            . htmlspecialchars($code);
                                        if ($aid !== '') {
                                            echo '<button type="button" class="btn-close btn-close-sm ms-2 d-print-none" '
                                                . 'style="font-size:.6rem" title="Remove assignment" '
                                                . 'onclick="removeCourse(' . (int)$aid . ')"></button>';
                                        }
                                        echo '</span>';
                                    }
                                }
                                ?>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <a class="btn btn-sm btn-outline-primary" title="View Details" href="view_staff.php?view=<?php echo htmlspecialchars($r->staff_id); ?>">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a class="btn btn-sm btn-outline-info" title="Edit Profile" href="editStaff.php?update=<?php echo htmlspecialchars($r->staff_id); ?>">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a class="btn btn-sm btn-primary" title="Manage Courses" href="assign_course_lecturer.php?staff_id=<?php echo urlencode($r->staff_id); ?>">
                                        <i class="fas fa-chalkboard"></i>
                                    </a>
                                    <button class="btn btn-sm btn-outline-danger" title="Delete Lecturer"
                                        onclick="confirmDeleteStaff('<?php echo htmlspecialchars($r->staff_id); ?>', '<?php echo htmlspecialchars(addslashes($r->Fname . ' ' . $r->Lname)); ?>')">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
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

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
$(document).ready(function(){


    // Initialize DataTable with unified configuration
    const table = $('#lecturerTable').DataTable({
        pageLength: 15,
        order: [[2, "asc"]], // Sort by name by default
        responsive: true,
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
        language: {
            search: "",
            searchPlaceholder: "Search lecturers...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ lecturers",
            infoEmpty: "No lecturers available",
            infoFiltered: "(filtered from _MAX_ total)",
            zeroRecords: '<div class="text-center py-5"><i class="fas fa-chalkboard-teacher fa-3x text-muted mb-3"></i><p class="text-muted">No lecturers match your search criteria</p></div>',
            paginate: {
                first: '<i class="fas fa-angle-double-left"></i>',
                last: '<i class="fas fa-angle-double-right"></i>',
                next: '<i class="fas fa-angle-right"></i>',
                previous: '<i class="fas fa-angle-left"></i>'
            }
        },
        columnDefs: [
            { orderable: false, targets: [4, 5] }, // Disable sorting for courses and actions columns
            { className: 'text-center', targets: 5 },
            { className: 'dt-nowrap', targets: [1, 5] } // Prevent text wrapping on ID and Actions
        ]
    });

    // Export to Excel functionality
    $('#exportExcel').click(function() {
        // Get table data
        const tableData = [];
        
        // Add headers
        tableData.push(['Staff ID', 'Full Name', 'Gender', 'Assigned Courses']);
        
        // Add rows
        table.rows({search: 'applied'}).every(function() {
            const data = this.data();
            const staffId = $(data[1]).text().trim();
            const fullName = $(data[2]).find('.fw-bold').text().trim();
            const gender = $(data[3]).text().trim();
            const courses = $(data[4]).find('.badge').map(function() { return $(this).text(); }).get().join(', ') || 'No courses';
            
            tableData.push([staffId, fullName, gender, courses]);
        });
        
        // Create workbook and worksheet
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(tableData);
        
        // Set column widths
        ws['!cols'] = [
            {wch: 15}, // Staff ID
            {wch: 30}, // Full Name
            {wch: 10}, // Gender
            {wch: 50}  // Courses
        ];
        
        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(wb, ws, 'Lecturers');
        
        // Generate file name with current date
        const date = new Date().toISOString().split('T')[0];
        const fileName = `Lecturers_${date}.xlsx`;
        
        // Save file
        XLSX.writeFile(wb, fileName);
    });

    // Print functionality
    $('#printTable').click(function() {
        const printWindow = window.open('', '_blank');
        const tableHtml = $('#lecturerTable').clone();
        
        // Remove action buttons from print view
        tableHtml.find('td:last-child').remove();
        tableHtml.find('th:last-child').remove();
        
        const printContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Lecturer Directory - Print</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
                <link rel="stylesheet" href="/wucportal/css/project-reusable.css">
                <style>@page{size:A4 portrait;margin:12mm}body{padding:12mm}table{width:100%;border-collapse:collapse}th,td{border:1px solid #444;padding:4px 6px;font-size:10pt}</style>
            </head>
            <body class="print-table-wrap">
                <h1>Lecturer Directory</h1>
                <p>Generated on: ${new Date().toLocaleString()}</p>
                <table class="table table-hover align-middle">
                    ${tableHtml.html()}
                </table>
            </body>
            </html>
        `;
        
        printWindow.document.write(printContent);
        printWindow.document.close();
        
        // Wait for content to load then print
        printWindow.onload = function() {
            printWindow.print();
        };
    });

});

function confirmDeleteStaff(staffId, staffName) {
    Swal.fire({
        title: 'Delete Lecturer?',
        html: `Are you sure you want to delete <strong>${staffName}</strong>?<br><small class="text-danger">This action cannot be undone.</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            // Redirect to your delete handler
            window.location.href = `deleteStaff.php?del=${staffId}`;
        }
    });
}

function removeCourse(courseId) {
    Swal.fire({
        title: 'Remove Course Assignment?',
        text: 'Are you sure you want to remove this course assignment?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, remove',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'deleteCourseLecturer.php';
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'id';
            idInput.value = courseId;
            form.appendChild(idInput);
            
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?php echo wuc_csrf_token(); ?>';
            form.appendChild(csrfInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    });
}
</script>

<?php require_once "includes/footer.php"; ?>


