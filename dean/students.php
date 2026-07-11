<?php
$page_title = 'Student Records';
require_once __DIR__ . '/includes/guard.php';
require "includes/nav.php";
error_reporting(0);

// Initialize records array
$records = array();

// Fetch student data (program_levels table does not exist in the live schema —
// students.year already carries the year of study used as the level)
$sql = "SELECT students.*, student_program.*, programs.*, students.year AS level
        FROM students
        INNER JOIN student_program ON students.SID = student_program.Sid
        INNER JOIN programs ON student_program.program_code = programs.program_code";
if ($results = $db->query($sql)) {
    if ($results->num_rows > 0) {
        while ($row = $results->fetch_object()) {
            $records[] = $row;
        }
        $results->free();
    }
}
$number = 1;
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header dean-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Student Records</h1>
                <p class="text-muted mb-0">View and manage student information</p>
            </div>
            <div class="col-auto">
                <div class="header-actions d-flex gap-2">
                    <button class="btn btn-outline-secondary" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                    <button class="btn btn-outline-primary" id="exportBtn">
                        <i class="fas fa-file-export me-2"></i>Export
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filters Card -->
    <div class="data-table-card mb-4 d-print-none">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-filter me-2"></i>Filters
                </h5>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="programFilter" class="form-label">Program</label>
                    <select class="form-select" id="programFilter">
                        <option value="">All Programs</option>
                        <!-- Program options would be populated via JavaScript -->
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="levelFilter" class="form-label">Level</label>
                    <select class="form-select" id="levelFilter">
                        <option value="">All Levels</option>
                        <!-- Level options would be populated via JavaScript -->
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="intakeFilter" class="form-label">Intake</label>
                    <select class="form-select" id="intakeFilter">
                        <option value="">All Intakes</option>
                        <!-- Intake options would be populated via JavaScript -->
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="modeFilter" class="form-label">Study Mode</label>
                    <select class="form-select" id="modeFilter">
                        <option value="">All Modes</option>
                        <!-- Mode options would be populated via JavaScript -->
                    </select>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Students Table Card -->
    <div class="data-table-card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-users me-2"></i>Student List
                </h5>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($records)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No student records found.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table id="studentsTable" class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th width="5%">#</th>
                                <th width="12%">Student ID</th>
                                <th width="18%">Full Name</th>
                                <th width="7%">Gender</th>
                                <th width="20%">Program</th>
                                <th width="8%">Level</th>
                                <th width="10%">Intake</th>
                                <th width="10%">Mode</th>
                                <th width="10%">Actions</th>
						                          </tr>
						                        </thead>
						                        <tbody>
                            <?php foreach($records as $r): ?>
                                <tr>
                                    <td><?php echo $number++; ?></td>
                                    <td><?php echo htmlspecialchars($r->SID); ?></td>
                                    <td><?php echo htmlspecialchars($r->Fname . ' ' . $r->Lname); ?></td>
                                    <td><?php echo htmlspecialchars($r->sex ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($r->program_name); ?></td>
                                    <td><?php echo htmlspecialchars($r->level); ?></td>
                                    <td><?php echo htmlspecialchars($r->intake ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($r->mode ?? ''); ?></td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <a href="adminSlip.php?view=<?php echo $r->SID?>" 
                                                class="btn btn-sm btn-primary" data-bs-toggle="tooltip" 
                                                data-bs-placement="top" title="Print Admin Slip">
                                                <i class="fas fa-print"></i>
                                            </a>
                                            <a href="#" class="btn btn-sm btn-outline-primary view-details"
                                                data-id="<?php echo $r->SID?>" data-bs-toggle="tooltip"
                                                data-bs-placement="top" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
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

<!-- Student Details Modal -->
<div class="modal fade" id="studentDetailsModal" tabindex="-1" aria-labelledby="studentDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="studentDetailsModalLabel">Student Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="studentDetailsContent">
                <!-- Details will be loaded here via AJAX -->
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" id="adminSlipLink" class="btn btn-primary">
                    <i class="fas fa-print me-2"></i>Print Admin Slip
                </a>
					</div>
				</div>
			</div>
			</div>

<!-- DataTables Initialization and Custom Scripts -->
<script>
$(document).ready(function() {
    // Initialize DataTable
    var table = $('#studentsTable').DataTable({
        responsive: true,
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search students...",
            zeroRecords: "No matching students found",
            info: "Showing _START_ to _END_ of _TOTAL_ students",
            lengthMenu: "Show _MENU_ students per page"
        },
        dom: '<"top"lf>rt<"bottom"ip><"clear">',
        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
        pageLength: 10,
        columnDefs: [
            {orderable: false, targets: [8]}, // Disable sorting on actions column
            {searchable: false, targets: [0, 8]} // Disable search on serial number and actions columns
        ]
    });

    // Initialize tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();
    
    // Populate filter dropdowns
    var programs = [], levels = [], intakes = [], modes = [];
    
    table.column(4).data().unique().sort().each(function(value) {
        programs.push(value.trim());
    });
    
    table.column(5).data().unique().sort().each(function(value) {
        levels.push(value.trim());
    });
    
    table.column(6).data().unique().sort().each(function(value) {
        intakes.push(value.trim());
    });
    
    table.column(7).data().unique().sort().each(function(value) {
        modes.push(value.trim());
    });
    
    // Add options to dropdowns
    programs.forEach(function(program) {
        $('#programFilter').append('<option value="' + program + '">' + program + '</option>');
    });
    
    levels.forEach(function(level) {
        $('#levelFilter').append('<option value="' + level + '">' + level + '</option>');
    });
    
    intakes.forEach(function(intake) {
        $('#intakeFilter').append('<option value="' + intake + '">' + intake + '</option>');
    });
    
    modes.forEach(function(mode) {
        $('#modeFilter').append('<option value="' + mode + '">' + mode + '</option>');
    });
    
    // Apply filters
    $('#programFilter, #levelFilter, #intakeFilter, #modeFilter').on('change', function() {
        var programVal = $('#programFilter').val();
        var levelVal = $('#levelFilter').val();
        var intakeVal = $('#intakeFilter').val();
        var modeVal = $('#modeFilter').val();
        
        // Clear all filters first
        table.columns().search('').draw();
        
        // Apply each filter if not empty
        if (programVal) {
            table.column(4).search(programVal).draw();
        }
        
        if (levelVal) {
            table.column(5).search(levelVal).draw();
        }
        
        if (intakeVal) {
            table.column(6).search(intakeVal).draw();
        }
        
        if (modeVal) {
            table.column(7).search(modeVal).draw();
        }
    });
    
    // Handle view details button click
    $('.view-details').on('click', function(e) {
        e.preventDefault();
        var studentId = $(this).data('id');
        $('#adminSlipLink').attr('href', 'adminSlip.php?view=' + studentId);
        
        // In a real implementation, you would load student details via AJAX
        // For now, we'll show a placeholder
        
        var mockData = {
            name: $(this).closest('tr').find('td:eq(2)').text(),
            id: studentId,
            program: $(this).closest('tr').find('td:eq(4)').text(),
            level: $(this).closest('tr').find('td:eq(5)').text()
        };
        
        // Display student details
        var detailsHtml = `
            <div class="row">
                <div class="col-md-4 text-center mb-3">
                    <div class="avatar-circle mx-auto" style="width: 120px; height: 120px;">
                        <img src="/wucportal/images/avatar.png" alt="Student" class="img-fluid rounded-circle">
                    </div>
                </div>
                <div class="col-md-8">
                    <h4>${mockData.name}</h4>
                    <p class="text-muted">Student ID: ${mockData.id}</p>
                    <p class="mb-1"><strong>Program:</strong> ${mockData.program}</p>
                    <p><strong>Level:</strong> ${mockData.level}</p>
		</div>
	</div>
            <hr>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>Student details would be loaded here from the database.
            </div>
        `;
        
        $('#studentDetailsContent').html(detailsHtml);
        var modal = new bootstrap.Modal(document.getElementById('studentDetailsModal'));
        modal.show();
    });
    
    // Export button
    $('#exportBtn').on('click', function() {
        // In a real implementation, this would trigger an export process
        // For now, we'll just show a message
        Swal.fire({
            title: 'Export Student Data',
            text: 'This would export the current filtered student list to Excel/CSV.',
            icon: 'info',
            confirmButtonText: 'OK'
        });
    });
});
</script>
<?php require "includes/footer.php"; ?>

