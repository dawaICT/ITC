<?php
require "includes/nav.php";
require_once dirname(__DIR__) . '/db/connect.php';

// Environment-controlled error reporting
$WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
if ($WUC_ENV === 'development' || isset($_GET['debug'])) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

function admissions_column_exists(mysqli $db, string $table, string $column): bool {
    $sql = "SHOW COLUMNS FROM `" . $db->real_escape_string($table) . "` LIKE '" . $db->real_escape_string($column) . "'";
    $result = $db->query($sql);
    return $result && $result->num_rows > 0;
}

$programStudyModeExpr = admissions_column_exists($db, 'programs', 'study_mode')
    ? "COALESCE(p.study_mode, 'semester')"
    : "'semester'";
$pcHasCredits = admissions_column_exists($db, 'program_courses', 'credits');
$courseHasCredits = admissions_column_exists($db, 'courses', 'credits');
if ($pcHasCredits && $courseHasCredits) {
    $courseCreditsExpr = "COALESCE(pc.credits, c.credits, 0)";
} elseif ($pcHasCredits) {
    $courseCreditsExpr = "COALESCE(pc.credits, 0)";
} elseif ($courseHasCredits) {
    $courseCreditsExpr = "COALESCE(c.credits, 0)";
} else {
    $courseCreditsExpr = "0";
}
$programCourseNameExpr = admissions_column_exists($db, 'program_courses', 'course_name')
    ? 'pc.course_name'
    : 'pc.course_code';
$programCourseSemesterExpr = admissions_column_exists($db, 'program_courses', 'semester')
    ? 'pc.semester'
    : '1';

// Fetch statistics
$stats = [
    'total_programs' => 0,
    'total_courses' => 0,
    'semester_total' => 0,
    'term_total' => 0,
];

// Total programs and courses
$result = $db->query("SELECT COUNT(DISTINCT program_code) as cnt FROM program_courses");
if ($result && $row = $result->fetch_assoc()) $stats['total_programs'] = (int)$row['cnt'];

$result = $db->query("SELECT COUNT(*) as cnt FROM program_courses");
if ($result && $row = $result->fetch_assoc()) $stats['total_courses'] = (int)$row['cnt'];

// Semester-based courses count
$result = $db->query("SELECT COUNT(*) as cnt FROM program_courses pc JOIN programs p ON pc.program_code = p.program_code WHERE {$programStudyModeExpr} = 'semester'");
if ($result && $row = $result->fetch_assoc()) $stats['semester_total'] = (int)$row['cnt'];

// Term-based courses count
$result = $db->query("SELECT COUNT(*) as cnt FROM program_courses pc JOIN programs p ON pc.program_code = p.program_code WHERE {$programStudyModeExpr} = 'term'");
if ($result && $row = $result->fetch_assoc()) $stats['term_total'] = (int)$row['cnt'];

// Fetch all program courses with related information
$sql = "SELECT pc.id, pc.program_code, pc.course_code, {$programCourseSemesterExpr} AS semester, {$courseCreditsExpr} AS credits,
               p.program_name,
               {$programStudyModeExpr} as study_mode,
               COALESCE(c.course_name, {$programCourseNameExpr}, pc.course_code) as course_name
        FROM program_courses pc
        LEFT JOIN programs p ON pc.program_code = p.program_code
        LEFT JOIN courses c ON pc.course_code = c.course_code
        ORDER BY p.program_name, semester, course_name";

$result = $db->query($sql);
$semester_courses = [];
$db_error = null;

if (!$result) {
    $db_error = $db->error;
} else {
    while ($row = $result->fetch_assoc()) {
        $row['study_mode'] = !empty($row['study_mode']) ? $row['study_mode'] : 'semester';
        $semester_courses[] = $row;
    }
}

// Group courses by program and period
$grouped_courses = [];
foreach ($semester_courses as $course) {
    $key = $course['program_code'] . '_' . $course['semester'];
    if (!isset($grouped_courses[$key])) {
        $grouped_courses[$key] = [
            'program_name' => $course['program_name'] ?: $course['program_code'],
            'program_code' => $course['program_code'],
            'semester' => $course['semester'],
            'study_mode' => $course['study_mode'],
            'courses' => []
        ];
    }
    $grouped_courses[$key]['courses'][] = $course;
}
?>

<div class="container-fluid px-4 py-4 portal-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header admin-section mb-3 d-flex align-items-center justify-content-between">
        <h3 class="dashboard-title mb-0"><i class="fas fa-layer-group me-2"></i>Program Courses</h3>
    </div>

    <!-- Statistics Cards Row -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card admin-card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">Total Programs</div>
                            <div class="h4 mb-0 fw-bold text-primary"><?= $stats['total_programs'] ?></div>
                            <div class="small text-muted">Active programs</div>
                        </div>
                        <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                            <i class="fas fa-graduation-cap fa-2x text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card admin-card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">Total Courses</div>
                            <div class="h4 mb-0 fw-bold text-success"><?= $stats['total_courses'] ?></div>
                            <div class="small text-muted">Assigned courses</div>
                        </div>
                        <div class="bg-success bg-opacity-10 rounded-circle p-3">
                            <i class="fas fa-book fa-2x text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card admin-card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">Semester Courses</div>
                            <div class="h4 mb-0 fw-bold text-info"><?= $stats['semester_total'] ?></div>
                            <div class="small text-muted">Semester-based</div>
                        </div>
                        <div class="bg-info bg-opacity-10 rounded-circle p-3">
                            <i class="fas fa-calendar-alt fa-2x text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card admin-card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">Term Courses</div>
                            <div class="h4 mb-0 fw-bold" style="color: #6f42c1;"><?= $stats['term_total'] ?></div>
                            <div class="small text-muted">Term-based</div>
                        </div>
                        <div class="rounded-circle p-3" style="background-color: rgba(111,66,193,0.1);">
                            <i class="fas fa-layer-group fa-2x" style="color: #6f42c1;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="data-table-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-layer-group me-2"></i>Program Courses</h5>
                <div class="d-flex gap-2">
                    <div class="input-group input-group-sm" style="max-width: 300px;">
                        <span class="input-group-text bg-white border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" class="form-control border-start-0 ps-0" id="searchInput"
                               placeholder="Search courses...">
                    </div>
                    <select class="form-select form-select-sm" id="periodFilter" style="max-width: 180px;">
                        <option value="">All Periods</option>
                        <optgroup label="Semesters">
                            <option value="semester_1">Semester 1</option>
                            <option value="semester_2">Semester 2</option>
                        </optgroup>
                        <optgroup label="Terms">
                            <option value="term_1">Term 1</option>
                            <option value="term_2">Term 2</option>
                            <option value="term_3">Term 3</option>
                        </optgroup>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if ($db_error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Error loading program courses: <?= htmlspecialchars($db_error) ?>
                </div>
            <?php elseif (empty($grouped_courses)): ?>
                <div class="text-center py-5">
                    <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-4" style="width: 100px; height: 100px;">
                        <i class="fas fa-graduation-cap fa-3x text-primary"></i>
                    </div>
                    <h4 class="text-muted mb-2">No Program Courses Found</h4>
                    <p class="text-muted">No courses have been assigned to any programs yet. Please contact the administrator.</p>
                </div>
            <?php else: ?>
                <div class="row" id="coursesContainer">
                    <?php foreach ($grouped_courses as $group): 
                        $studyMode = $group['study_mode'] ?? 'semester';
                        $periodNum = $group['semester'];

                        if ($studyMode === 'term') {
                            $periodLabel = 'Term ' . $periodNum;
                            $termColors = [
                                1 => ['bg' => 'bg-purple', 'text' => 'text-white', 'icon' => 'fa-calendar-day'],
                                2 => ['bg' => 'bg-orange', 'text' => 'text-white', 'icon' => 'fa-calendar-week'],
                                3 => ['bg' => 'bg-teal', 'text' => 'text-white', 'icon' => 'fa-calendar-check']
                            ];
                            $color = $termColors[$periodNum] ?? ['bg' => 'bg-secondary', 'text' => 'text-white', 'icon' => 'fa-calendar'];
                        } else {
                            $periodLabel = 'Semester ' . $periodNum;
                            $semColors = [
                                1 => ['bg' => 'bg-info', 'text' => 'text-white', 'icon' => 'fa-calendar-check'],
                                2 => ['bg' => 'bg-success', 'text' => 'text-white', 'icon' => 'fa-calendar-alt']
                            ];
                            $color = $semColors[$periodNum] ?? ['bg' => 'bg-primary', 'text' => 'text-white', 'icon' => 'fa-calendar'];
                        }

                        $totalCredits = array_sum(array_column($group['courses'], 'credits'));
                    ?>
                    <div class="col-lg-6 col-xl-4 mb-4 course-card-item" 
                         data-program="<?= htmlspecialchars($group['program_name']) ?>"
                         data-study-mode="<?= htmlspecialchars($studyMode) ?>"
                         data-period="<?= $periodNum ?>">
                        <div class="card h-100 border-0 shadow-sm hover-lift">
                            <div class="card-header <?= $color['bg'] ?> <?= $color['text'] ?> position-relative">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1">
                                            <i class="fas fa-graduation-cap me-2"></i>
                                            <?= htmlspecialchars($group['program_name']) ?>
                                        </h6>
                                        <small class="opacity-85">
                                            <i class="fas <?= $color['icon'] ?> me-1"></i>
                                            <?= $periodLabel ?>
                                            <span class="badge bg-white bg-opacity-25 text-dark ms-1" style="font-size: 0.65rem;"><?= ucfirst($studyMode) ?>-based</span>
                                        </small>
                                    </div>
                                    <div class="badge bg-white bg-opacity-20 text-white rounded-pill px-2 py-1">
                                        <?= count($group['courses']) ?> courses
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="courses-list">
                                    <?php foreach ($group['courses'] as $index => $course): 
                                        $borderClass = $index < count($group['courses']) - 1 ? 'border-bottom' : '';
                                        $bgClass = $index % 2 === 0 ? 'bg-light' : '';
                                    ?>
                                    <div class="course-item p-3 <?= $borderClass ?> <?= $bgClass ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="flex-grow-1">
                                                <div class="d-flex align-items-center mb-1">
                                                    <span class="badge bg-primary me-2"><?= htmlspecialchars($course['course_code']) ?></span>
                                                    <span class="badge bg-secondary"><?= $course['credits'] ?> credits</span>
                                                </div>
                                                <h6 class="mb-0 text-dark"><?= htmlspecialchars($course['course_name']) ?></h6>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="card-footer bg-light text-muted">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small>
                                        <i class="fas fa-book-open me-1"></i>
                                        <?= count($group['courses']) ?> course(s) assigned
                                    </small>
                                    <small class="text-end">
                                        <i class="fas fa-clock me-1"></i>
                                        Total Credits: <?= $totalCredits ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- All styles (.hover-lift, .course-item, .courses-list, .bg-purple/orange/teal) 
     are loaded from /admin/css/admin-dashboard.css via nav_unified.php -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const periodFilter = document.getElementById('periodFilter');

    function filterCards() {
        const searchTerm = searchInput.value.toLowerCase().trim();
        const filterValue = periodFilter.value; // e.g. "semester_1", "term_2", or ""
        const cards = document.querySelectorAll('.course-card-item');

        cards.forEach(card => {
            const program = (card.getAttribute('data-program') || '').toLowerCase();
            const studyMode = card.getAttribute('data-study-mode') || 'semester';
            const period = card.getAttribute('data-period') || '';
            const cardText = card.textContent.toLowerCase();

            let show = true;

            // Search filter
            if (searchTerm && !program.includes(searchTerm) && !cardText.includes(searchTerm)) {
                show = false;
            }

            // Period filter (format: "semester_1", "term_2", etc.)
            if (filterValue) {
                const [filterMode, filterNum] = filterValue.split('_');
                if (studyMode !== filterMode || period !== filterNum) {
                    show = false;
                }
            }

            card.style.display = show ? '' : 'none';
        });
    }

    if (searchInput) searchInput.addEventListener('input', filterCards);
    if (periodFilter) periodFilter.addEventListener('change', filterCards);
});
</script>

<?php require "includes/footer.php"; ?>

