<?php
$page_title = 'Admit Student';
require_once 'includes/admin.php';
require_once "includes/header.php";
?>
<style>
    html,
    body.has-unified-sidebar,
    .main-wrapper,
    .main-content {
        background: #f6f8fb !important;
    }

    .search-card {
        background: #fff;
        border-radius: 12px;
        padding: 2.25rem;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
        border: 1px solid #e9eef5;
        margin-bottom: 2rem;
    }

    .search-card .stat-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 18px;
        box-shadow: none;
    }

    .search-card .bg-blue-soft { background: #e8f2ff !important; }
    .search-card .text-blue,
    .search-card .stat-icon i { color: #2563eb !important; }

    .form-label {
        font-weight: 700;
        color: #64748b;
        font-size: 0.78rem;
        margin-bottom: 0.5rem;
    }

    .search-card .input-group,
    .modal .input-group {
        border: 1px solid #dfe7f1;
        border-radius: 10px;
        overflow: hidden;
        background: #fff;
    }

    .search-card .input-group-text {
        background: #fff;
        border: 0;
        color: #64748b;
        min-width: 48px;
        justify-content: center;
    }

    .search-card .form-control,
    .search-card .form-select {
        border: 0;
        min-height: 48px;
        box-shadow: none;
    }

    .search-card .input-group:focus-within {
        border-color: #8bb8ff;
        box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.12);
    }

    @media (max-width: 575.98px) {
        .search-card { padding: 1.25rem; }
    }
</style>
<?php

// Initialize variables
$student = null;
$admission = null;
$programs = [];
$showModal = false;

// Note: students table has columns: mobile (not phone), dob (lowercase)

// Process search if form submitted
if (isset($_POST['search'])) {
    $SID = isset($_POST['SID']) ? trim($_POST['SID']) : '';
    
    if (!empty($SID)) {
        // Use actual column names from students table
        $phoneField = 'mobile';  // students table uses 'mobile'
        $dobField = 'dob';       // students table uses lowercase 'dob'

        $selectFields = "s.SID, s.Fname, s.Lname, s.sex, s.email";
        $selectFields .= ", s.`$phoneField` AS phone";
        $selectFields .= ", s.`$dobField` AS dob";

        $query = "SELECT $selectFields FROM students s WHERE s.SID = ? LIMIT 1";
        if ($stmt = $db->prepare($query)) {
            $stmt->bind_param("s", $SID);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                $student = $result->fetch_assoc();

                // Normalize for UI
                $student['FullName'] = trim(($student['Fname'] ?? '') . ' ' . ($student['Lname'] ?? ''));
                $student['GenderLabel'] = isset($student['sex']) && strtoupper($student['sex']) === 'F' ? 'Female' : (isset($student['sex']) ? 'Male' : '');

                // Fetch admission/program info if exists
                // student_program has no created_at column; order by the auto-increment
                // PK to get the most recent admission (the old created_at order fataled).
                $admSql = "SELECT sp.program_code, sp.intake, sp.mode, sp.startYear, sp.endYear, p.program_name
                           FROM student_program sp
                           INNER JOIN programs p ON sp.program_code = p.program_code
                           WHERE sp.Sid = ?
                           ORDER BY sp.id DESC LIMIT 1";
                if ($adm = $db->prepare($admSql)) {
                    $adm->bind_param('s', $SID);
                    $adm->execute();
                    $admRes = $adm->get_result();
                    if ($admRes && $admRes->num_rows > 0) {
                        $admission = $admRes->fetch_assoc();
                        $student['admitted'] = 1;
                        $student['program_name'] = $admission['program_name'];
                    } else {
                        $student['admitted'] = 0;
                    }
                    $adm->close();
                }

                // Load programs for admission form
                $progRes = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_name ASC");
                if ($progRes) { $programs = $progRes->fetch_all(MYSQLI_ASSOC); }

                $showModal = true;
            } else {
                $errorMsg = "No student found with ID: " . htmlspecialchars($SID);
            }
            
            $stmt->close();
        } else {
            $errorMsg = "Database error: " . $db->error;
        }
    }
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4 mt-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-user-check me-2 text-primary"></i>Admit Registered Student</h5>
                <p class="page-subtitle mb-0">Search and enroll existing registry records into academic programs</p>
            </div>
            <a href="students_by_admin.php" class="btn btn-outline-secondary shadow-sm">
                <i class="fas fa-arrow-left me-1"></i>Back to Records
            </a>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-12 col-xl-8">
            <div class="search-card">
                <div class="text-center mb-4">
                    <div class="stat-icon bg-blue-soft text-blue mx-auto mb-3" style="width: 70px; height: 70px; font-size: 1.5rem;">
                        <i class="fas fa-search"></i>
                    </div>
                    <h5 class="fw-bold mb-1">Find Student</h5>
                    <p class="text-muted small">Enter the Student ID (SID) to begin admission</p>
                </div>

                <form role="form" method="POST" action="" class="needs-validation" novalidate>
                    <div class="input-group input-group-lg shadow-sm rounded-10">
                        <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                        <input type="text" class="form-control form-control-lg" name="SID" id="SID" 
                               placeholder="e.g. 20261001" autocomplete="off" required>
                        <button type="submit" class="btn btn-primary px-4" name="search">
                            <i class="fas fa-search me-1"></i>Search Records
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

    <!-- Student Details Modal -->
    <div class="modal fade" id="studentDetailsModal" tabindex="-1" aria-labelledby="studentDetailsModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    <h5 class="modal-title" id="studentDetailsModalLabel">
                    <i class="fas fa-id-badge me-2"></i>Student Details Verification
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if ($student): ?>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <div class="alert alert-info">
                            <i class="fas fa-circle-info me-2"></i>
                                Please verify the student details below before proceeding with admission
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                        <div class="mb-2"><strong>Student ID:</strong> <?php echo htmlspecialchars($student['SID']); ?></div>
                        <div class="mb-2"><strong>Full Name:</strong> <?php echo htmlspecialchars($student['FullName']); ?></div>
                        <div class="mb-2"><strong>Gender:</strong> <?php echo htmlspecialchars($student['GenderLabel']); ?></div>
                        <?php if (!empty($student['dob'])): ?>
                        <div class="mb-2"><strong>Date of Birth:</strong> <?php echo htmlspecialchars($student['dob']); ?></div>
                        <?php endif; ?>
                            </div>
                            
                    <div class="col-md-6">
                        <div class="mb-2"><strong>Email:</strong> <?php echo htmlspecialchars($student['email'] ?? ''); ?></div>
                        <div class="mb-2"><strong>Phone:</strong> <?php echo htmlspecialchars($student['phone'] ?? ''); ?></div>
                        <div class="mb-2"><strong>Enrollment Status:</strong>
                            <?php if (!empty($student['admitted'])): ?>
                                <span class="badge bg-success">Already Admitted</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Pending Admission</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($student['admitted']) && !empty($student['program_name'])): ?>
                            <div class="mb-2"><strong>Program:</strong> <?php echo htmlspecialchars($student['program_name']); ?></div>
                        <?php endif; ?>
                                </div>
                            </div>
                <?php endif; ?>
                            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-circle-xmark me-1"></i> Close
                </button>

                <?php if ($student && empty($student['admitted'])): ?>
                <!-- Admission Form -->
                <form action="processAdmit_student.php" method="POST" class="w-100">
                    <input type="hidden" name="Sid" value="<?php echo htmlspecialchars($student['SID']); ?>">
                    <div class="row g-3 text-start">
                        <div class="col-md-6">
                            <label for="program_code" class="form-label">Program of Study</label>
                            <select class="form-select" name="program_code" id="program_code" required>
                                <option value="" disabled selected>Select program</option>
                                <?php foreach ($programs as $p): ?>
                                    <option value="<?php echo htmlspecialchars($p['program_code']); ?>">
                                        <?php echo htmlspecialchars($p['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            </div>
                        <div class="col-md-6">
                            <label for="intake" class="form-label">Intake</label>
                            <select class="form-select" name="intake" id="intake" required>
                                <option value="" disabled selected>Select intake</option>
                                <option value="January">January</option>
                                <option value="May">May</option>
                                <option value="July">July</option>
                                <option value="September">September</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="mode" class="form-label">Mode of Study</label>
                            <select class="form-select" name="mode" id="mode" required>
                                <option value="" disabled selected>Select mode</option>
                                <option value="Full-Time">Full-Time</option>
                                <option value="Part-Time(Evening)">Part-Time (Evening)</option>
                                <option value="Distance">Distance</option>
                                <option value="Short Course">Short Course</option>
                            </select>
                                </div>
                        <div class="col-md-6">
                            <label for="startYear" class="form-label">Start Year</label>
                            <select class="form-select" id="startYear" name="startYear" required>
                                <option value="" disabled selected>Select start year…</option>
                                <?php $cy = (int)date('Y'); for ($y = $cy + 1; $y >= $cy - 4; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y === $cy ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                            <small class="text-muted">Completion year is derived from the programme duration.</small>
                            </div>
                    </div>
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-3">
                        <a href="./students_by_admin.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-circle-check me-1"></i>Confirm & Admit Student
                        </button>
                    </div>
                    </form>
                    <?php elseif ($student): ?>
                    <button type="button" class="btn btn-secondary" disabled>
                    <i class="fas fa-circle-check me-1"></i> Student Already Admitted
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Help Modal -->
    <div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                <h5 class="modal-title" id="helpModalLabel"><i class="fas fa-circle-question me-2"></i>How to Admit a Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ol class="list-group list-group-numbered">
                        <li class="list-group-item">Enter the student's ID number in the search field</li>
                    <li class="list-group-item">Click the "Search" button to find the student's details</li>
                        <li class="list-group-item">Review the student's information in the verification popup</li>
                    <li class="list-group-item">Fill in program details and click "Confirm & Admit Student"</li>
                    </ol>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Form validation
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms)
                .forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        if (!form.checkValidity()) {
                            event.preventDefault()
                            event.stopPropagation()
                        }
                        form.classList.add('was-validated')
                    }, false)
                })
        })()
        
        // Show modal if student details are found
        <?php if ($showModal): ?>
        document.addEventListener('DOMContentLoaded', function() {
            var studentModal = new bootstrap.Modal(document.getElementById('studentDetailsModal'));
            studentModal.show();
        });
        <?php endif; ?>
    </script>

<?php require_once 'includes/footer.php'; ?>
