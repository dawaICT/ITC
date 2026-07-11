<?php
// Accounts-local Add Fee Structure (wraps admin logic with path fixes)
ini_set('display_errors', '0');
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Buffer output to allow redirects after nav include
ob_start();
session_start();

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/db/connect.php';
require_once ROOT_PATH . '/includes/helpers/academic_period_helpers.php';
require_once __DIR__ . '/includes/nav.php';

$page_title = "Add New Fee Structure";

function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

function normalizeFeeType($value) {
    $allowed = ['registration', 'tuition', 'materials', 'deposit', 'balance', 'other', 'id_card', 'library', 'examination'];
    $value = strtolower(trim((string)$value));
    return in_array($value, $allowed, true) ? $value : null;
}

function feeTypeLabel($value) {
    return ucwords(str_replace('_', ' ', (string)$value));
}

function accountsFeeColumnExists(mysqli $db, string $column): bool {
    if ($res = $db->query("SHOW COLUMNS FROM fee_structure LIKE '" . $db->real_escape_string($column) . "'")) {
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
    return false;
}

function syncShortCourseHeadlineFee(mysqli $db, int $shortCourseId) {
    if ($shortCourseId <= 0) {
        return;
    }

    if (!accountsFeeColumnExists($db, 'short_course_id') || !accountsFeeColumnExists($db, 'entity_type')) {
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

if (isset($_POST['add_fee_structure'])) {
    try {
        $entity_type = (isset($_POST['entity_type']) && $_POST['entity_type'] === 'short_course') ? 'short_course' : 'program';
        $program_code = ($entity_type === 'program' && isset($_POST['program_code'])) ? sanitizeInput($_POST['program_code']) : null;
        $short_course_id = ($entity_type === 'short_course' && isset($_POST['short_course_id'])) ? (int)$_POST['short_course_id'] : null;
        $year_of_study = $entity_type === 'program' ? (int)($_POST['year_of_study'] ?? 0) : null;
        $semester = $entity_type === 'program' ? (int)($_POST['semester'] ?? 0) : null;
        $fee_type = normalizeFeeType($_POST['fee_type'] ?? '');
        $fee_description = isset($_POST['fee_description']) ? sanitizeInput($_POST['fee_description']) : '';
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.00;

        $tuition_fee = $entity_type === 'program' ? (float)($_POST['tuition_fee'] ?? 0) : 0;
        $id_card_fee = $entity_type === 'program' ? (float)($_POST['id_card_fee'] ?? 0) : 0;
        $registration_fee = $entity_type === 'program' ? (float)($_POST['registration_fee'] ?? 0) : 0;
        $library_fee = $entity_type === 'program' ? (float)($_POST['library_fee'] ?? 0) : 0;
        $exam_fee = $entity_type === 'program' ? (float)($_POST['exam_fee'] ?? 0) : 0;
        $other_fee = $entity_type === 'program' ? (float)($_POST['other_fee'] ?? 0) : 0;

        $breakdown_total = $tuition_fee + $id_card_fee + $registration_fee + $library_fee + $exam_fee + $other_fee;
        $hasFeeMetadata = accountsFeeColumnExists($db, 'entity_type')
            && accountsFeeColumnExists($db, 'short_course_id')
            && accountsFeeColumnExists($db, 'fee_type');

        if ($entity_type === 'short_course' && !$hasFeeMetadata) {
            if (empty($short_course_id)) { throw new Exception("Short course is required"); }
            if ($amount <= 0) { throw new Exception("Amount must be a positive number"); }
            $stmt = $db->prepare("UPDATE short_courses SET fee = ? WHERE id = ?");
            if (!$stmt) { throw new Exception("Prepare failed: " . $db->error); }
            $stmt->bind_param('di', $amount, $short_course_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_message'] = 'Short course headline fee updated successfully';
            $_SESSION['flash_type'] = 'success';
            header('Location: programFees.php');
            exit;
        }

        if ($fee_description === '' && $fee_type !== null) {
            $fee_description = feeTypeLabel($fee_type);
        }

        $periodMeta = ($entity_type === 'program' && $program_code !== '')
            ? getProgramAcademicStructure($db, $program_code)
            : ['period_mode' => 'semester', 'period_label' => 'Semester', 'max_periods' => 2];
        $period_type = $periodMeta['period_mode'];
        $max_period = (int)$periodMeta['max_periods'];

        $errors = [];
        if ($entity_type === 'program') {
            if (empty($program_code)) { $errors[] = "Program code is required"; }
            if ($year_of_study < 1 || $year_of_study > 4) { $errors[] = "Year of study must be between 1 and 4"; }
            if ($semester < 1 || $semester > $max_period) {
                $errors[] = "{$periodMeta['period_label']} must be between 1 and {$max_period}";
            }
        } else {
            if (empty($short_course_id)) { $errors[] = "Short course is required"; }
            if ($fee_type === null) { $errors[] = "Fee type is required for short courses"; }
        }
        if ($breakdown_total <= 0) {
            if (empty($fee_description)) { $errors[] = "Fee description is required"; }
            if ($amount <= 0) { $errors[] = "Amount must be a positive number"; }
        }

        if (!empty($errors)) { throw new Exception(implode(", ", $errors)); }

        if ($entity_type === 'program' && $breakdown_total > 0) {
            $items = [
                ['Tuition Fee', 'tuition', $tuition_fee],
                ['ID Card Fee', 'id_card', $id_card_fee],
                ['Registration Fee', 'registration', $registration_fee],
                ['Library Fee', 'library', $library_fee],
                ['Examination Fee', 'examination', $exam_fee],
                ['Other Fees', 'other', $other_fee]
            ];
            $db->begin_transaction();
            $insert_sql = $hasFeeMetadata
                ? "INSERT INTO fee_structure (entity_type, program_code, short_course_id, year_of_study, semester, fee_type, fee_description, amount, status) VALUES ('program', ?, NULL, ?, ?, ?, ?, ?, 'active')"
                : "INSERT INTO fee_structure (program_code, year_of_study, semester, fee_description, amount, status) VALUES (?, ?, ?, ?, ?, 'active')";
            $ins = $db->prepare($insert_sql);
            $added = 0; $skipped = 0;
            foreach ($items as [$desc, $itemFeeType, $val]) {
                if ($val > 0) {
                    try {
                        if ($hasFeeMetadata) {
                            $ins->bind_param('siissd', $program_code, $year_of_study, $semester, $itemFeeType, $desc, $val);
                        } else {
                            $ins->bind_param('siisd', $program_code, $year_of_study, $semester, $desc, $val);
                        }
                        $ins->execute();
                        $added++;
                    }
                    catch (Throwable $e) { $skipped++; }
                }
            }
            $db->commit();
            $msg = "Added $added fee items" . ($skipped?", skipped $skipped":"");
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => true, 'message' => $msg]);
            } else {
                $_SESSION['flash_message'] = $msg;
                $_SESSION['flash_type'] = 'success';
                header('Location: programFees.php');
            }
            exit;
        }

        // Single line
        if ($entity_type === 'program') {
            $chkSql = $hasFeeMetadata
                ? "SELECT COUNT(*) FROM fee_structure WHERE entity_type='program' AND program_code=? AND year_of_study=? AND semester=? AND fee_description=? AND status='active'"
                : "SELECT COUNT(*) FROM fee_structure WHERE program_code=? AND year_of_study=? AND semester=? AND fee_description=? AND status='active'";
            $chk = $db->prepare($chkSql);
            $chk->bind_param('siis', $program_code, $year_of_study, $semester, $fee_description);
        } else {
            $normalizedFeeType = $fee_type ?? '';
            $chk = $db->prepare("SELECT COUNT(*) FROM fee_structure WHERE entity_type='short_course' AND short_course_id=? AND COALESCE(fee_type,'')=? AND fee_description=? AND status='active'");
            $chk->bind_param('iss', $short_course_id, $normalizedFeeType, $fee_description);
        }
        $chk->execute();
        $cnt = ($chk->get_result()->fetch_row()[0]) ?? 0;
        if ((int)$cnt > 0) {
            throw new Exception($entity_type === 'short_course'
                ? 'This short course fee entry already exists for the selected fee type and description'
                : 'This fee structure already exists for the selected program, year, and semester');
        }

        if ($entity_type === 'program') {
            if ($hasFeeMetadata) {
                $ins = $db->prepare("INSERT INTO fee_structure (entity_type, program_code, short_course_id, year_of_study, semester, fee_type, fee_description, amount, status) VALUES ('program', ?, NULL, ?, ?, ?, ?, ?, 'active')");
                $ins->bind_param('siissd', $program_code, $year_of_study, $semester, $fee_type, $fee_description, $amount);
            } else {
                $ins = $db->prepare("INSERT INTO fee_structure (program_code, year_of_study, semester, fee_description, amount, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $ins->bind_param('siisd', $program_code, $year_of_study, $semester, $fee_description, $amount);
            }
        } else {
            $ins = $db->prepare("INSERT INTO fee_structure (entity_type, program_code, short_course_id, year_of_study, semester, fee_type, fee_description, amount, status) VALUES ('short_course', NULL, ?, NULL, NULL, ?, ?, ?, 'active')");
            $ins->bind_param('issd', $short_course_id, $fee_type, $fee_description, $amount);
        }
        $ins->execute();
        if ($entity_type === 'short_course' && $short_course_id !== null) {
            syncShortCourseHeadlineFee($db, $short_course_id);
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(['success' => true, 'message' => 'Fee structure added successfully']);
        } else {
            $_SESSION['flash_message'] = 'Fee structure added successfully';
            $_SESSION['flash_type'] = 'success';
            header('Location: programFees.php');
        }
        exit;
    } catch (Throwable $e) {
        error_log('accounts/add_fee_structure error: ' . $e->getMessage());
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Failed to save fee structure. Please try again.']);
        } else {
            $_SESSION['flash_message'] = 'Failed to save fee structure. Please try again.';
            $_SESSION['flash_type'] = 'error';
            header('Location: add_fee_structure.php');
        }
        exit;
    }
}

// Fetch programs
$programs = [];
if($result = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_code")) {
    while($row = $result->fetch_object()) { $programs[] = $row; }
    $result->free();
}

$shortCourses = [];
if($result = $db->query("SELECT id, course_code, course_name FROM short_courses ORDER BY course_code")) {
    while($row = $result->fetch_object()) { $shortCourses[] = $row; }
    $result->free();
}
?>

<div class="container-fluid px-4 portal-dashboard accounts-page add-fee-page">
    <div class="dashboard-header finance-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Add New Fee Structure</h1>
                <p class="text-muted">Create a new fee structure for a program or short course</p>
            </div>
            <div class="col-auto">
                <a href="programFees.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Back</a>
            </div>
        </div>
    </div>

    <div class="data-table-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Fee Structure Details</h5>
            </div>
        </div>
        <div class="card-body">
            <form id="feeStructureForm" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <p class="text-muted small mb-3">Fee structures support both semester-based (2 periods) and term-based (3 periods) programs, plus short course fee components such as deposits, balances, and materials.</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-diagram-project me-2"></i>Entity Type</label>
                        <select class="form-select" id="entity_type" name="entity_type" required>
                            <option value="program" selected>Program</option>
                            <option value="short_course">Short Course</option>
                        </select>
                    </div>
                    <div class="col-md-6 entity-program-only">
                        <label class="form-label"><i class="fas fa-graduation-cap me-2"></i>Program</label>
                        <select class="form-select" id="program_code" name="program_code" required>
                            <option value="" disabled selected>Select Program</option>
                            <?php foreach($programs as $program): ?>
                            <option value="<?php echo htmlspecialchars($program->program_code); ?>"><?php echo htmlspecialchars($program->program_code); ?> - <?php echo htmlspecialchars($program->program_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 entity-program-only">
                        <label class="form-label"><i class="fas fa-calendar-alt me-2"></i>Year of Study</label>
                        <select class="form-select" id="year_of_study" name="year_of_study" required>
                            <option value="" disabled selected>Select Year</option>
                            <option value="1">Year 1</option>
                            <option value="2">Year 2</option>
                            <option value="3">Year 3</option>
                            <option value="4">Year 4</option>
                        </select>
                    </div>
                    <div class="col-md-6 entity-program-only">
                        <label id="period_label" class="form-label"><i class="fas fa-calendar me-2"></i>Semester</label>
        				<select class="form-select" id="semester" name="semester" required>
                            <option value="" disabled selected>Select Semester</option>
                            <option value="1">Semester 1</option>
                            <option value="2">Semester 2</option>
                        </select>
                        <div id="period_hint" class="form-text">This program uses semesters (2 per academic year)</div>
                    </div>
                    <div class="col-md-6 entity-short-course-only d-none">
                        <label class="form-label"><i class="fas fa-certificate me-2"></i>Short Course</label>
                        <select class="form-select" id="short_course_id" name="short_course_id">
                            <option value="" disabled selected>Select Short Course</option>
                            <?php foreach($shortCourses as $course): ?>
                            <option value="<?php echo (int)$course->id; ?>"><?php echo htmlspecialchars($course->course_code); ?> - <?php echo htmlspecialchars($course->course_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 entity-short-course-only d-none">
                        <label class="form-label"><i class="fas fa-tags me-2"></i>Fee Type</label>
                        <select class="form-select" id="fee_type" name="fee_type">
                            <option value="" selected>Select Fee Type</option>
                            <option value="registration">Registration</option>
                            <option value="tuition">Tuition</option>
                            <option value="materials">Materials</option>
                            <option value="deposit">Deposit</option>
                            <option value="balance">Balance</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="col-12 entity-program-only"><hr></div>

                    <div class="col-md-4 entity-program-only"><label class="form-label"><i class="fas fa-book-open me-2"></i>Tuition Fee (ZMK)</label><input type="number" class="form-control" id="tuition_fee" name="tuition_fee" min="0" step="0.01"></div>
                    <div class="col-md-4 entity-program-only"><label class="form-label"><i class="fas fa-id-card me-2"></i>ID Card Fee (ZMK)</label><input type="number" class="form-control" id="id_card_fee" name="id_card_fee" min="0" step="0.01"></div>
                    <div class="col-md-4 entity-program-only"><label class="form-label"><i class="fas fa-file-signature me-2"></i>Registration Fee (ZMK)</label><input type="number" class="form-control" id="registration_fee" name="registration_fee" min="0" step="0.01"></div>
                    <div class="col-md-4 entity-program-only"><label class="form-label"><i class="fas fa-book-reader me-2"></i>Library Fee (ZMK)</label><input type="number" class="form-control" id="library_fee" name="library_fee" min="0" step="0.01"></div>
                    <div class="col-md-4 entity-program-only"><label class="form-label"><i class="fas fa-file-invoice-dollar me-2"></i>Examination Fee (ZMK)</label><input type="number" class="form-control" id="exam_fee" name="exam_fee" min="0" step="0.01"></div>
                    <div class="col-md-4 entity-program-only"><label class="form-label"><i class="fas fa-ellipsis-h me-2"></i>Other Fees (ZMK)</label><input type="number" class="form-control" id="other_fee" name="other_fee" min="0" step="0.01"></div>

                    <div class="col-md-6"><label class="form-label"><i class="fas fa-file-invoice me-2"></i>Fee Description</label><input type="text" class="form-control" id="fee_description" name="fee_description" placeholder="e.g., Tuition Fee"></div>
                    <div class="col-md-6"><label class="form-label"><i class="fas fa-money-bill-wave me-2"></i>Amount (ZMK)</label><div class="input-group"><span class="input-group-text"><i class="fas fa-money-bill"></i></span><input type="number" class="form-control" id="amount" name="amount" min="0" step="0.01"></div></div>

                    <div class="col-12 d-flex justify-content-end gap-2">
                        <a href="programFees.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back
                        </a>
                        <button type="submit" name="add_fee_structure" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Fee Structure
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function(){
  function toggleEntityFields() {
    const entityType = $('#entity_type').val();
    const isProgram = entityType === 'program';
    $('.entity-program-only').toggleClass('d-none', !isProgram);
    $('.entity-short-course-only').toggleClass('d-none', isProgram);

    $('#program_code, #year_of_study, #semester').prop('required', isProgram).prop('disabled', !isProgram);
    $('#short_course_id, #fee_type').prop('required', !isProgram).prop('disabled', isProgram);
    $('#tuition_fee, #id_card_fee, #registration_fee, #library_fee, #exam_fee, #other_fee').prop('disabled', !isProgram);
  }

  function setPeriodUI(periodType){
    const label = $('#period_label');
    const select = $('#semester');
    const hint = $('#period_hint');
    select.empty();
    select.append('<option value="" selected disabled></option>');
    if (periodType === 'term'){
      label.html('<i class="fas fa-calendar me-2"></i>Term');
      hint.text('This program uses terms (3 per academic year)');
      select.find('option:first').text('Select Term');
      select.append('<option value="1">Term 1</option><option value="2">Term 2</option><option value="3">Term 3</option>');
    } else {
      label.html('<i class="fas fa-calendar me-2"></i>Semester');
      hint.text('This program uses semesters (2 per academic year)');
      select.find('option:first').text('Select Semester');
      select.append('<option value="1">Semester 1</option><option value="2">Semester 2</option>');
    }
  }
  $('#program_code').on('change', function(){
    const program = $(this).val();
    if (!program) { setPeriodUI('semester'); return; }
    $.get('../admin/ajax/get_program_period_type.php', { program_code: program })
      .done(r => setPeriodUI((r && r.period_type) ? r.period_type : 'semester'))
      .fail(() => setPeriodUI('semester'));
  });
  $('#entity_type').on('change', toggleEntityFields);
  toggleEntityFields();

  $('#feeStructureForm').on('submit', function(e){
    e.preventDefault();
    const data = $(this).serializeArray();
    $.post('add_fee_structure.php', data)
      .done(resp => {
        try{ if(typeof resp === 'string') resp = JSON.parse(resp);}catch(e){}
        const message = (resp && resp.message) ? resp.message : 'Fee structure saved';
        if (window.Swal) {
          Swal.fire({ icon: 'success', title: 'Success', text: message, timer: 1200, showConfirmButton: false })
            .then(()=>{ window.location.href='programFees.php'; });
        } else {
          alert(message); window.location.href='programFees.php';
        }
      })
      .fail(xhr => {
        const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Save failed';
        if (window.Swal) { Swal.fire({ icon: 'error', title: 'Error', text: msg }); } else { alert(msg); }
      });
  });
});

/**
 * Amount validation for fee structure inputs
 * Ensures proper currency format (no leading zeros, max 2 decimal places)
 */
function isNumberKey(evt) {
    var charCode = (evt.which) ? evt.which : evt.keyCode;
    if (charCode === 46 || charCode === 8 || charCode === 9 || charCode === 27 || charCode === 13) {
        return true;
    }
    if ((charCode === 65 || charCode === 67 || charCode === 86 || charCode === 88) && (evt.ctrlKey === true || evt.metaKey === true)) {
        return true;
    }
    if (charCode >= 35 && charCode <= 40) {
        return true;
    }
    if ((charCode < 48 || charCode > 57) && charCode !== 46) {
        evt.preventDefault();
        return false;
    }
    return true;
}

// Attach amount validation to all fee input fields
$(document).ready(function() {
    $('input[type="number"][name*="fee"], input[type="number"][name="amount"]').each(function() {
        $(this).on('keypress', isNumberKey);
        $(this).on('blur', function() {
            var value = $(this).val().trim();
            if (value !== "" && value !== "0") {
                var pattern = /^[1-9]\d*(?:\.\d{0,2})?$/;
                if (!pattern.test(value)) {
                    alert('Amount should be in proper format!\n\nValid: 150.50, 500, 1000.00\nInvalid: 0.50, 100.555, $100');
                    $(this).focus().select();
                }
            }
        });
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>


