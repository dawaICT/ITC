<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/../db/connect.php';
require_once dirname(__DIR__) . '/includes/session_handler.php';

header('Content-Type: application/json');

// Helper function for profile image
function getProfileImagePath(?string $filename): string {
    if (empty($filename)) {
        return '/wucportal/images/avatar.png';
    }
    // Basic sanitization
    $filename = basename($filename);
    $path = dirname(__DIR__) . '/../uploads/profile/' . $filename;
    return file_exists($path) ? "/wucportal/uploads/profile/{$filename}" : '/wucportal/images/avatar.png';
}

// Check session
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF Check (optional, but good)
if (isset($_GET['csrf_token']) && isset($_SESSION['admit_modal_csrf'])) {
    if (!hash_equals($_SESSION['admit_modal_csrf'], $_GET['csrf_token'])) {
         http_response_code(403);
         echo json_encode(['success' => false, 'error' => 'Invalid security token']);
         exit;
    }
}

$q = $_GET['q'] ?? '';
if (strlen(trim($q)) < 3) {
    echo json_encode(['success' => true, 'students' => []]);
    exit;
}

try {
    $searchTerm = "%" . trim($q) . "%";
    
    // Search by SID, Name, NRC, Email
    // Only return students NOT already searched? No, users might want to select existing to verify.
    // Ideally we should flag if they are already active in "some" program, but `api/admit_student.php` handles check.
    
    $query = "SELECT SID, Fname, Lname, email, profile_image, nrc_pass 
              FROM students 
              WHERE SID LIKE ? OR Fname LIKE ? OR Lname LIKE ? OR nrc_pass LIKE ? OR email LIKE ?
              LIMIT 20";
              
    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new Exception($db->error);
    }
    
    $stmt->bind_param('sssss', $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $row['profile_image'] = getProfileImagePath($row['profile_image']);
        $students[] = $row;
    }
    $stmt->close();
    
    echo json_encode(['success' => true, 'students' => $students]);

} catch (Exception $e) {
    error_log("Search error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Search failed']);
}
?>
