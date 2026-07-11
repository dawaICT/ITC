<?php
require "includes/admin.php";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle exemption approval / decline (POST + CSRF; ids are integers)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['approve_id']) || isset($_POST['decline_id']))) {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['errorMsg'] = 'Request verification failed. Please try again.';
    } elseif (isset($_POST['approve_id'])) {
        $coRegId = (int)$_POST['approve_id'];
        $stmt = $db->prepare("UPDATE exemption SET status = 1 WHERE CoRegID = ? AND status = 0");
        if ($stmt) {
            $stmt->bind_param('i', $coRegId);
            $stmt->execute();
            $stmt->close();
            // Deactivate the exempted course registration (id, not CoRegID,
            // is the real course_registration key).
            $del = $db->prepare("UPDATE course_registration SET is_active = 0, status = 'exempted' WHERE id = ?");
            if ($del) {
                $del->bind_param('i', $coRegId);
                $del->execute();
                $del->close();
            }
            $_SESSION['successMsg'] = "Exemption approved successfully";
        }
    } elseif (isset($_POST['decline_id'])) {
        $coRegId = (int)$_POST['decline_id'];
        $stmt = $db->prepare("UPDATE exemption SET status = 2 WHERE CoRegID = ? AND status = 0");
        if ($stmt) {
            $stmt->bind_param('i', $coRegId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['successMsg'] = "Exemption declined successfully";
        }
    }
    header('Location: exemption.php');
    exit;
}

require "includes/header.php";

// Get exemption records
$records = [];
if($results = $db->query("SELECT * FROM exemption")) {
    if($count = $results->num_rows) {
        while($row = $results->fetch_object()){
            $records[] = $row;
        }
        $results->free();
    }
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header student-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Course Exemptions</h1>
                <p class="text-muted">Manage student course exemption applications</p>
            </div>
            <div class="col-auto">
                <div class="header-actions d-flex gap-2">
                    <a href="index.php" class="btn btn-primary d-flex align-items-center gap-2">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <button class="btn btn-outline-secondary" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <!-- Total Applications Card -->
        <div class="col-xl-4 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary rounded-circle p-3 me-3">
                        <i class="fas fa-file-alt fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format(count($records)); ?></h3>
                        <p class="text-muted mb-0">Total Applications</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Applications Card -->
        <div class="col-xl-4 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning rounded-circle p-3 me-3">
                        <i class="fas fa-clock fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php 
                            $pending = array_filter($records, function($r) { return $r->status == 0; });
                            echo number_format(count($pending)); 
                        ?></h3>
                        <p class="text-muted mb-0">Pending Applications</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approved Applications Card -->
        <div class="col-xl-4 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success rounded-circle p-3 me-3">
                        <i class="fas fa-check-circle fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php 
                            $approved = array_filter($records, function($r) { return $r->status == 1; });
                            echo number_format(count($approved)); 
                        ?></h3>
                        <p class="text-muted mb-0">Approved Applications</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Exemptions Table -->
    <div class="row g-4">
        <div class="col-12">
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Exemption Applications
                        </h5>
                        <div class="header-actions">
                            <button class="btn btn-sm btn-success" onclick="exportToExcel()">
                                <i class="fas fa-file-excel me-1"></i>Export
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="window.print()">
                                <i class="fas fa-print me-1"></i>Print
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if(isset($_SESSION['successMsg'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php 
                            echo $_SESSION['successMsg'];
                            unset($_SESSION['successMsg']); 
                            ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table id="exemptionsTable" class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>No.</th>
                                    <th>Student ID</th>
                                    <th>Course Code</th>
                                    <th>Supporting Document</th>
                                    <th>Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $number = 1;
                                foreach($records as $r): 
                                ?>
                                    <tr>
                                        <td><?php echo $number++; ?>.</td>
                                        <td><?php echo htmlspecialchars((string)$r->Sid, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)$r->course_code, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <a href="../students/uploads/<?php echo htmlspecialchars(rawurlencode((string)$r->support_doc), ENT_QUOTES, 'UTF-8'); ?>"
                                               target="_blank" 
                                               class="btn btn-sm btn-warning">
                                                <i class="fas fa-eye"></i> View Document
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($r->status == 1): ?>
                                                <span class="badge bg-success">Approved</span>
                                            <?php elseif ($r->status == 2): ?>
                                                <span class="badge bg-danger">Declined</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex justify-content-center gap-2">
                                                <?php if ($r->status == 0): ?>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to approve this exemption?')">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="approve_id" value="<?php echo (int)$r->CoRegID; ?>">
                                                        <button type="submit" class="btn btn-sm btn-success rounded-pill"><i class="fas fa-check"></i></button>
                                                    </form>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to decline this application for exemption?')">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="decline_id" value="<?php echo (int)$r->CoRegID; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger rounded-pill"><i class="fas fa-times"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#exemptionsTable').DataTable({
        pageLength: 25,
        responsive: true,
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
        language: {
            search: "",
            searchPlaceholder: "Search exemptions...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ applications",
            paginate: {
                first: '<i class="fas fa-angle-double-left"></i>',
                last: '<i class="fas fa-angle-double-right"></i>',
                next: '<i class="fas fa-angle-right"></i>',
                previous: '<i class="fas fa-angle-left"></i>'
            }
        },
        columnDefs: [
            { orderable: false, targets: [3, 5] }
        ]
    });
});

function exportToExcel() {
    let table = document.querySelector('#exemptionsTable');
    let html = table.outerHTML;
    let url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    let downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    downloadLink.href = url;
    downloadLink.download = 'exemptions.xls';
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require_once "includes/footer.php"; ?>

