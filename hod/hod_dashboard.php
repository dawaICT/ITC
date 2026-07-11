<?php
/**
 * HOS Dashboard â€” Final Exam Management
 * â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
 * Fixes applied:
 *  - Schema-aware dept resolution (programs.department_id INT vs departments.department_id VARCHAR)
 *  - Uses course_lecturer as fallback for course listing when dept JOIN fails
 *  - Separated individual approve/reject forms from bulk form (no nested forms)
 *  - Bulk action no longer interpolates status directly into SQL (bound param)
 *  - CSV download uses ob_start/ob_end_clean to avoid header conflicts
 *  - error_reporting(0) moved before nav.php include
 *  - submitted_by column existence checked before SELECT
 *  - Graceful empty-state for all tabs
 */

error_reporting(0);  // Must be BEFORE nav (which triggers error_bootstrap)
// Buffer the whole page: nav.php emits the document chrome, but the POST
// action handlers below still need to send a Location redirect afterwards.
// Without buffering that raises "headers already sent" (now a fatal under
// error_bootstrap's exception mode). Start our OWN buffer unconditionally —
// XAMPP's default 4 KB output_buffering auto-flushes once nav chrome exceeds
// it, which would send headers before the POST handler can redirect.
ob_start();
$page_title = 'HOS â€” Final Exam Management';
require "includes/nav.php";
require_once __DIR__ . '/includes/hod_schema_helpers.php';
require_once dirname(__DIR__) . '/includes/grading_helpers.php'; // wuc_result_exam_written / NE
require_once dirname(__DIR__) . '/includes/audit.php';

// â”€â”€ 0. Schema detection (no runtime DDL: the app user is DML-only and
// `exams` is a compatibility view over semester_assessment) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$migrationDone = false;
$chkStatus = @$db->query("SHOW COLUMNS FROM `exams` LIKE 'status'");
$hasExamStatus = ($chkStatus && $chkStatus->num_rows > 0);
$chkSub = @$db->query("SHOW COLUMNS FROM `exams` LIKE 'submitted_by'");
$hasSubmittedBy = ($chkSub && $chkSub->num_rows > 0);

require_once dirname(__DIR__) . '/includes/auth_helpers.php';
$csrfToken = wuc_csrf_token();

// â”€â”€ 1. Resolve the HoD's department â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$hod_staff_id = $_SESSION['staff_id'] ?? '';
$dept_id      = $_SESSION['dept_id'] ?? '';
$dept_name    = '';

$deptContext = hod_resolve_department($db, (string)$hod_staff_id);
$dept_id = (string)$deptContext['id'];
$dept_name = (string)($deptContext['name'] !== '' ? $deptContext['name'] : $deptContext['id']);
$sectionDeptIds = hod_section_department_ids($deptContext);

// â”€â”€ Helper: grade calculator â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function hodGetGrade(int $total): string {
    require_once dirname(__DIR__) . '/includes/grading_helpers.php';
    return wuc_result_grade((float)$total);
}

// â”€â”€ Helper: get course codes for this HoD's department â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Strategy: Use course_lecturer (staff assignments) as the most reliable link
// since programs.department_id (INT) doesn't join departments.department_id (VARCHAR).
$hodCourseCodes = hod_section_course_codes($db, $deptContext, (string)$hod_staff_id);

// â”€â”€ 2. Handle POST actions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Approvals must stay inside this HOS's section: every UPDATE is restricted to
// exam rows whose Course_Code belongs to the section's course list.

$examScopeClause = '';
$examScopeTypes = '';
$examScopeParams = [];
if (!empty($hodCourseCodes)) {
    $examScopeClause = ' AND `Course_Code` IN (' . implode(',', array_fill(0, count($hodCourseCodes), '?')) . ')';
    $examScopeTypes = str_repeat('s', count($hodCourseCodes));
    $examScopeParams = $hodCourseCodes;
}

// 2a. Single approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exam_action'], $_POST['exam_id'])) {
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['errorMsg'] = 'Your session token expired. Please retry the action.';
        header('Location: hod_dashboard.php?tab=approvals');
        exit;
    }
    $action = in_array($_POST['exam_action'], ['Approved', 'Rejected']) ? $_POST['exam_action'] : null;
    $examId = (int)$_POST['exam_id'];
    if ($action && $examId > 0 && $examScopeClause !== '') {
        $upd = @$db->prepare("UPDATE `exams` SET `status` = ? WHERE `id` = ?{$examScopeClause}");
        if ($upd) {
            $params = array_merge([$action, $examId], $examScopeParams);
            $upd->bind_param('si' . $examScopeTypes, ...$params);
            $upd->execute();
            $affected = $upd->affected_rows;
            $upd->close();
            if ($affected > 0) {
                $_SESSION['successMsg'] = "Exam record #<strong>{$examId}</strong> marked as <strong>{$action}</strong>.";
                if (function_exists('audit_log_current_user')) {
                    audit_log_current_user($db, 'results.exam_decision', [
                        'exam_id' => $examId,
                        'decision' => $action,
                    ]);
                }
            } else {
                $_SESSION['errorMsg'] = 'That exam record is outside your section, so it was not changed.';
            }
        } else {
            $_SESSION['errorMsg'] = "DB error: " . htmlspecialchars($db->error);
        }
    } elseif ($examScopeClause === '') {
        $_SESSION['errorMsg'] = 'No courses are mapped to your section, so approvals are disabled.';
    }
    $redir = 'hod_dashboard.php?tab=approvals';
    if (isset($_POST['status_filter'])) $redir .= '&status_filter=' . urlencode($_POST['status_filter']);
    header("Location: {$redir}");
    exit;
}

// 2b. Bulk approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'], $_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['errorMsg'] = 'Your session token expired. Please retry the action.';
        header('Location: hod_dashboard.php?tab=approvals');
        exit;
    }
    $bulkAction = in_array($_POST['bulk_action'], ['Approved', 'Rejected']) ? $_POST['bulk_action'] : null;
    if ($bulkAction && $examScopeClause !== '') {
        $ids = array_filter(array_map('intval', $_POST['selected_ids']), fn($v) => $v > 0);
        if (count($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types        = str_repeat('i', count($ids));
            // Status is bound as the first param to avoid SQL injection
            $sql  = "UPDATE `exams` SET `status` = ? WHERE `id` IN ({$placeholders}){$examScopeClause}";
            $allTypes = 's' . $types . $examScopeTypes;
            $allParams = array_merge([$bulkAction], array_values($ids), $examScopeParams);
            $upd = @$db->prepare($sql);
            if ($upd) {
                $upd->bind_param($allTypes, ...$allParams);
                $upd->execute();
                $affected = $upd->affected_rows;
                $upd->close();
                $_SESSION['successMsg'] = "<strong>{$affected}</strong> record(s) marked as <strong>{$bulkAction}</strong>.";
                if ($affected < count($ids)) {
                    $_SESSION['errorMsg'] = (count($ids) - $affected) . ' record(s) were outside your section and were skipped.';
                }
                if (function_exists('audit_log_current_user')) {
                    audit_log_current_user($db, 'results.exam_bulk_decision', [
                        'decision' => $bulkAction,
                        'requested' => count($ids),
                        'updated' => $affected,
                    ]);
                }
            } else {
                $_SESSION['errorMsg'] = "Bulk update failed: " . htmlspecialchars($db->error);
            }
        }
    } elseif ($examScopeClause === '') {
        $_SESSION['errorMsg'] = 'No courses are mapped to your section, so approvals are disabled.';
    }
    header("Location: hod_dashboard.php?tab=approvals");
    exit;
}

// 2c. CSV Download â€” use output buffering to avoid header conflicts
if (isset($_GET['download_csv']) && $_GET['download_csv'] === '1') {
    if (function_exists('audit_log_current_user')) {
        audit_log_current_user($db, 'reports.hos_results.export', [
            'department_id' => $dept_id,
            'department_name' => $dept_name,
            'course_count' => count($hodCourseCodes),
        ]);
    }
    // Clean any buffered output from nav.php
    while (ob_get_level()) ob_end_clean();

    $filename = 'dept_results_' . preg_replace('/[^a-z0-9_-]/i', '_', $dept_id) . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Student ID', 'Course Code', 'Course Name', 'Exam Marks', 'Total Marks', 'Grade', 'Semester', 'Year', 'Status']);

    if (!empty($hodCourseCodes)) {
        $placeholders = implode(',', array_fill(0, count($hodCourseCodes), '?'));
        $types = str_repeat('s', count($hodCourseCodes));
        $csvSql = "
            SELECT e.id, e.Sid, e.Course_Code,
                   COALESCE(c.course_name, e.Course_Code) AS course_name,
                   e.Exam_marks, e.Total_marks, e.semester, e.Year, e.status
            FROM exams e
            LEFT JOIN courses c ON e.Course_Code = c.course_code
            WHERE e.Course_Code IN ({$placeholders})
            ORDER BY e.Sid, e.Course_Code, e.semester, e.Year
        ";
        $csvStmt = @$db->prepare($csvSql);
        if ($csvStmt) {
            $csvStmt->bind_param($types, ...$hodCourseCodes);
            $csvStmt->execute();
            $csvRes = $csvStmt->get_result();
            $n = 1;
            while ($r = $csvRes->fetch_object()) {
                // Exam_marks / Total_marks both map to the Exam column; when the
                // student never sat the exam (NULL) report NE rather than 0/F.
                $wrote = wuc_result_exam_written($r->Total_marks);
                fputcsv($out, [
                    $n++, $r->Sid, $r->Course_Code, $r->course_name,
                    $wrote ? $r->Exam_marks : WUC_RESULT_NOT_EXAMINED,
                    $wrote ? $r->Total_marks : WUC_RESULT_NOT_EXAMINED,
                    $wrote ? hodGetGrade((int)$r->Total_marks) : WUC_RESULT_NOT_EXAMINED,
                    $r->semester, $r->Year, $r->status
                ]);
            }
            $csvStmt->close();
        }
    }
    fclose($out);
    exit;
}

// â”€â”€ 3. Fetch department courses â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$deptCourses = [];
if (!empty($hodCourseCodes)) {
    $placeholders = implode(',', array_fill(0, count($hodCourseCodes), '?'));
    $types = str_repeat('s', count($hodCourseCodes));
    $courseCreditsCol = hod_detect_column($db, 'courses', ['credits', 'credit_hours', 'credit_units']);
    $courseCreditsExpr = $courseCreditsCol ? "COALESCE(c.`{$courseCreditsCol}`, 0) AS credits" : "0 AS credits";
    $cSql = "
        SELECT c.course_code, c.course_name,
               {$courseCreditsExpr},
               GROUP_CONCAT(DISTINCT p.program_name ORDER BY p.program_name SEPARATOR '||') AS program_names,
               MIN(pc.semester) AS offered_semester
        FROM courses c
        LEFT JOIN program_courses pc ON c.course_code = pc.course_code
        LEFT JOIN programs p ON pc.program_code = p.program_code
        WHERE c.course_code IN ({$placeholders})
        GROUP BY c.course_code, c.course_name
        ORDER BY c.course_code ASC
    ";
    $cStmt = @$db->prepare($cSql);
    if ($cStmt) {
        $cStmt->bind_param($types, ...$hodCourseCodes);
        $cStmt->execute();
        $cRes = $cStmt->get_result();
        while ($cr = $cRes->fetch_object()) {
            $cr->programs = $cr->program_names ? explode('||', $cr->program_names) : [];
            $deptCourses[$cr->course_code] = $cr;
        }
        $cStmt->close();
    }
}

// â”€â”€ 4. Fetch exam marks for approval â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$examRecords  = [];
$filterStatus = $_GET['status_filter'] ?? 'Pending';
if (!in_array($filterStatus, ['Pending', 'Approved', 'Rejected', 'All'])) $filterStatus = 'Pending';

if (!empty($hodCourseCodes)) {
    $placeholders  = implode(',', array_fill(0, count($hodCourseCodes), '?'));
    $types = str_repeat('s', count($hodCourseCodes));
    $statusClause  = $filterStatus === 'All' ? '' : "AND e.status = '" . $db->real_escape_string($filterStatus) . "'";

    $subCol = $hasSubmittedBy ? 'e.submitted_by' : "NULL AS submitted_by";
    $eSql = "
        SELECT e.id, e.Sid, e.Course_Code,
               COALESCE(c.course_name, e.Course_Code) AS course_name,
               e.Exam_marks, e.Total_marks, e.semester, e.Year, e.status,
               {$subCol}
        FROM exams e
        LEFT JOIN courses c ON e.Course_Code = c.course_code
        WHERE e.Course_Code IN ({$placeholders}) {$statusClause}
        ORDER BY e.status ASC, e.Year DESC, e.semester DESC, e.Course_Code ASC
    ";
    $eStmt = @$db->prepare($eSql);
    if ($eStmt) {
        $eStmt->bind_param($types, ...$hodCourseCodes);
        $eStmt->execute();
        $eRes = $eStmt->get_result();
        while ($er = $eRes->fetch_object()) {
            $examRecords[] = $er;
        }
        $eStmt->close();
    }
}

// â”€â”€ 5. Quick counts â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$totalCourses  = count($deptCourses);
$pendingCount  = 0;
$approvedCount = 0;
$rejectedCount = 0;

if (!empty($hodCourseCodes)) {
    $placeholders = implode(',', array_fill(0, count($hodCourseCodes), '?'));
    $types = str_repeat('s', count($hodCourseCodes));
    $cntSql = "
        SELECT e.status, COUNT(*) AS cnt
        FROM exams e
        WHERE e.Course_Code IN ({$placeholders})
        GROUP BY e.status
    ";
    $cntStmt = @$db->prepare($cntSql);
    if ($cntStmt) {
        $cntStmt->bind_param($types, ...$hodCourseCodes);
        $cntStmt->execute();
        $cntRes = $cntStmt->get_result();
        while ($c = $cntRes->fetch_object()) {
            match($c->status) {
                'Pending'  => $pendingCount  = (int)$c->cnt,
                'Approved' => $approvedCount = (int)$c->cnt,
                'Rejected' => $rejectedCount = (int)$c->cnt,
                default    => null
            };
        }
        $cntStmt->close();
    }
}

$activeTab = in_array($_GET['tab'] ?? '', ['overview','courses','approvals','reports'])
             ? $_GET['tab'] : 'overview';
?>


<div class="container-fluid px-4 portal-dashboard hod-page hod-exam-dashboard">

    <?php if ($migrationDone): ?>
    <div class="migration-notice mt-3 d-print-none">
        <i class="fas fa-database me-2"></i>
        Schema updated: <code>status</code> &amp; <code>submitted_by</code> columns added to <code>exams</code>.
    </div>
    <?php endif; ?>

    <!-- â”€â”€ Page Header â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <div class="hod-page-header mt-4 mb-4 d-print-none">
        <div class="row align-items-center g-3">
            <div class="col-lg-6">
                <h1><i class="fas fa-clipboard-check me-2"></i>Final Exam Management</h1>
                <div class="subtitle mt-1">
                    <i class="fas fa-building me-1"></i>
                    <?php echo htmlspecialchars($dept_name ?: ($dept_id ?: 'No Department Assigned')); ?>
                    <?php if ($dept_id): ?>
                        <span class="ms-2 badge bg-light text-dark small"><?php echo htmlspecialchars($dept_id); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="row g-2">
                    <div class="col-4">
                        <div class="stat-mini">
                            <div class="stat-val"><?php echo $totalCourses; ?></div>
                            <div class="stat-lbl">Courses</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="stat-mini">
                            <div class="stat-val"><?php echo $pendingCount; ?></div>
                            <div class="stat-lbl">Pending</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="stat-mini">
                            <div class="stat-val"><?php echo $approvedCount; ?></div>
                            <div class="stat-lbl">Approved</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- â”€â”€ Flash Messages â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <?php if (isset($_SESSION['successMsg'])): ?>
        <div class="alert alert-success alert-dismissible fade show d-print-none hod-alert-rounded" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['successMsg']; unset($_SESSION['successMsg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['errorMsg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show d-print-none hod-alert-rounded" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['errorMsg']; unset($_SESSION['errorMsg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- No department warning -->
    <?php if ($hod_staff_id && !$dept_id): ?>
    <div class="no-dept-warn mb-4 d-print-none">
        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
        <h5>No Department Assigned</h5>
        <p class="mb-0 small">Your staff account (<strong><?php echo htmlspecialchars($hod_staff_id); ?></strong>) does not have a department linked. Please ask an administrator to assign your department ID.</p>
    </div>
    <?php endif; ?>

    <!-- â”€â”€ Tab Navigation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <ul class="nav nav-pills mb-4 flex-wrap gap-2 d-print-none" id="hodTabs" role="tablist">
        <?php
        $tabs = [
            'overview'  => ['icon' => 'fas fa-th-large',   'label' => 'Overview'],
            'courses'   => ['icon' => 'fas fa-book',        'label' => 'Dept Courses'],
            'approvals' => ['icon' => 'fas fa-tasks',       'label' => 'Mark Approvals'],
            'reports'   => ['icon' => 'fas fa-file-csv',    'label' => 'Reports'],
        ];
        foreach ($tabs as $key => $tab):
            $isActive = $activeTab === $key;
        ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $isActive ? 'active' : ''; ?>" href="?tab=<?php echo $key; ?>">
                <i class="<?php echo $tab['icon']; ?> me-1"></i><?php echo $tab['label']; ?>
                <?php if ($key === 'approvals' && $pendingCount > 0): ?>
                    <span class="badge bg-warning text-dark ms-1"><?php echo $pendingCount; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <!-- TAB: OVERVIEW                                                     -->
    <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <?php if ($activeTab === 'overview'): ?>
    <div class="row g-3 mb-4">
        <!-- Stat cards -->
        <div class="col-6 col-md-3">
            <div class="ov-card bg-white h-100">
                <div class="ov-icon ov-icon-grad-primary mx-auto">
                    <i class="fas fa-book-open text-white"></i>
                </div>
                <h2><?php echo $totalCourses; ?></h2>
                <p>Department Courses</p>
                <a href="?tab=courses" class="btn btn-sm btn-outline-primary rounded-pill mt-2 px-3">View All</a>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ov-card bg-white h-100">
                <div class="ov-icon ov-icon-grad-warning mx-auto">
                    <i class="fas fa-hourglass-half text-white"></i>
                </div>
                <h2><?php echo $pendingCount; ?></h2>
                <p>Pending Approvals</p>
                <a href="?tab=approvals&status_filter=Pending" class="btn btn-sm btn-outline-warning rounded-pill mt-2 px-3">Review</a>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ov-card bg-white h-100">
                <div class="ov-icon ov-icon-grad-success mx-auto">
                    <i class="fas fa-check-double text-white"></i>
                </div>
                <h2><?php echo $approvedCount; ?></h2>
                <p>Approved Results</p>
                <a href="?tab=approvals&status_filter=Approved" class="btn btn-sm btn-outline-success rounded-pill mt-2 px-3">View</a>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ov-card bg-white h-100">
                <div class="ov-icon ov-icon-grad-danger mx-auto">
                    <i class="fas fa-times-circle text-white"></i>
                </div>
                <h2><?php echo $rejectedCount; ?></h2>
                <p>Rejected Results</p>
                <a href="?tab=approvals&status_filter=Rejected" class="btn btn-sm btn-outline-danger rounded-pill mt-2 px-3">View</a>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="hod-card bg-white mb-4">
        <div class="card-header"><i class="fas fa-bolt me-2 text-warning"></i>Quick Actions</div>
        <div class="card-body row g-3 py-3">
            <div class="col-md-4">
                <a href="?tab=approvals&status_filter=Pending" class="btn btn-primary w-100 py-3 rounded-3 d-flex align-items-center justify-content-center gap-2">
                    <i class="fas fa-tasks"></i> Review Pending Marks
                    <?php if ($pendingCount > 0): ?><span class="badge bg-warning text-dark"><?php echo $pendingCount; ?></span><?php endif; ?>
                </a>
            </div>
            <div class="col-md-4">
                <a href="?tab=courses" class="btn btn-outline-primary w-100 py-3 rounded-3 d-flex align-items-center justify-content-center gap-2">
                    <i class="fas fa-book"></i> View Department Courses
                </a>
            </div>
            <div class="col-md-4">
                <a href="?download_csv=1" class="btn btn-success w-100 py-3 rounded-3 d-flex align-items-center justify-content-center gap-2">
                    <i class="fas fa-download"></i> Download Results CSV
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>


    <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <!-- TAB: DEPARTMENT COURSES                                           -->
    <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <?php if ($activeTab === 'courses'): ?>
    <div class="hod-card bg-white">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="fas fa-book me-2 text-hod-primary"></i>
                Department Courses
                <span class="badge bg-secondary ms-2"><?php echo $totalCourses; ?></span>
            </span>
            <input type="text" id="courseSearch" class="form-control form-control-sm search-course-input" placeholder="Search coursesâ€¦">
        </div>
        <div class="tbl-wrap">
            <table class="table table-hover align-middle tbl-hod" id="coursesTable">
                <thead class="table-light">
                    <tr>
                        <th class="th-w-50">#</th>
                        <th>Code</th>
                        <th>Course Name</th>
                        <th class="text-center">Credits</th>
                        <th class="text-center">Offered Sem.</th>
                        <th>Program(s)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deptCourses)): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted">
                            <i class="fas fa-info-circle fa-lg mb-2 d-block"></i>
                            No courses found<?php echo $dept_id ? " for department <strong>{$dept_id}</strong>" : ' â€” department not assigned'; ?>.
                            <?php if (empty($hodCourseCodes)): ?>
                            <br><small class="text-muted">Tip: Assign courses to lecturers in your department via the Course Lecturer assignments.</small>
                            <?php endif; ?>
                        </td></tr>
                    <?php else: $n = 1; foreach ($deptCourses as $cc): ?>
                        <tr>
                            <td class="text-muted"><?php echo $n++; ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($cc->course_code); ?></span></td>
                            <td class="fw-semibold"><?php echo htmlspecialchars($cc->course_name ?: $cc->course_code); ?></td>
                            <td class="text-center"><?php echo (int)$cc->credits ?: 'â€”'; ?></td>
                            <td class="text-center">
                                <?php if ($cc->offered_semester): ?>
                                    <span class="bs-grade">Sem <?php echo (int)$cc->offered_semester; ?></span>
                                <?php else: ?>â€”<?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($cc->programs)): ?>
                                    <?php foreach (array_unique($cc->programs) as $pn): ?>
                                        <span class="prog-tag"><?php echo htmlspecialchars($pn); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted small">â€”</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>


    <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <!-- TAB: MARK APPROVALS                                               -->
    <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <?php if ($activeTab === 'approvals'): ?>
    <div class="hod-card bg-white">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>
                <i class="fas fa-tasks me-2 text-hod-primary"></i>
                Exam Mark Approvals
                <span class="badge bg-secondary ms-2"><?php echo count($examRecords); ?> shown</span>
            </span>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <!-- Status filter group -->
                <div class="btn-group btn-group-sm">
                    <?php
                    $filters = [
                        'Pending'  => ['cls' => 'btn-outline-warning',   'cnt' => $pendingCount],
                        'Approved' => ['cls' => 'btn-outline-success',   'cnt' => $approvedCount],
                        'Rejected' => ['cls' => 'btn-outline-danger',    'cnt' => $rejectedCount],
                        'All'      => ['cls' => 'btn-outline-secondary', 'cnt' => $pendingCount + $approvedCount + $rejectedCount],
                    ];
                    foreach ($filters as $fk => $fv):
                    ?>
                    <a href="?tab=approvals&status_filter=<?php echo $fk; ?>"
                       class="btn <?php echo $fv['cls']; ?> <?php echo $filterStatus === $fk ? 'active' : ''; ?>">
                        <?php echo $fk; ?>
                        <span class="badge <?php echo $filterStatus === $fk ? 'bg-white text-dark' : ($fk === 'Pending' ? 'bg-warning text-dark' : ($fk === 'All' ? 'bg-secondary' : 'bg-' . ($fk === 'Approved' ? 'success' : 'danger'))); ?>">
                            <?php echo $fv['cnt']; ?>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <input type="text" id="examSearch" class="form-control form-control-sm search-exam-input" placeholder="Searchâ€¦">
            </div>
        </div>

        <!-- Bulk form (checkboxes + bulk buttons only) -->
        <form method="POST" action="hod_dashboard.php?tab=approvals" id="bulkForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($filterStatus); ?>">

            <?php if ($filterStatus === 'Pending' && !empty($examRecords)): ?>
            <div class="px-3 py-2 bg-light border-bottom d-flex align-items-center gap-3">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="selectAll">
                    <label class="form-check-label small fw-semibold" for="selectAll">Select All</label>
                </div>
                <button type="submit" name="bulk_action" value="Approved" class="btn-act-approve">
                    <i class="fas fa-check me-1"></i>Approve Selected
                </button>
                <button type="submit" name="bulk_action" value="Rejected" class="btn-act-reject">
                    <i class="fas fa-times me-1"></i>Reject Selected
                </button>
            </div>
            <?php endif; ?>

            <div class="tbl-wrap">
                <table class="table table-hover align-middle tbl-hod" id="approvalsTable">
                    <thead class="table-light">
                        <tr>
                            <?php if ($filterStatus === 'Pending'): ?><th class="th-w-40"></th><?php endif; ?>
                            <th class="th-w-45">#</th>
                            <th>Student ID</th>
                            <th>Course</th>
                            <th>Course Name</th>
                            <th class="text-center">Marks</th>
                            <th class="text-center">Grade</th>
                            <th class="text-center">Sem</th>
                            <th class="text-center">Year</th>
                            <th>Status</th>
                            <th class="text-center th-actions-min">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($examRecords)): ?>
                            <tr><td colspan="<?php echo $filterStatus === 'Pending' ? 11 : 10; ?>" class="text-center py-5 text-muted">
                                <i class="fas fa-check-circle fa-lg mb-2 d-block text-success"></i>
                                No <strong><?php echo strtolower($filterStatus === 'All' ? '' : $filterStatus); ?></strong> exam records<?php echo $filterStatus === 'All' ? ' at all' : ' found'; ?>.
                            </td></tr>
                        <?php else: $n = 1; foreach ($examRecords as $ex):
                            // Only grade a sat exam; otherwise show NE so a CA-only
                            // row isn't presented as a failed (F) final result.
                            $wroteExam = wuc_result_exam_written($ex->Total_marks);
                            $grade = $wroteExam ? hodGetGrade((int)$ex->Total_marks) : WUC_RESULT_NOT_EXAMINED;
                            $bsCls = match($ex->status) {
                                'Pending'  => 'bs-pending',
                                'Approved' => 'bs-approved',
                                'Rejected' => 'bs-rejected',
                                default    => 'badge bg-secondary',
                            };
                        ?>
                            <tr>
                                <?php if ($filterStatus === 'Pending'): ?>
                                <td>
                                    <input class="form-check-input row-check" type="checkbox"
                                           name="selected_ids[]" value="<?php echo (int)$ex->id; ?>">
                                </td>
                                <?php endif; ?>
                                <td class="text-muted"><?php echo $n++; ?></td>
                                <td class="fw-bold font-monospace"><?php echo htmlspecialchars($ex->Sid); ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($ex->Course_Code); ?></span></td>
                                <td><?php echo htmlspecialchars($ex->course_name); ?></td>
                                <td class="text-center fw-bold"><?php echo $wroteExam ? (int)$ex->Total_marks : WUC_RESULT_NOT_EXAMINED; ?></td>
                                <td class="text-center"><span class="bs-grade"><?php echo $grade; ?></span></td>
                                <td class="text-center"><?php echo (int)$ex->semester; ?></td>
                                <td class="text-center"><?php echo (int)$ex->Year; ?></td>
                                <td><span class="<?php echo $bsCls; ?>"><?php echo htmlspecialchars($ex->status); ?></span></td>
                                <td class="text-center text-nowrap">
                                    <!-- Individual action forms (separate from bulk, no nesting) -->
                                    <?php if ($ex->status === 'Pending'): ?>
                                        <form method="POST" action="hod_dashboard.php" class="d-inline" onsubmit="return confirm('Approve this record?')">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="exam_action" value="Approved">
                                            <input type="hidden" name="exam_id" value="<?php echo (int)$ex->id; ?>">
                                            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($filterStatus); ?>">
                                            <button type="submit" class="btn-act-approve" title="Approve"><i class="fas fa-check"></i></button>
                                        </form>
                                        <form method="POST" action="hod_dashboard.php" class="d-inline" onsubmit="return confirm('Reject this record?')">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="exam_action" value="Rejected">
                                            <input type="hidden" name="exam_id" value="<?php echo (int)$ex->id; ?>">
                                            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($filterStatus); ?>">
                                            <button type="submit" class="btn-act-reject" title="Reject"><i class="fas fa-times"></i></button>
                                        </form>
                                    <?php elseif ($ex->status === 'Approved'): ?>
                                        <span class="text-success"><i class="fas fa-check-circle" title="Approved"></i></span>
                                    <?php elseif ($ex->status === 'Rejected'): ?>
                                        <form method="POST" action="hod_dashboard.php" class="d-inline" onsubmit="return confirm('Re-approve this record?')">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="exam_action" value="Approved">
                                            <input type="hidden" name="exam_id" value="<?php echo (int)$ex->id; ?>">
                                            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($filterStatus); ?>">
                                            <button type="submit" class="btn-act-reapprove" title="Re-approve"><i class="fas fa-redo"></i> Re-approve</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </form><!-- end bulkForm -->
    </div>
    <?php endif; ?>


    <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <!-- TAB: REPORTS                                                       -->
    <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <?php if ($activeTab === 'reports'): ?>
    <div class="row g-4">
        <!-- CSV download card -->
        <div class="col-md-6">
            <div class="hod-card bg-white h-100">
                <div class="card-header"><i class="fas fa-file-csv me-2 text-success"></i>Download Department Results</div>
                <div class="card-body text-center py-5">
                    <div class="ov-icon report-download-icon ov-icon-grad-success mx-auto mb-4">
                        <i class="fas fa-download text-white fa-2x"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Complete Results Report</h5>
                    <p class="text-muted small mb-4">
                        All exam results for courses in
                        <strong><?php echo htmlspecialchars($dept_name ?: $dept_id ?: 'your department'); ?></strong>.<br>
                        Includes student IDs, marks, grades, and approval status.
                    </p>
                    <?php if (!empty($hodCourseCodes)): ?>
                        <a href="?download_csv=1" class="btn btn-success btn-lg rounded-pill px-5">
                            <i class="fas fa-download me-2"></i>Download CSV
                        </a>
                    <?php else: ?>
                        <button class="btn btn-secondary btn-lg rounded-pill px-5" disabled>
                            <i class="fas fa-ban me-2"></i>No courses available
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Summary card -->
        <div class="col-md-6">
            <div class="hod-card bg-white h-100">
                <div class="card-header"><i class="fas fa-chart-pie me-2 text-hod-primary"></i>Results Summary</div>
                <div class="card-body">
                    <?php $totalRes = max($approvedCount + $pendingCount + $rejectedCount, 1); ?>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small fw-semibold text-muted">Approved</span>
                            <span class="fw-bold text-success"><?php echo $approvedCount; ?></span>
                        </div>
                        <div class="progress hod-progress">
                            <div class="progress-bar bg-success js-progress-width" data-width="<?php echo round($approvedCount / $totalRes * 100); ?>"></div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small fw-semibold text-muted">Pending</span>
                            <span class="fw-bold text-warning"><?php echo $pendingCount; ?></span>
                        </div>
                        <div class="progress hod-progress">
                            <div class="progress-bar bg-warning js-progress-width" data-width="<?php echo round($pendingCount / $totalRes * 100); ?>"></div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small fw-semibold text-muted">Rejected</span>
                            <span class="fw-bold text-danger"><?php echo $rejectedCount; ?></span>
                        </div>
                        <div class="progress hod-progress">
                            <div class="progress-bar bg-danger js-progress-width" data-width="<?php echo round($rejectedCount / $totalRes * 100); ?>"></div>
                        </div>
                    </div>

                    <hr>
                    <div class="row text-center g-2">
                        <div class="col-4">
                            <div class="fw-bold fs-5"><?php echo $totalCourses; ?></div>
                            <div class="text-muted small">Courses</div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold fs-5"><?php echo $approvedCount + $pendingCount + $rejectedCount; ?></div>
                            <div class="text-muted small">Total Records</div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold fs-5"><?php echo $totalRes > 1 ? round($approvedCount / $totalRes * 100) : 0; ?>%</div>
                            <div class="text-muted small">Approved Rate</div>
                        </div>
                    </div>

                    <?php if ($approvedCount === 0 && $pendingCount === 0 && $rejectedCount === 0): ?>
                        <div class="text-center text-muted small mt-3">
                            <i class="fas fa-info-circle me-1"></i>No exam records found for your department.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /container-fluid -->

<script>
// â”€â”€ Live search â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
(function() {
    function attachSearch(inputId, tableId) {
        var inp = document.getElementById(inputId);
        if (!inp) return;
        inp.addEventListener('input', function() {
            var q = this.value.toLowerCase().trim();
            var rows = document.querySelectorAll('#' + tableId + ' tbody tr');
            rows.forEach(function(row) {
                row.style.display = (!q || row.textContent.toLowerCase().includes(q)) ? '' : 'none';
            });
        });
    }
    attachSearch('courseSearch', 'coursesTable');
    attachSearch('examSearch', 'approvalsTable');

    // Set progress widths from data attributes (moved out of inline style).
    document.querySelectorAll('.js-progress-width').forEach(function(bar) {
        var width = parseFloat(bar.getAttribute('data-width') || '0');
        if (isNaN(width)) width = 0;
        width = Math.max(0, Math.min(100, width));
        bar.style.width = width + '%';
        bar.setAttribute('aria-valuenow', String(Math.round(width)));
    });

    // â”€â”€ Select-all checkbox â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    var selAll = document.getElementById('selectAll');
    if (selAll) {
        selAll.addEventListener('change', function() {
            document.querySelectorAll('.row-check').forEach(function(cb) {
                cb.checked = selAll.checked;
            });
        });
        // Sync select-all when individual checkboxes change
        document.querySelectorAll('.row-check').forEach(function(cb) {
            cb.addEventListener('change', function() {
                var all = document.querySelectorAll('.row-check');
                var checked = document.querySelectorAll('.row-check:checked');
                selAll.indeterminate = checked.length > 0 && checked.length < all.length;
                selAll.checked = checked.length === all.length;
            });
        });
    }

    // â”€â”€ Bulk form: warn if nothing selected â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    var bulkForm = document.getElementById('bulkForm');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function(e) {
            var checked = document.querySelectorAll('.row-check:checked');
            if (checked.length === 0) {
                e.preventDefault();
                alert('Please select at least one record first.');
            }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

