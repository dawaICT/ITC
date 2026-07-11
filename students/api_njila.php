<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/portal_config.php';
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/short_course_student.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/includes/period_mode_helper.php';

const NJILA_LAUNCH_URL = 'https://njila.ai/#/';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === 'https://njila.ai') {
    header('Access-Control-Allow-Origin: https://njila.ai');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

wuc_api_start_session('njila-api');

if (!function_exists('njila_json')) {
    function njila_json(array $payload, int $status = 200): void
    {
        wuc_json_response($payload, $status);
    }
}

if (!function_exists('njila_bearer_token')) {
    function njila_bearer_token(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (preg_match('/^Bearer\s+(.+)$/i', trim((string)$header), $match)) {
            return trim($match[1]);
        }
        return '';
    }
}

if (!function_exists('njila_table_exists')) {
    function njila_table_exists(mysqli $db, string $table): bool
    {
        $safe = $db->real_escape_string($table);
        if ($result = @$db->query("SHOW TABLES LIKE '{$safe}'")) {
            $exists = $result->num_rows > 0;
            $result->free();
            return $exists;
        }
        return false;
    }
}

if (!function_exists('njila_columns')) {
    function njila_columns(mysqli $db, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $columns = [];
        if (njila_table_exists($db, $table) && ($result = @$db->query("SHOW COLUMNS FROM `{$table}`"))) {
            while ($row = $result->fetch_assoc()) {
                $columns[strtolower((string)$row['Field'])] = (string)$row['Field'];
            }
            $result->free();
        }
        return $cache[$table] = $columns;
    }
}

if (!function_exists('njila_first_column')) {
    function njila_first_column(array $columns, array $candidates): ?string
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

if (!function_exists('njila_valid_sid')) {
    function njila_valid_sid(string $sid): bool
    {
        return $sid !== '' && (bool)preg_match('/^[A-Za-z0-9\/\-_]+$/', $sid);
    }
}

if (!function_exists('njila_current_student_id')) {
    function njila_current_student_id(): ?string
    {
        $sessionSid = trim((string)($_SESSION['Sid'] ?? ''));
        if (njila_valid_sid($sessionSid)) {
            return $sessionSid;
        }

        $configuredToken = (string)(getenv('NJILA_STUDENT_API_TOKEN') ?: '');
        $requestToken = njila_bearer_token();
        $requestedSid = trim((string)($_GET['sid'] ?? ''));
        if ($configuredToken !== '' && $requestToken !== ''
            && hash_equals($configuredToken, $requestToken)
            && njila_valid_sid($requestedSid)) {
            return $requestedSid;
        }

        return null;
    }
}

if (!function_exists('njila_fetch_student')) {
    function njila_fetch_student(mysqli $db, string $sid): ?array
    {
        $sql = "SELECT SID, Fname, Lname, email, mobile FROM students WHERE SID = ? LIMIT 1";
        if (!$stmt = $db->prepare($sql)) {
            return null;
        }
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        $first = trim((string)($row['Fname'] ?? ''));
        $last = trim((string)($row['Lname'] ?? ''));
        return [
            'id' => (string)$row['SID'],
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => trim($first . ' ' . $last) ?: (string)$row['SID'],
            'email' => (string)($row['email'] ?? ''),
            'mobile' => (string)($row['mobile'] ?? ''),
        ];
    }
}

if (!function_exists('njila_fetch_program')) {
    function njila_fetch_program(mysqli $db, string $sid): array
    {
        $columns = njila_columns($db, 'student_program');
        $sidCol = njila_first_column($columns, ['Sid', 'student_id', 'SID', 'student']);
        $programCol = njila_first_column($columns, ['program_code', 'programme_code', 'program']);
        if ($sidCol === null || $programCol === null) {
            return [];
        }

        $startCol = njila_first_column($columns, ['startYear', 'start_year']);
        $endCol = njila_first_column($columns, ['endYear', 'end_year']);
        $orderCol = njila_first_column($columns, ['id', 'created_at', 'updated_at']);
        $startSelect = $startCol ? "sp.`{$startCol}` AS startYear" : "NULL AS startYear";
        $endSelect = $endCol ? "sp.`{$endCol}` AS endYear" : "NULL AS endYear";
        $orderSql = $orderCol ? " ORDER BY sp.`{$orderCol}` DESC" : '';

        $sql = "SELECT sp.`{$programCol}` AS program_code, {$startSelect}, {$endSelect},
                       p.program_name, p.program_duration, p.study_mode
                FROM student_program sp
                LEFT JOIN programs p ON p.program_code = sp.`{$programCol}`
                WHERE sp.`{$sidCol}` = ?
                {$orderSql}
                LIMIT 1";
        if (!$stmt = @$db->prepare($sql)) {
            return [];
        }
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return [
            'program_code' => (string)($row['program_code'] ?? ''),
            'program_name' => (string)($row['program_name'] ?? ''),
            'duration' => $row['program_duration'] ?? null,
            'study_mode' => (string)($row['study_mode'] ?? ''),
            'start_year' => $row['startYear'] ?? null,
            'end_year' => $row['endYear'] ?? null,
        ];
    }
}

if (!function_exists('njila_fetch_registration')) {
    function njila_fetch_registration(mysqli $db, string $sid): array
    {
        $columns = njila_columns($db, 'semester_registration');
        if (!$columns) {
            return [];
        }

        $sidCol = njila_first_column($columns, ['student_id', 'Sid', 'SID', 'student']);
        if ($sidCol === null) {
            return [];
        }

        $select = ["`{$sidCol}` AS student_id"];
        foreach ([
            'academic_year' => ['academic_year', 'AcademicYear', 'year'],
            'semester' => ['semester', 'sem'],
            'period_type' => ['period_type', 'period_mode'],
            'year_of_study' => ['year_of_study', 'Year', 'study_year'],
            'status' => ['status', 'registration_status'],
            'registered_at' => ['registration_date', 'created_at', 'date_registered'],
        ] as $alias => $candidates) {
            $col = njila_first_column($columns, $candidates);
            $select[] = $col ? "`{$col}` AS `{$alias}`" : "NULL AS `{$alias}`";
        }

        $orderCol = njila_first_column($columns, ['id', 'created_at', 'registration_date']);
        $orderSql = $orderCol ? " ORDER BY `{$orderCol}` DESC" : '';
        $sql = 'SELECT ' . implode(', ', $select) . " FROM semester_registration WHERE `{$sidCol}` = ?{$orderSql} LIMIT 1";
        if (!$stmt = @$db->prepare($sql)) {
            return [];
        }
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return $row;
    }
}

if (!function_exists('njila_fetch_courses')) {
    function njila_fetch_courses(mysqli $db, string $sid): array
    {
        $codes = getStudentEnrolledCourses($db, $sid);
        $codes = array_values(array_unique(array_filter(array_map(static function ($code): string {
            return strtoupper(trim((string)$code));
        }, is_array($codes) ? $codes : []))));

        if (!$codes) {
            return [];
        }

        $nameMap = [];
        $courseCols = njila_columns($db, 'courses');
        $codeCol = njila_first_column($courseCols, ['course_code', 'code']);
        $nameCol = njila_first_column($courseCols, ['course_name', 'name', 'title']);
        if ($codeCol && $nameCol) {
            $placeholders = implode(',', array_fill(0, count($codes), '?'));
            $types = str_repeat('s', count($codes));
            $sql = "SELECT `{$codeCol}` AS course_code, `{$nameCol}` AS course_name FROM courses WHERE UPPER(TRIM(`{$codeCol}`)) IN ({$placeholders})";
            if ($stmt = @$db->prepare($sql)) {
                $stmt->bind_param($types, ...$codes);
                $stmt->execute();
                if ($result = $stmt->get_result()) {
                    while ($row = $result->fetch_assoc()) {
                        $nameMap[strtoupper(trim((string)$row['course_code']))] = (string)$row['course_name'];
                    }
                }
                $stmt->close();
            }
        }

        return array_map(static function (string $code) use ($nameMap): array {
            return [
                'course_code' => $code,
                'course_name' => $nameMap[$code] ?? '',
            ];
        }, $codes);
    }
}

if (!function_exists('njila_fetch_short_courses')) {
    function njila_fetch_short_courses(mysqli $db, string $sid): array
    {
        if (!function_exists('sc_student_enrolments')) {
            return [];
        }

        $rows = [];
        foreach (sc_student_enrolments($db, $sid) as $row) {
            $rows[] = [
                'course_code' => (string)($row['course_code'] ?? ''),
                'course_name' => (string)($row['course_name'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'delivery_mode' => (string)($row['delivery_mode'] ?? ''),
                'start_date' => $row['start_date'] ?? null,
                'end_date' => $row['end_date'] ?? null,
            ];
        }
        return $rows;
    }
}

if (!isset($db) || !($db instanceof mysqli)) {
    njila_json(['success' => false, 'error' => 'Database connection is not available.'], 500);
}

$sid = njila_current_student_id();
if ($sid === null) {
    njila_json([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Log in as a student or provide a valid Njila bearer token.',
    ], 401);
}

$student = njila_fetch_student($db, $sid);
if ($student === null) {
    njila_json(['success' => false, 'error' => 'Student record was not found.'], 404);
}

$action = strtolower(trim((string)($_GET['action'] ?? 'context')));
if ($action === 'launch') {
    audit_log($db, $sid, 'njila.launch', ['provider' => 'njila.ai']);
    njila_json([
        'success' => true,
        'launch_url' => NJILA_LAUNCH_URL,
        'message' => 'Open the launch_url to continue to Njila AI.',
    ]);
}

if ($action !== 'context') {
    njila_json(['success' => false, 'error' => 'Invalid action.'], 400);
}

$periodMode = getStudentProgramPeriodMode($db, $sid);
$payload = [
    'success' => true,
    'provider' => [
        'name' => 'Njila AI',
        'url' => NJILA_LAUNCH_URL,
    ],
    'launch_url' => NJILA_LAUNCH_URL,
    'student' => $student,
    'academic' => [
        'program' => array_merge(njila_fetch_program($db, $sid), [
            'period_mode' => $periodMode,
        ]),
        'registration' => njila_fetch_registration($db, $sid),
        'period_mode' => $periodMode,
        'period_label' => wuc_period_label_from_structure($periodMode),
        'period_label_short' => wuc_period_short_label_from_structure($periodMode),
    ],
    'courses' => njila_fetch_courses($db, $sid),
    'short_courses' => njila_fetch_short_courses($db, $sid),
    'generated_at' => date(DATE_ATOM),
];

audit_log($db, $sid, 'njila.context.viewed', ['provider' => 'njila.ai']);
njila_json($payload);
