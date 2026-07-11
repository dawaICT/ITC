<?php
$page_title = 'Approved Assessments';
require "includes/nav.php";
require_once __DIR__ . '/includes/hod_schema_helpers.php';
error_reporting(0);

// Initialize records array
$records = array();

// Get department ID of current HOD
$hodStaffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
$deptContext = hod_resolve_department($db, $hodStaffId);
$departmentId = (string)$deptContext['id'];
$nonAcademicHosSection = !empty($deptContext['section_type']) && (string)$deptContext['section_type'] !== 'academic';
// Scope to every department in the section, not just the first.
$hodCourseCodes = hod_section_course_codes($db, $deptContext, $hodStaffId);

// Fetch approved assessment data
$approvedTable = hod_table_exists($db, 'approved_assessments') ? 'approved_assessments' : (hod_table_exists($db, 'approved_ca') ? 'approved_ca' : null);
if ($approvedTable) {
    if (!empty($hodCourseCodes)) {
        $placeholders = implode(',', array_fill(0, count($hodCourseCodes), '?'));
        $stmt = @$db->prepare("SELECT * FROM `{$approvedTable}` WHERE Course_Code IN ({$placeholders}) ORDER BY `Year` DESC, semester DESC, Course_Code ASC, Sid ASC");
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($hodCourseCodes)), ...$hodCourseCodes);
            $stmt->execute();
            $results = $stmt->get_result();
            while ($results && $row = $results->fetch_object()) {
                $records[] = $row;
            }
            $stmt->close();
        }
    }
    // No course scope: leave the list empty rather than exposing every
    // section's approved assessments.
}
$number = 1;

// Calculate statistics
$totalAssessments = count($records);
$averageScore = 0;
$passingCount = 0;
$failingCount = 0;

if ($totalAssessments > 0) {
    $totalScores = 0;
    foreach ($records as $record) {
        $totalScores += $record->Total_CA;
        if ($record->Total_CA >= 16) { // Assuming 16/40 is passing
            $passingCount++;
        } else {
            $failingCount++;
        }
    }
    $averageScore = round($totalScores / $totalAssessments, 1);
}

$passingPercentage = $totalAssessments > 0 ? round(($passingCount / $totalAssessments) * 100) : 0;
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Approved Assessments</h1>
                <p class="text-muted">View and manage approved continuous assessments</p>
            </div>
            <div class="col-auto">
                <div class="header-actions d-flex gap-2">
                    <a href="CAmanager.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to CA Manager
                    </a>
                    <button class="btn btn-outline-primary" id="printBtn">
                        <i class="fas fa-print me-2"></i>Print Report
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center mb-3">
                    <div class="stat-icon bg-success rounded-circle p-3 me-3">
                        <i class="fas fa-check-circle fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="stat-value mb-0"><?php echo number_format($totalAssessments); ?></h3>
                        <p class="stat-label mb-0">Total Approved</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center mb-3">
                    <div class="stat-icon bg-info rounded-circle p-3 me-3">
                        <i class="fas fa-calculator fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="stat-value mb-0"><?php echo $averageScore; ?>/40</h3>
                        <p class="stat-label mb-0">Average Score</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center mb-3">
                    <div class="stat-icon bg-warning rounded-circle p-3 me-3">
                        <i class="fas fa-percentage fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="stat-value mb-0"><?php echo $passingPercentage; ?>%</h3>
                        <p class="stat-label mb-0">Pass Rate</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary rounded-circle p-3 me-3">
                        <i class="fas fa-chart-pie fa-2x text-white"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-success">Pass: <?php echo $passingCount; ?></span>
                            <span class="text-danger">Fail: <?php echo $failingCount; ?></span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar bg-success" role="progressbar" 
                                style="width: <?php echo $passingPercentage; ?>%" 
                                aria-valuenow="<?php echo $passingPercentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filters Card -->
    <div class="data-table-card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="courseFilter" class="form-label">Course</label>
                    <select class="form-select" id="courseFilter">
                        <option value="">All Courses</option>
                        <!-- Course options would be populated via JavaScript -->
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="yearFilter" class="form-label">Academic Year</label>
                    <select class="form-select" id="yearFilter">
                        <option value="">All Years</option>
                        <!-- Year options would be populated via JavaScript -->
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="scoreRangeFilter" class="form-label">Score Range</label>
                    <select class="form-select" id="scoreRangeFilter">
                        <option value="">All Scores</option>
                        <option value="excellent">Excellent (32-40)</option>
                        <option value="good">Good (24-31)</option>
                        <option value="satisfactory">Satisfactory (16-23)</option>
                        <option value="poor">Below Standard (0-15)</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Assessment Table Card -->
    <div class="data-table-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-clipboard-check me-2"></i>Approved Assessments
                </h5>
            </div>
        </div>
        <div class="card-body" id="printableArea">
            <?php if (empty($records)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <?php if ($nonAcademicHosSection): ?>
                        <?php echo htmlspecialchars((string)($_SESSION['hos_section_name'] ?? 'This section')); ?> is not linked to academic approved assessments.
                    <?php else: ?>
                        No approved continuous assessments found.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table id="assessmentTable" class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th width="5%">#</th>
                                <th width="12%">Student ID</th>
                                <th width="15%">Course Code</th>
                                <th width="8%">A1</th>
                                <th width="8%">A2</th>
                                <th width="8%">T1</th>
                                <th width="8%">T2</th>
                                <th width="12%">Total CA</th>
                                <th width="12%">Year</th>
                                <th width="12%">Status</th>
						                          </tr>
						                        </thead>
						                        <tbody>
                            <?php foreach($records as $r): ?>
                                <tr>
                                    <td><?php echo $number++; ?></td>
                                    <td><?php echo htmlspecialchars($r->Sid); ?></td>
                                    <td><?php echo htmlspecialchars($r->Course_Code); ?></td>
                                    <td><?php echo htmlspecialchars($r->A1); ?></td>
                                    <td><?php echo htmlspecialchars($r->A2); ?></td>
                                    <td><?php echo htmlspecialchars($r->T1); ?></td>
                                    <td><?php echo htmlspecialchars($r->T2); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $r->Total_CA < 16 ? 'danger' : ($r->Total_CA < 24 ? 'warning' : 'success'); ?> rounded-pill">
                                            <?php echo htmlspecialchars($r->Total_CA); ?>/40
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($r->Year); ?></td>
                                    <td>
						                          <?php
                                            $status = '';
                                            $badgeClass = '';
                                            $totalCA = (int)$r->Total_CA;
                                            
                                            if ($totalCA >= 32) {
                                                $status = 'Excellent';
                                                $badgeClass = 'success';
                                            } elseif ($totalCA >= 24) {
                                                $status = 'Good';
                                                $badgeClass = 'primary';
                                            } elseif ($totalCA >= 16) {
                                                $status = 'Satisfactory';
                                                $badgeClass = 'warning';
                                            } else {
                                                $status = 'Below Standard';
                                                $badgeClass = 'danger';
                                            }
                                        ?>
                                        <span class="badge bg-<?php echo $badgeClass; ?>">
                                            <?php echo $status; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
						                        </tbody>
						                      </table>
						    </div>
            <?php endif; ?>
				</div>
			</div>
			</div>

<!-- Print Script -->
<script>
$(document).ready(function() {
    // Initialize DataTable
    var table = $('#assessmentTable').DataTable({
        responsive: true,
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search assessments...",
            zeroRecords: "No matching assessments found",
            info: "Showing _START_ to _END_ of _TOTAL_ assessments",
            lengthMenu: "Show _MENU_ assessments per page"
        },
        dom: '<"top"lf>rt<"bottom"ip><"clear">',
        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
        pageLength: 10
    });

    // Initialize tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();
    
    // Populate filter dropdowns
    var courses = [], years = [];
    
    table.column(2).data().unique().sort().each(function(value) {
        courses.push(value.trim());
    });
    
    table.column(8).data().unique().sort().each(function(value) {
        years.push(value.trim());
    });
    
    // Add options to dropdowns
    courses.forEach(function(course) {
        $('#courseFilter').append('<option value="' + course + '">' + course + '</option>');
    });
    
    years.forEach(function(year) {
        $('#yearFilter').append('<option value="' + year + '">' + year + '</option>');
    });
    
    // Apply filters
    $('#courseFilter, #yearFilter').on('change', function() {
        var courseVal = $('#courseFilter').val();
        var yearVal = $('#yearFilter').val();
        
        // Clear filters first
        table.columns().search('').draw();
        
        // Apply each filter if not empty
        if (courseVal) {
            table.column(2).search(courseVal).draw();
        }
        
        if (yearVal) {
            table.column(8).search(yearVal).draw();
        }
    });
    
    // Handle score range filter
    $('#scoreRangeFilter').on('change', function() {
        var value = $(this).val();
        
        $.fn.dataTable.ext.search.pop(); // Remove previous filter
        
        if (value) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                var totalScore = parseInt(data[7].replace(/[^\d]/g, '')) || 0;
                
                switch (value) {
                    case 'excellent':
                        return totalScore >= 32 && totalScore <= 40;
                    case 'good':
                        return totalScore >= 24 && totalScore < 32;
                    case 'satisfactory':
                        return totalScore >= 16 && totalScore < 24;
                    case 'poor':
                        return totalScore < 16;
                    default:
                        return true;
                }
            });
        }
        
        table.draw();
    });
    
    // Print button functionality
    $('#printBtn').on('click', function() {
        var printableArea = document.getElementById('printableArea');
        if (!printableArea) return;
        
        // Add header to print version
        var printHeader = `
            <div class="text-center mb-4">
                <img src="/wucportal/images/LOGO2.jpeg" alt="Logo" style="height: 80px;">
                <h2 class="mt-3">Approved Continuous Assessment Report</h2>
                <p>Department: <?php echo htmlspecialchars($departmentId); ?> - Generated: ${new Date().toLocaleDateString()}</p>
		</div>
        `;
        
        var temp = document.createElement('div');
        temp.innerHTML = printHeader + printableArea.innerHTML;
        if (window.wucPrintElement) {
            window.wucPrintElement(temp, 'Approved Continuous Assessment Report');
            return;
        }

        var printWindow = window.open('', '_blank', 'width=900,height=700');
        if (!printWindow) {
            window.print();
            return;
        }
        var styles = Array.prototype.map.call(
            document.querySelectorAll('link[rel="stylesheet"], style'),
            function (node) { return node.outerHTML; }
        ).join('\n');
        printWindow.document.open();
        printWindow.document.write('<!DOCTYPE html><html><head><title>Approved Continuous Assessment Report</title>' + styles + '<style>@media print{button,.d-print-none,.no-print{display:none!important}}body{background:#fff}</style></head><body>' + printHeader + printableArea.innerHTML + '<script>window.onload=function(){window.print();window.onafterprint=function(){window.close();};};<\/script></body></html>');
        printWindow.document.close();
    });
});
</script>

