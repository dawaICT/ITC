<?php
$page_title = 'Nursing CA Upload';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/nav.php';
require_once dirname(__DIR__) . '/includes/finance_guard.php';
require_once dirname(__DIR__) . '/includes/ca_helpers.php';

$flash = wuc_get_flash();
ca_ensure_schema($db);

if (isset($_POST["submit"]) && isset($_FILES["file"])) {
    $file = $_FILES["file"]["tmp_name"];
    if (!$file || !is_uploaded_file($file)) {
        wuc_flash('danger', 'No file was uploaded or the file is invalid.');
        wuc_safe_redirect('nursing.php');
    }

    $file_open = fopen($file, "r");
    if (!$file_open) {
        wuc_flash('danger', 'Could not read the uploaded file.');
        wuc_safe_redirect('nursing.php');
    }

    $staff_id = $_SESSION['staff_id'];
    fgetcsv($file_open); // Skip headers

    $success = 0; $skipped = 0; $errors = 0;

    while (($csv = fgetcsv($file_open, 10000, ",")) !== false) {
        if (count($csv) < 8) { $skipped++; continue; }

        $Sid         = trim($csv[0]);
        $Course_Code = trim($csv[1]);
        // Nursing scaling: A1,A2 × 0.10, T1,T2 × 0.10
        $A1          = round(floatval($csv[2]), 2);
        $A2          = round(floatval($csv[3]), 2);
        $T1          = round(floatval($csv[4]), 2);
        $T2          = round(floatval($csv[5]), 2);
        $semester    = trim($csv[6]);
        $Year        = trim($csv[7]);

        foreach (['A1' => $A1, 'A2' => $A2, 'T1' => $T1, 'T2' => $T2] as $mark) {
            if ($mark < 0 || $mark > 100) { $skipped++; continue 2; }
        }

        // Year must be the 4-digit calendar academic year (matches the rest of
        // the CA flow); rejects a year-of-study value entered by mistake.
        $periodCheck = ca_validate_period($semester, $Year);
        if (!$periodCheck['ok']) { $skipped++; continue; }

        $elig = is_student_allowed_ca($db, $Sid, $Year, $semester);
        if (!$elig['allowed']) { $skipped++; continue; }

        $save = ca_save_components($db, $Sid, $Course_Code, $semester, $Year, 'nursing', [
            'A1' => $A1,
            'A2' => $A2,
            'T1' => $T1,
            'T2' => $T2,
            'Exam' => null,
        ], $staff_id);
        if ($save['ok']) { $success++; } else { $errors++; }
    }

    fclose($file_open);
    wuc_flash('success', "Nursing CA Upload: $success processed, $skipped skipped, $errors errors.");
    wuc_safe_redirect('upload_ca.php');
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Nursing CA Upload</h1>
                <p class="text-muted">CSV Upload for Registered Nursing programme marks</p>
            </div>
            <div class="col-auto">
                <a href="upload_ca.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Back</a>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($flash['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card shadow">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-file-csv me-2"></i>Upload Nursing CA CSV</h5></div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Nursing CA:</strong> Enter A1, A2, T1, and T2 as local CA marks out of 100. External examination marks are not uploaded here.
            </div>
            <form action="nursing.php" method="post" enctype="multipart/form-data">
                <div class="mb-3">
                    <label for="file" class="form-label">Select CSV File <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" id="file" name="file" accept=".csv" required>
                    <small class="text-muted">Format: SID, Course_Code, A1, A2, T1, T2, Semester, Year</small>
                </div>
                <button type="submit" name="submit" class="btn btn-primary">
                    <i class="fas fa-upload me-2"></i>Upload
                </button>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

