<?php
require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/ca_helpers.php';
require_once dirname(__DIR__) . '/includes/result_entry_helpers.php';

$page_title = 'Uploaded CA Results';
$staffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
$summary = [
    'saved' => 0,
    'failed' => 0,
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['import'])) {
    $_SESSION['errorMsg'] = 'Please choose a CA CSV file to upload.';
    header('Location: upload_ca.php');
    exit;
}

$year = trim((string)($_POST['Year'] ?? ''));
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
$tmpName = (string)($_FILES['file']['tmp_name'] ?? '');
if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'csv' || !is_uploaded_file($tmpName)) {
    $_SESSION['errorMsg'] = 'Only CSV files are supported.';
    header('Location: upload_ca.php');
    exit;
}

ca_ensure_schema($db);

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

    if ($rowNumber === 1 && isset($row[0], $row[1])) {
        $first = strtolower(ltrim($row[0], "\xEF\xBB\xBF"));
        $second = strtolower($row[1]);
        if ($first === 'sid' && in_array($second, ['course_code', 'course code'], true)) {
            $summary['skipped']++;
            continue;
        }
    }

    if (count(array_filter($row, static fn($value) => $value !== '')) === 0) {
        $summary['skipped']++;
        continue;
    }

    if (count($row) < 7) {
        $summary['failed']++;
        $messages[] = ['type' => 'danger', 'text' => "Row {$rowNumber}: expected 7 columns."];
        continue;
    }

    $sid = ltrim((string)$row[0], "\xEF\xBB\xBF");
    $courseCode = strtoupper((string)$row[1]);
    if ($sid === '' || $courseCode === '') {
        $summary['failed']++;
        $messages[] = ['type' => 'danger', 'text' => "Row {$rowNumber}: student ID and course code are required."];
        continue;
    }

    $components = [];
    $columns = ['A1', 'A2', 'A3', 'T1', 'T2'];
    foreach ($columns as $offset => $component) {
        $mark = upload_ca_mark($row[$offset + 2] ?? '');
        if ($mark === false) {
            $summary['failed']++;
            $messages[] = ['type' => 'danger', 'text' => "Row {$rowNumber}: {$component} must be a number from 0 to 100."];
            continue 2;
        }
        $components[$component] = $mark;
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

require_once __DIR__ . '/includes/header.php';
?>

<style>
    html,
    body.has-unified-sidebar,
    body.has-unified-sidebar .main-wrapper,
    body.has-unified-sidebar .main-content {
        background: #f4f7fb !important;
        color: #1f2937;
    }

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
        padding: 1rem;
    }

    .uploaded-ca-page .summary-card strong {
        display: block;
        color: #172033;
        font-size: 1.35rem;
        line-height: 1.1;
    }

    .uploaded-ca-page .summary-card span {
        color: #64748b;
        font-size: 0.82rem;
    }

    .uploaded-ca-page .result-list {
        max-height: 520px;
        overflow: auto;
    }

    @media (max-width: 767.98px) {
        .uploaded-ca-page {
            padding-left: 0.8rem !important;
            padding-right: 0.8rem !important;
            padding-top: 4.25rem;
        }
    }
</style>

<div class="container-fluid px-4 portal-dashboard uploaded-ca-page">
    <div class="page-header mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-file-import me-2 text-primary"></i>Uploaded CA Results</h5>
                <p class="page-subtitle mb-0">Semester / Term <?= upload_ca_h($semester) ?>, <?= upload_ca_h($year) ?></p>
            </div>
            <a href="upload_ca.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Upload
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="summary-card">
                <strong><?= (int)$summary['saved'] ?></strong>
                <span>Saved or updated</span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="summary-card">
                <strong><?= (int)$summary['failed'] ?></strong>
                <span>Failed rows</span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="summary-card">
                <strong><?= (int)$summary['skipped'] ?></strong>
                <span>Skipped rows</span>
            </div>
        </div>
    </div>

    <div class="data-table-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Import Log</h5>
        </div>
        <div class="card-body">
            <?php if (empty($messages)): ?>
                <div class="alert alert-info mb-0">No data rows were found in the uploaded CSV file.</div>
            <?php else: ?>
                <div class="result-list">
                    <?php foreach ($messages as $message): ?>
                        <div class="alert alert-<?= upload_ca_h($message['type']) ?> py-2 mb-2">
                            <?= upload_ca_h($message['text']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
