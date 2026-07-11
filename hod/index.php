<?php
declare(strict_types=1);

error_reporting(0);

$page_title = 'HOS Dashboard';
require_once __DIR__ . '/includes/hod_schema_helpers.php';
require_once dirname(__DIR__) . '/includes/hos_section_helpers.php';
require_once dirname(__DIR__) . '/includes/academic_risk_engine.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';
require_once dirname(__DIR__) . '/includes/grading_helpers.php';
require __DIR__ . '/includes/nav.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_token'];

// Dashboard defaults — always defined so the template never hits undefined vars.
$activeSectionId = '';
$activeSectionName = '';
$activeSectionType = '';
$isTransportSection = false;
$isNonAcademicSection = false;

$totalStudents = 0;
$activeStudents = 0;
$totalPrograms = 0;
$totalStaff = 0;
$pendingApprovals = 0;
$recentAssessments = 0;
$deptCourseCount = 0;
$coursesWithoutLecturer = [];
$coursesWithoutLecturerCount = 0;
$registeredThisPeriod = 0;
$unregisteredStudents = 0;
$deptCourseCodes = [];
$lecturerWorkload = [];
$gradeDistribution = [];
$academicNotifications = [];
$transportNotifications = [];
$aiAcademicInsights = null;
$aiFleetInsights = null;

$totalVehicles = 0;
$availableVehicles = 0;
$maintenanceVehicles = 0;
$totalInstructors = 0;
$activeInstructors = 0;
$totalTrainees = 0;
$activeTrainees = 0;
$practicalSessions = [];
$totalAssessments = 0;
$passedAssessments = 0;
$rtsaPassRate = 0.0;
$fuelLogs = [];
$totalFuelLitres = 0.0;
$maintenanceLogs = [];

$aiDepartmentRiskSummary = null;
$aiDepartmentAlerts = [];

$__detectColumn = static function (mysqli $db, string $table, array $candidates): ?string {
    return hod_detect_column($db, $table, $candidates);
};
$tableExists = static function (mysqli $db, string $table): bool {
    return hod_table_exists($db, $table);
};

if (isset($_SESSION['staff_id']) && isset($db) && $db instanceof mysqli) {
    // ── Resolve Section Context ──
    $deptContext = hod_resolve_department($db, (string)$_SESSION['staff_id']);
    $activeSectionId = (string)($deptContext['section_id'] ?? '');
    $activeSectionName = (string)($deptContext['name'] ?? '');
    $activeSectionType = (string)($deptContext['section_type'] ?? '');
    $isTransportSection = ($activeSectionType === 'transport');
    $isNonAcademicSection = $isTransportSection;

    // Detect CA table. semester_assessment is canonical; assessments is the
    // legacy per-assessment table. Both key students by Sid/SID.
    if ($tableExists($db, 'semester_assessment')) { $caTable = 'semester_assessment'; }
    elseif ($tableExists($db, 'assessments')) { $caTable = 'assessments'; }

    // Hydrate HOS Record Info
    $staffDeptCol = $__detectColumn($db, 'staff', ['department_id', 'DeptID', 'deptId']);
    $staffNumericIdCol = $__detectColumn($db, 'staff', ['id']);
    $staffCodeIdCol = $__detectColumn($db, 'staff', ['staff_id']);
    $deptIdNumericCol = $__detectColumn($db, 'departments', ['id', 'DeptID', 'department_id']);
    $deptIdCodeCol = $__detectColumn($db, 'departments', ['deptId', 'department_code']);
    $deptNameCol = $__detectColumn($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']);
    $deptHodCol = $__detectColumn($db, 'departments', ['hod_id', 'HODID', 'hodId']);

    $deptJoin = '';
    if ($staffDeptCol === 'department_id' && $deptIdNumericCol) {
        $deptJoin = "LEFT JOIN departments d ON s.`department_id` = d.`{$deptIdNumericCol}`";
    } elseif (($staffDeptCol === 'deptId' || $staffDeptCol === 'DeptID') && ($deptIdCodeCol || $deptIdNumericCol)) {
        if ($deptIdCodeCol && $staffDeptCol !== 'DeptID') {
            $deptJoin = "LEFT JOIN departments d ON s.`{$staffDeptCol}` = d.`{$deptIdCodeCol}`";
        } elseif ($deptIdNumericCol && $staffDeptCol === 'DeptID') {
            $deptJoin = "LEFT JOIN departments d ON s.`{$staffDeptCol}` = d.`{$deptIdNumericCol}`";
        }
    }
    $deptNameExpr = ($deptJoin !== '' && $deptNameCol) ? "d.`{$deptNameCol}`" : "NULL";

    if ($stmt = $db->prepare("SELECT s.*, {$deptNameExpr} AS deptName FROM staff s {$deptJoin} WHERE s.staff_id = ? LIMIT 1")) {
        try {
            $sid = (string)$_SESSION['staff_id'];
            $stmt->bind_param('s', $sid);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $staff_record = $res->fetch_object();
            }
        } catch (Throwable $e) {
            error_log('hod/index.php staff profile query failed: ' . $e->getMessage());
        }
        $stmt->close();
    }
    if (isset($staff_record) && !empty($activeSectionName)) {
        $staff_record->deptName = $activeSectionName;
    }

    // ═══════════════════════════════════════════════════
    //  TRANSPORT SECTION DASHBOARD DATA
    // ═══════════════════════════════════════════════════
    if ($isTransportSection) {
        if ($tableExists($db, 'transport_vehicles')) {
            $totalVehicles = (int)hod_query_scalar($db, "SELECT COUNT(*) FROM transport_vehicles");
            $availableVehicles = (int)hod_query_scalar($db, "SELECT COUNT(*) FROM transport_vehicles WHERE status = 'available'");
            $maintenanceVehicles = (int)hod_query_scalar($db, "SELECT COUNT(*) FROM transport_vehicles WHERE status = 'maintenance'");
            $upcomingServices = hod_query_rows($db, "SELECT registration_no, next_service_due FROM transport_vehicles WHERE next_service_due IS NOT NULL AND next_service_due <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)");
        } else {
            $upcomingServices = [];
        }

        if ($tableExists($db, 'transport_instructors')) {
            $totalInstructors = (int)hod_query_scalar($db, "SELECT COUNT(*) FROM transport_instructors");
            $activeInstructors = (int)hod_query_scalar($db, "SELECT COUNT(*) FROM transport_instructors WHERE status = 'active'");
            $expiredAccred = hod_query_rows($db, "SELECT full_name, rtsa_expiry, teveta_expiry FROM transport_instructors WHERE (rtsa_expiry IS NOT NULL AND rtsa_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)) OR (teveta_expiry IS NOT NULL AND teveta_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))");
        } else {
            $expiredAccred = [];
        }

        if ($tableExists($db, 'transport_enrollments')) {
            $totalTrainees = (int)hod_query_scalar($db, "SELECT COUNT(*) FROM transport_enrollments");
            $activeTrainees = (int)hod_query_scalar($db, "SELECT COUNT(*) FROM transport_enrollments WHERE status = 'active'");
        }

        if ($tableExists($db, 'transport_sessions')) {
            $practicalSessions = hod_query_rows($db, "
                SELECT s.*, i.full_name AS instructor_name, v.registration_no, c.cohort_name
                FROM transport_sessions s
                LEFT JOIN transport_instructors i ON s.instructor_id = i.id
                LEFT JOIN transport_vehicles v ON s.vehicle_id = v.id
                LEFT JOIN transport_cohorts c ON s.cohort_id = c.id
                ORDER BY s.session_date DESC, s.start_time DESC LIMIT 5
            ");
        }

        if ($tableExists($db, 'transport_assessments')) {
            $totalAssessments = (int)hod_query_scalar($db, "SELECT COUNT(*) FROM transport_assessments");
            $passedAssessments = (int)hod_query_scalar($db, "SELECT COUNT(*) FROM transport_assessments WHERE result = 'pass'");
            $rtsaPassRate = $totalAssessments > 0 ? round(($passedAssessments / $totalAssessments) * 100, 1) : 0;
        }

        if ($tableExists($db, 'transport_fuel_logs') && $tableExists($db, 'transport_vehicles')) {
            $fuelLogs = hod_query_rows($db, "
                SELECT f.*, v.registration_no
                FROM transport_fuel_logs f
                INNER JOIN transport_vehicles v ON f.vehicle_id = v.id
                ORDER BY f.fuel_date DESC LIMIT 5
            ");
            $totalFuelLitres = (float)hod_query_scalar($db, "SELECT COALESCE(SUM(amount_added), 0) FROM transport_fuel_logs", '', [], 0);
        }

        if ($tableExists($db, 'transport_maintenance_logs') && $tableExists($db, 'transport_vehicles')) {
            $maintenanceLogs = hod_query_rows($db, "
                SELECT m.*, v.registration_no
                FROM transport_maintenance_logs m
                INNER JOIN transport_vehicles v ON m.vehicle_id = v.id
                ORDER BY m.service_date DESC LIMIT 5
            ");
        }

        $transportNotifications = [];
        foreach ($upcomingServices as $service) {
            $transportNotifications[] = [
                'icon' => 'fas fa-wrench',
                'color' => 'danger',
                'title' => 'Vehicle Service Due',
                'content' => "Vehicle {$service['registration_no']} is due for scheduled service on {$service['next_service_due']}.",
                'badge' => 'Fleet Alerts',
                'badge_color' => 'danger',
                'date' => 'Upcoming'
            ];
        }
        $expiredAccred = $expiredAccred ?? [];
        foreach ($expiredAccred as $inst) {
            $transportNotifications[] = [
                'icon' => 'fas fa-id-card',
                'color' => 'warning',
                'title' => 'Accreditation Renewal Due',
                'content' => "Instructor {$inst['full_name']} credentials expire soon (RTSA: {$inst['rtsa_expiry']}, TEVETA: {$inst['teveta_expiry']}).",
                'badge' => 'Instructor Alerts',
                'badge_color' => 'warning',
                'date' => 'Action required'
            ];
        }
        if ($tableExists($db, 'transport_preuse_checks')) {
            $unfitChecks = (int)hod_query_scalar($db, "SELECT COUNT(*) FROM transport_preuse_checks WHERE overall_status = 'unfit' AND checklist_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
        } else {
            $unfitChecks = 0;
        }
        if ($unfitChecks > 0) {
            $transportNotifications[] = [
                'icon' => 'fas fa-circle-xmark',
                'color' => 'danger',
                'title' => 'Unfit Vehicle Flagged',
                'content' => "{$unfitChecks} pre-use checks flagged vehicles as 'unfit' in the last 7 days.",
                'badge' => 'Pre-use Checks',
                'badge_color' => 'danger',
                'date' => 'Critical'
            ];
        }

        if (empty($transportNotifications)) {
            $transportNotifications[] = [
                'icon' => 'fas fa-check-double',
                'color' => 'success',
                'title' => 'Fleet Operations Normal',
                'content' => 'All vehicles are within safe maintenance windows and instructor accreditations are valid.',
                'badge' => 'Normal',
                'badge_color' => 'success',
                'date' => 'Now'
            ];
        }

        // AI insights generation
        $aiFleetInsights = null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'ai_fleet_insights') {
            if (hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
                $context = [
                    'report' => 'Fleet & Transport Operations Summary',
                    'role' => 'Transport Head of Section',
                    'kpis' => [
                        'total_vehicles' => $totalVehicles,
                        'available_vehicles' => $availableVehicles,
                        'maintenance_vehicles' => $maintenanceVehicles,
                        'total_instructors' => $totalInstructors,
                        'active_instructors' => $activeInstructors,
                        'total_trainees' => $totalTrainees,
                        'active_trainees' => $activeTrainees,
                        'rtsa_pass_rate' => $rtsaPassRate,
                        'total_fuel_litres' => $totalFuelLitres
                    ],
                    'recent_sessions' => array_slice($practicalSessions, 0, 5),
                    'recent_maintenance' => array_slice($maintenanceLogs, 0, 5),
                    'rules' => ['summarize_only' => true, 'no_invented_numbers' => true]
                ];
                $ctxJson = wuc_ai_context_json($context, 14000);
                $aiFleetInsights = wuc_ai_generate($db, [
                    'feature' => 'transport_hos_dashboard',
                    'user_role' => 'transport_hos',
                    'user_id' => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'transport_hos'),
                    'input_summary' => 'Fleet Insights',
                    'context_hash' => hash('sha256', $ctxJson),
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are the AI Fleet Operations Consultant. Summarise fleet status, highlight vehicles under maintenance, point out low fuel efficiency, and suggest corrective actions for driving instruction schedules.'],
                        ['role' => 'user', 'content' => "Fleet data (JSON):\n{$ctxJson}\n\nWrite the summary."],
                    ],
                    'fallback' => static function () use ($availableVehicles, $maintenanceVehicles): string {
                        return "Fleet Status Summary — Available Vehicles: {$availableVehicles}, In Maintenance: {$maintenanceVehicles}. AI Insights currently unavailable.";
                    },
                ]);
            }
        }
    }

    // ═══════════════════════════════════════════════════
    //  ACADEMIC (ENGINEERING & ICT) DASHBOARD DATA
    // ═══════════════════════════════════════════════════
    else {
        $candIds = !empty($deptContext['candidates']) ? $deptContext['candidates'] : [];
        $deptFilter = hod_department_id_placeholders($candIds);
        $deptIn = $deptFilter['clause'];
        $deptTypes = $deptFilter['types'];
        $deptParams = $deptFilter['params'];

        if ($deptParams !== []) {
            $totalStudents = (int)hod_query_scalar(
                $db,
                "SELECT COUNT(DISTINCT s.SID) FROM students s INNER JOIN student_program sp ON s.SID = sp.Sid INNER JOIN programs p ON sp.program_code = p.program_code WHERE p.department_id IN ($deptIn)",
                $deptTypes,
                $deptParams
            );
            $activeStudents = (int)hod_query_scalar(
                $db,
                "SELECT COUNT(DISTINCT s.SID) FROM students s INNER JOIN student_program sp ON s.SID = sp.Sid INNER JOIN programs p ON sp.program_code = p.program_code WHERE sp.status = 'active' AND p.department_id IN ($deptIn)",
                $deptTypes,
                $deptParams
            );
            $totalPrograms = (int)hod_query_scalar(
                $db,
                "SELECT COUNT(*) FROM programs p WHERE p.department_id IN ($deptIn)",
                $deptTypes,
                $deptParams
            );
        }

        foreach ($candIds as $candId) {
            $deptCourseCodes = array_merge($deptCourseCodes, hod_department_course_codes($db, (string)$candId));
        }
        $deptCourseCodes = array_values(array_unique(array_filter($deptCourseCodes)));

        if ($deptCourseCodes !== [] && hod_table_exists($db, 'course_lecturer')) {
            $placeholders = implode(',', array_fill(0, count($deptCourseCodes), '?'));
            $totalStaff = (int)hod_query_scalar(
                $db,
                "SELECT COUNT(DISTINCT staff_id) FROM course_lecturer WHERE course_code IN ($placeholders)",
                str_repeat('s', count($deptCourseCodes)),
                $deptCourseCodes
            );
        }

        $caTable = null;
        if ($tableExists($db, 'semester_assessment')) {
            $caTable = 'semester_assessment';
        } elseif ($tableExists($db, 'assessments')) {
            $caTable = 'assessments';
        }

        $pendingApprovals = 0;
        $recentAssessments = 0;
        if ($caTable && $deptParams !== []) {
            $statusCol = $__detectColumn($db, $caTable, ['status', 'state']);
            $caStudentCol = $__detectColumn($db, $caTable, ['Sid', 'student_id', 'SID']);
            $createdCol = $__detectColumn($db, $caTable, ['created_at', 'updated_at', 'submitted_at']);
            $pendingClause = $statusCol
                ? " AND (ca.`{$statusCol}` IS NULL OR ca.`{$statusCol}` = '' OR ca.`{$statusCol}` IN ('Pending','Submitted'))"
                : '';
            $recentClause = $createdCol
                ? " AND ca.`{$createdCol}` >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                : '';

            if ($caStudentCol) {
                $pendingApprovals = (int)hod_query_scalar(
                    $db,
                    "SELECT COUNT(*) FROM `{$caTable}` ca INNER JOIN student_program sp ON ca.`{$caStudentCol}` = sp.Sid INNER JOIN programs p ON sp.program_code = p.program_code WHERE p.department_id IN ($deptIn){$pendingClause}",
                    $deptTypes,
                    $deptParams
                );
                $recentAssessments = (int)hod_query_scalar(
                    $db,
                    "SELECT COUNT(*) FROM `{$caTable}` ca INNER JOIN student_program sp ON ca.`{$caStudentCol}` = sp.Sid INNER JOIN programs p ON sp.program_code = p.program_code WHERE p.department_id IN ($deptIn){$recentClause}",
                    $deptTypes,
                    $deptParams
                );
            }
        }

        $pcProgramCol = $__detectColumn($db, 'program_courses', ['program_code', 'programId', 'program']);
        $pcCourseCol = $__detectColumn($db, 'program_courses', ['course_code', 'courseId', 'course_code_id']);
        if ($pcProgramCol && $pcCourseCol && $deptParams !== []) {
            $deptCourseCount = (int)hod_query_scalar(
                $db,
                "SELECT COUNT(DISTINCT pc.`{$pcCourseCol}`) FROM program_courses pc INNER JOIN programs p ON pc.`{$pcProgramCol}` = p.program_code WHERE p.department_id IN ($deptIn)",
                $deptTypes,
                $deptParams
            );
        }

        // Decision-support signals: courses with no active lecturer, and how
        // many active students have (not) completed period registration.
        $coursesWithoutLecturer = [];
        if ($deptCourseCodes !== [] && $tableExists($db, 'course_lecturer') && $tableExists($db, 'courses')) {
            $placeholders = implode(',', array_fill(0, count($deptCourseCodes), '?'));
            $coursesWithoutLecturer = hod_query_rows(
                $db,
                "SELECT c.course_code, c.course_name
                 FROM courses c
                 WHERE c.course_code IN ($placeholders)
                   AND NOT EXISTS (
                       SELECT 1 FROM course_lecturer cl
                       WHERE cl.course_code = c.course_code
                         AND COALESCE(cl.status, 'active') = 'active'
                   )
                 ORDER BY c.course_code",
                str_repeat('s', count($deptCourseCodes)),
                $deptCourseCodes
            );
        }
        $coursesWithoutLecturerCount = count($coursesWithoutLecturer);

        $registeredThisPeriod = 0;
        $unregisteredStudents = 0;
        if ($deptParams !== [] && $tableExists($db, 'semester_registration')) {
            $registeredThisPeriod = (int)hod_query_scalar(
                $db,
                "SELECT COUNT(DISTINCT sr.SID)
                 FROM semester_registration sr
                 INNER JOIN programs p ON p.program_code = sr.program_code
                 WHERE p.department_id IN ($deptIn)
                   AND sr.registration_status = 'registered'
                   AND sr.academic_year = YEAR(CURDATE())",
                $deptTypes,
                $deptParams
            );
            $unregisteredStudents = (int)hod_query_scalar(
                $db,
                "SELECT COUNT(DISTINCT sp.Sid)
                 FROM student_program sp
                 INNER JOIN programs p ON sp.program_code = p.program_code
                 WHERE sp.status = 'active'
                   AND p.department_id IN ($deptIn)
                   AND NOT EXISTS (
                       SELECT 1 FROM semester_registration sr
                       WHERE (sr.SID = sp.Sid OR sr.student_id = sp.Sid)
                         AND sr.registration_status = 'registered'
                         AND sr.academic_year = YEAR(CURDATE())
                   )",
                $deptTypes,
                $deptParams
            );
        }

        $lecturerWorkload = [];
        if ($deptCourseCodes !== [] && hod_table_exists($db, 'course_lecturer')) {
            $placeholders = implode(',', array_fill(0, count($deptCourseCodes), '?'));
            $lecturerWorkload = hod_query_rows(
                $db,
                "SELECT s.Fname, s.Lname, s.staff_id,
                        (SELECT COUNT(DISTINCT cl2.course_code) FROM course_lecturer cl2 WHERE cl2.staff_id = s.staff_id AND COALESCE(cl2.status, 'active') = 'active') AS course_count
                 FROM staff s
                 WHERE s.staff_id IN (SELECT DISTINCT staff_id FROM course_lecturer WHERE course_code IN ($placeholders))
                 ORDER BY course_count DESC LIMIT 5",
                str_repeat('s', count($deptCourseCodes)),
                $deptCourseCodes
            );
        }

        $gradeDistribution = [];
        if ($deptCourseCodes !== [] && $tableExists($db, 'exams')) {
            $placeholders = implode(',', array_fill(0, count($deptCourseCodes), '?'));
            $examRows = hod_query_rows(
                $db,
                "SELECT Total_marks, COUNT(*) AS cnt FROM exams WHERE Course_Code IN ($placeholders) GROUP BY Total_marks",
                str_repeat('s', count($deptCourseCodes)),
                $deptCourseCodes
            );
            $tally = [];
            foreach ($examRows as $row) {
                $grade = $row['Total_marks'] !== null ? wuc_result_grade((float)$row['Total_marks']) : 'Ungraded';
                $tally[$grade] = ($tally[$grade] ?? 0) + (int)$row['cnt'];
            }
            arsort($tally);
            foreach ($tally as $grade => $cnt) {
                $gradeDistribution[] = ['grade' => $grade, 'cnt' => $cnt];
            }
        }

        $academicNotifications = [];
        $missingCaCourses = [];
        if ($deptCourseCodes !== [] && $tableExists($db, 'exams')) {
            $placeholders = implode(',', array_fill(0, count($deptCourseCodes), '?'));
            $missingCaCourses = hod_query_rows(
                $db,
                "SELECT cr.course_code, p.program_code, COUNT(DISTINCT cr.Sid) AS enrolled_count
                 FROM course_registration cr
                 INNER JOIN student_program sp ON cr.Sid = sp.Sid
                 INNER JOIN programs p ON sp.program_code = p.program_code
                 LEFT JOIN exams ex ON ex.Sid = cr.Sid AND ex.Course_Code = cr.course_code
                 WHERE cr.course_code IN ($placeholders) AND ex.id IS NULL
                 GROUP BY cr.course_code, p.program_code HAVING enrolled_count > 0 LIMIT 3",
                str_repeat('s', count($deptCourseCodes)),
                $deptCourseCodes
            );
        }
        foreach ($missingCaCourses as $c) {
            $academicNotifications[] = [
                'icon' => 'fas fa-clipboard-list',
                'color' => 'warning',
                'title' => 'Missing Continuous Assessment',
                'content' => "Course {$c['course_code']} ({$c['program_code']}) has student registrations with missing CA scores.",
                'badge' => 'CA Alerts',
                'badge_color' => 'warning',
                'date' => 'Action required'
            ];
        }

        // Hydrate risk summary. The engine scopes to one department per call,
        // so aggregate across every department in this section.
        try {
            $aiDepartmentRiskSummary = null;
            foreach ($candIds as $cid) {
                $deptSummary = wuc_academic_risk_department_summary($db, (int)$cid);
                if ($aiDepartmentRiskSummary === null) {
                    $aiDepartmentRiskSummary = $deptSummary;
                    continue;
                }
                foreach (['students_checked', 'high', 'medium', 'low'] as $k) {
                    $aiDepartmentRiskSummary[$k] += (int)($deptSummary[$k] ?? 0);
                }
                $aiDepartmentRiskSummary['top_learners'] = array_merge(
                    $aiDepartmentRiskSummary['top_learners'],
                    (array)($deptSummary['top_learners'] ?? [])
                );
            }
            $aiDepartmentAlerts = wuc_academic_risk_open_alerts($db, [
                'department_ids' => $candIds,
            ], 8);
        } catch (Throwable $e) {
            error_log('hod/index.php AI department insight failed: ' . $e->getMessage());
            $aiDepartmentRiskSummary = null;
            $aiDepartmentAlerts = [];
        }

        if ($coursesWithoutLecturerCount > 0) {
            $academicNotifications[] = [
                'icon' => 'fas fa-user-slash',
                'color' => 'danger',
                'title' => 'Courses Without Lecturers',
                'content' => "{$coursesWithoutLecturerCount} course(s) in your section have no active lecturer assigned. Open Courses to allocate them.",
                'badge' => 'Allocation',
                'badge_color' => 'danger',
                'date' => 'Action required'
            ];
        }
        if ($unregisteredStudents > 0) {
            $academicNotifications[] = [
                'icon' => 'fas fa-user-clock',
                'color' => 'warning',
                'title' => 'Unregistered Students',
                'content' => "{$unregisteredStudents} active student(s) in your section have not completed registration for the current academic year.",
                'badge' => 'Registration',
                'badge_color' => 'warning',
                'date' => 'Follow up'
            ];
        }
        if ($aiDepartmentRiskSummary && (int)($aiDepartmentRiskSummary['high'] ?? 0) > 0) {
            $academicNotifications[] = [
                'icon' => 'fas fa-triangle-exclamation',
                'color' => 'danger',
                'title' => 'High-Risk Academic Learners',
                'content' => "{$aiDepartmentRiskSummary['high']} students in your department are flagged as high risk by the academic risk engine.",
                'badge' => 'Risk Engine',
                'badge_color' => 'danger',
                'date' => 'Immediate check'
            ];
        }
        if ($recentAssessments > 0) {
            $academicNotifications[] = [
                'icon' => 'fas fa-file-circle-check',
                'color' => 'success',
                'title' => 'Recent CA Submissions',
                'content' => "{$recentAssessments} continuous assessment record(s) were submitted in your section during the last 30 days.",
                'badge' => 'CA Activity',
                'badge_color' => 'success',
                'date' => 'Last 30 days',
            ];
        }
        if ($pendingApprovals > 0) {
            $academicNotifications[] = [
                'icon' => 'fas fa-circle-exclamation',
                'color' => 'info',
                'title' => 'CA Approvals Outstanding',
                'content' => "You have {$pendingApprovals} continuous assessment submission bundles waiting for your review.",
                'badge' => 'Approvals',
                'badge_color' => 'info',
                'date' => 'Pending HOD'
            ];
        }

        if (empty($academicNotifications)) {
            $academicNotifications[] = [
                'icon' => 'fas fa-check-double',
                'color' => 'success',
                'title' => 'Academic Operations Normal',
                'content' => 'All CA marks are uploaded, and lecturer assignments are fully allocated.',
                'badge' => 'Normal',
                'badge_color' => 'success',
                'date' => 'Now'
            ];
        }

        // AI Insights
        $aiAcademicInsights = null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'ai_academic_insights') {
            if (hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
                $context = [
                    'report' => 'Academic Performance & Compliance Summary',
                    'role' => 'Academic Head of Section',
                    'kpis' => [
                        'total_students' => $totalStudents,
                        'active_students' => $activeStudents,
                        'total_programs' => $totalPrograms,
                        'total_lecturers' => $totalStaff,
                        'section_courses' => $deptCourseCount,
                        'pending_approvals' => $pendingApprovals,
                        'recent_assessments_30d' => $recentAssessments,
                    ],
                    'grade_distribution' => $gradeDistribution,
                    'lecturer_workload' => $lecturerWorkload,
                    'risk_engine_summary' => $aiDepartmentRiskSummary,
                    'rules' => ['summarize_only' => true, 'no_invented_numbers' => true]
                ];
                $ctxJson = wuc_ai_context_json($context, 14000);
                $aiAcademicInsights = wuc_ai_generate($db, [
                    'feature' => 'academic_hos_dashboard',
                    'user_role' => 'academic_hos',
                    'user_id' => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'academic_hos'),
                    'input_summary' => 'Academic Insights',
                    'context_hash' => hash('sha256', $ctxJson),
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are the AI Academic Excellence Consultant. Summarise student risk levels, highlight courses with missing marks, analyze lecturer course workload distribution, and recommend corrective academic support interventions.'],
                        ['role' => 'user', 'content' => "Academic data (JSON):\n{$ctxJson}\n\nWrite the summary."],
                    ],
                    'fallback' => static function () use ($totalStudents, $pendingApprovals): string {
                        return "Academic Status Summary — Total Students: {$totalStudents}, Pending Approvals: {$pendingApprovals}. AI Insights currently unavailable.";
                    },
                ]);
            }
        }
    }
}

// Configure unified dashboard variables
$dashboard_title = $activeSectionName !== '' ? $activeSectionName . ' Dashboard' : 'HOS Dashboard';
$dashboard_subtitle = $isTransportSection
    ? 'Control transport operations, fleet, instructors, cohorts, clients, and compliance reports'
    : ($activeSectionName !== ''
        ? 'Manage ' . $activeSectionName . ' operations, courses, lecturer workloads, and continuous assessment approvals'
        : 'Manage academic section operations, courses, and continuous assessment approvals');
$dashboard_container_class = 'hod-dashboard-page';
$user_role = 'Head of Section';
$user_role_class = $isTransportSection ? 'bg-success' : 'bg-hod';
$header_section_class = $isTransportSection ? 'hod-section transport-section' : 'hod-section';
$stat_icon_class = $isTransportSection ? 'bg-success' : 'bg-hod';
$profile_link = 'view_staff.php?view=' . ($_SESSION['staff_id'] ?? '');
$edit_profile_link = 'editStaff.php?update=' . ($_SESSION['staff_id'] ?? '');

// KPI Stats Cards
$stat_cards = $isTransportSection
    ? [
        [
            'icon' => 'fas fa-truck',
            'value' => number_format($totalVehicles),
            'label' => 'Total Fleet Size',
            'bg_class' => 'bg-success',
            'link' => '../transport/fleet.php',
            'link_text' => 'Manage'
        ],
        [
            'icon' => 'fas fa-users-gear',
            'value' => number_format($totalInstructors),
            'label' => 'Instructors',
            'bg_class' => 'bg-info',
            'link' => '../transport/instructors.php',
            'link_text' => 'Open'
        ],
        [
            'icon' => 'fas fa-user-graduate',
            'value' => number_format($totalTrainees),
            'label' => 'Total Trainees',
            'bg_class' => 'bg-primary',
            'link' => '../transport/cohorts.php',
            'link_text' => 'Trainees'
        ],
        [
            'icon' => 'fas fa-screwdriver-wrench',
            'value' => number_format($maintenanceVehicles),
            'label' => 'In Maintenance',
            'bg_class' => 'bg-danger',
            'link' => '../transport/fleet.php?status=maintenance',
            'link_text' => 'Fleet'
        ],
    ]
    : [
        [
            'icon' => 'fas fa-book',
            'value' => number_format($deptCourseCount),
            'label' => 'Section Courses',
            'bg_class' => 'bg-hod',
            'link' => 'courses.php',
            'link_text' => 'View',
        ],
        [
            'icon' => 'fas fa-user-graduate',
            'value' => number_format($totalStudents),
            'label' => 'Section Students',
            'bg_class' => 'bg-info',
            'link' => 'students.php',
            'link_text' => 'View',
        ],
        [
            'icon' => 'fas fa-chalkboard-user',
            'value' => number_format($totalStaff),
            'label' => 'Section Lecturers',
            'bg_class' => 'bg-primary',
            'link' => 'staff.php',
            'link_text' => 'View',
        ],
        [
            'icon' => 'fas fa-tasks',
            'value' => number_format($pendingApprovals),
            'label' => 'Pending Approvals',
            'bg_class' => 'bg-warning',
            'link' => 'CAmanager.php',
            'link_text' => 'Review',
        ],
        [
            'icon' => 'fas fa-user-slash',
            'value' => number_format($coursesWithoutLecturerCount),
            'label' => 'Courses Without Lecturer',
            'bg_class' => $coursesWithoutLecturerCount > 0 ? 'bg-danger' : 'bg-success',
            'link' => 'courses.php',
            'link_text' => 'Allocate',
        ],
        [
            'icon' => 'fas fa-user-clock',
            'value' => number_format($unregisteredStudents),
            'label' => 'Unregistered Students (' . date('Y') . ')',
            'bg_class' => $unregisteredStudents > 0 ? 'bg-warning' : 'bg-success',
            'link' => 'decision_support.php',
            'link_text' => 'Details',
        ],
    ];

// Announcements list (Notification Alerts)
$show_announcements = true;
$announcements = $isTransportSection ? $transportNotifications : $academicNotifications;

// Quick Access Modules
$quick_modules = $isTransportSection
    ? [
        [
            'icon' => 'fas fa-route',
            'title' => 'Transport Operations',
            'description' => 'Open the transport management workspace.',
            'link' => '../transport.php',
            'bg_class' => 'bg-success'
        ],
        [
            'icon' => 'fas fa-users-gear',
            'title' => 'Instructors',
            'description' => 'Manage instructor compliance and records.',
            'link' => '../transport/instructors.php',
            'bg_class' => 'bg-info'
        ],
        [
            'icon' => 'fas fa-truck',
            'title' => 'Fleet Management',
            'description' => 'Manage fleet and vehicle checks.',
            'link' => '../transport/fleet.php',
            'bg_class' => 'bg-warning'
        ],
        [
            'icon' => 'fas fa-clipboard-check',
            'title' => 'Compliance Reports',
            'description' => 'Review pre-use, fleet, and compliance summaries.',
            'link' => '../transport/reports.php',
            'bg_class' => 'bg-success'
        ],
    ]
    : [
        [
            'icon' => 'fas fa-book',
            'title' => 'Courses',
            'description' => 'Review section courses and assignments.',
            'link' => 'courses.php',
            'bg_class' => 'bg-hod'
        ],
        [
            'icon' => 'fas fa-user-graduate',
            'title' => 'Students',
            'description' => 'Open section student records.',
            'link' => 'students.php',
            'bg_class' => 'bg-info'
        ],
        [
            'icon' => 'fas fa-tasks',
            'title' => 'CA Manager',
            'description' => 'Review and approve assessment records.',
            'link' => 'CAmanager.php',
            'bg_class' => 'bg-warning'
        ],
        [
            'icon' => 'fas fa-calendar-alt',
            'title' => 'Calendar',
            'description' => 'Open section calendar and planning tools.',
            'link' => 'calendar.php',
            'bg_class' => 'bg-success'
        ],
    ];

// ═══════════════════════════════════════════════════
//  DASHBOARD WIDGET PANELS HTML
// ═══════════════════════════════════════════════════
$extra_dashboard_content = '';

if ($isTransportSection) {
    ob_start();
    ?>
    <!-- Transport Dashboard Panels -->
    <div class="row g-4 mb-4">
        <!-- AI Fleet Insights Card -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-success mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>AI Fleet &amp; Logistics Insights</h5>
                    <form method="post" action="index.php" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="ai_fleet_insights">
                        <button type="submit" class="btn btn-sm btn-outline-success rounded-pill px-3">
                            <i class="fas fa-arrows-rotate me-1"></i>Generate AI Insights
                        </button>
                    </form>
                </div>
                <div class="card-body p-4">
                    <?php if ($aiFleetInsights !== null): ?>
                        <?php if (empty($aiFleetInsights['used_ai'])): ?>
                            <div class="alert alert-warning small py-2 mb-3"><?php echo htmlspecialchars(wuc_ai_fallback_notice($aiFleetInsights)); ?></div>
                        <?php endif; ?>
                        <div class="bg-light border rounded-3 p-3">
                            <?php echo wuc_ai_output_block((string)$aiFleetInsights['text']); ?>
                        </div>
                    <?php else: ?>
                        <span class="text-muted small">Click <strong>Generate AI Insights</strong> to run logistics analytics on fleet logs, driver schedules, and alerts.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RTSA Readiness Doughnut -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold text-success mb-0"><i class="fas fa-id-card me-2"></i>RTSA Competency &amp; Assessments</h5>
                </div>
                <div class="card-body p-4 d-flex flex-column align-items-center justify-content-center">
                    <div style="position:relative;width:160px;height:160px">
                        <canvas id="rtsaChart"></canvas>
                    </div>
                    <div class="mt-3 text-center">
                        <span class="badge bg-success me-1">Pass rate: <?php echo $rtsaPassRate; ?>%</span>
                        <span class="text-muted small"><?php echo $passedAssessments; ?> of <?php echo $totalAssessments; ?> tests passed</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Practical schedules & fuel logs -->
    <div class="row g-4 mb-4">
        <!-- Recent practical schedules -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold text-success mb-0"><i class="fas fa-calendar-days me-2"></i>Practical training schedules</h5>
                </div>
                <div class="card-body p-4">
                    <?php if (!empty($practicalSessions)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Cohort</th>
                                    <th>Instructor</th>
                                    <th>Vehicle</th>
                                    <th>Date/Time</th>
                                    <th class="text-end">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($practicalSessions as $s):
                                    $st = strtolower((string)($s['status'] ?? ''));
                                    $badge = $st === 'completed' ? 'success' : ($st === 'cancelled' ? 'danger' : 'warning');
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars((string)($s['cohort_name'] ?? '-')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($s['instructor_name'] ?? '-')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($s['registration_no'] ?? '-')); ?></td>
                                    <td><small><?php echo htmlspecialchars((string)($s['session_date'] ?? '') . ' ' . (string)($s['start_time'] ?? '')); ?></small></td>
                                    <td class="text-end"><span class="badge bg-<?php echo $badge; ?>"><?php echo ucfirst($st ?: 'pending'); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info mb-0"><i class="fas fa-circle-info me-2"></i>No recent practical training sessions found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Fuel Logs & Maintenance summary -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold text-success mb-0"><i class="fas fa-gas-pump me-2"></i>Fuel Logs &amp; Services</h5>
                </div>
                <div class="card-body p-4">
                    <h6 class="text-muted fw-bold small text-uppercase mb-3">Recent Fuel Additions</h6>
                    <?php if (!empty($fuelLogs)): ?>
                    <ul class="list-group list-group-flush mb-4">
                        <?php foreach ($fuelLogs as $f): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <div>
                                <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars((string)($f['registration_no'] ?? '-')); ?></h6>
                                <small class="text-muted"><?php echo htmlspecialchars((string)($f['fuel_date'] ?? '')); ?></small>
                            </div>
                            <span class="badge bg-success"><?php echo number_format((float)($f['amount_added'] ?? 0), 1); ?> L added</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <div class="alert alert-light border small py-2 mb-4">No recent fuel logs recorded.</div>
                    <?php endif; ?>

                    <h6 class="text-muted fw-bold small text-uppercase mb-3">Recent Maintenance Services</h6>
                    <?php if (!empty($maintenanceLogs)): ?>
                    <ul class="list-group list-group-flush mb-0">
                        <?php foreach ($maintenanceLogs as $m): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <div>
                                <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars((string)($m['registration_no'] ?? '-')); ?></h6>
                                <small class="text-muted"><?php echo htmlspecialchars((string)($m['service_date'] ?? '')); ?></small>
                            </div>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars((string)($m['service_type'] ?? '-')); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <div class="alert alert-light border small py-2 mb-0">No recent maintenance logs found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ChartJS for Transport -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        new Chart(document.getElementById('rtsaChart'), {
            type: 'doughnut',
            data: {
                labels: ['Passed', 'Failed/Attempted'],
                datasets: [{
                    data: [<?php echo $passedAssessments; ?>, <?php echo max(0, $totalAssessments - $passedAssessments); ?>],
                    backgroundColor: ['#198754', '#dee2e6'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '70%',
                plugins: { legend: { display: false } }
            }
        });
    });
    </script>
    <?php
    $extra_dashboard_content = ob_get_clean();
} 
else {
    ob_start();
    ?>
    <!-- Academic Dashboard Panels -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="alert alert-light border mb-0 py-2 px-3 d-flex align-items-center gap-2">
                <i class="fas fa-user-check text-primary"></i>
                <span><strong><?php echo number_format($activeStudents); ?></strong> active students</span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="alert alert-light border mb-0 py-2 px-3 d-flex align-items-center gap-2">
                <i class="fas fa-layer-group text-primary"></i>
                <span><strong><?php echo number_format($totalPrograms); ?></strong> programmes</span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="alert alert-light border mb-0 py-2 px-3 d-flex align-items-center gap-2">
                <i class="fas fa-file-signature text-primary"></i>
                <span><strong><?php echo number_format($recentAssessments); ?></strong> CA submissions (30d)</span>
            </div>
        </div>
    </div>
    <div class="row g-4 mb-4">
        <!-- AI Academic Insights Card -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>AI Academic Excellence Insights</h5>
                    <form method="post" action="index.php" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="ai_academic_insights">
                        <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                            <i class="fas fa-arrows-rotate me-1"></i>Generate AI Insights
                        </button>
                    </form>
                </div>
                <div class="card-body p-4">
                    <?php if ($aiAcademicInsights !== null): ?>
                        <?php if (empty($aiAcademicInsights['used_ai'])): ?>
                            <div class="alert alert-warning small py-2 mb-3"><?php echo htmlspecialchars(wuc_ai_fallback_notice($aiAcademicInsights)); ?></div>
                        <?php endif; ?>
                        <div class="bg-light border rounded-3 p-3">
                            <?php echo wuc_ai_output_block((string)$aiAcademicInsights['text']); ?>
                        </div>
                    <?php else: ?>
                        <span class="text-muted small">Click <strong>Generate AI Insights</strong> to run performance analytics on student grades, lecturer allocations, and CA completion status.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Grade Distribution Chart -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-chart-bar me-2"></i>Grade Distribution</h5>
                </div>
                <div class="card-body p-4">
                    <?php if ($gradeDistribution !== []): ?>
                    <div style="position:relative;height:220px">
                        <canvas id="academicGradeChart"></canvas>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-chart-bar fa-2x mb-2 opacity-50"></i>
                        <p class="mb-0 small">No exam grade data for your section courses yet.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Academic Risk Engine Panel -->
    <?php if ($aiDepartmentRiskSummary !== null): ?>
    <div class="row g-4 mb-4">
        <div class="col-12">
            <?php 
            echo wuc_academic_risk_render_department_panel($aiDepartmentRiskSummary)
                . wuc_academic_risk_render_alerts_panel($aiDepartmentAlerts, 'AI Department Alerts');
            ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Lecturer workload list -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold text-primary mb-0"><i class="fas fa-chalkboard-user me-2"></i>Lecturer Workloads</h5>
                </div>
                <div class="card-body p-4">
                    <?php if (!empty($lecturerWorkload)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Lecturer ID</th>
                                    <th>Name</th>
                                    <th class="text-center">Assigned Courses</th>
                                    <th style="width:40%">Workload Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lecturerWorkload as $l): 
                                    $share = $deptCourseCount > 0 ? round(($l['course_count'] / $deptCourseCount) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($l['staff_id']); ?></td>
                                    <td><?php echo htmlspecialchars($l['Fname'] . ' ' . $l['Lname']); ?></td>
                                    <td class="text-center"><span class="badge bg-hod"><?php echo $l['course_count']; ?></span></td>
                                    <td>
                                        <div class="progress" style="height:18px">
                                            <div class="progress-bar bg-hod" style="width:<?php echo $share; ?>%" role="progressbar"><?php echo $share; ?>%</div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info mb-0"><i class="fas fa-circle-info me-2"></i>No lecturer workload details found in your department.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ChartJS for Academic -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <?php if ($gradeDistribution !== []): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const grades = <?php echo json_encode(array_column($gradeDistribution, 'grade'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const counts = <?php echo json_encode(array_column($gradeDistribution, 'cnt')); ?>;
        const chartEl = document.getElementById('academicGradeChart');
        if (!chartEl) {
            return;
        }
        new Chart(chartEl, {
            type: 'bar',
            data: {
                labels: grades,
                datasets: [{
                    label: 'Students Count',
                    data: counts,
                    backgroundColor: '#6f42c1',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false } }
                },
                plugins: { legend: { display: false } }
            }
        });
    });
    </script>
    <?php endif; ?>
    <?php
    $extra_dashboard_content = ob_get_clean();
}

// Spec empty-state: a Head-of-Section account with no active section assignment
// should see a clear message instead of a dashboard full of zeroes. Systems
// administrators always resolve to every active section (via
// hos_get_visible_staff_sections), so this only targets a genuinely unassigned
// HOS account.
if ($activeSectionId === '' && empty($_SESSION['hos_sections'])) {
    $stat_cards = [];
    $show_announcements = false;
    $dashboard_subtitle = 'Head of Section workspace';
    $extra_dashboard_content =
        '<div class="alert alert-warning border-0 shadow-sm rounded-4 d-flex align-items-center gap-3 p-4 mb-0">'
        . '<i class="fas fa-circle-info fa-2x text-warning"></i>'
        . '<div>'
        . '<h5 class="fw-bold mb-1">No section assigned</h5>'
        . '<p class="mb-0">No section has been assigned to your Head of Section account. '
        . 'Please contact the Systems Administrator or Registrar to be linked to a section before the dashboard can load.</p>'
        . '</div></div>';
}

// Include unified layout template
require_once dirname(__DIR__) . '/includes/dashboard_template.php';
?>

<style>
    /* Page-specific: green accent when the HOS heads the transport section.
       Generic stat-icon/profile-card/badge/title styling comes from the shared
       css/wuc-premium.css + css/portal-dashboard.css layers. */
    .dashboard-header.transport-section {
        border-left: 4px solid #198754;
        background: linear-gradient(135deg, rgba(27, 42, 74, 0.04), rgba(25, 135, 84, 0.04));
    }
    .dashboard-header.transport-section .dashboard-title {
        color: #198754;
    }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
