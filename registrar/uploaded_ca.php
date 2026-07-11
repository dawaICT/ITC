<?php
/**
 * Registrar – Uploaded CA Results (CSV import processor)
 *
 * Rewritten to use the central ca_helpers.php and result_entry_helpers.php
 * logic (identical to the admin import flow) to validate and import rows securely,
 * while utilizing the modern portal design system.
 *
 * Unified nav (includes/nav.php) handles session, DB, auth, and header chrome.
 */

require dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/ca_helpers.php';
require_once dirname(__DIR__) . '/includes/result_entry_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Access control: only Registrar (and Systems Admin) can access
if (!isset($_SESSION['staff_id'])) {
    $_SESSION['loginMaster'] = 'Please you need to login!';
    header('Location: /wucportal/index.php');
    exit;
}

$allowedRoles = array('Registrar', 'Systems Admin');
$userRole = null;
if ($stmt = $db->prepare("SELECT ar.assigned_access FROM access_right ar WHERE ar.staff_id = ? LIMIT 1")) {
    $stmt->bind_param('s', $_SESSION['staff_id']);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows) {
        $row = $res->fetch_assoc();
        $userRole = $row['assigned_access'];
    }
    $stmt->close();
}
if ($userRole !== null && !in_array($userRole, $allowedRoles, true)) {
    header('Location: /wucportal/error/404.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['import'])) {
    $_SESSION['errorMsg'] = 'Please choose a CA CSV file to upload.';
    header('Location: upload_ca.php');
    exit;
}

$year     = trim((string)($_POST['Year'] ?? ''));
$semester = trim((string)($_POST['semester'] ?? ''));

if (!preg_match('/^\d{4}$/', $year) || (int)$year < 2000 || (int)$year > ((int)date('Y') + 1)) {
    $_SESSION['errorMsg'] = 'Select a valid academic year.';
    header('Location: upload_ca.php');
    exit;
}

if (!in_array($semester, ['1', '2', '3'], true)) {
    $_SESSION['errorMsg'] = 'Select a valid semester or term.';
    header('Location: upload_ca.php');
    exit;
}

if (!isset($_FILES['file']) || (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $_SESSION['errorMsg'] = 'The CSV file could not be uploaded. Please try again.';
    header('Location: upload_ca.php');
    exit;
}

$fileName = (string)($_FILES['file']['name'] ?? '');
$tmpName  = (string)($_FILES['file']['tmp_name'] ?? '');
if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'csv' || !is_uploaded_file($tmpName)) {
    $_SESSION['errorMsg'] = 'Only CSV files are supported.';
    header('Location: upload_ca.php');
    exit;
}

ca_ensure_schema($db);

$staffId = (string)$_SESSION['staff_id'];
$summary = [
    'saved'   => 0,
    'failed'  => 0,
    'skipped' => 0,
];
$messages = [];

function upload_ca_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function upload_ca_mark($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return false;
    }
    $mark = round((float)$value, 2);
    return ($mark >= 0 && $mark <= 100) ? $mark : false;
}

$handle = fopen($tmpName, 'r');
if (!$handle) {
    $_SESSION['errorMsg'] = 'Could not read the uploaded CSV file.';
    header('Location: upload_ca.php');
    exit;
}

$rowNumber = 0;
while (($row = fgetcsv($handle, 10000, ',')) !== false) {
    $rowNumber++;
    $row = array_map(static function ($value) {
        return trim((string)$value);
    }, $row);

    // Skip Header row if detected
    if ($rowNumber === 1 && isset($row[0], $row[1])) {
        $first  = strtolower(ltrim($row[0], "\xEF\xBB\xBF"));
        $second = strtolower($row[1]);
        if ($first === 'sid' && in_array($second, ['course_code', 'course code'], true)) {
            $summary['skipped']++;
            continue;
        }
    }

    // Skip empty rows
    if (count(array_filter($row, static fn($value) => $value !== '')) === 0) {
        $summary['skipped']++;
        continue;
    }

    if (count($row) < 7) {
        $summary['failed']++;
        $messages[] = ['type' => 'danger', 'text' => "Row {$rowNumber}: expected at least 7 columns (Sid, Course_Code, A1, A2, A3, T1, T2)."];
        continue;
    }

    $sid        = ltrim((string)$row[0], "\xEF\xBB\xBF");
    $courseCode = strtoupper((string)$row[1]);
    if ($sid === '' || $courseCode === '') {
        $summary['failed']++;
        $messages[] = ['type' => 'danger', 'text' => "Row {$rowNumber}: student ID and course code are required."];
        continue;
    }

    $components = [];
    $columns    = ['A1', 'A2', 'A3', 'T1', 'T2'];
    $validMark  = true;
    foreach ($columns as $offset => $component) {
        $mark = upload_ca_mark($row[$offset + 2] ?? '');
        if ($mark === false) {
            $summary['failed']++;
            $messages[] = ['type' => 'danger', 'text' => "Row {$rowNumber}: {$component} must be a number from 0 to 100."];
            $validMark = false;
            break;
        }
        $components[$component] = $mark;
    }
    if (!$validMark) {
        continue;
    }

    $detectedType = function_exists('result_detect_programme_type')
        ? result_detect_programme_type($db, $sid, $courseCode)
        : 'semester';
    $programType = $detectedType === 'short_course' ? 'short_course' : 'semester';

    if ($programType === 'short_course' && function_exists('result_validate_short_course_entry')) {
        $eligibility = result_validate_short_course_entry($db, $sid, $courseCode, date('Y-m-d'));
        if (!($eligibility['ok'] ?? false)) {
            $summary['failed']++;
            $messages[] = ['type' => 'danger', 'text' => "Row {$rowNumber}: " . ($eligibility['message'] ?? 'Student is not eligible for this short course.')];
            continue;
        }
    }

    // Save CA marks using the helper function
    $result = ca_save_components($db, $sid, $courseCode, $semester, $year, $programType, $components, $staffId);
    if ($result['ok'] ?? false) {
        $summary['saved']++;
        $messages[] = [
            'type' => 'success',
            'text' => "Row {$rowNumber}: saved {$sid} / {$courseCode} ({$programType}) with Total CA " . number_format((float)($result['total_ca'] ?? 0), 2) . '.',
        ];
    } else {
        $summary['failed']++;
        $messages[] = ['type' => 'danger', 'text' => "Row {$rowNumber}: " . ($result['message'] ?? 'Failed to save CA row.')];
    }
}
fclose($handle);

$page_title = 'Uploaded CA Results';
require __DIR__ . '/includes/nav.php';
?>

<style>
    .uploaded-ca-page {
        padding-top: 1.25rem;
        padding-bottom: 2rem;
    }

    .uploaded-ca-page .page-header,
    .uploaded-ca-page .data-table-card,
    .uploaded-ca-page .summary-card {
        background: #fff !important;
        border: 1px solid #e5eaf2 !important;
        border-radius: 10px !important;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06) !important;
    }

    .uploaded-ca-page .page-header {
        padding: 1.1rem 1.25rem;
    }

    .uploaded-ca-page .page-title {
        color: #14213d;
        font-size: 1.05rem;
        font-weight: 700;
    }

    .uploaded-ca-page .summary-card {
        padding: 1.25rem;
    }

    .uploaded-ca-page .summary-card strong {
        display: block;
        color: #172033;
        font-size: 1.5rem;
        line-height: 1.1;
    }

    .uploaded-ca-page .summary-card span {
        color: #64748b;
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .uploaded-ca-page .result-list {
        max-height: 520px;
        overflow-y: auto;
        padding-right: 5px;
    }

    .uploaded-ca-page .result-list::-webkit-scrollbar {
        width: 6px;
    }
    .uploaded-ca-page .result-list::-webkit-scrollbar-thumb {
        background-color: #cbd5e1;
        border-radius: 3px;
    }
</style>

<div class="container-fluid px-4 portal-dashboard uploaded-ca-page">
    <!-- Page Header -->
    <div class="page-header mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-file-import me-2 text-primary"></i>Uploaded CA Results</h5>
                <p class="page-subtitle mb-0">Semester / Term <?= upload_ca_h($semester) ?>, <?= upload_ca_h($year) ?></p>
            </div>
            <a href="upload_ca.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Upload
            </a>
        </div>
    </div>

    <!-- Summary Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="summary-card border-start border-success border-4">
                <strong><?= (int)$summary['saved'] ?></strong>
                <span class="text-success">Saved / Updated</span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="summary-card border-start border-danger border-4">
                <strong><?= (int)$summary['failed'] ?></strong>
                <span class="text-danger">Failed Rows</span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="summary-card border-start border-secondary border-4">
                <strong><?= (int)$summary['skipped'] ?></strong>
                <span class="text-secondary">Skipped Rows</span>
            </div>
        </div>
    </div>

    <!-- Import Log Card -->
    <div class="data-table-card">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 text-primary"><i class="fas fa-list me-2"></i>Import Log</h5>
        </div>
        <div class="card-body">
            <?php if (empty($messages)): ?>
                <div class="alert alert-info mb-0">No data rows were processed from the uploaded CSV file.</div>
            <?php else: ?>
                <div class="result-list">
                    <?php foreach ($messages as $message): ?>
                        <div class="alert alert-<?= upload_ca_h($message['type']) ?> py-2 mb-2 d-flex align-items-center gap-2">
                            <?php if ($message['type'] === 'success'): ?>
                                <i class="fas fa-check-circle text-success"></i>
                            <?php else: ?>
                                <i class="fas fa-exclamation-circle text-danger"></i>
                            <?php endif; ?>
                            <div><?= upload_ca_h($message['text']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
