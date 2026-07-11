<?php
require "includes/admin.php";

// Set page title early so header can use it later
$page_title = "Course Management";

// Include common header
//quire dirname(dirname(__FILE__)) . "/includes/common_header.php";

// Initialize program variable
$program = null;

// Get program details
$program_code = isset($_GET['program']) ? $_GET['program'] : '';
if ($program_code) {
    $program_query = "SELECT * FROM programs WHERE program_code = ?";
    $program_stmt = $db->prepare($program_query);
    $program_stmt->bind_param("s", $program_code);
    $program_stmt->execute();
    $program = $program_stmt->get_result()->fetch_object();
}
require_once dirname(__DIR__) . '/students/includes/period_mode_helper.php';
require_once dirname(__DIR__) . '/includes/helpers/academic_period_helpers.php';
require_once dirname(__DIR__) . '/includes/helpers/course_availability_helpers.php';
$structure = $program ? getProgramPeriodMode($db, (string)$program->program_code) : 'semester';
$periodLabel = wuc_period_label_from_structure($structure, true);
$periodsPerYear = ($structure === 'term') ? 3 : (($structure === 'semester') ? 2 : (($structure === 'duration') ? 4 : 1));

// Handle course addition (before any output)
if (isset($_POST['add_course'])) {
    $course_codes = isset($_POST['course_codes']) ? $_POST['course_codes'] : [];
    $year = max(1, (int)($_POST['year'] ?? 0));
    $deliveryPeriodRaw = trim((string)($_POST['delivery_period'] ?? ''));
    $deliveryPeriod = $deliveryPeriodRaw !== '' ? (int)$deliveryPeriodRaw : null;
    $periodSpecific = !empty($_POST['period_specific']);

    if (empty($course_codes)) {
        $_SESSION['errorMsg'] = "Please select at least one course to add!";
    } elseif (!$program) {
        $_SESSION['errorMsg'] = "Select a valid program before adding courses.";
    } elseif ($year < 1 || $year > 10) {
        $_SESSION['errorMsg'] = "Please select a valid year of study.";
    } elseif ($periodSpecific && ($deliveryPeriod === null || $deliveryPeriod < 1)) {
        $_SESSION['errorMsg'] = "Select a {$periodLabel} when limiting a course to one period only.";
    } else {
        if ($periodSpecific && $deliveryPeriod !== null) {
            $periodCheck = validateCoursePeriodAssignment($db, $program_code, $year, $deliveryPeriod);
            if (!$periodCheck['ok']) {
                $_SESSION['errorMsg'] = $periodCheck['message'];
            }
        }
        if (!isset($_SESSION['errorMsg'])) {
            $added = 0;
            $skipped = 0;
            $failed = 0;

            foreach ($course_codes as $course_code) {
                $course_code = trim($course_code);
                if ($course_code === '') {
                    continue;
                }

                $result = wuc_insert_program_course_assignment(
                    $db,
                    $program_code,
                    $course_code,
                    $year,
                    $deliveryPeriod,
                    $periodSpecific
                );
                if ($result['skipped']) {
                    $skipped++;
                } elseif ($result['ok']) {
                    $added++;
                } else {
                    $failed++;
                }
            }

            if ($added > 0) {
                $msg = "$added course(s) added for Year {$year} (full academic year).";
                if ($skipped > 0) {
                    $msg .= " ({$skipped} already assigned for this year)";
                }
                $_SESSION['successMsg'] = $msg;
                header("Location: courses.php?program=" . urlencode($program_code));
                exit();
            }
            if ($skipped > 0 && $failed === 0) {
                $_SESSION['errorMsg'] = "All selected courses are already assigned to this programme for Year {$year}.";
            } else {
                $_SESSION['errorMsg'] = "Failed to add selected course(s)!";
            }
        }
    }
}

// Get course statistics if program is selected
if ($program) {
    $stats_query = "SELECT
        COUNT(DISTINCT CONCAT(course_code, '-', year)) as total_courses,
        COUNT(DISTINCT course_code) as distinct_courses,
        (SELECT COUNT(DISTINCT Sid) FROM course_registration WHERE course_code IN
            (SELECT course_code FROM program_courses WHERE program_code = ?)) as enrolled_students
    FROM program_courses WHERE program_code = ?";
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->bind_param("ss", $program_code, $program_code);
    $stats_stmt->execute();
    $stats = $stats_stmt->get_result()->fetch_object();

    // Catalogue courses available to add
    $catalogue = [];
    $cat_query = "SELECT course_code, course_name FROM courses ORDER BY course_code";
    $cat_stmt = $db->prepare($cat_query);
    $cat_stmt->execute();
    $cat_result = $cat_stmt->get_result();
    while ($crow = $cat_result->fetch_object()) {
        $catalogue[] = $crow;
    }
}

// Include header only after all pre-output logic is done
require "includes/header.php";
?>
<style>
    /* stat-card, stat-icon → assets/css/dashboard.css */
    .admin-card { border: 1px solid #f0f0f0 !important; transition: all 0.3s ease !important; background: #fff !important; }
    .admin-card:hover { border-color: var(--primary, #1B2A4A) !important; background: #fdfdff !important; transform: scale(1.02); }
    .modal-header.admin-modal { background: linear-gradient(135deg, #6f42c1 0%, #4e2a84 100%) !important; border: none !important; }
</style>
<?php
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-book me-2 text-primary"></i>Course Management</h5>
                <p class="page-subtitle mb-0"><?= $program ? 'Managing courses for <strong>' . htmlspecialchars($program->program_name) . '</strong>' : 'Select a program to manage its academic courses' ?></p>
            </div>
            <div class="header-actions d-flex gap-2">
                <?php if ($program): ?>
                    <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addCourse">
                        <i class="fas fa-plus me-1"></i>Add Course
                    </button>
                    <a href="courses.php" class="btn btn-outline-primary shadow-sm">
                        <i class="fas fa-list me-1"></i>All Programs
                    </a>
                <?php else: ?>
                    <a href="course_program_mgmt.php" class="btn btn-outline-primary shadow-sm">
                        <i class="fas fa-sitemap me-1"></i>Mapping Overview
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if(isset($_SESSION['successMsg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php 
            echo $_SESSION['successMsg'];
            unset($_SESSION['successMsg']); 
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if(isset($_SESSION['errorMsg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php 
            echo $_SESSION['errorMsg'];
            unset($_SESSION['errorMsg']); 
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
    <?php endif; ?>

<?php if ($program): ?>
    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-xl-4 col-md-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3 text-white"><i class="fas fa-graduation-cap"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= htmlspecialchars($program->program_code) ?></h4>
                        <p class="text-muted mb-0"><?= ucfirst($program->program_type ?? 'Program') ?></p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3 text-white"><i class="fas fa-book"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($stats->total_courses) ?></h4>
                        <p class="text-muted mb-0">Registered Courses</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3 text-white"><i class="fas fa-users"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($stats->enrolled_students) ?></h4>
                        <p class="text-muted mb-0">Total Enrolled</p>
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
                    <i class="fas fa-list me-2"></i>Program Courses
                </h5>
                <div class="header-actions">
                    <button class="btn btn-sm btn-success" onclick="exportToExcel()">
                        <i class="fas fa-file-excel me-1"></i>Export
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="window.print()">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="coursesTable" class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>No.</th>
                            <th>Course Code</th>
                            <th>Course Name</th>
                            <th>Year</th>
                            <th>Availability</th>
                            <th>Credits</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
            <tbody>
                <?php
                $number = 1;
                $courses_query = "SELECT pc.id, pc.course_code, pc.year, pc.semester,
                                         COALESCE(pc.is_full_year, 1) AS is_full_year,
                                         COALESCE(pc.is_period_specific, 0) AS is_period_specific,
                                         c.course_name, c.credits
                                  FROM program_courses pc
                                  INNER JOIN courses c ON c.course_code = pc.course_code
                                  WHERE pc.program_code = ?
                                  ORDER BY pc.year, pc.course_code";
                $courses_stmt = $db->prepare($courses_query);
                $courses_stmt->bind_param("s", $program_code);
                $courses_stmt->execute();
                $courses_result = $courses_stmt->get_result();

                        while ($course = $courses_result->fetch_object()): 
                        ?>
                            <tr>
                                <td><?php echo $number++; ?></td>
                                <td><?php echo htmlspecialchars($course->course_code); ?></td>
                                <td><?php echo htmlspecialchars($course->course_name); ?></td>
                                <td><?php echo (int)$course->year; ?></td>
                                <td>
                                    <?php if ((int)$course->is_period_specific === 1): ?>
                                        <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($periodLabel); ?> <?php echo (int)$course->semester; ?> only</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Full academic year</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars((string)($course->credits ?? '')); ?></td>
                                <td>
                                    <div class="d-flex justify-content-center gap-2">
                                        <a href="edit_course.php?program=<?php echo urlencode($program_code); ?>&course=<?php echo urlencode($course->course_code); ?>"
                                           class="btn btn-sm btn-primary rounded-pill">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="delete_course.php?program=<?php echo urlencode($program_code); ?>&course=<?php echo urlencode($course->course_code); ?>"
                                           class="btn btn-sm btn-danger rounded-pill"
                                           onclick="return confirm('Are you sure you want to delete this course?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
    </div>

<?php else: ?>
    <!-- Program Selection Grid -->
    <div class="row g-4 admin-section">
    <?php
    // Get all programs
        $programs_query = "SELECT p.*, 
            COUNT(pc.course_code) as course_count 
            FROM programs p 
            LEFT JOIN program_courses pc ON p.program_code = pc.program_code 
            GROUP BY p.program_code 
            ORDER BY p.program_name";
    $programs_result = $db->query($programs_query);

        if ($programs_result->num_rows > 0):
            while ($prog = $programs_result->fetch_object()):
        ?>
            <div class="col-xl-4 col-md-6">
                <a href="courses.php?program=<?= urlencode($prog->program_code) ?>"
                   class="card h-100 shadow-sm admin-card text-decoration-none border-0 overflow-hidden">
                    <div class="position-absolute top-0 end-0 p-3 opacity-25">
                        <i class="fas fa-graduation-cap fa-4x text-primary-subtle"></i>
                    </div>
                    <div class="card-body p-4 position-relative">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stat-icon bg-primary-subtle text-primary me-3">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <span class="badge bg-light text-primary border"><?= $prog->program_code ?></span>
                        </div>
                        <h5 class="card-title fw-bold text-dark mb-1">
                            <?= htmlspecialchars($prog->program_name) ?>
                        </h5>
                        <p class="text-muted small mb-3">
                            <?= ucfirst($prog->program_type ?? 'Degree') ?> Program
                        </p>
                        <hr class="my-3 opacity-25">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small">
                                <i class="fas fa-book-open me-1"></i> <?= number_format($prog->course_count) ?> Courses
                            </span>
                            <span class="text-primary small fw-bold">Manage <i class="fas fa-arrow-right ms-1"></i></span>
                        </div>
                    </div>
                </a>
            </div>
            <?php
            endwhile;
        else:
        ?>
            <div class="col-12">
                <div class="alert alert-info d-flex align-items-center" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <div>No programs found. Please add programs first.</div>
                </div>
        </div>
        <?php endif; ?>
</div>
<?php endif; ?>
</div>

<!-- Add Course Modal -->
<?php if ($program): ?>
<div class="modal fade" id="addCourse" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header admin-modal">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle"></i> Add New Course
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label d-block fw-bold">Select Courses</label>
                        <!-- Search input for live filtering -->
                        <div class="input-group mb-2 shadow-sm">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" id="courseSearch" class="form-control" placeholder="Search courses by code or name...">
                        </div>
                        
                        <!-- Scrollable checkbox list -->
                        <div class="form-control p-0" style="max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6;" id="courseCheckboxList">
                            <?php if (empty($catalogue)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-info-circle fa-2x mb-2 d-block"></i> No courses available to add.
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($catalogue as $cat): ?>
                                        <label class="list-group-item list-group-item-action d-flex align-items-center py-2 px-3 course-item" style="cursor: pointer;" data-search="<?= htmlspecialchars(strtolower($cat->course_code . ' ' . $cat->course_name)) ?>">
                                            <input class="form-check-input course-checkbox me-3" type="checkbox" name="course_codes[]" value="<?= htmlspecialchars($cat->course_code) ?>">
                                            <span class="font-monospace text-dark">
                                                <strong><?= htmlspecialchars($cat->course_code) ?></strong> - <?= htmlspecialchars($cat->course_name) ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="form-text d-flex justify-content-between align-items-center mt-2 px-1">
                            <span><?= count($catalogue) ?> course(s) available.</span>
                            <div>
                                <button type="button" class="btn btn-link btn-sm p-0 me-2 text-decoration-none" onclick="toggleSelectAllCourses(true)">Select All</button>
                                <button type="button" class="btn btn-link btn-sm p-0 text-muted text-decoration-none" onclick="toggleSelectAllCourses(false)">Clear</button>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Year of Study</label>
                        <select class="form-select" name="year" required>
                            <option value="" disabled selected>Select year of study</option>
                            <?php
                            $maxYears = max(3, min(10, (int)ceil((float)($program->program_duration ?? 1))));
                            for ($i = 1; $i <= $maxYears; $i++):
                            ?>
                                <option value="<?php echo $i; ?>">Year <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                        <div class="form-text">Courses are assigned to the programme for the full academic year by default.</div>
                    </div>

                    <div class="mb-3 border rounded p-3 bg-light">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="period_specific" value="1" id="periodSpecificToggle">
                            <label class="form-check-label fw-bold" for="periodSpecificToggle">
                                Limit to one <?php echo htmlspecialchars(strtolower($periodLabel)); ?> only
                            </label>
                        </div>
                        <div class="form-text mb-2">Use only for short courses or other approved exceptions. Most courses should run for the full year.</div>
                        <label class="form-label text-muted small mb-1">Primary delivery <?php echo htmlspecialchars(strtolower($periodLabel)); ?> (optional)</label>
                        <select class="form-select" name="delivery_period" id="deliveryPeriodSelect" disabled>
                            <option value="">— Not limited to one period —</option>
                            <?php
                            for ($i = 1; $i <= $periodsPerYear; $i++):
                            ?>
                                <option value="<?php echo $i; ?>"><?php echo htmlspecialchars($periodLabel); ?> <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="add_course" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Course(s)
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function() {
    // Initialize DataTable only if table exists
    if ($('#coursesTable').length) {
        $('#coursesTable').DataTable({
            pageLength: 25,
            responsive: true,
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
            language: {
                search: "",
                searchPlaceholder: "Search courses...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ courses",
                paginate: {
                    first: '<i class="fas fa-angle-double-left"></i>',
                    last: '<i class="fas fa-angle-double-right"></i>',
                    next: '<i class="fas fa-angle-right"></i>',
                    previous: '<i class="fas fa-angle-left"></i>'
                }
            },
            columnDefs: [
                // Disable sorting on the Actions column
                { orderable: false, targets: [6] }
            ]
        });
    }

    // Initialize Bootstrap modal only if element exists
    var modalEl = document.getElementById('addCourse');
    if (modalEl) {
        var addCourseModal = new bootstrap.Modal(modalEl, {
            keyboard: false
        });
    }

    // Live filter search for courses in modal
    $('#courseSearch').on('keyup', function() {
        var query = $(this).val().toLowerCase();
        $('#courseCheckboxList .course-item').each(function() {
            var text = $(this).attr('data-search') || '';
            if (text.indexOf(query) !== -1) {
                $(this).removeClass('d-none').addClass('d-flex');
            } else {
                $(this).removeClass('d-flex').addClass('d-none');
            }
        });
    });
});

function toggleSelectAllCourses(checked) {
    $('.course-checkbox:visible').prop('checked', checked);
}

function exportToExcel() {
    let table = document.querySelector('#coursesTable');
    let html = table.outerHTML;
    let url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    let downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    downloadLink.href = url;
    downloadLink.download = 'courses.xls';
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require_once "includes/footer.php"; ?>
