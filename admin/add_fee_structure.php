<?php
// Enable error reporting for debugging
ini_set('display_errors', '0');
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start output buffering
ob_start();

// Start session to access session variables
session_start();

// Define root path
define('ROOT_PATH', dirname(__DIR__));

// Include database connection (mysqli)
require_once ROOT_PATH . '/admin/includes/admin.php';
require_once ROOT_PATH . '/includes/audit.php';

// Debug: Log database connection status
error_log("Database connection status: " . (isset($db) && $db ? "Connected" : "Not connected"));
if (isset($db) && $db) {
    error_log("Database host info: " . $db->host_info);
}

// Verify database connection
if (!isset($db) || !$db) {
    die("Database connection failed. Please check your database configuration.");
}

$page_title = "Add New Fee Structure";

// Error logging function
function logError($message, $severity = 'ERROR') {
    $logFile = ROOT_PATH . '/admin/logs/fee_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$severity] $message" . PHP_EOL;

    // Create logs directory if it doesn't exist
    if (!file_exists(dirname($logFile))) {
        mkdir(dirname($logFile), 0777, true);
    }

    error_log($logMessage, 3, $logFile);
}

// Input sanitization function
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Validate amount
function validateAmount($amount) {
    return is_numeric($amount) && $amount > 0;
}

// Add audit logging function
function logAudit($action, $details, $user_id) {
    $logFile = ROOT_PATH . '/admin/logs/fee_audit.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [AUDIT] User ID: $user_id | Action: $action | Details: $details" . PHP_EOL;

    if (!file_exists(dirname($logFile))) {
        mkdir(dirname($logFile), 0777, true);
    }

    error_log($logMessage, 3, $logFile);
    audit_log($GLOBALS['db'], (string)$user_id, (string)$action, ['details' => $details]);
}

// Handle fee structure addition
if (isset($_POST['add_fee_structure'])) {
    try {
        // Get form data
        $program_code = isset($_POST['program_code']) ? sanitizeInput($_POST['program_code']) : '';
        $year_of_study = isset($_POST['year_of_study']) ? (int)$_POST['year_of_study'] : 0;
        $semester = isset($_POST['semester']) ? (int)$_POST['semester'] : 0; // For termly programs, this represents the term number
        $fee_description = isset($_POST['fee_description']) ? sanitizeInput($_POST['fee_description']) : '';
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.00;
        $status = 'active';

        // Compute total from breakdown if provided
        $tuition_fee = isset($_POST['tuition_fee']) ? (float)$_POST['tuition_fee'] : 0;
        $id_card_fee = isset($_POST['id_card_fee']) ? (float)$_POST['id_card_fee'] : 0;
        $registration_fee = isset($_POST['registration_fee']) ? (float)$_POST['registration_fee'] : 0;
        $library_fee = isset($_POST['library_fee']) ? (float)$_POST['library_fee'] : 0;
        $exam_fee = isset($_POST['exam_fee']) ? (float)$_POST['exam_fee'] : 0;
        $other_fee = isset($_POST['other_fee']) ? (float)$_POST['other_fee'] : 0;
        
        $breakdown_total = $tuition_fee + $id_card_fee + $registration_fee + $library_fee + $exam_fee + $other_fee;

        // Determine period type for selected program (semester vs term)
        $period_type = 'semester';
        if ($stmt = $db->query("SHOW COLUMNS FROM programs LIKE 'period_mode'")) {
            if ($stmt->num_rows > 0) {
                $ptypeStmt = $db->prepare("SELECT period_mode FROM programs WHERE program_code = ? LIMIT 1");
                if ($ptypeStmt) {
                    $ptypeStmt->bind_param('s', $program_code);
                    $ptypeStmt->execute();
                    $ptypeRes = $ptypeStmt->get_result();
                    if ($row = $ptypeRes->fetch_assoc()) {
                        $period_type = $row['period_mode'] ?: 'semester';
                    }
                    $ptypeStmt->close();
                }
            }
        }

        $max_period = ($period_type === 'term') ? 3 : 2;

        // Validate inputs
        $errors = [];
        if (empty($program_code)) {
            $errors[] = "Program code is required";
        }
        if ($year_of_study < 1 || $year_of_study > 4) {
            $errors[] = "Year of study must be between 1 and 4";
        }
        if ($semester < 1 || $semester > $max_period) {
            $errors[] = $period_type === 'term' ? "Term must be between 1 and 3" : "Semester must be either 1 or 2";
        }
        if ($breakdown_total <= 0) {
            // No breakdown provided, require single description + amount
            if (empty($fee_description)) {
                $errors[] = "Fee description is required";
            }
            if ($amount <= 0) {
                $errors[] = "Amount must be a positive number";
            }
        }

        if (!empty($errors)) {
            throw new Exception(implode(", ", $errors));
        }

        // If breakdown provided, insert multiple rows
        if ($breakdown_total > 0) {
            $items = [
                ['Tuition Fee', $tuition_fee],
                ['ID Card Fee', $id_card_fee],
                ['Registration Fee', $registration_fee],
                ['Library Fee', $library_fee],
                ['Examination Fee', $exam_fee],
                ['Other Fees', $other_fee]
            ];

            $db->begin_transaction();
            $insert_sql = "INSERT INTO fee_structure (program_code, year_of_study, semester, fee_description, amount, status) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($insert_sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $db->error);
            }

            $added = 0; $skipped = 0;
            foreach ($items as [$desc, $val]) {
                if ($val > 0) {
                    try {
                        $stmt->bind_param("siisds", $program_code, $year_of_study, $semester, $desc, $val, $status);
                        $stmt->execute();
                        $added++;
                    } catch (mysqli_sql_exception $e) {
                        // Duplicate or constraint error, skip
                        $skipped++;
                    }
                }
            }
            $db->commit();

            logAudit("ADD_FEE_STRUCTURE", "Added $added fee items (skipped $skipped) for program: $program_code", $_SESSION['user_id'] ?? 0);

            $msg = "Added $added fee items" . ($skipped ? ", skipped $skipped" : "");
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => true, 'message' => $msg]);
            } else {
                $_SESSION['flash_message'] = $msg;
                $_SESSION['flash_type'] = 'success';
                header('Location: fee_structure.php');
            }
            exit();
        }

        // Otherwise, insert a single fee row
        // Check if fee structure already exists
        $check_sql = "SELECT COUNT(*) FROM fee_structure 
                     WHERE program_code = ? 
                     AND year_of_study = ? 
                     AND semester = ? 
                     AND fee_description = ? 
                     AND status = 'active'";
        
        $stmt = $db->prepare($check_sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }

        if (!$stmt->bind_param("siis", $program_code, $year_of_study, $semester, $fee_description)) {
            throw new Exception("Bind failed: " . $stmt->error);
        }

        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }

        $result = $stmt->get_result();
        $count = $result->fetch_row()[0];

        if ($count > 0) {
            throw new Exception("This fee structure already exists for the selected program, year, and semester");
        }

        // Insert new fee structure
        $insert_sql = "INSERT INTO fee_structure (program_code, year_of_study, semester, fee_description, amount, status) 
                      VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $db->prepare($insert_sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }

        if (!$stmt->bind_param("siisds", $program_code, $year_of_study, $semester, $fee_description, $amount, $status)) {
            throw new Exception("Bind failed: " . $stmt->error);
        }

        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }

        // Log success
        logAudit("ADD_FEE_STRUCTURE", "Added fee structure for program: $program_code", $_SESSION['user_id'] ?? 0);

        // Return success response for AJAX
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(['success' => true, 'message' => 'Fee structure added successfully']);
        } else {
            $_SESSION['flash_message'] = 'Fee structure added successfully';
            $_SESSION['flash_type'] = 'success';
            header('Location: fee_structure.php');
        }
        exit();

    } catch (Exception $e) {
        error_log('add_fee_structure error: ' . $e->getMessage());
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Failed to save fee structure. Please try again.']);
        } else {
            $_SESSION['flash_message'] = 'Failed to save fee structure. Please try again.';
            $_SESSION['flash_type'] = 'error';
            header('Location: add_fee_structure.php');
        }
        exit();
    }
}

// Get all programs for dropdown
$programs = [];
if($result = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_code")) {
    while($row = $result->fetch_object()) {
        $programs[] = $row;
    }
    $result->free();
}

// Include header

require_once ROOT_PATH . '/admin/includes/header.php';
// Link the admin dashboard stylesheet to match fee_structure
?>
<div class="container-fluid px-4 portal-dashboard">
        <!-- Page Header -->
    <div class="dashboard-header finance-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Add New Fee Structure</h1>
                    <p class="text-muted">Create a new fee structure for a program</p>
            </div>
            <div class="col-auto">
                    <a href="fee_structure.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Fee Structures
                </a>
            </div>
        </div>
    </div>

        <!-- Add Fee Structure Form -->
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-plus-circle me-2"></i>Fee Structure Details
                        </h5>
                    </div>
                </div>
                <div class="card-body">
                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_GET['error']); ?>
                    </div>
                <?php endif; ?>

                <form id="feeStructureForm" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                    <!-- Context note under title -->
                    <p class="text-muted small mb-3">This program uses semesters (2 per academic year). If a program uses terms, the selector below will adapt automatically.</p>

                    <!-- Filter Row: Program, Year, Period -->
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="program_code" class="form-label"><i class="fas fa-graduation-cap me-2"></i>Program</label>
                            <select class="form-select" id="program_code" name="program_code" required>
                                <option value="" selected disabled>Select Program</option>
                                <?php foreach($programs as $program): ?>
                                    <option value="<?php echo htmlspecialchars($program->program_code); ?>"><?php echo htmlspecialchars($program->program_code); ?> - <?php echo htmlspecialchars($program->program_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="year_of_study" class="form-label"><i class="fas fa-calendar-alt me-2"></i>Year of Study</label>
                            <select class="form-select" id="year_of_study" name="year_of_study" required>
                                <option value="" selected disabled>Select Year</option>
                                <option value="1">Year 1</option>
                                <option value="2">Year 2</option>
                                <option value="3">Year 3</option>
                                <option value="4">Year 4</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="semester" class="form-label" id="period_label"><i class="fas fa-calendar me-2"></i>Semester</label>
                            <select class="form-select" id="semester" name="semester" required>
                                <option value="" selected disabled>Select Semester</option>
                                <option value="1">Semester 1</option>
                                <option value="2">Semester 2</option>
                            </select>
                            <div class="form-text" id="period_hint">This program uses semesters (2 per academic year)</div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <!-- Two-column fee grid -->
                    <div class="row g-4">
                        <!-- Left: Fixed fee categories -->
                        <div class="col-lg-7">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="tuition_fee" class="form-label"><i class="fas fa-book-open me-2"></i>Tuition Fee (ZMK)</label>
                                    <input type="number" class="form-control" id="tuition_fee" name="tuition_fee" min="0" step="0.01" placeholder="0.00" title="Enter fee in ZMK">
                                </div>
                                <div class="col-md-6">
                                    <label for="registration_fee" class="form-label"><i class="fas fa-file-signature me-2"></i>Registration Fee (ZMK)</label>
                                    <input type="number" class="form-control" id="registration_fee" name="registration_fee" min="0" step="0.01" placeholder="0.00" title="Enter fee in ZMK">
                                </div>
                                <div class="col-md-6">
                                    <label for="library_fee" class="form-label"><i class="fas fa-book-reader me-2"></i>Library Fee (ZMK)</label>
                                    <input type="number" class="form-control" id="library_fee" name="library_fee" min="0" step="0.01" placeholder="0.00" title="Enter fee in ZMK">
                                </div>
                                <div class="col-md-6">
                                    <label for="id_card_fee" class="form-label"><i class="fas fa-id-card me-2"></i>ID Card Fee (ZMK)</label>
                                    <input type="number" class="form-control" id="id_card_fee" name="id_card_fee" min="0" step="0.01" placeholder="0.00" title="Enter fee in ZMK">
                                </div>
                                <div class="col-md-6">
                                    <label for="exam_fee" class="form-label"><i class="fas fa-file-invoice-dollar me-2"></i>Examination Fee (ZMK)</label>
                                    <input type="number" class="form-control" id="exam_fee" name="exam_fee" min="0" step="0.01" placeholder="0.00" title="Enter fee in ZMK">
                                </div>
                                <div class="col-md-6">
                                    <label for="other_fee" class="form-label"><i class="fas fa-ellipsis-h me-2"></i>Other Fees (ZMK)</label>
                                    <input type="number" class="form-control" id="other_fee" name="other_fee" min="0" step="0.01" placeholder="0.00" title="Enter fee in ZMK">
                                </div>
                            </div>
                        </div>
                        <!-- Right: Description + Amount side-by-side -->
                        <div class="col-lg-5">
                            <div class="row g-3 align-items-end">
                                <div class="col-sm-7">
                                    <label for="fee_description" class="form-label"><i class="fas fa-file-invoice me-2"></i>Fee Description</label>
                                    <input type="text" class="form-control" id="fee_description" name="fee_description" placeholder="e.g., Tuition Fee">
                                </div>
                                <div class="col-sm-5">
                                    <label for="amount" class="form-label"><i class="fas fa-money-bill-wave me-2"></i>Amount (ZMK)</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-money-bill"></i></span>
                                        <input type="number" class="form-control" id="amount" name="amount" min="0" step="0.01" placeholder="0.00" title="Enter fee in ZMK">
                                    </div>
                                </div>
                            </div>
                            <div class="form-text mt-2">Use the breakdown on the left or specify a single description and amount.</div>
                        </div>
                    </div>
            </div>
            <div class="card-footer bg-white border-top">
                <div class="d-flex justify-content-end gap-2">
                    <a href="fee_structure.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back
                    </a>
                    <button type="submit" name="add_fee_structure" class="btn btn-primary px-4">
                        <i class="fas fa-save me-2"></i>Save Fee Structure
                    </button>
                </div>
            </div>
            </form>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        // Dynamically adjust period options (Semester vs Term) based on selected program
        function setPeriodUI(periodType) {
            const label = $('#period_label');
            const select = $('#semester');
            const hint = $('#period_hint');
            select.empty();
            select.append('<option value="" selected disabled></option>');
            if (periodType === 'term') {
                label.html('<i class="fas fa-calendar me-2"></i>Term');
                hint.text('This program uses terms (3 per academic year)');
                select.find('option:first').text('Select Term');
                select.append('<option value="1">Term 1</option>');
                select.append('<option value="2">Term 2</option>');
                select.append('<option value="3">Term 3</option>');
            } else {
                label.html('<i class="fas fa-calendar me-2"></i>Semester');
                hint.text('This program uses semesters (2 per academic year)');
                select.find('option:first').text('Select Semester');
                select.append('<option value="1">Semester 1</option>');
                select.append('<option value="2">Semester 2</option>');
            }
        }

        function fetchProgramPeriod(programCode) {
            if (!programCode) { setPeriodUI('semester'); return; }
            $.ajax({
                url: 'ajax/get_program_period_type.php',
                method: 'GET',
                data: { program_code: programCode },
                success: function(resp) {
                    const type = (resp && resp.period_type) ? resp.period_type : 'semester';
                    setPeriodUI(type);
                },
                error: function() { setPeriodUI('semester'); }
            });
        }

        $('#program_code').on('change', function(){
            fetchProgramPeriod($(this).val());
        });

        // Handle form submission
        $('#feeStructureForm').on('submit', function(e) {
            e.preventDefault();
            
            // Gather breakdown values
            const tuition_fee = parseFloat($('#tuition_fee').val() || 0);
            const id_card_fee = parseFloat($('#id_card_fee').val() || 0);
            const registration_fee = parseFloat($('#registration_fee').val() || 0);
            const library_fee = parseFloat($('#library_fee').val() || 0);
            const exam_fee = parseFloat($('#exam_fee').val() || 0);
            const other_fee = parseFloat($('#other_fee').val() || 0);
            const breakdownTotal = tuition_fee + id_card_fee + registration_fee + library_fee + exam_fee + other_fee;
            
            // Get form data
            const formData = {
                program_code: $('#program_code').val(),
                year_of_study: $('#year_of_study').val(),
                semester: $('#semester').val(),
                fee_description: $('#fee_description').val(),
                amount: $('#amount').val(),
                tuition_fee: tuition_fee,
                id_card_fee: id_card_fee,
                registration_fee: registration_fee,
                library_fee: library_fee,
                exam_fee: exam_fee,
                other_fee: other_fee
            };
            
            // Validate basics
            if (!formData.program_code) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Please select a program' });
                return;
            }
            if (!formData.year_of_study) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Please select year of study' });
                return;
            }
            if (!formData.semester) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Please select semester' });
                return;
            }

            if (breakdownTotal <= 0) {
                // Require single line if no breakdown
                if (!formData.fee_description) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Please enter fee description or provide a breakdown' });
                    return;
                }
                if (!formData.amount || parseFloat(formData.amount) <= 0) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Please enter a valid amount or provide a breakdown' });
                    return;
                }
            }
            
            // Confirm and submit
            Swal.fire({
                title: 'Confirm Fee Structure',
                text: breakdownTotal > 0 ? 'This will add multiple fee items.' : 'Are you sure you want to add this fee?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, save it',
                cancelButtonText: 'No, cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: $(this).attr('action'),
                        method: 'POST',
                        data: {
                            add_fee_structure: true,
                            ...formData
                        },
                        success: function(response) {
                            if (typeof response === 'string') {
                                try { response = JSON.parse(response); } catch(e) { response = { success: true }; }
                            }
                            Swal.fire({
                                icon: 'success',
                                title: 'Success!',
                                text: response.message || 'Fee structure has been added successfully',
                                showConfirmButton: false,
                                timer: 1500
                            }).then(() => {
                                window.location.href = 'fee_structure.php';
                            });
                        },
                        error: function(xhr) {
                            const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to add fee structure. Please try again.';
                            Swal.fire({ icon: 'error', title: 'Error', text: msg });
                        }
                    });
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
    
    function validateAmountFields() {
        var amountInputs = document.querySelectorAll('input[type="number"][name*="fee"], input[type="number"][name="amount"]');
        var pattern = /^[1-9]\d*(?:\.\d{0,2})?$/;
        var isValid = true;
        
        amountInputs.forEach(function(input) {
            var value = input.value.trim();
            if (value !== "" && value !== "0" && !pattern.test(value)) {
                alert('Amount in "' + (input.previousElementSibling ? input.previousElementSibling.textContent : input.name) + '" should be in proper format!\n\nValid: 150.50, 500, 1000.00\nInvalid: 0.50, 100.555, $100');
                input.focus();
                input.select();
                isValid = false;
                return false;
            }
        });
        
        return isValid;
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
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
