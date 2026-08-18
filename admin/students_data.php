<?php
/**
 * Paginated JSON feed for admin/students_by_admin.php.
 * Mirrors registrar/students_data.php but keeps the admin LEFT JOIN semantics
 * (students without an active programme still appear) and admin auth.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/auth_check.php';
checkAdminAuth();
require_once dirname(__DIR__) . '/db/connect.php';

header('Content-Type: application/json; charset=utf-8');

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
$perPage = max(5, min(100, $perPage));
$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$offset = ($page - 1) * $perPage;

$where = '';
$types = '';
$params = [];
if ($search !== '') {
    $where = " WHERE (s.SID LIKE ? OR s.Fname LIKE ? OR s.Lname LIKE ?
                      OR CONCAT(s.Fname, ' ', s.Lname) LIKE ?
                      OR COALESCE(p.program_name, sc.course_name, '') LIKE ?)";
    $like = '%' . $search . '%';
    $types = 'sssss';
    $params = [$like, $like, $like, $like, $like];
}

$baseFrom = " FROM students s
              LEFT JOIN student_program sp ON s.SID = sp.Sid
              LEFT JOIN programs p ON COALESCE(sp.program_code, s.program) = p.program_code
              LEFT JOIN short_courses sc ON COALESCE(sp.program_code, s.program) = sc.course_code";

// Page over distinct students, then attach one programme row per student so
// multi-programme joins cannot explode the page size.
$total = 0;
$countSql = $search !== ''
    ? 'SELECT COUNT(DISTINCT s.SID) AS c' . $baseFrom . $where
    : 'SELECT COUNT(*) AS c FROM students s';
if ($stmt = $db->prepare($countSql)) {
    if ($search !== '' && $types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
}

$rows = [];
$idSql = 'SELECT DISTINCT s.SID' . $baseFrom . $where . ' ORDER BY s.SID ASC LIMIT ? OFFSET ?';
$idTypes = $types . 'ii';
$idParams = array_merge($params, [$perPage, $offset]);
$pageIds = [];
if ($stmt = $db->prepare($idSql)) {
    $stmt->bind_param($idTypes, ...$idParams);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $pageIds[] = (string)$row['SID'];
    }
    $stmt->close();
}

if ($pageIds !== []) {
    $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
    $detailSql = "SELECT s.SID, s.Fname, s.Lname, s.sex, s.email,
                         COALESCE(sp.intake, s.intake) AS intake,
                         COALESCE(sp.program_code, s.program) AS program_code,
                         COALESCE(p.program_name, sc.course_name) AS program_name
                  FROM students s
                  LEFT JOIN student_program sp ON s.SID = sp.Sid
                  LEFT JOIN programs p ON COALESCE(sp.program_code, s.program) = p.program_code
                  LEFT JOIN short_courses sc ON COALESCE(sp.program_code, s.program) = sc.course_code
                  WHERE s.SID IN ($placeholders)
                  ORDER BY s.SID ASC, sp.id DESC";
    if ($stmt = $db->prepare($detailSql)) {
        $detailTypes = str_repeat('s', count($pageIds));
        $stmt->bind_param($detailTypes, ...$pageIds);
        $stmt->execute();
        $res = $stmt->get_result();
        $seen = [];
        while ($row = $res->fetch_assoc()) {
            $sid = (string)$row['SID'];
            if (isset($seen[$sid])) {
                continue; // keep newest programme assignment per student
            }
            $seen[$sid] = true;
            $rows[] = $row;
        }
        $stmt->close();
    }
}

echo json_encode([
    'ok' => true,
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'total_pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1,
    'offset' => $offset,
    'rows' => $rows,
], JSON_UNESCAPED_UNICODE);
