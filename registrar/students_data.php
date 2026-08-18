<?php
/**
 * Paginated, searchable JSON feed for the registrar student roster.
 *
 * Performance: previously students_by_admin.php ran `SELECT * FROM students
 * JOIN ...` with no limit, rendered every row into HTML, and let client-side
 * DataTables paginate in the browser — the entire students table (all columns)
 * was serialised into every page load. This endpoint returns only the requested
 * page of rows and only the columns the table actually displays, so the wire
 * payload and DB work scale with the page size, not the table size.
 *
 * Auth mirrors search_student.php: Registrar portal access (registrar OR
 * systems_admin via canAccessRegistrar()). All user input is bound via
 * prepared statements.
 */

require_once dirname(__DIR__) . '/config/auth_check.php';
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/portal_access.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
syncLegacyStaffSessionKey();

$staffId = trim((string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? ''));
if ($staffId === '') {
    authRedirectToLogin();
}

hydrateStaffRolesFromDatabase($staffId);
wuc_resolve_session_user_id($db);

if (!canAccessRegistrar()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Access denied. Registrar or administrator access is required.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$page    = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 25;
$perPage = max(5, min(100, $perPage)); // clamp to a sane window
$search  = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$offset  = ($page - 1) * $perPage;

$where  = '';
$types  = '';
$params = [];
if ($search !== '') {
    $where = " WHERE (s.SID LIKE ? OR s.Fname LIKE ? OR s.Lname LIKE ?
                      OR CONCAT(s.Fname, ' ', s.Lname) LIKE ? OR p.program_name LIKE ?)";
    $like   = '%' . $search . '%';
    $types  = 'sssss';
    $params = [$like, $like, $like, $like, $like];
}

$baseFrom = " FROM students s
              INNER JOIN student_program sp ON s.SID = sp.Sid
              INNER JOIN programs p ON sp.program_code = p.program_code";

// Total row count for pagination controls.
$total = 0;
if ($stmt = $db->prepare("SELECT COUNT(*) AS c" . $baseFrom . $where)) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
}

// One page of rows — only the columns the roster table renders.
$rows    = [];
$dataSql = "SELECT s.SID, s.Fname, s.Lname, s.sex, p.program_name, sp.intake, sp.mode"
    . $baseFrom . $where
    . " ORDER BY s.Lname ASC, s.Fname ASC
        LIMIT ? OFFSET ?";
$dataTypes  = $types . 'ii';
$dataParams = array_merge($params, [$perPage, $offset]);
if ($stmt = $db->prepare($dataSql)) {
    $stmt->bind_param($dataTypes, ...$dataParams);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
}

echo json_encode([
    'ok'          => true,
    'page'        => $page,
    'per_page'    => $perPage,
    'total'       => $total,
    'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
    'offset'      => $offset,
    'rows'        => $rows,
]);
