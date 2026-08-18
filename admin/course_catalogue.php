<?php
require "includes/admin.php";
require_once dirname(__DIR__) . '/students/includes/period_mode_helper.php';
require_once dirname(__DIR__) . '/includes/helpers/academic_structure_helpers.php';
require_once dirname(__DIR__) . '/includes/helpers/academic_period_helpers.php';
require_once dirname(__DIR__) . '/includes/helpers/course_availability_helpers.php';

$page_title = "Course Catalogue";

// CSRF token
if (empty($_SESSION['catalogue_csrf'])) {
    $_SESSION['catalogue_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['catalogue_csrf'];

function catalogue_valid_csrf(?string $t): bool {
    return is_string($t) && isset($_SESSION['catalogue_csrf']) && hash_equals($_SESSION['catalogue_csrf'], $t);
}

function catalogue_active_programs(mysqli $db): array {
    $programs = [];
    $res = $db->query("SELECT program_code, program_name, period_mode
                       FROM programs
                       WHERE COALESCE(is_active, 1) = 1
                       ORDER BY program_name");
    if ($res) {
        while ($row = $res->fetch_object()) {
            $programs[] = $row;
        }
        $res->free();
    }
    return $programs;
}

function catalogue_program_period_meta(mysqli $db, string $programCode): array {
    $structure = getProgramPeriodMode($db, $programCode);
    $label = wuc_period_label_from_structure($structure, true);
    $limit = $structure === 'term' ? 3 : ($structure === 'semester' ? 2 : 1);
    if ($structure === 'duration' || $structure === 'intake') {
        $limit = 4;
    }
    return ['structure' => $structure, 'label' => $label, 'limit' => max(1, $limit)];
}

function catalogue_program_is_active(mysqli $db, string $programCode): bool {
    $stmt = $db->prepare("SELECT 1 FROM programs WHERE program_code = ? AND COALESCE(is_active, 1) = 1 LIMIT 1");
    $stmt->bind_param("s", $programCode);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function catalogue_course_has_program(mysqli $db, string $courseCode): bool {
    $stmt = $db->prepare("SELECT 1 FROM program_courses WHERE course_code = ? LIMIT 1");
    $stmt->bind_param("s", $courseCode);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function catalogue_assign_course_to_program(
    mysqli $db,
    string $programCode,
    string $courseCode,
    int $year,
    ?int $deliveryPeriod = null,
    bool $periodSpecific = false
): bool {
    $result = wuc_insert_program_course_assignment(
        $db,
        $programCode,
        $courseCode,
        $year,
        $deliveryPeriod,
        $periodSpecific
    );
    return $result['ok'];
}

// ─── Handle form submissions before any output ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!catalogue_valid_csrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['errorMsg'] = "Security token mismatch. Please refresh and try again.";
        header("Location: course_catalogue.php");
        exit();
    }

    $action = $_POST['action'];

    if ($action === 'add' || $action === 'edit') {
        $code    = strtoupper(trim($_POST['course_code'] ?? ''));
        $name    = trim($_POST['course_name'] ?? '');
        $credits = max(0, min(30, (int)($_POST['credits'] ?? 0)));
        $status  = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

        $errors = [];
        if ($code === '' || $name === '') {
            $errors[] = "Course code and name are required.";
        }
        if ($code !== '' && (!preg_match('/^[A-Z0-9][A-Z0-9\-.\/]*$/', $code) || strlen($code) > 50)) {
            $errors[] = "Invalid course code: use letters, numbers, dashes, dots or slashes (max 50 chars).";
        }
        if (mb_strlen($name) > 200) {
            $errors[] = "Course name is too long (max 200 characters).";
        }
        if ($action === 'add') {
            $programCode = trim((string)($_POST['program_code'] ?? ''));
            $year = (int)($_POST['year'] ?? 0);
            $deliveryPeriod = (int)($_POST['semester'] ?? 0);
            $periodSpecific = !empty($_POST['period_specific']);
            $periodMeta = $programCode !== '' ? catalogue_program_period_meta($db, $programCode) : ['label' => 'Period', 'limit' => 1];
            if ($programCode === '' || !catalogue_program_is_active($db, $programCode)) {
                $errors[] = "Select an active program for this course.";
            }
            $maxYear = ($programCode !== '' && function_exists('wuc_program_max_curriculum_year'))
                ? wuc_program_max_curriculum_year($db, $programCode)
                : 10;
            if ($year < 1 || $year > $maxYear) {
                $errors[] = "Year of study must be between 1 and {$maxYear} for the selected program (its duration / curriculum span).";
            }
            if ($periodSpecific && ($deliveryPeriod < 1 || $deliveryPeriod > (int)$periodMeta['limit'])) {
                $errors[] = $periodMeta['label'] . " must be between 1 and " . (int)$periodMeta['limit'] . " when limiting to one period.";
            } elseif ($periodSpecific) {
                $periodCheck = validateCoursePeriodAssignment($db, $programCode, $year, $deliveryPeriod);
                if (!$periodCheck['ok']) {
                    $errors[] = $periodCheck['message'];
                }
            }
        }
        if (!empty($errors)) {
            $_SESSION['errorMsg'] = implode(' ', $errors);
            header("Location: course_catalogue.php");
            exit();
        }

        if ($action === 'add') {
            $chk = $db->prepare("SELECT id FROM courses WHERE course_code = ?");
            $chk->bind_param("s", $code);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $_SESSION['errorMsg'] = "Course code '{$code}' already exists.";
            } else {
                $programCode = trim((string)($_POST['program_code'] ?? ''));
                $year = (int)$_POST['year'];
                $deliveryPeriod = (int)($_POST['semester'] ?? 0);
                $periodSpecific = !empty($_POST['period_specific']);
                try {
                    $db->begin_transaction();
                    $ins = $db->prepare("INSERT INTO courses (course_code, course_name, credits, status) VALUES (?, ?, ?, ?)");
                    $ins->bind_param("ssis", $code, $name, $credits, $status);
                    $ins->execute();
                    catalogue_assign_course_to_program(
                        $db,
                        $programCode,
                        $code,
                        $year,
                        $periodSpecific ? $deliveryPeriod : null,
                        $periodSpecific
                    );
                    $db->commit();
                    $_SESSION['successMsg'] = "Course added and assigned to program successfully!";
                } catch (Throwable $e) {
                    $db->rollback();
                    error_log('Course add failed: ' . $e->getMessage());
                    $_SESSION['errorMsg'] = "Course could not be saved. Please check the program assignment and try again.";
                }
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            // course_code is editable but must stay unique
            $chk = $db->prepare("SELECT id FROM courses WHERE course_code = ? AND id <> ?");
            $chk->bind_param("si", $code, $id);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $_SESSION['errorMsg'] = "Another course already uses code '{$code}'.";
            } else {
                $old = $db->prepare("SELECT course_code FROM courses WHERE id = ? LIMIT 1");
                $old->bind_param("i", $id);
                $old->execute();
                $oldRow = $old->get_result()->fetch_object();
                $old->close();

                if (!$oldRow) {
                    $_SESSION['errorMsg'] = "Course not found.";
                } elseif ($status === 'active' && !catalogue_course_has_program($db, (string)$oldRow->course_code)) {
                    $_SESSION['errorMsg'] = "This course must be assigned to at least one program before it can be active.";
                } else {
                    try {
                        $db->begin_transaction();
                        if ((string)$oldRow->course_code !== $code) {
                            $sync = $db->prepare("UPDATE program_courses SET course_code = ? WHERE course_code = ?");
                            $sync->bind_param("ss", $code, $oldRow->course_code);
                            $sync->execute();
                            $sync->close();
                        }
                        $upd = $db->prepare("UPDATE courses SET course_code = ?, course_name = ?, credits = ?, status = ? WHERE id = ?");
                        $upd->bind_param("ssisi", $code, $name, $credits, $status, $id);
                        $upd->execute();
                        $db->commit();
                        $_SESSION['successMsg'] = "Course updated successfully!";
                    } catch (Throwable $e) {
                        $db->rollback();
                        error_log('Course update failed: ' . $e->getMessage());
                        $_SESSION['errorMsg'] = "Course could not be updated. Please try again.";
                    }
                }
            }
        }
        header("Location: course_catalogue.php");
        exit();
    }

    if ($action === 'assign') {
        $id = (int)($_POST['id'] ?? 0);
        $programCode = trim((string)($_POST['program_code'] ?? ''));
        $year = (int)($_POST['year'] ?? 0);
        $deliveryPeriod = (int)($_POST['semester'] ?? 0);
        $periodSpecific = !empty($_POST['period_specific']);
        $periodMeta = $programCode !== '' ? catalogue_program_period_meta($db, $programCode) : ['label' => 'Period', 'limit' => 1];

        $courseStmt = $db->prepare("SELECT course_code FROM courses WHERE id = ? LIMIT 1");
        $courseStmt->bind_param("i", $id);
        $courseStmt->execute();
        $courseRow = $courseStmt->get_result()->fetch_object();
        $courseStmt->close();

        if (!$courseRow) {
            $_SESSION['errorMsg'] = "Course not found.";
        } elseif ($programCode === '' || !catalogue_program_is_active($db, $programCode)) {
            $_SESSION['errorMsg'] = "Select an active program for this course.";
        } elseif ($year < 1 || $year > 10) {
            $_SESSION['errorMsg'] = "Enter a valid year of study for the selected program.";
        } elseif ($periodSpecific && ($deliveryPeriod < 1 || $deliveryPeriod > (int)$periodMeta['limit'])) {
            $_SESSION['errorMsg'] = "Enter a valid " . strtolower((string)$periodMeta['label']) . " when limiting to one period.";
        } else {
            try {
                catalogue_assign_course_to_program(
                    $db,
                    $programCode,
                    (string)$courseRow->course_code,
                    $year,
                    $periodSpecific ? $deliveryPeriod : null,
                    $periodSpecific
                );
                $_SESSION['successMsg'] = "Course assigned to program.";
            } catch (Throwable $e) {
                error_log('Course assignment failed: ' . $e->getMessage());
                $_SESSION['errorMsg'] = "Course could not be assigned. Please try again.";
            }
        }
        header("Location: course_catalogue.php");
        exit();
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Resolve the code so we can check program usage.
        $codeRes = $db->prepare("SELECT course_code FROM courses WHERE id = ?");
        $codeRes->bind_param("i", $id);
        $codeRes->execute();
        $row = $codeRes->get_result()->fetch_object();

        if (!$row) {
            $_SESSION['errorMsg'] = "Course not found.";
        } else {
            $used = $db->prepare("SELECT COUNT(*) AS c FROM program_courses WHERE course_code = ?");
            $used->bind_param("s", $row->course_code);
            $used->execute();
            $count = (int)$used->get_result()->fetch_object()->c;
            if ($count > 0) {
                $_SESSION['errorMsg'] = "Cannot delete '{$row->course_code}' — it is assigned to {$count} program(s). Remove it from those programs first.";
            } else {
                $del = $db->prepare("DELETE FROM courses WHERE id = ?");
                $del->bind_param("i", $id);
                $_SESSION[$del->execute() ? 'successMsg' : 'errorMsg'] = $del->error ?: "Course deleted.";
            }
        }
        header("Location: course_catalogue.php");
        exit();
    }
}

// ─── Fetch catalogue with program-usage counts ───────────────────────────
$programOptions = catalogue_active_programs($db);
$courses = [];
$res = $db->query(
    "SELECT c.id, c.course_code, c.course_name, c.credits, c.status,
            (SELECT COUNT(*) FROM program_courses pc WHERE pc.course_code = c.course_code) AS program_count
     FROM courses c
     ORDER BY c.course_code"
);
if ($res) {
    while ($row = $res->fetch_object()) {
        $courses[] = $row;
    }
    $res->free();
}

$totalCourses = count($courses);
$activeCourses = count(array_filter($courses, fn($c) => strtolower((string)$c->status) === 'active'));
$mappedCourses = count(array_filter($courses, fn($c) => (int)$c->program_count > 0));
$unmappedCourses = $totalCourses - $mappedCourses;

require "includes/header.php";
?>
<style>
    /* stat-card, stat-icon → assets/css/dashboard.css */
    .modal-header.admin-modal { background: linear-gradient(135deg, #6f42c1 0%, #4e2a84 100%) !important; border: none !important; }
    .code-badge { font-family: 'JetBrains Mono', monospace; background: #f8f9fa; border: 1px solid #e9ecef; padding: 2px 8px; border-radius: 4px; font-weight: 600; }
</style>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-book me-2 text-primary"></i>Course Catalogue</h5>
                <p class="page-subtitle mb-0">Define academic courses available to assign to programs</p>
            </div>
            <div class="header-actions d-flex gap-2">
                <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addCourseModal">
                    <i class="fas fa-plus me-1"></i>Add Course
                </button>
                <a href="course_program_mgmt.php" class="btn btn-outline-primary shadow-sm">
                    <i class="fas fa-arrow-left me-1"></i>Academic Structure
                </a>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (isset($_SESSION['successMsg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($_SESSION['successMsg']); unset($_SESSION['successMsg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['errorMsg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($_SESSION['errorMsg']); unset($_SESSION['errorMsg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100"><div class="d-flex align-items-center">
                <div class="stat-icon bg-primary me-3 text-white"><i class="fas fa-book"></i></div>
                <div><h4 class="mb-0 fw-bold"><?= number_format($totalCourses) ?></h4><p class="text-muted mb-0">Total Courses</p></div>
            </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100"><div class="d-flex align-items-center">
                <div class="stat-icon bg-success me-3 text-white"><i class="fas fa-check-circle"></i></div>
                <div><h4 class="mb-0 fw-bold"><?= number_format($activeCourses) ?></h4><p class="text-muted mb-0">Active</p></div>
            </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100"><div class="d-flex align-items-center">
                <div class="stat-icon bg-info me-3 text-white"><i class="fas fa-sitemap"></i></div>
                <div><h4 class="mb-0 fw-bold"><?= number_format($mappedCourses) ?></h4><p class="text-muted mb-0">Assigned to Programs</p></div>
            </div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100"><div class="d-flex align-items-center">
                <div class="stat-icon bg-warning me-3 text-white"><i class="fas fa-link-slash"></i></div>
                <div><h4 class="mb-0 fw-bold"><?= number_format($unmappedCourses) ?></h4><p class="text-muted mb-0">Need Assignment</p></div>
            </div></div>
        </div>
    </div>

    <!-- Table -->
    <div class="data-table-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Courses</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="catalogueTable" class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>No.</th>
                            <th>Course Code</th>
                            <th>Course Name</th>
                            <th class="text-center">Credits</th>
                            <th class="text-center">Programs</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $n = 1; foreach ($courses as $c): ?>
                            <tr>
                                <td><?= $n++ ?></td>
                                <td><span class="code-badge"><?= htmlspecialchars($c->course_code) ?></span></td>
                                <td><?= htmlspecialchars($c->course_name) ?></td>
                                <td class="text-center"><?= htmlspecialchars((string)($c->credits ?? '')) ?></td>
                                <td class="text-center">
                                    <?php if ((int)$c->program_count > 0): ?>
                                        <span class="badge bg-light text-dark border"><?= (int)$c->program_count ?></span>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-warning assign-course"
                                            data-id="<?= (int)$c->id ?>"
                                            data-code="<?= htmlspecialchars($c->course_code) ?>"
                                            data-bs-toggle="modal" data-bs-target="#assignCourseModal">
                                            Assign
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= strtolower((string)$c->status) === 'active' ? 'success' : 'secondary' ?>-subtle text-<?= strtolower((string)$c->status) === 'active' ? 'success' : 'secondary' ?> border">
                                        <?= ucfirst(htmlspecialchars((string)($c->status ?: 'active'))) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">
                                        <button class="btn btn-sm btn-outline-primary edit-course"
                                            data-course='<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>'
                                            data-bs-toggle="modal" data-bs-target="#editCourseModal" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this course from the catalogue?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="id" value="<?= (int)$c->id ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addCourseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header admin-modal text-white">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add Course</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Course Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="course_code" required maxlength="50"
                               pattern="[A-Za-z0-9][A-Za-z0-9\-./]*" placeholder="e.g. GEN101" style="text-transform:uppercase;">
                        <div class="invalid-feedback">Enter a code using letters, numbers, dashes, dots or slashes.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Course Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="course_name" required maxlength="200"
                               placeholder="e.g. Communication Skills">
                        <div class="invalid-feedback">Course name is required (max 200 characters).</div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Credits</label>
                            <input type="number" class="form-control" name="credits" min="0" max="30" value="3">
                            <div class="invalid-feedback">Credits must be between 0 and 30.</div>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label">Program <span class="text-danger">*</span></label>
                        <select class="form-select catalogue-program-select" name="program_code" required>
                            <option value="">Select program</option>
                            <?php foreach ($programOptions as $p): ?>
                                <?php $meta = catalogue_program_period_meta($db, (string)$p->program_code); ?>
                                <option value="<?= htmlspecialchars($p->program_code) ?>"
                                        data-period-label="<?= htmlspecialchars($meta['label']) ?>"
                                        data-period-limit="<?= (int)$meta['limit'] ?>">
                                    <?= htmlspecialchars($p->program_name . ' (' . $p->program_code . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Select the program this course belongs to.</div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Year of Study <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="year" min="1" max="10" value="1" required>
                        </div>
                        <div class="col-6 mb-3 catalogue-period-wrap">
                            <label class="form-label catalogue-period-label">Delivery period</label>
                            <input type="number" class="form-control catalogue-period-input" name="semester" min="1" max="2" value="1" disabled>
                            <div class="form-text">Only when limiting to one period below.</div>
                        </div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input catalogue-period-toggle" type="checkbox" name="period_specific" value="1" id="addPeriodSpecific">
                        <label class="form-check-label" for="addPeriodSpecific">Limit to one period only (exception)</label>
                    </div>
                    <p class="small text-muted mb-0">By default, courses run for the full academic year for the selected year of study.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Course</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editCourseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header admin-modal text-white">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Course</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="id" id="editCourseId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Course Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="course_code" id="editCourseCode" required maxlength="50"
                               pattern="[A-Za-z0-9][A-Za-z0-9\-./]*" style="text-transform:uppercase;">
                        <div class="invalid-feedback">Enter a code using letters, numbers, dashes, dots or slashes.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Course Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="course_name" id="editCourseName" required maxlength="200">
                        <div class="invalid-feedback">Course name is required (max 200 characters).</div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Credits</label>
                            <input type="number" class="form-control" name="credits" id="editCourseCredits" min="0" max="30">
                            <div class="invalid-feedback">Credits must be between 0 and 30.</div>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="editCourseStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Modal -->
<div class="modal fade" id="assignCourseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header admin-modal text-white">
                <h5 class="modal-title"><i class="fas fa-link me-2"></i>Assign Course</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="assign">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="id" id="assignCourseId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Course</label>
                        <input type="text" class="form-control" id="assignCourseCode" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Program <span class="text-danger">*</span></label>
                        <select class="form-select catalogue-program-select" name="program_code" required>
                            <option value="">Select program</option>
                            <?php foreach ($programOptions as $p): ?>
                                <?php $meta = catalogue_program_period_meta($db, (string)$p->program_code); ?>
                                <option value="<?= htmlspecialchars($p->program_code) ?>"
                                        data-period-label="<?= htmlspecialchars($meta['label']) ?>"
                                        data-period-limit="<?= (int)$meta['limit'] ?>">
                                    <?= htmlspecialchars($p->program_name . ' (' . $p->program_code . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Select the program this course belongs to.</div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Year of Study <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="year" min="1" max="10" value="1" required>
                        </div>
                        <div class="col-6 mb-3 catalogue-period-wrap">
                            <label class="form-label catalogue-period-label">Delivery period</label>
                            <input type="number" class="form-control catalogue-period-input" name="semester" min="1" max="2" value="1" disabled>
                            <div class="form-text">Only when limiting to one period below.</div>
                        </div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input catalogue-period-toggle" type="checkbox" name="period_specific" value="1" id="assignPeriodSpecific">
                        <label class="form-check-label" for="assignPeriodSpecific">Limit to one period only (exception)</label>
                    </div>
                    <p class="small text-muted mb-0">By default, courses run for the full academic year for the selected year of study.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Assign Course</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function () {
    function updateCataloguePeriodControl(selectEl) {
        var form = selectEl.closest('form');
        if (!form) return;
        var option = selectEl.options[selectEl.selectedIndex];
        var label = (option && option.getAttribute('data-period-label')) || 'Period';
        var limit = parseInt((option && option.getAttribute('data-period-limit')) || '2', 10);
        if (!limit || limit < 1) limit = 1;

        var labelEl = form.querySelector('.catalogue-period-label');
        var inputEl = form.querySelector('.catalogue-period-input');
        if (labelEl) labelEl.textContent = label;
        if (inputEl) {
            inputEl.max = String(limit);
            if (parseInt(inputEl.value || '1', 10) > limit) inputEl.value = String(limit);
        }
    }

    function syncCataloguePeriodToggle(toggleEl) {
        var form = toggleEl.closest('form');
        if (!form) return;
        var inputEl = form.querySelector('.catalogue-period-input');
        if (!inputEl) return;
        inputEl.disabled = !toggleEl.checked;
        inputEl.required = toggleEl.checked;
    }

    document.querySelectorAll('.catalogue-period-toggle').forEach(function (toggleEl) {
        toggleEl.addEventListener('change', function () { syncCataloguePeriodToggle(toggleEl); });
        syncCataloguePeriodToggle(toggleEl);
    });

    document.querySelectorAll('.catalogue-program-select').forEach(function (selectEl) {
        selectEl.addEventListener('change', function () { updateCataloguePeriodControl(selectEl); });
        updateCataloguePeriodControl(selectEl);
    });

    if ($('#catalogueTable').length) {
        $('#catalogueTable').DataTable({
            pageLength: 15,
            responsive: true,
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
            language: {
                search: '',
                searchPlaceholder: 'Search courses...',
                lengthMenu: 'Show _MENU_ entries',
                zeroRecords: '<div class="text-center py-5"><i class="fas fa-book fa-3x text-muted mb-3"></i><p class="text-muted">No courses match your search criteria</p></div>',
                info: 'Showing _START_ to _END_ of _TOTAL_ courses',
                infoEmpty: 'No courses available',
                infoFiltered: '(filtered from _MAX_ total)',
                paginate: {
                    first: '<i class="fas fa-angle-double-left"></i>',
                    last: '<i class="fas fa-angle-double-right"></i>',
                    next: '<i class="fas fa-angle-right"></i>',
                    previous: '<i class="fas fa-angle-left"></i>'
                }
            },
            columnDefs: [
                { orderable: false, targets: [6], className: 'text-center' },
                { targets: [1, 6], className: 'dt-nowrap' }
            ]
        });
    }

    document.querySelectorAll('.edit-course').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var c = JSON.parse(this.dataset.course);
            document.getElementById('editCourseId').value = c.id;
            document.getElementById('editCourseCode').value = c.course_code;
            document.getElementById('editCourseName').value = c.course_name;
            document.getElementById('editCourseCredits').value = c.credits;
            document.getElementById('editCourseStatus').value = (String(c.status).toLowerCase() === 'inactive') ? 'inactive' : 'active';
        });
    });

    document.querySelectorAll('.assign-course').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('assignCourseId').value = this.dataset.id;
            document.getElementById('assignCourseCode').value = this.dataset.code;
        });
    });

    // Bootstrap client-side validation
    document.querySelectorAll('.needs-validation').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!form.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
            form.classList.add('was-validated');
        }, false);
    });
});
</script>

<?php require_once "includes/footer.php"; ?>

<?php require_once "includes/footer.php"; ?>

<?php require_once "includes/footer.php"; ?>






























