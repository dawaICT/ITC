<?php
/**
 * Njila student connector API.
 *
 * External origin: https://njila.ai/#/
 * Browser origins do not include hash fragments, so the CORS origin is
 * https://njila.ai.
 *
 * Endpoints:
 * - GET /wucportal/api/njila_students.php
 * - GET /wucportal/api/njila_students.php?action=me
 * - GET /wucportal/api/njila_students.php?action=student&sid=STUDENT_ID
 *
 * Server-to-server student lookup requires WUC_NJILA_API_KEY via:
 * - X-Api-Key: <key>
 * - Authorization: Bearer <key>
 */

declare(strict_types=1);

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/schema_helpers.php';
require_once __DIR__ . '/../includes/api_auth.php';

wuc_api_start_session('njila-connector-api');

const NJILA_URL = 'https://njila.ai/#/';
const NJILA_ALLOWED_ORIGIN = 'https://njila.ai';

njila_apply_cors();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    njila_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

$action = strtolower(trim((string)($_GET['action'] ?? 'status')));

try {
    switch ($action) {
        case '':
        case 'status':
            njila_json([
                'success' => true,
                'service' => 'ITC Portal Njila student connector',
                'version' => '1.0',
                'njila_url' => NJILA_URL,
                'students_url' => njila_portal_url('students/'),
                'endpoints' => [
                    'GET ?action=me' => 'Return the current logged-in student connection payload.',
                    'GET ?action=student&sid={SID}' => 'Return a student connection payload using WUC_NJILA_API_KEY.',
                ],
            ]);

        case 'me':
            $sid = trim((string)($_SESSION['Sid'] ?? ''));
            if ($sid === '') {
                njila_json(['success' => false, 'message' => 'Student session required'], 401);
            }

            $payload = njila_student_payload($db, $sid);
            if ($payload === null) {
                njila_json(['success' => false, 'message' => 'Student not found'], 404);
            }

            njila_json(['success' => true, 'data' => $payload]);

        case 'student':
            njila_require_api_key();

            $sid = trim((string)($_GET['sid'] ?? $_GET['SID'] ?? ''));
            if ($sid === '' || !preg_match('/^[A-Za-z0-9._-]{2,50}$/', $sid)) {
                njila_json(['success' => false, 'message' => 'Valid sid parameter required'], 400);
            }

            $payload = njila_student_payload($db, $sid);
            if ($payload === null) {
                njila_json(['success' => false, 'message' => 'Student not found'], 404);
            }

            njila_json(['success' => true, 'data' => $payload]);

        default:
            njila_json(['success' => false, 'message' => 'Unknown action'], 404);
    }
} catch (Throwable $e) {
    error_log('Njila student API error: ' . $e->getMessage());
    njila_json(['success' => false, 'message' => 'Internal server error'], 500);
}

function njila_apply_cors(): void
{
    if (headers_sent()) {
        return;
    }

    $origin = rtrim((string)($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
    header('Content-Type: application/json; charset=utf-8');
    header('Vary: Origin');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if ($origin === NJILA_ALLOWED_ORIGIN) {
        header('Access-Control-Allow-Origin: ' . NJILA_ALLOWED_ORIGIN);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, Authorization');
        header('Access-Control-Max-Age: 600');
    }
}

function njila_json(array $payload, int $statusCode = 200): void
{
    wuc_json_response($payload, $statusCode);
}

function njila_expected_api_key(): string
{
    $key = getenv('WUC_NJILA_API_KEY');
    if (is_string($key) && trim($key) !== '') {
        return trim($key);
    }

    if (defined('NJILA_API_KEY')) {
        return trim((string)constant('NJILA_API_KEY'));
    }

    return '';
}

function njila_request_api_key(): string
{
    $key = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($key !== '') {
        return trim($key);
    }

    $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function njila_require_api_key(): void
{
    $expected = njila_expected_api_key();
    if ($expected === '') {
        njila_json(['success' => false, 'message' => 'Njila API key is not configured'], 503);
    }

    if (!hash_equals($expected, njila_request_api_key())) {
        njila_json(['success' => false, 'message' => 'Unauthorized'], 401);
    }
}

function njila_portal_url(string $path = ''): string
{
    $base = rtrim(wuc_public_base_url(), '/') . WUC_APP_BASE_PATH . '/';
    return $base . ltrim($path, '/');
}

function njila_student_payload(mysqli $db, string $sid): ?array
{
    $student = njila_get_student($db, $sid);
    if ($student === null) {
        return null;
    }

    $program = njila_get_student_program($db, $sid);
    $registration = njila_get_latest_registration($db, $sid);
    $courseSummary = njila_get_course_summary($db, $sid);

    return [
        'student' => $student,
        'program' => $program,
        'latest_registration' => $registration,
        'course_summary' => $courseSummary,
        'links' => [
            'students_home' => njila_portal_url('students/'),
            'course_registration' => njila_portal_url('students/courseReg.php'),
            'profile' => njila_portal_url('students/editProfile.php'),
            'njila' => NJILA_URL,
        ],
        'generated_at' => gmdate('c'),
    ];
}

function njila_get_student(mysqli $db, string $sid): ?array
{
    $cols = wuc_table_columns($db, 'students');
    $sidCol = $cols['sid'] ?? $cols['student_id'] ?? null;
    if ($sidCol === null) {
        throw new RuntimeException('students SID column not found');
    }

    $select = [
        "`{$sidCol}` AS sid",
        njila_select_or_null($cols, ['Fname', 'fname', 'first_name'], 'first_name'),
        njila_select_or_null($cols, ['Lname', 'lname', 'last_name'], 'last_name'),
        njila_select_or_null($cols, ['email', 'Email'], 'email'),
        njila_select_or_null($cols, ['mobile', 'phone', 'Phone'], 'mobile'),
        njila_select_or_null($cols, ['status', 'Status'], 'status'),
        njila_select_or_null($cols, ['profile_image'], 'profile_image'),
    ];

    $stmt = $db->prepare('SELECT ' . implode(', ', $select) . " FROM students WHERE `{$sidCol}` = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare student lookup');
    }
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $firstName = (string)($row['first_name'] ?? '');
    $lastName = (string)($row['last_name'] ?? '');
    $profileImage = trim((string)($row['profile_image'] ?? ''));
    if ($profileImage !== '' && strpos($profileImage, '/') === false && strpos($profileImage, '\\') === false) {
        $profileImage = 'uploads/profile/' . $profileImage;
    }

    return [
        'sid' => (string)$row['sid'],
        'first_name' => $firstName,
        'last_name' => $lastName,
        'full_name' => trim($firstName . ' ' . $lastName),
        'email' => (string)($row['email'] ?? ''),
        'mobile' => (string)($row['mobile'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'profile_image_url' => $profileImage !== '' ? njila_portal_url(ltrim($profileImage, '/')) : null,
    ];
}

function njila_get_student_program(mysqli $db, string $sid): ?array
{
    $spCols = wuc_table_columns($db, 'student_program');
    $sidCol = $spCols['sid'] ?? $spCols['student_id'] ?? null;
    $programCol = $spCols['program_code'] ?? null;
    if ($sidCol === null || $programCol === null) {
        return null;
    }

    $orderCol = $spCols['id'] ?? $programCol;
    $select = [
        "sp.`{$programCol}` AS program_code",
        njila_select_or_null($spCols, ['status'], 'program_status', 'sp'),
        njila_select_or_null($spCols, ['academic_year'], 'academic_year', 'sp'),
        njila_select_or_null($spCols, ['term'], 'term', 'sp'),
        njila_select_or_null($spCols, ['intake'], 'intake', 'sp'),
        njila_select_or_null($spCols, ['mode'], 'mode', 'sp'),
    ];

    $programsCols = wuc_table_columns($db, 'programs');
    $canJoinPrograms = isset($programsCols['program_code']);
    if ($canJoinPrograms) {
        $select[] = njila_select_or_null($programsCols, ['program_name'], 'program_name', 'p');
        $select[] = njila_select_or_null($programsCols, ['program_type'], 'program_type', 'p');
        $select[] = njila_select_or_null($programsCols, ['study_mode'], 'study_mode', 'p');
        $select[] = njila_select_or_null($programsCols, ['period_mode'], 'period_mode', 'p');
    } else {
        $select[] = "NULL AS program_name";
        $select[] = "NULL AS program_type";
        $select[] = "NULL AS study_mode";
        $select[] = "NULL AS period_mode";
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM student_program sp ';
    if ($canJoinPrograms) {
        $sql .= "LEFT JOIN programs p ON p.`{$programsCols['program_code']}` = sp.`{$programCol}` ";
    }
    $sql .= "WHERE sp.`{$sidCol}` = ? ORDER BY sp.`{$orderCol}` DESC LIMIT 1";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare program lookup');
    }
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function njila_get_latest_registration(mysqli $db, string $sid): ?array
{
    $cols = wuc_table_columns($db, 'semester_registration');
    $sidCol = $cols['student_id'] ?? $cols['sid'] ?? null;
    if ($sidCol === null) {
        return null;
    }

    $orderCol = $cols['id'] ?? $sidCol;
    $select = [
        njila_select_or_null($cols, ['id'], 'id'),
        njila_select_or_null($cols, ['program_code'], 'program_code'),
        njila_select_or_null($cols, ['semester'], 'semester'),
        njila_select_or_null($cols, ['year_of_study', 'Year', 'year'], 'year_of_study'),
        njila_select_or_null($cols, ['academic_year'], 'academic_year'),
        njila_select_or_null($cols, ['period_type'], 'period_type'),
        njila_select_or_null($cols, ['financial_status'], 'financial_status'),
        njila_select_or_null($cols, ['date_registered', 'created_at'], 'registered_at'),
    ];

    $stmt = $db->prepare('SELECT ' . implode(', ', $select) . " FROM semester_registration WHERE `{$sidCol}` = ? ORDER BY `{$orderCol}` DESC LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare registration lookup');
    }
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function njila_get_course_summary(mysqli $db, string $sid): array
{
    $cols = wuc_table_columns($db, 'course_registration');
    $sidCol = $cols['sid'] ?? $cols['student_id'] ?? null;
    if ($sidCol === null) {
        return ['registered_courses' => 0, 'active_courses' => 0];
    }

    $activeSql = isset($cols['is_active']) ? 'SUM(CASE WHEN `is_active` = 1 THEN 1 ELSE 0 END)' : 'COUNT(*)';
    $stmt = $db->prepare("SELECT COUNT(*) AS registered_courses, {$activeSql} AS active_courses FROM course_registration WHERE `{$sidCol}` = ?");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare course summary lookup');
    }
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return [
        'registered_courses' => (int)($row['registered_courses'] ?? 0),
        'active_courses' => (int)($row['active_courses'] ?? 0),
    ];
}

function njila_select_or_null(array $columns, array $candidates, string $alias, string $tableAlias = ''): string
{
    foreach ($candidates as $candidate) {
        $key = strtolower((string)$candidate);
        if (isset($columns[$key])) {
            $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';
            return "{$prefix}`{$columns[$key]}` AS `{$alias}`";
        }
    }

    return "NULL AS `{$alias}`";
}
