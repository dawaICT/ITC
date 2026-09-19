<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../db/connect.php';

$shortCourseHelper = __DIR__ . '/../../includes/short_course_student.php';
if (is_file($shortCourseHelper)) {
    require_once $shortCourseHelper;
}

if (!isset($_SESSION['Sid'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$sid = (string)($_SESSION['Sid'] ?? '');
$response = ['results' => []];

if (!function_exists('digital_student_table_exists')) {
    function digital_student_table_exists(mysqli $db, string $table): bool
    {
        $cacheKey = 'wuc_schema_exists_' . $table;
        if (function_exists('apcu_fetch')) {
            $ok = false;
            $cached = apcu_fetch($cacheKey, $ok);
            if ($ok) {
                return (bool)$cached;
            }
        }

        $safe = $db->real_escape_string($table);
        if ($res = @$db->query("SHOW TABLES LIKE '{$safe}'")) {
            $exists = $res->num_rows > 0;
            $res->free();
            if (function_exists('apcu_store')) {
                apcu_store($cacheKey, $exists, 300);
            }
            return $exists;
        }
        return false;
    }
}

if (!function_exists('digital_student_columns')) {
    function digital_student_columns(mysqli $db, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $cacheKey = 'wuc_schema_cols_' . $table;
        if (function_exists('apcu_fetch')) {
            $ok = false;
            $cached = apcu_fetch($cacheKey, $ok);
            if ($ok && is_array($cached)) {
                return $cache[$table] = $cached;
            }
        }

        $columns = [];
        if (digital_student_table_exists($db, $table) && ($res = @$db->query("SHOW COLUMNS FROM `{$table}`"))) {
            while ($row = $res->fetch_assoc()) {
                $columns[strtolower((string)$row['Field'])] = (string)$row['Field'];
            }
            $res->free();
        }
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $columns, 300);
        }
        return $cache[$table] = $columns;
    }
}

if (!function_exists('digital_student_first_column')) {
    function digital_student_first_column(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($columns[$key])) {
                return $columns[$key];
            }
        }
        return null;
    }
}

if (!function_exists('digital_student_expr')) {
    function digital_student_expr(array $columns, array $candidates, string $fallback, ?string $alias = null): string
    {
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($columns[$key])) {
                $column = $columns[$key];
                return $alias ? "r.`{$column}` AS `{$alias}`" : "r.`{$column}`";
            }
        }
        return $alias ? "{$fallback} AS `{$alias}`" : $fallback;
    }
}

if (!function_exists('digital_student_bind')) {
    function digital_student_bind(mysqli_stmt $stmt, string $types, array &$params): void
    {
        if ($types === '') {
            return;
        }
        $refs = [$types];
        foreach ($params as $idx => &$value) {
            $refs[] = &$params[$idx];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

if (!function_exists('digital_student_search_clause')) {
    function digital_student_search_clause(array $columns): array
    {
        $parts = [];
        foreach (['title', 'subject', 'description', 'keywords', 'course_code', 'resource_type'] as $candidate) {
            $key = strtolower($candidate);
            if (isset($columns[$key])) {
                $column = $columns[$key];
                $parts[] = "r.`{$column}` LIKE ?";
            }
        }
        return $parts ?: ['r.`id` LIKE ?'];
    }
}

if (!function_exists('digital_student_visibility_clause')) {
    function digital_student_visibility_clause(array $columns): string
    {
        if (isset($columns['access_level'])) {
            return " AND COALESCE(r.`{$columns['access_level']}`, 'registered') IN ('open','registered','student','students','campus','public','all')";
        }
        if (isset($columns['visibility'])) {
            return " AND COALESCE(r.`{$columns['visibility']}`, 'students') IN ('open','registered','student','students','all','public','campus')";
        }
        return '';
    }
}

if (!function_exists('digital_student_views_subquery')) {
    function digital_student_views_subquery(mysqli $db): string
    {
        $cols = digital_student_columns($db, 'library_usage_analytics');
        if (!$cols || !isset($cols['resource_id']) || !isset($cols['event_type'])) {
            return "0 AS views";
        }
        return "(SELECT COUNT(*) FROM library_usage_analytics a WHERE a.`{$cols['resource_id']}` = r.id AND a.`{$cols['event_type']}` = 'view') AS views";
    }
}

if (!function_exists('digital_student_log_event')) {
    function digital_student_log_event(mysqli $db, ?int $resourceId, string $sid, string $eventType, array $meta = []): void
    {
        $cols = digital_student_columns($db, 'library_usage_analytics');
        if (!$cols) {
            return;
        }

        if (isset($cols['user_type'], $cols['user_id'])) {
            $metaSql = isset($cols['meta']) ? ', `meta`' : '';
            $metaVal = isset($cols['meta']) ? ', ?' : '';
            $sql = "INSERT INTO library_usage_analytics (`resource_id`, `user_type`, `user_id`, `event_type`{$metaSql}) VALUES (?, 'student', ?, ?{$metaVal})";
            if ($stmt = @$db->prepare($sql)) {
                if (isset($cols['meta'])) {
                    $json = json_encode($meta, JSON_UNESCAPED_SLASHES);
                    $stmt->bind_param('isss', $resourceId, $sid, $eventType, $json);
                } else {
                    $stmt->bind_param('iss', $resourceId, $sid, $eventType);
                }
                $stmt->execute();
                $stmt->close();
            }
            return;
        }

        if (isset($cols['student_id']) && $resourceId !== null) {
            $sql = "INSERT INTO library_usage_analytics (`resource_id`, `student_id`, `event_type`) VALUES (?, ?, ?)";
            if ($stmt = @$db->prepare($sql)) {
                $stmt->bind_param('iss', $resourceId, $sid, $eventType);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

if (!function_exists('digital_student_tokens')) {
    function digital_student_tokens(string $text): array
    {
        static $stop = [
            'and' => true, 'the' => true, 'for' => true, 'with' => true, 'into' => true,
            'from' => true, 'your' => true, 'course' => true, 'skills' => true, 'principles' => true,
            'introduction' => true, 'basic' => true, 'free' => true, 'open' => true,
        ];
        $raw = preg_split('/[^a-z0-9]+/i', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = [];
        foreach ($raw ?: [] as $token) {
            if (strlen($token) < 3 || isset($stop[$token])) {
                continue;
            }
            $tokens[$token] = true;
        }
        return array_keys($tokens);
    }
}

if (!function_exists('digital_student_programme_codes')) {
    /**
     * Resolve the student's programme scope (codes + names) so book/reading
     * resources can be recommended "according to the programme the student is
     * doing", not just the registered course list.
     *
     * @return array<int,array{programme_code:string,programme_name:string,label:string}>
     */
    function digital_student_programme_codes(mysqli $db, string $sid): array
    {
        $programmes = [];
        if ($sid === '' || !digital_student_table_exists($db, 'student_program')) {
            return $programmes;
        }

        $spCols = digital_student_columns($db, 'student_program');
        $sidCol = digital_student_first_column($spCols, ['Sid', 'student_id', 'SID', 'student']);
        $codeCol = digital_student_first_column($spCols, ['program_code', 'programme_code', 'ProgramCode']);
        if (!$sidCol || !$codeCol) {
            return $programmes;
        }

        $sql = "SELECT DISTINCT `{$codeCol}` AS program_code
                FROM student_program
                WHERE `{$sidCol}` = ?
                ORDER BY `{$codeCol}` ASC
                LIMIT 20";
        $codes = [];
        if ($stmt = @$db->prepare($sql)) {
            $stmt->bind_param('s', $sid);
            $stmt->execute();
            if ($res = $stmt->get_result()) {
                while ($row = $res->fetch_assoc()) {
                    $code = strtoupper(trim((string)($row['program_code'] ?? '')));
                    if ($code !== '') {
                        $codes[$code] = true;
                    }
                }
            }
            $stmt->close();
        }
        if (!$codes) {
            return $programmes;
        }

        // Programme names where programmes/programs provides them (defensive).
        $nameByCode = [];
        foreach (['programmes', 'programs'] as $ptable) {
            if (!digital_student_table_exists($db, $ptable)) {
                continue;
            }
            $pCols = digital_student_columns($db, $ptable);
            $pCodeCol = digital_student_first_column($pCols, ['program_code', 'programme_code', 'code', 'ProgramCode']);
            $pNameCol = digital_student_first_column($pCols, ['program_name', 'programme_name', 'name', 'title']);
            if (!$pCodeCol || !$pNameCol) {
                continue;
            }
            $codesList = array_keys($codes);
            $ph = implode(',', array_fill(0, count($codesList), '?'));
            $nsql = "SELECT `{$pCodeCol}` AS program_code, `{$pNameCol}` AS program_name
                     FROM `{$ptable}`
                     WHERE `{$pCodeCol}` IN ({$ph})
                     LIMIT 50";
            if ($nstmt = @$db->prepare($nsql)) {
                $nstmt->bind_param(str_repeat('s', count($codesList)), ...$codesList);
                $nstmt->execute();
                if ($nres = $nstmt->get_result()) {
                    while ($nrow = $nres->fetch_assoc()) {
                        $k = strtoupper(trim((string)($nrow['program_code'] ?? '')));
                        $v = trim((string)($nrow['program_name'] ?? ''));
                        if ($k !== '' && $v !== '') {
                            $nameByCode[$k] = $v;
                        }
                    }
                }
                $nstmt->close();
            }
            if ($nameByCode) {
                break;
            }
        }

        foreach (array_keys($codes) as $code) {
            $name = $nameByCode[$code] ?? '';
            $programmes[] = [
                'programme_code' => $code,
                'programme_name' => $name,
                'label' => trim($code . ($name !== '' ? ' - ' . $name : '')),
            ];
        }
        return $programmes;
    }
}

if (!function_exists('digital_student_course_context')) {
    function digital_student_course_context(mysqli $db, string $sid): array
    {
        $courses = [];
        $seen = [];

        $crCols = digital_student_columns($db, 'course_registration');
        if ($crCols) {
            $sidCol = digital_student_first_column($crCols, ['Sid', 'student_id', 'SID', 'student']);
            $codeCol = digital_student_first_column($crCols, ['course_code', 'CourseCode', 'course']);
            if ($sidCol && $codeCol) {
                $coursesCols = digital_student_columns($db, 'courses');
                $courseNameSelect = "'' AS course_name";
                $courseJoin = '';
                if ($coursesCols && isset($coursesCols['course_code'])) {
                    $courseNameCol = digital_student_first_column($coursesCols, ['course_name', 'title', 'name']);
                    if ($courseNameCol) {
                        $courseNameSelect = "COALESCE(c.`{$courseNameCol}`, '') AS course_name";
                        $courseJoin = " LEFT JOIN courses c ON c.`{$coursesCols['course_code']}` = cr.`{$codeCol}`";
                    }
                }

                $select = [
                    "cr.`{$codeCol}` AS course_code",
                    $courseNameSelect,
                    isset($crCols['semester']) ? "cr.`{$crCols['semester']}` AS semester" : "NULL AS semester",
                    isset($crCols['year']) ? "cr.`{$crCols['year']}` AS year_of_study" : "NULL AS year_of_study",
                    isset($crCols['semester_registration_id']) ? "cr.`{$crCols['semester_registration_id']}` AS semester_registration_id" : "NULL AS semester_registration_id",
                    isset($crCols['id']) ? "cr.`{$crCols['id']}` AS row_id" : "0 AS row_id",
                ];

                $activeSql = isset($crCols['is_active']) ? " AND COALESCE(cr.`{$crCols['is_active']}`, 1) = 1" : '';
                $orderParts = [];
                if (isset($crCols['semester_registration_id'])) {
                    $orderParts[] = "cr.`{$crCols['semester_registration_id']}` DESC";
                }
                if (isset($crCols['year'])) {
                    $orderParts[] = "cr.`{$crCols['year']}` DESC";
                }
                if (isset($crCols['semester'])) {
                    $orderParts[] = "cr.`{$crCols['semester']}` DESC";
                }
                if (isset($crCols['id'])) {
                    $orderParts[] = "cr.`{$crCols['id']}` DESC";
                }
                $orderSql = $orderParts ? implode(', ', $orderParts) : "cr.`{$codeCol}` ASC";
                $sql = 'SELECT ' . implode(', ', $select) . "
                        FROM course_registration cr
                        {$courseJoin}
                        WHERE cr.`{$sidCol}` = ?{$activeSql}
                        ORDER BY {$orderSql}
                        LIMIT 120";
                if ($stmt = @$db->prepare($sql)) {
                    $stmt->bind_param('s', $sid);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
                    $stmt->close();

                    if ($rows) {
                        $first = $rows[0];
                        $latestSemReg = trim((string)($first['semester_registration_id'] ?? ''));
                        $latestYear = trim((string)($first['year_of_study'] ?? ''));
                        $latestSemester = trim((string)($first['semester'] ?? ''));
                        foreach ($rows as $row) {
                            $rowSemReg = trim((string)($row['semester_registration_id'] ?? ''));
                            $include = false;
                            if ($latestSemReg !== '') {
                                $include = $rowSemReg === $latestSemReg;
                            } elseif ($latestYear !== '' || $latestSemester !== '') {
                                $include = trim((string)($row['year_of_study'] ?? '')) === $latestYear
                                    && trim((string)($row['semester'] ?? '')) === $latestSemester;
                            } else {
                                $include = true;
                            }
                            if (!$include) {
                                continue;
                            }
                            $code = strtoupper(trim((string)($row['course_code'] ?? '')));
                            if ($code === '' || isset($seen[$code])) {
                                continue;
                            }
                            $seen[$code] = true;
                            $courses[] = [
                                'course_code' => $code,
                                'course_name' => trim((string)($row['course_name'] ?? '')),
                                'source' => 'semester',
                                'semester' => $row['semester'] ?? null,
                                'year_of_study' => $row['year_of_study'] ?? null,
                            ];
                        }
                    }
                }
            }
        }

        if (function_exists('sc_student_enrolments')) {
            foreach (sc_student_enrolments($db, $sid) as $enrolment) {
                $code = strtoupper(trim((string)($enrolment['course_code'] ?? '')));
                if ($code === '' || isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                $courses[] = [
                    'course_code' => $code,
                    'course_name' => trim((string)($enrolment['course_name'] ?? '')),
                    'source' => 'short_course',
                    'status' => $enrolment['status'] ?? '',
                ];
            }
        }

        return $courses;
    }
}

if (!function_exists('digital_student_score_resources')) {
    function digital_student_score_resources(array $rows, array $courses, array $programmes = []): array
    {
        $courseTokens = [];
        foreach ($courses as $course) {
            $code = strtoupper(trim((string)($course['course_code'] ?? '')));
            $name = trim((string)($course['course_name'] ?? ''));
            $courseTokens[] = [
                'code' => $code,
                'name' => $name,
                'label' => trim($code . ($name !== '' ? ' - ' . $name : '')),
                'tokens' => digital_student_tokens($code . ' ' . $name),
            ];
        }

        // Programme scope: books/reading resources can be recommended "according
        // to the programme the student is doing", so programme codes + names are
        // matched the same way course codes are.
        $programmeTokens = [];
        foreach ($programmes as $programme) {
            $code = strtoupper(trim((string)($programme['programme_code'] ?? '')));
            $name = trim((string)($programme['programme_name'] ?? ''));
            $programmeTokens[] = [
                'code' => $code,
                'name' => $name,
                'label' => trim($code . ($name !== '' ? ' - ' . $name : '')),
                'tokens' => digital_student_tokens($code . ' ' . $name),
            ];
        }

        foreach ($rows as &$row) {
            $title = (string)($row['title'] ?? '');
            $description = (string)($row['description'] ?? '');
            $subject = (string)($row['subject'] ?? '');
            $rawUrl = (string)($row['url'] ?? '');
            $courseCode = strtoupper(trim((string)($row['course_code'] ?? $subject)));
            $type = strtolower((string)($row['resource_type'] ?? ''));
            $haystack = strtolower($title . ' ' . $description . ' ' . $subject . ' ' . $courseCode);
            $score = 0;
            $reasons = [];
            $matched = [];

            foreach ($courseTokens as $course) {
                $code = $course['code'];
                $label = $course['label'];
                if ($code !== '' && $courseCode === $code) {
                    $score += 120;
                    $matched[] = $code;
                    $reasons[] = "Assigned course: {$label}";
                    continue;
                }
                if ($code !== '' && strpos($haystack, strtolower($code)) !== false) {
                    $score += 75;
                    $matched[] = $code;
                    $reasons[] = "Mentions {$code}";
                    continue;
                }
                $overlap = 0;
                foreach ($course['tokens'] as $token) {
                    if (strpos($haystack, $token) !== false) {
                        $overlap++;
                    }
                }
                if ($overlap >= 2) {
                    $score += 22 + ($overlap * 6);
                    $matched[] = $code;
                    $reasons[] = "Related to {$label}";
                } elseif ($overlap === 1) {
                    $score += 8;
                }
            }

            // Programme-aware boost: a book/reading resource that names the
            // student's programme (code or name) is recommended to them.
            foreach ($programmeTokens as $programme) {
                $code = $programme['code'];
                $label = $programme['label'];
                if ($code === '') {
                    continue;
                }
                if ($courseCode === $code) {
                    $score += 90;
                    $matched[] = $code;
                    $reasons[] = "Programme: {$label}";
                    continue;
                }
                if (strpos($haystack, strtolower($code)) !== false) {
                    $score += 50;
                    $matched[] = $code;
                    $reasons[] = "Programme mentions {$code}";
                    continue;
                }
                $overlap = 0;
                foreach ($programme['tokens'] as $token) {
                    if (strpos($haystack, $token) !== false) {
                        $overlap++;
                    }
                }
                if ($overlap >= 2) {
                    $score += 20 + ($overlap * 5);
                    $matched[] = $code;
                    $reasons[] = "Related to programme {$label}";
                } elseif ($overlap === 1) {
                    $score += 6;
                }
            }

            if ($type === 'video' && $score > 0) {
                $score += 18;
            }

            // Universal open-access *book* source (e.g. the seeded Z-Library):
            // books are relevant to every student regardless of course, so give
            // it a baseline that puts it in the "Recommended" surface.
            if (strpos($rawUrl, 'z-library.biz') !== false || strpos($rawUrl, 'z-lib.io') !== false) {
                if ($score < 38) {
                    $score = 38;
                }
                $reasons[] = 'Free eBook & textbook source for your studies';
            } elseif ($courseCode === '' && $score === 0) {
                $score = 4;
                $reasons[] = 'General study resource';
            }

            $row['recommendation_score'] = $score;
            $row['recommended'] = $score >= 30 ? 1 : 0;
            $row['match_reason'] = $reasons ? $reasons[0] : '';
            $row['matched_courses'] = array_values(array_unique(array_filter($matched)));
            $row['source'] = $row['source'] ?? 'library';
        }
        unset($row);

        usort($rows, static function (array $a, array $b): int {
            $score = ((int)($b['recommendation_score'] ?? 0)) <=> ((int)($a['recommendation_score'] ?? 0));
            if ($score !== 0) {
                return $score;
            }
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        });

        return $rows;
    }
}

if (!function_exists('digital_student_el_module_cards')) {
    function digital_student_el_module_cards(mysqli $db, array $courses): array
    {
        if (!$courses || !digital_student_table_exists($db, 'el_course_modules')) {
            return [];
        }
        $cards = [];
        foreach ($courses as $course) {
            if (($course['source'] ?? '') !== 'semester') {
                continue;
            }
            $code = (string)($course['course_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $sql = "SELECT id, course_code, title, description, created_at
                    FROM el_course_modules
                    WHERE course_code = ?
                      AND (release_at IS NULL OR release_at <= NOW())
                      AND (close_at IS NULL OR close_at >= NOW())
                    ORDER BY position, id
                    LIMIT 6";
            if ($stmt = @$db->prepare($sql)) {
                $stmt->bind_param('s', $code);
                $stmt->execute();
                if ($res = $stmt->get_result()) {
                    while ($row = $res->fetch_assoc()) {
                        $cards[] = [
                            'id' => 'el-module-' . (int)$row['id'],
                            'title' => (string)$row['title'],
                            'resource_type' => 'document',
                            'subject' => $code,
                            'course_code' => $code,
                            'access_level' => 'students',
                            'url' => '/wucportal/students/elearning/course.php?course=' . rawurlencode($code),
                            'description' => trim((string)($row['description'] ?? '')) !== ''
                                ? (string)$row['description']
                                : 'Open the current eLearning module for this assigned course.',
                            'created_at' => $row['created_at'] ?? null,
                            'views' => 0,
                            'recommendation_score' => 95,
                            'recommended' => 1,
                            'match_reason' => 'eLearning module for ' . $code,
                            'matched_courses' => [$code],
                            'source' => 'elearning',
                            'synthetic' => 1,
                        ];
                    }
                }
                $stmt->close();
            }
        }
        return $cards;
    }
}

if (!function_exists('digital_student_short_course_material_cards')) {
    function digital_student_short_course_material_cards(mysqli $db, array $courses): array
    {
        if (!$courses || !digital_student_table_exists($db, 'short_course_materials') || !digital_student_table_exists($db, 'short_courses')) {
            return [];
        }
        $cards = [];
        foreach ($courses as $course) {
            if (($course['source'] ?? '') !== 'short_course') {
                continue;
            }
            $code = (string)($course['course_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $sql = "SELECT m.id, m.title, m.material_type, m.url, m.created_at, sc.course_code
                    FROM short_course_materials m
                    INNER JOIN short_courses sc ON sc.id = m.short_course_id
                    WHERE sc.course_code = ?
                    ORDER BY m.created_at DESC, m.id DESC
                    LIMIT 8";
            if ($stmt = @$db->prepare($sql)) {
                $stmt->bind_param('s', $code);
                $stmt->execute();
                if ($res = $stmt->get_result()) {
                    while ($row = $res->fetch_assoc()) {
                        $materialType = strtolower((string)($row['material_type'] ?? 'document'));
                        $cards[] = [
                            'id' => 'sc-material-' . (int)$row['id'],
                            'title' => (string)$row['title'],
                            'resource_type' => $materialType === 'video' ? 'video' : 'document',
                            'subject' => $code,
                            'course_code' => $code,
                            'access_level' => 'students',
                            'url' => (string)($row['url'] ?? ''),
                            'description' => 'Short-course learning material assigned to your enrolment.',
                            'created_at' => $row['created_at'] ?? null,
                            'views' => 0,
                            'recommendation_score' => 115,
                            'recommended' => 1,
                            'match_reason' => 'Short-course material for ' . $code,
                            'matched_courses' => [$code],
                            'source' => 'short_course',
                            'synthetic' => 1,
                        ];
                    }
                }
                $stmt->close();
            }
        }
        return $cards;
    }
}

if (!function_exists('digital_student_suggestion_cards')) {
    function digital_student_suggestion_cards(array $courses, string $type = '', array $programmes = []): array
    {
        $cards = [];
        $limit = 10;
        $bookSource = 'https://z-library.biz/';

        // Per-course "get books" suggestion so reading resources are recommended
        // according to the courses the student is doing (links to the Z-Library
        // book source). Shown for the default & ebook/document/journal views.
        $showBooks = $type === '' || in_array($type, ['ebook', 'document', 'journal'], true);
        foreach ($courses as $course) {
            if (count($cards) >= $limit) {
                break;
            }
            $code = strtoupper(trim((string)($course['course_code'] ?? '')));
            $name = trim((string)($course['course_name'] ?? ''));
            if ($code === '') {
                continue;
            }
            $label = trim($code . ($name !== '' ? ' ' . $name : ''));
            $query = rawurlencode($label . ' lecture tutorial');

            if ($type === '' || $type === 'video') {
                $cards[] = [
                    'id' => 'suggest-video-' . preg_replace('/[^A-Z0-9_-]/', '', $code),
                    'title' => 'Video lessons for ' . ($name !== '' ? $name : $code),
                    'resource_type' => 'video',
                    'subject' => $code,
                    'course_code' => $code,
                    'access_level' => 'students',
                    'url' => 'https://www.youtube.com/results?search_query=' . $query,
                    'description' => 'Suggested video search based on your assigned course. Review the lecturer-approved library resources first where available.',
                    'created_at' => date('Y-m-d H:i:s'),
                    'views' => 0,
                    'recommendation_score' => 52,
                    'recommended' => 1,
                    'match_reason' => 'Suggested from your course list',
                    'matched_courses' => [$code],
                    'source' => 'suggested_video',
                    'synthetic' => 1,
                ];
            }

            if ($showBooks) {
                $cards[] = [
                    'id' => 'suggest-book-' . preg_replace('/[^A-Z0-9_-]/', '', $code),
                    'title' => 'Get books for ' . ($name !== '' ? $name : $code),
                    'resource_type' => 'ebook',
                    'subject' => $code,
                    'course_code' => $code,
                    'access_level' => 'students',
                    'url' => $bookSource,
                    'description' => 'Find eBooks and textbooks for this course on Z-Library.',
                    'created_at' => date('Y-m-d H:i:s'),
                    'views' => 0,
                    'recommendation_score' => 56,
                    'recommended' => 1,
                    'match_reason' => 'Books matched to your course',
                    'matched_courses' => [$code],
                    'source' => 'suggested_book',
                    'synthetic' => 1,
                ];
            }

            if (count($cards) >= $limit) {
                break;
            }
            if ($type === '' || in_array($type, ['ebook', 'journal', 'document'], true)) {
                $cards[] = [
                    'id' => 'suggest-oer-' . preg_replace('/[^A-Z0-9_-]/', '', $code),
                    'title' => 'Open resources for ' . ($name !== '' ? $name : $code),
                    'resource_type' => 'document',
                    'subject' => $code,
                    'course_code' => $code,
                    'access_level' => 'students',
                    'url' => 'https://www.oercommons.org/search?f.search=' . rawurlencode($label),
                    'description' => 'Suggested open educational resources matched to this course.',
                    'created_at' => date('Y-m-d H:i:s'),
                    'views' => 0,
                    'recommendation_score' => 44,
                    'recommended' => 1,
                    'match_reason' => 'Suggested from your course list',
                    'matched_courses' => [$code],
                    'source' => 'suggested_resource',
                    'synthetic' => 1,
                ];
            }
        }

        // Programme-level "get books" card so a reading resource is recommended
        // for the programme the student is doing even before course overlaps.
        if ($showBooks) {
            foreach ($programmes as $programme) {
                if (count($cards) >= $limit + 2) {
                    break;
                }
                $code = strtoupper(trim((string)($programme['programme_code'] ?? '')));
                $name = trim((string)($programme['programme_name'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $pid = preg_replace('/[^A-Z0-9_-]/', '', $code);
                $cards[] = [
                    'id' => 'suggest-book-prog-' . $pid,
                    'title' => 'Get books for ' . ($name !== '' ? $name : $code),
                    'resource_type' => 'ebook',
                    'subject' => $code,
                    'course_code' => '',
                    'programme_code' => $code,
                    'access_level' => 'students',
                    'url' => $bookSource,
                    'description' => 'Find eBooks and textbooks for your programme on Z-Library.',
                    'created_at' => date('Y-m-d H:i:s'),
                    'views' => 0,
                    'recommendation_score' => 54,
                    'recommended' => 1,
                    'match_reason' => 'Books matched to your programme',
                    'matched_courses' => [$code],
                    'source' => 'suggested_book',
                    'synthetic' => 1,
                ];
            }
        }

        return $cards;
    }
}

try {
    $resourceCols = digital_student_columns($db, 'library_digital_resources');
    if (!$resourceCols) {
        throw new Exception('Digital library is not configured.');
    }

    $titleExpr = digital_student_expr($resourceCols, ['title'], "''", 'title');
    $typeExpr = digital_student_expr($resourceCols, ['resource_type', 'type'], "'resource'", 'resource_type');
    $subjectExpr = digital_student_expr($resourceCols, ['subject', 'course_code'], "''", 'subject');
    $courseCodeExpr = digital_student_expr($resourceCols, ['course_code', 'subject'], "''", 'course_code');
    $accessExpr = digital_student_expr($resourceCols, ['access_level', 'visibility'], "'registered'", 'access_level');
    $urlExpr = digital_student_expr($resourceCols, ['url', 'file_path'], "''", 'url');
    $descExpr = digital_student_expr($resourceCols, ['description', 'summary'], "''", 'description');
    $createdExpr = digital_student_expr($resourceCols, ['created_at'], "NULL", 'created_at');
    $viewsExpr = digital_student_views_subquery($db);

    $select = "r.`id`, {$titleExpr}, {$typeExpr}, {$subjectExpr}, {$courseCodeExpr}, {$accessExpr}, {$urlExpr}, {$descExpr}, {$createdExpr}, {$viewsExpr}";
    $visibility = digital_student_visibility_clause($resourceCols);
    $learningContext = digital_student_course_context($db, $sid);
    // Programme scope so books/reading resources are recommended according to
    // the programme the student is doing, not just their registered courses.
    $programmeContext = digital_student_programme_codes($db, $sid);

    switch ($action) {
        case 'list':
        case 'popular':
        case 'search':
            $rawQ = trim((string)($_GET['q'] ?? ''));
            $type = trim((string)($_GET['type'] ?? ''));
            $params = [];
            $types = '';

            if ($action === 'search') {
                $searchParts = digital_student_search_clause($resourceCols);
                $sql = "SELECT {$select} FROM library_digital_resources r WHERE (" . implode(' OR ', $searchParts) . "){$visibility}";
                $q = '%' . $rawQ . '%';
                $types .= str_repeat('s', count($searchParts));
                $params = array_fill(0, count($searchParts), $q);
            } else {
                $sql = "SELECT {$select} FROM library_digital_resources r WHERE 1=1{$visibility}";
            }

            if ($type !== '' && isset($resourceCols['resource_type'])) {
                $sql .= " AND r.`{$resourceCols['resource_type']}` = ?";
                $types .= 's';
                $params[] = $type;
            }

            $order = $action === 'popular' ? 'views DESC, r.id DESC' : 'r.id DESC';
            $sql .= " ORDER BY {$order} LIMIT 100";

            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $db->error);
            }
            digital_student_bind($stmt, $types, $params);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();

            if ($rawQ !== '' && $action === 'search') {
                digital_student_log_event($db, null, $sid, 'search', ['q' => $rawQ]);
            }

            $rows = digital_student_score_resources($rows, $learningContext, $programmeContext);
            $addonRows = [];
            if ($rawQ === '') {
                $addonRows = array_merge(
                    digital_student_short_course_material_cards($db, $learningContext),
                    digital_student_el_module_cards($db, $learningContext),
                    digital_student_suggestion_cards($learningContext, $type, $programmeContext)
                );
            }

            $mergedRows = array_merge($rows, $addonRows);
            usort($mergedRows, static function (array $a, array $b): int {
                $score = ((int)($b['recommendation_score'] ?? 0)) <=> ((int)($a['recommendation_score'] ?? 0));
                if ($score !== 0) {
                    return $score;
                }
                return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
            });

            $response['results'] = array_slice($mergedRows, 0, 120);
            $response['learning_context'] = $learningContext;
            $response['programme_context'] = $programmeContext;
            break;

        case 'view':
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Invalid resource.');
            }
            digital_student_log_event($db, $id, $sid, 'view');
            $response['ok'] = true;
            break;

        default:
            http_response_code(400);
            $response['error'] = 'Invalid action';
    }
} catch (Throwable $e) {
    http_response_code(http_response_code() >= 400 ? http_response_code() : 500);
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
