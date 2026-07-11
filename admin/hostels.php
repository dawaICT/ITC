<?php
$page_title = "Hostel Management";
require_once "includes/admin.php";
require_once "includes/header.php";
error_reporting(0);

// Link the admin dashboard stylesheet
// Get hostel statistics
$total_applicants = 0;
$total_boarders = 0;
$total_rooms = 0;

try {
    $stats_query = "SELECT 
        COUNT(DISTINCT ba.Sid) as applicant_count,
        COUNT(DISTINCT s.SID) as boarder_count,
        COUNT(DISTINCT r.room_id) as room_count
    FROM boarding_applicants ba
    LEFT JOIN students s ON ba.Sid = s.SID
    LEFT JOIN rooms r ON 1=1";

    if($result = $db->query($stats_query)) {
        $stats = $result->fetch_object();
        $total_applicants = $stats->applicant_count;
        $total_boarders = $stats->boarder_count;
        $total_rooms = $stats->room_count;
        $result->free();
    }
} catch (Exception $e) {
    error_log("Error fetching hostel statistics: " . $e->getMessage());
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Hostel Management</h1>
                <p class="text-muted">Manage student accommodation and boarding facilities</p>
            </div>
            <div class="col-auto">
                <div class="d-flex gap-2">
                    <a href="index.php" class="btn btn-outline-primary d-flex align-items-center gap-2">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <!-- Total Applicants Card -->
        <div class="col-xl-4 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary rounded-circle p-3 me-3">
                        <i class="fas fa-users fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($total_applicants); ?></h3>
                        <p class="text-muted mb-0">Total Applicants</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Current Boarders Card -->
        <div class="col-xl-4 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success rounded-circle p-3 me-3">
                        <i class="fas fa-bed fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($total_boarders); ?></h3>
                        <p class="text-muted mb-0">Current Boarders</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Rooms Card -->
        <div class="col-xl-4 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info rounded-circle p-3 me-3">
                        <i class="fas fa-door-open fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($total_rooms); ?></h3>
                        <p class="text-muted mb-0">Total Rooms</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-bolt me-2"></i>Quick Actions
                        </h5>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-3 col-md-6">
                            <a href="applicants.php" class="btn btn-outline-primary w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 quick-action-btn">
                                <i class="fas fa-eye fa-2x mb-2"></i>
                                <span class="fw-bold">View Applicants</span>
                                <small class="text-muted mt-1">Manage boarding applications</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="boarders.php" class="btn btn-outline-success w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 quick-action-btn">
                                <i class="fas fa-users fa-2x mb-2"></i>
                                <span class="fw-bold">View Boarders</span>
                                <small class="text-muted mt-1">Current hostel residents</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="rooms.php" class="btn btn-outline-info w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 quick-action-btn">
                                <i class="fas fa-door-open fa-2x mb-2"></i>
                                <span class="fw-bold">Manage Rooms</span>
                                <small class="text-muted mt-1">Room allocation & maintenance</small>
                            </a>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <a href="hostel_settings.php" class="btn btn-outline-warning w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 quick-action-btn">
                                <i class="fas fa-cog fa-2x mb-2"></i>
                                <span class="fw-bold">Settings</span>
                                <small class="text-muted mt-1">Configure hostel options</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Applicants Table -->
    <div class="row g-4">
        <div class="col-12">
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Student Boarding Applicants
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
                    <div class="table-responsive">
                        <table id="applicantsTable" class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>No.</th>
                                    <th>Student No</th>
                                    <th>Names</th>
                                    <th>Gender</th>
                                    <th>Program</th>
                                    <th>Reason</th>
                                    <th>Date Applied</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if(isset($records)) {
                                    foreach($records as $r) {
                                ?>
                                    <tr>
                                        <td><?php echo $number++; ?>.</td>
                                        <td><?php echo htmlspecialchars($r->SID); ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-circle me-2 bg-primary text-white">
                                                    <?php echo strtoupper(substr($r->Fname, 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <?php echo htmlspecialchars($r->Fname . ' ' . $r->Lname); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $r->sex == 'M' ? 'info' : 'danger'; ?>">
                                                <?php echo $r->sex == 'M' ? 'Male' : 'Female'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($r->program_name); ?></td>
                                        <td><?php echo htmlspecialchars($r->reason); ?></td>
                                        <td><?php echo htmlspecialchars($r->dte); ?></td>
                                        <td>
                                            <div class="d-flex justify-content-center gap-2">
                                                <a href="view_application.php?id=<?php echo htmlspecialchars($r->SID); ?>" 
                                                   class="btn btn-sm btn-info">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="approve_application.php?id=<?php echo htmlspecialchars($r->SID); ?>" 
                                                   class="btn btn-sm btn-success">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                                <a href="deleteStudent.php?del=<?php echo htmlspecialchars($r->SID); ?>" 
                                                   class="btn btn-sm btn-danger"
                                                   onclick="return confirm('Are you sure you want to delete this application?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php 
                                    }
                                }  
                                ?>
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
    $('#applicantsTable').DataTable({
        pageLength: 10,
        responsive: true,
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
        language: {
            search: "",
            searchPlaceholder: "Search records...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ records",
            paginate: {
                first: '<i class="fas fa-angle-double-left"></i>',
                last: '<i class="fas fa-angle-double-right"></i>',
                next: '<i class="fas fa-angle-right"></i>',
                previous: '<i class="fas fa-angle-left"></i>'
            }
        }
    });
});

function exportToExcel() {
    let table = document.querySelector('#applicantsTable');
    let html = table.outerHTML;
    let url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    let downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    downloadLink.href = url;
    downloadLink.download = 'hostel_applicants.xls';
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require_once "includes/footer.php"; ?>

