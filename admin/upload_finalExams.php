<?php
include "includes/admin.php";
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/academic_risk_engine.php';
require_once dirname(__DIR__) . '/includes/exam_upload_service.php';

if (function_exists('canEnterExamMarks') && !canEnterExamMarks()) {
    $_SESSION['errorMsg'] = 'Access denied. You do not have permission to upload exam marks.';
    header('Location: index.php');
    exit();
}

require "includes/header.php";

// Link the admin dashboard stylesheet
error_reporting(0);

function wuc_admin_exam_upload_alert(string $message): void
{
    echo "<script>alert(" . json_encode($message) . ");</script>";
}

$examUploadActor = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');

// Process individual result upload
if(isset($_POST["submit_individual"])) {
    $save = wuc_exam_upload_save_mark(
        $db,
        (string)($_POST["Sid"] ?? ''),
        (string)($_POST["Course_Code"] ?? ''),
        $_POST["Exam_marks"] ?? '',
        (string)($_POST["semester"] ?? ''),
        (string)($_POST["Year"] ?? ''),
        $examUploadActor
    );
    wuc_admin_exam_upload_alert($save['message'] ?? ($save['ok'] ? 'Student exam result submitted successfully.' : 'Failed to save exam result.'));
}

// Process CSV upload
if(isset($_POST["submit_csv"])) {
    if(isset($_FILES["file"]) && is_uploaded_file($_FILES["file"]["tmp_name"])) {
        $file = $_FILES["file"]["tmp_name"];
        $file_open = fopen($file, "r");
        
        // Skip the header row
        fgetcsv($file_open);
        
        $success_count = 0;
        $error_count = 0;
        $messages = [];
        $line = 1;
        
        while(($csv = fgetcsv($file_open, 10000, ",")) !== false) {
            $line++;
            if (count($csv) < 5) {
                $error_count++;
                if (count($messages) < 5) {
                    $messages[] = "Line {$line}: missing required columns.";
                }
                continue;
            }

            $save = wuc_exam_upload_save_mark(
                $db,
                (string)$csv[0],
                (string)$csv[1],
                $csv[2] ?? '',
                (string)($csv[3] ?? ''),
                (string)($csv[4] ?? ''),
                $examUploadActor
            );

            if (!empty($save['ok'])) {
                $success_count++;
            } else {
                $error_count++;
                if (count($messages) < 5) {
                    $messages[] = "Line {$line}: " . (string)($save['message'] ?? 'Unable to save result.');
                }
            }
        }
        fclose($file_open);
        
        $detail = empty($messages) ? '' : "\n" . implode("\n", $messages);
        wuc_admin_exam_upload_alert("Upload completed. Saved: {$success_count}, Failed: {$error_count}{$detail}");
    }
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Upload Final Exam Results</h1>
                <p class="text-muted">Upload and manage student exam results</p>
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-secondary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Print
                </button>
            </div>
        </div>
    </div>

    <!-- Individual Upload Card -->
    <div class="data-table-card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-user-plus me-2"></i>Upload Individual Result
                </h5>
            </div>
        </div>
        <div class="card-body">
            <form method="POST" action="" class="needs-validation" novalidate>
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Course Code</label>
                            <select class="form-control" name="Course_Code" id="Course_Code" required>
                                <option value="" disabled selected>Select course</option>
                                <?php
                                if($Results = $db->query("SELECT * FROM courses")) {
                                    while($row = $Results->fetch_object()) {
                                        echo "<option value='{$row->course_code}'>{$row->course_code}</option>";
                                    }
                                }
                                ?>
                            </select>
                            <div class="invalid-feedback">Please select a course</div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Student ID</label>
                            <select class="form-control" name="Sid" id="Sid" required>
                                <option value="" disabled selected>Select student</option>
                            </select>
                            <div class="invalid-feedback">Please select a student</div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Exam Marks (out of 100)</label>
                            <input type="number" class="form-control" name="Exam_marks" min="0" max="100" required>
                            <div class="invalid-feedback">Please enter valid exam marks</div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Semester</label>
                            <select class="form-control" name="semester" id="semester" required>
                                <option value="" disabled selected>Select semester</option>
                                <option value="1">Semester 1</option>
                                <option value="2">Semester 2</option>
                            </select>
                            <div class="invalid-feedback">Please select a semester</div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Academic Year</label>
                            <input type="text" class="form-control" name="Year" id="Year" required>
                            <div class="invalid-feedback">Please enter academic year</div>
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="submit" name="submit_individual" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Result
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- CSV Upload Card -->
    <div class="data-table-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-upload me-2"></i>Upload Results File
                </h5>
            </div>
        </div>
        <div class="card-body">
            <form method="POST" action="" enctype="multipart/form-data" class="needs-validation" novalidate>
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Select Results File</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-file-excel"></i>
                                </span>
                                <input type="file" 
                                       class="form-control" 
                                       name="file" 
                                       accept=".csv"
                                       required>
                            </div>
                            <div class="invalid-feedback">
                                Please select a valid CSV file
                            </div>
                            <small class="text-muted">Accepted format: .csv</small>
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="submit" name="submit_csv" class="btn btn-primary">
                            <i class="fas fa-cloud-upload-alt me-2"></i>Upload Results
                        </button>
                    </div>
                </div>
            </form>

            <!-- File Format Guide -->
            <div class="mt-4">
                <h6 class="text-primary mb-3">
                    <i class="fas fa-info-circle me-2"></i>File Format Guide
                </h6>
                <div class="alert alert-info">
                    <p class="mb-2">Your CSV file should include the following columns in order:</p>
                    <ul class="mb-0">
                        <li>Student ID (required)</li>
                        <li>Course Code (required)</li>
                        <li>Exam Score (required, out of 100)</li>
                        <li>Semester (required, 1 or 2)</li>
                        <li>Academic Year (required)</li>
                    </ul>
                    <div class="mt-3">
                        <a href="templates/exam_results_template.csv" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-download me-2"></i>Download Template
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();
</script>

<script>
$(document).ready(function() {
    // Handle course selection change
    $('#Course_Code').change(function() {
        var courseCode = $(this).val();
        var semester = $('#semester').val();
        var year = $('#Year').val();
        
        if(courseCode && semester && year) {
            loadStudents(courseCode, semester, year);
        }
    });

    // Handle semester and year changes
    $('#semester, #Year').change(function() {
        var courseCode = $('#Course_Code').val();
        var semester = $('#semester').val();
        var year = $('#Year').val();
        
        if(courseCode && semester && year) {
            loadStudents(courseCode, semester, year);
        }
    });

    function loadStudents(courseCode, semester, year) {
        $.ajax({
            url: 'get_students.php',
            type: 'POST',
            data: {
                course_code: courseCode,
                semester: semester,
                year: year
            },
            success: function(response) {
                $('#Sid').html(response);
            },
            error: function() {
                alert('Error loading students. Please try again.');
            }
        });
    }
});
</script>

<?php require_once "includes/footer.php"; ?>

