<?php
/**
 * Portal search suggest endpoint (Sprint 8).
 *
 * GET ?q=<term>            → grouped role-scoped results + recent searches
 * GET ?q=&recent=1         → recent searches only
 *
 * Staff sessions search all entity types; student sessions only programmes
 * and courses. Read-only, so no CSRF needed; identity comes from the session.
 */

require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/portal_search.php';

wuc_api_start_session('search-suggest');
wuc_guard_sync_session_aliases(['staff_id', 'user_id']);

$searchUserId = trim((string)($_SESSION['staff_id'] ?? ''));
$searchScope = 'staff';
$searchRole = trim((string)($_SESSION['role'] ?? 'staff'));
if ($searchUserId === '') {
    $searchUserId = trim((string)($_SESSION['Sid'] ?? ''));
    $searchScope = 'student';
    $searchRole = 'student';
}
if ($searchUserId === '' || !preg_match('/^[A-Za-z0-9\/\-_]+$/', $searchUserId)) {
    wuc_json_response([
        'success' => false,
        'message' => 'Session expired or invalid.',
        'session_expired' => true,
    ], 401);
}

$query = trim((string)($_GET['q'] ?? ''));

try {
    if ($query === '' || isset($_GET['recent'])) {
        wuc_json_response([
            'success' => true,
            'query' => $query,
            'total' => 0,
            'groups' => new stdClass(),
            'recent' => wuc_search_recent_queries($db, $searchUserId),
            'suggestions' => [],
        ]);
    }

    $result = wuc_portal_search($db, $query, ['scope' => $searchScope]);
    wuc_search_log_recent($db, $searchUserId, $searchRole, $query, (int)$result['total']);

    wuc_json_response([
        'success' => true,
        'query' => $result['query'],
        'total' => $result['total'],
        'groups' => $result['groups'] ?: new stdClass(),
        'recent' => wuc_search_recent_queries($db, $searchUserId),
        'suggestions' => $result['suggestions'],
    ]);
} catch (Throwable $e) {
    error_log('api/search_suggest.php failed: ' . $e->getMessage());
    wuc_json_error('Search is temporarily unavailable.', 500);
}
