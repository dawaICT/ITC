<?php
include "includes/admin.php";
include 'add_class.php';

// Controlled error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Verify database connection
if (!$db) {
    error_log("Database connection failed: " . mysqli_connect_error());
    die("Database connection failed");
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    if ($_GET['action'] === 'get_student_program') {
        try {
            $Sid = trim($_GET['student_id']);

            $student_query = "SELECT s.*, sp.program_code, p.program_name 
                             FROM students s
                             INNER JOIN student_program sp ON s.SID = sp.SID
                             INNER JOIN programs p ON sp.program_code = p.program_code
                             WHERE s.SID = ? AND s.status = 'Active'";

            $check_stmt = $db->prepare($student_query);
            if (!$check_stmt) {
                throw new Exception("Failed to prepare query: " . $db->error);
            }

            $check_stmt->bind_param("s", $Sid);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows === 0) {
                $student_only_query = "SELECT * FROM students WHERE SID = ?";
                $student_check = $db->prepare($student_only_query);
                $student_check->bind_param("s", $Sid);
                $student_check->execute();
                $student_result = $student_check->get_result();

                if ($student_result->num_rows === 0) {
                    http_response_code(404);
                    echo json_encode([
                        'error' => 'Student not found',
                        'details' => "No student record found with ID: $Sid"
                    ]);
                } else {
                    http_response_code(404);
                    echo json_encode([
                        'error' => 'No program assigned',
                        'details' => "Student found but no program is assigned"
                    ]);
                }
                exit;
            }

            $student_data = $check_result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'program_code' => $student_data['program_code'],
                'program_name' => $student_data['program_name'],
                'student_info' => [
                    'id' => $student_data['SID'],
                    'name' => $student_data['Fname'] . ' ' . $student_data['Lname']
                ]
            ]);
            exit;

        } catch (Exception $e) {
            error_log("Error in student program lookup: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => $e->getMessage(),
                'details' => 'Failed to fetch student program'
            ]);
            exit;
        }
    }
}

// Handle form submission
if (isset($_POST['submit'])) {
    try {
        // Validate required fields
        $required_fields = ['Sid', 'program_code', 'semester', 'Year', 'status'];
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
                throw new Exception("Please fill in all required fields");
            }
        }

        $Sid = trim($_POST["Sid"]);
        $program_code = trim($_POST["program_code"]);
        $semester = trim($_POST["semester"]);
        $Year = trim($_POST["Year"]);
        $status = trim($_POST["status"]);

        // Validate Student ID format
        if (!ctype_digit($Sid) || strlen($Sid) !== 9) {
            throw new Exception("Invalid Student ID format");
        }

        // Check if student exists
        $student_check = "SELECT * FROM students WHERE Sid = ?";
        if ($check_stmt = $db->prepare($student_check)) {
            $check_stmt->bind_param("s", $Sid);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows === 0) {
                throw new Exception("Student ID not found");
            }
            $check_stmt->close();
        }

        // Check if already registered
        $check = "SELECT * FROM semester_registration WHERE SID=? AND program_code=? AND semester=? AND academic_year=?";
        if ($stmt = $db->prepare($check)) {
            $stmt->bind_param("ssss", $Sid, $program_code, $semester, $Year);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                throw new Exception("Student is already registered for this semester");
            }

            // Start transaction
            $db->begin_transaction();

            try {
                // Insert registration using the live schema.
                $insert = "INSERT INTO semester_registration (SID, program_code, semester, academic_year, registration_date, created_at)
                          VALUES (?, ?, ?, ?, NOW(), NOW())";
                if ($insert_stmt = $db->prepare($insert)) {
                    $insert_stmt->bind_param("ssss", $Sid, $program_code, $semester, $Year);
                    if (!$insert_stmt->execute()) {
                        throw new Exception("Failed to register student");
                    }
                    $insert_stmt->close();
                }

                $db->commit();
                echo json_encode(['success' => 'Registration successful']);
                exit;
            } catch (Exception $e) {
                $db->rollback();
                throw new Exception("Registration failed: " . $e->getMessage());
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log('admin_semester_reg error: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed. Please check inputs and try again.']);
        exit;
    }
}

// Handle batch registration
if (isset($_FILES['file'])) {
    header('Content-Type: application/json');

    try {
        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload failed');
        }

        $file = fopen($_FILES['file']['tmp_name'], 'r');
        if (!$file) {
            throw new Exception('Could not open file');
        }

        $db->begin_transaction();
        $success_count = 0;
        $error_count = 0;
        $row = 1;

        while (($data = fgetcsv($file)) !== FALSE) {
            if ($row === 1) {
                $row++;
                continue; // Skip header row
            }

            try {
                if (count($data) !== 5) {
                    throw new Exception("Invalid data format in row $row");
                }

                list($Sid, $program_code, $semester, $Year, $status) = $data;

                // Validate data
                if (!ctype_digit($Sid) || strlen($Sid) !== 9) {
                    throw new Exception("Invalid Student ID in row $row");
                }

                // Insert registration using the live schema.
                $insert = "INSERT INTO semester_registration (SID, program_code, semester, academic_year, registration_date, created_at)
                          VALUES (?, ?, ?, ?, NOW(), NOW())";
                $stmt = $db->prepare($insert);
                $stmt->bind_param("ssss", $Sid, $program_code, $semester, $Year);

                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    throw new Exception("Failed to insert row $row");
                }

                $stmt->close();
            } catch (Exception $e) {
                error_log("Error in row $row: " . $e->getMessage());
                $error_count++;
            }
            $row++;
        }

        fclose($file);

        if ($success_count > 0) {
            $db->commit();
            echo json_encode([
                'success' => true,
                'message' => "Processed $success_count registrations successfully" . 
                            ($error_count > 0 ? " ($error_count errors)" : "")
            ]);
        } else {
            $db->rollback();
            throw new Exception("No registrations were processed successfully");
        }
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Semester Registration</title>
    <link rel="stylesheet" href="dist/css/bootstrap.min.css">
    <style>
    :root {
        --primary-color: #1a237e;
        --primary-light: #534bae;
        --primary-dark: #000051;
        --secondary-color: #ffc107;
        --secondary-light: #fff350;
        --secondary-dark: #c79100;
        --background-color: #f5f5f5;
        --surface-color: #ffffff;
        --text-primary: #212121;
        --text-secondary: #757575;
        --success-color: #4CAF50;
        --error-color: #f44336;
        --warning-color: #ff9800;
        --spacing-small: 8px;
        --spacing-medium: 16px;
        --spacing-large: 24px;
        --border-radius: 4px;
        --card-border-radius: 12px;
        --inner-card-radius: 8px;
        --shadow-soft: 0 2px 4px rgba(0,0,0,0.08);
        --shadow-card: 0 8px 24px rgba(26, 35, 126, 0.12);
        --shadow-hover: 0 12px 32px rgba(26, 35, 126, 0.16);
    }

    body {
        background-color: var(--background-color);
        color: var(--text-primary);
        min-height: 100vh;
        padding: var(--spacing-large) 0;
    }

    .page-card {
        background: var(--surface-color);
        border-radius: var(--card-border-radius);
        box-shadow: var(--shadow-card);
        max-width: 1200px;
        margin: 0 auto;
        overflow: hidden;
    }

    .card-header {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        padding: var(--spacing-large);
        color: white;
    }

    .card-body {
        padding: var(--spacing-large);
    }

    .form-section {
        background: white;
        border-radius: var(--inner-card-radius);
        padding: var(--spacing-large);
        margin-bottom: var(--spacing-large);
        box-shadow: var(--shadow-soft);
    }

    .form-control {
        border: 2px solid rgba(0, 0, 0, 0.1);
        border-radius: var(--inner-card-radius);
        padding: 12px var(--spacing-medium);
    }

    .form-control:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        border: none;
        padding: 12px var(--spacing-large);
    }

    .btn-primary:hover {
        background: linear-gradient(135deg, var(--primary-light), var(--primary-color));
        transform: translateY(-1px);
    }

    .file-upload {
        border: 2px dashed rgba(0, 0, 0, 0.1);
        padding: var(--spacing-large);
        text-align: center;
        border-radius: var(--inner-card-radius);
        cursor: pointer;
    }

    .file-upload:hover {
        border-color: var(--primary-color);
        background: rgba(26, 35, 126, 0.02);
    }

    .notification {
        position: fixed;
        top: var(--spacing-large);
        right: var(--spacing-large);
        padding: var(--spacing-medium) var(--spacing-large);
        border-radius: var(--inner-card-radius);
        color: white;
        z-index: 1000;
        display: none;
    }

    @media (max-width: 768px) {
        .page-card {
            margin: var(--spacing-medium);
        }

        .form-section {
            padding: var(--spacing-medium);
        }

        .btn {
            width: 100%;
            margin-bottom: var(--spacing-small);
        }
    }
    </style>
</head>
<body>
    <div id="notification" class="notification"></div>
    <div class="page-card">
        <div class="card-header">
            <h2>Admin Semester Registration</h2>
        </div>
        <div class="card-body">
            <!-- Student Search Section -->
            <div class="form-section">
                <h3>Quick Student Search</h3>
                <div class="form-group">
                    <div class="input-group">
                        <input type="text" 
                               class="form-control" 
                               id="searchStudentId" 
                               placeholder="Enter student ID to check registration status"
                               pattern="\d{9}"
                               maxlength="9">
                        <div class="input-group-append">
                            <button class="btn btn-secondary" type="button" id="searchButton">
                                Search
                            </button>
                        </div>
                    </div>
                    <div id="searchResult" class="mt-2"></div>
                </div>
            </div>

            <!-- Main Registration Form -->
            <form id="registrationForm" class="form-section">
                <h3>Student Registration</h3>

                <div class="form-group">
                    <label for="Sid">Student ID</label>
                    <input type="text" 
                           class="form-control" 
                           id="Sid" 
                           name="Sid" 
                           required 
                           pattern="\d{9}"
                           maxlength="9"
                           placeholder="Enter 9-digit student ID"
                           aria-describedby="sidHelp">
                    <div class="invalid-feedback" id="sidHelp"></div>
                    <div class="valid-feedback">Student ID format is valid</div>
                </div>

                <div class="form-group">
                    <label for="program_code">Program</label>
                    <select class="form-control" 
                            id="program_code" 
                            name="program_code" 
                            required
                            aria-describedby="programHelp">
                        <option value="" disabled selected>Select a Program</option>
                    </select>
                    <div class="invalid-feedback" id="programHelp">Please select a program</div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="semester">Semester</label>
                            <select class="form-control" 
                                    id="semester" 
                                    name="semester" 
                                    required
                                    aria-describedby="semesterHelp">
                                <option value="" disabled selected>Select Semester</option>
                                <option value="Spring">Spring</option>
                                <option value="Summer">Summer</option>
                                <option value="Fall">Fall</option>
                            </select>
                            <div class="invalid-feedback" id="semesterHelp">Please select a semester</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="Year">Year</label>
                            <select class="form-control" 
                                    id="Year" 
                                    name="Year" 
                                    required
                                    aria-describedby="yearHelp">
                                <option value="" disabled selected>Select Year</option>
                            </select>
                            <div class="invalid-feedback" id="yearHelp">Please select a year</div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Registration Status</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input type="radio" 
                                   class="form-check-input" 
                                   id="statusRegistered" 
                                   name="status" 
                                   value="Registered" 
                                   required>
                            <label class="form-check-label" for="statusRegistered">Registered</label>
                        </div>
                        <div class="form-check">
                            <input type="radio" 
                                   class="form-check-input" 
                                   id="statusPending" 
                                   name="status" 
                                   value="Pending">
                            <label class="form-check-label" for="statusPending">Pending</label>
                        </div>
                        <div class="form-check">
                            <input type="radio" 
                                   class="form-check-input" 
                                   id="statusCancelled" 
                                   name="status" 
                                   value="Cancelled">
                            <label class="form-check-label" for="statusCancelled">Cancelled</label>
                        </div>
                    </div>
                    <div class="invalid-feedback">Please select a registration status</div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        Register Student
                    </button>
                    <button type="reset" class="btn btn-secondary">
                        Reset Form
                    </button>
                </div>
            </form>

            <!-- Batch Registration Section -->
            <div class="form-section">
                <h3>Batch Registration</h3>
                <div class="file-upload" id="dropZone">
                    <input type="file" 
                           id="batchFile" 
                           accept=".csv" 
                           style="display: none;">
                    <p>Drag & drop a CSV file here or click to select</p>
                    <small class="text-muted">
                        CSV format: StudentID, ProgramCode, Semester, Year, Status
                    </small>
                </div>
                <button class="btn btn-primary mt-3" id="uploadBtn" disabled>
                    Process Batch Registration
                </button>
            </div>
        </div>
    </div>

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="dist/js/bootstrap.bundle.min.js"></script>
    <script>
    $(document).ready(function() {
        // Constants for validation
        const STUDENT_ID = {
            LENGTH: 9,
            PATTERN: /^\d{9}$/,
            MIN_LENGTH: 9,
            MAX_LENGTH: 9
        };

        // Variables for typing timer
        let typingTimer;
        const doneTypingInterval = 500;

        // Initialize year dropdown
        function initializeYearDropdown() {
            const currentYear = new Date().getFullYear();
            const yearSelect = $('#Year');

            for (let i = 0; i <= 2; i++) {
                const year = currentYear + i;
                yearSelect.append($('<option>', {
                    value: year,
                    text: year
                }));
            }
        }

        // Load programs
        function loadPrograms() {
            const programSelect = $('#program_code');

            $.ajax({
                url: 'get_programs.php',
                type: 'GET',
                dataType: 'json',
                beforeSend: function() {
                    programSelect.html('<option value="" disabled selected>Loading programs...</option>');
                },
                success: function(response) {
                    programSelect.html('<option value="" disabled selected>Select a Program</option>');

                    if (response.programs && Array.isArray(response.programs)) {
                        response.programs.forEach(function(program) {
                            programSelect.append($('<option>', {
                                value: program.program_code,
                                text: program.program_name
                            }));
                        });
                    }
                },
                error: function() {
                    programSelect.html('<option value="" disabled selected>Error loading programs</option>');
                    showNotification('Failed to load programs', 'error');
                }
            });
        }

        // Validate fields
        function validateField(field) {
            const $field = $(field);
            const feedback = $field.siblings('.invalid-feedback');

            if (!$field.val()) {
                $field.addClass('is-invalid').removeClass('is-valid');
                feedback.show();
                return false;
            }

            $field.removeClass('is-invalid').addClass('is-valid');
            feedback.hide();
            return true;
        }

        // Show notifications
        function showNotification(message, type = 'error') {
            const notification = $('#notification');
            notification.removeClass().addClass('notification');

            switch(type) {
                case 'success':
                    notification.addClass('bg-success');
                    break;
                case 'error':
                    notification.addClass('bg-danger');
                    break;
                case 'warning':
                    notification.addClass('bg-warning text-dark');
                    break;
            }

            notification.text(message).fadeIn();
            setTimeout(() => notification.fadeOut(), 5000);
        }

        // Initialize form
        initializeYearDropdown();
        loadPrograms();

        // Event handlers
        $('#Sid').on('input', function() {
            const input = this;

            if (!/^\d*$/.test(input.value)) {
                input.value = input.value.replace(/\D/g, '');
            }

            if (input.value.length > STUDENT_ID.MAX_LENGTH) {
                input.value = input.value.slice(0, STUDENT_ID.MAX_LENGTH);
            }

            validateField(input);
        });

        $('select').on('change', function() {
            validateField(this);
        });

        // Form submission
        $('#registrationForm').on('submit', function(e) {
            e.preventDefault();
            const $form = $(this);
            const $submitBtn = $('#submitBtn');

            let isValid = true;

            $form.find('input[required], select[required]').each(function() {
                if (!validateField(this)) {
                    isValid = false;
                }
            });

            if (!$('input[name="status"]:checked').length) {
                isValid = false;
                showNotification('Please select a registration status', 'error');
            }

            if (!isValid) {
                showNotification('Please correct all errors before submitting', 'error');
                return;
            }

            $submitBtn.prop('disabled', true)
                .html('<span class="spinner-border spinner-border-sm"></span> Processing...');

            $.ajax({
                type: 'POST',
                url: window.location.href,
                data: $form.serialize() + '&submit=1',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showNotification(response.success, 'success');
                        $form[0].reset();
                        $form.find('.is-valid').removeClass('is-valid');
                    } else if (response.error) {
                        showNotification(response.error, 'error');
                    }
                },
                error: function() {
                    showNotification('Registration failed', 'error');
                },
                complete: function() {
                    $submitBtn.prop('disabled', false)
                        .html('Register Student');
                }
            });
        });

        // Quick search
        $('#searchButton').on('click', function() {
            const searchId = $('#searchStudentId').val().trim();
            const $result = $('#searchResult');

            if (!STUDENT_ID.PATTERN.test(searchId)) {
                $result.html('<div class="text-danger">Please enter a valid 9-digit student ID</div>');
                return;
            }

            $.ajax({
                url: 'check_registration.php',
                type: 'GET',
                data: { student_id: searchId },
                dataType: 'json',
                success: function(response) {
                    if (response.registered) {
                        $result.html(`
                            <div class="alert alert-info">
                                Student is registered for ${response.semester} ${response.year}
                                <br>Program: ${response.program}
                                <br>Status: ${response.status}
                            </div>
                        `);
                    } else {
                        $result.html('<div class="alert alert-warning">Student not registered for current semester</div>');
                    }
                },
                error: function() {
                    $result.html('<div class="alert alert-danger">Error checking registration status</div>');
                }
            });
        });

        // Batch upload
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('batchFile');
        const uploadBtn = document.getElementById('uploadBtn');

        dropZone.addEventListener('click', () => fileInput.click());

        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('bg-light');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('bg-light');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('bg-light');

            const file = e.dataTransfer.files[0];
            if (file && file.type === 'text/csv') {
                handleFile(file);
            } else {
                showNotification('Please upload a CSV file', 'error');
            }
        });

        fileInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) handleFile(file);
        });

        function handleFile(file) {
            uploadBtn.disabled = false;
            showNotification('File selected: ' + file.name, 'success');
        }

        uploadBtn.addEventListener('click', () => {
            const file = fileInput.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('file', file);

            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing...';

            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    showNotification(response.message, response.success ? 'success' : 'error');
                },
                error: function() {
                    showNotification('Batch registration failed', 'error');
                },
                complete: function() {
                    uploadBtn.disabled = false;
                    uploadBtn.innerHTML = 'Process Batch Registration';
                    fileInput.value = '';
                }
            });
        });
    });
    </script>
</body>
</html> 
