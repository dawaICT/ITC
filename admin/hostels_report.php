<?php
$page_title = "Hostels Capacity Report";
require_once "includes/admin.php";
require_once "includes/header.php";
require_once __DIR__ . '/../includes/report_print.php';
error_reporting(0);

// Only systems admin can access this page
if (!$isAdmin) {
    header("Location: /wucportal/admin/index.php");
    exit();
}

// Verify database connection
if (!isset($db) || !$db) {
    die('<div class="alert alert-danger">Database connection failed. Please check your database configuration.</div>');
}

// -----------------------------------------------------------------------
// Query the hostels table for capacity and occupancy data.
// Expected columns: hostel_name, total_beds, occupied_beds
// Column detection handles common schema variations gracefully.
// -----------------------------------------------------------------------

$hostels        = [];
$total_beds_all = 0;
$total_occupied = 0;
$query_error    = null;

// Check whether the hostels table exists
$table_check = $db->query("SHOW TABLES LIKE 'hostels'");
$hostels_table_exists = ($table_check && $table_check->num_rows > 0);

if ($hostels_table_exists) {
    // Detect actual column names to handle schema variations
    $col_map = [];
    $col_res = $db->query("SHOW COLUMNS FROM `hostels`");
    if ($col_res) {
        while ($col = $col_res->fetch_assoc()) {
            $col_map[] = strtolower($col['Field']);
        }
        $col_res->free();
    }

    // Map name column
    $name_col = 'hostel_name';
    foreach (['hostel_name', 'name', 'hostel', 'block_name', 'block'] as $candidate) {
        if (in_array($candidate, $col_map)) { $name_col = $candidate; break; }
    }

    // Map total beds column
    $total_col = 'total_beds';
    foreach (['total_beds', 'capacity', 'total_capacity', 'beds', 'bed_count'] as $candidate) {
        if (in_array($candidate, $col_map)) { $total_col = $candidate; break; }
    }

    // Map occupied beds column
    $occupied_col = 'occupied_beds';
    foreach (['occupied_beds', 'current_occupancy', 'occupancy', 'occupied', 'current_occupants', 'boarders'] as $candidate) {
        if (in_array($candidate, $col_map)) { $occupied_col = $candidate; break; }
    }

    $sql = "SELECT `{$name_col}` AS hostel_name,
                   `{$total_col}`    AS total_beds,
                   `{$occupied_col}` AS occupied_beds
            FROM `hostels`
            ORDER BY `{$name_col}` ASC";

    $result = $db->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['total_beds']    = (int) $row['total_beds'];
            $row['occupied_beds'] = (int) $row['occupied_beds'];
            $row['available_beds'] = max(0, $row['total_beds'] - $row['occupied_beds']);

            // Occupancy percentage
            if ($row['total_beds'] > 0) {
                $row['occupancy_pct'] = round(($row['occupied_beds'] / $row['total_beds']) * 100);
            } else {
                $row['occupancy_pct'] = 0;
            }

            // Status: Full when all beds are taken, Available otherwise
            $row['status']       = ($row['available_beds'] === 0) ? 'Full' : 'Available';
            $row['status_class'] = ($row['status'] === 'Full')    ? 'danger' : 'success';

            $total_beds_all += $row['total_beds'];
            $total_occupied += $row['occupied_beds'];

            $hostels[] = $row;
        }
        $result->free();
    } else {
        $query_error = $db->error;
    }
} else {
    $query_error = "The <strong>hostels</strong> table does not exist in the database. "
                 . "Please run the database setup script to create it.";
}

// Overall totals for summary cards
$total_available   = max(0, $total_beds_all - $total_occupied);
$overall_occupancy = ($total_beds_all > 0)
    ? round(($total_occupied / $total_beds_all) * 100)
    : 0;
$full_count = count(array_filter($hostels, fn($h) => $h['status'] === 'Full'));
?>

<div class="container-fluid px-4 portal-dashboard">

    <?php
    render_report_print_styles();
    render_report_print_script();
    render_report_print_header(
        'Hostels Capacity Report',
        'Bed capacity and current occupancy',
        [
            'Hostels'        => count($hostels),
            'Total Beds'     => $total_beds_all,
            'Occupied'       => $total_occupied,
            'Overall Occ.'   => $overall_occupancy . '%',
        ]
    );
    ?>

    <!-- Page Header -->
    <div class="dashboard-header admin-section mb-4 d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">
                    <i class="fas fa-building me-2"></i>Hostels Capacity Report
                </h1>
                <p class="text-muted mb-0">
                    Overview of all hostels &mdash; bed capacity and current occupancy
                </p>
            </div>
            <div class="col-auto">
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-success" onclick="exportToExcel()">
                        <i class="fas fa-file-excel me-1"></i>Export
                    </button>
                    <button class="btn btn-sm btn-secondary" onclick="printReport('Hostels Capacity Report')">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                    <a href="hostels.php" class="btn btn-outline-primary d-flex align-items-center gap-2">
                        <i class="fas fa-arrow-left"></i> Back to Hostels
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($query_error): ?>
    <div class="alert alert-warning d-flex align-items-center" role="alert">
        <i class="fas fa-exclamation-triangle me-2 flex-shrink-0"></i>
        <div><?php echo htmlspecialchars(strip_tags($query_error), ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <?php endif; ?>

    <!-- Summary Statistics Cards -->
    <div class="row g-4 mb-4">

        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary rounded-circle p-3 me-3">
                        <i class="fas fa-building fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format(count($hostels)); ?></h3>
                        <p class="text-muted mb-0">Total Hostels</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info rounded-circle p-3 me-3">
                        <i class="fas fa-bed fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($total_beds_all); ?></h3>
                        <p class="text-muted mb-0">Total Beds</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning rounded-circle p-3 me-3">
                        <i class="fas fa-user-check fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($total_occupied); ?></h3>
                        <p class="text-muted mb-0">
                            Occupied Beds
                            <small class="d-block"><?php echo $overall_occupancy; ?>% occupancy</small>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100 rounded-3 bg-white p-4 shadow-sm">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success rounded-circle p-3 me-3">
                        <i class="fas fa-door-open fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($total_available); ?></h3>
                        <p class="text-muted mb-0">
                            Available Beds
                            <small class="d-block"><?php echo $full_count; ?> hostel(s) full</small>
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Hostels Table Card -->
    <div class="data-table-card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Hostel Occupancy Details
                </h5>
                <span class="text-muted small">
                    Report generated: <?php echo date('d M Y, H:i'); ?>
                </span>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($hostels) && !$query_error): ?>
                <div class="text-center py-5">
                    <i class="fas fa-bed fa-3x text-muted mb-3 d-block"></i>
                    <p class="text-muted">No hostel records found in the database.</p>
                </div>
            <?php elseif (!empty($hostels)): ?>
            <div class="table-responsive">
                <table id="hostelsTable" class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Hostel Name</th>
                            <th class="text-center">Total Beds</th>
                            <th class="text-center">Occupied Beds</th>
                            <th class="text-center">Available Beds</th>
                            <th>Occupancy</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $counter = 1;
                        foreach ($hostels as $hostel):
                            if ($hostel['occupancy_pct'] >= 90) {
                                $bar_class = 'bg-danger';
                            } elseif ($hostel['occupancy_pct'] >= 60) {
                                $bar_class = 'bg-warning';
                            } else {
                                $bar_class = 'bg-success';
                            }
                        ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle me-2 bg-primary text-white">
                                        <i class="fas fa-building"></i>
                                    </div>
                                    <strong><?php echo htmlspecialchars($hostel['hostel_name']); ?></strong>
                                </div>
                            </td>
                            <td class="text-center">
                                <?php echo number_format($hostel['total_beds']); ?>
                            </td>
                            <td class="text-center">
                                <?php echo number_format($hostel['occupied_beds']); ?>
                            </td>
                            <td class="text-center">
                                <?php echo number_format($hostel['available_beds']); ?>
                            </td>
                            <td style="min-width: 140px;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 8px;">
                                        <div class="progress-bar <?php echo $bar_class; ?>"
                                             role="progressbar"
                                             style="width: <?php echo $hostel['occupancy_pct']; ?>%"
                                             aria-valuenow="<?php echo $hostel['occupancy_pct']; ?>"
                                             aria-valuemin="0"
                                             aria-valuemax="100">
                                        </div>
                                    </div>
                                    <small class="text-muted" style="width: 36px; text-align: right;">
                                        <?php echo $hostel['occupancy_pct']; ?>%
                                    </small>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo htmlspecialchars($hostel['status_class']); ?> px-3 py-2">
                                    <?php echo htmlspecialchars($hostel['status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="2">Overall Totals</td>
                            <td class="text-center"><?php echo number_format($total_beds_all); ?></td>
                            <td class="text-center"><?php echo number_format($total_occupied); ?></td>
                            <td class="text-center"><?php echo number_format($total_available); ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 8px;">
                                        <?php
                                        if ($overall_occupancy >= 90) $ob = 'bg-danger';
                                        elseif ($overall_occupancy >= 60) $ob = 'bg-warning';
                                        else $ob = 'bg-success';
                                        ?>
                                        <div class="progress-bar <?php echo $ob; ?>"
                                             role="progressbar"
                                             style="width: <?php echo $overall_occupancy; ?>%">
                                        </div>
                                    </div>
                                    <small class="text-muted" style="width: 36px; text-align: right;">
                                        <?php echo $overall_occupancy; ?>%
                                    </small>
                                </div>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div><!-- /.data-table-card -->

</div><!-- /.container-fluid -->

<script>
$(document).ready(function () {
    if ($('#hostelsTable tbody tr').length > 0) {
        $('#hostelsTable').DataTable({
            pageLength: 25,
            responsive: true,
            order: [[1, 'asc']],
            columnDefs: [
                { orderable: false, targets: [5] }
            ],
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
            language: {
                search: "",
                searchPlaceholder: "Search hostels...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ hostels",
                paginate: {
                    first:    '<i class="fas fa-angle-double-left"></i>',
                    last:     '<i class="fas fa-angle-double-right"></i>',
                    next:     '<i class="fas fa-angle-right"></i>',
                    previous: '<i class="fas fa-angle-left"></i>'
                }
            }
        });
    }
});

function exportToExcel() {
    var table = document.querySelector('#hostelsTable');
    if (!table) { alert('No data to export.'); return; }
    var html = table.outerHTML;
    var url  = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    var link = document.createElement('a');
    document.body.appendChild(link);
    link.href     = url;
    link.download = 'hostels_capacity_report.xls';
    link.click();
    document.body.removeChild(link);
}
</script>

<?php require_once "includes/footer.php"; ?>
