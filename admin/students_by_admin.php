<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once '../includes/db_connect.php';
$page_title = "Student Management";
require_once "includes/admin.php";

if (!isset($db)) {
    die("Database connection not established.");
}

/**
 * STATISTICS SECTION
 * Optimized to fetch counts efficiently
 */
$stats_query = $db->query("
    SELECT
        (SELECT COUNT(*) FROM students) as total,
        (SELECT COUNT(*) FROM students WHERE sex='M') as male,
        (SELECT COUNT(*) FROM students WHERE sex='F') as female
");
$counts = $stats_query->fetch_object();
$total_students  = $counts->total ?? 0;
$male_students   = $counts->male ?? 0;
$female_students = $counts->female ?? 0;

// Program statistics (only need the distinct program count for the stat card)
$program_stats = [];
$program_result = $db->query("SELECT p.program_name, COUNT(sp.Sid) as count
                              FROM programs p
                              LEFT JOIN student_program sp ON p.program_code = sp.program_code
                              GROUP BY p.program_name");
if ($program_result) {
    while($row = $program_result->fetch_object()) {
        $program_stats[$row->program_name] = $row->count;
    }
}

require_once 'includes/header.php';
?>
<style>
    /* Page-specific overrides — shared components in assets/css/dashboard.css */
    .page-title { letter-spacing: -0.02em; font-weight: 800 !important; }
    .action-btn-card i {
        font-size: 1.8rem;
        background: linear-gradient(135deg, var(--primary, #1B2A4A), var(--secondary, #C8102E));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin-bottom: 0.75rem;
    }
</style>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-user-graduate me-2 text-primary"></i>Student Registry Management</h5>
                <p class="page-subtitle mb-0">Total of <?= number_format($total_students) ?> students enrolled across all programs</p>
            </div>
            <div class="header-actions d-flex gap-2">
                <a href="manage_admitted_students.php" class="btn btn-outline-primary">
                    <i class="fas fa-user-check me-1"></i>Admitted
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-home me-1"></i>Home
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3 text-white"><i class="fas fa-users"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($total_students) ?></h3>
                        <p class="text-muted mb-0">Total Students</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3 text-white"><i class="fas fa-mars"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($male_students) ?></h3>
                        <p class="text-muted mb-0">Male Students</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-danger me-3 text-white"><i class="fas fa-venus"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($female_students) ?></h3>
                        <p class="text-muted mb-0">Female Students</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3 text-white"><i class="fas fa-graduation-cap"></i></div>
                    <div>
                        <h3 class="mb-0"><?= count($program_stats) ?></h3>
                        <p class="text-muted mb-0">Total Programs</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions Grid -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <a href="applicants.php" class="action-btn-card">
                <i class="fas fa-users"></i>
                <span>Applicants</span>
                <small>Review Applications</small>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="admitEnrolled_student.php" class="action-btn-card">
                <i class="fas fa-user-check"></i>
                <span>Admit Student</span>
                <small>Enroll Applicant</small>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="editStud_by_prog.php" class="action-btn-card">
                <i class="fas fa-layer-group"></i>
                <span>Batch Edit</span>
                <small>Mass Update Data</small>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="regNewStud.php" class="action-btn-card">
                <i class="fas fa-user-plus"></i>
                <span>Register New</span>
                <small>Registry Entry</small>
            </a>
        </div>
    </div>

    <!-- Main Table Card -->
    <div class="card border-0 shadow-sm data-table-card mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <h5 class="mb-0 text-primary">
                    <i class="fas fa-list me-2"></i>Registry Records
                </h5>
            </div>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="fas fa-download me-1"></i>Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                    <li><a class="dropdown-item" href="export_students.php"><i class="fas fa-file-excel me-2"></i>Excel</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="#" onclick="window.print(); return false;"><i class="fas fa-print me-2"></i>Print</a></li>
                </ul>
            </div>
        </div>
        <div class="card-body p-0">
            <?php
            $sql = "SELECT s.SID, s.Fname, s.Lname, s.sex, s.email,
                           COALESCE(sp.intake, s.intake) as intake,
                           COALESCE(sp.program_code, s.program) as program_code,
                           COALESCE(p.program_name, sc.course_name) as program_name
                    FROM students s
                    LEFT JOIN student_program sp ON s.SID = sp.Sid
                    LEFT JOIN programs p ON COALESCE(sp.program_code, s.program) = p.program_code
                    LEFT JOIN short_courses sc ON COALESCE(sp.program_code, s.program) = sc.course_code
                    ORDER BY s.SID ASC";
            $results = $db->query($sql);
            $rows = $results ? $results->fetch_all(MYSQLI_ASSOC) : [];
            ?>
            <?php if (!empty($rows)): ?>
            <div class="table-responsive">
                <table id="myTable" class="table table-hover align-middle mb-0" width="100%">
                    <thead class="table-light">
                        <tr class="text-uppercase small-header">
                            <th width="4%" class="text-center">#</th>
                            <th width="10%">Student ID</th>
                            <th width="26%">Student</th>
                            <th width="10%">Gender</th>
                            <th width="24%">Program</th>
                            <th width="10%">Intake</th>
                            <th width="10%" class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $index => $r):
                            $initials = strtoupper(substr($r['Fname'] ?? '', 0, 1) . substr($r['Lname'] ?? '', 0, 1));
                            $isMale = ($r['sex'] ?? '') === 'M';
                        ?>
                        <tr>
                            <td class="text-center fw-bold text-muted"><?= $index + 1 ?></td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace">
                                    <?= htmlspecialchars($r['SID']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle me-3"><?= htmlspecialchars($initials) ?></div>
                                    <div>
                                        <div class="fw-bold"><?= htmlspecialchars($r['Fname'] . ' ' . $r['Lname']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($r['email'] ?? '') ?></small>
                                    </div>
                                </div>
                            </td>
                            <td data-gender="<?= htmlspecialchars($r['sex'] ?? '') ?>">
                                <?php if ($isMale): ?>
                                    <span class="badge bg-blue-subtle text-primary border border-primary-subtle rounded-pill">
                                        <i class="fas fa-mars me-1"></i>Male
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-pink-subtle text-danger border border-danger-subtle rounded-pill">
                                        <i class="fas fa-venus me-1"></i>Female
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td data-program="<?= htmlspecialchars($r['program_name'] ?? '') ?>">
                                <div class="text-truncate fw-semibold text-primary" style="max-width: 240px;" title="<?= htmlspecialchars($r['program_name'] ?? '') ?>">
                                    <?= htmlspecialchars($r['program_name'] ?? 'No Program') ?>
                                </div>
                                <small class="text-muted font-monospace"><?= htmlspecialchars($r['program_code'] ?? '') ?></small>
                            </td>
                            <td data-intake="<?= htmlspecialchars($r['intake'] ?? '') ?>">
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($r['intake'] ?? 'N/A') ?></span>
                            </td>
                            <td class="text-end pe-3">
                                <div class="btn-group btn-group-sm action-btns" role="group" aria-label="Student actions">
                                    <a href="view_student.php?view=<?= urlencode($r['SID']) ?>" class="btn btn-outline-secondary" title="View student" aria-label="View student">
                                        <i class="fas fa-eye"></i><span class="d-none d-lg-inline ms-1">View</span>
                                    </a>
                                    <a href="editStudent.php?update=<?= urlencode($r['SID']) ?>" class="btn btn-outline-primary" title="Edit student" aria-label="Edit student">
                                        <i class="fas fa-pen"></i><span class="d-none d-lg-inline ms-1">Edit</span>
                                    </a>
                                    <button type="button" onclick="deleteStudent('<?= htmlspecialchars(addslashes($r['SID']), ENT_QUOTES) ?>')" class="btn btn-outline-danger" title="Delete student" aria-label="Delete student">
                                        <i class="fas fa-trash-alt"></i><span class="d-none d-lg-inline ms-1">Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center py-5">
                <div class="mb-3">
                    <span class="fa-stack fa-2x text-muted">
                        <i class="fas fa-circle fa-stack-2x opacity-25"></i>
                        <i class="fas fa-user-graduate fa-stack-1x"></i>
                    </span>
                </div>
                <h5>No Students Found</h5>
                <p class="text-muted">Students will appear here once they are registered or admitted.</p>
                <a href="regNewStud.php" class="btn btn-primary rounded-pill px-4">
                    <i class="fas fa-user-plus me-2"></i>Register New Student
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable (layout consistent with manage_admitted_students.php)
    if ($.fn.DataTable && $('#myTable').length) {
        $('#myTable').DataTable({
            responsive: true,
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            order: [[1, 'asc']],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search students...",
                lengthMenu: "Show _MENU_",
                info: "Showing _START_ to _END_ of _TOTAL_ students",
                emptyTable: "No students found",
                zeroRecords: "No matching students found"
            },
            columnDefs: [
                { orderable: false, targets: -1 },
                { responsivePriority: 1, targets: [1, 2, -1] }
            ],
            dom: '<"row align-items-center mb-3"<"col-md-6"l><"col-md-6"f>>' +
                 '<"table-responsive"t>' +
                 '<"row align-items-center mt-3"<"col-md-5"i><"col-md-7"p>>'
        });
    }

    // Delete logic (SweetAlert2 with native confirm fallback)
    window.deleteStudent = function(id) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Are you sure?',
                html: "You are about to delete student <strong>" + id + "</strong>. This cannot be undone!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-trash-alt me-1"></i>Yes, delete it!',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'delete_student.php?sid=' + encodeURIComponent(id);
                }
            });
        } else if (confirm('Permanently delete student ' + id + '?')) {
            window.location.href = 'delete_student.php?sid=' + encodeURIComponent(id);
        }
    };
});
</script>

<?php require 'includes/footer.php'; ?>
