<?php
// Accounts-local soft delete for fee_structure
error_reporting(E_ALL);
ini_set('display_errors', 0);
session_start();

$root_path = dirname(dirname(dirname(__FILE__)));
require_once $root_path . '/db/connect.php';
require_once $root_path . '/includes/audit.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid fee structure ID']);
    exit;
}

$id = (int)$_POST['id'];

function syncShortCourseHeadlineFee(mysqli $db, int $shortCourseId) {
    if ($shortCourseId <= 0) {
        return;
    }

    $sql = "UPDATE short_courses SET fee = (
                SELECT COALESCE(SUM(amount), 0)
                FROM fee_structure
                WHERE short_course_id = ? AND entity_type = 'short_course' AND status = 'active'
            ) WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $shortCourseId, $shortCourseId);
    $stmt->execute();
    $stmt->close();
}

try {
    $db->begin_transaction();

    $feeMeta = null;
    $metaStmt = $db->prepare("SELECT entity_type, short_course_id FROM fee_structure WHERE id = ? LIMIT 1");
    $metaStmt->bind_param('i', $id);
    $metaStmt->execute();
    $feeMeta = $metaStmt->get_result()->fetch_assoc();
    $metaStmt->close();

    $sql = "UPDATE fee_structure SET status = 'inactive' WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        $user_id = $_SESSION['user_id'] ?? ($_SESSION['staff_id'] ?? 0);
        audit_log($db, (string)$user_id, 'delete_fee_structure', [
            'record_id' => $id,
            'entity_type' => $feeMeta['entity_type'] ?? 'program',
            'short_course_id' => $feeMeta['short_course_id'] ?? null,
        ]);
        if (($feeMeta['entity_type'] ?? 'program') === 'short_course' && !empty($feeMeta['short_course_id'])) {
            syncShortCourseHeadlineFee($db, (int)$feeMeta['short_course_id']);
        }
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Fee structure marked inactive']);
    } else {
        $db->rollback();
        echo json_encode(['success' => false, 'message' => 'Fee structure not found']);
    }
} catch (Throwable $e) {
    $db->rollback();
    echo json_encode(['success' => false, 'message' => 'Error deleting fee structure']);
}
?>


