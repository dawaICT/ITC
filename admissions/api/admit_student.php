<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/../db/connect.php';
require_once dirname(__DIR__) . '/includes/session_handler.php';
require_once dirname(__DIR__) . '/../includes/applicant_admission.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['admit_modal_csrf'] ?? '', (string)$_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

if (!isAdminAuthenticated()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $required = ['student_id', 'program_code', 'intake', 'mode', 'startYear'];
    foreach ($required as $field) {
        if (trim((string)($_POST[$field] ?? '')) === '') {
            throw new Exception("Missing required field: {$field}");
        }
    }

    $studentId = trim((string)$_POST['student_id']);
    $programCode = trim((string)$_POST['program_code']);
    $intake = trim((string)$_POST['intake']);
    $mode = trim((string)$_POST['mode']);
    $entryYear = (int)substr(trim((string)$_POST['startYear']), 0, 4) ?: (int)date('Y');
    $isTransfer = !empty($_POST['is_transfer']) && (string)$_POST['is_transfer'] === '1';

    if ($isTransfer) {
        $previousInstitution = trim((string)($_POST['previous_institution'] ?? ''));
        $creditsTransferred = (int)($_POST['credits_transferred'] ?? 0);
        if ($studentTransfer = $db->prepare('UPDATE students SET is_transfer = 1, transfer_from = ?, transfer_credits = ? WHERE SID = ?')) {
            $studentTransfer->bind_param('sis', $previousInstitution, $creditsTransferred, $studentId);
            $studentTransfer->execute();
            $studentTransfer->close();
        }

        if (!empty($_FILES['transfer_document']) && $_FILES['transfer_document']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['transfer_document'];
            $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
            if (!in_array($file['type'], $allowed, true)) {
                throw new Exception('Invalid file type');
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                throw new Exception('File too large (Max 5MB)');
            }
            $uploadDir = dirname(__DIR__) . '/../uploads/transfers/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $filename = 'transfer_' . $studentId . '_' . time() . '_' . basename($file['name']);
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                throw new Exception('Failed to upload transfer document');
            }
        }
    }

    $result = admissionsEnrollExistingStudent($db, $studentId, $programCode, $intake, $mode, $entryYear);
    if (empty($result['success'])) {
        throw new Exception((string)($result['message'] ?? 'Admission failed'));
    }

    try {
        $enrolledBy = (string)($_SESSION['username'] ?? $_SESSION['staff_id'] ?? 'system');
        if ($log = $db->prepare("INSERT INTO admission_logs (student_id, program_code, intake, action, performed_by) VALUES (?, ?, ?, 'admit', ?)")) {
            $log->bind_param('ssss', $studentId, $programCode, $intake, $enrolledBy);
            $log->execute();
            $log->close();
        }
    } catch (Throwable $e) {
        error_log('admit_student.php audit log failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => $result['message'] ?? 'Student admitted successfully.',
        'student_id' => $studentId,
    ]);
} catch (Throwable $e) {
    error_log('Admission error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
