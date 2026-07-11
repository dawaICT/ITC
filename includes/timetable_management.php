<?php

if (!function_exists('ttm_h')) {
    function ttm_h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ttm_table_exists')) {
    function ttm_table_exists(mysqli $db, string $table): bool {
        $safe = $db->real_escape_string($table);
        $res = @$db->query("SHOW TABLES LIKE '{$safe}'");
        if (!$res) {
            return false;
        }
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
}

if (!function_exists('ttm_columns')) {
    function ttm_columns(mysqli $db, string $table): array {
        $cols = [];
        $safe = str_replace('`', '', $table);
        $res = @$db->query("SHOW COLUMNS FROM `{$safe}`");
        if (!$res) {
            return $cols;
        }
        while ($row = $res->fetch_assoc()) {
            $cols[(string)$row['Field']] = true;
        }
        $res->free();
        return $cols;
    }
}

if (!function_exists('ttm_column_definitions')) {
    function ttm_column_definitions(mysqli $db, string $table): array {
        $cols = [];
        $safe = str_replace('`', '', $table);
        $res = @$db->query("SHOW COLUMNS FROM `{$safe}`");
        if (!$res) {
            return $cols;
        }
        while ($row = $res->fetch_assoc()) {
            $cols[(string)$row['Field']] = $row;
        }
        $res->free();
        return $cols;
    }
}

if (!function_exists('ttm_is_integer_column')) {
    function ttm_is_integer_column(mysqli $db, string $table, string $column): bool {
        $defs = ttm_column_definitions($db, $table);
        $type = strtolower((string)($defs[$column]['Type'] ?? ''));
        return preg_match('/\b(tinyint|smallint|mediumint|int|bigint)\b/', $type) === 1;
    }
}

if (!function_exists('ttm_ensure_schema')) {
    function ttm_ensure_schema(mysqli $db): void {
        $tableExists = ttm_table_exists($db, 'course_schedule');
        try {
            if (!$tableExists) {
                $db->query("CREATE TABLE IF NOT EXISTS course_schedule (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    course_code VARCHAR(64) NOT NULL,
                    lecturer_id VARCHAR(64) NULL,
                    schedule_type VARCHAR(40) NOT NULL DEFAULT 'lecture',
                    title VARCHAR(160) NULL,
                    day VARCHAR(20) NULL,
                    day_of_week VARCHAR(20) NULL,
                    start_time TIME NULL,
                    end_time TIME NULL,
                    room VARCHAR(80) NULL,
                    semester TINYINT UNSIGNED NULL,
                    `Year` SMALLINT UNSIGNED NULL,
                    status VARCHAR(40) NOT NULL DEFAULT 'active',
                    created_by VARCHAR(64) NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_course_schedule_course (course_code),
                    INDEX idx_course_schedule_lecturer (lecturer_id),
                    INDEX idx_course_schedule_period (`Year`, semester),
                    INDEX idx_course_schedule_day (day_of_week)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }

            $cols = ttm_columns($db, 'course_schedule');
            $additions = [
                'schedule_type' => "ALTER TABLE course_schedule ADD COLUMN schedule_type VARCHAR(40) NOT NULL DEFAULT 'lecture' AFTER lecturer_id",
                'title' => "ALTER TABLE course_schedule ADD COLUMN title VARCHAR(160) NULL AFTER schedule_type",
                'day' => "ALTER TABLE course_schedule ADD COLUMN day VARCHAR(20) NULL AFTER title",
                'day_of_week' => "ALTER TABLE course_schedule ADD COLUMN day_of_week VARCHAR(20) NULL AFTER day",
                'start_time' => "ALTER TABLE course_schedule ADD COLUMN start_time TIME NULL AFTER day_of_week",
                'end_time' => "ALTER TABLE course_schedule ADD COLUMN end_time TIME NULL AFTER start_time",
                'room' => "ALTER TABLE course_schedule ADD COLUMN room VARCHAR(80) NULL AFTER end_time",
                'semester' => "ALTER TABLE course_schedule ADD COLUMN semester TINYINT UNSIGNED NULL AFTER room",
                'Year' => "ALTER TABLE course_schedule ADD COLUMN `Year` SMALLINT UNSIGNED NULL AFTER semester",
                'status' => "ALTER TABLE course_schedule ADD COLUMN status VARCHAR(40) NOT NULL DEFAULT 'active' AFTER `Year`",
                'created_by' => "ALTER TABLE course_schedule ADD COLUMN created_by VARCHAR(64) NULL AFTER status",
                'updated_at' => "ALTER TABLE course_schedule ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
            ];
            foreach ($additions as $column => $sql) {
                if (!isset($cols[$column])) {
                    try {
                        $db->query($sql);
                    } catch (Throwable $e) {
                        error_log('Failed to add course_schedule.' . $column . ': ' . $e->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Failed to ensure schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ttm_days')) {
    function ttm_days(): array {
        return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    }
}

if (!function_exists('ttm_schedule_types')) {
    function ttm_schedule_types(): array {
        return ['lecture' => 'Lecture', 'tutorial' => 'Tutorial', 'lab' => 'Lab', 'seminar' => 'Seminar', 'workshop' => 'Workshop'];
    }
}

if (!function_exists('ttm_courses')) {
    function ttm_courses(mysqli $db, ?array $allowedCourseCodes = null): array {
        $allowed = null;
        if (is_array($allowedCourseCodes)) {
            $allowed = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $allowedCourseCodes)))));
            if (empty($allowed)) {
                return [];
            }
        }

        $courses = [];
        $sql = "SELECT course_code, course_name, 'academic' AS course_type FROM courses WHERE status = 'active'";
        $params = [];
        $types = '';
        if ($allowed !== null) {
            $sql .= " AND UPPER(course_code) IN (" . implode(',', array_fill(0, count($allowed), '?')) . ")";
            $params = $allowed;
            $types = str_repeat('s', count($params));
        }

        $stmt = $db->prepare($sql);
        if ($stmt) {
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $courses[] = $row;
            }
            $stmt->close();
        }

        if (ttm_table_exists($db, 'short_courses')) {
            $shortSql = "SELECT course_code, course_name, 'short_course' AS course_type
                         FROM short_courses
                         WHERE status IN ('active', 'upcoming')";
            $shortParams = [];
            $shortTypes = '';
            if ($allowed !== null) {
                $shortSql .= " AND UPPER(course_code) IN (" . implode(',', array_fill(0, count($allowed), '?')) . ")";
                $shortParams = $allowed;
                $shortTypes = str_repeat('s', count($shortParams));
            }
            $shortSql .= " ORDER BY course_code";
            $shortStmt = $db->prepare($shortSql);
            if ($shortStmt) {
                if ($shortTypes !== '') {
                    $shortStmt->bind_param($shortTypes, ...$shortParams);
                }
                $shortStmt->execute();
                $shortRes = $shortStmt->get_result();
                while ($shortRes && ($row = $shortRes->fetch_assoc())) {
                    $courses[] = $row;
                }
                $shortStmt->close();
            }
        }

        usort($courses, static function(array $a, array $b): int {
            return strcmp((string)$a['course_code'], (string)$b['course_code']);
        });

        return $courses;
    }
}

if (!function_exists('ttm_lecturers')) {
    function ttm_lecturers(mysqli $db, ?array $allowedCourseCodes = null): array {
        $allowed = null;
        if (is_array($allowedCourseCodes)) {
            $allowed = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $allowedCourseCodes)))));
            if (empty($allowed)) {
                return [];
            }
        }

        $params = [];
        $types = '';
        $join = '';
        $where = '';
        if ($allowed !== null && ttm_table_exists($db, 'course_lecturer')) {
            $join = " INNER JOIN course_lecturer cl ON cl.staff_id = s.staff_id";
            $where = " WHERE UPPER(cl.course_code) IN (" . implode(',', array_fill(0, count($allowed), '?')) . ")";
            $params = $allowed;
            $types = str_repeat('s', count($params));
        }

        $sql = "SELECT DISTINCT s.staff_id, s.title, s.Fname, s.Lname
                FROM staff s{$join}{$where}
                ORDER BY s.Lname, s.Fname, s.staff_id";
        $lecturers = [];
        $stmt = $db->prepare($sql);
        if ($stmt) {
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $lecturers[] = $row;
            }
            $stmt->close();
        }
        return $lecturers;
    }
}

if (!function_exists('ttm_time_slots')) {
    function ttm_time_slots(mysqli $db): array {
        if (ttm_table_exists($db, 'time_slots')) {
            $slots = [];
            $res = @$db->query("SELECT slot_name, start_time, end_time,
                                       CASE WHEN LOWER(slot_name) LIKE '%break%' THEN 'break' ELSE 'class' END AS type
                                FROM time_slots
                                WHERE COALESCE(is_active, 1) = 1
                                ORDER BY start_time, id");
            while ($res && ($row = $res->fetch_assoc())) {
                $slots[] = $row;
            }
            if ($res) {
                $res->free();
            }
            if (!empty($slots)) {
                return $slots;
            }
        }
        return [
            ['slot_name' => 'First Class', 'start_time' => '08:30:00', 'end_time' => '10:30:00', 'type' => 'class'],
            ['slot_name' => 'Break', 'start_time' => '10:30:00', 'end_time' => '11:00:00', 'type' => 'break'],
            ['slot_name' => 'Second Class', 'start_time' => '11:00:00', 'end_time' => '13:00:00', 'type' => 'class'],
            ['slot_name' => 'Lunch', 'start_time' => '13:00:00', 'end_time' => '14:00:00', 'type' => 'break'],
            ['slot_name' => 'Last Class', 'start_time' => '14:00:00', 'end_time' => '16:00:00', 'type' => 'class'],
        ];
    }
}

if (!function_exists('ttm_room_options')) {
    function ttm_room_options(mysqli $db): array {
        $rooms = [];
        if (!ttm_table_exists($db, 'classrooms')) {
            return $rooms;
        }
        $res = @$db->query("SELECT room_code, room_name, building FROM classrooms WHERE COALESCE(status, 'available') = 'available' ORDER BY room_code");
        while ($res && ($row = $res->fetch_assoc())) {
            $label = trim((string)$row['room_code']);
            if (!empty($row['room_name'])) {
                $label .= ' - ' . trim((string)$row['room_name']);
            }
            $rooms[] = ['value' => (string)$row['room_code'], 'label' => $label];
        }
        if ($res) {
            $res->free();
        }
        return $rooms;
    }
}

if (!function_exists('ttm_resolve_lecturer_storage_value')) {
    function ttm_resolve_lecturer_storage_value(mysqli $db, string $lecturerId) {
        if ($lecturerId === '') {
            return null;
        }
        if (!ttm_is_integer_column($db, 'course_schedule', 'lecturer_id')) {
            return $lecturerId;
        }
        $stmt = $db->prepare("SELECT id FROM staff WHERE staff_id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $lecturerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    }
}

if (!function_exists('ttm_resolve_created_by_storage_value')) {
    function ttm_resolve_created_by_storage_value(mysqli $db, string $createdBy) {
        if (!ttm_is_integer_column($db, 'course_schedule', 'created_by')) {
            return $createdBy;
        }
        $stmt = $db->prepare("SELECT id FROM staff WHERE staff_id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $createdBy);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    }
}

if (!function_exists('ttm_resolve_time_slot_id')) {
    function ttm_resolve_time_slot_id(mysqli $db, string $startTime, string $endTime): ?int {
        if (!ttm_table_exists($db, 'time_slots')) {
            return null;
        }
        $stmt = $db->prepare("SELECT id FROM time_slots WHERE start_time = ? AND end_time = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $start = strlen($startTime) === 5 ? $startTime . ':00' : $startTime;
        $end = strlen($endTime) === 5 ? $endTime . ':00' : $endTime;
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    }
}

if (!function_exists('ttm_resolve_classroom_id')) {
    function ttm_resolve_classroom_id(mysqli $db, string $room): ?int {
        if ($room === '' || !ttm_table_exists($db, 'classrooms')) {
            return null;
        }
        $stmt = $db->prepare("SELECT id FROM classrooms WHERE room_code = ? OR room_name = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $room, $room);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    }
}

if (!function_exists('ttm_fetch_schedules')) {
    function ttm_fetch_schedules(mysqli $db, int $yearOfStudy, int $semester, ?array $allowedCourseCodes = null, string $lecturerId = ''): array {
        ttm_ensure_schema($db);

        $cols = ttm_columns($db, 'course_schedule');
        $hasTimeSlotId = isset($cols['time_slot_id']);
        $hasClassroomId = isset($cols['classroom_id']);
        
        $yearCol = isset($cols['academic_year']) ? 'academic_year' : (isset($cols['Year']) ? 'Year' : '');
        $statusFilter = isset($cols['is_active']) ? "COALESCE(cs.is_active, 1) = 1" : (isset($cols['status']) ? "COALESCE(cs.status, 'active') = 'active'" : "1=1");
        
        $titleExpr = isset($cols['title']) ? "cs.title" : (isset($cols['notes']) ? "cs.notes AS title" : "'' AS title");
        
        if (isset($cols['day_of_week']) && isset($cols['day'])) {
            $dayExpr = "COALESCE(NULLIF(cs.day_of_week, ''), cs.day)";
        } elseif (isset($cols['day_of_week'])) {
            $dayExpr = "cs.day_of_week";
        } elseif (isset($cols['day'])) {
            $dayExpr = "cs.day";
        } else {
            $dayExpr = "'Monday'";
        }
        
        $joins = [];
        
        if ($hasTimeSlotId) {
            $startTimeExpr = "TIME_FORMAT(ts.start_time, '%H:%i')";
            $endTimeExpr = "TIME_FORMAT(ts.end_time, '%H:%i')";
            $joins[] = "LEFT JOIN time_slots ts ON ts.id = cs.time_slot_id";
        } else {
            $startTimeExpr = isset($cols['start_time']) ? "TIME_FORMAT(cs.start_time, '%H:%i')" : "''";
            $endTimeExpr = isset($cols['end_time']) ? "TIME_FORMAT(cs.end_time, '%H:%i')" : "''";
        }
        
        if ($hasClassroomId) {
            $roomExpr = "cl.room_code";
            $joins[] = "LEFT JOIN classrooms cl ON cl.id = cs.classroom_id";
        } else {
            $roomExpr = isset($cols['room']) ? "cs.room" : "''";
        }
        
        $joins[] = "LEFT JOIN staff s ON (s.staff_id = cs.lecturer_id OR s.id = cs.lecturer_id)";

        $hasShortCourses = ttm_table_exists($db, 'short_courses');
        $courseNameParts = ["c.course_name"];
        if ($hasShortCourses) {
            $courseNameParts[] = "sc.course_name";
        }
        $courseNameParts[] = "cs.course_code";
        $courseNameExpr = "COALESCE(" . implode(", ", $courseNameParts) . ")";

        $joins[] = "LEFT JOIN courses c ON c.course_code = cs.course_code";
        if ($hasShortCourses) {
            $joins[] = "LEFT JOIN short_courses sc ON sc.course_code = cs.course_code";
        }

        $lecturerDbId = null;
        if ($lecturerId !== '') {
            $stmt = $db->prepare("SELECT id FROM staff WHERE staff_id = ?");
            if ($stmt) {
                $stmt->bind_param('s', $lecturerId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $lecturerDbId = (int)$row['id'];
                }
                $stmt->close();
            }
        }

        $params = [];
        $types = '';
        $where = [$statusFilter];
        
        if ($yearCol !== '') {
            $where[] = "(cs.{$yearCol} = ? OR cs.{$yearCol} IS NULL)";
            $params[] = $yearOfStudy;
            $types .= 'i';
        }
        
        if (isset($cols['semester'])) {
            $where[] = "(cs.semester = ? OR cs.semester IS NULL)";
            $params[] = $semester;
            $types .= 'i';
        }

        if (is_array($allowedCourseCodes)) {
            $allowed = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $allowedCourseCodes)))));
            if (empty($allowed)) {
                return [];
            }
            $where[] = 'UPPER(cs.course_code) IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
            foreach ($allowed as $code) {
                $params[] = $code;
                $types .= 's';
            }
        }

        if ($lecturerId !== '') {
            if ($lecturerDbId !== null) {
                $where[] = "(cs.lecturer_id = ? OR cs.lecturer_id = ?)";
                $params[] = $lecturerId;
                $params[] = $lecturerDbId;
                $types .= 'si';
            } else {
                $where[] = "cs.lecturer_id = ?";
                $params[] = $lecturerId;
                $types .= 's';
            }
        }

        $sql = "SELECT cs.id, cs.course_code, cs.lecturer_id, cs.schedule_type, {$titleExpr},
                       {$dayExpr} AS day_of_week,
                       {$startTimeExpr} AS start_time,
                       {$endTimeExpr} AS end_time,
                       {$roomExpr} AS room, 
                       " . (isset($cols['semester']) ? "cs.semester" : "1 AS semester") . ", 
                       " . ($yearCol !== '' ? "cs.{$yearCol} AS year_of_study" : "1 AS year_of_study") . ",
                       {$courseNameExpr} AS course_name,
                       TRIM(CONCAT(COALESCE(s.title, ''), ' ', COALESCE(s.Fname, ''), ' ', COALESCE(s.Lname, ''))) AS lecturer_name
                FROM course_schedule cs
                " . implode("\n                ", $joins) . "
                WHERE " . implode(' AND ', $where) . "
                GROUP BY cs.id
                ORDER BY FIELD({$dayExpr}, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
                         " . ($hasTimeSlotId ? "ts.start_time" : (isset($cols['start_time']) ? "cs.start_time" : "1")) . ", cs.course_code";

        $rows = [];
        $stmt = $db->prepare($sql);
        if ($stmt) {
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $rows[] = $row;
            }
            $stmt->close();
        }
        return $rows;
    }
}

if (!function_exists('ttm_validate_schedule_input')) {
    function ttm_validate_schedule_input(array $input, ?array $allowedCourseCodes = null): array {
        $courseCode = strtoupper(trim((string)($input['course_code'] ?? '')));
        $lecturerId = trim((string)($input['lecturer_id'] ?? ''));
        $day = trim((string)($input['day_of_week'] ?? ''));
        $start = trim((string)($input['start_time'] ?? ''));
        $end = trim((string)($input['end_time'] ?? ''));
        $room = trim((string)($input['room'] ?? ''));
        $semester = (int)($input['semester'] ?? 0);
        $yearOfStudy = (int)($input['year_of_study'] ?? 0);
        $type = strtolower(trim((string)($input['schedule_type'] ?? 'lecture')));
        $title = trim((string)($input['title'] ?? ''));

        $errors = [];
        if ($courseCode === '') {
            $errors[] = 'Select a course.';
        }
        if (is_array($allowedCourseCodes)) {
            $allowed = array_map('strtoupper', array_map('trim', $allowedCourseCodes));
            if (!in_array($courseCode, $allowed, true)) {
                $errors[] = 'This course is outside your timetable scope.';
            }
        }
        if (!in_array($day, ttm_days(), true)) {
            $errors[] = 'Select a valid day.';
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || strtotime($start) >= strtotime($end)) {
            $errors[] = 'Enter a valid start and end time.';
        }
        if ($semester < 1 || $semester > 4) {
            $errors[] = 'Select a valid semester or term.';
        }
        if ($yearOfStudy < 1 || $yearOfStudy > 7) {
            $errors[] = 'Select a valid year of study.';
        }
        if (!array_key_exists($type, ttm_schedule_types())) {
            $type = 'lecture';
        }

        return [$errors, [
            'course_code' => $courseCode,
            'lecturer_id' => $lecturerId,
            'day_of_week' => $day,
            'start_time' => $start . ':00',
            'end_time' => $end . ':00',
            'room' => $room,
            'semester' => $semester,
            'year_of_study' => $yearOfStudy,
            'schedule_type' => $type,
            'title' => $title,
        ]];
    }
}

if (!function_exists('ttm_save_schedule')) {
    function ttm_save_schedule(mysqli $db, array $data, string $createdBy = '', int $id = 0): array {
        ttm_ensure_schema($db);
        $cols = ttm_columns($db, 'course_schedule');
        $lecturerValue = isset($cols['lecturer_id']) ? ttm_resolve_lecturer_storage_value($db, (string)$data['lecturer_id']) : null;
        $createdByValue = isset($cols['created_by']) ? ttm_resolve_created_by_storage_value($db, $createdBy) : null;
        $timeSlotId = isset($cols['time_slot_id']) ? ttm_resolve_time_slot_id($db, (string)$data['start_time'], (string)$data['end_time']) : null;
        $classroomId = isset($cols['classroom_id']) ? ttm_resolve_classroom_id($db, (string)$data['room']) : null;

        if (isset($cols['time_slot_id']) && !isset($cols['start_time']) && $timeSlotId === null) {
            return [false, 'Choose one of the configured timetable time slots for this schema.'];
        }

        $setters = [];
        $values = [];
        $types = '';
        $add = static function (string $column, $value, string $type) use (&$setters, &$values, &$types, $cols): void {
            if (!isset($cols[$column])) {
                return;
            }
            $setters[$column] = ($column === 'Year' ? '`Year`' : "`{$column}`") . ' = ?';
            $values[] = $value;
            $types .= $type;
        };

        $add('course_code', $data['course_code'], 's');
        if (isset($cols['lecturer_id'])) {
            $add('lecturer_id', $lecturerValue, is_int($lecturerValue) ? 'i' : 's');
        }
        $add('schedule_type', $data['schedule_type'], 's');
        if (isset($cols['title'])) {
            $add('title', $data['title'], 's');
        } elseif (isset($cols['notes'])) {
            $add('notes', $data['title'], 's');
        }
        $add('day', $data['day_of_week'], 's');
        $add('day_of_week', $data['day_of_week'], 's');
        $add('start_time', $data['start_time'], 's');
        $add('end_time', $data['end_time'], 's');
        if (isset($cols['time_slot_id'])) {
            $add('time_slot_id', $timeSlotId, 'i');
        }
        $add('room', $data['room'], 's');
        if (isset($cols['classroom_id'])) {
            $add('classroom_id', $classroomId, 'i');
        }
        $add('semester', $data['semester'], 'i');
        if (isset($cols['Year'])) {
            $add('Year', $data['year_of_study'], 'i');
        } elseif (isset($cols['academic_year'])) {
            $add('academic_year', $data['year_of_study'], 'i');
        }
        $add('status', 'active', 's');
        $add('is_active', 1, 'i');

        if (empty($setters)) {
            return [false, 'The timetable table has no writable schedule columns.'];
        }

        if ($id > 0) {
            $sql = "UPDATE course_schedule SET " . implode(', ', array_values($setters));
            if (isset($cols['updated_at'])) {
                $sql .= ', updated_at = NOW()';
            }
            $sql .= ' WHERE id = ?';
            $types .= 'i';
            $values[] = $id;
        } else {
            if (isset($cols['created_by'])) {
                $setters['created_by'] = '`created_by` = ?';
                $values[] = $createdByValue;
                $types .= is_int($createdByValue) ? 'i' : 's';
            }
            $columns = [];
            $placeholders = [];
            foreach (array_keys($setters) as $column) {
                $columns[] = $column === 'Year' ? '`Year`' : "`{$column}`";
                $placeholders[] = '?';
            }
            if (isset($cols['created_at'])) {
                $columns[] = 'created_at';
                $placeholders[] = 'NOW()';
            }
            if (isset($cols['updated_at'])) {
                $columns[] = 'updated_at';
                $placeholders[] = 'NOW()';
            }
            $sql = 'INSERT INTO course_schedule (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        }

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [false, 'Unable to prepare timetable save.'];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$values);
        }

        if (!$stmt->execute()) {
            $message = 'Timetable could not be saved: ' . $stmt->error;
            $stmt->close();
            return [false, $message];
        }
        $stmt->close();

        if ($data['lecturer_id'] !== '' && ttm_table_exists($db, 'course_lecturer')) {
            $clCols = ttm_columns($db, 'course_lecturer');
            $assignColumns = ['staff_id', 'course_code'];
            $assignPlaceholders = ['?', '?'];
            $assignTypes = 'ss';
            $assignValues = [$data['lecturer_id'], $data['course_code']];
            if (isset($clCols['semester'])) {
                $assignColumns[] = 'semester';
                $assignPlaceholders[] = '?';
                $assignTypes .= 'i';
                $assignValues[] = $data['semester'];
            }
            if (isset($clCols['academic_year'])) {
                $assignColumns[] = 'academic_year';
                $assignPlaceholders[] = '?';
                $assignTypes .= 'i';
                $assignValues[] = $data['year_of_study'];
            }
            if (isset($clCols['status'])) {
                $assignColumns[] = 'status';
                $assignPlaceholders[] = "'active'";
            }
            if (isset($clCols['created_at'])) {
                $assignColumns[] = 'created_at';
                $assignPlaceholders[] = 'NOW()';
            }
            if (isset($clCols['updated_at'])) {
                $assignColumns[] = 'updated_at';
                $assignPlaceholders[] = 'NOW()';
            }
            $assignSql = "INSERT IGNORE INTO course_lecturer (`" . implode('`, `', $assignColumns) . "`)
                          VALUES (" . implode(', ', $assignPlaceholders) . ")";
            $assign = $db->prepare($assignSql);
            if ($assign) {
                $assign->bind_param($assignTypes, ...$assignValues);
                @$assign->execute();
                $assign->close();
            }
        }

        return [true, $id > 0 ? 'Timetable entry updated.' : 'Timetable entry created.'];
    }
}

if (!function_exists('ttm_cancel_schedule')) {
    function ttm_cancel_schedule(mysqli $db, int $id, ?array $allowedCourseCodes = null): array {
        ttm_ensure_schema($db);
        $cols = ttm_columns($db, 'course_schedule');
        $params = [$id];
        $types = 'i';
        $where = 'id = ?';
        if (is_array($allowedCourseCodes)) {
            $allowed = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $allowedCourseCodes)))));
            if (empty($allowed)) {
                return [false, 'No timetable scope is available for this user.'];
            }
            $where .= ' AND UPPER(course_code) IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
            foreach ($allowed as $code) {
                $params[] = $code;
                $types .= 's';
            }
        }
        $sets = [];
        if (isset($cols['status'])) {
            $sets[] = "status = 'cancelled'";
        }
        if (isset($cols['is_active'])) {
            $sets[] = 'is_active = 0';
        }
        if (isset($cols['updated_at'])) {
            $sets[] = 'updated_at = NOW()';
        }
        if (empty($sets)) {
            return [false, 'The timetable table does not support cancelling entries.'];
        }
        $stmt = $db->prepare("UPDATE course_schedule SET " . implode(', ', $sets) . " WHERE {$where}");
        if (!$stmt) {
            return [false, 'Unable to prepare timetable delete.'];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return [$affected > 0, $affected > 0 ? 'Timetable entry removed.' : 'Timetable entry was not found.'];
    }
}
