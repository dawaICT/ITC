<?php
$page_title = 'Department Calendar';
require "includes/nav.php";
require_once __DIR__ . '/includes/hod_schema_helpers.php';
error_reporting(0);

$tableExists = function(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $res = @$db->query("SHOW TABLES LIKE '{$safe}'");
    if (!$res) {
        return false;
    }
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
};

$detectColumn = function(mysqli $db, string $table, array $candidates): ?string {
    foreach ($candidates as $col) {
        $safeTable = $db->real_escape_string($table);
        $safeCol = $db->real_escape_string($col);
        $res = @$db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
        if ($res && $res->num_rows > 0) {
            $res->free();
            return $col;
        }
        if ($res) {
            $res->free();
        }
    }
    return null;
};

$toYearInt = function($value, int $fallback): int {
    if (is_numeric($value)) {
        $num = (int)$value;
        if ($num >= 2000 && $num <= 2100) {
            return $num;
        }
    }
    if (is_string($value) && preg_match('/(20\d{2})/', $value, $m)) {
        $num = (int)$m[1];
        if ($num >= 2000 && $num <= 2100) {
            return $num;
        }
    }
    return $fallback;
};

$normalizeDay = function(string $day): ?string {
    $key = strtolower(trim($day));
    $map = [
        'mon' => 'monday',
        'monday' => 'monday',
        'tue' => 'tuesday',
        'tues' => 'tuesday',
        'tuesday' => 'tuesday',
        'wed' => 'wednesday',
        'wednesday' => 'wednesday',
        'thu' => 'thursday',
        'thur' => 'thursday',
        'thurs' => 'thursday',
        'thursday' => 'thursday',
        'fri' => 'friday',
        'friday' => 'friday',
        'sat' => 'saturday',
        'saturday' => 'saturday',
        'sun' => 'sunday',
        'sunday' => 'sunday',
    ];
    return $map[$key] ?? null;
};

$currentStaffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
if ($currentStaffId !== '' && !isset($_SESSION['staff_id'])) {
    $_SESSION['staff_id'] = $currentStaffId;
}

$staffDeptCol = $detectColumn($db, 'staff', ['deptId', 'DeptID', 'department_id']);
$deptIdRaw = '';
$deptName = '';

$deptContext = hod_resolve_department($db, $currentStaffId);
$deptIdRaw = (string)$deptContext['id'];
$deptName = (string)$deptContext['name'];

if ($deptIdRaw !== '' && $tableExists($db, 'departments')) {
    $deptNameCol = $detectColumn($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']);
    $deptCodeCol = $detectColumn($db, 'departments', ['deptId', 'department_code']);
    $deptNumCol = $detectColumn($db, 'departments', ['department_id', 'DeptID', 'id']);
    if ($deptNameCol) {
        if ($deptCodeCol) {
            $deptStmt = @$db->prepare("SELECT `{$deptNameCol}` AS dept_name FROM departments WHERE `{$deptCodeCol}` = ? LIMIT 1");
            if ($deptStmt) {
                $deptStmt->bind_param('s', $deptIdRaw);
                $deptStmt->execute();
                $deptRes = $deptStmt->get_result();
                if ($deptRes && ($deptRow = $deptRes->fetch_assoc())) {
                    $deptName = (string)($deptRow['dept_name'] ?? '');
                }
                $deptStmt->close();
            }
        }
        if ($deptName === '' && $deptNumCol) {
            $deptStmt = @$db->prepare("SELECT `{$deptNameCol}` AS dept_name FROM departments WHERE `{$deptNumCol}` = ? LIMIT 1");
            if ($deptStmt) {
                $deptStmt->bind_param('s', $deptIdRaw);
                $deptStmt->execute();
                $deptRes = $deptStmt->get_result();
                if ($deptRes && ($deptRow = $deptRes->fetch_assoc())) {
                    $deptName = (string)($deptRow['dept_name'] ?? '');
                }
                $deptStmt->close();
            }
        }
    }
}

// Course scope spans every department in the HOS's section.
$deptCourseCodes = hod_section_course_codes($db, $deptContext);

if ($currentStaffId !== '' && $tableExists($db, 'course_lecturer')) {
    $selfStmt = @$db->prepare("SELECT DISTINCT course_code FROM course_lecturer WHERE staff_id = ?");
    if ($selfStmt) {
        $selfStmt->bind_param('s', $currentStaffId);
        $selfStmt->execute();
        $selfRes = $selfStmt->get_result();
        while ($selfRes && ($row = $selfRes->fetch_assoc())) {
            $code = trim((string)($row['course_code'] ?? ''));
            if ($code !== '') {
                $deptCourseCodes[] = $code;
            }
        }
        $selfStmt->close();
    }
}

if (empty($deptCourseCodes) && $deptIdRaw !== '' && $tableExists($db, 'program_courses') && $tableExists($db, 'programs')) {
    $pcProgramCol = $detectColumn($db, 'program_courses', ['program_code', 'programId', 'program']);
    $pcCourseCol = $detectColumn($db, 'program_courses', ['course_code', 'courseId']);
    $progCodeCol = $detectColumn($db, 'programs', ['deptId', 'department_code', 'dept_code']);
    $progNumCol = $detectColumn($db, 'programs', ['department_id', 'DeptID', 'id']);
    if ($pcProgramCol && $pcCourseCol) {
        if ($progCodeCol) {
            $progStmt = @$db->prepare("
                SELECT DISTINCT pc.`{$pcCourseCol}` AS course_code
                FROM program_courses pc
                INNER JOIN programs p ON p.program_code = pc.`{$pcProgramCol}`
                WHERE p.`{$progCodeCol}` = ?
            ");
            if ($progStmt) {
                $progStmt->bind_param('s', $deptIdRaw);
                $progStmt->execute();
                $progRes = $progStmt->get_result();
                while ($progRes && ($row = $progRes->fetch_assoc())) {
                    $code = trim((string)($row['course_code'] ?? ''));
                    if ($code !== '') {
                        $deptCourseCodes[] = $code;
                    }
                }
                $progStmt->close();
            }
        }
        if (empty($deptCourseCodes) && $progNumCol) {
            $progStmt = @$db->prepare("
                SELECT DISTINCT pc.`{$pcCourseCol}` AS course_code
                FROM program_courses pc
                INNER JOIN programs p ON p.program_code = pc.`{$pcProgramCol}`
                WHERE p.`{$progNumCol}` = ?
            ");
            if ($progStmt) {
                $progStmt->bind_param('s', $deptIdRaw);
                $progStmt->execute();
                $progRes = $progStmt->get_result();
                while ($progRes && ($row = $progRes->fetch_assoc())) {
                    $code = trim((string)($row['course_code'] ?? ''));
                    if ($code !== '') {
                        $deptCourseCodes[] = $code;
                    }
                }
                $progStmt->close();
            }
        }
    }
}

$deptCourseCodes = array_values(array_unique(array_filter($deptCourseCodes)));

$defaultYear = (int)date('Y');
$defaultSemester = 1;

if ($tableExists($db, 'academic_periods')) {
    $periodYearCol = $detectColumn($db, 'academic_periods', ['academic_year', 'year']);
    $periodSemCol = $detectColumn($db, 'academic_periods', ['semester_term', 'semester']);
    $periodCurrentCol = $detectColumn($db, 'academic_periods', ['is_current']);
    $periodIdCol = $detectColumn($db, 'academic_periods', ['id']);
    if ($periodYearCol && $periodSemCol) {
        $periodSql = "SELECT `{$periodYearCol}` AS period_year, `{$periodSemCol}` AS period_sem FROM academic_periods";
        if ($periodCurrentCol) {
            $periodSql .= " WHERE `{$periodCurrentCol}` = 1";
        }
        if ($periodIdCol) {
            $periodSql .= " ORDER BY `{$periodIdCol}` DESC";
        }
        $periodSql .= " LIMIT 1";
        $periodRes = @$db->query($periodSql);
        if ($periodRes && ($periodRow = $periodRes->fetch_assoc())) {
            $defaultYear = $toYearInt($periodRow['period_year'] ?? null, $defaultYear);
            $semVal = (int)($periodRow['period_sem'] ?? 1);
            $defaultSemester = in_array($semVal, [1, 2], true) ? $semVal : 1;
        }
        if ($periodRes) {
            $periodRes->free();
        }
    }
}

$selectedYear = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
if (!$selectedYear || $selectedYear < 2000 || $selectedYear > 2100) {
    $selectedYear = $defaultYear;
}

$selectedSemester = filter_input(INPUT_GET, 'semester', FILTER_VALIDATE_INT);
if ($selectedSemester === false || $selectedSemester === null) {
    $selectedSemester = $defaultSemester;
}
if (!in_array($selectedSemester, [0, 1, 2], true)) {
    $selectedSemester = $defaultSemester;
}

$selectedMonth = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT);
if (!$selectedMonth || $selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = (int)date('n');
}

$selectedType = $_GET['type'] ?? 'all';
if (!in_array($selectedType, ['all', 'class', 'exam'], true)) {
    $selectedType = 'all';
}

$monthStart = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
$daysInSelectedMonth = (int)date('t', strtotime($monthStart));

$calendarEvents = [];
$notes = [];
$rawClassRows = [];
$rawExamRows = [];
$classSessionCount = 0;
$examEventCount = 0;

if ($currentStaffId === '') {
    $notes[] = 'Unable to identify the logged-in staff account.';
}
if ($deptIdRaw === '') {
    $notes[] = 'No department is linked to your staff account yet.';
}
if (empty($deptCourseCodes)) {
    $notes[] = 'No department courses were found to build a calendar view.';
}

if (!empty($deptCourseCodes) && $tableExists($db, 'course_schedule')) {
    $courseCodeCol = $detectColumn($db, 'course_schedule', ['course_code']);
    $dayCol = $detectColumn($db, 'course_schedule', ['day_of_week', 'day']);
    $yearCol = $detectColumn($db, 'course_schedule', ['academic_year', 'year']);
    $semesterCol = $detectColumn($db, 'course_schedule', ['semester', 'semester_term']);
    $isActiveCol = $detectColumn($db, 'course_schedule', ['is_active']);

    if (!$courseCodeCol || !$dayCol) {
        $notes[] = 'Course schedule table is missing required columns (course_code/day_of_week).';
    } else {
        $hasCoursesTable = $tableExists($db, 'courses');
        $timeSlotIdCol = $detectColumn($db, 'course_schedule', ['time_slot_id', 'slot_id']);
        $classroomIdCol = $detectColumn($db, 'course_schedule', ['classroom_id', 'room_id']);
        $roomTextCol = $detectColumn($db, 'course_schedule', ['room', 'room_code', 'venue']);
        $startDirectCol = $detectColumn($db, 'course_schedule', ['start_time']);
        $endDirectCol = $detectColumn($db, 'course_schedule', ['end_time']);
        $hasSlotsTable = $tableExists($db, 'time_slots') && $timeSlotIdCol;
        $hasRoomsTable = $tableExists($db, 'classrooms') && $classroomIdCol;
        $scheduleTypeCol = $detectColumn($db, 'course_schedule', ['schedule_type']);

        $placeholders = implode(',', array_fill(0, count($deptCourseCodes), '?'));
        $courseNameExpr = $hasCoursesTable ? "COALESCE(c.course_name, cs.`{$courseCodeCol}`) AS course_name" : "cs.`{$courseCodeCol}` AS course_name";
        $slotNameExpr = $hasSlotsTable ? "COALESCE(ts.slot_name, '') AS slot_name" : "'' AS slot_name";
        $startExpr = $hasSlotsTable ? "TIME_FORMAT(ts.start_time, '%H:%i') AS start_time" : ($startDirectCol ? "TIME_FORMAT(cs.`{$startDirectCol}`, '%H:%i') AS start_time" : "'' AS start_time");
        $endExpr = $hasSlotsTable ? "TIME_FORMAT(ts.end_time, '%H:%i') AS end_time" : ($endDirectCol ? "TIME_FORMAT(cs.`{$endDirectCol}`, '%H:%i') AS end_time" : "'' AS end_time");
        $roomCodeExpr = $hasRoomsTable ? "COALESCE(cl.room_code, 'TBA') AS room_code" : ($roomTextCol ? "COALESCE(cs.`{$roomTextCol}`, 'TBA') AS room_code" : "'TBA' AS room_code");
        $roomNameExpr = $hasRoomsTable ? "COALESCE(cl.room_name, '') AS room_name" : "'' AS room_name";
        $typeExpr = $scheduleTypeCol ? "COALESCE(cs.`{$scheduleTypeCol}`, 'lecture') AS schedule_type" : "'lecture' AS schedule_type";

        $classSql = "
            SELECT
                cs.id,
                cs.`{$courseCodeCol}` AS course_code,
                cs.`{$dayCol}` AS day_of_week,
                {$courseNameExpr},
                {$slotNameExpr},
                {$startExpr},
                {$endExpr},
                {$roomCodeExpr},
                {$roomNameExpr},
                {$typeExpr}
            FROM course_schedule cs
            " . ($hasCoursesTable ? "LEFT JOIN courses c ON c.course_code = cs.`{$courseCodeCol}`" : "") . "
            " . ($hasSlotsTable ? "LEFT JOIN time_slots ts ON ts.id = cs.`{$timeSlotIdCol}`" : "") . "
            " . ($hasRoomsTable ? "LEFT JOIN classrooms cl ON cl.id = cs.`{$classroomIdCol}`" : "") . "
            WHERE cs.`{$courseCodeCol}` IN ({$placeholders})
        ";

        $bindTypes = str_repeat('s', count($deptCourseCodes));
        $bindValues = $deptCourseCodes;

        if ($yearCol) {
            $classSql .= " AND cs.`{$yearCol}` = ?";
            $bindTypes .= 'i';
            $bindValues[] = $selectedYear;
        }
        if ($semesterCol && $selectedSemester > 0) {
            $classSql .= " AND cs.`{$semesterCol}` = ?";
            $bindTypes .= 'i';
            $bindValues[] = $selectedSemester;
        }
        if ($isActiveCol) {
            $classSql .= " AND cs.`{$isActiveCol}` = 1";
        }

        $classSql .= " ORDER BY FIELD(cs.`{$dayCol}`, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')";
        if ($hasSlotsTable) {
            $classSql .= ", ts.start_time";
        }

        $classStmt = @$db->prepare($classSql);
        if ($classStmt) {
            $classStmt->bind_param($bindTypes, ...$bindValues);
            $classStmt->execute();
            $classRes = $classStmt->get_result();
            while ($classRes && ($row = $classRes->fetch_assoc())) {
                $rawClassRows[] = $row;
            }
            $classStmt->close();
        }
    }
} elseif (!empty($deptCourseCodes)) {
    $notes[] = 'Course timetable table (course_schedule) is not available in this database.';
}

if (!empty($deptCourseCodes) && $tableExists($db, 'exam_schedule')) {
    $examCourseCol = $detectColumn($db, 'exam_schedule', ['course_code']);
    $examDateCol = $detectColumn($db, 'exam_schedule', ['exam_date', 'date']);
    $examTypeCol = $detectColumn($db, 'exam_schedule', ['exam_type', 'type']);
    $examStartCol = $detectColumn($db, 'exam_schedule', ['start_time']);
    $examEndCol = $detectColumn($db, 'exam_schedule', ['end_time']);
    $examSemesterCol = $detectColumn($db, 'exam_schedule', ['semester', 'semester_term']);
    $examStatusCol = $detectColumn($db, 'exam_schedule', ['status']);
    $examRoomIdCol = $detectColumn($db, 'exam_schedule', ['classroom_id']);

    if (!$examCourseCol || !$examDateCol) {
        $notes[] = 'Exam schedule table is missing required columns (course_code/exam_date).';
    } else {
        $hasCoursesTable = $tableExists($db, 'courses');
        $hasRoomsTable = $tableExists($db, 'classrooms');
        $placeholders = implode(',', array_fill(0, count($deptCourseCodes), '?'));

        $examCourseExpr = $hasCoursesTable ? "COALESCE(c.course_name, es.`{$examCourseCol}`) AS course_name" : "es.`{$examCourseCol}` AS course_name";
        $examTypeExpr = $examTypeCol ? "COALESCE(es.`{$examTypeCol}`, 'exam') AS exam_type" : "'exam' AS exam_type";
        $examStartExpr = $examStartCol ? "TIME_FORMAT(es.`{$examStartCol}`, '%H:%i') AS start_time" : "'' AS start_time";
        $examEndExpr = $examEndCol ? "TIME_FORMAT(es.`{$examEndCol}`, '%H:%i') AS end_time" : "'' AS end_time";
        $examStatusExpr = $examStatusCol ? "COALESCE(es.`{$examStatusCol}`, '') AS exam_status" : "'' AS exam_status";
        $examRoomCodeExpr = ($hasRoomsTable && $examRoomIdCol) ? "COALESCE(cl.room_code, 'TBA') AS room_code" : "'TBA' AS room_code";
        $examRoomNameExpr = ($hasRoomsTable && $examRoomIdCol) ? "COALESCE(cl.room_name, '') AS room_name" : "'' AS room_name";

        $examSql = "
            SELECT
                es.id,
                es.`{$examCourseCol}` AS course_code,
                DATE(es.`{$examDateCol}`) AS exam_date,
                {$examCourseExpr},
                {$examTypeExpr},
                {$examStartExpr},
                {$examEndExpr},
                {$examStatusExpr},
                {$examRoomCodeExpr},
                {$examRoomNameExpr}
            FROM exam_schedule es
            " . ($hasCoursesTable ? "LEFT JOIN courses c ON c.course_code COLLATE utf8mb4_general_ci = es.`{$examCourseCol}` COLLATE utf8mb4_general_ci" : "") . "
            " . (($hasRoomsTable && $examRoomIdCol) ? "LEFT JOIN classrooms cl ON cl.id = es.`{$examRoomIdCol}`" : "") . "
            WHERE es.`{$examCourseCol}` IN ({$placeholders})
              AND YEAR(es.`{$examDateCol}`) = ?
              AND MONTH(es.`{$examDateCol}`) = ?
        ";

        $bindTypes = str_repeat('s', count($deptCourseCodes)) . 'ii';
        $bindValues = array_merge($deptCourseCodes, [$selectedYear, $selectedMonth]);

        if ($examSemesterCol && $selectedSemester > 0) {
            $examSql .= " AND es.`{$examSemesterCol}` = ?";
            $bindTypes .= 'i';
            $bindValues[] = $selectedSemester;
        }

        $examSql .= " ORDER BY es.`{$examDateCol}`";
        if ($examStartCol) {
            $examSql .= ", es.`{$examStartCol}`";
        }

        $examStmt = @$db->prepare($examSql);
        if ($examStmt) {
            $examStmt->bind_param($bindTypes, ...$bindValues);
            $examStmt->execute();
            $examRes = $examStmt->get_result();
            while ($examRes && ($row = $examRes->fetch_assoc())) {
                $rawExamRows[] = $row;
            }
            $examStmt->close();
        }
    }
}

if ($selectedType !== 'exam') {
    foreach ($rawClassRows as $row) {
        $normalizedDay = $normalizeDay((string)($row['day_of_week'] ?? ''));
        if (!$normalizedDay) {
            continue;
        }
        for ($d = 1; $d <= $daysInSelectedMonth; $d++) {
            $date = sprintf('%04d-%02d-%02d', $selectedYear, $selectedMonth, $d);
            if (strtolower(date('l', strtotime($date))) !== $normalizedDay) {
                continue;
            }
            $startTime = trim((string)($row['start_time'] ?? ''));
            $endTime = trim((string)($row['end_time'] ?? ''));
            $slotName = trim((string)($row['slot_name'] ?? ''));
            $roomCode = trim((string)($row['room_code'] ?? 'TBA'));
            $roomName = trim((string)($row['room_name'] ?? ''));
            $typeLabel = ucfirst((string)($row['schedule_type'] ?? 'Lecture'));
            $timeLabel = ($startTime !== '' && $endTime !== '') ? "{$startTime} - {$endTime}" : ($slotName !== '' ? $slotName : 'Time TBA');
            $locationLabel = $roomCode;
            if ($roomName !== '') {
                $locationLabel .= " ({$roomName})";
            }
            $calendarEvents[] = [
                'id' => 'class-' . (int)$row['id'] . '-' . $date,
                'type' => 'class',
                'course_code' => (string)$row['course_code'],
                'title' => (string)$row['course_code'],
                'subtitle' => (string)$row['course_name'],
                'date' => $date,
                'time' => $timeLabel,
                'location' => $locationLabel,
                'meta' => $typeLabel,
            ];
            $classSessionCount++;
        }
    }
}

if ($selectedType !== 'class') {
    foreach ($rawExamRows as $row) {
        $date = (string)($row['exam_date'] ?? '');
        if ($date === '' || strpos($date, sprintf('%04d-%02d-', $selectedYear, $selectedMonth)) !== 0) {
            continue;
        }
        $startTime = trim((string)($row['start_time'] ?? ''));
        $endTime = trim((string)($row['end_time'] ?? ''));
        $roomCode = trim((string)($row['room_code'] ?? 'TBA'));
        $roomName = trim((string)($row['room_name'] ?? ''));
        $examType = ucfirst((string)($row['exam_type'] ?? 'Exam'));
        $examStatus = trim((string)($row['exam_status'] ?? ''));
        $timeLabel = ($startTime !== '' && $endTime !== '') ? "{$startTime} - {$endTime}" : 'Time TBA';
        $locationLabel = $roomCode;
        if ($roomName !== '') {
            $locationLabel .= " ({$roomName})";
        }
        $metaLabel = $examType;
        if ($examStatus !== '') {
            $metaLabel .= " - " . ucfirst($examStatus);
        }
        $calendarEvents[] = [
            'id' => 'exam-' . (int)$row['id'],
            'type' => 'exam',
            'course_code' => (string)$row['course_code'],
            'title' => (string)$row['course_code'],
            'subtitle' => (string)$row['course_name'],
            'date' => $date,
            'time' => $timeLabel,
            'location' => $locationLabel,
            'meta' => $metaLabel,
        ];
        $examEventCount++;
    }
}

usort($calendarEvents, static function(array $a, array $b): int {
    $akey = $a['date'] . ' ' . $a['time'];
    $bkey = $b['date'] . ' ' . $b['time'];
    return strcmp($akey, $bkey);
});

$eventsByDate = [];
foreach ($calendarEvents as $event) {
    $date = $event['date'];
    if (!isset($eventsByDate[$date])) {
        $eventsByDate[$date] = [];
    }
    $eventsByDate[$date][] = $event;
}

$today = date('Y-m-d');
$defaultSelectedDate = (date('Y', strtotime($today)) == $selectedYear && date('n', strtotime($today)) == $selectedMonth)
    ? $today
    : sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
$selectedDate = $_GET['date'] ?? $defaultSelectedDate;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = $defaultSelectedDate;
}
if (strpos($selectedDate, sprintf('%04d-%02d-', $selectedYear, $selectedMonth)) !== 0) {
    $selectedDate = $defaultSelectedDate;
}

$firstDayWeekIndex = (int)date('N', strtotime($monthStart)); // 1 (Mon) to 7 (Sun)
$calendarCells = [];
for ($i = 1; $i < $firstDayWeekIndex; $i++) {
    $calendarCells[] = null;
}
for ($d = 1; $d <= $daysInSelectedMonth; $d++) {
    $calendarCells[] = sprintf('%04d-%02d-%02d', $selectedYear, $selectedMonth, $d);
}
while (count($calendarCells) % 7 !== 0) {
    $calendarCells[] = null;
}

$monthLabel = date('F Y', strtotime($monthStart));
$prevMonthTs = strtotime($monthStart . ' -1 month');
$nextMonthTs = strtotime($monthStart . ' +1 month');

$buildQuery = static function(int $year, int $month, int $semester, string $type): string {
    return http_build_query([
        'year' => $year,
        'month' => $month,
        'semester' => $semester,
        'type' => $type,
    ]);
};

$upcomingEvents = array_values(array_filter($calendarEvents, static function(array $event) use ($today): bool {
    return $event['date'] >= $today;
}));
if (empty($upcomingEvents)) {
    $upcomingEvents = $calendarEvents;
}
$upcomingEvents = array_slice($upcomingEvents, 0, 8);

$eventPayload = json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>

<div class="container-fluid px-4 portal-dashboard hod-page calendar-page">
    <div class="page-header mt-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1 class="page-title mb-1"><i class="fas fa-calendar-alt me-2"></i>Department Calendar</h1>
                <p class="page-subtitle mb-0">
                    <?php echo htmlspecialchars($deptName !== '' ? $deptName : ($deptIdRaw !== '' ? $deptIdRaw : 'Department not assigned')); ?>
                    timetable and exam schedule overview.
                </p>
            </div>
            <a href="index.php" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
        </div>
    </div>

    <?php foreach ($notes as $notice): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="fas fa-exclamation-circle mt-1"></i>
            <div><?php echo htmlspecialchars($notice); ?></div>
        </div>
    <?php endforeach; ?>

    <div class="data-table-card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-sliders-h me-2 text-hod-primary"></i>Calendar Filters</h5>
        </div>
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="year" class="form-label fw-semibold">Year</label>
                    <select class="form-select" id="year" name="year">
                        <?php for ($y = $selectedYear - 1; $y <= $selectedYear + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $selectedYear ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="month" class="form-label fw-semibold">Month</label>
                    <select class="form-select" id="month" name="month">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m === $selectedMonth ? 'selected' : ''; ?>>
                                <?php echo date('F', strtotime(sprintf('2000-%02d-01', $m))); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="semester" class="form-label fw-semibold">Semester</label>
                    <select class="form-select" id="semester" name="semester">
                        <option value="0" <?php echo $selectedSemester === 0 ? 'selected' : ''; ?>>All Semesters</option>
                        <option value="1" <?php echo $selectedSemester === 1 ? 'selected' : ''; ?>>Semester 1</option>
                        <option value="2" <?php echo $selectedSemester === 2 ? 'selected' : ''; ?>>Semester 2</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="type" class="form-label fw-semibold">Event Type</label>
                    <select class="form-select" id="type" name="type">
                        <option value="all" <?php echo $selectedType === 'all' ? 'selected' : ''; ?>>All Events</option>
                        <option value="class" <?php echo $selectedType === 'class' ? 'selected' : ''; ?>>Classes Only</option>
                        <option value="exam" <?php echo $selectedType === 'exam' ? 'selected' : ''; ?>>Exams Only</option>
                    </select>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-2"></i>Apply Filters</button>
                    <a href="calendar.php" class="btn btn-outline-secondary"><i class="fas fa-undo me-2"></i>Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-calendar me-3"><i class="fas fa-calendar-day text-white"></i></div>
                    <div>
                        <div class="stat-value"><?php echo count($calendarEvents); ?></div>
                        <div class="stat-label">Total Events</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-class me-3"><i class="fas fa-chalkboard text-white"></i></div>
                    <div>
                        <div class="stat-value"><?php echo $classSessionCount; ?></div>
                        <div class="stat-label">Class Sessions</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-exam me-3"><i class="fas fa-file-signature text-white"></i></div>
                    <div>
                        <div class="stat-value"><?php echo $examEventCount; ?></div>
                        <div class="stat-label">Exam Events</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon stat-icon-course me-3"><i class="fas fa-book text-white"></i></div>
                    <div>
                        <div class="stat-value"><?php echo count($deptCourseCodes); ?></div>
                        <div class="stat-label">Dept Courses</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <a class="btn btn-sm btn-outline-primary" href="?<?php echo $buildQuery((int)date('Y', $prevMonthTs), (int)date('n', $prevMonthTs), $selectedSemester, $selectedType); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <h5 class="mb-0"><?php echo htmlspecialchars($monthLabel); ?></h5>
                            <a class="btn btn-sm btn-outline-primary" href="?<?php echo $buildQuery((int)date('Y', $nextMonthTs), (int)date('n', $nextMonthTs), $selectedSemester, $selectedType); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                        <div class="calendar-legend">
                            <span class="legend-item"><span class="legend-dot legend-class"></span>Class</span>
                            <span class="legend-item"><span class="legend-dot legend-exam"></span>Exam</span>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="hod-calendar-shell">
                        <div class="hod-calendar-weekdays">
                            <div>Mon</div>
                            <div>Tue</div>
                            <div>Wed</div>
                            <div>Thu</div>
                            <div>Fri</div>
                            <div>Sat</div>
                            <div>Sun</div>
                        </div>
                        <div class="hod-calendar-grid">
                            <?php foreach ($calendarCells as $cellDate): ?>
                                <?php if ($cellDate === null): ?>
                                    <div class="hod-day-cell is-empty" aria-hidden="true"></div>
                                <?php else: ?>
                                    <?php
                                    $dayEvents = $eventsByDate[$cellDate] ?? [];
                                    $isToday = $cellDate === $today;
                                    $isSelected = $cellDate === $selectedDate;
                                    ?>
                                    <button type="button"
                                            class="hod-day-cell js-hod-day <?php echo $isToday ? 'is-today' : ''; ?> <?php echo $isSelected ? 'is-selected' : ''; ?>"
                                            data-date="<?php echo htmlspecialchars($cellDate); ?>">
                                        <span class="hod-day-number"><?php echo (int)substr($cellDate, -2); ?></span>
                                        <div class="hod-day-events">
                                            <?php foreach (array_slice($dayEvents, 0, 3) as $event): ?>
                                                <span class="hod-event-chip <?php echo $event['type'] === 'exam' ? 'event-exam' : 'event-class'; ?>">
                                                    <?php echo htmlspecialchars($event['title']); ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if (count($dayEvents) > 3): ?>
                                                <span class="hod-event-more">+<?php echo count($dayEvents) - 3; ?> more</span>
                                            <?php endif; ?>
                                        </div>
                                    </button>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="data-table-card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-calendar-day me-2 text-hod-primary"></i><span id="dayEventsHeading">Day Details</span></h5>
                </div>
                <div class="card-body">
                    <div id="dayEventsEmpty" class="calendar-empty-state d-none">
                        <i class="fas fa-calendar-times fa-2x mb-2"></i>
                        <p class="mb-0">No events on this day.</p>
                    </div>
                    <div id="dayEventsList" class="day-events-list"></div>
                </div>
            </div>

            <div class="data-table-card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-clock me-2 text-hod-primary"></i>Upcoming Events</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($upcomingEvents)): ?>
                        <div class="calendar-empty-state">
                            <i class="fas fa-info-circle fa-2x mb-2"></i>
                            <p class="mb-0">No upcoming events found.</p>
                        </div>
                    <?php else: ?>
                        <div class="upcoming-events-list">
                            <?php foreach ($upcomingEvents as $event): ?>
                                <div class="upcoming-item <?php echo $event['type'] === 'exam' ? 'upcoming-exam' : 'upcoming-class'; ?>">
                                    <div class="upcoming-top">
                                        <span class="upcoming-code"><?php echo htmlspecialchars($event['title']); ?></span>
                                        <span class="upcoming-date"><?php echo date('d M', strtotime($event['date'])); ?></span>
                                    </div>
                                    <div class="upcoming-sub"><?php echo htmlspecialchars($event['subtitle']); ?></div>
                                    <div class="upcoming-meta">
                                        <span><i class="fas fa-clock me-1"></i><?php echo htmlspecialchars($event['time']); ?></span>
                                        <span><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($event['location']); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const events = <?php echo $eventPayload ?: '[]'; ?>;
    const eventsByDate = {};
    events.forEach(function (event) {
        if (!eventsByDate[event.date]) {
            eventsByDate[event.date] = [];
        }
        eventsByDate[event.date].push(event);
    });

    const dayButtons = document.querySelectorAll('.js-hod-day');
    const heading = document.getElementById('dayEventsHeading');
    const list = document.getElementById('dayEventsList');
    const empty = document.getElementById('dayEventsEmpty');
    const initialDate = <?php echo json_encode($selectedDate); ?>;

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function formatDateLabel(ymd) {
        const date = new Date(ymd + 'T00:00:00');
        if (Number.isNaN(date.getTime())) {
            return ymd;
        }
        return date.toLocaleDateString(undefined, {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        });
    }

    function renderDay(date) {
        heading.textContent = formatDateLabel(date);
        const dayEvents = (eventsByDate[date] || []).slice().sort(function (a, b) {
            return String(a.time).localeCompare(String(b.time));
        });

        if (!dayEvents.length) {
            list.innerHTML = '';
            empty.classList.remove('d-none');
            return;
        }

        empty.classList.add('d-none');
        list.innerHTML = dayEvents.map(function (event) {
            const eventClass = event.type === 'exam' ? 'day-event exam' : 'day-event class';
            const icon = event.type === 'exam' ? 'fa-file-signature' : 'fa-chalkboard-teacher';
            return (
                '<article class="' + eventClass + '">' +
                    '<div class="day-event-top">' +
                        '<span class="event-badge ' + (event.type === 'exam' ? 'event-exam' : 'event-class') + '">' + escapeHtml(event.type.toUpperCase()) + '</span>' +
                        '<span class="event-course">' + escapeHtml(event.title) + '</span>' +
                    '</div>' +
                    '<div class="day-event-subtitle">' + escapeHtml(event.subtitle) + '</div>' +
                    '<div class="day-event-meta"><i class="fas ' + icon + ' me-1"></i>' + escapeHtml(event.meta) + '</div>' +
                    '<div class="day-event-meta"><i class="fas fa-clock me-1"></i>' + escapeHtml(event.time) + '</div>' +
                    '<div class="day-event-meta"><i class="fas fa-map-marker-alt me-1"></i>' + escapeHtml(event.location) + '</div>' +
                '</article>'
            );
        }).join('');
    }

    function setSelectedButton(date) {
        dayButtons.forEach(function (button) {
            button.classList.toggle('is-selected', button.getAttribute('data-date') === date);
        });
    }

    function updateUrlDate(date) {
        const url = new URL(window.location.href);
        url.searchParams.set('date', date);
        window.history.replaceState({}, '', url.toString());
    }

    dayButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const date = button.getAttribute('data-date');
            if (!date) {
                return;
            }
            setSelectedButton(date);
            renderDay(date);
            updateUrlDate(date);
        });
    });

    const initialButton = document.querySelector('.js-hod-day[data-date="' + initialDate + '"]');
    if (initialButton) {
        setSelectedButton(initialDate);
        renderDay(initialDate);
    } else {
        const firstButton = document.querySelector('.js-hod-day');
        if (firstButton) {
            const firstDate = firstButton.getAttribute('data-date');
            setSelectedButton(firstDate);
            renderDay(firstDate);
            updateUrlDate(firstDate);
        }
    }
})();
</script>

<?php require "includes/footer.php"; ?>
