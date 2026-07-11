<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/elearning_guard.php';
elearning_require_role(['systems_admin','lecturer']);

require_once __DIR__ . '/../../db/connect.php';
require_once __DIR__ . '/../../includes/elearning_live_sessions.php';
$config = require __DIR__ . '/../../config/elearning.php';

// Auto-cleanup old videos (runs once per hour per user session)
$cleanupInterval = 3600; // 1 hour
$lastCleanup = $_SESSION['last_video_cleanup'] ?? 0;
if (time() - $lastCleanup > $cleanupInterval) {
    $_SESSION['last_video_cleanup'] = time();
    // Run cleanup in background (non-blocking). Never let a cleanup failure (e.g.
    // the legacy lms_sessions table not yet migrated) take down the whole page.
    define('CLEANUP_INTERNAL_CALL', true);
    require_once __DIR__ . '/cron_cleanup_videos.php';
    try {
        cleanup_old_videos($db);
        cleanup_expired_sessions($db);
    } catch (Throwable $e) {
        error_log('sessions.php cleanup skipped: ' . $e->getMessage());
    }
}

$err = null; $ok = null;

// Get current user ID for authorization checks
$currentUserId = $_SESSION['staff_id'] ?? null;
$userRole = $_SESSION['role'] ?? '';

// Check if user is admin
$isUserAdmin = ($userRole === 'systems_admin');

// CSRF Protection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    // Keep existing token for POST requests (allow retry on validation errors)
    $csrfToken = $_SESSION['csrf_token'];
} else {
    // Generate new token only for GET requests
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;
}

// Fetch courses based on user role
$courses = [];
if ($isUserAdmin) {
    // Admin sees all courses
    $courseQuery = "SELECT course_code, course_name FROM courses ORDER BY course_code";
    $courseStmt = $db->prepare($courseQuery);
    
    if (!$courseStmt) {
        $err = 'Database query failed: ' . htmlspecialchars($db->error);
    } else {
        $courseStmt->execute();
        $courseResult = $courseStmt->get_result();
        while ($row = $courseResult->fetch_assoc()) {
            $courses[] = $row;
        }
    }
} else {
    // Lecturers only see courses they teach
    // Use course_lecturer table for lecturer-course assignments
    $tableCheck = $db->query("SHOW TABLES LIKE 'course_lecturer'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $courseQuery = "SELECT c.course_code, c.course_name 
                        FROM courses c 
                        INNER JOIN course_lecturer cl ON c.course_code COLLATE utf8mb4_general_ci = cl.course_code COLLATE utf8mb4_general_ci 
                        WHERE cl.staff_id = ? 
                        ORDER BY c.course_code";
        $courseStmt = $db->prepare($courseQuery);
        $courseStmt->bind_param('s', $currentUserId);
        $courseStmt->execute();
        $courseResult = $courseStmt->get_result();
        while ($row = $courseResult->fetch_assoc()) {
            $courses[] = $row;
        }
        
        // Check if empty result for lecturer
        if (empty($courses)) {
            // If lecturer has no assignments, check if it's actually an admin with wrong role
            if ($isUserAdmin) {
                // Admin fallback - show all courses
                $courseQuery = "SELECT course_code, course_name FROM courses ORDER BY course_code";
                $courseStmt = $db->prepare($courseQuery);
                $courseStmt->execute();
                $courseResult = $courseStmt->get_result();
                while ($row = $courseResult->fetch_assoc()) {
                    $courses[] = $row;
                }
            }
        }
    } else {
        // Table doesn't exist - show all courses only for admins
        if ($isUserAdmin) {
            $courseQuery = "SELECT course_code, course_name FROM courses ORDER BY course_code";
            $courseStmt = $db->prepare($courseQuery);
            $courseStmt->execute();
            $courseResult = $courseStmt->get_result();
            while ($row = $courseResult->fetch_assoc()) {
                $courses[] = $row;
            }
        }
        // Lecturers see nothing if table missing and they're not admin
    }
}

// For admins: the list of lecturers (and the classes each teaches) so a session can
// be created on behalf of a specific lecturer. The session — and therefore its secure
// per-student links — belongs to the chosen lecturer.
$lecturers = [];
$lecturerCourseMap = [];
if ($isUserAdmin) {
    if ($lecRes = $db->query("SELECT DISTINCT cl.staff_id, s.title, s.Fname, s.Lname
                              FROM course_lecturer cl
                              LEFT JOIN staff s ON cl.staff_id = s.staff_id
                              ORDER BY s.Fname, s.Lname")) {
        while ($lecRow = $lecRes->fetch_assoc()) {
            $lecId = trim((string)($lecRow['staff_id'] ?? ''));
            if ($lecId === '') { continue; }
            $lecName = trim(((string)($lecRow['title'] ?? '')) . ' ' . ((string)($lecRow['Fname'] ?? '')) . ' ' . ((string)($lecRow['Lname'] ?? '')));
            $lecturers[] = ['staff_id' => $lecId, 'name' => $lecName !== '' ? $lecName : $lecId];
            $lecturerCourseMap[$lecId] = getLecturerCourseDetails($db, $lecId);
        }
        $lecRes->free();
    }
}

// Handle session creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_session') {
    $courseCode = trim($_POST['course_code'] ?? '');
    $sessionType = trim($_POST['session_type'] ?? 'google_meet');
    $topic = trim($_POST['topic'] ?? '');
    $start = trim($_POST['start_time'] ?? '');
    $duration = (int)($_POST['duration'] ?? 60);
    $duration = max(15, min(300, $duration));
    $requiresPayment = isset($_POST['requires_payment']) ? 1 : 0;
    $minPaymentPct = max(0, min(100, (float)($_POST['min_payment_percentage'] ?? 50.00)));
    $meetingUrl = trim((string)($_POST['meeting_url'] ?? ''));
    
    // Validate course code exists and user has permission
    $validCourse = false;
    foreach ($courses as $course) {
        if ($course['course_code'] === $courseCode) {
            $validCourse = true;
            break;
        }
    }
    
    if (!$validCourse) {
        $err = 'Invalid course code or you do not have permission to create sessions for this course.';
    } elseif ($topic === '' || $start === '') {
        $err = 'Topic and start time are required.';
    } elseif (!strtotime($start)) {
        $err = 'Invalid start time format. Please select a valid date and time.';
    } elseif (strtotime($start) < time() - 300) { // Allow 5 min grace period
        $err = 'Start time must be in the future. Selected time: ' . date('Y-m-d H:i', strtotime($start));
    } else {
        // Resolve the lecturer who OWNS this session. Admins create on behalf of a
        // chosen lecturer; a lecturer always owns their own sessions. created_by is set
        // to this owner, so only that lecturer (and students enrolled in the course)
        // can see the session and obtain its links.
        if ($isUserAdmin) {
            $user = trim((string)($_POST['lecturer_id'] ?? ''));
            if ($user === '') {
                $err = 'Select the lecturer this session belongs to.';
            }
        } else {
            $user = (string)($currentUserId ?? '');
        }

        // The chosen course must be one of the owning lecturer's own classes, so a
        // session can only ever target a class that lecturer actually teaches.
        if (!$err && !isLecturerAssignedToCourse($db, $user, $courseCode)) {
            $err = $isUserAdmin
                ? 'The selected lecturer is not assigned to this course. Choose one of their classes.'
                : 'You do not have permission to create sessions for this course.';
        }
        
        if (!$err) {
            if ($sessionType === 'internal') {
                $dupCheck = $db->prepare("SELECT id FROM lms_sessions WHERE course_code = ? AND session_type = ? AND start_time = ? AND created_by = ?");
                $dupCheck->bind_param('ssss', $courseCode, $sessionType, $start, $user);
                $dupCheck->execute();
                if ($dupCheck->get_result()->num_rows > 0) {
                    $err = 'A recorded session with the same course and start time already exists.';
                }
            } else {
                // Live session: a portal-hosted room (system-generated, default) or an
                // external Google Meet link. The portal room never exposes an external URL.
                $platform = ($sessionType === 'google_meet') ? 'google_meet' : 'internal';
                $roomName = null;
                if ($platform === 'internal') {
                    // System-generated room — nothing is entered or pasted by the user.
                    $roomName = elearningGenerateInternalRoomName($courseCode);
                    $meetingUrl = elearningInternalRoomUrl($roomName);
                } elseif ($meetingUrl === '') {
                    // No URL supplied: auto-generate a Google Meet link for the session.
                    $meetingUrl = elearningGenerateGoogleMeetUrl();
                } elseif (!elearningAllowedMeetingHost($platform, $meetingUrl)) {
                    $err = 'Enter a valid Google Meet URL such as https://meet.google.com/abc-defg-hij, or leave it blank to auto-generate one.';
                }
                if (!$err) {
                    $dupCheck = $db->prepare("SELECT id FROM el_live_sessions WHERE course_code = ? AND platform = ? AND start_time = ? AND created_by = ?");
                    $dupCheck->bind_param('ssss', $courseCode, $platform, $start, $user);
                    $dupCheck->execute();
                    if ($dupCheck->get_result()->num_rows > 0) {
                        $err = 'A live session with the same course, platform, and start time already exists.';
                    }
                }
            }
        }
        
        if (!$err) {
            if ($sessionType === 'internal') {
                $stmt = $db->prepare("INSERT INTO lms_sessions 
                    (course_code, provider, session_type, topic, start_time, duration_minutes, 
                     access_requires_payment, min_payment_percentage, status, created_by) 
                    VALUES (?,?,?,?,?,?,?,?,?,?)");
                $provider = 'internal';
                $status = 'scheduled';
                $stmt->bind_param('sssssiidss', $courseCode, $provider, $sessionType, $topic, $start, $duration, $requiresPayment, $minPaymentPct, $status, $user);
            } else {
                $linkExpiry = (int)($_POST['link_expiry_minutes'] ?? 120);
                $maxParticipants = (int)($_POST['max_participants'] ?? 100);
                $waitingRoomEnabled = isset($_POST['waiting_room_enabled']) ? 1 : 0;
                $recordingEnabled = isset($_POST['recording_enabled']) ? 1 : 0;
                $end = date('Y-m-d H:i:s', strtotime($start) + ($duration * 60));
                $settings = json_encode([
                    'duration_minutes' => $duration,
                    'link_expiry_minutes' => max(30, min(480, $linkExpiry)),
                    'max_participants' => max(2, min(500, $maxParticipants)),
                    'waiting_room_enabled' => (bool)$waitingRoomEnabled,
                    'recording_enabled' => (bool)$recordingEnabled,
                    'access_requires_payment' => (bool)$requiresPayment,
                    'min_payment_percentage' => $minPaymentPct,
                    // Audit trail when an admin creates on behalf of a lecturer.
                    'created_on_behalf_by' => $isUserAdmin ? ($currentUserId ?? null) : null,
                ]);

                $stmt = $db->prepare("INSERT INTO el_live_sessions
                    (course_code, platform, topic, start_time, end_time, status, join_url, host_url, external_meeting_id, settings_json, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, 'scheduled', ?, ?, ?, ?, ?, NOW())");
                $hostUrl = $meetingUrl;
                $stmt->bind_param('ssssssssss', $courseCode, $platform, $topic, $start, $end, $meetingUrl, $hostUrl, $roomName, $settings, $user);
            }
            
            if ($stmt->execute()) { 
                // Regenerate token after successful operation for security
                $csrfToken = bin2hex(random_bytes(32));
                $_SESSION['csrf_token'] = $csrfToken;
                $_SESSION['success_message'] = 'Session created successfully for ' . htmlspecialchars($courseCode) . '.';
                // Redirect to prevent form resubmission (PRG pattern)
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } else { 
                $err = 'Failed to create session: ' . htmlspecialchars($stmt->error ?? 'Unknown error'); 
            }
        }
    }
}

// Get actual PHP upload limits
function parseIniSize($size) {
    $unit = strtolower(substr($size, -1));
    $value = (int)$size;
    switch($unit) {
        case 'g': return $value * 1024 * 1024 * 1024;
        case 'm': return $value * 1024 * 1024;
        case 'k': return $value * 1024;
        default: return $value;
    }
}

$serverUploadMax = parseIniSize(ini_get('upload_max_filesize'));
$serverPostMax = parseIniSize(ini_get('post_max_size'));
$actualMaxUpload = min($serverUploadMax, $serverPostMax);
$actualMaxUploadMB = round($actualMaxUpload / (1024 * 1024));



// Handle video deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_video') {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    
    $stmt = $db->prepare("SELECT video_file_path, created_by FROM lms_sessions WHERE id = ?");
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result && ($result['created_by'] === $currentUserId || $isUserAdmin)) {
        $filePath = __DIR__ . '/../../' . $result['video_file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        $update = $db->prepare("UPDATE lms_sessions SET video_file_path = NULL, video_file_size = NULL, video_format = NULL, status = 'scheduled' WHERE id = ?");
        $update->bind_param('i', $sessionId);
        if ($update->execute()) {
            // Regenerate token after successful operation for security
            $csrfToken = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $csrfToken;
            $_SESSION['success_message'] = 'Video deleted successfully.';
            // Redirect to prevent form resubmission (PRG pattern)
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    } else {
        $err = 'Permission denied.';
    }
}

// Handle session deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_session') {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $sessionSource = $_POST['session_source'] ?? 'lms';

    if ($sessionSource === 'el') {
        $stmt = $db->prepare("SELECT created_by FROM el_live_sessions WHERE id = ?");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result && ($result['created_by'] === $currentUserId || $isUserAdmin)) {
            $db->begin_transaction();
            try {
                $links = $db->prepare("DELETE FROM el_live_session_links WHERE session_id = ?");
                $links->bind_param('i', $sessionId);
                $links->execute();
                $links->close();

                $del = $db->prepare("DELETE FROM el_live_sessions WHERE id = ?");
                $del->bind_param('i', $sessionId);
                $del->execute();
                $del->close();

                $db->commit();
                $_SESSION['success_message'] = 'Live session deleted successfully.';
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } catch (Throwable $e) {
                $db->rollback();
                $err = 'Failed to delete live session: ' . $e->getMessage();
            }
        } else {
            $err = 'Permission denied or session not found.';
        }
    } else {
    // Check permission and get file path
    $stmt = $db->prepare("SELECT video_file_path, created_by FROM lms_sessions WHERE id = ?");
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result && ($result['created_by'] === $currentUserId || $isUserAdmin)) {
        // Delete video file if exists
        if (!empty($result['video_file_path'])) {
            $filePath = __DIR__ . '/../../' . $result['video_file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        
        // Delete session record
        $del = $db->prepare("DELETE FROM lms_sessions WHERE id = ?");
        $del->bind_param('i', $sessionId);
        if ($del->execute()) {
            $_SESSION['success_message'] = 'Session deleted successfully.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $err = 'Failed to delete session: ' . $db->error;
        }
    } else {
        $err = 'Permission denied or session not found.';
    }
    }
}

// Handle live meeting URL updates for active el_live_sessions rows
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_live_link') {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $meetingUrl = trim((string)($_POST['meeting_url'] ?? ''));

    if ($sessionId <= 0 || !elearningAllowedMeetingHost('google_meet', $meetingUrl)) {
        $err = 'Enter a valid Google Meet URL such as https://meet.google.com/abc-defg-hij.';
    } else {
        $stmt = $db->prepare("SELECT created_by FROM el_live_sessions WHERE id = ?");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result && ($result['created_by'] === $currentUserId || $isUserAdmin)) {
            $update = $db->prepare("UPDATE el_live_sessions SET join_url = ?, host_url = ? WHERE id = ?");
            $update->bind_param('ssi', $meetingUrl, $meetingUrl, $sessionId);
            if ($update->execute()) {
                $_SESSION['success_message'] = 'Meeting URL updated successfully.';
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
            $err = 'Failed to update meeting URL: ' . $update->error;
        } else {
            $err = 'Permission denied or session not found.';
        }
    }
}

// Fetch upcoming sessions with course details. Live sessions use the active el_* schema;
// recorded upload sessions remain on the legacy lms_sessions table.
$sessions = [];
$query = "SELECT s.*, c.course_name, 'lms' AS session_source
          FROM lms_sessions s
          LEFT JOIN courses c ON s.course_code COLLATE utf8mb4_general_ci = c.course_code COLLATE utf8mb4_general_ci
          WHERE s.start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) ";

if (!$isUserAdmin) {
    $query .= "AND s.created_by = ? ";
}
$query .= "ORDER BY s.start_time DESC LIMIT 100";

// Recorded sessions live in the legacy lms_sessions table. Guard the fetch so a
// missing/un-migrated table degrades to "no recorded sessions" instead of taking
// down the page — the live (el_*) sessions below still render.
try {
    $stmt = $db->prepare($query);
    if (!$isUserAdmin) {
        $stmt->bind_param('s', $currentUserId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sessions[] = $row;
    }
} catch (Throwable $e) {
    error_log('sessions.php recorded-session list failed: ' . $e->getMessage());
}

$liveQuery = "SELECT ls.id, ls.course_code, ls.platform AS provider, 'google_meet' AS session_type,
                     ls.topic, ls.start_time,
                     GREATEST(15, COALESCE(TIMESTAMPDIFF(MINUTE, ls.start_time, ls.end_time), 60)) AS duration_minutes,
                     ls.status, ls.join_url, ls.host_url, ls.settings_json, ls.created_by, ls.created_at, ls.end_time,
                     c.course_name, 'el' AS session_source,
                     NULL AS video_file_path, NULL AS video_file_size, NULL AS video_format
              FROM el_live_sessions ls
              LEFT JOIN courses c ON ls.course_code COLLATE utf8mb4_general_ci = c.course_code COLLATE utf8mb4_general_ci
              WHERE ls.start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) ";
if (!$isUserAdmin) {
    $liveQuery .= "AND ls.created_by = ? ";
}
$liveQuery .= "ORDER BY ls.start_time DESC LIMIT 100";

$liveStmt = $db->prepare($liveQuery);
if (!$isUserAdmin) {
    $liveStmt->bind_param('s', $currentUserId);
}
$liveStmt->execute();
$liveResult = $liveStmt->get_result();
while ($row = $liveResult->fetch_assoc()) {
    $settings = json_decode((string)($row['settings_json'] ?? ''), true);
    if (!is_array($settings)) {
        $settings = [];
    }
    $row['recording_enabled'] = !empty($settings['recording_enabled']);
    $row['waiting_room_enabled'] = !empty($settings['waiting_room_enabled']);
    $row['access_requires_payment'] = !empty($settings['access_requires_payment']);
    $row['min_payment_percentage'] = (float)($settings['min_payment_percentage'] ?? 100);
    $sessions[] = $row;
}

usort($sessions, static function (array $a, array $b): int {
    return strcmp((string)$b['start_time'], (string)$a['start_time']);
});
$sessions = array_slice($sessions, 0, 100);

require_once __DIR__ . '/../includes/header.php';

// Handle success messages from redirects (PRG pattern)
if (isset($_SESSION['success_message'])) {
    $ok = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>
<link rel="stylesheet" href="../css/admin-dashboard.css" />
<style>
/* Styles for the new drag-and-drop uploader */
.upload-drop-zone {
    border: 2px dashed #ccc;
    border-radius: 10px;
    padding: 40px;
    text-align: center;
    color: #777;
    cursor: pointer;
    transition: all 0.3s ease;
    background-color: #f9f9f9;
}
.upload-drop-zone.dragover {
    border-color: #0d6efd;
    color: #0d6efd;
    background-color: #eef5ff;
}
.upload-drop-zone .upload-icon {
    font-size: 3rem;
    margin-bottom: 15px;
}
.upload-drop-zone p {
    margin: 0;
    font-size: 1.1rem;
}
.upload-drop-zone small {
    display: block;
    margin-top: 10px;
}
#uploadPreview {
    margin-top: 20px;
    display: none;
}
#uploadPreview .file-info {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px;
    background-color: #e9ecef;
    border-radius: 5px;
}
#uploadPreview .file-name {
    font-weight: bold;
}
#uploadPreview .file-size {
    color: #6c757d;
}
.progress-wrapper {
    margin-top: 10px;
}
.upload-status {
    margin-top: 15px;
    font-weight: bold;
}

/* Sessions Table Styling - Clean & Modern */
.sessions-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}
.sessions-table thead th {
    background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%);
    color: #fff;
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 0.875rem 0.75rem;
    border: none;
    position: sticky;
    top: 0;
    z-index: 10;
}
.sessions-table thead th:first-child { border-radius: 8px 0 0 0; }
.sessions-table thead th:last-child { border-radius: 0 8px 0 0; }
.sessions-table tbody tr {
    transition: all 0.2s ease;
}
.sessions-table tbody tr:hover {
    background-color: #f0f7ff;
    transform: scale(1.001);
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.sessions-table tbody td {
    padding: 0.75rem 0.75rem;
    vertical-align: middle;
    border-bottom: 1px solid #e9ecef;
    font-size: 0.875rem;
}
/* Column widths - Simplified 6 columns */
.sessions-table th:nth-child(1), .sessions-table td:nth-child(1) { width: 180px; } /* Course & Topic */
.sessions-table th:nth-child(2), .sessions-table td:nth-child(2) { width: 100px; } /* Type */
.sessions-table th:nth-child(3), .sessions-table td:nth-child(3) { width: 150px; } /* Schedule */
.sessions-table th:nth-child(4), .sessions-table td:nth-child(4) { width: 100px; } /* Status */
.sessions-table th:nth-child(5), .sessions-table td:nth-child(5) { width: 80px; } /* Info */
.sessions-table th:nth-child(6), .sessions-table td:nth-child(6) { width: 160px; text-align: center; } /* Actions */

/* Session info cell styling */
.session-info { line-height: 1.4; }
.session-info .course-code { 
    font-weight: 700; 
    color: #1e3a5f; 
    font-size: 0.8rem;
    display: block;
    margin-bottom: 2px;
}
.session-info .topic { 
    color: #495057; 
    font-size: 0.85rem;
    display: block;
    max-width: 200px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.session-info .topic:hover {
    white-space: normal;
    overflow: visible;
}

/* Type badges */
.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 0.35rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}
.type-badge.live { background: linear-gradient(135deg, #0d6efd, #6610f2); color: #fff; }
.type-badge.recorded { background: linear-gradient(135deg, #198754, #20c997); color: #fff; }

/* Schedule cell */
.schedule-info { line-height: 1.3; }
.schedule-info .date { font-weight: 600; color: #1e293b; font-size: 0.8rem; }
.schedule-info .time { color: #6c757d; font-size: 0.75rem; }
.schedule-info .duration { 
    display: inline-block;
    background: #e9ecef;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.7rem;
    color: #495057;
    margin-top: 2px;
}

/* Status badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 0.3rem 0.6rem;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 600;
}
.status-badge.scheduled { background: #e3f2fd; color: #1565c0; }
.status-badge.live { background: #c8e6c9; color: #2e7d32; animation: pulse 2s infinite; }
.status-badge.available { background: #d4edda; color: #155724; }
.status-badge.processing { background: #fff3cd; color: #856404; }
.status-badge.ended { background: #f5f5f5; color: #666; }

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

/* Info icons */
.info-icons { display: flex; gap: 6px; flex-wrap: wrap; }
.info-icons i { font-size: 0.85rem; cursor: help; }

/* Action buttons - Clean dropdown style */
.action-buttons {
    display: flex;
    gap: 4px;
    justify-content: center;
    flex-wrap: wrap;
}
.action-buttons .btn {
    padding: 0.35rem 0.6rem;
    font-size: 0.75rem;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s ease;
}
.action-buttons .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}
.action-buttons .btn i { font-size: 0.7rem; }

/* Primary actions */
.btn-action-primary {
    background: linear-gradient(135deg, #0d6efd, #0056d3);
    border: none;
    color: #fff;
}
.btn-action-success {
    background: linear-gradient(135deg, #198754, #157347);
    border: none;
    color: #fff;
}
.btn-action-warning {
    background: linear-gradient(135deg, #ffc107, #e0a800);
    border: none;
    color: #212529;
}
.btn-action-danger {
    background: #fff;
    border: 1px solid #dc3545;
    color: #dc3545;
}
.btn-action-danger:hover {
    background: #dc3545;
    color: #fff;
}
.btn-action-secondary {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    color: #495057;
}
.btn-action-secondary:hover {
    background: #e9ecef;
}

/* Dropdown menu styling */
.action-buttons .dropdown-menu {
    min-width: 140px;
    padding: 0.5rem 0;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.action-buttons .dropdown-item {
    font-size: 0.8rem;
    padding: 0.5rem 1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.action-buttons .dropdown-item i { width: 16px; text-align: center; }
.action-buttons .dropdown-divider { margin: 0.25rem 0; }
</style>
<div class="container-fluid px-4 portal-dashboard">
  <h2 class="mb-3">Virtual Classroom Sessions</h2>
  
  <?php if (empty($courses)): ?>
    <div class="alert alert-warning">
      <i class="fas fa-exclamation-triangle"></i> 
      You don't have any active courses assigned. Please contact the administrator.
    </div>
  <?php endif; ?>
  
  <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($err); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  
  <?php if ($ok): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($ok); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-header">
      <i class="fas fa-plus-circle"></i> Create New Session
    </div>
    <div class="card-body">
      <form method="post" id="sessionForm">
        <input type="hidden" name="action" value="create_session" />
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>" />
        
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Session Type</label>
            <select class="form-select" name="session_type" id="sessionType" required>
              <option value="portal" selected>Portal Live Room (in-system)</option>
              <option value="google_meet">Google Meet (Live)</option>
              <option value="internal">Recorded Video (Upload)</option>
            </select>
          </div>
          
          <?php if ($isUserAdmin): ?>
          <div class="col-md-3">
            <label class="form-label" for="lecturer_id">Lecturer <span class="text-danger">*</span></label>
            <select class="form-select" name="lecturer_id" id="lecturer_id" required>
              <option value="">Select Lecturer...</option>
              <?php foreach ($lecturers as $lec): ?>
                <option value="<?php echo htmlspecialchars($lec['staff_id']); ?>">
                  <?php echo htmlspecialchars($lec['name'] . ' (' . $lec['staff_id'] . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Session &amp; links belong to this lecturer.</div>
          </div>
          <?php endif; ?>

          <div class="col-md-3">
            <label class="form-label" for="course_code">Course <span class="text-danger">*</span></label>
            <select class="form-select" name="course_code" id="course_code" required>
              <option value="">Select Course...</option>
              <?php if (!$isUserAdmin): foreach ($courses as $course): ?>
                <option value="<?php echo htmlspecialchars($course['course_code']); ?>">
                  <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']); ?>
                </option>
              <?php endforeach; endif; ?>
            </select>
            <?php if ($isUserAdmin): ?>
              <div class="form-text">Pick a lecturer first to load their classes.</div>
            <?php elseif (empty($courses)): ?>
              <div class="form-text text-danger">No courses available</div>
            <?php endif; ?>
          </div>
          
          <div class="col-md-6">
            <label class="form-label" for="topic">Topic <span class="text-danger">*</span></label>
            <input class="form-control" name="topic" id="topic" required placeholder="e.g., Introduction to Data Structures" />
          </div>
          
          <!-- Google Meet configuration fields -->
          <div id="googleMeetFields" class="row g-3 mt-0">
            <div class="col-md-6" id="meetingUrlField">
              <label class="form-label" for="meeting_url">Google Meet URL</label>
              <input type="url" class="form-control" name="meeting_url" id="meeting_url"
                     placeholder="https://meet.google.com/abc-defg-hij" />
              <div class="form-text">Leave blank to auto-generate a Google Meet link. Paste a real Meet URL to override.</div>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="max_participants">Max Participants</label>
              <input type="number" class="form-control" name="max_participants" id="max_participants" value="100" min="2" max="500" />
            </div>
            <div class="col-md-3">
              <label class="form-label" for="link_expiry_minutes">Link Expiry (min)</label>
              <input type="number" class="form-control" name="link_expiry_minutes" id="link_expiry_minutes" value="120" min="30" max="480" />
              <div class="form-text">How long student links remain valid after session starts</div>
            </div>
            <div class="col-md-3">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="waiting_room_enabled" id="waitingRoom" />
                <label class="form-check-label" for="waitingRoom">Enable Waiting Room</label>
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="recording_enabled" id="recordingEnabled" checked />
                <label class="form-check-label" for="recordingEnabled">Enable Recording</label>
              </div>
            </div>
          </div>
          
          <div class="col-md-3">
            <label class="form-label" for="start_time">Start Time <span class="text-danger">*</span></label>
            <input type="datetime-local" class="form-control" name="start_time" id="start_time" required 
                   min="<?php echo date('Y-m-d\TH:i'); ?>" />
          </div>
          
          <div class="col-md-2">
            <label class="form-label" for="duration">Duration (min)</label>
            <input type="number" class="form-control" name="duration" id="duration" value="60" min="15" max="300" />
          </div>
          
          <div class="col-md-2">
            <label class="form-label" for="min_payment_percentage">Min Payment %</label>
            <input type="number" class="form-control" name="min_payment_percentage" id="min_payment_percentage" value="50" min="0" max="100" step="0.01" />
          </div>
          
          <div class="col-md-5">
            <div class="form-check mt-4">
              <input class="form-check-input" type="checkbox" name="requires_payment" id="requiresPayment" checked />
              <label class="form-check-label" for="requiresPayment">
                Require payment for access
              </label>
            </div>
          </div>
          
          <div class="col-md-12 text-end">
            <button class="btn btn-primary" type="submit" id="createSessionBtn" <?php echo empty($courses) ? 'disabled' : ''; ?>>
              <i class="fas fa-calendar-plus"></i> Create Session
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><i class="fas fa-list"></i> Sessions</span>
      <span class="badge bg-secondary"><?php echo count($sessions); ?> total</span>
    </div>
    <div class="card-body table-responsive">
      <table class="table table-hover align-middle sessions-table">
        <thead class="table-light">
          <tr>
            <th>Session Details</th>
            <th>Type</th>
            <th>Schedule</th>
            <th>Status</th>
            <th>Info</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sessions)): ?>
            <tr>
              <td colspan="6" class="text-center text-muted py-5">
                <i class="fas fa-video-slash fa-3x mb-3 text-secondary"></i><br>
                <strong>No sessions found</strong><br>
                <small>Create your first session using the form above.</small>
              </td>
            </tr>
          <?php endif; ?>
          
          <?php foreach ($sessions as $s): 
            $startTime = strtotime($s['start_time']);
            $isPast = $startTime < time();
            $isLive = ($startTime <= time() && $startTime + ($s['duration_minutes'] * 60) > time());
            $status = $s['status'] ?? 'scheduled';
            $sessionSource = $s['session_source'] ?? 'lms';
            // Recorded uploads live in lms_sessions; everything from el_live_sessions is a
            // live session. A live session is a portal room when its platform is 'internal'.
            $isRecorded = ($sessionSource === 'lms');
            $isLiveSession = !$isRecorded;
            $isPortalRoom = ($isLiveSession && (string)($s['provider'] ?? '') === 'internal');
            $joinUrl = trim((string)($s['join_url'] ?? ''));
          ?>
            <tr>
              <!-- Session Details (Course + Topic) -->
              <td>
                <div class="session-info">
                  <span class="course-code"><?php echo htmlspecialchars($s['course_code']); ?></span>
                  <span class="topic" title="<?php echo htmlspecialchars($s['topic']); ?>"><?php echo htmlspecialchars($s['topic']); ?></span>
                </div>
              </td>
              
              <!-- Type Badge -->
              <td>
                <?php if ($isRecorded): ?>
                  <span class="type-badge recorded"><i class="fas fa-video"></i> Recorded</span>
                <?php else: ?>
                  <span class="type-badge live"><i class="fas fa-broadcast-tower"></i> Live</span>
                <?php endif; ?>
              </td>
              
              <!-- Schedule -->
              <td>
                <div class="schedule-info">
                  <span class="date"><?php echo date('M j, Y', $startTime); ?></span><br>
                  <span class="time"><?php echo date('g:i A', $startTime); ?></span>
                  <span class="duration"><?php echo (int)$s['duration_minutes']; ?> min</span>
                </div>
              </td>
              
              <!-- Status -->
              <td>
                <?php if ($isLive || $status === 'live'): ?>
                  <span class="status-badge live"><i class="fas fa-circle"></i> Live Now</span>
                <?php elseif ($status === 'available'): ?>
                  <span class="status-badge available"><i class="fas fa-check"></i> Available</span>
                <?php elseif ($status === 'processing'): ?>
                  <span class="status-badge processing"><i class="fas fa-spinner fa-spin"></i> Processing</span>
                <?php elseif ($isPast || in_array($status, ['ended', 'completed'], true)): ?>
                  <span class="status-badge ended"><i class="fas fa-flag-checkered"></i> Ended</span>
                <?php else: ?>
                  <span class="status-badge scheduled"><i class="fas fa-clock"></i> Scheduled</span>
                <?php endif; ?>
              </td>
              
              <!-- Info Icons -->
              <td>
                <div class="info-icons">
                  <?php if ($isLiveSession): ?>
                    <?php if ($s['recording_enabled'] ?? false): ?>
                      <i class="fas fa-record-vinyl text-danger" title="Recording enabled"></i>
                    <?php endif; ?>
                    <?php if ($s['waiting_room_enabled'] ?? false): ?>
                      <i class="fas fa-door-open text-info" title="Waiting room"></i>
                    <?php endif; ?>
                    <?php if ($s['access_requires_payment'] ?? false): ?>
                      <i class="fas fa-lock text-warning" title="Payment required (<?php echo number_format($s['min_payment_percentage'], 0); ?>%)"></i>
                    <?php endif; ?>
                    <?php if ($joinUrl !== ''): ?>
                      <i class="fas fa-link text-success" title="Meeting link configured"></i>
                    <?php else: ?>
                      <i class="fas fa-link-slash text-warning" title="Meeting link not configured"></i>
                    <?php endif; ?>
                    <i class="fas fa-shield-alt text-success" title="Secure student links"></i>
                  <?php else: ?>
                    <?php if (!empty($s['video_file_path'])): ?>
                      <i class="fas fa-check-circle text-success" title="Video uploaded (<?php echo number_format(($s['video_file_size'] ?? 0) / 1048576, 1); ?> MB)"></i>
                    <?php else: ?>
                      <i class="fas fa-exclamation-circle text-warning" title="No video uploaded"></i>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
              
              <!-- Actions -->
              <td>
                <div class="action-buttons">
                  <?php if ($isRecorded): ?>
                    <?php if (empty($s['video_file_path'])): ?>
                      <!-- Direct file upload -->
                      <input type="file" 
                             id="videoFile<?php echo $s['id']; ?>" 
                             class="direct-upload-input"
                             data-session-id="<?php echo $s['id']; ?>"
                             data-csrf-token="<?php echo $csrfToken; ?>"
                             accept="video/mp4,video/webm,video/ogg,video/quicktime"
                             style="display: none;" />
                      <button type="button" 
                              class="btn btn-action-primary" 
                              id="uploadBtn<?php echo $s['id']; ?>"
                              onclick="document.getElementById('videoFile<?php echo $s['id']; ?>').click()"
                              title="Upload Video (Max: <?php echo $actualMaxUploadMB; ?> MB)">
                        <i class="fas fa-upload"></i> Upload
                      </button>
                    <?php else: ?>
                      <!-- Preview & Delete for uploaded videos -->
                      <a href="../../<?php echo htmlspecialchars($s['video_file_path']); ?>" 
                         target="_blank" class="btn btn-action-success" title="Preview Video">
                        <i class="fas fa-play"></i> Preview
                      </a>
                      <button class="btn btn-action-danger delete-trigger" 
                              data-bs-toggle="modal" 
                              data-bs-target="#deleteModal<?php echo $s['id']; ?>"
                              title="Delete Video">
                        <i class="fas fa-trash"></i>
                      </button>
                    <?php endif; ?>
                  <?php else: ?>
                    <!-- Live session actions -->
                    <?php if ($isPortalRoom): ?>
                      <a href="room.php?id=<?php echo (int)$s['id']; ?>"
                         target="_blank" rel="noopener" class="btn btn-action-primary" title="Launch portal live room as host">
                        <i class="fas fa-chalkboard-teacher"></i> Launch
                      </a>
                    <?php elseif ($sessionSource === 'lms'): ?>
                      <a href="manage_session.php?id=<?php echo $s['id']; ?>"
                         class="btn btn-action-primary" title="Manage Session">
                        <i class="fas fa-cog"></i> Manage
                      </a>
                    <?php elseif ($joinUrl !== ''): ?>
                      <a href="<?php echo htmlspecialchars($joinUrl, ENT_QUOTES, 'UTF-8'); ?>" 
                         target="_blank" rel="noopener noreferrer" class="btn btn-action-primary" title="Launch Meeting">
                        <i class="fas fa-external-link-alt"></i> Launch
                      </a>
                    <?php else: ?>
                      <span class="badge bg-warning text-dark" title="Add a Google Meet URL when creating the session">
                        <i class="fas fa-link-slash"></i> No link
                      </span>
                    <?php endif; ?>
                    
                    <!-- More options dropdown -->
                    <div class="dropdown">
                      <button class="btn btn-action-secondary dropdown-toggle" type="button" 
                              data-bs-toggle="dropdown" aria-expanded="false" title="More Options">
                        <i class="fas fa-ellipsis-v"></i>
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end">
                        <?php if ($sessionSource === 'lms'): ?>
                          <li>
                            <a class="dropdown-item" href="manage_session.php?id=<?php echo $s['id']; ?>">
                              <i class="fas fa-eye text-primary"></i> View Details
                            </a>
                          </li>
                        <?php endif; ?>
                        <?php if ($joinUrl !== ''): ?>
                          <li>
                            <a class="dropdown-item" href="#" onclick="copyMeetLink(<?php echo htmlspecialchars(json_encode($joinUrl), ENT_QUOTES, 'UTF-8'); ?>); return false;">
                              <i class="fas fa-link text-info"></i> Copy Meeting URL
                            </a>
                          </li>
                        <?php endif; ?>
                        <?php if ($sessionSource === 'el'): ?>
                          <li>
                            <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#linkModal<?php echo (int)$s['id']; ?>">
                              <i class="fas fa-pen text-warning"></i> <?php echo $joinUrl !== '' ? 'Update Meeting URL' : 'Add Meeting URL'; ?>
                            </a>
                          </li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                          <a class="dropdown-item text-danger" href="#" 
                             onclick="confirmDeleteSession(<?php echo (int)$s['id']; ?>, '<?php echo htmlspecialchars($sessionSource, ENT_QUOTES, 'UTF-8'); ?>'); return false;">
                            <i class="fas fa-trash"></i> Delete Session
                          </a>
                        </li>
                      </ul>
                    </div>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            
            <!-- Direct upload handled via inline file input in Actions column -->
            
            <!-- Delete Confirmation Modal -->
            <?php if ($s['provider'] === 'internal' && !empty($s['video_file_path'])): ?>
            <div class="modal fade" id="deleteModal<?php echo $s['id']; ?>" tabindex="-1" aria-labelledby="deleteModalLabel<?php echo $s['id']; ?>" aria-hidden="true">
              <div class="modal-dialog">
                <div class="modal-content">
                  <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModalLabel<?php echo $s['id']; ?>">Delete Video - Session #<?php echo $s['id']; ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <form method="post">
                    <input type="hidden" name="action" value="delete_video" />
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>" />
                    <input type="hidden" name="session_id" value="<?php echo $s['id']; ?>" />
                    <div class="modal-body">
                      <p>Are you sure you want to delete the video for <strong><?php echo htmlspecialchars($s['topic']); ?></strong>?</p>
                      <p class="text-danger"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Permanently
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($sessionSource === 'el'): ?>
            <div class="modal fade" id="linkModal<?php echo (int)$s['id']; ?>" tabindex="-1" aria-labelledby="linkModalLabel<?php echo (int)$s['id']; ?>" aria-hidden="true">
              <div class="modal-dialog">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title" id="linkModalLabel<?php echo (int)$s['id']; ?>">Meeting URL - <?php echo htmlspecialchars($s['topic']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <form method="post">
                    <input type="hidden" name="action" value="update_live_link" />
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>" />
                    <input type="hidden" name="session_id" value="<?php echo (int)$s['id']; ?>" />
                    <div class="modal-body">
                      <label class="form-label" for="meetingUrl<?php echo (int)$s['id']; ?>">Google Meet URL</label>
                      <input type="url" class="form-control" name="meeting_url" id="meetingUrl<?php echo (int)$s['id']; ?>"
                             value="<?php echo htmlspecialchars($joinUrl, ENT_QUOTES, 'UTF-8'); ?>"
                             placeholder="https://meet.google.com/abc-defg-hij" required />
                      <div class="form-text">Students can generate secure join links only after this URL is valid.</div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save URL
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <?php endif; ?>
            
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
const MAX_UPLOAD_SIZE = <?php echo $actualMaxUpload; ?>;
const MAX_UPLOAD_MB = <?php echo $actualMaxUploadMB; ?>;

function escapeHtml(value) {
  const div = document.createElement('div');
  div.textContent = String(value ?? '');
  return div.innerHTML;
}

// Copy meeting link to clipboard
function copyMeetLink(link) {
  navigator.clipboard.writeText(link).then(() => {
    showToast('Meeting URL copied to clipboard!', 'success');
  }).catch(err => {
    showToast('Failed to copy meeting URL', 'error');
  });
}

// Confirm delete session
function confirmDeleteSession(sessionId, sessionSource = 'lms') {
  if (confirm('Are you sure you want to delete this session? This action cannot be undone.')) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
      <input type="hidden" name="action" value="delete_session">
      <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
      <input type="hidden" name="session_id" value="${sessionId}">
      <input type="hidden" name="session_source" value="${sessionSource}">
    `;
    document.body.appendChild(form);
    form.submit();
  }
}

// Toast notification
function showToast(message, type = 'info', duration = 3000) {
  const existing = document.querySelector('.upload-toast');
  if (existing) existing.remove();
  
  const toast = document.createElement('div');
  toast.className = `upload-toast alert alert-${type === 'success' ? 'success' : type === 'error' ? 'danger' : 'info'}`;
  toast.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-radius: 8px;';
  toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i> ${escapeHtml(message)}`;
  document.body.appendChild(toast);
  
  if (duration > 0) {
    setTimeout(() => toast.remove(), duration);
  }
  return toast;
}

// Progress toast with bar
function showProgressToast(sessionId, fileName) {
  const existing = document.querySelector('.upload-toast');
  if (existing) existing.remove();
  
  const toast = document.createElement('div');
  toast.id = `progressToast${sessionId}`;
  toast.className = 'upload-toast alert alert-info';
  toast.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 350px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-radius: 8px;';
  toast.innerHTML = `
    <div class="d-flex align-items-center mb-2">
      <i class="fas fa-upload me-2"></i>
      <strong>Uploading Video</strong>
    </div>
    <div class="small text-muted mb-2" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(fileName)}</div>
    <div class="progress" style="height: 8px;">
      <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
    </div>
    <div class="upload-status small text-muted mt-1">Starting...</div>
  `;
  document.body.appendChild(toast);
  return toast;
}

// Initialize direct upload handlers
document.addEventListener('DOMContentLoaded', function() {
  const sessionType = document.getElementById('sessionType');
  const googleMeetFields = document.getElementById('googleMeetFields');

  function syncSessionTypeFields() {
    if (!sessionType || !googleMeetFields) return;
    const t = sessionType.value;
    // Recorded uploads need none of the live fields; the portal room needs the live
    // options but auto-generates its link, so only Google Meet shows the URL field.
    googleMeetFields.style.display = (t === 'internal') ? 'none' : '';
    const urlField = document.getElementById('meetingUrlField');
    if (urlField) urlField.style.display = (t === 'google_meet') ? '' : 'none';
  }

  if (sessionType) {
    sessionType.addEventListener('change', syncSessionTypeFields);
    syncSessionTypeFields();
  }
  
  // Attach change handlers to all direct upload inputs
  document.querySelectorAll('.direct-upload-input').forEach(input => {
    input.addEventListener('change', handleDirectUpload);
  });
});

// Handle file selection and immediate upload
function handleDirectUpload(event) {
  const input = event.target;
  const file = input.files[0];
  if (!file) return;
  
  const sessionId = input.dataset.sessionId;
  const csrfToken = input.dataset.csrfToken;
  const uploadBtn = document.getElementById(`uploadBtn${sessionId}`);
  
  // Validate file size
  if (file.size > MAX_UPLOAD_SIZE) {
    const sizeMB = (file.size / 1048576).toFixed(1);
    showToast(`File too large (${sizeMB} MB). Maximum: ${MAX_UPLOAD_MB} MB`, 'error', 5000);
    input.value = '';
    return;
  }
  
  // Validate file type
  const validTypes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
  const validExts = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'];
  const ext = file.name.split('.').pop().toLowerCase();
  
  if (!validTypes.includes(file.type) && !validExts.includes(ext)) {
    showToast('Invalid file type. Please select a video file.', 'error', 5000);
    input.value = '';
    return;
  }
  
  // Disable button and show uploading state
  if (uploadBtn) {
    uploadBtn.disabled = true;
    uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  }
  
  // Show progress toast
  const toast = showProgressToast(sessionId, file.name);
  const progressBar = toast.querySelector('.progress-bar');
  const statusDiv = toast.querySelector('.upload-status');
  
  // Create FormData and upload
  const formData = new FormData();
  formData.append('session_id', sessionId);
  formData.append('csrf_token', csrfToken);
  formData.append('video_file', file);
  
  const xhr = new XMLHttpRequest();
  
  xhr.upload.onprogress = (e) => {
    if (e.lengthComputable) {
      const percent = Math.round((e.loaded / e.total) * 100);
      progressBar.style.width = percent + '%';
      progressBar.textContent = percent + '%';
      
      const uploaded = (e.loaded / 1048576).toFixed(1);
      const total = (e.total / 1048576).toFixed(1);
      statusDiv.textContent = `${uploaded} MB / ${total} MB`;
    }
  };
  
  xhr.onload = () => {
    if (xhr.status >= 200 && xhr.status < 300) {
      // Success
      toast.className = 'upload-toast alert alert-success';
      toast.innerHTML = `
        <i class="fas fa-check-circle"></i> 
        <strong>Upload Complete!</strong>
        <div class="small">Refreshing page...</div>
      `;
      setTimeout(() => window.location.reload(), 1500);
    } else {
      // Error
      let errMsg = 'Upload failed';
      try {
        const res = JSON.parse(xhr.responseText);
        if (res.error) errMsg = res.error;
      } catch (e) {
        errMsg = xhr.responseText.substring(0, 100) || 'Server error';
      }
      
      toast.className = 'upload-toast alert alert-danger';
      toast.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${errMsg}`;
      
      if (uploadBtn) {
        uploadBtn.disabled = false;
        uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Retry';
      }
      input.value = '';
      
      setTimeout(() => toast.remove(), 5000);
    }
  };
  
  xhr.onerror = () => {
    toast.className = 'upload-toast alert alert-danger';
    toast.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error. Please try again.';
    
    if (uploadBtn) {
      uploadBtn.disabled = false;
      uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Retry';
    }
    input.value = '';
    
    setTimeout(() => toast.remove(), 5000);
  };
  
  xhr.open('POST', 'ajax_upload_handler.php', true);
  xhr.send(formData);
}

// Admin: filter the course dropdown to the chosen lecturer's classes.
const lecturerCourseMap = <?php echo json_encode($lecturerCourseMap, JSON_UNESCAPED_SLASHES); ?>;
const lecturerSelect = document.getElementById('lecturer_id');
const courseSelect = document.getElementById('course_code');
if (lecturerSelect && courseSelect) {
  lecturerSelect.addEventListener('change', function () {
    const classes = lecturerCourseMap[this.value] || [];
    courseSelect.innerHTML = '<option value="">Select Course...</option>';
    classes.forEach(function (c) {
      const opt = document.createElement('option');
      opt.value = c.course_code;
      opt.textContent = c.course_code + ' - ' + c.course_name;
      courseSelect.appendChild(opt);
    });
  });
}

// Session creation loading state
const sessionForm = document.getElementById('sessionForm');
if (sessionForm) {
  sessionForm.addEventListener('submit', function() {
    const btn = document.getElementById('createSessionBtn');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
    }
  });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

