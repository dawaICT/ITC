<?php
// Edit Fee Structure Page
ini_set('display_errors', '0');
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
session_start();

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/db/connect.php';
require_once ROOT_PATH . '/includes/audit.php';
require_once ROOT_PATH . '/includes/helpers/academic_period_helpers.php';
require_once __DIR__ . '/includes/nav.php';

$page_title = "Edit Fee Structure";

// Get fee ID from URL
$fee_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($fee_id <= 0) {
    $_SESSION['flash_message'] = 'Invalid fee structure ID';
    $_SESSION['flash_type'] = 'error';
    header('Location: programFees.php');
    exit;
}

// Fetch fee structure details
$fee = null;
if ($stmt = $db->prepare("SELECT * FROM fee_structure WHERE id = ? LIMIT 1")) {
    $stmt->bind_param('i', $fee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $fee = $result->fetch_assoc();
    $stmt->close();
}

if (!$fee) {
    $_SESSION['flash_message'] = 'Fee structure not found';
    $_SESSION['flash_type'] = 'error';
    header('Location: programFees.php');
    exit;
}

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

function accountsEditFeeColumnExists(mysqli $db, string $column): bool {
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

    if (!accountsEditFeeColumnExists($db, 'short_course_id') || !accountsEditFeeColumnExists($db, 'entity_type')) {
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

if (isset($_POST['update_fee_structure'])) {
    try {
        $originalShortCourseId = ($fee['entity_type'] ?? 'program') === 'short_course' ? (int)($fee['short_course_id'] ?? 0) : 0;
        $hasFeeMetadata = accountsEditFeeColumnExists($db, 'entity_type')
            && accountsEditFeeColumnExists($db, 'short_course_id')
            && accountsEditFeeColumnExists($db, 'fee_type');
        $entity_type = (isset($_POST['entity_type']) && $_POST['entity_type'] === 'short_course') ? 'short_course' : 'program';
        $program_code = $entity_type === 'program' ? sanitizeInput($_POST['program_code'] ?? '') : null;
        $short_course_id = $entity_type === 'short_course' ? (int)($_POST['short_course_id'] ?? 0) : null;
        $year_of_study = $entity_type === 'program' ? (int)($_POST['year_of_study'] ?? 0) : null;
        $semester = $entity_type === 'program' ? (int)($_POST['semester'] ?? 0) : null;
        $fee_type = normalizeFeeType($_POST['fee_type'] ?? '');
        $fee_description = isset($_POST['fee_description']) ? sanitizeInput($_POST['fee_description']) : '';
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.00;
        $status = isset($_POST['status']) ? sanitizeInput($_POST['status']) : 'active';
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
            if ($hasFeeMetadata && $fee_type === null) { $errors[] = "Fee type is required for short courses"; }
        }
        if (empty($fee_description)) { $errors[] = "Fee description is required"; }
        if ($amount <= 0) { $errors[] = "Amount must be a positive number"; }

        if (!empty($errors)) { 
            throw new Exception(implode(", ", $errors)); 
        }

        if ($entity_type === 'short_course' && !$hasFeeMetadata) {
            $stmt = $db->prepare("UPDATE short_courses SET fee = ? WHERE id = ?");
            if (!$stmt) { throw new Exception('Database error: ' . $db->error); }
            $stmt->bind_param('di', $amount, $short_course_id);
            $stmt->execute();
            $stmt->close();

            $_SESSION['flash_message'] = 'Short course headline fee updated successfully';
            $_SESSION['flash_type'] = 'success';
            header('Location: programFees.php');
            exit;
        }

        // Check if period_type column exists
        $hasPeriodType = false;
        if ($stmt = $db->query("SHOW COLUMNS FROM fee_structure LIKE 'period_type'")) {
            $hasPeriodType = ($stmt->num_rows > 0);
            $stmt->free();
        }

        $db->begin_transaction();

        if (!$hasFeeMetadata) {
            $sql = "UPDATE fee_structure
                    SET program_code = ?, year_of_study = ?, semester = ?, fee_description = ?, amount = ?, status = ?
                    WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('siisdsi', $program_code, $year_of_study, $semester, $fee_description, $amount, $status, $fee_id);
        } elseif ($hasPeriodType) {
            if ($entity_type === 'program') {
                $sql = "UPDATE fee_structure 
                        SET entity_type = 'program', program_code = ?, short_course_id = NULL, year_of_study = ?, semester = ?, fee_type = ?,
                            fee_description = ?, amount = ?, status = ?, period_type = ?
                        WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->bind_param('siissdsi', $program_code, $year_of_study, $semester,
                                $fee_type, $fee_description, $amount, $status, $period_type, $fee_id);
            } else {
                $sql = "UPDATE fee_structure 
                        SET entity_type = 'short_course', program_code = NULL, short_course_id = ?, year_of_study = NULL, semester = NULL, fee_type = ?,
                            fee_description = ?, amount = ?, status = ?, period_type = NULL
                        WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->bind_param('issdsi', $short_course_id, $fee_type, $fee_description, $amount, $status, $fee_id);
            }
        } else {
            if ($entity_type === 'program') {
                $sql = "UPDATE fee_structure 
                        SET entity_type = 'program', program_code = ?, short_course_id = NULL, year_of_study = ?, semester = ?, fee_type = ?,
                            fee_description = ?, amount = ?, status = ?
                        WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->bind_param('siissdi', $program_code, $year_of_study, $semester,
                                $fee_type, $fee_description, $amount, $status, $fee_id);
            } else {
                $sql = "UPDATE fee_structure 
                        SET entity_type = 'short_course', program_code = NULL, short_course_id = ?, year_of_study = NULL, semester = NULL, fee_type = ?,
                            fee_description = ?, amount = ?, status = ?
                        WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->bind_param('issdsi', $short_course_id, $fee_type, $fee_description, $amount, $status, $fee_id);
            }
        }

        $stmt->execute();

        if ($stmt->affected_rows >= 0) {
            $newShortCourseId = $entity_type === 'short_course' ? (int)$short_course_id : 0;
            if ($originalShortCourseId > 0) {
                syncShortCourseHeadlineFee($db, $originalShortCourseId);
            }
            if ($newShortCourseId > 0 && $newShortCourseId !== $originalShortCourseId) {
                syncShortCourseHeadlineFee($db, $newShortCourseId);
            }
            if ($newShortCourseId > 0 && $newShortCourseId === $originalShortCourseId) {
                syncShortCourseHeadlineFee($db, $newShortCourseId);
            }

            $user_id = $_SESSION['user_id'] ?? ($_SESSION['staff_id'] ?? 0);
            audit_log($db, (string)$user_id, 'update_fee_structure', [
                'record_id' => $fee_id,
                'entity_type' => $entity_type,
                'program_code' => $program_code,
                'short_course_id' => $short_course_id,
            ]);

            $db->commit();
            $_SESSION['flash_message'] = 'Fee structure updated successfully';
            $_SESSION['flash_type'] = 'success';
            header('Location: programFees.php');
            exit;
        } else {
            throw new Exception('Failed to update fee structure');
        }
    } catch (Throwable $e) {
        $db->rollback();
        $error_message = $e->getMessage();
    }
}

// Get all programs for dropdown
$programs = [];
if ($result = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_code")) {
    while ($row = $result->fetch_object()) {
        $programs[] = $row;
    }
    $result->free();
}

$shortCourses = [];
if ($result = $db->query("SELECT id, course_code, course_name FROM short_courses ORDER BY course_code")) {
    while ($row = $result->fetch_object()) {
        $shortCourses[] = $row;
    }
    $result->free();
}

$periodMeta = (($fee['entity_type'] ?? 'program') === 'program' && !empty($fee['program_code']))
    ? getProgramAcademicStructure($db, (string)$fee['program_code'])
    : ['period_mode' => 'semester', 'period_label' => 'Semester', 'max_periods' => 2];
$period_type = $periodMeta['period_mode'];
$max_period = (int)$periodMeta['max_periods'];
?>

<div class="container-fluid px-4 portal-dashboard accounts-page edit-fee-page">
    <div class="row my-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4 edit-fee-header">
                <div>
                    <h1 class="h3 mb-1">Edit Fee Structure</h1>
                    <p class="text-muted mb-0">Update fee structure details for programs and short courses</p>
                </div>
                <a href="programFees.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Fee Structures
                </a>
            </div>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Fee Structure Details</h5>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="editFeeForm">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="entity_type" class="form-label">Entity Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="entity_type" name="entity_type" required>
                                    <option value="program" <?= ($fee['entity_type'] ?? 'program') === 'program' ? 'selected' : '' ?>>Program</option>
                                    <option value="short_course" <?= ($fee['entity_type'] ?? 'program') === 'short_course' ? 'selected' : '' ?>>Short Course</option>
                                </select>
                            </div>

                            <div class="col-md-6 entity-program-only">
                                <label for="program_code" class="form-label">Program <span class="text-danger">*</span></label>
                                <select class="form-select" id="program_code" name="program_code" required>
                                    <option value="">Select Program</option>
                                    <?php foreach ($programs as $p): ?>
                                        <option value="<?= htmlspecialchars($p->program_code) ?>" 
                                                <?= $fee['program_code'] === $p->program_code ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($p->program_code . ' - ' . $p->program_name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6 entity-short-course-only d-none">
                                <label for="short_course_id" class="form-label">Short Course <span class="text-danger">*</span></label>
                                <select class="form-select" id="short_course_id" name="short_course_id">
                                    <option value="">Select Short Course</option>
                                    <?php foreach ($shortCourses as $course): ?>
                                        <option value="<?= (int)$course->id ?>" <?= (int)($fee['short_course_id'] ?? 0) === (int)$course->id ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($course->course_code . ' - ' . $course->course_name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3 entity-program-only">
                                <label for="year_of_study" class="form-label">Year of Study <span class="text-danger">*</span></label>
                                <select class="form-select" id="year_of_study" name="year_of_study" required>
                                    <option value="">Select Year</option>
                                    <?php for ($y = 1; $y <= 4; $y++): ?>
                                        <option value="<?= $y ?>" <?= $fee['year_of_study'] == $y ? 'selected' : '' ?>>
                                            Year <?= $y ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="col-md-3 entity-program-only">
                                <label for="semester" class="form-label">
                                    <span id="periodLabel"><?= $period_type === 'term' ? 'Term' : 'Semester' ?></span> 
                                    <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="semester" name="semester" required>
                                    <option value="">Select</option>
                                    <?php for ($s = 1; $s <= $max_period; $s++): ?>
                                        <option value="<?= $s ?>" <?= $fee['semester'] == $s ? 'selected' : '' ?>>
                                            <?= $s ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="col-md-4 entity-short-course-only d-none">
                                <label for="fee_type" class="form-label">Fee Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="fee_type" name="fee_type">
                                    <option value="">Select Fee Type</option>
                                    <?php foreach (['registration', 'tuition', 'materials', 'deposit', 'balance', 'other'] as $type): ?>
                                        <option value="<?= htmlspecialchars($type) ?>" <?= ($fee['fee_type'] ?? '') === $type ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(feeTypeLabel($type)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-8">
                                <label for="fee_description" class="form-label">Fee Description <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="fee_description" name="fee_description" 
                                       value="<?= htmlspecialchars($fee['fee_description']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label for="amount" class="form-label">Amount (ZMK) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0.01" class="form-control" id="amount" 
                                       name="amount" value="<?= htmlspecialchars($fee['amount']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="active" <?= $fee['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= $fee['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-4 d-flex gap-2">
                            <button type="submit" name="update_fee_structure" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Fee Structure
                            </button>
                            <a href="programFees.php" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(function() {
    function toggleEntityFields() {
        const entityType = $('#entity_type').val();
        const isProgram = entityType === 'program';
        $('.entity-program-only').toggleClass('d-none', !isProgram);
        $('.entity-short-course-only').toggleClass('d-none', isProgram);

        $('#program_code, #year_of_study, #semester').prop('required', isProgram).prop('disabled', !isProgram);
        $('#short_course_id, #fee_type').prop('required', !isProgram).prop('disabled', isProgram);
    }

    // Update period label and options when program changes
    $('#program_code').on('change', function() {
        const programCode = $(this).val();
        if (!programCode) return;
        
        $.post('ajax/get_program_period_type.php', { program_code: programCode }, function(res) {
            if (res && res.period_type) {
                const periodType = res.period_type;
                const maxPeriod = periodType === 'term' ? 3 : 2;
                const label = periodType === 'term' ? 'Term' : 'Semester';
                
                $('#periodLabel').text(label);
                
                const $semesterSelect = $('#semester');
                const currentValue = $semesterSelect.val();
                $semesterSelect.empty().append('<option value="">Select</option>');
                
                for (let i = 1; i <= maxPeriod; i++) {
                    const selected = (currentValue == i) ? 'selected' : '';
                    $semesterSelect.append(`<option value="${i}" ${selected}>${i}</option>`);
                }
            }
        });
    });

    $('#entity_type').on('change', toggleEntityFields);
    toggleEntityFields();
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
