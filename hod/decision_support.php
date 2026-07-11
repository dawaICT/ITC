<?php
/**
 * Head of Section — Decision Support System.
 *
 * Rule-based exception reporting over live tables. Detectors run on page load,
 * findings are upserted into hos_dss_findings so the review workflow
 * (pending → reviewed → resolved) survives reloads, and findings that stop
 * firing are auto-resolved. Everything is scoped to the HOS's own section.
 */

error_reporting(0);
// Buffer output: nav.php emits chrome, but the status-update handler below
// redirects afterwards. XAMPP's default 4 KB buffer would auto-flush headers
// before then, so start our own unbounded buffer first.
ob_start();
$page_title = 'Decision Support';
require __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/includes/hod_schema_helpers.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
require_once dirname(__DIR__) . '/includes/hos_report_insights.php';
require_once dirname(__DIR__) . '/includes/schema_guard.php';
require_once dirname(__DIR__) . '/includes/audit.php';

$csrfToken = wuc_csrf_token();
$hodStaffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
$deptContext = hod_resolve_department($db, $hodStaffId);
$sectionId = trim((string)($_SESSION['hos_section_id'] ?? ''));
$sectionName = trim((string)($_SESSION['hos_section_name'] ?? $deptContext['name'] ?? ''));
$isTransportSection = ((string)($deptContext['section_type'] ?? '')) === 'transport';
$sectionDeptIds = hod_section_department_ids($deptContext);

// Backstop for environments where the migration has not been applied yet;
// a DDL-privilege failure only logs (schema_guard) and the page stays up.
wuc_ensure_tables($db, [
    "CREATE TABLE IF NOT EXISTS hos_dss_findings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        section_id VARCHAR(30) NOT NULL,
        finding_key VARCHAR(190) NOT NULL,
        category VARCHAR(60) NOT NULL,
        severity ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
        title VARCHAR(255) NOT NULL,
        detail TEXT NULL,
        affected_ref VARCHAR(190) NULL,
        recommended_action VARCHAR(255) NULL,
        responsible VARCHAR(120) NULL,
        status ENUM('pending','reviewed','resolved') NOT NULL DEFAULT 'pending',
        detected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by VARCHAR(50) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_section_finding (section_id, finding_key),
        KEY idx_section_status (section_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]);
$dssTableReady = wuc_table_exists($db, 'hos_dss_findings');
$dssScopeId = $sectionId !== '' ? $sectionId : ('DEPT_' . (string)($deptContext['id'] ?? ''));

// ── Status workflow POST (scoped to this section's rows only) ──────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['errorMsg'] = 'Your session token expired. Please retry the action.';
    } else {
        $findingId = (int)($_POST['finding_id'] ?? 0);
        $newStatus = (string)($_POST['status'] ?? '');
        if ($findingId > 0 && in_array($newStatus, ['pending', 'reviewed', 'resolved'], true) && $dssTableReady) {
            // mysqli is in exception mode; a DB fault on submit must surface as a
            // flash message + redirect, not a 500 on the POST.
            try {
                $stmt = $db->prepare("UPDATE hos_dss_findings SET status = ?, updated_by = ? WHERE id = ? AND section_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ssis', $newStatus, $hodStaffId, $findingId, $dssScopeId);
                    $stmt->execute();
                    $updated = $stmt->affected_rows;
                    $stmt->close();
                    if ($updated > 0) {
                        $_SESSION['successMsg'] = 'Finding marked as ' . htmlspecialchars($newStatus) . '.';
                        if (function_exists('audit_log_current_user')) {
                            audit_log_current_user($db, 'dss.finding_status', [
                                'finding_id' => $findingId,
                                'status' => $newStatus,
                            ]);
                        }
                    } else {
                        $_SESSION['errorMsg'] = 'Finding not found in your section.';
                    }
                }
            } catch (Throwable $e) {
                error_log('hod/decision_support.php status update failed: ' . $e->getMessage());
                $_SESSION['errorMsg'] = 'The finding could not be updated. Please try again.';
            }
        }
    }
    header('Location: decision_support.php', true, 303);
    exit;
}

// ── Detectors ───────────────────────────────────────────────────────────────
// Each finding: key (stable id), category, severity, title, detail,
// affected, action, responsible.
$liveFindings = [];
$addFinding = static function (
    string $key,
    string $category,
    string $severity,
    string $title,
    string $detail,
    string $affected,
    string $action,
    string $responsible = 'Head of Section'
) use (&$liveFindings): void {
    $liveFindings[] = [
        'key' => mb_substr($key, 0, 190),
        'category' => $category,
        'severity' => $severity,
        'title' => mb_substr($title, 0, 255),
        'detail' => $detail,
        'affected' => mb_substr($affected, 0, 190),
        'action' => mb_substr($action, 0, 255),
        'responsible' => mb_substr($responsible, 0, 120),
    ];
};

$insights = null;

if (!$isTransportSection) {
    // ── Academic detectors ──────────────────────────────────────────────────
    $deptIdsInt = array_values(array_filter(array_map('intval', $sectionDeptIds)));
    // wuc_hos_report_insights() runs raw prepared statements and mysqli is in
    // exception mode (MYSQLI_REPORT_STRICT), so a data/schema fault there would
    // otherwise 500 the whole page — breaking the graceful-degradation contract
    // this file documents. Degrade to an empty insight set instead, mirroring
    // the guard hod/academic_reports.php already applies to this same helper.
    try {
        $insights = wuc_hos_report_insights($db, $deptIdsInt);
    } catch (Throwable $e) {
        error_log('hod/decision_support.php insights failed: ' . $e->getMessage());
        $insights = [
            'programs_in_scope' => 0,
            'program_performance' => [],
            'failure_hotspots' => [],
            'lecturer_workload' => [],
            'missing_ca_courses' => [],
            'progression' => ['high' => 0, 'medium' => 0, 'low' => 0],
            'warnings' => ['Section insights are temporarily unavailable due to a data error.'],
            'actions' => [],
        ];
    }

    foreach ($insights['failure_hotspots'] as $hotspot) {
        $addFinding(
            'high_failure:' . $hotspot['course_code'],
            'High-Failure Course',
            'high',
            'High failure rate in ' . $hotspot['course_code'],
            "Fail rate {$hotspot['fail_rate']}% across {$hotspot['records']} assessment records (average {$hotspot['average']}%).",
            'Course ' . $hotspot['course_code'],
            'Review teaching and assessment for this course; consider moderation or remedial classes.',
            'Course Lecturer / HOS'
        );
    }

    foreach ($insights['missing_ca_courses'] as $missing) {
        $severity = $missing['missing'] >= 10 ? 'high' : 'medium';
        $addFinding(
            'missing_ca:' . $missing['course_code'],
            'Missing CA Marks',
            $severity,
            'CA marks missing for ' . $missing['course_code'],
            "{$missing['missing']} of {$missing['registered']} registered learners have no CA marks uploaded.",
            'Course ' . $missing['course_code'],
            'Follow up CA uploads with the responsible lecturer before the submission deadline.',
            'Course Lecturer'
        );
    }

    foreach ($insights['lecturer_workload'] as $load) {
        if ((int)$load['courses'] >= 5) {
            $addFinding(
                'overload:' . $load['staff_id'],
                'Workload Imbalance',
                'medium',
                'Lecturer overloaded: ' . $load['name'],
                "{$load['name']} ({$load['staff_id']}) carries {$load['courses']} active courses with {$load['learners']} learners.",
                'Lecturer ' . $load['staff_id'],
                'Rebalance course allocations across the section teaching team.',
                'Head of Section'
            );
        }
    }

    if ((int)$insights['progression']['high'] > 0) {
        $addFinding(
            'risk:high',
            'Students At Risk',
            'high',
            $insights['progression']['high'] . ' high-risk learner(s) in the section',
            "The academic risk engine currently scores {$insights['progression']['high']} learner(s) as HIGH risk "
                . "and {$insights['progression']['medium']} as MEDIUM risk in your section.",
            'Section learners',
            'Prioritise intervention for high-risk learners; monitor medium-risk weekly.',
            'HOS / Student Support'
        );
    }

    // Courses without an active lecturer.
    $sectionCourses = hod_section_course_codes($db, $deptContext);
    if ($sectionCourses !== [] && hod_table_exists($db, 'course_lecturer') && hod_table_exists($db, 'courses')) {
        $ph = implode(',', array_fill(0, count($sectionCourses), '?'));
        $rows = hod_query_rows(
            $db,
            "SELECT c.course_code, c.course_name
             FROM courses c
             WHERE c.course_code IN ($ph)
               AND NOT EXISTS (
                   SELECT 1 FROM course_lecturer cl
                   WHERE cl.course_code = c.course_code AND COALESCE(cl.status, 'active') = 'active'
               )
             ORDER BY c.course_code LIMIT 25",
            str_repeat('s', count($sectionCourses)),
            $sectionCourses
        );
        foreach ($rows as $row) {
            $addFinding(
                'no_lecturer:' . $row['course_code'],
                'Course Without Lecturer',
                'high',
                'No lecturer assigned to ' . $row['course_code'],
                ($row['course_name'] !== '' ? $row['course_name'] . ' — ' : '') . 'no active lecturer assignment exists for this course.',
                'Course ' . $row['course_code'],
                'Assign a lecturer from the Courses page.',
                'Head of Section'
            );
        }
    }

    // Unregistered active students, aggregated per programme.
    if ($deptIdsInt !== [] && hod_table_exists($db, 'semester_registration')) {
        $ph = implode(',', array_fill(0, count($deptIdsInt), '?'));
        $rows = hod_query_rows(
            $db,
            "SELECT sp.program_code, COUNT(DISTINCT sp.Sid) AS unregistered
             FROM student_program sp
             INNER JOIN programs p ON p.program_code = sp.program_code
             WHERE sp.status = 'active'
               AND p.department_id IN ($ph)
               AND NOT EXISTS (
                   SELECT 1 FROM semester_registration sr
                   WHERE (sr.SID = sp.Sid OR sr.student_id = sp.Sid)
                     AND sr.registration_status = 'registered'
                     AND sr.academic_year = YEAR(CURDATE())
               )
             GROUP BY sp.program_code
             HAVING unregistered > 0
             ORDER BY unregistered DESC LIMIT 15",
            str_repeat('i', count($deptIdsInt)),
            $deptIdsInt
        );
        foreach ($rows as $row) {
            $count = (int)$row['unregistered'];
            $addFinding(
                'unregistered:' . $row['program_code'],
                'Unregistered Students',
                $count >= 10 ? 'high' : 'medium',
                $count . ' unregistered student(s) in ' . $row['program_code'],
                "{$count} active learner(s) on {$row['program_code']} have no '" . 'registered' . "' record for " . date('Y') . '.',
                'Programme ' . $row['program_code'],
                'Follow up registration (and any fee blocks) with the programme coordinator.',
                'HOS / Registrar'
            );
        }
    }

    // Declining enrolment: current intake vs previous year per programme.
    if ($deptIdsInt !== []) {
        $ph = implode(',', array_fill(0, count($deptIdsInt), '?'));
        $rows = hod_query_rows(
            $db,
            "SELECT sp.program_code,
                    SUM(CASE WHEN sp.startYear = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS current_intake,
                    SUM(CASE WHEN sp.startYear = YEAR(CURDATE()) - 1 THEN 1 ELSE 0 END) AS previous_intake
             FROM student_program sp
             INNER JOIN programs p ON p.program_code = sp.program_code
             WHERE p.department_id IN ($ph)
             GROUP BY sp.program_code
             HAVING previous_intake >= 5 AND current_intake < previous_intake * 0.5",
            str_repeat('i', count($deptIdsInt)),
            $deptIdsInt
        );
        foreach ($rows as $row) {
            $addFinding(
                'declining:' . $row['program_code'],
                'Declining Enrolment',
                'medium',
                'Enrolment falling on ' . $row['program_code'],
                "New enrolments dropped from {$row['previous_intake']} (" . (date('Y') - 1) . ") to {$row['current_intake']} (" . date('Y') . ').',
                'Programme ' . $row['program_code'],
                'Investigate demand and marketing for this programme with Admissions.',
                'HOS / Admissions'
            );
        }
    }
} else {
    // ── Transport detectors ─────────────────────────────────────────────────
    if (hod_table_exists($db, 'transport_instructors')) {
        $rows = hod_query_rows($db, "
            SELECT id, full_name, rtsa_expiry, teveta_expiry
            FROM transport_instructors
            WHERE status = 'active'
              AND (
                  (rtsa_expiry IS NOT NULL AND rtsa_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
                  OR (teveta_expiry IS NOT NULL AND teveta_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
              )
            ORDER BY LEAST(COALESCE(rtsa_expiry, '9999-12-31'), COALESCE(teveta_expiry, '9999-12-31'))
            LIMIT 25");
        foreach ($rows as $row) {
            $addFinding(
                'instr_accred:' . $row['id'],
                'Instructor Accreditation',
                'high',
                'Accreditation expiring: ' . $row['full_name'],
                "RTSA expiry: " . ($row['rtsa_expiry'] ?: 'n/a') . ', TEVETA expiry: ' . ($row['teveta_expiry'] ?: 'n/a') . '.',
                'Instructor ' . $row['full_name'],
                'Arrange accreditation renewal before the expiry date; suspend scheduling if lapsed.',
                'HOS / Instructor'
            );
        }
    }

    if (hod_table_exists($db, 'transport_vehicles')) {
        $rows = hod_query_rows($db, "
            SELECT registration_no, next_service_due, fitness_expiry, insurance_expiry
            FROM transport_vehicles
            WHERE status <> 'unavailable'
              AND (
                  (next_service_due IS NOT NULL AND next_service_due <= DATE_ADD(CURDATE(), INTERVAL 10 DAY))
                  OR (fitness_expiry IS NOT NULL AND fitness_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
                  OR (insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
              )
            LIMIT 25");
        foreach ($rows as $row) {
            $overdue = !empty($row['next_service_due']) && $row['next_service_due'] <= date('Y-m-d');
            $parts = [];
            if (!empty($row['next_service_due'])) { $parts[] = 'service due ' . $row['next_service_due']; }
            if (!empty($row['fitness_expiry'])) { $parts[] = 'fitness expires ' . $row['fitness_expiry']; }
            if (!empty($row['insurance_expiry'])) { $parts[] = 'insurance expires ' . $row['insurance_expiry']; }
            $addFinding(
                'vehicle:' . $row['registration_no'],
                'Vehicle Compliance',
                $overdue ? 'high' : 'medium',
                'Vehicle attention needed: ' . $row['registration_no'],
                ucfirst(implode('; ', $parts)) . '.',
                'Vehicle ' . $row['registration_no'],
                'Schedule servicing / renew fitness and insurance before training use.',
                'Fleet Coordinator'
            );
        }
    }

    if (hod_table_exists($db, 'transport_preuse_checks')) {
        $unfit = (int)hod_query_scalar($db, "
            SELECT COUNT(*) FROM transport_preuse_checks
            WHERE overall_status = 'unfit'
              AND checklist_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
        if ($unfit > 0) {
            $addFinding(
                'unfit_checks:last7d',
                'Pre-Use Safety',
                'high',
                $unfit . ' unfit pre-use check(s) in the last 7 days',
                "Pre-use inspections flagged vehicles as 'unfit' {$unfit} time(s) within the last week.",
                'Fleet',
                'Ground the affected vehicles until defects are corrected and re-inspected.',
                'Fleet Coordinator'
            );
        }
    }

    if (hod_table_exists($db, 'transport_enrollments') && hod_table_exists($db, 'transport_assessments')) {
        $rows = hod_query_rows($db, "
            SELECT e.id, e.enrollment_date,
                   TRIM(CONCAT(COALESCE(t.first_name, ''), ' ', COALESCE(t.last_name, ''))) AS full_name
            FROM transport_enrollments e
            LEFT JOIN transport_trainees t ON t.id = e.trainee_id
            WHERE e.status = 'active'
              AND e.booking_status = 'booked'
              AND e.enrollment_date <= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              AND NOT EXISTS (
                  SELECT 1 FROM transport_assessments a
                  WHERE a.enrollment_id = e.id AND a.assessment_type = 'practical'
              )
            ORDER BY e.enrollment_date ASC
            LIMIT 25");
        foreach ($rows as $row) {
            $addFinding(
                'no_practical:' . $row['id'],
                'Incomplete Practical Records',
                'medium',
                'No practical assessment: ' . ($row['full_name'] ?: ('enrolment #' . $row['id'])),
                'Active trainee booked since ' . $row['enrollment_date'] . ' has no practical assessment recorded.',
                'Enrolment #' . $row['id'],
                'Schedule a practical assessment or update the training record.',
                'Instructor / HOS'
            );
        }
    }
}

// ── Persist: upsert live findings, auto-resolve those no longer firing ──────
// Guarded: mysqli is in exception mode, so a write fault here must not abort the
// page after the detectors have already computed their findings above.
if ($dssTableReady && $dssScopeId !== 'DEPT_') {
    try {
        $liveKeys = [];
        foreach ($liveFindings as $finding) {
            $liveKeys[] = $finding['key'];
            $stmt = $db->prepare("
                INSERT INTO hos_dss_findings
                    (section_id, finding_key, category, severity, title, detail, affected_ref, recommended_action, responsible, last_seen_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    category = VALUES(category),
                    severity = VALUES(severity),
                    title = VALUES(title),
                    detail = VALUES(detail),
                    affected_ref = VALUES(affected_ref),
                    recommended_action = VALUES(recommended_action),
                    responsible = VALUES(responsible),
                    last_seen_at = NOW(),
                    -- A resolved finding that fires again is not actually fixed.
                    status = IF(status = 'resolved', 'pending', status)
            ");
            if ($stmt) {
                $stmt->bind_param(
                    'sssssssss',
                    $dssScopeId,
                    $finding['key'],
                    $finding['category'],
                    $finding['severity'],
                    $finding['title'],
                    $finding['detail'],
                    $finding['affected'],
                    $finding['action'],
                    $finding['responsible']
                );
                $stmt->execute();
                $stmt->close();
            }
        }

        // Auto-resolve findings that no longer fire.
        if ($liveKeys === []) {
            $stmt = $db->prepare("UPDATE hos_dss_findings SET status = 'resolved', updated_by = 'system:auto' WHERE section_id = ? AND status <> 'resolved'");
            if ($stmt) {
                $stmt->bind_param('s', $dssScopeId);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $ph = implode(',', array_fill(0, count($liveKeys), '?'));
            $stmt = $db->prepare("UPDATE hos_dss_findings SET status = 'resolved', updated_by = 'system:auto' WHERE section_id = ? AND status <> 'resolved' AND finding_key NOT IN ($ph)");
            if ($stmt) {
                $params = array_merge([$dssScopeId], $liveKeys);
                $stmt->bind_param(str_repeat('s', count($params)), ...$params);
                $stmt->execute();
                $stmt->close();
            }
        }
    } catch (Throwable $e) {
        error_log('hod/decision_support.php persistence failed: ' . $e->getMessage());
    }
}

// ── Fetch findings for display ──────────────────────────────────────────────
$filterStatus = trim((string)($_GET['status'] ?? ''));
if (!in_array($filterStatus, ['pending', 'reviewed', 'resolved', ''], true)) {
    $filterStatus = '';
}
$filterSeverity = trim((string)($_GET['severity'] ?? ''));
if (!in_array($filterSeverity, ['high', 'medium', 'low', ''], true)) {
    $filterSeverity = '';
}

$findingRows = [];
$severityCounts = ['high' => 0, 'medium' => 0, 'low' => 0];
$statusCounts = ['pending' => 0, 'reviewed' => 0, 'resolved' => 0];
if ($dssTableReady) {
    $sql = "SELECT * FROM hos_dss_findings WHERE section_id = ?";
    $types = 's';
    $params = [$dssScopeId];
    if ($filterStatus !== '') {
        $sql .= " AND status = ?";
        $types .= 's';
        $params[] = $filterStatus;
    }
    if ($filterSeverity !== '') {
        $sql .= " AND severity = ?";
        $types .= 's';
        $params[] = $filterSeverity;
    }
    $sql .= " ORDER BY FIELD(status, 'pending', 'reviewed', 'resolved'), FIELD(severity, 'high', 'medium', 'low'), detected_at DESC LIMIT 200";
    $findingRows = hod_query_rows($db, $sql, $types, $params);

    $countRows = hod_query_rows($db, "SELECT severity, status, COUNT(*) AS total FROM hos_dss_findings WHERE section_id = ? GROUP BY severity, status", 's', [$dssScopeId]);
    foreach ($countRows as $row) {
        if ((string)$row['status'] !== 'resolved' && isset($severityCounts[(string)$row['severity']])) {
            $severityCounts[(string)$row['severity']] += (int)$row['total'];
        }
        if (isset($statusCounts[(string)$row['status']])) {
            $statusCounts[(string)$row['status']] += (int)$row['total'];
        }
    }
}

$successMsg = (string)($_SESSION['successMsg'] ?? '');
$errorMsg = (string)($_SESSION['errorMsg'] ?? '');
unset($_SESSION['successMsg'], $_SESSION['errorMsg']);

$e = static fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$severityBadge = ['high' => 'bg-danger', 'medium' => 'bg-warning text-dark', 'low' => 'bg-secondary'];
$statusBadge = ['pending' => 'bg-danger', 'reviewed' => 'bg-info', 'resolved' => 'bg-success'];
?>

<div class="container-fluid px-4 portal-dashboard hod-page">
    <div class="page-header mt-4 mb-3 d-print-none">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-compass me-2 text-primary"></i>Decision Support</h5>
                <p class="page-subtitle mb-0">
                    <i class="fas fa-building me-1"></i>
                    <?php echo $e($sectionName !== '' ? $sectionName : 'Section not assigned'); ?>
                    — exception reporting and early-warning indicators
                </p>
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="fas fa-print me-1"></i>Print
            </button>
        </div>
    </div>

    <?php if ($successMsg !== ''): ?>
        <div class="alert alert-success d-print-none"><i class="fas fa-check-circle me-2"></i><?php echo $successMsg; ?></div>
    <?php endif; ?>
    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger d-print-none"><i class="fas fa-triangle-exclamation me-2"></i><?php echo $errorMsg; ?></div>
    <?php endif; ?>
    <?php if (!$dssTableReady): ?>
        <div class="alert alert-warning">
            <i class="fas fa-database me-2"></i>
            The decision-support store (hos_dss_findings) is missing. Apply
            <code>migrations/create_hos_dss_findings.sql</code> as the migrator user.
            Findings below are computed live but review status cannot be saved.
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-danger me-3"><i class="fas fa-fire text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo (int)$severityCounts['high']; ?></h3>
                        <p class="text-muted mb-0">High Severity (open)</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning me-3"><i class="fas fa-triangle-exclamation text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo (int)$severityCounts['medium']; ?></h3>
                        <p class="text-muted mb-0">Medium Severity (open)</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3"><i class="fas fa-eye text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo (int)$statusCounts['reviewed']; ?></h3>
                        <p class="text-muted mb-0">Reviewed</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3"><i class="fas fa-check-double text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?php echo (int)$statusCounts['resolved']; ?></h3>
                        <p class="text-muted mb-0">Resolved</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="data-table-card mb-4 d-print-none">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label for="status" class="form-label small fw-semibold text-muted">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <?php foreach (['pending', 'reviewed', 'resolved'] as $statusOpt): ?>
                            <option value="<?php echo $statusOpt; ?>" <?php echo $filterStatus === $statusOpt ? 'selected' : ''; ?>><?php echo ucfirst($statusOpt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="severity" class="form-label small fw-semibold text-muted">Severity</label>
                    <select id="severity" name="severity" class="form-select form-select-sm">
                        <option value="">All Severities</option>
                        <?php foreach (['high', 'medium', 'low'] as $sevOpt): ?>
                            <option value="<?php echo $sevOpt; ?>" <?php echo $filterSeverity === $sevOpt ? 'selected' : ''; ?>><?php echo ucfirst($sevOpt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>Apply</button>
                </div>
                <div class="col-md-2 d-grid">
                    <a href="decision_support.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-undo me-1"></i>Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="data-table-card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list-check me-2"></i>Findings &amp; Recommended Actions</h5>
        </div>
        <?php if (empty($findingRows)): ?>
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-shield-halved fa-3x mb-3 d-block text-success"></i>
                <h5>No findings for this filter</h5>
                <p class="small mb-0">All monitored indicators for <?php echo $e($sectionName ?: 'your section'); ?> are within expected limits.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Problem Detected</th>
                            <th>Affected</th>
                            <th class="text-center">Severity</th>
                            <th>Recommended Action</th>
                            <th>Responsible</th>
                            <th class="text-nowrap">Detected</th>
                            <th class="text-center">Status</th>
                            <th class="text-center d-print-none">Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($findingRows as $row): ?>
                            <tr>
                                <td>
                                    <strong><?php echo $e($row['title']); ?></strong>
                                    <div class="small text-muted"><?php echo $e($row['detail']); ?></div>
                                    <span class="badge bg-light text-dark border small"><?php echo $e($row['category']); ?></span>
                                </td>
                                <td class="small"><?php echo $e($row['affected_ref']); ?></td>
                                <td class="text-center">
                                    <span class="badge <?php echo $severityBadge[(string)$row['severity']] ?? 'bg-secondary'; ?>"><?php echo $e(ucfirst((string)$row['severity'])); ?></span>
                                </td>
                                <td class="small"><?php echo $e($row['recommended_action']); ?></td>
                                <td class="small"><?php echo $e($row['responsible']); ?></td>
                                <td class="small text-nowrap"><?php echo $e(substr((string)$row['detected_at'], 0, 10)); ?></td>
                                <td class="text-center">
                                    <span class="badge <?php echo $statusBadge[(string)$row['status']] ?? 'bg-secondary'; ?>"><?php echo $e(ucfirst((string)$row['status'])); ?></span>
                                </td>
                                <td class="text-center text-nowrap d-print-none">
                                    <?php if ($row['status'] !== 'reviewed' && $row['status'] !== 'resolved'): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $e($csrfToken); ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="finding_id" value="<?php echo (int)$row['id']; ?>">
                                            <input type="hidden" name="status" value="reviewed">
                                            <button type="submit" class="btn btn-outline-info btn-sm" title="Mark reviewed"><i class="fas fa-eye"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($row['status'] !== 'resolved'): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $e($csrfToken); ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="finding_id" value="<?php echo (int)$row['id']; ?>">
                                            <input type="hidden" name="status" value="resolved">
                                            <button type="submit" class="btn btn-outline-success btn-sm" title="Mark resolved"><i class="fas fa-check"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $e($csrfToken); ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="finding_id" value="<?php echo (int)$row['id']; ?>">
                                            <input type="hidden" name="status" value="pending">
                                            <button type="submit" class="btn btn-outline-secondary btn-sm" title="Reopen"><i class="fas fa-rotate-left"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($insights !== null): ?>
        <?php echo wuc_hos_render_report_insights($insights, $sectionName); ?>
    <?php endif; ?>
</div>

<?php require "includes/footer.php"; ?>
