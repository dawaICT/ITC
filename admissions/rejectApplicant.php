<?php
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';

initializeSession();
if (!checkSessionTimeout(30) || !isAdminAuthenticated()) {
    $_SESSION['errorMssg'] = 'Session expired or unauthorized access.';
    header('Location: /wucportal/staff_login.php');
    exit;
}

// Environment-controlled error reporting
$WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
if ($WUC_ENV === 'development') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// Validate input
if (!isset($_POST['mov']) || !isset($_POST['csrf_token'])
    || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['csrf_token'])) {
    $_SESSION['errorMssg'] = 'Invalid request.';
    header('Location: applicants.php');
    exit;
}

$id = (int) $_POST['mov'];
if ($id <= 0) {
    $_SESSION['errorMssg'] = 'Invalid application.';
    header('Location: applicants.php');
    exit;
}

function fetchApplicantTableColumns(mysqli $db, string $table): array {
    $columns = [];
    $table = $db->real_escape_string($table);
    if ($result = $db->query("SHOW COLUMNS FROM `{$table}`")) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = (string) $row['Field'];
        }
        $result->free();
    }
    return $columns;
}

$db->begin_transaction();

try {
    $onlineCols = fetchApplicantTableColumns($db, 'online_applicants');
    $processedCols = fetchApplicantTableColumns($db, 'processed_applicants');

    if (empty($onlineCols) || empty($processedCols)) {
        throw new RuntimeException('Failed to read application table schema.');
    }

    $common = array_values(array_intersect($onlineCols, $processedCols));
    $common = array_values(array_filter($common, static function ($column) {
        return strtolower((string) $column) !== 'id';
    }));

    if (empty($common)) {
        throw new RuntimeException('No compatible application columns found.');
    }

    $colsList = '`' . implode('`,`', $common) . '`';
    $insertSql = "INSERT INTO processed_applicants ({$colsList})
                  SELECT {$colsList}
                  FROM online_applicants
                  WHERE id = ? AND status = 'pending'";

    $insert = $db->prepare($insertSql);
    if (!$insert) {
        throw new RuntimeException('Failed to prepare rejection transfer.');
    }

    $insert->bind_param('i', $id);
    $insert->execute();
    $inserted = $insert->affected_rows;
    $newId = (int) $db->insert_id;
    $insert->close();

    if ($inserted <= 0 || $newId <= 0) {
        throw new RuntimeException('Application was not found or has already been processed.');
    }

    $update = $db->prepare("UPDATE processed_applicants SET status = 'rejected' WHERE id = ?");
    if (!$update) {
        throw new RuntimeException('Failed to prepare rejection status update.');
    }

    $update->bind_param('i', $newId);
    $update->execute();
    $update->close();

    $delete = $db->prepare("DELETE FROM online_applicants WHERE id = ?");
    if (!$delete) {
        throw new RuntimeException('Failed to prepare pending application cleanup.');
    }

    $delete->bind_param('i', $id);
    $delete->execute();
    $deleted = $delete->affected_rows;
    $delete->close();

    if ($deleted <= 0) {
        throw new RuntimeException('Failed to remove pending application.');
    }

    $db->commit();
    $_SESSION['successMssg'] = 'Application rejected successfully.';
} catch (Exception $e) {
    $db->rollback();
    $_SESSION['errorMssg'] = 'Failed to reject application: ' . $e->getMessage();
}

header('Location: applicants.php');
exit;
?>