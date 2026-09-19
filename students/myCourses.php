<?php
// Start session and load database connection
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/RegistrationDataService.php';
require_once __DIR__ . '/includes/AcademicSessionService.php';
require_once __DIR__ . '/includes/period_mode_helper.php';
require_once dirname(__DIR__) . '/includes/short_course_student.php';

// Debug mode is restricted to systems administrators.
$debugMode = isset($_GET['debug'])
    && ($_GET['debug'] === '1' || $_GET['debug'] === 'true')
    && function_exists('wuc_should_show_error_details')
    && wuc_should_show_error_details();
$debugLog = []; // Collect debug info for frontend output

function addDebug($category, $message, $data = null) {
    global $debugMode, $debugLog;
    if ($debugMode) {
        $entry = [
            'time' => date('H:i:s.') . substr(microtime(), 2, 3),
            'category' => $category,
            'message' => $message
        ];
        if ($data !== null) {
            $entry['data'] = $data;
        }
        $debugLog[] = $entry;
        error_log("myCourses.php [{$category}]: {$message}" . ($data !== null ? ' | Data: ' . json_encode($data) : ''));
    }
}

addDebug('INIT', 'Debug mode enabled', ['GET' => $_GET, 'session_id' => session_id()]);

// Debug database connection
addDebug('DB', 'Checking database connection');
if (!isset($db) || !($db instanceof mysqli)) {
    addDebug('DB', 'ERROR: Database connection not available', ['db_isset' => isset($db)]);
    error_log('myCourses.php: Database connection not available');
    die('<div class="alert alert-danger">Database connection error. Please contact support.</div>');
}

if ($db->connect_error) {
    addDebug('DB', 'ERROR: Database connection failed', ['error' => $db->connect_error]);
    error_log('myCourses.php: Database connection error - ' . $db->connect_error);
    die('<div class="alert alert-danger">Database connection error. Please contact support.</div>');
}

addDebug('DB', 'Database connection OK', ['host_info' => $db->host_info, 'server_info' => $db->server_info]);

// Error reporting - show errors in debug mode
if ($debugMode) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>My Courses - ITC</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
<link rel="stylesheet" href="/wucportal/css/admin-style.css">
<link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">

<link rel="stylesheet" href="../css/consistent-styles.css">
<link rel="stylesheet" href="../css/debug.css">
</head>
<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    <?php
      // Compute current term for header display (robust to schema variants)
      addDebug('SESSION', 'Session data check', [
          'Sid' => $_SESSION['Sid'] ?? 'NOT SET',
          'student_id' => $_SESSION['student_id'] ?? 'NOT SET',
          'user_id' => $_SESSION['user_id'] ?? 'NOT SET',
          'username' => $_SESSION['username'] ?? 'NOT SET'
      ]);
      
      // Check for system-wide current term settings
      $systemCurrentTerm = null;
      $systemAcademicYear = null;
      $systemSemester = null;
      
      // Try to get current term from settings/academic_calendar tables
      $termTables = ['settings', 'academic_settings', 'academic_calendar', 'current_term', 'system_settings'];
      foreach ($termTables as $tbl) {
          $checkTbl = $db->query("SHOW TABLES LIKE '{$tbl}'");
          if ($checkTbl && $checkTbl->num_rows > 0) {
              addDebug('TERM', "Checking table: {$tbl} for current term settings");
              $tblCols = [];
              if ($colRes = $db->query("SHOW COLUMNS FROM `{$tbl}`")) {
                  while ($col = $colRes->fetch_assoc()) { $tblCols[strtolower($col['Field'])] = $col['Field']; }
                  $colRes->free();
              }
              addDebug('TERM', "Table {$tbl} columns", $tblCols);
              
              // Look for semester/term and academic_year columns
              $semCol = $tblCols['current_semester'] ?? ($tblCols['semester'] ?? ($tblCols['term'] ?? null));
              $yearCol = $tblCols['current_academic_year'] ?? ($tblCols['academic_year'] ?? ($tblCols['year'] ?? null));
              $activeCol = $tblCols['is_active'] ?? ($tblCols['active'] ?? ($tblCols['status'] ?? null));
              
              if ($semCol || $yearCol) {
                  $selects = [];
                  if ($semCol) $selects[] = "`{$semCol}` AS semester";
                  if ($yearCol) $selects[] = "`{$yearCol}` AS academic_year";
                  $where = $activeCol ? "WHERE `{$activeCol}` = 1 OR `{$activeCol}` = 'active'" : "";
                  $termQ = "SELECT " . implode(', ', $selects) . " FROM `{$tbl}` {$where} LIMIT 1";
                  addDebug('TERM', 'System term query', ['sql' => $termQ]);
                  if ($termRes = $db->query($termQ)) {
                      if ($termRow = $termRes->fetch_assoc()) {
                          $systemSemester = $termRow['semester'] ?? null;
                          $systemAcademicYear = $termRow['academic_year'] ?? null;
                          addDebug('TERM', 'System current term found', [
                              'table' => $tbl,
                              'semester' => $systemSemester,
                              'academic_year' => $systemAcademicYear
                          ]);
                      }
                      $termRes->free();
                  }
                  if ($systemSemester || $systemAcademicYear) break;
              }
          }
          if ($checkTbl) $checkTbl->free();
      }
      
      // Also check semester_registration for the most recent registration across all students (system-wide current term)
      if (!$systemSemester && !$systemAcademicYear) {
          $latestTermQ = "SELECT semester, academic_year, COUNT(*) as reg_count FROM semester_registration GROUP BY academic_year, semester ORDER BY academic_year DESC, semester DESC LIMIT 1";
          addDebug('TERM', 'Checking most common recent term', ['sql' => $latestTermQ]);
          if ($ltRes = $db->query($latestTermQ)) {
              if ($ltRow = $ltRes->fetch_assoc()) {
                  $systemSemester = $ltRow['semester'] ?? null;
                  $systemAcademicYear = $ltRow['academic_year'] ?? null;
                  addDebug('TERM', 'Most recent term from registrations', [
                      'semester' => $systemSemester,
                      'academic_year' => $systemAcademicYear,
                      'registration_count' => $ltRow['reg_count']
                  ]);
              }
              $ltRes->free();
          }
      }
      
      $currentSemester = '';
      $currentYear = '';
      $currentAcadYear = '';
      $currentRegistration = null;
      $headerPeriodLabel = !empty($_SESSION['Sid']) ? getPeriodLabel($db, (string)$_SESSION['Sid']) : 'Semester';
      $registrationDataService = new RegistrationDataService($db);
      if (!empty($_SESSION['Sid'])) {
          $sidForTerm = (string)$_SESSION['Sid'];
          $periodModeForTerm = getStudentProgramPeriodMode($db, $sidForTerm);
          $sessionServiceForTerm = new AcademicSessionService($db);
          $currentSessionForTerm = $sessionServiceForTerm->getCurrentSession($periodModeForTerm);
          if ($currentSessionForTerm) {
              $currentAyForTerm = (string)($currentSessionForTerm['academic_year'] ?? '');
              $currentSemForTerm = (int)($currentSessionForTerm['semester_term'] ?? 0);
              if ($currentAyForTerm !== '' && $currentSemForTerm > 0) {
                  $currentRegistration = $registrationDataService->getLatestSemesterRegistration(
                      $sidForTerm,
                      $currentAyForTerm,
                      $currentSemForTerm,
                      $periodModeForTerm
                  );
              }
          }
          if (empty($currentRegistration)) {
              $currentRegistration = $registrationDataService->getLatestSemesterRegistration($sidForTerm);
          }
          if ($currentRegistration) {
              $currentSemester = (string)($currentRegistration['semester'] ?? '');
              $currentYear = (string)($currentRegistration['year_of_study'] ?? '');
              $currentAcadYear = (string)($currentRegistration['academic_year'] ?? '');
              addDebug('TERM', 'Student current registration from RegistrationDataService', $currentRegistration);
          } else {
              addDebug('TERM', 'WARNING: No registration context found for student');
          }
      }
    ?>
    <div class="content-wrapper">
        <div class="container">
            <div class="row">
                <div class="col-12 animate__animated animate__fadeIn">
                    <div class="page-header d-flex justify-content-between align-items-center">
                        <h2 class="page-title">My Courses</h2>
                        <div class="stat-badges">
                            <?php if ($currentAcadYear !== '') { echo '<span class="stat-badge">'.htmlspecialchars((string)$currentAcadYear).'</span>'; } ?>
                            <?php if ($currentYear !== '')     { echo '<span class="stat-badge">Year '.htmlspecialchars((string)$currentYear).'</span>'; } ?>
                            <?php if ($currentSemester !== '') { echo '<span class="stat-badge">'.htmlspecialchars($headerPeriodLabel).' '.htmlspecialchars((string)$currentSemester).'</span>'; } ?>
                            <?php if ($debugMode && $systemSemester): ?>
                                <span class="stat-badge bg-info-subtle text-primary-emphasis border border-info-subtle" title="System current term">
                                    <i class="fas fa-info-circle"></i> System: <?php echo htmlspecialchars($systemAcademicYear ?? ''); ?> <?php echo htmlspecialchars($headerPeriodLabel); ?> <?php echo htmlspecialchars($systemSemester); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <nav aria-label="breadcrumb" class="mb-3">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item">Academics</li>
                            <li class="breadcrumb-item active" aria-current="page">My Courses</li>
                        </ol>
                    </nav>

                    <?php if ($debugMode): ?>
                    <!-- Term/Semester Debug Info Card -->
                    <div class="card mb-3 border-info">
                        <div class="card-header bg-info text-white py-2">
                            <strong><i class="fas fa-calendar-alt"></i> Term/Semester Debug Info</strong>
                        </div>
                        <div class="card-body py-2">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-primary mb-2">Student's Current Registration</h6>
                                    <table class="table table-hover align-middle mb-0">
                                        <tr><th class="w-40">Academic Year</th><td><?php echo htmlspecialchars($currentAcadYear ?: 'Not Set'); ?></td></tr>
                                        <tr><th>Year of Study</th><td><?php echo htmlspecialchars($currentYear ?: 'Not Set'); ?></td></tr>
                                        <tr><th>Semester</th><td><?php echo htmlspecialchars($currentSemester ?: 'Not Set'); ?></td></tr>
                                        <tr><th>Student ID (Sid)</th><td><code><?php echo htmlspecialchars($_SESSION['Sid'] ?? 'NOT SET'); ?></code></td></tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-success mb-2">System Current Term</h6>
                                    <table class="table table-hover align-middle mb-0">
                                        <tr><th class="w-40">Academic Year</th><td><?php echo htmlspecialchars($systemAcademicYear ?: 'Not Found'); ?></td></tr>
                                        <tr><th>Semester</th><td><?php echo htmlspecialchars($systemSemester ?: 'Not Found'); ?></td></tr>
                                        <tr>
                                            <th>Status</th>
                                            <td>
                                                <?php 
                                                if (!$systemSemester && !$systemAcademicYear) {
                                                    echo '<span class="badge bg-warning text-dark">No system term configured</span>';
                                                } elseif ($currentSemester == $systemSemester && $currentAcadYear == $systemAcademicYear) {
                                                    echo '<span class="badge bg-success">✓ Student matches system term</span>';
                                                } else {
                                                    echo '<span class="badge bg-danger">⚠ Term mismatch</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <?php
                            // Show all student's semester registrations
                            $sessSid = (string)($_SESSION['Sid'] ?? '');
                            if ($sessSid !== '' && ($allStmt = $db->prepare("SELECT * FROM semester_registration WHERE student_id = ? ORDER BY id DESC LIMIT 5"))) {
                                $allStmt->bind_param('s', $sessSid);
                                $allStmt->execute();
                                if ($allRegsRes = $allStmt->get_result()) {
                                    if ($allRegsRes->num_rows > 0) {
                                        echo '<hr class="my-2"><h6 class="text-secondary mb-2">Recent Semester Registrations (Last 5)</h6>';
                                        echo '<div class="table-responsive"><table class="table table-hover align-middle mb-0 fs-sm">';
                                        echo '<thead class="table-light"><tr>';
                                        $firstRow = $allRegsRes->fetch_assoc();
                                        foreach (array_keys($firstRow) as $col) {
                                            echo '<th>' . htmlspecialchars($col) . '</th>';
                                        }
                                        echo '</tr></thead><tbody>';
                                        // Output first row
                                        echo '<tr>';
                                        foreach ($firstRow as $val) {
                                            echo '<td>' . htmlspecialchars((string)$val) . '</td>';
                                        }
                                        echo '</tr>';
                                        // Output remaining rows
                                        while ($regRow = $allRegsRes->fetch_assoc()) {
                                            echo '<tr>';
                                            foreach ($regRow as $val) {
                                                echo '<td>' . htmlspecialchars((string)$val) . '</td>';
                                            }
                                            echo '</tr>';
                                        }
                                        echo '</tbody></table></div>';
                                    }
                                }
                                $allStmt->close();
                            }
                            ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="card">
                        <div class="card-body">
                            <div class="mb-3">
                                <h5 class="mb-0">Current Registered Courses</h5>
                            </div>
                            <?php
                            $number = 1;
                            $records = [];
                            $selectedYear = '';
                            $selectedSemester = '';
                            $academicYear = '';

                            // Ensure we have a logged-in student ID
                            $sid = isset($_SESSION['Sid']) ? (string)$_SESSION['Sid'] : '';
                            addDebug('AUTH', 'Student ID from session', ['sid' => $sid ?: 'EMPTY', 'session_keys' => array_keys($_SESSION)]);
                            error_log('myCourses.php DEBUG: Student ID from session: ' . ($sid ?: 'EMPTY'));
                            
                            if ($sid === '') {
                                echo '<div class="alert alert-danger">Session expired. Please log in again.</div>';
                                addDebug('AUTH', 'ERROR: No student ID in session');
                                error_log('myCourses.php ERROR: No student ID in session');
                            } elseif (isShortCourseStudent($db, $sid)) {
                                // Short-course students have no semester registration; their
                                // "courses" are their short-course enrolments. Show those so
                                // "My Courses" isn't a misleading "register first" dead-end.
                                foreach (sc_student_enrolments($db, $sid) as $e) {
                                    $records[] = (object)[
                                        'course_code' => (string)($e['course_code'] ?? ''),
                                        'course_name' => (string)($e['course_name'] ?? ''),
                                        'credits' => 0,
                                        'CoRegID' => null,
                                        'short_course_id' => (int)($e['short_course_id'] ?? 0),
                                    ];
                                }
                                $isShortCourseView = true;
                                addDebug('RESULT', 'Short-course enrolments shown', ['count' => count($records)]);
                            } else {
                                // Period context resolved in page header ($currentRegistration).
                                $semesterRegId = null;
                                $program = '';
                                if ($currentRegistration) {
                                    $semesterRegId = isset($currentRegistration['id']) ? (int)$currentRegistration['id'] : null;
                                    $selectedYear = (string)($currentRegistration['year_of_study'] ?? '');
                                    $selectedSemester = (string)($currentRegistration['semester'] ?? '');
                                    $academicYear = (string)($currentRegistration['academic_year'] ?? '');
                                    $program = (string)($currentRegistration['program_code'] ?? '');
                                    addDebug('RESULT', 'RegistrationDataService term context found', $currentRegistration);
                                }

                                if ($selectedYear === '' || $selectedSemester === '') {
                                // Discover semester_registration column names
                                $cols = [];
                                if ($meta = $db->query("SHOW COLUMNS FROM semester_registration")) {
                                    while ($c = $meta->fetch_assoc()) { $cols[strtolower((string)$c['Field'])] = (string)$c['Field']; }
                                    $meta->free();
                                }
                                addDebug('SCHEMA', 'semester_registration columns discovered', $cols);
                                // Use correct column names prioritizing student_id and year_of_study
                                $srSidCol  = $cols['student_id'] ?? ($cols['sid'] ?? ($cols['student'] ?? 'student_id'));
                                $srSemCol  = $cols['semester'] ?? ($cols['semester_term'] ?? ($cols['term'] ?? 'semester'));
                                $srYearCol = $cols['year_of_study'] ?? ($cols['year'] ?? ($cols['academic_year'] ?? 'year_of_study'));
                                $srAcadYearCol = $cols['academic_year'] ?? 'academic_year';
                                $srIdCol   = $cols['id'] ?? null;

                                // Get the latest semester registration for this student (current term only)
                                $order = $srIdCol ? "ORDER BY `{$srIdCol}` DESC" : "ORDER BY `{$srYearCol}` DESC, `{$srSemCol}` DESC";
                                $srIdSelect = $srIdCol ? "`{$srIdCol}` AS id," : "";
                                $sqlCurrent = "SELECT {$srIdSelect} `{$srYearCol}` AS Year, `{$srSemCol}` AS semester, `{$srAcadYearCol}` AS AcademicYear, program_code FROM semester_registration WHERE `{$srSidCol}`=? $order LIMIT 1";
                                $program = '';
                                $semesterRegId = null;
                                addDebug('QUERY', 'Semester registration query', ['sql' => $sqlCurrent]);
                                error_log('myCourses.php DEBUG: Semester registration query: ' . $sqlCurrent);
                                
                                if ($stmtCurrent = $db->prepare($sqlCurrent)) {
                                    $stmtCurrent->bind_param('s', $sid);
                                    $stmtCurrent->execute();
                                    $rs = $stmtCurrent->get_result();
                                    if ($rs && ($row = $rs->fetch_assoc())) {
                                        $semesterRegId = isset($row['id']) ? (int)$row['id'] : null;
                                        $selectedYear = (string)($row['Year'] ?? '');
                                        $selectedSemester = (string)($row['semester'] ?? '');
                                        $academicYear = (string)($row['AcademicYear'] ?? '');
                                        $program = (string)($row['program_code'] ?? '');
                                        addDebug('RESULT', 'Semester registration found', [
                                            'semesterRegId' => $semesterRegId,
                                            'Year' => $selectedYear,
                                            'semester' => $selectedSemester,
                                            'academicYear' => $academicYear,
                                            'program' => $program
                                        ]);
                                        error_log('myCourses.php DEBUG: Found semester registration - Year: ' . $selectedYear . ', Sem: ' . $selectedSemester . ', Academic Year: ' . $academicYear);
                                    } else {
                                        addDebug('RESULT', 'WARNING: No semester registration found for student', ['sid' => $sid]);
                                        error_log('myCourses.php WARNING: No semester registration row found for student: ' . $sid);
                                    }
                                    $stmtCurrent->close();
                                } else {
                                    addDebug('ERROR', 'Semester registration query failed', ['error' => $db->error]);
                                    error_log('myCourses.php ERROR: Semester registration query failed: ' . $db->error);
                                }
                                }

                                if ($selectedYear === '' || $selectedSemester === '') {
                                    echo '<div class="alert alert-warning">No active semester registration found. Please complete semester registration first.</div>';
                                } else {
                                    $serviceCourses = $registrationDataService->getRegisteredCourses(
                                        $sid,
                                        (int)$selectedYear,
                                        (int)$selectedSemester,
                                        $semesterRegId,
                                        $academicYear !== '' ? $academicYear : null,
                                        'year'
                                    );
                                    foreach ($serviceCourses as $serviceCourse) {
                                        $records[] = (object)[
                                            'course_code' => $serviceCourse['course_code'] ?? '',
                                            'course_name' => $serviceCourse['course_name'] ?? '',
                                            'credits' => $serviceCourse['credit_hours'] ?? ($serviceCourse['credits'] ?? 3),
                                            'CoRegID' => $serviceCourse['id'] ?? null,
                                        ];
                                    }
                                    addDebug('RESULT', 'RegistrationDataService course lookup', ['count' => count($records)]);

                                    if (empty($records) && !isset($_GET['Year']) && !isset($_GET['semester'])) {
                                        // Keep My Courses scoped to the current semester_registration.
                                        // Older unlinked course_registration rows can have academic years
                                        // in the Year column and should not appear as current courses.
                                        addDebug('RESULT', 'No linked courses found for current semester registration; stale legacy course fallback skipped', [
                                            'semesterRegId' => $semesterRegId,
                                            'year_of_study' => $selectedYear,
                                            'semester' => $selectedSemester,
                                            'academic_year' => $academicYear
                                        ]);
                                    }

                                    if (empty($records)) {
                                    // Discover course_registration column names
                                    $crCols = [];
                                    if ($m2 = $db->query("SHOW COLUMNS FROM course_registration")) {
                                        while ($c2 = $m2->fetch_assoc()) { $crCols[strtolower((string)$c2['Field'])] = (string)$c2['Field']; }
                                        $m2->free();
                                    }
                                    addDebug('SCHEMA', 'course_registration columns discovered', $crCols);
                                    $crSidCol = $crCols['sid'] ?? ($crCols['student_id'] ?? 'Sid');
                                    $crSemCol = $crCols['semester'] ?? ($crCols['semester_term'] ?? 'semester');
                                    $crYearCol = $crCols['year'] ?? ($crCols['academic_year'] ?? 'Year');
                                    $crSemRegIdCol = $crCols['semester_registration_id'] ?? null;
                                    $crIdCol   = $crCols['coregid'] ?? null; // optional CoRegID
                                    $crCourseCol = $crCols['course_code'] ?? 'course_code';
                                    $crActiveCol = $crCols['is_active'] ?? null;

                                    $sidEsc = $db->real_escape_string($sid);
                                    $semEsc = $db->real_escape_string((string)$selectedSemester);
                                    $yearEsc = $db->real_escape_string((string)$selectedYear);
                                    $acadYearEsc = $db->real_escape_string((string)$academicYear);

                                    $selectId = $crIdCol ? ", cr.`{$crIdCol}` AS CoRegID" : ", NULL AS CoRegID";
                                    $courseCols = [];
                                    if ($mCourses = $db->query("SHOW COLUMNS FROM courses")) {
                                        while ($courseCol = $mCourses->fetch_assoc()) {
                                            $courseCols[strtolower((string)$courseCol['Field'])] = (string)$courseCol['Field'];
                                        }
                                        $mCourses->free();
                                    }
                                    $catalogCodeCol = $courseCols['course_code'] ?? 'course_code';
                                    $catalogNameCol = $courseCols['course_name'] ?? ($courseCols['name'] ?? null);
                                    $catalogCreditCol = $courseCols['credit_hours'] ?? ($courseCols['credits'] ?? ($courseCols['credit'] ?? ($courseCols['units'] ?? null)));
                                    $courseNameSelect = $catalogNameCol
                                        ? "COALESCE(c.`{$catalogNameCol}`, CONCAT('[Not in catalog] ', cr.`{$crCourseCol}`)) AS course_name"
                                        : "CONCAT('[Not in catalog] ', cr.`{$crCourseCol}`) AS course_name";
                                    $creditSelect = $catalogCreditCol ? "COALESCE(c.`{$catalogCreditCol}`, 3) AS credits" : "3 AS credits";
                                    
                                    // Prefer linking by semester_registration_id, but fall back if the data wasn't populated.
                                    $whereBySemRegId = null;
                                    if ($semesterRegId !== null && $crSemRegIdCol) {
                                        $whereBySemRegId = "cr.`{$crSemRegIdCol}` = '{$semesterRegId}'";
                                    }
                                    $whereByTerm = "cr.`{$crSidCol}` = '{$sidEsc}' "
                                                 . "AND cr.`{$crSemCol}` = '{$semEsc}' "
                                                 . "AND cr.`{$crYearCol}` = '{$yearEsc}'";
                                    if ($crActiveCol) {
                                        $whereByTerm .= " AND COALESCE(cr.`{$crActiveCol}`, 1) = 1";
                                        if ($whereBySemRegId !== null) {
                                            $whereBySemRegId .= " AND COALESCE(cr.`{$crActiveCol}`, 1) = 1";
                                        }
                                    }

                                    // Use LEFT JOIN so missing catalog rows don't hide registrations.
                                    // Also normalize course codes to reduce join mismatches due to spaces/case.
                                    $baseSql = "SELECT cr.`{$crCourseCol}` AS course_code, "
                                             . $courseNameSelect . ", "
                                             . $creditSelect . $selectId . "\n"
                                             . "FROM course_registration cr\n"
                                             . "LEFT JOIN courses c ON TRIM(UPPER(c.`{$catalogCodeCol}`)) = TRIM(UPPER(cr.`{$crCourseCol}`))\n";

                                    // Attempt 1: semester_registration_id (if available)
                                    addDebug('QUERY', 'Course lookup parameters', [
                                        'sidEsc' => $sidEsc,
                                        'semEsc' => $semEsc,
                                        'yearEsc' => $yearEsc,
                                        'semesterRegId' => $semesterRegId,
                                        'crSidCol' => $crSidCol,
                                        'crSemCol' => $crSemCol,
                                        'crYearCol' => $crYearCol
                                    ]);
                                    if ($whereBySemRegId !== null) {
                                        $sql = $baseSql . "WHERE {$whereBySemRegId}\n" . "ORDER BY cr.`{$crCourseCol}`";
                                        addDebug('QUERY', 'Attempt 1: by semester_registration_id', ['sql' => $sql]);
                                        error_log('myCourses.php DEBUG: Attempt 1 query (by semester_registration_id): ' . $sql);
                                        if ($results = $db->query($sql)) {
                                            while ($row = $results->fetch_object()) { $records[] = $row; }
                                            addDebug('RESULT', 'Attempt 1 results', ['count' => count($records)]);
                                            error_log('myCourses.php DEBUG: Attempt 1 found ' . count($records) . ' courses');
                                            $results->free();
                                        } else {
                                            addDebug('ERROR', 'Attempt 1 query failed', ['error' => $db->error]);
                                            error_log('myCourses.php ERROR: Attempt 1 query failed: ' . $db->error);
                                        }
                                    }
                                    // Attempt 2: term columns — also runs when semester_registration_id
                                    // lookup returned empty (legacy rows with NULL FK).
                                    if (empty($records)) {
                                        $sql = $baseSql . "WHERE {$whereByTerm}\n" . "ORDER BY cr.`{$crCourseCol}`";
                                        addDebug('QUERY', 'Attempt 2: by term columns', ['sql' => $sql, 'whereByTerm' => $whereByTerm]);
                                        error_log('myCourses.php DEBUG: Attempt 2 query (by term columns): ' . $sql);
                                        if ($results = $db->query($sql)) {
                                            while ($row = $results->fetch_object()) { $records[] = $row; }
                                            addDebug('RESULT', 'Attempt 2 results', ['count' => count($records)]);
                                            error_log('myCourses.php DEBUG: Attempt 2 found ' . count($records) . ' courses');
                                            $results->free();
                                        } else {
                                            addDebug('ERROR', 'Attempt 2 query failed', ['error' => $db->error]);
                                            error_log('myCourses.php ERROR: Attempt 2 query failed: ' . $db->error);
                                        }
                                    }
                                    
                                    // Fallback: Check student_courses (legacy table) if no records found
                                    if (empty($records)) {
                                        addDebug('QUERY', 'Attempting fallback: student_courses table');
                                        $scCols = [];
                                        $hasStudentCourses = false;
                                        if ($scTable = $db->query("SHOW TABLES LIKE 'student_courses'")) {
                                            $hasStudentCourses = $scTable->num_rows > 0;
                                            $scTable->free();
                                        }
                                        if ($hasStudentCourses && ($m4 = $db->query("SHOW COLUMNS FROM student_courses"))) {
                                            while ($c4 = $m4->fetch_assoc()) { $scCols[strtolower((string)$c4['Field'])] = (string)$c4['Field']; }
                                            $m4->free();
                                        }
                                        addDebug('SCHEMA', 'student_courses columns discovered', $scCols);
                                        if ($hasStudentCourses && !empty($scCols)) {
                                            $scSidCol = $scCols['student_id'] ?? ($scCols['sid'] ?? 'student_id');
                                            $scSemCol = $scCols['semester'] ?? ($scCols['semester_term'] ?? 'semester');
                                            $scAcadYearCol = $scCols['academic_year'] ?? ($scCols['year'] ?? 'academic_year');
                                            
                                            // Build fallback WHERE clause
                                            $scWhere = "sc.`{$scSidCol}` = '{$sidEsc}' AND sc.`{$scSemCol}` = '{$semEsc}'";
                                            // Only add year filter if we can match it reasonably (e.g. first 4 chars of acadYearEsc)
                                            if ($academicYear !== '') {
                                                $shortYear = substr($academicYear, 0, 4);
                                                $scWhere .= " AND (sc.`{$scAcadYearCol}` = '{$acadYearEsc}' OR sc.`{$scAcadYearCol}` = '{$shortYear}' OR sc.`{$scAcadYearCol}` = '{$yearEsc}')";
                                            }

                                            $legacyCourseNameSelect = $catalogNameCol ? "COALESCE(c.`{$catalogNameCol}`, '') AS course_name" : "'' AS course_name";
                                            $sqlLegacy = "SELECT sc.course_code, {$legacyCourseNameSelect}, {$creditSelect}, NULL AS CoRegID\n"
                                                       . "FROM student_courses sc\n"
                                                       . "LEFT JOIN courses c ON TRIM(UPPER(c.`{$catalogCodeCol}`)) = TRIM(UPPER(sc.course_code))\n"
                                                       . "WHERE {$scWhere}\n"
                                                       . "ORDER BY sc.course_code";
                                            
                                            addDebug('QUERY', 'Attempt 3: legacy student_courses', ['sql' => $sqlLegacy, 'where' => $scWhere]);
                                            error_log('myCourses.php DEBUG: Attempt 3 query (legacy student_courses): ' . $sqlLegacy);
                                            if ($rs2 = $db->query($sqlLegacy)) {
                                                while ($row2 = $rs2->fetch_object()) { $records[] = $row2; }
                                                addDebug('RESULT', 'Attempt 3 (legacy) results', ['count' => count($records)]);
                                                error_log('myCourses.php DEBUG: Attempt 3 (legacy) found ' . count($records) . ' courses');
                                                $rs2->free();
                                            } else {
                                                addDebug('ERROR', 'Attempt 3 (legacy) query failed', ['error' => $db->error]);
                                                error_log('myCourses.php ERROR: Attempt 3 (legacy) query failed: ' . $db->error);
                                            }
                                        } else {
                                            addDebug('SCHEMA', 'student_courses fallback skipped because table is not present');
                                        }
                                    }
                                    }
                                    
                                    // Final debug summary
                                    addDebug('SUMMARY', 'Course lookup complete', [
                                        'total_courses_found' => count($records),
                                        'courses' => array_map(function($r) { return $r->course_code; }, $records)
                                    ]);

                                }
                            }
                            ?>
                            
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <?php if (!empty($selectedYear) && !empty($selectedSemester)) { ?>
                                <span class="badge bg-primary">Year <?php echo htmlspecialchars((string)$selectedYear); ?> &bull; <?php echo htmlspecialchars($headerPeriodLabel); ?> <?php echo htmlspecialchars((string)$selectedSemester); ?><?php echo !empty($academicYear) ? ' &bull; ' . htmlspecialchars((string)$academicYear) : ''; ?></span>
                                <?php } ?>
                                <div class="text-muted small">Total: <?php 
                                    $courseCount = count($records);
                                    error_log('myCourses.php DEBUG: Final course count displayed: ' . $courseCount);
                                    echo $courseCount; 
                                ?> course(s)</div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle" id="coursesTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Course Code</th>
                                            <th>Course Name</th>
                                            <th class="text-center">Credit hours</th>
                                            <th class="text-center">Content</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        foreach($records as $r) {
                                        ?>
                                        <tr>
                                            <td class="text-muted"><?php echo $number ++; ?></td>
                                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars((string)$r->course_code); ?></span></td>
                                            <td><?php echo htmlspecialchars((string)$r->course_name); ?></td>
                                            <td class="text-center"><?php $cr = isset($r->credits) ? (int)$r->credits : 3; echo $cr > 0 ? '<span class="badge bg-primary">' . $cr . '</span>' : '<span class="text-muted">&ndash;</span>'; ?></td>
                                            <td class="text-center">
                                                <?php 
                                                $courseCodeDebug = (string)$r->course_code;
                                                // Short-course rows open the short-course content view; academic
                                                // rows use materials.php (the academic course content view).
                                                if (!empty($r->short_course_id)) {
                                                    $viewUrl = 'short_courses.php?id=' . urlencode((string)$r->short_course_id);
                                                } else {
                                                    $viewUrl = 'materials.php?code=' . urlencode($courseCodeDebug);
                                                }
                                                if (empty($courseCodeDebug)) {
                                                    echo '<span class="text-danger small">No course code</span>';
                                                    error_log('myCourses.php: Empty course code for record');
                                                } else {
                                                    echo '<a href="' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '" class="btn btn-sm btn-primary" title="View ' . htmlspecialchars($courseCodeDebug) . ' content"><i class="fas fa-eye me-1"></i>View Course</a>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                        <?php 
                                        }  
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (empty($records) && isset($sid) && $sid !== '' && !empty($selectedYear) && !empty($selectedSemester)) { ?>
                                <div class="alert alert-info mt-3">No registered courses found for your current semester registration.</div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>

           
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($debugMode): ?>
<!-- Debug Panel -->
<!-- Debug Panel -->
<div id="debugPanel" class="debug-panel d-none">
    <div class="debug-panel-header">
        <span><strong>🔧 Debug Panel</strong> - myCourses.php</span>
        <div>
            <button onclick="copyDebugLog()" class="debug-btn me-1">Copy Log</button>
            <button onclick="toggleDebugPanel()" class="debug-btn">Hide</button>
        </div>
    </div>
    <div id="debugContent" class="p-2"></div>
</div>
<button id="debugToggle" onclick="toggleDebugPanel()" class="debug-toggle-btn">🔧 Debug</button>

<script>
// Debug data from PHP
const debugLog = <?php echo json_encode($debugLog, JSON_PRETTY_PRINT); ?>;
const pageData = {
    studentId: <?php echo json_encode($sid ?? ''); ?>,
    selectedYear: <?php echo json_encode($selectedYear ?? ''); ?>,
    selectedSemester: <?php echo json_encode($selectedSemester ?? ''); ?>,
    academicYear: <?php echo json_encode($academicYear ?? ''); ?>,
    courseCount: <?php echo count($records); ?>,
    debugMode: true,
    // Term comparison data
    term: {
        student: {
            semester: <?php echo json_encode($currentSemester ?? ''); ?>,
            yearOfStudy: <?php echo json_encode($currentYear ?? ''); ?>,
            academicYear: <?php echo json_encode($currentAcadYear ?? ''); ?>
        },
        system: {
            semester: <?php echo json_encode($systemSemester ?? null); ?>,
            academicYear: <?php echo json_encode($systemAcademicYear ?? null); ?>
        },
        match: <?php echo json_encode(
            ($systemSemester && $currentSemester && $systemSemester == $currentSemester) &&
            ($systemAcademicYear && $currentAcadYear && $systemAcademicYear == $currentAcadYear)
        ); ?>
    }
};

// Log to console
console.group('%c🔧 myCourses.php Debug Info', 'background:#007acc;color:#fff;padding:4px 8px;border-radius:3px;');
console.log('%cPage Data:', 'font-weight:bold;color:#4fc3f7;', pageData);
console.log('%cDebug Log:', 'font-weight:bold;color:#4fc3f7;');
debugLog.forEach((entry, i) => {
    const color = entry.category === 'ERROR' ? '#f44336' : 
                  entry.category === 'RESULT' ? '#4caf50' : 
                  entry.category === 'QUERY' ? '#ff9800' : '#2196f3';
    console.log(`%c[${entry.time}] [${entry.category}]%c ${entry.message}`, 
                `color:${color};font-weight:bold;`, 'color:#d4d4d4;', 
                entry.data || '');
});
console.groupEnd();

// Render debug panel
function renderDebugPanel() {
    const content = document.getElementById('debugContent');
    let html = '<div class="debug-data-container"><strong class="debug-data-title">Page Data:</strong><pre class="debug-data-pre">' + JSON.stringify(pageData, null, 2) + '</pre></div>';
    html += '<div class="debug-log-title"><strong>Execution Log:</strong></div>';
    
    debugLog.forEach((entry, i) => {
        const bgColor = entry.category === 'ERROR' ? '#5c2626' : 
                        entry.category === 'RESULT' ? '#264d26' : 
                        entry.category === 'QUERY' ? '#4d3d1a' : '#1e3a5f';
        const textColor = entry.category === 'ERROR' ? '#f48771' : 
                          entry.category === 'RESULT' ? '#89d185' : 
                          entry.category === 'QUERY' ? '#dcdcaa' : '#9cdcfe';
        html += `<div class="debug-log-entry" style="background:${bgColor};border-left:3px solid ${textColor};">`;
        html += `<span class="debug-log-time">[${entry.time}]</span> `;
        html += `<span style="color:${textColor};font-weight:bold;">[${entry.category}]</span> `;
        html += `<span class="debug-log-msg">${entry.message}</span>`;
        if (entry.data) {
            html += `<pre class="debug-log-pre">${JSON.stringify(entry.data, null, 2)}</pre>`;
        }
        html += '</div>';
    });
    content.innerHTML = html;
}

function toggleDebugPanel() {
    const panel = document.getElementById('debugPanel');
    const toggle = document.getElementById('debugToggle');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        toggle.style.display = 'none';
        renderDebugPanel();
    } else {
        panel.style.display = 'none';
        toggle.style.display = 'block';
    }
}

function copyDebugLog() {
    const text = JSON.stringify({pageData, debugLog}, null, 2);
    navigator.clipboard.writeText(text).then(() => {
        alert('Debug log copied to clipboard!');
    });
}

// Auto-show panel if there are errors
if (debugLog.some(e => e.category === 'ERROR')) {
    toggleDebugPanel();
}
</script>
<?php endif; ?>

</body>
</html>
