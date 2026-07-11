<?php

// Enable strict error reporting for MySQLi
$page_title = "Fee Structure Management";

require_once "includes/admin.php";
require_once "../includes/common_header.php";

require_once "includes/header.php";
// Link the admin dashboard stylesheet
// Error handling configuration
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Show errors on screen

// Get current academic period
$current_period = null;
if($result = $db->query("SELECT * FROM academic_periods WHERE is_current = TRUE LIMIT 1")) {
    $current_period = $result->fetch_object();
    $result->free();
}

// Get all academic periods for filter
$academic_periods = [];
if($result = $db->query("SELECT * FROM academic_periods ORDER BY academic_year DESC, period_number DESC")) {
    while($row = $result->fetch_object()) {
        $academic_periods[] = $row;
    }
    $result->free();
}

// Get all programs
$programs = [];
if($result = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_code")) {
    while($row = $result->fetch_object()) {
        $programs[] = $row;
    }
    $result->free();
}

// Get all courses
$courses = [];
if($result = $db->query("SELECT * FROM courses WHERE status = 'active' ORDER BY course_code")) {
    while($row = $result->fetch_object()) {
        $courses[] = $row;
    }
    $result->free();
}

// Get total fee structures count
$total_fees = 0;
if($result = $db->query("SELECT COUNT(*) as count FROM fee_structure")) {
    $total_fees = $result->fetch_object()->count;
    $result->free();
}

// Get total programs with fees
$total_programs = 0;
if($result = $db->query("SELECT COUNT(DISTINCT program_code) as count FROM fee_structure")) {
    $total_programs = $result->fetch_object()->count;
    $result->free();
}

// Get total active fee structures
$active_fees = 0;
if($result = $db->query("SELECT COUNT(*) as count FROM fee_structure WHERE status = 'active'")) {
    $active_fees = $result->fetch_object()->count;
    $result->free();
}

// Get total inactive fee structures
$inactive_fees = 0;
if($result = $db->query("SELECT COUNT(*) as count FROM fee_structure WHERE status = 'inactive'")) {
    $inactive_fees = $result->fetch_object()->count;
    $result->free();
}

// Get total revenue
$total_revenue = 0;
if($result = $db->query("SELECT SUM(amount) as total FROM fee_structure")) {
    $total_revenue = $result->fetch_object()->total;
    $result->free();
}
?>

<style>
/* Row Group Styling for Fee Structure Table */
#feeStructureTable tr.group-header td {
    background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%) !important;
    color: white !important;
    font-weight: 600;
    font-size: 0.95rem;
    padding: 10px 15px !important;
    box-shadow: 0 2px 4px rgba(67, 97, 238, 0.2);
}

#feeStructureTable tr.group-header td .badge {
    font-weight: 500;
    padding: 5px 10px;
    background-color: rgba(255, 255, 255, 0.2) !important;
    color: white !important;
}

/* Subtle alternating background for grouped rows */
#feeStructureTable tbody tr:not(.group-header):hover {
    background-color: rgba(67, 97, 238, 0.05);
}

/* Make the table more compact and clean */
#feeStructureTable tbody td {
    padding: 10px 12px;
    vertical-align: middle;
}

/* Enhance the amount column */
#feeStructureTable .amount {
    font-weight: 600;
    color: #2d3436;
}

/* Year and Semester badges */
#feeStructureTable tbody td:nth-child(2),
#feeStructureTable tbody td:nth-child(3) {
    font-weight: 500;
    color: #6c5ce7;
}
</style>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header finance-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Fee Structure Management</h1>
                <p class="text-muted">Manage course fees and fee structures</p>
            </div>
            <div class="col-auto">
            <div class="d-flex gap-2">
                <?php if (!empty($_SESSION['flash_message'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['flash_type']==='error'?'danger':'success'; ?> py-2 px-3 mb-0">
                        <?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
                    </div>
                <?php endif; ?>
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#filterModal">
                        <i class="fas fa-filter me-2"></i>Filter
                        <span class="badge bg-primary" id="activeFilterCount" style="display: none;">0</span>
                    </button>
                    <a href="add_fee_structure.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Add New Fee
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <!-- Total Fees Card -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary">
                        <i class="fas fa-money-bill fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($total_fees); ?></h3>
                        <p class="text-muted mb-0">Total Fee Structures</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Programs Card -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning">
                        <i class="fas fa-graduation-cap fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($total_programs); ?></h3>
                        <p class="text-muted mb-0">Programs with Fees</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Fees Card -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success">
                        <i class="fas fa-check-circle fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($active_fees); ?></h3>
                        <p class="text-muted mb-0">Active Fee Structures</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inactive Fees Card -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-danger">
                        <i class="fas fa-times-circle fa-2x text-white"></i>
                    </div>
                    <div>
                        <h3 class="mb-1"><?php echo number_format($inactive_fees); ?></h3>
                        <p class="text-muted mb-0">Inactive Fee Structures</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Fee Structure Table -->
    <div class="data-table-card">
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
                <table id="feeStructureTable" class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th style="display:none;">Program</th>
                            <th>Year</th>
                            <th>Semester</th>
                            <th>Description</th>
                            <th class="text-end">Amount (ZMK)</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Filter Modal -->
<div class="modal fade" id="filterModal" tabindex="-1" aria-labelledby="filterModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header finance-modal">
                <h5 class="modal-title" id="filterModalLabel">
                    <i class="fas fa-filter me-2"></i>Filter Fee Structures
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="filterForm">
                    <div class="mb-3">
                        <label for="filterProgram" class="form-label">Program</label>
                        <select class="form-select" id="filterProgram">
                            <option value="">All Programs</option>
                            <?php foreach($programs as $program): ?>
                                <option value="<?php echo $program->program_code; ?>">
                                    <?php echo $program->program_code; ?> - <?php echo $program->program_name; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="filterYear" class="form-label">Year of Study</label>
                        <select class="form-select" id="filterYear">
                            <option value="">All Years</option>
                            <option value="1">Year 1</option>
                            <option value="2">Year 2</option>
                            <option value="3">Year 3</option>
                            <option value="4">Year 4</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="filterStatus" class="form-label">Status</label>
                        <select class="form-select" id="filterStatus">
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </form>
                <div class="active-filters" id="activeFilters"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="clearFilters">
                    <i class="fas fa-times me-2"></i>Clear Filters
                </button>
                <button type="button" class="btn btn-primary" id="applyFilter">
                    <i class="fas fa-check me-2"></i>Apply Filters
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    console.log('Document ready');
    
    // Initialize DataTable with enhanced options
    var feeTable = $('#feeStructureTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: 'ajax/get_fee_structure.php',
            method: 'POST',
            data: function(d) {
                d.filterProgram = $('#filterProgram').val();
                d.filterYear = $('#filterYear').val();
                d.filterStatus = $('#filterStatus').val();
            },
            error: function(xhr, error, thrown) {
                console.error('DataTable error:', error, thrown);
                console.error('Response:', xhr.responseText);
                Swal.fire({
                    icon: 'error',
                    title: 'Error Loading Data',
                    text: 'There was an error loading the fee structure data. Please try again later.'
                });
            }
        },
        columns: [
            { 
                data: null,
                render: function(data, type, row, meta) {
                    return meta.row + meta.settings._iDisplayStart + 1;
                }
            },
            { 
                data: 'program_name',
                visible: false, // Hidden but used for grouping
                render: function(data, type, row) {
                    return data || 'N/A';
                }
            },
            { 
                data: 'year_of_study',
                render: function(data) {
                    return `Year ${data}`;
                }
            },
            { 
                data: 'semester',
                render: function(data, type, row) {
                    const label = (row && row.period_type && row.period_type.toLowerCase() === 'term') ? 'Term' : 'Semester';
                    return `${label} ${data}`;
                }
            },
            
            { 
                data: 'fee_description',
                render: function(data) {
                    return `<span title="${data}">${data}</span>`;
                }
            },
            { 
                data: 'amount',
                className: 'text-end amount',
                render: function(data) {
                    return `ZMK ${parseFloat(data).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                }
            },
            { 
                data: 'status',
                render: function(data) {
                    let statusClass = 'bg-secondary';
                    if (data.toLowerCase() === 'active') {
                        statusClass = 'bg-success';
                    } else if (data.toLowerCase() === 'inactive') {
                        statusClass = 'bg-danger';
                    }
                    return `<span class="badge ${statusClass}">${data}</span>`;
                }
            },
            {
                data: null,
                orderable: false,
                searchable: false,
                className: 'text-center action-buttons',
                render: function(data) {
                    return `
                        <div class="d-flex justify-content-center gap-1">
                            <a href="edit_fee_structure.php?id=${data.id}" 
                               class="btn btn-sm btn-outline-primary" 
                               title="Edit Fee Structure">
                                <i class="fas fa-edit"></i>
                            </a>
                            <button type="button" 
                                    class="btn btn-sm btn-outline-danger delete-fee" 
                                    data-id="${data.id}"
                                    title="Delete Fee Structure">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    `;
                }
            }
        ],
        // Row Grouping by Program
        rowGroup: {
            dataSrc: 'program_name',
            startRender: function(rows, group) {
                return $('<tr class="group-header"/>')
                    .append('<td colspan="7">' +
                        '<i class="fas fa-graduation-cap me-2"></i>' + 
                        (group || 'Unassigned Program') + 
                        '<span class="badge ms-2">' + rows.count() + '</span>' +
                    '</td>');
            }
        },
        order: [[1, 'asc'], [2, 'asc'], [3, 'asc']], // Order by program, year, semester
        pageLength: 25, // Show more entries for grouped view
        responsive: true,
        language: {
            processing: '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>',
            emptyTable: '<div class="text-center py-4"><i class="fas fa-info-circle fa-2x mb-3"></i><br>No fee structures found</div>',
            info: 'Showing _START_ to _END_ of _TOTAL_ entries',
            infoEmpty: 'Showing 0 to 0 of 0 entries',
            infoFiltered: '(filtered from _MAX_ total entries)',
            lengthMenu: 'Show _MENU_ entries',
            loadingRecords: '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>',
            search: '<i class="fas fa-search"></i>',
            zeroRecords: '<div class="text-center py-4"><i class="fas fa-info-circle fa-2x mb-3"></i><br>No matching records found</div>'
        },
        initComplete: function() {
            // Initialize tooltips
            $('[title]').tooltip();
            
            // Add custom search input
            $('.dataTables_filter input').attr('placeholder', 'Search fee structures...');
        }
    });

    // Filter functionality
    let activeFilters = {};
    
    function updateActiveFilters() {
        const activeFiltersContainer = $('#activeFilters');
        activeFiltersContainer.empty();
        let filterCount = 0;
        
        Object.entries(activeFilters).forEach(([key, value]) => {
            if (value) {
                filterCount++;
                const filterName = key.replace('filter', '').toLowerCase();
                activeFiltersContainer.append(`
                    <span class="badge bg-primary mb-2 me-2 p-2">
                        ${filterName}: ${value}
                        <span class="remove-filter" data-filter="${key}" style="cursor: pointer;">
                            <i class="fas fa-times ms-1"></i>
                        </span>
                    </span>
                `);
            }
        });
        
        const badge = $('#activeFilterCount');
        if (filterCount > 0) {
            badge.text(filterCount).show();
        } else {
            badge.hide();
        }
    }
    
    function applyFilters() {
        const params = {};
        Object.entries(activeFilters).forEach(([key, value]) => {
            if (value) {
                params[key] = value;
            }
        });
        
        feeTable.ajax.reload(null, false);
    }
    
    // Handle filter changes
    $('#filterProgram, #filterYear, #filterStatus').change(function() {
        const id = $(this).attr('id');
        const value = $(this).val();
        activeFilters[id] = value;
        updateActiveFilters();
    });
    
    // Handle remove filter
    $(document).on('click', '.remove-filter', function() {
        const filterId = $(this).data('filter');
        $(`#${filterId}`).val('');
        activeFilters[filterId] = '';
        updateActiveFilters();
        applyFilters();
    });
    
    // Handle clear filters
    $('#clearFilters').click(function() {
        $('#filterForm select').val('');
        activeFilters = {};
        updateActiveFilters();
        applyFilters();
    });
    
    // Handle apply filters
    $('#applyFilter').click(function() {
        applyFilters();
        $('#filterModal').modal('hide');
    });
    
    // Initialize filters
    updateActiveFilters();

    // Handle delete button click
    $(document).on('click', '.delete-fee', function() {
        const feeId = $(this).data('id');
        
        if (!feeId) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Invalid fee structure ID'
            });
            return;
        }

        Swal.fire({
            title: 'Are you sure?',
            text: 'This fee structure will be marked as inactive.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'ajax/delete_fee_structure.php',
                    type: 'POST',
                    data: { id: feeId },
                    dataType: 'json',
                    success: function(response) {
                        if (response && response.success) {
                            feeTable.ajax.reload();
                            Swal.fire({
                                icon: 'success',
                                title: 'Deleted!',
                                text: response.message || 'Fee structure deleted successfully'
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: (response && response.message) ? response.message : 'Failed to delete fee structure'
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Delete error:', error, xhr.responseText);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred while deleting the fee structure.'
                        });
                    }
                });
            }
        });
    });

    // Search functionality
    $('#searchInput').on('keyup', function() {
        feeTable.search(this.value).draw();
    });

    // Export to Excel
    window.exportToExcel = function() {
        let table = document.querySelector('#feeStructureTable');
        let html = table.outerHTML;
        let url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
        let downloadLink = document.createElement("a");
        document.body.appendChild(downloadLink);
        downloadLink.href = url;
        downloadLink.download = 'fee_structure.xls';
        downloadLink.click();
        document.body.removeChild(downloadLink);
    };
});
</script>

<?php require_once "includes/footer.php"; ?> 
