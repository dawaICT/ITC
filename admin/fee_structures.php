<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Start output buffering
ob_start();

// Start session
session_start();

// Define root path
$root_path = dirname(dirname(__FILE__));

// Include database connection
require_once $root_path . '/db/connect.php';
require_once $root_path . '/includes/audit.php';

// Include header
require_once 'includes/header.php';
// Function to log errors
function log_error($message) {
    global $root_path;
    error_log($message . "\n", 3, $root_path . "/logs/error.log");
}

// Function to sanitize input
function sanitize_input($data) {
    global $db;
    return $db->real_escape_string(trim($data));
}

// Function to validate input
function validate_input($data, $type = 'text') {
    switch ($type) {
        case 'number':
            return is_numeric($data);
        case 'email':
            return filter_var($data, FILTER_VALIDATE_EMAIL);
        default:
            return !empty($data);
    }
}

// Function to log audit trail
function log_audit($user_id, $action, $details) {
    global $db;
    audit_log($db, (string)$user_id, (string)$action, ['details' => $details]);
}

// Get all fee structures
$sql = "SELECT fs.*, p.program_name 
        FROM fee_structure fs 
        LEFT JOIN programs p ON fs.program_code = p.program_code 
        ORDER BY fs.program_code, fs.year_of_study, fs.semester";
$result = $db->query($sql);

// Debug output
error_log("SQL Query: " . $sql);
error_log("Number of rows: " . $result->num_rows);

// Get total count
$total_count = $result->num_rows;

// Get total amount
$total_amount = 0;
if ($result->num_rows > 0) {
    $result->data_seek(0);
    while ($row = $result->fetch_assoc()) {
        $total_amount += $row['amount'];
    }
    $result->data_seek(0);
}
?>

<div class="container-fluid">
    <div class="container-content">
        <!-- Dashboard Header -->
        <div class="dashboard-header mb-4">
            <div class="row align-items-center">
                <div class="col">
                    <h1 class="dashboard-title">
                        <i class="fas fa-money-bill-wave text-primary me-2"></i>Fee Structure Management
                    </h1>
                    <p class="text-muted">Manage fee structures for all programs and semesters</p>
                </div>
                <div class="col-auto">
                    <a href="add_fee_structure.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Add New Fee
                    </a>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <!-- Total Fee Structures Card -->
            <div class="col-xl-4 col-md-6">
                <div class="stat-card primary h-100">
                    <div class="stat-icon icon-lg icon-circle icon-primary">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo number_format($total_count); ?></h3>
                        <p>Total Fee Structures</p>
                    </div>
                </div>
            </div>

            <!-- Total Amount Card -->
            <div class="col-xl-4 col-md-6">
                <div class="stat-card success h-100">
                    <div class="stat-icon icon-lg icon-circle icon-success">
                        <i class="fas fa-money-bill-alt"></i>
                    </div>
                    <div class="stat-content">
                        <h3>ZMK <?php echo number_format($total_amount, 2); ?></h3>
                        <p>Total Amount</p>
                    </div>
                </div>
            </div>
            
            <!-- Active Fees Card -->
            <div class="col-xl-4 col-md-6">
                <div class="stat-card warning h-100">
                    <div class="stat-icon icon-lg icon-circle icon-warning">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo date('Y'); ?></h3>
                        <p>Current Academic Year</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fee Structure Table Card -->
        <div class="data-table-card mb-4">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-table me-2"></i>Fee Structures
                    </h5>
                    <div class="header-actions">
                        <div class="input-group input-group-sm" style="width: auto;">
                            <input type="text" class="form-control" id="searchInput" placeholder="Search fees...">
                            <button class="btn btn-outline-secondary" type="button">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" width="5%">#</th>
                                <th width="20%">Program</th>
                                <th width="10%">Year</th>
                                <th width="10%">Semester</th>
                                <th width="25%">Description</th>
                                <th width="15%" class="text-end">Amount (ZMK)</th>
                                <th width="10%">Status</th>
                                <th width="10%" class="text-center pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($result->num_rows > 0) {
                                $counter = 1;
                                while ($row = $result->fetch_assoc()) {
                                    // Debug output
                                    error_log("Processing row: " . print_r($row, true));
                                    
                                    echo "<tr>";
                                    echo "<td class='ps-3'>" . $counter++ . "</td>";
                                    echo "<td><strong>" . htmlspecialchars($row['program_name']) . "</strong><br><small class='text-muted'>" . htmlspecialchars($row['program_code']) . "</small></td>";
                                    echo "<td>Year " . htmlspecialchars($row['year_of_study']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['semester']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['fee_description']) . "</td>";
                                    echo "<td class='text-end fw-bold'>ZMK " . number_format($row['amount'], 2) . "</td>";
                                    
                                    // Status badge
                                    $statusClass = $row['status'] == 'active' ? 'bg-success' : 'bg-warning';
                                    echo "<td><span class='badge rounded-pill {$statusClass}'>" . ucfirst($row['status']) . "</span></td>";
                                    
                                    // Action buttons
                                    echo "<td class='text-center pe-3'>
                                            <div class='d-flex justify-content-center gap-2'>
                                                <a href='edit_fee_structure.php?id=" . $row['id'] . "' class='btn btn-sm btn-warning' title='Edit'>
                                                    <i class='fas fa-edit'></i>
                                                </a>
                                                <button type='button' class='btn btn-sm btn-danger delete-fee' data-id='" . $row['id'] . "' title='Delete'>
                                                    <i class='fas fa-trash'></i>
                                                </button>
                                            </div>
                                          </td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr>
                                        <td colspan='8' class='text-center py-5'>
                                            <div class='d-flex flex-column align-items-center'>
                                                <i class='fas fa-folder-open fa-3x text-muted mb-3'></i>
                                                <h5 class='fw-bold'>No Fee Structures Found</h5>
                                                <p class='text-muted'>There are no fee structures created yet.</p>
                                                <a href='add_fee_structure.php' class='btn btn-primary mt-2'>
                                                    <i class='fas fa-plus me-2'></i>Add New Fee Structure
                                                </a>
                                            </div>
                                        </td>
                                      </tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted">Showing <?php echo $total_count; ?> fee structures</span>
                    <div>
                        <a href="fee_structure_print.php" class="btn btn-sm btn-outline-secondary me-2">
                            <i class="fas fa-print me-2"></i>Print
                        </a>
                        <a href="fee_structure_export.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-download me-2"></i>Export to Excel
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    console.log('Document ready');
    
    // Search functionality
    $('#searchInput').on('keyup', function() {
        console.log('Search input changed');
        var value = $(this).val().toLowerCase();
        $('table tbody tr').filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });

    // Delete functionality
    $('.delete-fee').click(function() {
        console.log('Delete button clicked');
        var id = $(this).data('id');
        
        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74a3b',
            cancelButtonColor: '#858796',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'ajax/delete_fee_structure.php',
                    type: 'POST',
                    data: { id: id },
                    success: function(response) {
                        console.log('Delete response:', response);
                        var data = JSON.parse(response);
                        if (data.success) {
                            Swal.fire(
                                'Deleted!',
                                'The fee structure has been deleted.',
                                'success'
                            ).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire(
                                'Error!',
                                'Error deleting fee structure: ' + data.message,
                                'error'
                            );
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Delete error:', error);
                        Swal.fire(
                            'Error!',
                            'There was a problem connecting to the server.',
                            'error'
                        );
                    }
                });
            }
        });
    });
});
</script>

<?php
// Include footer
require_once 'includes/footer.php';

// End output buffering and flush
ob_end_flush();
?> 
