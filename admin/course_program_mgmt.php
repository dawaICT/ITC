<?php
require_once "includes/admin.php";
require_once "includes/header.php";

function academic_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function academic_column_exists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function academic_count(mysqli $db, string $sql): int
{
    if ($res = $db->query($sql)) {
        $row = $res->fetch_assoc();
        $res->free();
        return (int)($row['c'] ?? 0);
    }
    return 0;
}

$programCount = academic_table_exists($db, 'programs')
    ? academic_count($db, 'SELECT COUNT(*) AS c FROM programs')
    : 0;

$activePrograms = $programCount;
if (academic_column_exists($db, 'programs', 'is_active')) {
    $activePrograms = academic_count($db, 'SELECT COUNT(*) AS c FROM programs WHERE is_active = 1 OR is_active IS NULL');
} elseif (academic_column_exists($db, 'programs', 'status')) {
    $activePrograms = academic_count($db, "SELECT COUNT(*) AS c FROM programs WHERE LOWER(status) = 'active'");
}

// Course catalogue count and programme-course assignment count are separate
// workflows. Counting assignments as "courses" made the dashboard misleading.
$courseCount = academic_table_exists($db, 'courses')
    ? academic_count($db, 'SELECT COUNT(*) AS c FROM courses')
    : 0;
$programCourseCount = academic_table_exists($db, 'program_courses')
    ? academic_count($db, 'SELECT COUNT(*) AS c FROM program_courses')
    : 0;

$workflowGroups = [
    [
        'title' => 'Catalogue & Structure',
        'description' => 'Define programmes and course records before attaching courses to a programme.',
        'items' => [
            ['href' => 'programs.php', 'icon' => 'fas fa-graduation-cap', 'title' => 'Programs', 'desc' => 'Configure programme codes, types, duration and study mode'],
            ['href' => 'course_catalogue.php', 'icon' => 'fas fa-book', 'title' => 'Course Catalogue', 'desc' => 'Maintain course codes, names, credits and fees'],
            ['href' => 'courses.php', 'icon' => 'fas fa-book-open', 'title' => 'Program Courses', 'desc' => 'Attach courses to programmes, years and periods'],
            ['href' => 'course_prerequisites.php', 'icon' => 'fas fa-project-diagram', 'title' => 'Prerequisites', 'desc' => 'Set course relationship and progression rules'],
        ],
    ],
    [
        'title' => 'Short Courses & Intakes',
        'description' => 'Manage flexible training catalogues, intakes, batches and short-course enrolment.',
        'items' => [
            ['href' => 'short_courses.php', 'icon' => 'fas fa-certificate', 'title' => 'Training Catalogue', 'desc' => 'ITC courses by category, level, duration and fee'],
            ['href' => 'itc_intakes.php', 'icon' => 'fas fa-calendar-check', 'title' => 'Training Intakes', 'desc' => 'Admission windows, offerings and training batches'],
            ['href' => 'short_course_registration.php', 'icon' => 'fas fa-user-check', 'title' => 'Short Course Registration', 'desc' => 'Enroll trainees into short-course offerings'],
        ],
    ],
    [
        'title' => 'Scheduling & Registration',
        'description' => 'Configure registration periods, register students and assign individual courses.',
        'items' => [
            ['href' => 'registration_setup.php', 'icon' => 'fas fa-tools', 'title' => 'Registration Setup', 'desc' => 'Open and configure registration periods'],
            ['href' => 'semester_registration.php', 'icon' => 'fas fa-user-edit', 'title' => 'Term Registration', 'desc' => 'Register students for semesters or terms'],
            ['href' => 'courseReg.php', 'icon' => 'fas fa-clipboard-list', 'title' => 'Course Enrollment', 'desc' => 'Register or adjust a student course load'],
            ['href' => 'timetable_settings.php', 'icon' => 'fas fa-calendar-alt', 'title' => 'Timetable Settings', 'desc' => 'Configure class schedules and timetable rules'],
        ],
    ],
    [
        'title' => 'Teaching Assignment',
        'description' => 'Connect lecturers to the courses they deliver.',
        'items' => [
            ['href' => 'assign_course.php', 'icon' => 'fas fa-chalkboard-teacher', 'title' => 'Faculty Assignment', 'desc' => 'Assign lecturers to courses and review workloads'],
        ],
    ],
];
?>
<style>
    /* stat-card, stat-icon → assets/css/dashboard.css */
    .action-tile {
        padding: 1.5rem !important;
        text-align: left !important;
        border-radius: 16px !important;
        border: 1px solid #f0f0f0 !important;
        background: #fff !important;
        transition: all 0.3s ease !important;
        display: block !important;
        text-decoration: none !important;
        color: #495057 !important;
        height: 100%;
    }
    .action-tile:hover {
        border-color: var(--primary-color) !important;
        background: #f8f9ff !important;
        transform: translateY(-3px);
        box-shadow: 0 8px 16px rgba(0,0,0,0.05);
    }
    .action-tile i {
        font-size: 1.8rem;
        margin-bottom: 1rem;
        color: var(--primary-color);
        display: block;
    }
    .action-tile .tile-title {
        font-weight: 700;
        font-size: 1.1rem;
        display: block;
        margin-bottom: 0.25rem;
    }
    .action-tile .tile-desc {
        font-size: 0.85rem;
        color: #6c757d;
        display: block;
    }
    .workflow-section {
        margin-bottom: 1.5rem;
    }
    .workflow-heading {
        display: flex;
        align-items: start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .workflow-heading h6 {
        font-size: 0.95rem;
        font-weight: 800;
        margin: 0;
        color: #1B2A4A;
    }
    .workflow-heading p {
        margin: 0.15rem 0 0;
        color: #6c757d;
        font-size: 0.86rem;
    }
</style>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-sitemap me-2 text-primary"></i>Academic Structure</h5>
                <p class="page-subtitle mb-0">Unified management hub for programs, courses, and registrations</p>
            </div>
            <div class="header-actions">
                <a href="index.php" class="btn btn-outline-primary shadow-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3 text-white"><i class="fas fa-graduation-cap"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($programCount) ?></h3>
                        <p class="text-muted mb-0">Total Programs</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3 text-white"><i class="fas fa-check-circle"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($activePrograms) ?></h3>
                        <p class="text-muted mb-0">Active Programs</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3 text-white"><i class="fas fa-book"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($courseCount) ?></h3>
                        <p class="text-muted mb-0">Catalogue Courses</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning me-3 text-white"><i class="fas fa-layer-group"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($programCourseCount) ?></h3>
                        <p class="text-muted mb-0">Program Assignments</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

  <div class="data-table-card">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-sitemap me-2"></i>Academic Workflow</h5>
      </div>
    </div>
    <div class="card-body bg-light-subtle rounded-bottom">
        <?php foreach ($workflowGroups as $group): ?>
            <section class="workflow-section">
                <div class="workflow-heading">
                    <div>
                        <h6><?= htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8') ?></h6>
                        <p><?= htmlspecialchars($group['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
                <div class="row g-3">
                    <?php foreach ($group['items'] as $item): ?>
                        <div class="col-md-4 col-xl-3">
                            <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" class="action-tile">
                                <i class="<?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                                <span class="tile-title"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="tile-desc"><?= htmlspecialchars($item['desc'], ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
  </div>
</div>

<?php require_once "includes/footer.php"; ?>
