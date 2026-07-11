<?php
/**
 * Edit Fee Structure
 * Allows editing an existing fee structure entry
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
session_start();

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/admin/includes/admin.php';

$page_title = "Edit Fee Structure";

// Check if ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['flash_message'] = 'Invalid fee structure ID';
    $_SESSION['flash_type'] = 'error';
    header('Location: fee_structure.php');
    exit();
}

$fee_id = (int)$_GET['id'];

// Get fee structure data
$fee = null;
$stmt = $db->prepare("SELECT * FROM fee_structure WHERE id = ?");
$stmt->bind_param("i", $fee_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    $_SESSION['flash_message'] = 'Fee structure not found';
    $_SESSION['flash_type'] = 'error';
    header('Location: fee_structure.php');
    exit();
}
$fee = $result->fetch_object();
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_fee_structure'])) {
    try {
        $program_code = trim($_POST['program_code'] ?? '');
        $year_of_study = (int)($_POST['year_of_study'] ?? 0);
        $semester = (int)($_POST['semester'] ?? 0);
        $fee_description = trim($_POST['fee_description'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $status = $_POST['status'] ?? 'active';

        // Validate
        $errors = [];
        if (empty($program_code)) $errors[] = "Program is required";
        if ($year_of_study < 1 || $year_of_study > 4) $errors[] = "Year must be between 1 and 4";
        if ($semester < 1 || $semester > 3) $errors[] = "Semester/Term must be between 1 and 3";
        if (empty($fee_description)) $errors[] = "Fee description is required";
        if ($amount <= 0) $errors[] = "Amount must be greater than 0";
        if (!in_array($status, ['active', 'inactive'])) $errors[] = "Invalid status";

        if (!empty($errors)) {
            throw new Exception(implode(", ", $errors));
        }

        // Update fee structure
        $stmt = $db->prepare("UPDATE fee_structure SET program_code = ?, year_of_study = ?, semester = ?, fee_description = ?, amount = ?, status = ? WHERE id = ?");
        $stmt->bind_param("siisdsi", $program_code, $year_of_study, $semester, $fee_description, $amount, $status, $fee_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update: " . $stmt->error);
        }

        $_SESSION['flash_message'] = 'Fee structure updated successfully';
        $_SESSION['flash_type'] = 'success';
        header('Location: fee_structure.php');
        exit();

    } catch (Exception $e) {
        $_SESSION['flash_message'] = $e->getMessage();
        $_SESSION['flash_type'] = 'error';
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

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="dashboard-header finance-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Edit Fee Structure</h1>
                <p class="text-muted">Update fee structure details</p>
            </div>
            <div class="col-auto">
                <a href="fee_structure.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Fee Structures
                </a>
            </div>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash_type']==='error'?'danger':'success'; ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['flash_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <!-- Edit Form -->
    <div class="data-table-card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fas fa-edit me-2"></i>Fee Structure Details
            </h5>
        </div>
        <div class="card-body">
            <form method="POST" id="editFeeForm">
                <!-- Hidden field to ensure form action is identified when using JS submit -->
                <input type="hidden" name="update_fee_structure" value="1">
                <div class="row g-3">
                    <!-- Program -->
                    <div class="col-md-6">
                        <label for="program_code" class="form-label">
                            <i class="fas fa-graduation-cap me-2"></i>Program <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="program_code" name="program_code" required>
                            <option value="">Select Program</option>
                            <?php foreach($programs as $program): ?>
                                <option value="<?php echo htmlspecialchars($program->program_code); ?>" 
                                    <?php echo ($fee->program_code === $program->program_code) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($program->program_code . ' - ' . $program->program_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Year of Study -->
                    <div class="col-md-3">
                        <label for="year_of_study" class="form-label">
                            <i class="fas fa-calendar-alt me-2"></i>Year of Study <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="year_of_study" name="year_of_study" required>
                            <option value="">Select Year</option>
                            <?php for($i = 1; $i <= 4; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo ($fee->year_of_study == $i) ? 'selected' : ''; ?>>
                                    Year <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <!-- Semester -->
                    <div class="col-md-3">
                        <label for="semester" class="form-label">
                            <i class="fas fa-calendar me-2"></i>Semester/Term <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="semester" name="semester" required>
                            <option value="">Select</option>
                            <?php for($i = 1; $i <= 3; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo ($fee->semester == $i) ? 'selected' : ''; ?>>
                                    <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <!-- Fee Description -->
                    <div class="col-md-6">
                        <label for="fee_description" class="form-label">
                            <i class="fas fa-file-alt me-2"></i>Fee Description <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="fee_description" name="fee_description" 
                               value="<?php echo htmlspecialchars($fee->fee_description); ?>" 
                               placeholder="e.g., Tuition Fee" required>
                    </div>

                    <!-- Amount -->
                    <div class="col-md-3">
                        <label for="amount" class="form-label">
                            <i class="fas fa-money-bill-wave me-2"></i>Amount (ZMK) <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">ZMK</span>
                            <input type="number" class="form-control" id="amount" name="amount" 
                                   value="<?php echo number_format($fee->amount, 2, '.', ''); ?>" 
                                   min="0" step="0.01" required>
                        </div>
                    </div>

                    <!-- Status -->
                    <div class="col-md-3">
                        <label for="status" class="form-label">
                            <i class="fas fa-toggle-on me-2"></i>Status <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="active" <?php echo ($fee->status === 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($fee->status === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>

                <hr class="my-4">

                <!-- Form Actions -->
                <div class="d-flex justify-content-end gap-2">
                    <a href="fee_structure.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update Fee Structure
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Track if form is being submitted after confirmation
    let formSubmitting = false;
    
    // Form validation
    $('#editFeeForm').on('submit', function(e) {
        // If already confirmed, allow submission
        if (formSubmitting) {
            return true;
        }
        
        const amount = parseFloat($('#amount').val());
        if (amount <= 0 || isNaN(amount)) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Invalid Amount',
                text: 'Amount must be greater than 0'
            });
            return false;
        }
        
        // Validate required fields before showing confirmation
        const programCode = $('#program_code').val();
        const yearOfStudy = $('#year_of_study').val();
        const semester = $('#semester').val();
        const feeDescription = $('#fee_description').val().trim();
        
        if (!programCode || !yearOfStudy || !semester || !feeDescription) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Missing Fields',
                text: 'Please fill in all required fields'
            });
            return false;
        }
        
        // Confirm before submitting
        e.preventDefault();
        
        Swal.fire({
            title: 'Confirm Update',
            text: 'Are you sure you want to update this fee structure?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-check me-2"></i>Yes, update it',
            cancelButtonText: '<i class="fas fa-times me-2"></i>Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                formSubmitting = true;
                // Use native form submission to bypass jQuery handler
                document.getElementById('editFeeForm').submit();
            }
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

// Attach amount validation to all amount/fee input fields
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

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>

