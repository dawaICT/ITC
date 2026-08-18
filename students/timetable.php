<?php
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/includes/period_mode_helper.php';
require_once __DIR__ . '/includes/RegistrationDataService.php';
require_once __DIR__ . '/includes/AcademicSessionService.php';
require_once dirname(__DIR__) . '/includes/short_course_student.php';

if (!isset($_SESSION['Sid'])) {
  header('Location: ../student_login.php');
  exit();
}

$Sid = $_SESSION['Sid'];
$registrationDataService = new RegistrationDataService($db);
$periodMode = getStudentProgramPeriodMode($db, (string)$Sid);
$sessionService = new AcademicSessionService($db);
$currentSession = $sessionService->getCurrentSession($periodMode);
$termContext = null;
if ($currentSession) {
    $currentAy = (string)($currentSession['academic_year'] ?? '');
    $currentSem = (int)($currentSession['semester_term'] ?? 0);
    if ($currentAy !== '' && $currentSem > 0) {
        $termContext = $registrationDataService->getLatestSemesterRegistration($Sid, $currentAy, $currentSem, $periodMode);
    }
}
if (!$termContext) {
    $termContext = $registrationDataService->getLatestSemesterRegistration($Sid);
}
$defaultYear = (int)($termContext['year_of_study'] ?? date('Y'));
$defaultSemester = (int)($termContext['semester'] ?? 1);
$semesterRegistrationId = isset($termContext['id']) ? (int)$termContext['id'] : null;
$academicYear = isset($termContext['academic_year']) ? (string)$termContext['academic_year'] : null;

$hasExplicitTermFilter = isset($_GET['Year']) || isset($_GET['semester']);
$year = isset($_GET['Year']) ? (int)$_GET['Year'] : $defaultYear;
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : $defaultSemester;
$view = isset($_GET['view']) ? $_GET['view'] : 'weekly'; // weekly, daily, list

if ($hasExplicitTermFilter) {
  // Do not keep the latest registration id when the student manually selects
  // a different term; otherwise the selected Year/Semester label and loaded
  // courses can point to different registrations.
  $semesterRegistrationId = $registrationDataService->getSemesterRegistrationId($Sid, $year, $semester);
}

function student_timetable_table_exists(mysqli $db, string $table): bool {
  $safeTable = $db->real_escape_string($table);
  if ($result = $db->query("SHOW TABLES LIKE '{$safeTable}'")) {
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
  }
  return false;
}

function student_timetable_columns(mysqli $db, string $table): array {
  $columns = [];
  $safeTable = $db->real_escape_string($table);
  if ($result = $db->query("SHOW COLUMNS FROM `{$safeTable}`")) {
    while ($row = $result->fetch_assoc()) {
      $columns[strtolower((string)$row['Field'])] = (string)$row['Field'];
    }
    $result->free();
  }
  return $columns;
}

function student_timetable_default_slots(): array {
  return [
    ['slot_name' => 'Class 1', 'start_time' => '08:30:00', 'end_time' => '10:30:00', 'type' => 'class'],
    ['slot_name' => 'Break', 'start_time' => '10:30:00', 'end_time' => '11:00:00', 'type' => 'break'],
    ['slot_name' => 'Class 2', 'start_time' => '11:00:00', 'end_time' => '13:00:00', 'type' => 'class'],
    ['slot_name' => 'Lunch', 'start_time' => '13:00:00', 'end_time' => '14:00:00', 'type' => 'break'],
    ['slot_name' => 'Class 3', 'start_time' => '14:00:00', 'end_time' => '16:00:00', 'type' => 'class'],
  ];
}

function student_timetable_class_in_slot(array $class, array $slot): bool {
  if (($slot['type'] ?? 'class') !== 'class' || empty($class['start_time'])) {
    return false;
  }

  $classStart = strtotime((string)$class['start_time']);
  $slotStart = strtotime((string)$slot['start_time']);
  $slotEnd = strtotime((string)$slot['end_time']);

  return $classStart !== false && $slotStart !== false && $slotEnd !== false
    && $classStart >= $slotStart
    && $classStart < $slotEnd;
}

function student_timetable_slot_covers_class(array $slots, array $class): bool {
  foreach ($slots as $slot) {
    if (student_timetable_class_in_slot($class, $slot)) {
      return true;
    }
  }
  return false;
}

function student_timetable_slots_with_scheduled_times(array $defaultSlots, array $rows): array {
  $slots = $defaultSlots;
  $seen = [];
  foreach ($slots as $slot) {
    $seen[($slot['start_time'] ?? '') . '|' . ($slot['end_time'] ?? '')] = true;
  }

  foreach ($rows as $row) {
    if (empty($row['day_of_week']) || empty($row['start_time']) || empty($row['end_time'])) {
      continue;
    }
    if (student_timetable_slot_covers_class($defaultSlots, $row)) {
      continue;
    }
    $start = strlen((string)$row['start_time']) === 5 ? $row['start_time'] . ':00' : (string)$row['start_time'];
    $end = strlen((string)$row['end_time']) === 5 ? $row['end_time'] . ':00' : (string)$row['end_time'];
    $key = $start . '|' . $end;
    if (isset($seen[$key])) {
      continue;
    }
    $seen[$key] = true;
    $slots[] = [
      'slot_name' => date('H:i', strtotime($start)) . ' - ' . date('H:i', strtotime($end)),
      'start_time' => $start,
      'end_time' => $end,
      'type' => 'class',
    ];
  }

  usort($slots, static function(array $a, array $b): int {
    return strcmp((string)($a['start_time'] ?? ''), (string)($b['start_time'] ?? ''));
  });

  return $slots;
}

// Get period mode for the student's program
$periodLabel = getPeriodLabel($db, $Sid);
$periodLabelShort = getPeriodLabelShort($db, $Sid);
$isTermBased = isTermBasedProgram($db, $Sid);

// Get registered courses from the schema-aware registration service, then attach
// schedule rows only when the local schedule table has matching columns/data.
$rows = [];
$registeredCourses = $registrationDataService->getRegisteredCourses($Sid, $year, $semester, $semesterRegistrationId, $academicYear);
if (!$hasExplicitTermFilter && empty($registeredCourses)) {
  $courseTermContext = $registrationDataService->getLatestRegisteredCourseTerm($Sid);
  if ($courseTermContext) {
    $year = (int)($courseTermContext['year_of_study'] ?? $year);
    $semester = (int)($courseTermContext['semester'] ?? $semester);
    $semesterRegistrationId = isset($courseTermContext['id']) ? (int)$courseTermContext['id'] : null;
    $academicYear = isset($courseTermContext['academic_year']) ? (string)$courseTermContext['academic_year'] : $academicYear;
    $registeredCourses = $registrationDataService->getRegisteredCourses($Sid, $year, $semester, $semesterRegistrationId, $academicYear);
  }
}
$courseMap = [];
$shortCourseKeys = [];
foreach ($registeredCourses as $course) {
  $code = trim((string)($course['course_code'] ?? ''));
  if ($code === '') {
    continue;
  }
  $courseMap[strtoupper($code)] = [
    'course_code' => $code,
    'course_name' => (string)($course['course_name'] ?? $code),
    'credits' => $course['credit_hours'] ?? ($course['credits'] ?? null),
    'day_of_week' => null,
    'schedule_type' => 'lecture',
    'slot_name' => null,
    'start_time' => null,
    'end_time' => null,
    'room_code' => null,
    'room_name' => null,
    'building' => null,
    'lecturer_name' => null,
    'is_short_course' => false,
  ];
}

foreach (sc_student_enrolments($db, $Sid) as $course) {
  $code = trim((string)($course['course_code'] ?? ''));
  if ($code === '') {
    continue;
  }
  $key = strtoupper($code);
  $shortCourseKeys[$key] = true;
  if (!isset($courseMap[$key])) {
    $courseMap[$key] = [
      'course_code' => $code,
      'course_name' => (string)($course['course_name'] ?? $code),
      'credits' => null,
      'day_of_week' => null,
      'schedule_type' => 'lecture',
      'slot_name' => null,
      'start_time' => null,
      'end_time' => null,
      'room_code' => null,
      'room_name' => null,
      'building' => null,
      'lecturer_name' => null,
      'is_short_course' => true,
    ];
  } else {
    $courseMap[$key]['is_short_course'] = true;
  }
}

$scheduledCourseKeys = [];
$hasScheduleTables = student_timetable_table_exists($db, 'course_schedule');
$scheduleCols = $hasScheduleTables ? student_timetable_columns($db, 'course_schedule') : [];
if ($hasScheduleTables && !empty($courseMap) && isset($scheduleCols['course_code'])) {
  $dayCol = $scheduleCols['day_of_week'] ?? ($scheduleCols['day'] ?? null);
  $typeCol = $scheduleCols['schedule_type'] ?? ($scheduleCols['type'] ?? null);
  $slotCol = $scheduleCols['slot_name'] ?? ($scheduleCols['title'] ?? null);
  $startCol = $scheduleCols['start_time'] ?? null;
  $endCol = $scheduleCols['end_time'] ?? null;
  $roomCol = $scheduleCols['room_code'] ?? ($scheduleCols['room'] ?? null);
  $buildingCol = $scheduleCols['building'] ?? null;
  $lecturerCol = $scheduleCols['lecturer_id'] ?? null;
  $yearCol = $scheduleCols['year'] ?? null;
  $semCol = $scheduleCols['semester'] ?? null;
  $statusCol = $scheduleCols['status'] ?? null;

  $select = [
    'cs.`' . $scheduleCols['course_code'] . '` AS course_code',
    $dayCol ? "cs.`{$dayCol}` AS day_of_week" : 'NULL AS day_of_week',
    $typeCol ? "cs.`{$typeCol}` AS schedule_type" : "'lecture' AS schedule_type",
    $slotCol ? "cs.`{$slotCol}` AS slot_name" : 'NULL AS slot_name',
    $startCol ? "TIME_FORMAT(cs.`{$startCol}`, '%H:%i') AS start_time" : 'NULL AS start_time',
    $endCol ? "TIME_FORMAT(cs.`{$endCol}`, '%H:%i') AS end_time" : 'NULL AS end_time',
    $roomCol ? "cs.`{$roomCol}` AS room_code" : 'NULL AS room_code',
    $roomCol ? "cs.`{$roomCol}` AS room_name" : 'NULL AS room_name',
    $buildingCol ? "cs.`{$buildingCol}` AS building" : 'NULL AS building',
    $lecturerCol ? "CONCAT(s.Fname, ' ', s.Lname) AS lecturer_name" : 'NULL AS lecturer_name',
  ];
  $placeholders = implode(',', array_fill(0, count($courseMap), '?'));
  $scheduleSql = 'SELECT ' . implode(', ', $select) . ' FROM course_schedule cs ';
  if ($lecturerCol) {
    $scheduleSql .= "LEFT JOIN staff s ON s.staff_id = cs.`{$lecturerCol}` ";
  }
  $scheduleSql .= "WHERE UPPER(TRIM(cs.`{$scheduleCols['course_code']}`)) IN ({$placeholders})";
  $params = array_keys($courseMap);
  $types = str_repeat('s', count($params));
  $periodParts = [];
  if (!empty($shortCourseKeys)) {
    $periodParts[] = "UPPER(TRIM(cs.`{$scheduleCols['course_code']}`)) IN (" . implode(',', array_fill(0, count($shortCourseKeys), '?')) . ")";
    foreach (array_keys($shortCourseKeys) as $code) {
      $params[] = $code;
      $types .= 's';
    }
  }
  $academicPeriodParts = [];
  if ($yearCol) {
    $academicPeriodParts[] = "(cs.`{$yearCol}` = ? OR cs.`{$yearCol}` IS NULL)";
    $params[] = (string)$year;
    $types .= 's';
  }
  if ($semCol) {
    $academicPeriodParts[] = "(cs.`{$semCol}` = ? OR cs.`{$semCol}` IS NULL)";
    $params[] = (string)$semester;
    $types .= 's';
  }
  if (!empty($academicPeriodParts)) {
    $periodParts[] = '(' . implode(' AND ', $academicPeriodParts) . ')';
  }
  if (!empty($periodParts)) {
    $scheduleSql .= ' AND (' . implode(' OR ', $periodParts) . ')';
  }
  if ($statusCol) {
    $scheduleSql .= " AND (cs.`{$statusCol}` = 'active' OR cs.`{$statusCol}` IS NULL)";
  }
  $orderDay = $dayCol ? "FIELD(cs.`{$dayCol}`, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')," : '';
  $orderTime = $startCol ? "cs.`{$startCol}`," : '';
  $scheduleSql .= " ORDER BY {$orderDay} {$orderTime} cs.`{$scheduleCols['course_code']}`";

  if ($stmt = $db->prepare($scheduleSql)) {
    $refs = [];
    foreach ($params as $idx => &$value) {
      $refs[$idx] = &$value;
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($schedule = $result->fetch_assoc()) {
      $key = strtoupper(trim((string)$schedule['course_code']));
      if (!isset($courseMap[$key])) {
        continue;
      }
      $scheduledCourseKeys[$key] = true;
      $rows[] = array_merge($courseMap[$key], $schedule);
    }
    $stmt->close();
  }
}

foreach ($courseMap as $key => $course) {
  if (!isset($scheduledCourseKeys[$key])) {
    $rows[] = $course;
  }
}

// Group by day for grid and list lookup.
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
foreach ($days as $day) {
  $scheduleByDay[$day] = [];
}
foreach ($rows as $row) {
  if (!empty($row['day_of_week'])) {
    $scheduleByDay[$row['day_of_week']][] = $row;
  }
}

// Include exact admin/HOS schedule times so classes outside the standard slots
// still appear on the student weekly grid.
$timeSlots = student_timetable_slots_with_scheduled_times(student_timetable_default_slots(), $rows);

// Get upcoming exams
$exams = [];
if (student_timetable_table_exists($db, 'exam_schedule')) {
// course_registration has no is_active column in every install; resolve the
// "active registration" filter from whatever column actually exists.
$crCols = student_timetable_columns($db, 'course_registration');
$crActiveCond = '';
if (isset($crCols['is_active'])) {
  $crActiveCond = " AND cr.`{$crCols['is_active']}` = 1";
} elseif (isset($crCols['status'])) {
  $crActiveCond = " AND (cr.`{$crCols['status']}` = 'active' OR cr.`{$crCols['status']}` IS NULL)";
}
$crAcademicYearCond = '';
$examTypes = 'si';
$examParams = [$Sid, $year];
if (isset($crCols['academic_year']) && $academicYear !== null && trim((string)$academicYear) !== '') {
  $crAcademicYearCond = " AND (cr.`{$crCols['academic_year']}` = ? OR cr.`{$crCols['academic_year']}` IS NULL OR cr.`{$crCols['academic_year']}` = '')";
  $examTypes .= 's';
  $examParams[] = (string)$academicYear;
}
$examSql = "SELECT
              es.course_code,
              c.course_name,
              es.exam_type,
              es.exam_date,
              TIME_FORMAT(es.start_time, '%H:%i') as start_time,
              TIME_FORMAT(es.end_time, '%H:%i') as end_time,
              cl.room_code,
              cl.room_name
            FROM exam_schedule es
            LEFT JOIN courses c ON c.course_code COLLATE utf8mb4_unicode_ci = es.course_code COLLATE utf8mb4_unicode_ci
            LEFT JOIN classrooms cl ON cl.id = es.classroom_id
            WHERE es.course_code IN (
              SELECT cr.course_code COLLATE utf8mb4_general_ci FROM course_registration cr
              WHERE cr.Sid = ? AND cr.Year = ?{$crAcademicYearCond}{$crActiveCond}
            )
            AND es.exam_date >= CURDATE()
            AND es.status = 'scheduled'
            ORDER BY es.exam_date, es.start_time";
$examStmt = $db->prepare($examSql);
$examStmt->bind_param($examTypes, ...$examParams);
$examStmt->execute();
$examResult = $examStmt->get_result();
while ($e = $examResult->fetch_assoc()) {
  $exams[] = $e;
}
$examStmt->close();
}

// Helper function for schedule type badge
function getScheduleTypeBadge($type) {
  $badges = [
    'lecture' => 'bg-primary',
    'tutorial' => 'bg-success',
    'lab' => 'bg-warning text-dark',
    'seminar' => 'bg-info',
    'workshop' => 'bg-secondary'
  ];
  return $badges[$type] ?? 'bg-secondary';
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Timetable - ITC</title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="/wucportal/css/admin-style.css">
  <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
  <style>
    /* Timetable-specific styles – generic from student-unified.css */
    .timetable-grid { min-height: 500px; }
    .time-slot-cell { min-height: 96px; border: 1px solid var(--sp-border); position: relative; background: #fff; }
    .time-slot-cell.has-class { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); }
    .time-header { background: #f8f9fa; font-weight: 600; text-align: center; padding: 10px 5px; font-size: 0.85rem; }
    .day-header { background: var(--sp-primary); color: white; font-weight: 600; text-align: center; padding: 12px; }
    .break-row .time-header,
    .break-cell { background: #fff7ed; color: #9a3412; font-weight: 700; text-align: center; }
    .break-cell { border: 1px solid #fed7aa; letter-spacing: 0; }
    .schedule-card { background: white; border-radius: var(--sp-radius-sm); padding: 8px; margin: 4px; box-shadow: var(--sp-shadow); font-size: 0.8rem; cursor: pointer; transition: transform 0.2s; }
    .schedule-card:hover { transform: scale(1.02); box-shadow: var(--sp-shadow-md); }
    .schedule-card.lecture { border-left: 4px solid var(--sp-primary); }
    .schedule-card.tutorial { border-left: 4px solid var(--sp-green); }
    .schedule-card.lab { border-left: 4px solid var(--sp-amber); }
    .schedule-card.seminar { border-left: 4px solid var(--sp-blue); }
    .schedule-card .course-code { font-weight: 700; color: var(--sp-text-dark); }
    .schedule-card .course-name { color: var(--sp-text-muted); font-size: 0.75rem; }
    .schedule-card .venue { color: var(--sp-text-light); font-size: 0.7rem; margin-top: 4px; }
    .view-tabs .nav-link { color: var(--sp-text-muted); }
    .view-tabs .nav-link.active { background: var(--sp-primary); color: white; }
    .exam-card { border-left: 4px solid var(--sp-red); background: rgba(239,68,68,0.04); }
    .legend-item { display: inline-flex; align-items: center; margin-right: 15px; font-size: 0.85rem; }
    .legend-color { width: 16px; height: 16px; border-radius: 4px; margin-right: 6px; }
    .no-schedule-msg { color: var(--sp-text-light); font-style: italic; text-align: center; padding: 20px; }
    @media print { .no-print { display: none !important; } .timetable-grid { page-break-inside: avoid; } }
  </style>
</head>
<body class="bg-light">
  <?php require_once __DIR__ . '/includes/navbar.php'; ?>
  <div class="content-wrapper">
    <div class="container-fluid px-4">
      <!-- Header -->
      <header class="student-page-heading d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
          <h3 class="mb-1"><i class="fas fa-calendar-alt me-2"></i>My Timetable</h3>
          <p class="text-muted mb-0">Year of Study <?php echo htmlspecialchars((string)$year); ?> - <?php echo $periodLabel . ' ' . $semester; ?></p>
        </div>
        <div class="no-print">
          <button class="btn btn-outline-secondary btn-sm me-2" onclick="window.print()">
            <i class="fas fa-print me-1"></i>Print
          </button>
          <button class="btn btn-outline-primary btn-sm" onclick="exportTimetable()">
            <i class="fas fa-download me-1"></i>Export
          </button>
        </div>
      </header>

      <!-- Filters -->
      <div class="card mb-4 no-print">
        <div class="card-body">
          <form class="row g-3 align-items-end" method="get">
            <div class="col-md-2">
              <label class="form-label" for="timetableYear">Year of Study</label>
              <input type="number" class="form-control" id="timetableYear" name="Year" value="<?php echo htmlspecialchars((string)$year); ?>" min="1" max="7">
            </div>
            <div class="col-md-2">
              <label class="form-label" for="timetableSemester"><?php echo $periodLabel; ?></label>
              <select class="form-select" id="timetableSemester" name="semester">
                <option value="1" <?php echo $semester===1?'selected':''; ?>><?php echo $periodLabel; ?> 1</option>
                <option value="2" <?php echo $semester===2?'selected':''; ?>><?php echo $periodLabel; ?> 2</option>
                <?php if ($isTermBased): ?>
                <option value="3" <?php echo $semester===3?'selected':''; ?>><?php echo $periodLabel; ?> 3</option>
                <?php endif; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label" for="timetableView">View</label>
              <select class="form-select" id="timetableView" name="view">
                <option value="weekly" <?php echo $view==='weekly'?'selected':''; ?>>Weekly Grid</option>
                <option value="list" <?php echo $view==='list'?'selected':''; ?>>List View</option>
              </select>
            </div>
            <div class="col-auto">
              <button class="btn btn-primary"><i class="fas fa-filter me-1"></i>Apply</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Legend -->
      <div class="mb-3 no-print">
        <div class="legend-item"><div class="legend-color bg-primary"></div> Lecture</div>
        <div class="legend-item"><div class="legend-color bg-success"></div> Tutorial</div>
        <div class="legend-item"><div class="legend-color bg-warning"></div> Lab</div>
        <div class="legend-item"><div class="legend-color bg-info"></div> Seminar</div>
      </div>

      <?php
      // Warn when the student has registered courses but none have a schedule assigned yet.
      $hasAnySchedule = !empty(array_filter($rows, fn($r) => !empty($r['day_of_week'])));
      if (!empty($rows) && !$hasAnySchedule):
      ?>
        <div class="alert alert-warning">
          <i class="fas fa-calendar-times me-2"></i>Your timetable is not available yet. Please check again later or contact your department.
        </div>
      <?php endif; ?>

      <?php if (empty($rows)): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i>No timetable is available for Year of Study <?php echo $year; ?>, <?php echo $periodLabel . ' ' . $semester; ?> because no registered courses were found.
        </div>
      <?php elseif ($view === 'weekly'): ?>
        <!-- Weekly Grid View -->
        <div class="card">
          <div class="card-body p-0">
            <div class="table-responsive timetable-grid">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th class="time-header w-80px">Time</th>
                    <?php foreach ($days as $day): ?>
                      <th class="day-header"><?php echo $day; ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($timeSlots as $slot): ?>
                    <?php if (($slot['type'] ?? 'class') === 'break'): ?>
                    <tr class="break-row">
                      <td class="time-header">
                        <?php echo date('H:i', strtotime($slot['start_time'])); ?><br>
                        <small><?php echo date('H:i', strtotime($slot['end_time'])); ?></small>
                      </td>
                      <td class="break-cell" colspan="<?php echo count($days); ?>">
                        <i class="fas fa-coffee me-2"></i><?php echo htmlspecialchars($slot['slot_name']); ?>
                      </td>
                    </tr>
                    <?php continue; endif; ?>
                    <tr>
                      <td class="time-header">
                        <?php echo date('H:i', strtotime($slot['start_time'])); ?><br>
                        <small class="text-muted"><?php echo date('H:i', strtotime($slot['end_time'])); ?></small>
                      </td>
                      <?php foreach ($days as $day): 
                        $slotClasses = array_values(array_filter($scheduleByDay[$day] ?? [], fn($s) => student_timetable_class_in_slot($s, $slot)));
                        $hasClasses = !empty($slotClasses);
                      ?>
                        <td class="time-slot-cell <?php echo $hasClasses ? 'has-class' : ''; ?>">
                          <?php if ($hasClasses): 
                            foreach ($slotClasses as $s): ?>
                              <div class="schedule-card <?php echo htmlspecialchars($s['schedule_type'] ?? 'lecture'); ?>" 
                                   data-bs-toggle="tooltip" 
                                   title="<?php echo htmlspecialchars($s['course_name'] ?? ''); ?>">
                                <div class="course-code"><?php echo htmlspecialchars($s['course_code']); ?></div>
                                <div class="course-name text-truncate"><?php echo htmlspecialchars($s['course_name'] ?? ''); ?></div>
                                <?php if (!empty($s['start_time']) || !empty($s['end_time'])): ?>
                                  <div class="venue"><i class="fas fa-clock me-1"></i><?php echo htmlspecialchars(($s['start_time'] ?? 'TBA') . ' - ' . ($s['end_time'] ?? 'TBA')); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($s['room_code'])): ?>
                                  <div class="venue"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($s['room_code']); ?></div>
                                <?php endif; ?>
                              </div>
                          <?php endforeach; endif; ?>
                        </td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      <?php else: ?>
        <!-- List View -->
        <div class="row">
          <div class="col-lg-8">
            <div class="card">
              <div class="card-header bg-white">
                <strong><i class="fas fa-list me-2"></i>Class Schedule</strong>
              </div>
              <div class="card-body">
                <?php 
                $hasSchedule = false;
                foreach ($days as $day): 
                  if (!empty($scheduleByDay[$day])): 
                    $hasSchedule = true;
                ?>
                  <h6 class="text-primary mb-3"><i class="fas fa-calendar-day me-2"></i><?php echo $day; ?></h6>
                  <div class="list-group mb-4">
                    <?php foreach ($scheduleByDay[$day] as $s): ?>
                      <div class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between align-items-start">
                          <div>
                            <span class="badge <?php echo getScheduleTypeBadge($s['schedule_type'] ?? 'lecture'); ?> me-2">
                              <?php echo ucfirst($s['schedule_type'] ?? 'Lecture'); ?>
                            </span>
                            <strong><?php echo htmlspecialchars($s['course_code']); ?></strong>
                            - <?php echo htmlspecialchars($s['course_name'] ?? 'N/A'); ?>
                            <?php if (!empty($s['lecturer_name'])): ?>
                              <br><small class="text-muted"><i class="fas fa-user me-1"></i><?php echo htmlspecialchars($s['lecturer_name']); ?></small>
                            <?php endif; ?>
                          </div>
                          <div class="text-end">
                            <span class="badge bg-light text-dark">
                              <?php echo htmlspecialchars($s['start_time'] ?? 'TBA'); ?> - <?php echo htmlspecialchars($s['end_time'] ?? 'TBA'); ?>
                            </span>
                            <?php if (!empty($s['room_code'])): ?>
                              <br><small class="text-muted"><i class="fas fa-door-open me-1"></i><?php echo htmlspecialchars($s['room_code'] . ' - ' . $s['building']); ?></small>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; endforeach; 
                
                if (!$hasSchedule):
                ?>
                  <div class="no-schedule-msg">
                    <i class="fas fa-calendar-times fa-2x mb-3 d-block"></i>
                    No schedule has been set for your registered courses yet.
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Courses without schedule -->
          <div class="col-lg-4">
            <div class="card mb-4">
              <div class="card-header bg-white">
                <strong><i class="fas fa-book me-2"></i>Registered Courses</strong>
              </div>
              <div class="list-group list-group-flush">
                <?php 
                $coursesShown = [];
                foreach ($rows as $row): 
                  if (!in_array($row['course_code'], $coursesShown)):
                    $coursesShown[] = $row['course_code'];
                ?>
                  <div class="list-group-item">
                    <strong><?php echo htmlspecialchars($row['course_code']); ?></strong>
                    <br><small class="text-muted"><?php echo htmlspecialchars($row['course_name'] ?? 'N/A'); ?></small>
                    <?php if (!empty($row['credits'])): ?>
                      <span class="badge bg-secondary float-end"><?php echo (int)$row['credits']; ?> Credit hours</span>
                    <?php endif; ?>
                  </div>
                <?php endif; endforeach; ?>
              </div>
            </div>

            <!-- Upcoming Exams -->
            <?php if (!empty($exams)): ?>
            <div class="card">
              <div class="card-header bg-white">
                <strong><i class="fas fa-file-alt me-2 text-danger"></i>Upcoming Exams</strong>
              </div>
              <div class="list-group list-group-flush">
                <?php foreach ($exams as $exam): ?>
                  <div class="list-group-item exam-card">
                    <strong><?php echo htmlspecialchars($exam['course_code']); ?></strong>
                    <span class="badge bg-danger float-end"><?php echo ucfirst($exam['exam_type']); ?></span>
                    <br><small class="text-muted"><?php echo htmlspecialchars($exam['course_name'] ?? ''); ?></small>
                    <br>
                    <i class="fas fa-calendar me-1"></i><?php echo date('D, M j, Y', strtotime($exam['exam_date'])); ?>
                    <br>
                    <i class="fas fa-clock me-1"></i><?php echo $exam['start_time']; ?> - <?php echo $exam['end_time']; ?>
                    <?php if (!empty($exam['room_code'])): ?>
                      <br><i class="fas fa-door-open me-1"></i><?php echo htmlspecialchars($exam['room_code']); ?>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    function exportTimetable() {
      // Simple CSV export
      const table = document.querySelector('.timetable-grid table') || document.querySelector('.list-group');
      if (!table) {
        alert('No timetable data to export');
        return;
      }
      window.print();
    }
  </script>
</body>
</html>
