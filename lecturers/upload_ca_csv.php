<?php
$page_title = 'Upload CA CSV';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/finance_guard.php';
require_once dirname(__DIR__) . '/includes/ca_helpers.php';
// isLecturerAssignedToCourse() lives here; upload_ca_csv.php must include it so
// the per-row assignment check below is callable (it was previously missing).
require_once dirname(__DIR__) . '/includes/elearning_access.php';

ca_ensure_schema($db);

$upload_log = []; // Collect per-row processing messages

if (isset($_POST['submit']) && isset($_FILES['file'])) {
    wuc_verify_csrf();

    // ── Feature flag: CSV upload enabled? ──────────────────────────
    if (ca_setting($db, 'ca_upload_csv_enabled', '1') !== '1') {
        wuc_flash('danger', 'CSV upload has been disabled by the administrator.');
        wuc_safe_redirect('upload_ca.php?tab=csv');
    }

    // ── Validate the uploaded file (error, size, extension, real upload) ──
    $upload = $_FILES['file'];
    if (!isset($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK) {
        wuc_flash('danger', 'CSV upload failed. Please select a valid CSV file.');
        wuc_safe_redirect('upload_ca.php?tab=csv');
    }

    $maxSize = 5 * 1024 * 1024; // 5 MB
    if ((int)($upload['size'] ?? 0) <= 0) {
        wuc_flash('danger', 'The selected CSV file appears to be empty.');
        wuc_safe_redirect('upload_ca.php?tab=csv');
    }
    if ((int)$upload['size'] > $maxSize) {
        wuc_flash('danger', 'CSV file is too large. Maximum allowed size is 5MB.');
        wuc_safe_redirect('upload_ca.php?tab=csv');
    }

    $ext = strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        wuc_flash('danger', 'Only CSV files are allowed.');
        wuc_safe_redirect('upload_ca.php?tab=csv');
    }

    $file = (string)($upload['tmp_name'] ?? '');
    if ($file === '' || !is_uploaded_file($file)) {
        wuc_flash('danger', 'No valid uploaded file was found.');
        wuc_safe_redirect('upload_ca.php?tab=csv');
    }

    $file_open = fopen($file, 'r');
    if (!$file_open) {
        wuc_flash('danger', 'Could not read the uploaded file.');
        wuc_safe_redirect('upload_ca.php?tab=csv');
    }

    $staff_id = (string)($_SESSION['staff_id'] ?? '');

    // ── Validate the header by NAME (not by column count) ──────────
    $header = fgetcsv($file_open, 10000, ',');
    if ($header === false || $header === null) {
        fclose($file_open);
        wuc_flash('danger', 'CSV file is empty or missing a header row.');
        wuc_safe_redirect('upload_ca.php?tab=csv');
    }
    // Strip a UTF-8 BOM the template writes ahead of the first header cell.
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }
    $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);

    $required = ['sid', 'course_code', 'a1', 'a2', 'a3', 't1', 't2', 'semester', 'year'];
    foreach ($required as $col) {
        if (!in_array($col, $header, true)) {
            fclose($file_open);
            wuc_flash('danger', "Missing required CSV column: {$col}");
            wuc_safe_redirect('upload_ca.php?tab=csv');
        }
    }
    $idx = array_flip($header);

    // ── Load term enforcement & CA window settings ─────────────────
    $enforceTerm = ca_setting($db, 'enforce_current_term', '1') === '1';
    $curAy  = ca_setting($db, 'current_academic_year', date('Y'));
    $curSem = ca_setting($db, 'current_semester', ((int)date('n') <= 6 ? '1' : '2'));
    $caWindows = ca_windows($db);

    // ── Strict numeric mark reader: '' => null, invalid => false (logged) ──
    $readMark = static function ($value, string $label, string $sid, array &$log) {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            $log[] = "Skipped {$sid}: {$label} must be numeric.";
            return false;
        }
        $mark = round((float)$value, 2);
        if ($mark < 0 || $mark > 100) {
            $log[] = "Skipped {$sid}: {$label} must be between 0 and 100.";
            return false;
        }
        return $mark;
    };

    $success = 0; $skipped = 0; $errors = 0;

    // ── Process each CSV row by header index ───────────────────────
    while (($csv = fgetcsv($file_open, 10000, ',')) !== false) {
        // Skip completely blank lines.
        if ($csv === [null] || count(array_filter($csv, static fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }
        // Silently skip template comment / instruction rows.
        $first = trim((string)($csv[0] ?? ''));
        if ($first !== '' && (strncmp($first, '---', 3) === 0 || preg_match('/^instructions:?$/i', $first) || preg_match('/^\d+\.\s/', $first))) {
            continue;
        }

        $Sid         = trim((string)($csv[$idx['sid']] ?? ''));
        $Course_Code = trim((string)($csv[$idx['course_code']] ?? ''));
        $semester    = trim((string)($csv[$idx['semester']] ?? ''));
        $Year        = trim((string)($csv[$idx['year']] ?? ''));

        if ($Sid === '' || $Course_Code === '' || $semester === '' || $Year === '') {
            $upload_log[] = 'Skipped row: SID, Course_Code, semester, and Year are required.';
            $skipped++; continue;
        }

        // Determine program type of the course
        $rowProgramType = 'semester';
        if (ca_table_exists($db, 'program_courses') && ca_table_exists($db, 'programs')) {
            $sqlProg = "SELECT p.academic_structure, p.period_mode 
                        FROM program_courses pc 
                        INNER JOIN programs p ON p.program_code = pc.program_code 
                        WHERE pc.course_code = ? LIMIT 1";
            if ($stmtProg = $db->prepare($sqlProg)) {
                $stmtProg->bind_param('s', $Course_Code);
                if ($stmtProg->execute()) {
                    $rowProg = $stmtProg->get_result()->fetch_assoc();
                    if ($rowProg) {
                        $structure = $rowProg['academic_structure'] ?? '';
                        if ($structure === 'short_course') {
                            $rowProgramType = 'short_course';
                        } elseif ($structure === 'semester_exception' || ($rowProg['period_mode'] ?? '') === 'semester') {
                            $rowProgramType = 'semester';
                        } else {
                            $rowProgramType = 'term';
                        }
                    }
                }
                $stmtProg->close();
            }
        }
        if ($rowProgramType === 'semester' && ca_table_exists($db, 'courses')) {
            if ($stmtC = $db->prepare("SELECT course_type FROM courses WHERE course_code = ? LIMIT 1")) {
                $stmtC->bind_param('s', $Course_Code);
                if ($stmtC->execute()) {
                    $resC = $stmtC->get_result()->fetch_assoc();
                    if ($resC && ($resC['course_type'] ?? '') === 'short course') {
                        $rowProgramType = 'short_course';
                    }
                }
                $stmtC->close();
            }
        }

        $periodCheck = ca_validate_period($semester, $Year, $rowProgramType);
        if (!$periodCheck['ok']) {
            $upload_log[] = "Skipped {$Sid}: " . $periodCheck['message'];
            $skipped++; continue;
        }

        // ── Lecturer must be assigned to the course (parity with manual) ──
        if (!isLecturerAssignedToCourse($db, $staff_id, $Course_Code)) {
            $upload_log[] = "Skipped {$Sid}: You are not assigned to {$Course_Code}.";
            $skipped++; continue;
        }

        // ── Read marks strictly (rejects non-numeric / out-of-range) ──
        $A1 = $readMark($csv[$idx['a1']] ?? '', 'A1', $Sid, $upload_log);
        $A2 = $readMark($csv[$idx['a2']] ?? '', 'A2', $Sid, $upload_log);
        $A3 = $readMark($csv[$idx['a3']] ?? '', 'A3', $Sid, $upload_log);
        $T1 = $readMark($csv[$idx['t1']] ?? '', 'T1', $Sid, $upload_log);
        $T2 = $readMark($csv[$idx['t2']] ?? '', 'T2', $Sid, $upload_log);
        if ($A1 === false || $A2 === false || $A3 === false || $T1 === false || $T2 === false) {
            $skipped++; continue; // reason already logged by $readMark
        }

        // ── Enforce term restriction ───────────────────────────────
        if ($enforceTerm && ($Year !== $curAy || $semester !== $curSem)) {
            $upload_log[] = "Skipped {$Sid}: Term not current (Y{$Year}/S{$semester}).";
            $skipped++; continue;
        }

        // ── Enforce component upload windows ───────────────────────
        $blocked = false;
        foreach (['A1' => $A1, 'A2' => $A2, 'T1' => $T1, 'T2' => $T2] as $label => $val) {
            $check = ca_window_allows($caWindows, $label, $val === null ? 0.0 : (float)$val);
            if (!$check['ok']) {
                $upload_log[] = "Skipped {$Sid}: {$label} window is closed.";
                $blocked = true; break;
            }
        }
        if ($blocked) { $skipped++; continue; }

        // ── Enforce 50% fee payment ────────────────────────────────
        $elig = is_student_allowed_ca($db, $Sid, $Year, $semester);
        if (!$elig['allowed']) {
            $upload_log[] = "Skipped {$Sid}: Paid " . round((float)$elig['percent'], 1) . "% (min 50%).";
            $skipped++; continue;
        }

        // ── Check course registration ──────────────────────────────
        if (!ca_student_registered($db, $Sid, $Course_Code, $semester, $Year)) {
            $upload_log[] = "Skipped {$Sid}: Not registered for {$Course_Code} (Y{$Year}/S{$semester}).";
            $skipped++; continue;
        }

        if (ca_is_external_short_course($db, $Course_Code)) {
            $upload_log[] = "Skipped {$Sid}: Course {$Course_Code} is an externally examined short course (no CA permitted).";
            $skipped++; continue;
        }

        // ── Save (ca_save_components runs its own per-row transaction
        //    and preserves existing components / recalculates Total_CA) ──
        $save = ca_save_components($db, $Sid, $Course_Code, $semester, $Year, $rowProgramType, [
            'A1' => $A1,
            'A2' => $A2,
            'A3' => $A3,
            'T1' => $T1,
            'T2' => $T2,
            'Exam' => null,
        ], $staff_id);

        if ($save['ok']) {
            $success++;
        } else {
            $upload_log[] = "Error {$Sid}: " . $save['message'];
            $errors++;
        }
    }

    fclose($file_open);

    // Hand the per-row processing notes to the combined page for display.
    if (!empty($upload_log)) {
        $_SESSION['_upload_log'] = $upload_log;
    }

    if ($success > 0) {
        wuc_flash('success', "CSV CA Upload complete: {$success} processed, {$skipped} skipped, {$errors} errors.");
    } elseif ($skipped > 0 || $errors > 0) {
        wuc_flash('warning', "CSV CA Upload finished with no marks saved: {$success} processed, {$skipped} skipped, {$errors} errors. Review the processing details below.");
    } else {
        wuc_flash('warning', 'No CA marks were saved. The CSV file had no eligible student rows. Confirm course registrations, academic year, and term/semester before uploading.');
    }
    wuc_safe_redirect('upload_ca.php?tab=csv');
}

// ── GET view (standalone fallback; the main workflow lives in upload_ca.php) ──
require_once __DIR__ . '/includes/nav.php';

$flash = wuc_get_flash();
$prev_log = [];
if (isset($_SESSION['_upload_log'])) {
    $prev_log = (array)$_SESSION['_upload_log'];
    unset($_SESSION['_upload_log']);
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Upload CA via CSV</h1>
                <p class="text-muted">Bulk upload continuous assessment marks from a CSV file</p>
            </div>
            <div class="col-auto">
                <a href="upload_ca.php?tab=csv" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Back</a>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show" role="alert">
        <i class="fas fa-<?php echo ($flash['type'] ?? '') === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
        <?php echo htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (!empty($prev_log)): ?>
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="fas fa-list me-2"></i>Processing Details (<?php echo count($prev_log); ?> notes)</h6>
        </div>
        <div class="card-body" style="max-height:300px;overflow-y:auto;">
            <?php foreach ($prev_log as $msg): ?>
            <div class="text-warning small"><i class="fas fa-exclamation-triangle me-1"></i><?php echo htmlspecialchars((string)$msg, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-file-csv me-2"></i>Upload CSV File</h5></div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Format:</strong> SID, Course_Code, A1, A2, A3, T1, T2, semester, Year<br>
                <small>First row is the header. Enter each raw CA component out of 100; weighting and the annual 100% ceiling are applied automatically. Leave non-applicable components blank.</small>
            </div>
            <form action="upload_ca_csv.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <div class="mb-3">
                    <label for="file" class="form-label">Select CSV File <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" id="file" name="file" accept=".csv" required>
                </div>
                <button type="submit" name="submit" value="1" class="btn btn-primary">
                    <i class="fas fa-upload me-2"></i>Upload &amp; Process
                </button>
                <a href="download_ca_template.php" class="btn btn-outline-success ms-2">
                    <i class="fas fa-download me-2"></i>Download Template
                </a>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
