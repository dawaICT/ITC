<?php
$page_title = 'Admin Slip';
require "includes/nav.php";
require_once __DIR__ . '/includes/hod_schema_helpers.php';
error_reporting(0);

// Fetch student data if view parameter is set
$records = [];
$slipAccessDenied = false;
if (isset($_GET['view'])) {
    $SID = trim($_GET['view']);
    $hodStaffId = (string)($_SESSION['staff_id'] ?? '');

    // A HOS may only print slips for students enrolled in their own section's
    // programmes; systems admins keep full visibility.
    $allowed = function_exists('hos_can_switch_sections') && hos_can_switch_sections($db, $hodStaffId);
    if (!$allowed) {
        $deptContext = hod_resolve_department($db, $hodStaffId);
        $allowed = hod_student_in_section($db, $deptContext, $SID);
    }

    if ($allowed) {
        $sql = "SELECT students.*, student_program.program_code, programs.program_name
                FROM students
                INNER JOIN student_program ON students.SID = student_program.Sid
                INNER JOIN programs ON student_program.program_code = programs.program_code
                WHERE students.SID = ?";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('s', $SID);
            if ($stmt->execute()) {
                $results = $stmt->get_result();
                while ($row = $results->fetch_object()) {
                    $records[] = $row;
                }
            }
            $stmt->close();
        }
    } else {
        $slipAccessDenied = true;
        require_once dirname(__DIR__) . '/includes/audit.php';
        if (function_exists('audit_log_current_user')) {
            audit_log_current_user($db, 'security.hos_admin_slip_denied', [
                'requested_sid' => $SID,
            ]);
        }
    }
}
?>

<div class="container-fluid px-4 portal-dashboard hod-page admin-slip-page">
    <!-- Dashboard Header -->
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Admin Slip</h1>
                <p class="text-muted mb-0">Generate and print student admission slips</p>
            </div>
            <div class="col-auto">
                <div class="header-actions d-flex gap-2">
                    <a href="students.php" class="btn btn-outline-success">
                        <i class="fas fa-arrow-left me-2"></i>Back to Students
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($slipAccessDenied)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-ban me-2"></i>
            This student is not enrolled in your section, so their admission slip is not available
            from your Head of Section account.
        </div>
        <a href="students.php" class="btn btn-success"><i class="fas fa-users me-2"></i>Back to My Students</a>
    <?php elseif (empty($records)): ?>
        <!-- No student selected state -->
        <div class="data-table-card mb-4">
            <div class="card-body text-center py-5">
                <div class="mb-4">
                    <i class="fas fa-file-alt fa-4x text-muted"></i>
                </div>
                <h4>No Student Selected</h4>
                <p class="text-muted">Please select a student from the students list to view their admission slip.</p>
                <a href="students.php" class="btn btn-success mt-3">
                    <i class="fas fa-users me-2"></i>View Students
                </a>
            </div>
        </div>
    <?php else: ?>
        <?php $r = $records[0]; ?>
        <!-- Student Admission Slip -->
        <div class="data-table-card mb-4">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-file-alt me-2"></i>Student Admission Slip
                    </h5>
                    <div class="header-actions">
                        <button class="btn btn-success btn-sm d-print-none" id="printButton">
                            <i class="fas fa-print me-2"></i>Print Slip
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div id="printArea" class="border p-4 bg-white">
                    <!-- Institution Header -->
                    <div class="text-center mb-4">
                        <img src="/wucportal/images/itc_logo.png" alt="ITC Logo" class="img-fluid admin-slip-logo">
                        <h2 class="mt-3 py-2 bg-warning">Industrial Training Centre</h2>
                    </div>

                    <!-- Memo Header -->
                    <div class="border p-3 mb-4">
                        <h4 class="mb-3">MEMORANDUM</h4>
                        <p class="mb-1"><strong>To:</strong> All staff members/Librarian/Security/Parents</p>
                        <p class="mb-1"><strong>From:</strong> Registrar</p>
                        <p class="mb-1"><strong>Subject:</strong> Admission Slip</p>
                        <p class="mb-1"><strong>Date:</strong> <?php echo date('F d, Y'); ?></p>
                    </div>

                    <!-- Slip Content -->
                    <div class="mb-4">
                        <p class="mb-3">
                            I confirm that <strong><?php echo htmlspecialchars($r->Fname . ' ' . ($r->Lname ?? '')); ?></strong> with Student Number
                            <strong><?php echo htmlspecialchars($r->SID); ?></strong> under the Program of <strong><?php echo htmlspecialchars($r->program_name); ?></strong>
                            has completed the process of registration having paid the amount of <strong>K____________</strong>.
                        </p>
                        <p class="mb-3">
                            His/Her registration has been approved and will be
                            <span class="border-bottom border-dark px-1">Day scholar / Boarder</span>.
                        </p>
                        <p class="mb-4">
                            Therefore, he/she is a bona fide student of this institution and is entitled to participate in all institutional
                            activities unless otherwise indicated here.
                        </p>
                        <div class="mb-4">
                            <p class="mb-2"><strong>Special Remarks:</strong></p>
                            <p class="border-bottom border-dark py-2">&nbsp;</p>
                            <p class="border-bottom border-dark py-2">&nbsp;</p>
                        </div>
                    </div>

                    <!-- Signature Section -->
                    <div class="row mt-5">
                        <div class="col-md-6">
                            <div class="mb-4">
                                <p class="border-bottom border-dark pb-1">&nbsp;</p>
                                <p class="text-center"><strong>Name</strong></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-4">
                                <p class="border-bottom border-dark pb-1">&nbsp;</p>
                                <p class="text-center"><strong>Signature</strong></p>
                            </div>
                        </div>
                    </div>

                    <!-- Official Stamp Area -->
                    <div class="row mt-4">
                        <div class="col-md-6 offset-md-6">
                            <div class="border border-dark rounded p-4 text-center">
                                <p class="mb-0">Official Stamp</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Student Details Card (below the slip, hidden for print) -->
        <div class="row g-4 mb-4 d-print-none">
            <div class="col-md-6">
                <div class="data-table-card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">Student Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-borderless">
                                <tr>
                                    <th class="admin-slip-label-col">Student ID:</th>
                                    <td><?php echo htmlspecialchars($r->SID); ?></td>
                                </tr>
                                <tr>
                                    <th>Full Name:</th>
                                    <td><?php echo htmlspecialchars($r->Fname . ' ' . ($r->Lname ?? '')); ?></td>
                                </tr>
                                <tr>
                                    <th>Program:</th>
                                    <td><?php echo htmlspecialchars($r->program_name); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="data-table-card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button class="btn btn-success" id="editSlipBtn">
                                <i class="fas fa-edit me-2"></i>Edit Slip Details
                            </button>
                            <button class="btn btn-info" id="emailSlipBtn">
                                <i class="fas fa-envelope me-2"></i>Email Slip
                            </button>
                            <button class="btn btn-warning" id="viewHistoryBtn">
                                <i class="fas fa-history me-2"></i>View Previous Slips
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Print and Action Scripts -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const printButton = document.getElementById('printButton');
    if (printButton) {
        printButton.addEventListener('click', function() {
            window.print();
        });
    }

    // Action button handlers
    $('#editSlipBtn').on('click', function() {
        Swal.fire({
            title: 'Edit Slip',
            text: 'This would open an editor to modify the slip details.',
            icon: 'info',
            confirmButtonColor: '#198754'
        });
    });

    $('#emailSlipBtn').on('click', function() {
        Swal.fire({
            title: 'Email Slip',
            text: 'This would send the slip to the student and relevant departments.',
            icon: 'info',
            confirmButtonColor: '#198754'
        });
    });

    $('#viewHistoryBtn').on('click', function() {
        Swal.fire({
            title: 'Slip History',
            text: 'This would show previous slips issued to the student.',
            icon: 'info',
            confirmButtonColor: '#198754'
        });
    });
});
</script>

<?php require "includes/footer.php"; ?>

