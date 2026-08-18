<?php
// session_handler.php calls initializeSession() on include — do not start session manually here
require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';

// Auth gate — nav.php only runs after the AJAX block, so guard endpoints here
if (!isAdminAuthenticated()) {
    if (isset($_GET['ajax']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }
    header('Location: /wucportal/staff_login.php');
    exit;
}

// One-time table setup per session (avoid running DDL on every typeahead request).
// The app user (wucportal_app) is DML-only, so a raw CREATE TABLE throws
// "CREATE command denied" under mysqli STRICT mode — even with IF NOT EXISTS, and
// even when the table already exists, because MySQL checks the CREATE privilege
// BEFORE it evaluates IF NOT EXISTS. That uncaught exception is what produced the
// generic "We could not load this page" error. wuc_ensure_tables()
// (includes/schema_guard.php, already loaded by db/connect.php) checks
// information_schema first and only issues DDL when the table is genuinely
// missing, logging instead of throwing on a privilege failure.
// user_id is VARCHAR because the session stores staff_id / student SID strings.
if (empty($_SESSION['search_history_ready'])) {
    wuc_ensure_tables($db, ["CREATE TABLE IF NOT EXISTS search_history (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id      VARCHAR(50) NOT NULL,
        student_id   VARCHAR(50) NOT NULL,
        student_name VARCHAR(150) NOT NULL,
        searched_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_searched (user_id, searched_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"]);
    $_SESSION['search_history_ready'] = true;
}

// Initialize CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// AJAX API Handler
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $response = ['success' => false, 'error' => null];
    
    try {
        if (isset($_GET['action']) && $_GET['action'] === 'get_recent') {
            $response['success'] = true;
            $response['recent'] = getRecentSearches($db);
        } elseif (isset($_GET['q'])) {
            // Broad search returning list
            $term = trim($_GET['q']);
            if (strlen($term) < 3) {
                throw new Exception('Search term must be at least 3 characters');
            }
            
            // Allow alphanumeric, spaces, dots, hyphens, and @
            $termClean = preg_replace('/[^a-zA-Z0-9\s\-@.]/', '', $term);
            $likeTerm = "%{$termClean}%";
            // NRC may be typed with or without slashes/spaces — compare against a
            // normalised (digits/letters only) copy of the stored value.
            $nrcLike = '%' . preg_replace('/[^a-zA-Z0-9]/', '', $term) . '%';

            $query = "SELECT s.SID, s.Fname, s.Lname, s.email, s.mobile, s.profile_image,
                      sp.program_code, p.program_name
                      FROM students s
                      LEFT JOIN student_program sp ON s.SID = sp.Sid
                      LEFT JOIN programs p ON sp.program_code = p.program_code
                      WHERE s.SID LIKE ? OR s.Fname LIKE ? OR s.Lname LIKE ?
                         OR CONCAT(s.Fname, ' ', s.Lname) LIKE ?
                         OR s.email LIKE ?
                         OR REPLACE(REPLACE(s.nrc_pass, '/', ''), ' ', '') LIKE ?
                      GROUP BY s.SID
                      LIMIT 10";

            $stmt = $db->prepare($query);
            if (!$stmt) {
                throw new Exception('Database error: ' . $db->error);
            }
            $stmt->bind_param('ssssss', $likeTerm, $likeTerm, $likeTerm, $likeTerm, $likeTerm, $nrcLike);
            $stmt->execute();
            $result = $stmt->get_result();

            // Send plain values — Vue auto-escapes in {{ }} (double-escaping mangles "&" / "'")
            $students = [];
            while ($row = $result->fetch_assoc()) {
                $students[] = [
                    'SID' => $row['SID'],
                    'Fname' => $row['Fname'],
                    'Lname' => $row['Lname'],
                    'email' => $row['email'] ?? '',
                    'profile_image' => getProfileImagePath($row['profile_image'] ?? ''),
                    'program_name' => $row['program_name'] ?? ''
                ];
            }
            $stmt->close();
            
            $response['success'] = true;
            $response['students'] = $students;
            
        } elseif (isset($_GET['SID'])) {
            // Specific single student search (Existing logic)
            $sid = trim($_GET['SID']);
             // Validation
            if (empty($sid)) {
                throw new Exception('Search term is required');
            }
            
            if (strlen($sid) < 3) {
                throw new Exception('Search term must be at least 3 characters');
            }
            
            // Normalised NRC (digits/letters only) so a number typed with or
            // without slashes/spaces still matches the stored value, and a
            // name LIKE term so the Search button resolves names too — not just
            // exact IDs (the typeahead already matches names, so the button now
            // behaves consistently).
            $nrcNorm = preg_replace('/[^a-zA-Z0-9]/', '', $sid);
            $likeTerm = '%' . preg_replace('/[^a-zA-Z0-9\s\-@.]/', '', $sid) . '%';

            // Search query with program info. Real transfer columns live on `students`
            // (is_transfer / transfer_from / transfer_credits); aliasing them here avoids
            // a duplicate-column collision with s.* in the result set.
            // match_rank keeps an EXACT id/nrc/email hit ahead of a fuzzy name hit.
            $query = "SELECT s.SID, s.Fname, s.Lname, s.sex, s.nrc_pass, s.country,
                             s.email, s.mobile, s.profile_image,
                             s.is_transfer, s.transfer_from AS previous_institution,
                             s.transfer_credits AS credits_transferred,
                             sp.program_code, sp.intake, sp.mode,
                             p.program_name,
                             (CASE WHEN s.SID = ? OR s.email = ?
                                        OR REPLACE(REPLACE(s.nrc_pass, '/', ''), ' ', '') = ?
                                   THEN 0 ELSE 1 END) AS match_rank
                      FROM students s
                      LEFT JOIN student_program sp ON s.SID = sp.Sid
                      LEFT JOIN programs p ON sp.program_code = p.program_code
                      WHERE s.SID = ? OR s.email = ?
                         OR REPLACE(REPLACE(s.nrc_pass, '/', ''), ' ', '') = ?
                         OR s.Fname LIKE ? OR s.Lname LIKE ?
                         OR CONCAT(s.Fname, ' ', s.Lname) LIKE ?
                      ORDER BY match_rank ASC
                      LIMIT 1";

            $stmt = $db->prepare($query);
            if (!$stmt) {
                throw new Exception('Database error: ' . $db->error);
            }

            $stmt->bind_param(
                'sssssssss',
                $sid, $sid, $nrcNorm,          // match_rank
                $sid, $sid, $nrcNorm,          // exact WHERE
                $likeTerm, $likeTerm, $likeTerm // name WHERE
            );
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception('Student not found');
            }
            
            $student = $result->fetch_assoc();
            $stmt->close();
            
            $profileImage = getProfileImagePath($student['profile_image'] ?? '');
            
            $response['success'] = true;
            $response['student'] = [
                'SID' => $student['SID'],
                'Fname' => $student['Fname'],
                'Lname' => $student['Lname'],
                'name' => $student['Fname'] . ' ' . $student['Lname'],
                'email' => $student['email'] ?? '',
                'mobile' => $student['mobile'] ?? '',
                'sex' => $student['sex'],
                'program_name' => $student['program_name'] ?? 'Not Assigned',
                'intake' => $student['intake'] ?? 'N/A',
                'mode' => $student['mode'] ?? 'N/A',
                'is_transfer' => (bool)($student['is_transfer'] ?? false),
                'previous_institution' => $student['previous_institution'] ?? '',
                'credits_transferred' => (int)($student['credits_transferred'] ?? 0),
                'profile_image' => $profileImage,
                'status' => determineStudentStatus($student),
                'status_class' => getStatusClass($student)
            ];
            
            addToRecentSearches($db, $response['student']);
        } else {
             throw new Exception('Missing search parameters');
        }
        
    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
        // Don't set 404/400 for broad search to avoid console errors in UI typeahead
        if (isset($_GET['SID'])) {
             http_response_code($e->getMessage() === 'Student not found' ? 404 : 400);
        }
    }
    
    echo json_encode($response);
    exit;
}

// Save recent search via AJAX (Fallback/Explicit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate CSRF
    if (!isset($input['csrf_token']) || !hash_equals($csrf_token, $input['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }
    
    if (isset($input['action']) && $input['action'] === 'save_recent' && isset($input['student'])) {
        // We already save on GET success, but this endpoint can be used if we want client-side control
        // Re-implement or just return success as we do it on server side primarily now.
        // But for compatibility with the frontend code provided:
        addToRecentSearches($db, $input['student']); // Use the DB function
        
        echo json_encode(['success' => true]);
        exit;
    }
}

// Helper functions
function getProfileImagePath(?string $filename): string {
    $fallback = '/wucportal/admissions/images/avatar.png';

    if (empty($filename)) {
        return $fallback;
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExtensions) || !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename)) {
        return $fallback;
    }

    $path = dirname(__DIR__) . '/uploads/profile/' . $filename;
    return file_exists($path) ? "/wucportal/uploads/profile/{$filename}" : $fallback;
}

function determineStudentStatus(array $student): string {
    if ($student['is_transfer'] ?? false) return 'Transfer Student';
    if (!empty($student['program_name'])) return 'Active Student';
    return 'Pending Enrollment';
}

function getStatusClass(array $student): string {
    if ($student['is_transfer'] ?? false) return 'transfer';
    if (!empty($student['program_name'])) return 'active';
    return 'pending';
}

function resolveUserId(mysqli $db): ?string {
    // Auth is keyed on staff_id / index===admin (see isAdminAuthenticated), but
    // some login flows set user_id and others don't, and nav.php — which would
    // backfill it — runs *after* the AJAX handlers. Fall back to the canonical
    // auth identities so recent-search history persists for every valid session.
    $uid = $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? $_SESSION['student_id'] ?? null;
    return ($uid !== null && $uid !== '') ? (string)$uid : null;
}

function addToRecentSearches(mysqli $db, array $student): void {
    $userId = resolveUserId($db);
    if ($userId === null) return;

    $sid = $student['SID'];
    
    // Check if searched recently (1 hour)
    $check = $db->prepare("SELECT 1 FROM search_history WHERE user_id = ? AND student_id = ? AND searched_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    if ($check) {
        $check->bind_param('ss', $userId, $sid);
        $check->execute();
        
        if ($check->get_result()->num_rows === 0) {
            $insert = $db->prepare("INSERT INTO search_history (user_id, student_id, student_name, searched_at) VALUES (?, ?, ?, NOW())");
            if ($insert) {
                $name = $student['name'] ?? ($student['Fname'] . ' ' . $student['Lname']);
                $insert->bind_param('sss', $userId, $sid, $name);
                $insert->execute();
                $insert->close();
            }
        }
        $check->close();
    }
}

// Get recent searches from database
function getRecentSearches(mysqli $db): array {
    $userId = resolveUserId($db);
    if ($userId === null) return [];

    $stmt = $db->prepare("SELECT student_id as sid, student_name as name, searched_at as time
                          FROM search_history
                          WHERE user_id = ?
                          ORDER BY searched_at DESC
                          LIMIT 5");
    if (!$stmt) return [];
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $searches = [];
    while ($row = $result->fetch_assoc()) {
        $searches[] = [
            'sid' => $row['sid'],
            'name' => $row['name'],
            'time' => strtotime($row['time'])
        ];
    }
    $stmt->close();
    return $searches;
}

$page_title = 'Search Student';

// Determine active nav: default to admissions unless requested from admin
$is_admin_nav = false;
if (isset($_GET['from']) && $_GET['from'] === 'admin') {
    $is_admin_nav = true;
} elseif (isset($_SESSION['role']) && !in_array($_SESSION['role'], ['admission_officer'])) {
    // If user's role is not admissions officer, default to admin nav
    $is_admin_nav = true;
}

if ($is_admin_nav) {
    require_once __DIR__ . '/../admin/includes/nav.php';
} else {
    require __DIR__ . '/includes/nav.php';
}

// Get recent searches
$recent_searches = getRecentSearches($db);
?>

<style>
/* Follows the flat admin panel conventions used by admin/timetable_settings.php:
   white panels, 1px #e5e7eb borders, 8px radius, no gradients/shadows. */
:root {
    --primary: var(--bs-primary, #0d6efd);
    --primary-dark: #0a58ca;
    --secondary: #64748b;
    --success: #1cc88a;
    --info: #36b9cc;
    --warning: #f6c23e;
    --danger: #e74a3b;
    --light: #f8fafc;
    --dark: #1e293b;
    --white: #fff;
    --shadow: none;
    --shadow-sm: none;
    --radius: 8px;
    --radius-sm: 6px;
}

/* Main Container */
.search-container {
    max-width: 900px;
    margin: 0 auto;
    padding: 0;
}

/* Card Styles — .portal-dashboard prefix matches the specificity of the
   .portal-dashboard .search-card rule in portal-dashboard.css so this
   later-loaded block wins. */
.portal-dashboard .search-card {
    background: var(--white);
    border: 1px solid #e5e7eb;
    border-radius: var(--radius);
    box-shadow: none;
    padding: 0;
    overflow: hidden;
    position: relative;
    width: 100%;
    margin-bottom: 18px;
}

.card-bg-logo {
    display: none; /* watermark is not part of the flat admin panel style */
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    opacity: 0.03;
    pointer-events: none;
    z-index: 0;
}

.card-bg-logo img {
    width: 400px;
    height: auto;
    filter: grayscale(100%);
}

.card-header-custom {
    background: var(--white);
    color: var(--dark);
    padding: 14px 16px;
    border-bottom: 1px solid #e5e7eb;
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-header-custom h5 {
    font-weight: 600;
    font-size: 1.1rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.card-header-custom h5 i {
    color: var(--primary);
}

.card-body-custom {
    padding: 16px;
    position: relative;
    z-index: 1;
}

/* Search Form */
.search-form-group {
    margin-bottom: 1.5rem;
}

.search-label {
    font-weight: 600;
    color: var(--dark);
    margin-bottom: 0.75rem;
    display: block;
    font-size: 0.95rem;
}

.search-input-group {
    display: flex;
    box-shadow: var(--shadow-sm);
    border-radius: var(--radius-sm);
    overflow: hidden;
}

.search-input-group .input-group-text {
    background: var(--light);
    border: 1px solid #e5e7eb;
    border-right: none;
    padding: 0 1rem;
    color: var(--secondary);
    display: flex;
    align-items: center;
}

.search-input {
    flex: 1;
    border: 1px solid #e5e7eb;
    border-left: none;
    border-right: none;
    padding: .75rem 1rem;
    font-size: 1rem;
    transition: all 0.2s;
}

.search-input:focus {
    outline: none;
    border-color: var(--primary);
}

.search-btn {
    background: var(--primary);
    color: var(--white);
    border: none;
    padding: 0 1.5rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
}

.search-btn:hover:not(:disabled) {
    background: var(--primary-dark);
}

.search-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.search-hint {
    font-size: 0.85rem;
    color: var(--secondary);
    margin-top: 0.5rem;
}

/* Validation & Alerts */
.validation-error {
    background: #fff3cd;
    border: 1px solid #ffc107;
    color: #856404;
    padding: 0.75rem 1rem;
    border-radius: var(--radius-sm);
    margin-top: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.alert-custom {
    padding: 1rem;
    border-radius: var(--radius-sm);
    margin-top: 1rem;
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}

.alert-danger-custom {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.alert-info-custom {
    background: #d1ecf1;
    border: 1px solid #bee5eb;
    color: #0c5460;
}

/* Skeleton Loading */
.skeleton-loader {
    display: none;
    padding: 1.5rem 0;
}

.skeleton-loader.active {
    display: block;
}

.skeleton-line {
    height: 12px;
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: skeleton-loading 1.5s infinite;
    border-radius: 6px;
    margin-bottom: 12px;
}

.skeleton-line:last-child {
    margin-bottom: 0;
}

.skeleton-lg { height: 24px; width: 60%; }
.skeleton-md { height: 16px; width: 80%; }
.skeleton-sm { height: 12px; width: 40%; }

@keyframes skeleton-loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* Results Card */
.result-card {
    background: var(--white);
    border-radius: var(--radius-sm);
    border: 1px solid #e5e7eb;
    margin-top: 1.5rem;
    overflow: hidden;
    animation: slideUp 0.3s ease-out;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Profile Section */
.profile-section {
    display: flex;
    gap: 1.5rem;
    padding: 1.5rem;
    background: var(--light);
    border-bottom: 1px solid #e5e7eb;
}

@media (max-width: 576px) {
    .profile-section {
        flex-direction: column;
        text-align: center;
    }
    
    .profile-image-wrap {
        margin: 0 auto;
    }
}

.profile-image-wrap {
    position: relative;
    flex-shrink: 0;
}

.profile-img {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid var(--white);
    box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
}

.status-badge {
    position: absolute;
    bottom: 8px;
    right: 8px;
    padding: 0.35rem 0.75rem;
    border-radius: 2rem;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.status-active { background: var(--success); color: var(--white); }
.status-transfer { background: var(--info); color: var(--white); }
.status-pending { background: var(--warning); color: var(--dark); }

.profile-info h4 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 0.5rem;
}

.profile-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    color: var(--secondary);
    font-size: 0.95rem;
}

@media (max-width: 576px) {
    .profile-meta {
        justify-content: center;
    }
}

.profile-meta span {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Details Table */
.details-section h6 {
    background: var(--light);
    padding: 1rem 1.5rem;
    margin: 0;
    font-weight: 600;
    color: var(--dark);
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.details-table {
    width: 100%;
    margin: 0;
}

.details-table td {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #f0f0f0;
}

.details-table tr:last-child td {
    border-bottom: none;
}

.field-label {
    font-weight: 600;
    color: var(--secondary);
    width: 35%;
}

.badge-program {
    display: inline-flex;
    align-items: center;
    background: rgba(13, 110, 253, 0.1);
    color: var(--primary);
    padding: 0.5rem 1rem;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 0.875rem;
}

/* Action Buttons */
.action-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    padding: 1rem 1.5rem;
    background: var(--light);
    border-top: 1px solid #e5e7eb;
}

.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1.25rem;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 0.875rem;
    text-decoration: none;
    transition: all 0.2s;
    border: none;
    cursor: pointer;
}

.btn-action:hover {
    filter: brightness(0.95);
}

.btn-primary-action { background: var(--primary); color: var(--white); }
.btn-primary-action:hover { background: var(--primary-dark); color: var(--white); }

.btn-info-action { background: var(--info); color: var(--white); }
.btn-info-action:hover { background: #2c9faf; color: var(--white); }

.btn-secondary-action { background: var(--secondary); color: var(--white); }
.btn-secondary-action:hover { background: #717375; color: var(--white); }

.btn-outline-action { 
    background: transparent; 
    color: var(--secondary); 
    border: 1px solid #d1d3e2;
    margin-left: auto;
}

.btn-outline-action:hover {
    background: var(--light);
    color: var(--dark);
}

/* Recent Searches */
.recent-section {
    margin-top: 2rem;
    padding-top: 2rem;
    border-top: 1px solid #e5e7eb;
}

.recent-title {
    font-size: 0.875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--secondary);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.recent-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.recent-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1rem;
    background: var(--light);
    border: 1px solid #dbe3ef;
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    color: var(--dark);
    text-decoration: none;
    transition: all 0.2s;
    cursor: pointer;
}

.recent-chip:hover {
    background: var(--primary);
    color: var(--white);
    border-color: var(--primary);
}

.recent-chip small {
    opacity: 0.7;
}

/* Help Section */
.help-section {
    margin-top: 1.5rem;
    padding: 1.25rem;
    background: var(--light);
    border: 1px solid #e5e7eb;
    border-radius: var(--radius-sm);
    border-left: 4px solid var(--primary);
}

.help-section h6 {
    color: var(--dark);
    font-weight: 700;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.help-section ul {
    margin: 0;
    padding-left: 1.25rem;
    color: var(--secondary);
    font-size: 0.9rem;
}

.help-section li {
    margin-bottom: 0.5rem;
}

.help-section li:last-child {
    margin-bottom: 0;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem 1.5rem;
}

.empty-icon {
    font-size: 4rem;
    color: #d1d3e2;
    margin-bottom: 1rem;
}

.empty-state h5 {
    color: var(--dark);
    margin-bottom: 0.5rem;
}

.empty-state p {
    color: var(--secondary);
    margin-bottom: 1.5rem;
}

/* Utilities */
.text-primary-subtle { color: var(--primary) !important; }
.bg-primary-subtle { background-color: rgba(13, 110, 253, 0.1) !important; }
/* Typeahead Dropdown */
.cursor-pointer { cursor: pointer; }

.typeahead-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    z-index: 1050;
    background: var(--white);
    border: 1px solid #e5e7eb;
    border-radius: var(--radius-sm);
    box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
    max-height: 320px;
    overflow-y: auto;
}

.typeahead-item:last-child { border-bottom: 0 !important; }
.typeahead-item:hover { background-color: rgba(13, 110, 253, 0.08); }
.typeahead-item.bg-primary:hover { background-color: var(--primary); }

/* Keyboard Navigation */
.bg-primary.text-white .text-muted {
    color: rgba(255, 255, 255, 0.7) !important;
}

/* Focus States */
.search-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
}

/* Loading States */
.spinner-border-sm {
    width: 1rem;
    height: 1rem;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .search-container {
        margin: 1rem auto;
        padding: 0 0.5rem;
    }
    
    .search-card {
        border-radius: var(--radius-sm);
    }
    
    .card-body-custom {
        padding: 1.5rem;
    }
}
</style>

<div class="container-fluid px-4 py-4 portal-dashboard">
<div id="searchApp" class="search-container">
    <div class="search-card">
        <!-- Background Logo -->
        <div class="card-bg-logo">
            <img src="/wucportal/images/itc_logo.png" alt="ITC Logo">
        </div>
        
        <!-- Header -->
        <div class="card-header-custom">
            <h5>
                <i class="fas fa-search"></i>
                Student Search
            </h5>
            <a href="index.php" class="btn-close" aria-label="Close"></a>
        </div>
        
        <!-- Body -->
        <div class="card-body-custom">
            <!-- Search Form -->
            <div class="search-form-group">
                <label class="search-label" for="SID">Enter Student ID, Name, NRC or Email</label>
                <div class="search-input-wrap position-relative" ref="searchWrap">
                    <div class="search-input-group">
                        <span class="input-group-text">
                            <i class="fas fa-search"></i>
                        </span>
                        <input
                            type="text"
                            id="SID"
                            class="search-input"
                            v-model="searchQuery"
                            @input="handleInput"
                            @focus="handleFocus"
                            @keyup.enter="handleSearch"
                            @keydown="handleKeyDown"
                            placeholder="e.g., STU-2024-001 or 123456/10/1 or email@example.com"
                            autocomplete="off"
                            :disabled="isSearching"
                            aria-label="Search student by ID, NRC, or email"
                            aria-autocomplete="list"
                            aria-controls="search-results"
                            :aria-expanded="showDropdown ? 'true' : 'false'"
                            role="combobox"
                        >
                        <button
                            type="button"
                            class="search-btn"
                            @click="handleSearch"
                            :disabled="isSearching || !canSearch"
                        >
                            <span v-if="isSearching" class="spinner-border spinner-border-sm"></span>
                            <i v-else class="fas fa-search"></i>
                            <span>{{ isSearching ? 'Searching...' : 'Search' }}</span>
                        </button>
                    </div>

                    <!-- Typeahead Dropdown — sibling of input-group so overflow:hidden on the group can't clip it -->
                    <div
                        id="search-results"
                        v-if="showDropdown"
                        class="typeahead-dropdown"
                        role="listbox"
                    >
                        <div
                            v-for="(result, index) in searchResults"
                            :key="result.SID"
                            @click="selectResult(result)"
                            @mouseenter="selectedResultIndex = index"
                            :class="['typeahead-item p-2 border-bottom cursor-pointer', { 'bg-primary text-white': index === selectedResultIndex }]"
                            role="option"
                            :aria-selected="index === selectedResultIndex"
                        >
                            <div class="d-flex align-items-center">
                                <img :src="result.profile_image" alt="" class="rounded-circle me-2" style="width: 32px; height: 32px; object-fit: cover;" onerror="this.src='/wucportal/admissions/images/avatar.png'">
                                <div>
                                    <div class="fw-bold">{{ result.Fname }} {{ result.Lname }}</div>
                                    <div class="small" :class="index === selectedResultIndex ? 'text-white-50' : 'text-muted'">{{ result.SID }}</div>
                                    <div class="small" :class="index === selectedResultIndex ? 'text-white-50' : 'text-muted'" v-if="result.program_name">{{ result.program_name }}</div>
                                </div>
                            </div>
                        </div>
                        <div v-if="searchResults.length === 0" class="p-3 text-center text-muted small">
                            No students found
                        </div>
                    </div>
                </div>
                <div class="search-hint">
                    <i class="fas fa-info-circle me-1"></i>
                    Search by student ID, full name, NRC number (with or without slashes), or email address
                </div>
            </div>
            
            <!-- Validation Error -->
            <div v-if="validationError" class="validation-error">
                <i class="fas fa-exclamation-triangle"></i>
                {{ validationError }}
            </div>
            
            <!-- Skeleton Loading -->
            <div v-if="isSearching" class="skeleton-loader active">
                <div class="skeleton-line skeleton-lg"></div>
                <div class="skeleton-line skeleton-md"></div>
                <div class="skeleton-line skeleton-sm"></div>
                <div class="skeleton-line skeleton-md"></div>
            </div>
            
            <!-- Error Message -->
            <div v-if="error && !isSearching" class="alert-custom alert-danger-custom">
                <i class="fas fa-times-circle fa-lg"></i>
                <div>
                    <strong>{{ error }}</strong>
                    <div v-if="errorDetails" class="small mt-1 opacity-75">{{ errorDetails }}</div>
                </div>
            </div>
            
            <!-- Search Results -->
            <div v-if="student && !isSearching" class="result-card">
                <!-- Profile Header -->
                <div class="profile-section">
                    <div class="profile-image-wrap">
                        <img :src="student.profile_image" :alt="student.name" class="profile-img" onerror="this.src='/wucportal/admissions/images/avatar.png'">
                        <span class="status-badge" :class="'status-' + student.status_class">
                            {{ student.status }}
                        </span>
                    </div>
                    <div class="profile-info">
                        <h4>{{ student.name }}</h4>
                        <div class="profile-meta">
                            <span>
                                <i class="fas fa-id-card text-primary"></i>
                                {{ student.SID }}
                            </span>
                            <span v-if="student.email">
                                <i class="fas fa-envelope text-info"></i>
                                {{ student.email }}
                            </span>
                            <span v-if="student.mobile">
                                <i class="fas fa-phone text-success"></i>
                                {{ student.mobile }}
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Academic Details -->
                <div class="details-section">
                    <h6>
                        <i class="fas fa-graduation-cap"></i>
                        Academic Information
                    </h6>
                    <table class="details-table">
                        <tbody>
                            <tr>
                                <td class="field-label">Program</td>
                                <td>
                                    <span class="badge-program">
                                        <i class="fas fa-book me-2"></i>
                                        {{ student.program_name }}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="field-label">Intake</td>
                                <td>
                                    <i class="fas fa-calendar-alt me-2 text-muted"></i>
                                    {{ student.intake }}
                                </td>
                            </tr>
                            <tr>
                                <td class="field-label">Study Mode</td>
                                <td>
                                    <i class="fas fa-clock me-2 text-muted"></i>
                                    {{ student.mode }}
                                </td>
                            </tr>
                            <tr v-if="student.is_transfer">
                                <td class="field-label">Previous Institution</td>
                                <td>
                                    <i class="fas fa-university me-2 text-muted"></i>
                                    {{ student.previous_institution || 'N/A' }}
                                </td>
                            </tr>
                            <tr v-if="student.is_transfer">
                                <td class="field-label">Credits Transferred</td>
                                <td>
                                    <i class="fas fa-exchange-alt me-2 text-muted"></i>
                                    {{ student.credits_transferred }} credits
                                </td>
                            </tr>
                            <tr>
                                <td class="field-label">Gender</td>
                                <td>
                                    <i :class="['fas', genderIcon, 'me-2']"></i>
                                    {{ genderLabel }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Actions -->
                <div class="action-bar">
                    <a :href="'view_student.php?id=' + encodeURIComponent(student.SID)" class="btn-action btn-primary-action">
                        <i class="fas fa-eye"></i>
                        View Full Profile
                    </a>
                    <a :href="'editStudent.php?edit=' + encodeURIComponent(student.SID)" class="btn-action btn-info-action">
                        <i class="fas fa-edit"></i>
                        Edit
                    </a>
                    <a :href="'print_admission_letter.php?sid=' + encodeURIComponent(student.SID)" target="_blank" class="btn-action btn-secondary-action">
                        <i class="fas fa-print"></i>
                        Print Letter
                    </a>
                    <button @click="clearSearch" class="btn-action btn-outline-action">
                        <i class="fas fa-search"></i>
                        New Search
                    </button>
                </div>
            </div>
            
            <!-- Recent Searches -->
            <div v-if="recentSearches.length && !student && !isSearching && !error" class="recent-section">
                <div class="recent-title">
                    <i class="fas fa-history"></i>
                    Recent Searches
                </div>
                <div class="recent-chips">
                    <a 
                        v-for="recent in recentSearches" 
                        :key="recent.sid"
                        @click.prevent="searchFromHistory(recent.sid)"
                        class="recent-chip"
                    >
                        <i class="fas fa-clock"></i>
                        <span>{{ recent.name }}</span>
                        <small>({{ recent.sid }})</small>
                    </a>
                </div>
            </div>
            
            <!-- Help Section -->
            <div v-if="showHelp" class="help-section">
                <h6>
                    <i class="fas fa-lightbulb"></i>
                    Search Tips
                </h6>
                <ul>
                    <li>Check that you've entered the correct student ID</li>
                    <li>You can also search by NRC number or email address</li>
                    <li>Student IDs are typically in format: <strong>STU-YYYY-XXX</strong></li>
                    <li>If the student was recently admitted, try waiting a few minutes</li>
                    <li>Contact the registrar if you continue to have issues</li>
                </ul>
            </div>
        </div>
    </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/axios@1.4.0/dist/axios.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script>
const { createApp, ref, computed, onMounted, onBeforeUnmount, watch } = Vue;

createApp({
    setup() {
        // State
        const searchQuery = ref('');
        const isSearching = ref(false);
        const student = ref(null);
        const error = ref(null);
        const errorDetails = ref(null);
        const validationError = ref(null);
        const recentSearches = ref(<?= json_encode($recent_searches) ?>);
        const searchResults = ref([]);
        const showDropdown = ref(false);
        const selectedResultIndex = ref(-1);
        const searchWrap = ref(null);

        const csrfToken = '<?= $csrf_token ?>';

        // Computed
        const canSearch = computed(() => {
            const query = searchQuery.value.trim();
            return query.length >= 3;
        });

        const showHelp = computed(() => {
            return error.value && error.value.includes('not found');
        });

        const hasResults = computed(() => {
            return searchResults.value.length > 0;
        });

        const genderLabel = computed(() => {
            const s = student.value?.sex;
            if (s === 'M' || s === 'm') return 'Male';
            if (s === 'F' || s === 'f') return 'Female';
            return s ? s : 'Not specified';
        });

        const genderIcon = computed(() => {
            const s = student.value?.sex;
            if (s === 'M' || s === 'm') return 'fa-mars text-primary';
            if (s === 'F' || s === 'f') return 'fa-venus text-danger';
            return 'fa-genderless text-muted';
        });
        
        // Debounced search for typeahead
        let searchTimeout = null;
        let typeaheadRequestId = 0;

        const handleInput = () => {
            validationError.value = null;
            error.value = null;
            errorDetails.value = null;

            const query = searchQuery.value.trim();

            // Clear student when typing
            if (student.value) {
                student.value = null;
            }

            // Real-time search for typeahead
            if (query.length >= 3) {
                if (searchTimeout) {
                    clearTimeout(searchTimeout);
                }
                searchTimeout = setTimeout(() => {
                    searchTypeahead(query);
                }, 300);
            } else {
                searchResults.value = [];
                showDropdown.value = false;
            }
        };

        const handleFocus = () => {
            // Re-open dropdown if we have cached results
            if (searchResults.value.length > 0 && searchQuery.value.trim().length >= 3) {
                showDropdown.value = true;
            }
        };

        const searchTypeahead = async (query) => {
            const requestId = ++typeaheadRequestId;
            try {
                const response = await axios.get('search_student.php', {
                    params: {
                        q: query,
                        ajax: '1',
                        _t: Date.now()
                    },
                    timeout: 5000,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                // Drop stale responses
                if (requestId !== typeaheadRequestId) return;

                if (response.data.success) {
                    searchResults.value = response.data.students ?? [];
                } else {
                    searchResults.value = [];
                }
                selectedResultIndex.value = -1;
                showDropdown.value = true;
            } catch (err) {
                if (requestId !== typeaheadRequestId) return;
                console.error('Typeahead search error:', err);
                searchResults.value = [];
                showDropdown.value = false;
            }
        };

        const handleDocumentClick = (event) => {
            if (!showDropdown.value) return;
            const wrap = searchWrap.value;
            if (wrap && !wrap.contains(event.target)) {
                showDropdown.value = false;
                selectedResultIndex.value = -1;
            }
        };
        
        const handleSearch = async () => {
            const query = searchQuery.value.trim();
            
            if (!query) {
                validationError.value = 'Please enter a search term';
                return;
            }
            
            if (query.length < 3) {
                validationError.value = 'Please enter at least 3 characters';
                return;
            }
            
            isSearching.value = true;
            error.value = null;
            errorDetails.value = null;
            student.value = null;
            showDropdown.value = false;
            
            try {
                const response = await axios.get('search_student.php', {
                    params: {
                        SID: query,
                        ajax: '1',
                        _t: Date.now(),
                        csrf_token: csrfToken
                    },
                    timeout: 10000,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Cache-Control': 'no-cache',
                        'Pragma': 'no-cache'
                    }
                });
                
                if (response.data.success && response.data.student) {
                    student.value = response.data.student;
                    updateRecentList(response.data.student);
                } else {
                    throw new Error(response.data.error || 'Student not found');
                }
            } catch (err) {
                console.error('Search error:', err);
                
                if (err.code === 'ECONNABORTED') {
                    error.value = 'Request timed out';
                    errorDetails.value = 'Please check your connection and try again';
                } else if (err.response?.status === 404) {
                    error.value = 'Student not found';
                    errorDetails.value = 'No student found matching: ' + query;
                } else if (err.response?.status === 403) {
                    error.value = 'Security error';
                    errorDetails.value = 'Session expired. Please refresh the page';
                } else {
                    error.value = err.response?.data?.error || err.message || 'Search failed';
                    errorDetails.value = 'Please try again or contact support';
                }
            } finally {
                isSearching.value = false;
            }
        };
        
        const selectResult = (result) => {
            searchQuery.value = result.SID;
            searchResults.value = [];
            showDropdown.value = false;
            handleSearch();
        };
        
        const updateRecentList = (studentData) => {
            const exists = recentSearches.value.find(s => s.sid === studentData.SID);
            if (!exists) {
                recentSearches.value.unshift({
                    sid: studentData.SID,
                    name: studentData.name,
                    time: Math.floor(Date.now() / 1000)
                });
                recentSearches.value = recentSearches.value.slice(0, 5);
            }
        };
        
        const searchFromHistory = (sid) => {
            searchQuery.value = sid;
            handleSearch();
        };
        
        const clearSearch = () => {
            searchQuery.value = '';
            student.value = null;
            error.value = null;
            errorDetails.value = null;
            validationError.value = null;
            searchResults.value = [];
            showDropdown.value = false;
            selectedResultIndex.value = -1;
            
            setTimeout(() => {
                document.getElementById('SID')?.focus();
            }, 0);
        };
        
        const handleKeyDown = (event) => {
            if (!showDropdown.value || searchResults.value.length === 0) return;
            
            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    selectedResultIndex.value = Math.min(
                        selectedResultIndex.value + 1,
                        searchResults.value.length - 1
                    );
                    break;
                case 'ArrowUp':
                    event.preventDefault();
                    selectedResultIndex.value = Math.max(
                        selectedResultIndex.value - 1,
                        -1
                    );
                    break;
                case 'Enter':
                    event.preventDefault();
                    if (selectedResultIndex.value >= 0) {
                        selectResult(searchResults.value[selectedResultIndex.value]);
                    } else {
                        handleSearch();
                    }
                    break;
                case 'Escape':
                    event.preventDefault();
                    showDropdown.value = false;
                    selectedResultIndex.value = -1;
                    break;
            }
        };
        
        // Watchers
        watch(searchQuery, (newVal, oldVal) => {
            if (newVal === '') {
                searchResults.value = [];
                showDropdown.value = false;
                selectedResultIndex.value = -1;
            }
        });
        
        // Lifecycle
        onMounted(() => {
            document.addEventListener('click', handleDocumentClick);

            // Check for URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            const urlSid = urlParams.get('SID');
            if (urlSid) {
                searchQuery.value = urlSid;
                setTimeout(() => handleSearch(), 100);
            } else {
                setTimeout(() => {
                    document.getElementById('SID')?.focus();
                }, 100);
            }
        });

        onBeforeUnmount(() => {
            document.removeEventListener('click', handleDocumentClick);
        });

        return {
            searchQuery,
            isSearching,
            student,
            error,
            errorDetails,
            validationError,
            recentSearches,
            canSearch,
            showHelp,
            searchResults,
            showDropdown,
            selectedResultIndex,
            searchWrap,
            hasResults,
            genderLabel,
            genderIcon,
            handleInput,
            handleFocus,
            handleSearch,
            searchFromHistory,
            clearSearch,
            selectResult,
            handleKeyDown
        };
    }
}).mount('#searchApp');
</script>

<?php
if ($is_admin_nav) {
    require_once __DIR__ . '/../admin/includes/footer.php';
} else {
    require __DIR__ . '/includes/footer.php';
}
?>
