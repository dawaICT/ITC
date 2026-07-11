<?php
define('IS_SCRIPT', true);
require_once "includes/admin.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['errorMssg'] = 'Invalid request method.';
    header('Location: applicants.php');
    exit;
}

$token = $_POST['token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    $_SESSION['errorMssg'] = 'Security token mismatch. Please try again.';
    header('Location: applicants.php');
    exit;
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($id <= 0) {
    $_SESSION['errorMssg'] = 'Invalid application selected.';
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
    $_SESSION['successMessage'] = 'Application rejected successfully.';
    header('Location: processedApp.php');
    exit;
} catch (Throwable $e) {
    $db->rollback();
    error_log('Reject applicant failed: ' . $e->getMessage());
    $_SESSION['errorMssg'] = $e->getMessage();
    header('Location: applicants.php');
    exit;
}
