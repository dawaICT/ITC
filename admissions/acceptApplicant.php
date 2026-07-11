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
	header('Location: processedApp.php');
	exit;
}

$id = (int) $_POST['mov'];
if ($id <= 0) {
	$_SESSION['errorMssg'] = 'Invalid application.';
	header('Location: processedApp.php');
	exit;
}

// Helper: fetch table column names
function fetchTableColumns(mysqli $db, string $table): array {
	$cols = [];
	if ($res = $db->query("SHOW COLUMNS FROM `".$db->real_escape_string($table)."`")) {
		while ($row = $res->fetch_assoc()) { $cols[] = (string)$row['Field']; }
		$res->free();
	}
	return $cols;
}

$db->begin_transaction();

$srcCols = fetchTableColumns($db, 'online_applicants');
$dstCols = fetchTableColumns($db, 'processed_applicants');

if (empty($srcCols) || empty($dstCols)) {
	$db->rollback();
	$_SESSION['errorMssg'] = 'Schema read error.';
	header('Location: processedApp.php');
	exit;
}

$common = array_values(array_intersect($srcCols, $dstCols));
$common = array_values(array_filter($common, function($c){ return strtolower($c) !== 'id'; }));

if (empty($common)) {
	$db->rollback();
	$_SESSION['errorMssg'] = 'No compatible columns to transfer.';
	header('Location: processedApp.php');
	exit;
}

$colsList = '`' . implode('`,`', $common) . '`';
$insertSql = "INSERT INTO processed_applicants ($colsList) SELECT $colsList FROM online_applicants WHERE id = ?";

if (!$stmt = $db->prepare($insertSql)) {
	$db->rollback();
	$_SESSION['errorMssg'] = 'Failed to prepare insert.';
	header('Location: processedApp.php');
	exit;
}

$stmt->bind_param('i', $id);
if ($stmt->execute() && $stmt->affected_rows > 0) {
	$stmt->close();
	$newId = $db->insert_id;
	$update_stmt = $db->prepare("UPDATE processed_applicants SET status = 'accepted' WHERE id = ?");
	$update_stmt->bind_param('i', $newId);
	$update_stmt->execute();
	$update_stmt->close();
	// The idx_dte_adm index on processed_applicants is owned by a migration
	// (applied as wucportal_migrator) and already exists. It must NOT be
	// (re)created here at runtime: the DML-only app user lacks ALTER, so under
	// mysqli STRICT mode this threw "ALTER command denied" and crashed the accept
	// transaction (the rollback below was never reached) — and it did so even
	// though the index exists, because MySQL checks the ALTER privilege before it
	// evaluates IF NOT EXISTS.
	if ($del = $db->prepare("DELETE FROM online_applicants WHERE id = ?")) {
		$del->bind_param('i', $id);
		if ($del->execute()) {
			$del->close();
			$db->commit();

			// Decoupled activation of student record, portal login, and billing accounts
			require_once __DIR__ . '/../includes/applicant_admission.php';
			$admitResult = admitProcessedApplicant($db, $newId, (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? ''));

			if ($admitResult['success']) {
				// Record the successful conversion so the application list reflects "Already Added"
				$added_by = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
				$track_ins = $db->prepare("INSERT INTO processed_applicants_added (applicant_id, student_id, added_by) VALUES (?, ?, ?)");
				if ($track_ins) {
					$track_ins->bind_param('iss', $newId, $admitResult['student_id'], $added_by);
					$track_ins->execute();
					$track_ins->close();
				}
				$_SESSION['successMssg'] = "Application accepted and student account activated successfully. Generated Student ID: " . $admitResult['student_id'];
			} else {
				$_SESSION['successMssg'] = "Application accepted, but auto-admission warning: " . $admitResult['message'];
			}

			header('Location: processedApp.php');
			exit;
		}
		$del->close();
	}
}

// Failure path
$db->rollback();
if (isset($stmt) && $stmt) { $stmt->close(); }
$_SESSION['errorMssg'] = 'Failed to process application.';
header('Location: processedApp.php');
exit;

?>