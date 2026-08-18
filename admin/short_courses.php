<?php
/**
 * Short Courses Management
 * Admin page to create, view, edit short courses
 * AND enroll/create student accounts linked to them.
 */
require "includes/admin.php";
require_once dirname(__DIR__) . '/includes/short_course_student.php';
require_once dirname(__DIR__) . '/includes/itc_course_helpers.php';

$page_title = "Short Courses";

// Reference data for the classification dropdowns (categories / levels / intake types).
$catList        = itc_categories($db);
$levelList      = itc_levels($db);
$intakeTypeList = itc_intake_types($db);
$staffId = $_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '';

// CSRF token shared with the AJAX handler (admin/ajax/short_course_ajax.php)
if (empty($_SESSION['sc_admin_csrf'])) {
    $_SESSION['sc_admin_csrf'] = bin2hex(random_bytes(32));
}
$scCsrfToken = $_SESSION['sc_admin_csrf'];

// ─── Handle form submissions BEFORE any output ───────────────────────────
$msg = '';
$msgType = '';

// Validate CSRF once for every state-changing POST (add/edit/delete). Uses the
// same sc_admin_csrf token the AJAX handler enforces, so admin pages stay consistent.
$scCsrfValid = isset($_POST['csrf_token'])
    && is_string($_POST['csrf_token'])
    && !empty($_SESSION['sc_admin_csrf'])
    && hash_equals($_SESSION['sc_admin_csrf'], $_POST['csrf_token']);

// ADD short course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !$scCsrfValid) {
    $msg = 'Security token mismatch. Please refresh the page and try again.';
    $msgType = 'danger';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add') {
        $code = strtoupper(trim($_POST['course_code'] ?? ''));
        $name = trim($_POST['course_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $durVal = max(1, (int) ($_POST['duration_value'] ?? 12));
        $durUnit = in_array($_POST['duration_unit'] ?? '', ['days', 'weeks', 'months', 'years']) ? $_POST['duration_unit'] : 'days';
        $fee = max(0, (float) ($_POST['fee'] ?? 0));
        $cap = max(1, (int) ($_POST['max_capacity'] ?? 30));
        $prereqs = trim($_POST['prerequisites'] ?? '');
        $mode = in_array($_POST['delivery_mode'] ?? '', ['full-time', 'part-time', 'online', 'blended']) ? $_POST['delivery_mode'] : 'full-time';
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive', 'upcoming']) ? $_POST['status'] : 'active';
        // ITC classification (category / level / intake type / structured duration).
        $categoryId   = ($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null;
        $levelId      = ($_POST['level_id'] ?? '') !== '' ? (int) $_POST['level_id'] : null;
        $intakeTypeId = ($_POST['intake_type_id'] ?? '') !== '' ? (int) $_POST['intake_type_id'] : null;
        $minAge       = ($_POST['minimum_age'] ?? '') !== '' ? max(0, (int) $_POST['minimum_age']) : null;
        $stdDays      = itc_duration_to_days($durVal, $durUnit);
        $start = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $endRaw = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        // Auto-fill the end date from start + duration when not supplied.
        $end = sc_derive_end_date($start, $durVal, $durUnit, $endRaw);

        $errs = [];
        if ($code === '' || $name === '') {
            $errs[] = 'Course code and name are required.';
        }
        if ($code !== '' && (!preg_match('/^[A-Z0-9][A-Z0-9\-.\/]*$/', $code) || strlen($code) > 30)) {
            $errs[] = 'Invalid course code: letters, numbers, dashes, dots or slashes (max 30 chars).';
        }
        if (mb_strlen($name) > 200) {
            $errs[] = 'Course name is too long (max 200 characters).';
        }
        if ($start && $endRaw && strtotime($endRaw) < strtotime($start)) {
            $errs[] = 'End date cannot be before the start date.';
        }
        if (!sc_is_short_course_duration($durVal, $durUnit)) {
            $errs[] = 'Short courses cannot be longer than six months. Create this as a programme instead.';
        }
        // BR-COURSE-001 / BR-CAT-002: an active course must be fully classified.
        if ($status === 'active') {
            $missing = itc_missing_classification([
                'category_id' => $categoryId, 'level_id' => $levelId,
                'intake_type_id' => $intakeTypeId, 'duration_value' => $durVal,
            ]);
            if (!empty($missing)) {
                $errs[] = 'Cannot activate this course until it has a ' . implode(', ', $missing)
                    . '. Set Status to Upcoming/Inactive, or complete the classification.';
            }
        }
        if (!empty($errs)) {
            $msg = implode(' ', $errs);
            $msgType = 'danger';
        } else {
            // Check duplicate
            $chk = $db->prepare("SELECT id FROM short_courses WHERE course_code = ?");
            $chk->bind_param("s", $code);
            $chk->execute();
            $courseExists = $chk->get_result()->num_rows > 0;
            $chk->close();

            if ($courseExists) {
                $msg = "Course code <strong>" . htmlspecialchars($code) . "</strong> already exists.";
                $msgType = 'danger';
            } else {
                $ins = $db->prepare("INSERT INTO short_courses
                    (course_code, course_name, description, category_id, level_id, intake_type_id,
                     duration_value, duration_unit, standard_duration_days, minimum_age,
                     fee, max_capacity, prerequisites, delivery_mode, status, start_date, end_date, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                // types: code,name,desc=s | cat,level,intake,durVal=i | durUnit=s | stdDays,minAge=i
                //        | fee=d | cap=i | prereqs,mode,status,start,end,createdBy=s  (18 params)
                $ins->bind_param(
                    "sssiiiisiidissssss",
                    $code, $name, $desc, $categoryId, $levelId, $intakeTypeId,
                    $durVal, $durUnit, $stdDays, $minAge,
                    $fee, $cap, $prereqs, $mode, $status, $start, $end, $staffId
                );
                if ($ins->execute()) {
                    $newId = (int)$ins->insert_id;
                    require_once dirname(__DIR__) . '/includes/short_course_db.php';
                    sc_ensure_courses_mirror($db, [
                        'course_code' => $code,
                        'course_name' => $name,
                        'fee' => $fee,
                        'status' => $status,
                    ]);
                    $msg = "Short course <strong>" . htmlspecialchars($name) . "</strong> created successfully.";
                    $msgType = 'success';
                } else {
                    $msg = "Database error: " . htmlspecialchars($db->error);
                    $msgType = 'danger';
                }
            }
        }
    }

    // EDIT short course
    if ($_POST['action'] === 'edit' && !empty($_POST['id'])) {
        $id = (int) $_POST['id'];
        $name = trim($_POST['course_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $durVal = max(1, (int) ($_POST['duration_value'] ?? 12));
        $durUnit = in_array($_POST['duration_unit'] ?? '', ['days', 'weeks', 'months', 'years']) ? $_POST['duration_unit'] : 'days';
        $fee = max(0, (float) ($_POST['fee'] ?? 0));
        $cap = max(1, (int) ($_POST['max_capacity'] ?? 30));
        $prereqs = trim($_POST['prerequisites'] ?? '');
        $mode = in_array($_POST['delivery_mode'] ?? '', ['full-time', 'part-time', 'online', 'blended']) ? $_POST['delivery_mode'] : 'full-time';
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive', 'upcoming']) ? $_POST['status'] : 'active';
        $categoryId   = ($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null;
        $levelId      = ($_POST['level_id'] ?? '') !== '' ? (int) $_POST['level_id'] : null;
        $intakeTypeId = ($_POST['intake_type_id'] ?? '') !== '' ? (int) $_POST['intake_type_id'] : null;
        $minAge       = ($_POST['minimum_age'] ?? '') !== '' ? max(0, (int) $_POST['minimum_age']) : null;
        $stdDays      = itc_duration_to_days($durVal, $durUnit);
        $start = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $endRaw = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        // Auto-fill the end date from start + duration when not supplied.
        $end = sc_derive_end_date($start, $durVal, $durUnit, $endRaw);

        // BR-COURSE-001 / BR-CAT-002: an active course must be fully classified.
        $classMissing = ($status === 'active') ? itc_missing_classification([
            'category_id' => $categoryId, 'level_id' => $levelId,
            'intake_type_id' => $intakeTypeId, 'duration_value' => $durVal,
        ]) : [];

        if ($name === '') {
            $msg = 'Course name is required.';
            $msgType = 'danger';
        } elseif (mb_strlen($name) > 200) {
            $msg = 'Course name is too long (max 200 characters).';
            $msgType = 'danger';
        } elseif ($start && $endRaw && strtotime($endRaw) < strtotime($start)) {
            $msg = 'End date cannot be before the start date.';
            $msgType = 'danger';
        } elseif (!sc_is_short_course_duration($durVal, $durUnit)) {
            $msg = 'Short courses cannot be longer than six months. Create this as a programme instead.';
            $msgType = 'danger';
        } elseif (!empty($classMissing)) {
            $msg = 'Cannot activate this course until it has a ' . implode(', ', $classMissing)
                . '. Set Status to Upcoming/Inactive, or complete the classification.';
            $msgType = 'danger';
        } else {
            $upd = $db->prepare("UPDATE short_courses SET
                course_name=?, description=?, category_id=?, level_id=?, intake_type_id=?,
                duration_value=?, duration_unit=?, standard_duration_days=?, minimum_age=?,
                fee=?, max_capacity=?, prerequisites=?, delivery_mode=?, status=?, start_date=?, end_date=?
                WHERE id=?");
            $upd->bind_param(
                "ssiiiisiidisssssi",
                $name, $desc, $categoryId, $levelId, $intakeTypeId,
                $durVal, $durUnit, $stdDays, $minAge,
                $fee, $cap, $prereqs, $mode, $status, $start, $end, $id
            );
            if ($upd->execute()) {
                $codeForMirror = '';
                if ($cstmt = $db->prepare('SELECT course_code FROM short_courses WHERE id = ? LIMIT 1')) {
                    $cstmt->bind_param('i', $id);
                    $cstmt->execute();
                    $codeForMirror = (string)($cstmt->get_result()->fetch_assoc()['course_code'] ?? '');
                    $cstmt->close();
                }
                require_once dirname(__DIR__) . '/includes/short_course_db.php';
                sc_ensure_courses_mirror($db, [
                    'course_code' => $codeForMirror,
                    'course_name' => $name,
                    'fee' => $fee,
                    'status' => $status,
                ]);
                $msg = "Course updated successfully.";
                $msgType = 'success';
            } else {
                $msg = "Update failed: " . htmlspecialchars($db->error);
                $msgType = 'danger';
            }
        }
    }

    // DELETE
    if ($_POST['action'] === 'delete' && !empty($_POST['id'])) {
        $id = (int) $_POST['id'];

        try {
            $db->begin_transaction();

            $delEnrollments = $db->prepare("DELETE FROM short_course_enrollments WHERE short_course_id = ?");
            $delEnrollments->bind_param("i", $id);
            $delEnrollments->execute();
            $delEnrollments->close();

            $del = $db->prepare("DELETE FROM short_courses WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();

            $db->commit();
            $msg = "Course deleted.";
            $msgType = 'warning';
        } catch (Throwable $e) {
            $db->rollback();
            $msg = "Delete failed: " . htmlspecialchars($e->getMessage());
            $msgType = 'danger';
        }
    }
}

// ─── Fetch all short courses with enrollment counts ─────────────────────
$courses = [];
$res = $db->query("
    SELECT sc.*, 
           (SELECT COUNT(*) FROM short_course_enrollments e WHERE e.short_course_id = sc.id AND e.status IN ('enrolled','active')) AS enrolled_count,
           (SELECT COUNT(*) FROM short_course_enrollments e WHERE e.short_course_id = sc.id) AS total_enrollments
    FROM short_courses sc 
    ORDER BY sc.status ASC, sc.course_code ASC
");
if ($res) {
    while ($r = $res->fetch_object()) {
        $courses[] = $r;
    }
    $res->free();
}

// Stats
$totalActive = count(array_filter($courses, fn($c) => $c->status === 'active'));
$totalUpcoming = count(array_filter($courses, fn($c) => $c->status === 'upcoming'));
$totalInactive = count(array_filter($courses, fn($c) => $c->status === 'inactive'));
$totalEnrolled = array_sum(array_map(fn($c) => (int) $c->enrolled_count, $courses));

// Include header
require "includes/header.php";
?>

<style>
    html,
    body.has-unified-sidebar,
    body.has-unified-sidebar .main-wrapper,
    body.has-unified-sidebar .main-content {
        background: #f4f7fb !important;
        color: #1f2937;
    }

    .short-courses-page {
        max-width: 100%;
        padding-top: 1.25rem;
        padding-bottom: 2rem;
    }

    .short-courses-page .page-header,
    .short-courses-page .stat-card,
    .short-courses-page .data-table-card {
        background: #ffffff !important;
        border: 1px solid #e5eaf2 !important;
        border-radius: 10px !important;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06) !important;
    }

    .short-courses-page .page-header {
        padding: 1.1rem 1.25rem;
    }

    .short-courses-page .page-title {
        color: #14213d;
        font-size: 1.05rem;
        font-weight: 700;
        letter-spacing: 0;
    }

    .short-courses-page .page-subtitle {
        color: #64748b;
        font-size: 0.88rem;
        margin-top: 0.15rem;
    }

    .short-courses-page .btn-primary {
        background: #2457a6;
        border-color: #2457a6;
        box-shadow: 0 6px 14px rgba(36, 87, 166, 0.18);
    }

    .short-courses-page .btn-primary:hover,
    .short-courses-page .btn-primary:focus {
        background: #1d4788;
        border-color: #1d4788;
    }

    .short-courses-page .stat-card {
        min-height: 92px;
        padding: 1.15rem !important;
    }

    .short-courses-page .stat-icon {
        width: 48px !important;
        height: 48px !important;
        min-width: 48px !important;
        border-radius: 10px !important;
        margin-right: 0.9rem !important;
        box-shadow: none !important;
    }

    .short-courses-page .stat-card h3 {
        font-size: 1.25rem !important;
        line-height: 1.1;
        color: #172033;
    }

    .short-courses-page .stat-card p {
        color: #64748b !important;
        font-size: 0.8rem !important;
        line-height: 1.25;
    }

    .short-courses-page .data-table-card {
        overflow: hidden;
    }

    .short-courses-page .data-table-card .card-header {
        background: #ffffff !important;
        border-bottom: 1px solid #e8edf5 !important;
        padding: 0.9rem 1.1rem;
    }

    .short-courses-page .data-table-card .card-header h5 {
        color: #164e86;
        font-size: 0.95rem;
        font-weight: 700;
    }

    .short-courses-page .data-table-card .card-body {
        padding: 1rem 1.1rem 1.15rem;
    }

    .short-courses-page #shortCoursesTable {
        width: 100% !important;
        min-width: 1260px;
    }

    .short-courses-page #shortCoursesTable th {
        color: #475569;
        font-size: 0.72rem;
        letter-spacing: 0;
        white-space: nowrap;
    }

    .short-courses-page #shortCoursesTable td {
        color: #334155;
        vertical-align: middle;
    }

    .short-courses-page #shortCoursesTable th:nth-child(1),
    .short-courses-page #shortCoursesTable td:nth-child(1) { width: 46px; min-width: 46px; }
    .short-courses-page #shortCoursesTable th:nth-child(2),
    .short-courses-page #shortCoursesTable td:nth-child(2) { min-width: 112px; }
    .short-courses-page #shortCoursesTable th:nth-child(3),
    .short-courses-page #shortCoursesTable td:nth-child(3) { min-width: 330px; }
    .short-courses-page #shortCoursesTable th:nth-child(4),
    .short-courses-page #shortCoursesTable td:nth-child(4) { min-width: 96px; }
    .short-courses-page #shortCoursesTable th:nth-child(5),
    .short-courses-page #shortCoursesTable td:nth-child(5) { min-width: 92px; }
    .short-courses-page #shortCoursesTable th:nth-child(6),
    .short-courses-page #shortCoursesTable td:nth-child(6) { min-width: 104px; }
    .short-courses-page #shortCoursesTable th:nth-child(7),
    .short-courses-page #shortCoursesTable td:nth-child(7) { min-width: 122px; }
    .short-courses-page #shortCoursesTable th:nth-child(8),
    .short-courses-page #shortCoursesTable td:nth-child(8) { min-width: 110px; }
    .short-courses-page #shortCoursesTable th:nth-child(9),
    .short-courses-page #shortCoursesTable td:nth-child(9) { min-width: 130px; }
    .short-courses-page #shortCoursesTable th:nth-child(10),
    .short-courses-page #shortCoursesTable td:nth-child(10) { min-width: 150px; }

    .short-courses-page #shortCoursesTable tbody tr:hover td {
        background: #f8fbff !important;
    }

    .short-courses-page .course-code-badge {
        display: inline-flex;
        white-space: nowrap;
        background: #f8fafc;
        border: 1px solid #dbe4f0;
        color: #1f2937;
        border-radius: 6px;
        padding: 0.35rem 0.55rem;
        font-size: 0.78rem;
        letter-spacing: 0;
    }

    .short-courses-page .course-name-cell strong {
        display: block;
        color: #1e293b;
        font-size: 0.86rem;
        line-height: 1.35;
    }

    .short-courses-page .course-name-cell small {
        display: block;
        margin-top: 0.15rem;
        max-width: 340px;
        color: #64748b !important;
    }

    .short-courses-page .dur-badge {
        color: #172033;
        font-weight: 700;
    }

    .short-courses-page .duration-unit {
        color: #64748b;
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0;
        text-transform: lowercase;
    }

    .short-courses-page .mode-tag,
    .short-courses-page .enroll-badge,
    .short-courses-page .status-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
        min-height: 28px;
        padding: 0.28rem 0.55rem;
        font-size: 0.76rem;
        font-weight: 700;
        line-height: 1.2;
        text-transform: capitalize;
    }

    .short-courses-page .mode-tag {
        background: #edf4ff;
        color: #1d4f91;
    }

    .short-courses-page .enroll-badge {
        background: #eef2f7;
        color: #172033;
    }

    .short-courses-page .fee-amount {
        color: #087f5b;
        font-weight: 700;
    }

    .short-courses-page .class-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.3rem;
        margin-top: 0.3rem;
    }

    .short-courses-page .class-badges .cb {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font-size: 0.68rem;
        font-weight: 700;
        line-height: 1;
        padding: 0.22rem 0.45rem;
        border-radius: 5px;
        white-space: nowrap;
    }

    .short-courses-page .class-badges .cb-cat { background: #eef2ff; color: #3949ab; }
    .short-courses-page .class-badges .cb-level { background: #ecfdf5; color: #047857; }
    .short-courses-page .class-badges .cb-intake { background: #fff7ed; color: #c2410c; }
    .short-courses-page .class-badges .cb-warn { background: #fef2f2; color: #b91c1c; }

    .short-courses-page .status-pill {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #334155;
        white-space: nowrap;
    }

    .short-courses-page .status-dot {
        width: 8px;
        height: 8px;
        margin-right: 0.45rem;
    }

    .short-courses-page .actions-group {
        gap: 0.35rem;
        flex-wrap: nowrap;
    }

    .short-courses-page .actions-group .btn {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        border-radius: 8px;
    }

    .short-courses-page #shortCoursesTable_wrapper > .row:first-child {
        align-items: center;
        row-gap: 0.75rem;
        margin-bottom: 0.9rem;
    }

    .short-courses-page #shortCoursesTable_length label,
    .short-courses-page #shortCoursesTable_filter label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: #475569;
        font-size: 0.85rem;
        margin: 0;
    }

    .short-courses-page #shortCoursesTable_length select {
        min-width: 74px;
        border-radius: 8px;
        border-color: #d7e0ec;
    }

    .short-courses-page #shortCoursesTable_filter {
        display: flex;
        justify-content: flex-end;
    }

    .short-courses-page #shortCoursesTable_filter input {
        width: min(100%, 260px);
        border-radius: 8px;
        border: 1px solid #d7e0ec;
        padding: 0.48rem 0.75rem;
    }

    .short-courses-page .dataTables_info,
    .short-courses-page .dataTables_paginate {
        color: #64748b;
        font-size: 0.82rem;
        padding-top: 0.85rem !important;
    }

    .short-courses-page .quick-preset {
        min-width: 96px;
        border-color: #dbe4f0 !important;
        border-radius: 8px !important;
        background: #ffffff;
    }

    .short-courses-page .quick-preset:hover,
    .short-courses-page .quick-preset.selected {
        border-color: #2457a6 !important;
        background: #eef5ff;
        transform: none;
        box-shadow: 0 6px 14px rgba(36, 87, 166, 0.12);
    }

    #addModal .modal-content,
    #editModal .modal-content,
    #manageModal .modal-content {
        border: 0;
        border-radius: 10px;
        overflow: hidden;
    }

    #addModal .modal-header.admin-modal,
    #editModal .modal-header.admin-modal,
    #manageModal .modal-header.admin-modal {
        background: #14213d !important;
        color: #ffffff;
    }

    #addModal .modal-header.admin-modal .modal-title,
    #editModal .modal-header.admin-modal .modal-title,
    #manageModal .modal-header.admin-modal .modal-title {
        color: #ffffff !important;
    }

    #manageModal .search-dropdown {
        z-index: 1080;
    }

    @media (max-width: 767.98px) {
        .short-courses-page {
            padding-left: 0.8rem !important;
            padding-right: 0.8rem !important;
            padding-top: 4.25rem;
        }

        .short-courses-page .page-header {
            padding: 1rem;
        }

        .short-courses-page .page-header .btn {
            width: 100%;
            justify-content: center;
        }

        .short-courses-page .stat-card {
            min-height: 82px;
            padding: 0.9rem !important;
        }

        .short-courses-page .stat-icon {
            width: 42px !important;
            height: 42px !important;
            min-width: 42px !important;
        }

        .short-courses-page .data-table-card .card-body {
            padding: 0.8rem;
        }

        .short-courses-page #shortCoursesTable_filter,
        .short-courses-page #shortCoursesTable_filter label,
        .short-courses-page #shortCoursesTable_filter input {
            width: 100%;
        }

        .short-courses-page #shortCoursesTable_length label {
            justify-content: space-between;
            width: 100%;
        }
    }
</style>

<div class="container-fluid px-4 portal-dashboard short-courses-page">
    <!-- Page Header -->
    <div class="page-header mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-certificate me-2 text-primary"></i>Short Courses</h5>
                <p class="page-subtitle mb-0">Create and manage short courses. Enroll students with flexible durations.</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="fas fa-plus me-1"></i>New Short Course
            </button>
        </div>
    </div>

    <!-- Alert -->
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
            <i
                class="fas fa-<?= $msgType === 'success' ? 'check-circle' : ($msgType === 'danger' ? 'exclamation-triangle' : 'info-circle') ?> me-2"></i>
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3"><i class="fas fa-certificate text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?= count($courses) ?></h3>
                        <p class="text-muted mb-0">Total Courses</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3"><i class="fas fa-check-circle text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?= $totalActive ?></h3>
                        <p class="text-muted mb-0">Active</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3"><i
                            class="fas fa-user-graduate text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?= $totalEnrolled ?></h3>
                        <p class="text-muted mb-0">Enrolled Students</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning me-3"><i class="fas fa-clock text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?= $totalUpcoming ?></h3>
                        <p class="text-muted mb-0">Upcoming</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Courses Table -->
    <div class="data-table-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Short Courses</h5>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($courses)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-certificate fa-3x text-muted mb-3 d-block" style="opacity:0.2;"></i>
                    <h5 class="text-muted">No short courses yet</h5>
                    <p class="text-muted">Click "New Short Course" to create your first one.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="shortCoursesTable">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Code</th>
                                <th>Course Name</th>
                                <th class="text-center">Duration</th>
                                <th>Mode</th>
                                <th class="text-end">Fee (ZMW)</th>
                                <th class="text-center">Enrolled / Cap</th>
                                <th>Status</th>
                                <th>Dates</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $n = 1;
                            foreach ($courses as $c): ?>
                                <tr>
                                    <td class="text-muted"><?= $n++ ?></td>
                                    <td><span
                                            class="course-code-badge fw-bold"><?= htmlspecialchars($c->course_code) ?></span>
                                    </td>
                                    <td class="course-name-cell">
                                        <strong><?= htmlspecialchars($c->course_name) ?></strong>
                                        <?php
                                        $cid = (int) ($c->category_id ?? 0);
                                        $lid = (int) ($c->level_id ?? 0);
                                        $tid = (int) ($c->intake_type_id ?? 0);
                                        ?>
                                        <div class="class-badges">
                                            <?php if ($cid && isset($catList[$cid])): ?>
                                                <span class="cb cb-cat" title="Category"><?= htmlspecialchars($catList[$cid]['category_name']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($lid && isset($levelList[$lid])): ?>
                                                <span class="cb cb-level" title="Level"><?= htmlspecialchars($levelList[$lid]['level_name']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($tid && isset($intakeTypeList[$tid])): ?>
                                                <span class="cb cb-intake" title="Intake type"><?= htmlspecialchars($intakeTypeList[$tid]['type_name']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!$cid || !$lid || !$tid): ?>
                                                <span class="cb cb-warn" title="Missing classification — cannot be published"><i class="fas fa-exclamation-triangle"></i> unclassified</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($c->description): ?>
                                            <small
                                                class="text-muted d-block"><?= htmlspecialchars(mb_strimwidth($c->description, 0, 60, '...')) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="dur-badge"><?= (int) $c->duration_value ?></span>
                                        <span class="duration-unit"><?= $c->duration_unit ?></span>
                                    </td>
                                    <td><span class="mode-tag"><?= $c->delivery_mode ?></span></td>
                                    <td class="text-end fee-amount"><?= number_format((float) $c->fee, 2) ?></td>
                                    <td class="text-center">
                                        <span class="enroll-badge"><?= (int) $c->enrolled_count ?> /
                                            <?= (int) $c->max_capacity ?></span>
                                    </td>
                                    <td>
                                        <span class="status-pill"><span class="status-dot <?= $c->status ?>"></span>
                                            <?= ucfirst($c->status) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($c->start_date): ?>
                                            <small><?= date('M j, Y', strtotime($c->start_date)) ?></small>
                                            <?php if ($c->end_date): ?>
                                                <br><small class="text-muted">to <?= date('M j, Y', strtotime($c->end_date)) ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <small class="text-muted">-</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center actions-group">
                                            <a class="btn btn-sm btn-success" title="Manage Content (modules &amp; materials)"
                                                aria-label="Manage content for <?= htmlspecialchars($c->course_name) ?>"
                                                href="short_course_content.php?course_id=<?= (int)$c->id ?>">
                                                <i class="fas fa-layer-group"></i>
                                            </a>
                                            <button class="btn btn-sm btn-primary manage-btn" title="Manage Students"
                                                aria-label="Manage students for <?= htmlspecialchars($c->course_name) ?>"
                                                data-course-id="<?= $c->id ?>"
                                                data-course-name="<?= htmlspecialchars($c->course_name) ?>"
                                                data-course-code="<?= htmlspecialchars($c->course_code) ?>"
                                                data-bs-toggle="modal" data-bs-target="#manageModal">
                                                <i class="fas fa-users"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary edit-btn"
                                                title="Edit Course"
                                                aria-label="Edit <?= htmlspecialchars($c->course_name) ?>"
                                                data-course='<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>'
                                                data-bs-toggle="modal" data-bs-target="#editModal">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" class="d-inline"
                                                onsubmit="return confirm('Delete this short course and all enrollments?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($scCsrfToken) ?>">
                                                <input type="hidden" name="id" value="<?= $c->id ?>">
                                                <button class="btn btn-sm btn-outline-danger" title="Delete Course"
                                                    aria-label="Delete <?= htmlspecialchars($c->course_name) ?>"><i
                                                        class="fas fa-trash"></i></button>
                                            </form>
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

<!-- ═══ ADD MODAL ═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header admin-modal">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Create Short Course</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($scCsrfToken) ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- Code & Name -->
                        <div class="col-md-4">
                            <label class="form-label">Course Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="course_code" required
                                pattern="[A-Za-z0-9][A-Za-z0-9\-./]*" maxlength="30"
                                placeholder="e.g. SC-WLD01" style="text-transform: uppercase;">
                            <div class="invalid-feedback">Enter a code using letters, numbers, dashes, dots or slashes (max 30).</div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Course Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="course_name" required maxlength="200"
                                placeholder="e.g. Basic Welding Techniques">
                            <div class="invalid-feedback">Course name is required (max 200 characters).</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"
                                placeholder="Brief course overview..."></textarea>
                        </div>

                        <!-- Duration Presets -->
                        <div class="col-12">
                            <label class="form-label fw-bold"><i
                                    class="fas fa-clock me-1 text-primary"></i>Duration</label>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <div class="quick-preset border rounded px-3 py-2 text-center"
                                    onclick="setDuration(5,'days',this)">
                                    <div class="duration-display">5</div>
                                    <div class="duration-unit">Days</div>
                                </div>
                                <div class="quick-preset border rounded px-3 py-2 text-center"
                                    onclick="setDuration(14,'days',this)">
                                    <div class="duration-display">14</div>
                                    <div class="duration-unit">Days</div>
                                </div>
                                <div class="quick-preset border rounded px-3 py-2 text-center"
                                    onclick="setDuration(21,'days',this)">
                                    <div class="duration-display">21</div>
                                    <div class="duration-unit">Days</div>
                                </div>
                                <div class="quick-preset border rounded px-3 py-2 text-center"
                                    onclick="setDuration(3,'months',this)">
                                    <div class="duration-display">3</div>
                                    <div class="duration-unit">Months</div>
                                </div>
                                <div class="quick-preset border rounded-pill px-3 py-2 text-center bg-light"
                                    onclick="focusCustom(this)">
                                    <div style="font-size:1.2rem;font-weight:700;color:#6f42c1;">✎</div>
                                    <div class="duration-unit">Custom</div>
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="number" class="form-control" name="duration_value" id="addDurationVal"
                                        min="1" value="5" required>
                                </div>
                                <div class="col-6">
                                    <select class="form-select" name="duration_unit" id="addDurationUnit">
                                        <option value="days" selected>Days</option>
                                        <option value="weeks">Weeks</option>
                                        <option value="months">Months</option>
                                        <option value="years">Years</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- ITC Classification -->
                        <div class="col-12">
                            <label class="form-label fw-bold"><i class="fas fa-sitemap me-1 text-primary"></i>Classification</label>
                            <div class="row g-2">
                                <div class="col-md-3 col-6">
                                    <label class="form-label small text-muted mb-1">Category</label>
                                    <select class="form-select" name="category_id" id="addCategory">
                                        <option value="">— Select —</option>
                                        <?php foreach ($catList as $cid => $crow): ?>
                                            <option value="<?= (int) $cid ?>"><?= htmlspecialchars($crow['category_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 col-6">
                                    <label class="form-label small text-muted mb-1 d-flex justify-content-between align-items-center">
                                        <span>Level</span>
                                        <button type="button" class="btn btn-link btn-sm p-0 suggest-btn" data-scope="add"
                                            title="Suggest level &amp; intake from name + duration"><i class="fas fa-wand-magic-sparkles"></i> Suggest</button>
                                    </label>
                                    <select class="form-select" name="level_id" id="addLevel">
                                        <option value="">— Select —</option>
                                        <?php foreach ($levelList as $lid => $lrow): ?>
                                            <option value="<?= (int) $lid ?>" data-code="<?= htmlspecialchars($lrow['level_code']) ?>"><?= htmlspecialchars($lrow['level_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 col-6">
                                    <label class="form-label small text-muted mb-1">Intake Type</label>
                                    <select class="form-select" name="intake_type_id" id="addIntakeType">
                                        <option value="">— Select —</option>
                                        <?php foreach ($intakeTypeList as $tid => $trow): ?>
                                            <option value="<?= (int) $tid ?>" data-code="<?= htmlspecialchars($trow['type_code']) ?>"><?= htmlspecialchars($trow['type_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 col-6">
                                    <label class="form-label small text-muted mb-1">Minimum Age</label>
                                    <input type="number" class="form-control" name="minimum_age" id="addMinAge" min="0" placeholder="e.g. 18">
                                </div>
                            </div>
                            <div class="form-text"><i class="fas fa-circle-info me-1"></i>A course needs a category, level, duration and intake type before it can be <strong>Active</strong>.</div>
                        </div>

                        <!-- Fee & Capacity -->
                        <div class="col-md-4">
                            <label class="form-label">Fee (ZMW)</label>
                            <input type="number" class="form-control" name="fee" step="0.01" min="0" value="0.00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Max Capacity</label>
                            <input type="number" class="form-control" name="max_capacity" min="1" value="30">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Delivery Mode</label>
                            <select class="form-select" name="delivery_mode">
                                <option value="full-time">Full-time</option>
                                <option value="part-time">Part-time</option>
                                <option value="online">Online</option>
                                <option value="blended">Blended</option>
                            </select>
                        </div>

                        <!-- Dates & Status -->
                        <div class="col-md-4">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="active">Active</option>
                                <option value="upcoming">Upcoming</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Entry Requirements</label>
                            <textarea class="form-control" name="prerequisites" rows="2"
                                placeholder="e.g. Grade 9 certificate, Minimum age 16"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i
                            class="fas fa-times me-1"></i>Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Create Course</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ EDIT MODAL ══════════════════════════════════════════════════════ -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header admin-modal">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Short Course</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($scCsrfToken) ?>">
                <input type="hidden" name="id" id="editId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Course Code</label>
                            <input type="text" class="form-control" id="editCode" disabled>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Course Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="course_name" id="editName" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="editDesc" rows="2"></textarea>
                        </div>

                        <!-- ITC Classification -->
                        <div class="col-md-3 col-6">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category_id" id="editCategory">
                                <option value="">— Select —</option>
                                <?php foreach ($catList as $cid => $crow): ?>
                                    <option value="<?= (int) $cid ?>"><?= htmlspecialchars($crow['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label d-flex justify-content-between align-items-center">
                                <span>Level</span>
                                <button type="button" class="btn btn-link btn-sm p-0 suggest-btn" data-scope="edit"
                                    title="Suggest level &amp; intake from name + duration"><i class="fas fa-wand-magic-sparkles"></i> Suggest</button>
                            </label>
                            <select class="form-select" name="level_id" id="editLevel">
                                <option value="">— Select —</option>
                                <?php foreach ($levelList as $lid => $lrow): ?>
                                    <option value="<?= (int) $lid ?>" data-code="<?= htmlspecialchars($lrow['level_code']) ?>"><?= htmlspecialchars($lrow['level_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label">Intake Type</label>
                            <select class="form-select" name="intake_type_id" id="editIntakeType">
                                <option value="">— Select —</option>
                                <?php foreach ($intakeTypeList as $tid => $trow): ?>
                                    <option value="<?= (int) $tid ?>" data-code="<?= htmlspecialchars($trow['type_code']) ?>"><?= htmlspecialchars($trow['type_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label">Minimum Age</label>
                            <input type="number" class="form-control" name="minimum_age" id="editMinAge" min="0">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Duration</label>
                            <input type="number" class="form-control" name="duration_value" id="editDurVal" min="1"
                                required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Unit</label>
                            <select class="form-select" name="duration_unit" id="editDurUnit">
                                <option value="days">Days</option>
                                <option value="weeks">Weeks</option>
                                <option value="months">Months</option>
                                <option value="years">Years</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fee (ZMW)</label>
                            <input type="number" class="form-control" name="fee" id="editFee" step="0.01" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Max Capacity</label>
                            <input type="number" class="form-control" name="max_capacity" id="editCap" min="1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Delivery Mode</label>
                            <select class="form-select" name="delivery_mode" id="editMode">
                                <option value="full-time">Full-time</option>
                                <option value="part-time">Part-time</option>
                                <option value="online">Online</option>
                                <option value="blended">Blended</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" id="editStart">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" id="editEnd">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="editStatus">
                                <option value="active">Active</option>
                                <option value="upcoming">Upcoming</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Entry Requirements</label>
                            <textarea class="form-control" name="prerequisites" id="editPrereqs" rows="2"></textarea>
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

<!-- ═══ MANAGE STUDENTS MODAL ═══════════════════════════════════════════ -->
<div class="modal fade" id="manageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header admin-modal">
                <h5 class="modal-title"><i class="fas fa-users me-2"></i>Manage Students — <span
                        id="manageCourseName"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <!-- Tabs -->
                <ul class="nav nav-tabs px-3 pt-3" id="manageTabs">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#tabEnrolled"><i
                                class="fas fa-list me-1"></i>Enrolled Students</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#tabEnrollExisting"><i
                                class="fas fa-user-plus me-1"></i>Enroll Existing</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#tabCreateNew"><i
                                class="fas fa-user-edit me-1"></i>Create &amp; Enroll New</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#tabLecturers"><i
                                class="fas fa-chalkboard-teacher me-1"></i>Lecturers</a>
                    </li>
                </ul>

                <div class="tab-content p-4">
                    <!-- TAB: Enrolled Students -->
                    <div class="tab-pane fade show active" id="tabEnrolled">
                        <div id="enrolledLoading" class="text-center py-4"><i
                                class="fas fa-spinner fa-spin fa-2x text-primary"></i></div>
                        <div id="enrolledContent" style="display:none;">
                            <div id="enrolledEmpty" class="text-center py-4" style="display:none;">
                                <i class="fas fa-inbox fa-3x text-muted d-block mb-2" style="opacity:0.15;"></i>
                                <p class="text-muted">No students enrolled yet. Use the tabs above to add students.</p>
                            </div>
                            <div class="table-responsive" id="enrolledTableWrap" style="display:none;">
                                <table class="table table-hover align-middle enrolled-table" id="enrolledTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Student ID</th>
                                            <th>Name</th>
                                            <th>Contact</th>
                                            <th>Enrolled</th>
                                            <th>Status</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="enrolledBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- TAB: Enroll Existing Student -->
                    <div class="tab-pane fade" id="tabEnrollExisting">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Search Student</label>
                            <div class="position-relative">
                                <input type="text" class="form-control" id="searchStudentInput"
                                    placeholder="Type student ID, name, or NRC..." autocomplete="off">
                                <div id="searchResults" class="search-dropdown" style="display:none;"></div>
                            </div>
                        </div>
                        <div id="selectedStudentCard" class="alert alert-info d-flex align-items-center"
                            style="display:none !important;">
                            <div class="flex-grow-1">
                                <strong id="selectedStudentName"></strong><br>
                                <small>ID: <code id="selectedStudentId"></code></small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                onclick="clearSelectedStudent()"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes (optional)</label>
                            <input type="text" class="form-control" id="enrollNotes"
                                placeholder="e.g. Sponsored by NAPSA">
                        </div>
                        <button class="btn btn-primary" id="enrollExistingBtn" disabled
                            onclick="enrollExistingStudent()">
                            <i class="fas fa-user-check me-1"></i>Enroll Student
                        </button>
                        <div id="enrollExistingAlert" class="mt-3" style="display:none;"></div>
                    </div>

                    <!-- TAB: Create & Enroll New Student -->
                    <div class="tab-pane fade" id="tabCreateNew">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="newFname" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="newLname" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Gender</label>
                                <select class="form-select" id="newSex">
                                    <option value="M">Male</option>
                                    <option value="F">Female</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">NRC / Passport</label>
                                <input type="text" class="form-control" id="newNrc" placeholder="e.g. 123456/78/1">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" id="newDob">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Mobile</label>
                                <input type="text" class="form-control" id="newMobile" placeholder="e.g. 0971234567">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" id="newEmail" placeholder="Optional">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes (optional)</label>
                                <input type="text" class="form-control" id="newNotes"
                                    placeholder="e.g. Walk-in registration">
                            </div>
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-success" onclick="createAndEnroll()">
                                <i class="fas fa-user-plus me-1"></i>Create Student &amp; Enroll
                            </button>
                        </div>
                        <div id="createAlert" class="mt-3" style="display:none;"></div>
                    </div>

                    <!-- TAB: Lecturers -->
                    <div class="tab-pane fade" id="tabLecturers">
                        <p class="text-muted small">
                            <i class="fas fa-info-circle me-1"></i>Assign a lecturer to this short course so they can
                            enter continuous assessment (CA) marks for its enrolled students.
                        </p>
                        <div class="row g-2 align-items-end mb-3">
                            <div class="col-md-8">
                                <label class="form-label fw-bold">Lecturer</label>
                                <select class="form-select" id="lecturerSelect">
                                    <option value="" disabled selected>Loading lecturers…</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-primary w-100" id="assignLecturerBtn" onclick="assignLecturer()" disabled>
                                    <i class="fas fa-user-plus me-1"></i>Assign Lecturer
                                </button>
                            </div>
                        </div>
                        <div id="assignLecturerAlert" class="mb-3" style="display:none;"></div>
                        <div id="lecturersLoading" class="text-center py-3"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i></div>
                        <div id="lecturersTableWrap" class="table-responsive" style="display:none;">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr><th>#</th><th>Lecturer</th><th>Staff ID</th><th>Status</th><th class="text-center">Action</th></tr>
                                </thead>
                                <tbody id="lecturersBody"></tbody>
                            </table>
                        </div>
                        <div id="lecturersEmpty" class="text-center py-4" style="display:none;">
                            <i class="fas fa-chalkboard-teacher fa-3x text-muted d-block mb-2" style="opacity:0.15;"></i>
                            <p class="text-muted mb-0">No lecturer is assigned to this course yet. Assigned lecturers appear here.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // ─── Duration preset helpers ──────────────────────────────────────────
    function setDuration(val, unit, el) {
        document.getElementById('addDurationVal').value = val;
        document.getElementById('addDurationUnit').value = unit;
        document.querySelectorAll('#addModal .quick-preset').forEach(p => p.classList.remove('selected'));
        if (el) el.classList.add('selected');
    }
    function focusCustom(el) {
        document.querySelectorAll('#addModal .quick-preset').forEach(p => p.classList.remove('selected'));
        if (el) el.classList.add('selected');
        document.getElementById('addDurationVal').focus();
        document.getElementById('addDurationVal').select();
    }

    // ─── Edit modal population ───────────────────────────────────────────
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const c = JSON.parse(this.dataset.course);
            document.getElementById('editId').value = c.id;
            document.getElementById('editCode').value = c.course_code;
            document.getElementById('editName').value = c.course_name;
            document.getElementById('editDesc').value = c.description || '';
            document.getElementById('editDurVal').value = c.duration_value;
            document.getElementById('editDurUnit').value = c.duration_unit;
            document.getElementById('editFee').value = c.fee;
            document.getElementById('editCap').value = c.max_capacity;
            document.getElementById('editMode').value = c.delivery_mode;
            document.getElementById('editStart').value = c.start_date || '';
            document.getElementById('editEnd').value = c.end_date || '';
            document.getElementById('editStatus').value = c.status;
            document.getElementById('editPrereqs').value = c.prerequisites || '';
            document.getElementById('editCategory').value = c.category_id || '';
            document.getElementById('editLevel').value = c.level_id || '';
            document.getElementById('editIntakeType').value = c.intake_type_id || '';
            document.getElementById('editMinAge').value = c.minimum_age || '';
        });
    });

    // ─── Classification "Suggest" — server-side §5/§8 single source of truth ─
    document.querySelectorAll('.suggest-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const scope = this.dataset.scope; // 'add' | 'edit'
            const modal = document.getElementById(scope === 'add' ? 'addModal' : 'editModal');
            const nameEl = modal.querySelector('[name="course_name"]');
            const valEl = document.getElementById(scope === 'add' ? 'addDurationVal' : 'editDurVal');
            const unitEl = document.getElementById(scope === 'add' ? 'addDurationUnit' : 'editDurUnit');
            const catEl = document.getElementById(scope === 'add' ? 'addCategory' : 'editCategory');
            const lvlEl = document.getElementById(scope === 'add' ? 'addLevel' : 'editLevel');
            const itEl = document.getElementById(scope === 'add' ? 'addIntakeType' : 'editIntakeType');
            const name = (nameEl && nameEl.value || '').trim();
            if (!name) { alert('Enter the course name first, then click Suggest.'); if (nameEl) nameEl.focus(); return; }

            const params = new URLSearchParams({
                action: 'classify',
                name: name,
                duration_value: (valEl && valEl.value) || '0',
                duration_unit: (unitEl && unitEl.value) || 'days',
                category_id: (catEl && catEl.value) || '0'
            });
            const original = this.innerHTML;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            fetch('ajax/short_course_ajax.php?' + params.toString())
                .then(r => r.json())
                .then(data => {
                    this.innerHTML = original;
                    if (!data || !data.success) return;
                    if (data.level_id) lvlEl.value = data.level_id;
                    if (data.intake_type_id) itEl.value = data.intake_type_id;
                })
                .catch(() => { this.innerHTML = original; });
        });
    });

    // ─── Student Management Modal ────────────────────────────────────────
    const SC_CSRF = <?= json_encode($scCsrfToken) ?>;
    let currentCourseId = null;
    let selectedStudentSID = null;
    let searchTimeout = null;

    document.querySelectorAll('.manage-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            currentCourseId = this.dataset.courseId;
            document.getElementById('manageCourseName').textContent =
                this.dataset.courseCode + ' — ' + this.dataset.courseName;
            loadEnrollments();
            loadLecturers();
            // Reset other tabs
            const laAlert = document.getElementById('assignLecturerAlert');
            if (laAlert) laAlert.style.display = 'none';
            clearSelectedStudent();
            document.getElementById('searchStudentInput').value = '';
            document.getElementById('searchResults').style.display = 'none';
            document.getElementById('enrollExistingAlert').style.display = 'none';
            document.getElementById('createAlert').style.display = 'none';
            ['newFname', 'newLname', 'newNrc', 'newMobile', 'newEmail', 'newDob', 'newNotes'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
        });
    });

    // Load enrolled students
    function loadEnrollments() {
        document.getElementById('enrolledLoading').style.display = '';
        document.getElementById('enrolledContent').style.display = 'none';

        fetch('ajax/short_course_ajax.php?action=get_enrollments&course_id=' + currentCourseId)
            .then(r => r.json())
            .then(data => {
                document.getElementById('enrolledLoading').style.display = 'none';
                document.getElementById('enrolledContent').style.display = '';
                const list = data.enrollments || [];

                if (list.length === 0) {
                    document.getElementById('enrolledEmpty').style.display = '';
                    document.getElementById('enrolledTableWrap').style.display = 'none';
                } else {
                    document.getElementById('enrolledEmpty').style.display = 'none';
                    document.getElementById('enrolledTableWrap').style.display = '';
                    const body = document.getElementById('enrolledBody');
                    body.innerHTML = '';
                    list.forEach((e, i) => {
                        const statusColors = { enrolled: 'bg-success', active: 'bg-info', completed: 'bg-primary', withdrawn: 'bg-warning text-dark', expired: 'bg-secondary' };
                        const row = document.createElement('tr');
                        row.innerHTML = `
                        <td class="text-muted">${i + 1}</td>
                        <td><code>${e.student_id}</code></td>
                        <td><strong>${esc(e.Fname)} ${esc(e.Lname)}</strong></td>
                        <td><small>${esc(e.mobile || '')}${e.email ? '<br>' + esc(e.email) : ''}</small></td>
                        <td><small>${new Date(e.enrollment_date).toLocaleDateString()}</small></td>
                        <td>
                            <select class="form-select form-select-sm" style="width:120px;"
                                    onchange="updateEnrollmentStatus(${e.id}, this.value)">
                                <option value="enrolled" ${e.status === 'enrolled' ? 'selected' : ''}>Enrolled</option>
                                <option value="active" ${e.status === 'active' ? 'selected' : ''}>Active</option>
                                <option value="completed" ${e.status === 'completed' ? 'selected' : ''}>Completed</option>
                                <option value="withdrawn" ${e.status === 'withdrawn' ? 'selected' : ''}>Withdrawn</option>
                                <option value="expired" ${e.status === 'expired' ? 'selected' : ''}>Expired</option>
                            </select>
                        </td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-danger" title="Remove" onclick="removeEnrollment(${e.id})">
                                <i class="fas fa-user-minus"></i>
                            </button>
                        </td>
                    `;
                        body.appendChild(row);
                    });
                }
            })
            .catch(err => {
                document.getElementById('enrolledLoading').style.display = 'none';
                document.getElementById('enrolledContent').style.display = '';
                document.getElementById('enrolledEmpty').style.display = '';
                document.getElementById('enrolledEmpty').innerHTML = '<p class="text-danger">Error loading enrollments.</p>';
            });
    }

    // ─── Student Search ──────────────────────────────────────────────────
    document.getElementById('searchStudentInput').addEventListener('input', function () {
        clearTimeout(searchTimeout);
        const q = this.value.trim();
        if (q.length < 2) {
            document.getElementById('searchResults').style.display = 'none';
            return;
        }
        searchTimeout = setTimeout(() => {
            fetch('ajax/short_course_ajax.php?action=search_students&q=' + encodeURIComponent(q) + '&course_id=' + currentCourseId)
                .then(r => r.json())
                .then(data => {
                    const box = document.getElementById('searchResults');
                    if (!data.results || data.results.length === 0) {
                        box.innerHTML = '<div class="item text-muted"><i class="fas fa-search me-2"></i>No students found</div>';
                    } else {
                        box.innerHTML = data.results.map(s =>
                            `<div class="item" onclick="selectStudent('${s.SID}','${esc(s.Fname)} ${esc(s.Lname)}')">
                            <strong>${esc(s.Fname)} ${esc(s.Lname)}</strong>
                            <span class="badge bg-light text-dark ms-2">${s.SID}</span>
                            ${s.mobile ? '<br><small class="text-muted">' + esc(s.mobile) + '</small>' : ''}
                        </div>`
                        ).join('');
                    }
                    box.style.display = '';
                });
        }, 300);
    });

    function selectStudent(sid, name) {
        selectedStudentSID = sid;
        document.getElementById('selectedStudentName').textContent = name;
        document.getElementById('selectedStudentId').textContent = sid;
        document.getElementById('selectedStudentCard').style.display = 'flex';
        document.getElementById('selectedStudentCard').style.cssText = 'display:flex !important';
        document.getElementById('searchResults').style.display = 'none';
        document.getElementById('searchStudentInput').value = '';
        document.getElementById('enrollExistingBtn').disabled = false;
    }

    function clearSelectedStudent() {
        selectedStudentSID = null;
        document.getElementById('selectedStudentCard').style.cssText = 'display:none !important';
        document.getElementById('enrollExistingBtn').disabled = true;
    }

    // ─── Enroll Existing Student ─────────────────────────────────────────
    function enrollExistingStudent() {
        if (!selectedStudentSID || !currentCourseId) return;
        const btn = document.getElementById('enrollExistingBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Enrolling...';

        const fd = new FormData();
        fd.append('action', 'enroll_student');
        fd.append('csrf_token', SC_CSRF);
        fd.append('course_id', currentCourseId);
        fd.append('student_id', selectedStudentSID);
        fd.append('notes', document.getElementById('enrollNotes').value);

        fetch('ajax/short_course_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                showAlert('enrollExistingAlert', data.message, data.success ? 'success' : 'danger');
                btn.innerHTML = '<i class="fas fa-user-check me-1"></i>Enroll Student';
                if (data.success) {
                    clearSelectedStudent();
                    document.getElementById('enrollNotes').value = '';
                    loadEnrollments();
                } else {
                    btn.disabled = false;
                }
            });
    }

    // ─── Create & Enroll New ─────────────────────────────────────────────
    function createAndEnroll() {
        const fname = document.getElementById('newFname').value.trim();
        const lname = document.getElementById('newLname').value.trim();
        if (!fname || !lname) {
            showAlert('createAlert', 'First name and last name are required.', 'danger');
            return;
        }

        const btn = event.target;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Creating...';

        const fd = new FormData();
        fd.append('action', 'create_enroll');
        fd.append('csrf_token', SC_CSRF);
        fd.append('course_id', currentCourseId);
        fd.append('fname', fname);
        fd.append('lname', lname);
        fd.append('sex', document.getElementById('newSex').value);
        fd.append('nrc_pass', document.getElementById('newNrc').value);
        fd.append('mobile', document.getElementById('newMobile').value);
        fd.append('email', document.getElementById('newEmail').value);
        fd.append('dob', document.getElementById('newDob').value);
        fd.append('notes', document.getElementById('newNotes').value);

        fetch('ajax/short_course_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-user-plus me-1"></i>Create Student & Enroll';
                if (data.success) {
                    let alertHtml = `<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>${esc(data.message)}</div>`;
                    alertHtml += `<div class="credential-card mb-2">
                    <p class="mb-1 fw-bold"><i class="fas fa-id-badge me-1"></i>Student Credentials</p>
                    <p class="mb-1">Student ID: <code>${data.student_id}</code></p>
                    <p class="mb-0">Default Password: <code>${data.default_password}</code></p>
                </div>`;
                    document.getElementById('createAlert').innerHTML = alertHtml;
                    document.getElementById('createAlert').style.display = '';
                    // Clear form
                    ['newFname', 'newLname', 'newNrc', 'newMobile', 'newEmail', 'newDob', 'newNotes'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.value = '';
                    });
                    loadEnrollments();
                } else {
                    showAlert('createAlert', data.message, 'danger');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-user-plus me-1"></i>Create Student & Enroll';
                showAlert('createAlert', 'Network error. Please try again.', 'danger');
            });
    }

    // ─── Update enrollment status ────────────────────────────────────────
    function updateEnrollmentStatus(enrollId, status) {
        const fd = new FormData();
        fd.append('action', 'update_enrollment');
        fd.append('csrf_token', SC_CSRF);
        fd.append('enrollment_id', enrollId);
        fd.append('status', status);

        fetch('ajax/short_course_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success) alert('Error: ' + data.message);
            });
    }

    // ─── Remove enrollment ──────────────────────────────────────────────
    function removeEnrollment(enrollId) {
        if (!confirm('Remove this student from the course?')) return;
        const fd = new FormData();
        fd.append('action', 'remove_enrollment');
        fd.append('csrf_token', SC_CSRF);
        fd.append('enrollment_id', enrollId);

        fetch('ajax/short_course_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) loadEnrollments();
                else alert('Error: ' + data.message);
            });
    }

    // ─── Lecturer assignment (enables short-course CA entry) ─────────────
    function loadLecturers() {
        const loading = document.getElementById('lecturersLoading');
        const wrap = document.getElementById('lecturersTableWrap');
        const empty = document.getElementById('lecturersEmpty');
        const select = document.getElementById('lecturerSelect');
        const assignBtn = document.getElementById('assignLecturerBtn');
        loading.style.display = '';
        wrap.style.display = 'none';
        empty.style.display = 'none';
        select.innerHTML = '<option value="" disabled selected>Loading lecturers…</option>';
        assignBtn.disabled = true;

        fetch('ajax/short_course_ajax.php?action=get_lecturers&course_id=' + currentCourseId)
            .then(r => r.json())
            .then(data => {
                loading.style.display = 'none';
                const assigned = data.assigned || [];
                const available = data.available || [];
                const assignedIds = new Set(assigned.map(a => a.staff_id));

                // Populate the dropdown with lecturers not already assigned.
                const selectable = available.filter(l => !assignedIds.has(l.staff_id));
                if (selectable.length === 0) {
                    select.innerHTML = '<option value="" disabled selected>' +
                        (available.length ? 'All eligible lecturers already assigned' : 'No lecturers available') + '</option>';
                    assignBtn.disabled = true;
                } else {
                    select.innerHTML = '<option value="" disabled selected>Select a lecturer…</option>' +
                        selectable.map(l => `<option value="${esc(l.staff_id)}">${esc(l.name || l.staff_id)} (${esc(l.staff_id)})</option>`).join('');
                    assignBtn.disabled = false;
                }

                // Render the assigned list.
                if (assigned.length === 0) {
                    empty.style.display = '';
                    wrap.style.display = 'none';
                } else {
                    empty.style.display = 'none';
                    wrap.style.display = '';
                    const body = document.getElementById('lecturersBody');
                    body.innerHTML = assigned.map((a, i) => `
                        <tr>
                            <td class="text-muted">${i + 1}</td>
                            <td><strong>${esc(a.name || '')}</strong></td>
                            <td><code>${esc(a.staff_id)}</code></td>
                            <td><span class="badge ${a.status === 'inactive' ? 'bg-secondary' : 'bg-success'}">${esc(a.status || 'active')}</span></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-danger" title="Unassign" onclick="unassignLecturer(${parseInt(a.id, 10)})">
                                    <i class="fas fa-user-minus"></i>
                                </button>
                            </td>
                        </tr>`).join('');
                }
            })
            .catch(() => {
                loading.style.display = 'none';
                empty.style.display = '';
                empty.innerHTML = '<p class="text-danger mb-0">Error loading lecturers.</p>';
            });
    }

    function assignLecturer() {
        const select = document.getElementById('lecturerSelect');
        const staffId = select.value;
        if (!staffId || !currentCourseId) return;
        const btn = document.getElementById('assignLecturerBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Assigning…';

        const fd = new FormData();
        fd.append('action', 'assign_lecturer');
        fd.append('csrf_token', SC_CSRF);
        fd.append('course_id', currentCourseId);
        fd.append('staff_id', staffId);

        fetch('ajax/short_course_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                btn.innerHTML = '<i class="fas fa-user-plus me-1"></i>Assign Lecturer';
                showAlert('assignLecturerAlert', data.message, data.success ? 'success' : 'danger');
                loadLecturers();
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-user-plus me-1"></i>Assign Lecturer';
                showAlert('assignLecturerAlert', 'Network error. Please try again.', 'danger');
            });
    }

    function unassignLecturer(assignmentId) {
        if (!confirm('Unassign this lecturer from the course? They will no longer be able to enter its CA.')) return;
        const fd = new FormData();
        fd.append('action', 'unassign_lecturer');
        fd.append('csrf_token', SC_CSRF);
        fd.append('assignment_id', assignmentId);
        fd.append('course_id', currentCourseId);

        fetch('ajax/short_course_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                showAlert('assignLecturerAlert', data.message, data.success ? 'success' : 'danger');
                loadLecturers();
            })
            .catch(() => showAlert('assignLecturerAlert', 'Network error while unassigning.', 'danger'));
    }

    // ─── Utilities ───────────────────────────────────────────────────────
    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function showAlert(id, msg, type) {
        const el = document.getElementById(id);
        el.innerHTML = `<div class="alert alert-${type}">${esc(msg)}</div>`;
        el.style.display = '';
        setTimeout(() => { el.style.display = 'none'; }, 6000);
    }

    // Bootstrap client-side validation for add/edit forms
    document.querySelectorAll('.needs-validation').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!form.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
            form.classList.add('was-validated');
        }, false);
    });

    // DataTable init
    $(document).ready(function () {
        if ($('#shortCoursesTable').length) {
            $('#shortCoursesTable').DataTable({
                pageLength: 15,
                lengthMenu: [[15, 25, 50, 100], [15, 25, 50, 100]],
                autoWidth: false,
                responsive: false,
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
                language: { search: "", searchPlaceholder: "Search short courses..." },
                columnDefs: [
                    { orderable: false, targets: -1 },
                    { className: 'text-center', targets: [0, 3, 6, 9] },
                    { className: 'text-end', targets: [5] }
                ]
            });
        }
        // Make the success/error banner impossible to miss after a POST round-trip.
        var banner = document.querySelector('.alert.alert-success, .alert.alert-danger, .alert.alert-warning');
        if (banner) { banner.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
</script>

<?php require_once "includes/footer.php"; ?>
</body>

</html>
