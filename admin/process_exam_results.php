<?php
require "includes/admin.php";
require_once dirname(__DIR__) . '/includes/result_entry_helpers.php';
require_once dirname(__DIR__) . '/includes/grading_helpers.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
error_reporting(0);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (function_exists('canEnterExamMarks') && !canEnterExamMarks()) {
    $_SESSION['errorMsg'] = 'Access denied. You do not have permission to process exam marks.';
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/includes/header.php';

if(isset($_POST["submit"])) {
    $file = $_FILES["file"]["tmp_name"];
    if (!$file || !is_uploaded_file($file)) {
        $_SESSION['errorMsg'] = 'No valid CSV file was uploaded.';
        header('Location: process_exam_results.php');
        exit();
    }
    $file_open = fopen($file, "r");
    if (!$file_open) {
        $_SESSION['errorMsg'] = 'Unable to read uploaded CSV file.';
        header('Location: process_exam_results.php');
        exit();
    }
	$staff_id = $_SESSION['staff_id'];
    // Skip the first line if it contains column headers
    fgetcsv($file_open);
    $success = 0;
    $skipped = 0;
    $errors = [];

    while(($csv = fgetcsv($file_open, 10000, ",")) !== false) {
        if (count($csv) < 5) {
            $skipped++;
            continue;
        }
        $Sid = trim((string)$csv[0]);
		$Course_Code = trim((string)$csv[1]);
        $Exam_marks = (int)round((float)$csv[2]);
		$semester = trim((string)$csv[3]);
        $Year = trim((string)$csv[4]);
        $assessmentDate = trim((string)($csv[5] ?? date('Y-m-d')));

        if ($Sid === '' || $Course_Code === '' || $semester === '' || $Year === '' || $Exam_marks < 0 || $Exam_marks > 100) {
            $errors[] = "Skipped row for {$Sid}: invalid required data or mark.";
            $skipped++;
            continue;
        }

        $eligibility = result_validate_entry($db, $Sid, $Course_Code, $semester, $Year, $assessmentDate);
        if (!$eligibility['ok']) {
            $errors[] = "Skipped {$Sid}/{$Course_Code}: " . $eligibility['message'];
            $skipped++;
            continue;
        }

        // Persist each row's exam mark to the canonical marks table via the
        // shared helper (status=Submitted + audit). Replaces the dead `exams` writes.
        $programType = (($eligibility['type'] ?? '') === 'short_course') ? 'short_course' : 'semester';
        $save = result_save_exam_mark($db, $Sid, $Course_Code, $semester, $Year, (float)$Exam_marks, $programType, $staff_id);
        if ($save['ok']) {
            $success++;
        } else {
            $errors[] = "Failed {$Sid}/{$Course_Code}: " . $save['message'];
            $skipped++;
        }
    }
    fclose($file_open);

    $_SESSION['successMsg'] = "Bulk result upload complete: {$success} saved, {$skipped} skipped.";
    if (!empty($errors)) {
        $_SESSION['errorMsg'] = implode('<br>', array_map(fn($m) => htmlspecialchars($m, ENT_QUOTES, 'UTF-8'), array_slice($errors, 0, 10)));
    }
    header('Location: process_exam_results.php');
    exit();
}
?>

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <h1 class="dashboard-title"><i class="fas fa-upload me-2"></i>Bulk Upload Final Results</h1>
    <p class="text-muted">Upload a CSV file with exam results</p>
  </div>

  <div class="data-table-card">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-upload me-2"></i>Upload CSV</h5>
      </div>
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-3">
        <div class="col-md-6">
          <label class="form-label">CSV File</label>
          <input type="file" name="file" class="form-control" accept=".csv" required>
        </div>
        <div class="col-md-6 d-flex align-items-end">
          <button class="btn btn-primary" name="submit" type="submit"><i class="fas fa-upload me-2"></i>Upload</button>
          <a href="upload_finalExams.php" class="btn btn-outline-secondary ms-2">Back</a>
        </div>
      </form>
      <small class="text-muted d-block mt-2">Expected columns: Sid, Course_Code, Exam_marks, Semester, Year</small>
      <small class="text-muted d-block">Optional sixth column: Assessment_Date (YYYY-MM-DD) for short-course duration validation.</small>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; $db->close(); ?>

