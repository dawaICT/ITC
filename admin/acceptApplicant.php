<?php
define('IS_SCRIPT', true);
require_once "includes/admin.php";

// Basic validation - Accept POST requests for better security
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['errorMssg'] = 'Invalid request method.';
    header('Location: applicants.php');
    exit;
}

if (!isset($_POST['mov'], $_POST['token'])) {
    $_SESSION['errorMssg'] = 'Invalid request.';
    header('Location: applicants.php');
    exit;
}

$id = (int) $_POST['mov'];
$token = $_POST['token'];

if ($id <= 0) {
    $_SESSION['errorMssg'] = 'Invalid application.';
    header('Location: applicants.php');
    exit;
}

if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    $_SESSION['errorMssg'] = 'Security token mismatch. Please try again.';
    header('Location: applicants.php');
    exit;
}

// Helper to fetch column names for a table
function fetchTableColumns(mysqli $db, string $table): array {
    $cols = [];
    if ($res = $db->query("SHOW COLUMNS FROM `".$db->real_escape_string($table)."`")) {
        while ($row = $res->fetch_assoc()) { $cols[] = (string)$row['Field']; }
        $res->free();
    }
    return $cols;
}

// Transactionally move record: online_applicants -> processed_applicants using only common columns
$db->begin_transaction();

$onlineCols = fetchTableColumns($db, 'online_applicants');
$processedCols = fetchTableColumns($db, 'processed_applicants');

if (empty($onlineCols) || empty($processedCols)) {
    $db->rollback();
    $_SESSION['errorMssg'] = 'Failed to read table schema.';
    header('Location: applicants.php');
    exit;
}

$common = array_values(array_intersect($onlineCols, $processedCols));
// Exclude auto ID columns if present
$common = array_values(array_filter($common, function($c){ return strtolower($c) !== 'id'; }));

if (empty($common)) {
    $db->rollback();
    $_SESSION['errorMssg'] = 'No compatible columns to transfer.';
    header('Location: applicants.php');
    exit;
}

$colsList = '`' . implode('`,`', $common) . '`';
$insertSql = "INSERT INTO processed_applicants ($colsList) SELECT $colsList FROM online_applicants WHERE id = ?";

if (!$stmt = $db->prepare($insertSql)) {
    $db->rollback();
    $_SESSION['errorMssg'] = 'Failed to prepare insert.';
    header('Location: applicants.php');
    exit;
}

$stmt->bind_param('i', $id);
if ($stmt->execute() && $stmt->affected_rows > 0) {
    $stmt->close();

    // Explicitly set status to accepted on the transferred row.
    $newId = $db->insert_id;
    if ($upd = $db->prepare("UPDATE processed_applicants SET status = 'accepted' WHERE id = ?")) {
        $upd->bind_param('i', $newId);
        $upd->execute();
        $upd->close();
    }

    if ($del = $db->prepare("DELETE FROM online_applicants WHERE id = ?")) {
        $del->bind_param('i', $id);
        if ($del->execute()) {
            $del->close();
            $db->commit();
            $_SESSION['successMssg'] = 'Application processed successfully.';
            header('Location: processedApp.php');
            exit;
        }
        $del->close();
    }
}

// If we reach here, something failed
$db->rollback();
if (isset($stmt) && $stmt) { $stmt->close(); }
$_SESSION['errorMssg'] = 'Failed to process application.';
header('Location: applicants.php');
exit;
?>
