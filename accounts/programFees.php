<?php
require "includes/nav.php";

// Get all programs for filter dropdown
$programs = [];
if ($result = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_code")) {
    while ($row = $result->fetch_object()) {
        $programs[] = $row;
    }
    $result->free();
}

// Get short courses for shared filter dropdown
$shortCourses = [];
if ($result = $db->query("SELECT id, course_code, course_name FROM short_courses ORDER BY course_code")) {
    while ($row = $result->fetch_object()) {
        $shortCourses[] = $row;
    }
    $result->free();
}

// Get fee statistics in a single query
$stats = ['total' => 0, 'programs' => 0, 'active' => 0, 'inactive' => 0];
$statsQuery = "SELECT 
    COUNT(*) as total,
    COUNT(DISTINCT program_code) as programs,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive
    FROM fee_structure";
if ($result = $db->query($statsQuery)) {
    $row = $result->fetch_object();
    $stats = [
        'total'    => (int)$row->total,
        'programs' => (int)$row->programs,
        'active'   => (int)$row->active,
        'inactive' => (int)$row->inactive
    ];
    $result->free();
}

// Flash message helper
$flash = null;
if (!empty($_SESSION['flash_message'])) {
    $flash = [
        'type' => ($_SESSION['flash_type'] ?? '') === 'error' ? 'danger' : 'success',
        'message' => htmlspecialchars($_SESSION['flash_message'])
    ];
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$maxYearOfStudy = 4;
if ($result = $db->query("SELECT MAX(year_of_study) AS max_year FROM fee_structure")) {
    $row = $result->fetch_object();
    if ($row && !empty($row->max_year)) {
        $maxYearOfStudy = max(4, (int)$row->max_year);
    }
    $result->free();
}
?>

<div class="container-fluid px-4 portal-dashboard accounts-page program-fees-page">
    <!-- Header -->
    <div class="dashboard-header finance-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Fee Structure Management</h1>
                <p class="text-muted mb-0">Manage course fees and fee structures</p>
            </div>
            <div class="col-auto">
                <div class="d-flex gap-2 align-items-center">
                    <?php if ($flash): ?>
                        <div class="alert alert-<?= $flash['type'] ?> py-2 px-3 mb-0"><?= $flash['message'] ?></div>
                    <?php endif; ?>
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#filterModal">
                        <i class="fas fa-filter me-2"></i>Filter
                        <span class="badge bg-primary d-none" id="activeFilterCount">0</span>
                    </button>
                    <a href="add_fee_structure.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Add New Fee
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="text-primary mb-2"><i class="fas fa-list fa-2x"></i></div>
                    <h3 class="mb-1" data-stat-key="total"><?= $stats['total'] ?></h3>
                    <small class="text-muted">Total Fee Entries</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="text-info mb-2"><i class="fas fa-graduation-cap fa-2x"></i></div>
                    <h3 class="mb-1" data-stat-key="programs"><?= $stats['programs'] ?></h3>
                    <small class="text-muted">Programs with Fees</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="text-success mb-2"><i class="fas fa-check-circle fa-2x"></i></div>
                    <h3 class="mb-1" data-stat-key="active"><?= $stats['active'] ?></h3>
                    <small class="text-muted">Active Fees</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="text-danger mb-2"><i class="fas fa-times-circle fa-2x"></i></div>
                    <h3 class="mb-1" data-stat-key="inactive"><?= $stats['inactive'] ?></h3>
                    <small class="text-muted">Inactive Fees</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Fee Structure Grouped by Program -->
    <div class="data-table-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-table me-2"></i>Fee Structures by Program</h5>
                <div class="header-actions d-flex gap-2">
                    <button class="btn btn-sm btn-primary" id="expandAll">
                        <i class="fas fa-expand-alt me-1"></i>Expand All
                    </button>
                    <button class="btn btn-sm btn-secondary" id="collapseAll">
                        <i class="fas fa-compress-alt me-1"></i>Collapse All
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div class="btn-group" role="group" id="entityTypeTabs" aria-label="Fee entity filters">
                    <button type="button" class="btn btn-outline-secondary active" data-entity-filter="">
                        All
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-entity-filter="program">
                        Programs
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-entity-filter="short_course">
                        Short Courses
                    </button>
                </div>
                <small class="text-muted">Switch between program fees and short course fee structures.</small>
            </div>
            <div id="loadingSpinner" class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-3 text-muted">Loading fee structures...</p>
            </div>
            <div id="feeStructureAccordion" class="accordion fee-accordion-hidden"></div>
            <div id="noDataMessage" class="text-center py-5 no-data-hidden">
                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                <p class="text-muted">No fee structures found</p>
            </div>
        </div>
    </div>
</div>

<!-- Filter Modal -->
<div class="modal fade" id="filterModal" tabindex="-1" aria-labelledby="filterModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header finance-modal">
                <h5 class="modal-title" id="filterModalLabel"><i class="fas fa-filter me-2"></i>Filter Fee Structures</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="filterForm">
                    <div class="mb-3">
                        <label for="filterProgram" class="form-label">Program / Short Course</label>
                        <select class="form-select" id="filterProgram">
                            <option value="">All Groups</option>
                            <?php if (!empty($programs)): ?>
                                <optgroup label="Programs">
                                    <?php foreach ($programs as $p): ?>
                                        <option value="<?= htmlspecialchars($p->program_code) ?>">
                                            <?= htmlspecialchars($p->program_code . ' - ' . $p->program_name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($shortCourses)): ?>
                                <optgroup label="Short Courses">
                                    <?php foreach ($shortCourses as $course): ?>
                                        <option value="<?= htmlspecialchars($course->course_code) ?>">
                                            <?= htmlspecialchars($course->course_code . ' - ' . $course->course_name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="filterYear" class="form-label">Year of Study</label>
                        <select class="form-select" id="filterYear">
                            <option value="">All Years</option>
                            <?php for ($y = 1; $y <= $maxYearOfStudy; $y++): ?>
                                <option value="<?= $y ?>">Year <?= $y ?></option>
                            <?php endfor; ?>
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
                <div id="activeFilters"></div>
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
$(function() {
    const esc = s => $('<div>').text(String(s ?? '')).html();

    function getCurrentFilters() {
        return {
            filterProgram: $('#filterProgram').val() || '',
            filterYear: $('#filterYear').val() || '',
            filterStatus: $('#filterStatus').val() || '',
            filterEntityType: $('#entityTypeTabs .active').data('entityFilter') || ''
        };
    }

    function updateStats(stats) {
        if (!stats) {
            return;
        }

        ['total', 'programs', 'active', 'inactive'].forEach(function(key) {
            const value = Number.parseInt(stats[key], 10);
            $(`[data-stat-key="${key}"]`).text(Number.isNaN(value) ? 0 : value);
        });
    }

    // Load and display fee structures grouped by program
    function loadFeeStructures() {
        const filters = getCurrentFilters();
        $('#loadingSpinner').show();
        $('#feeStructureAccordion').hide();
        $('#noDataMessage').hide();

        $.ajax({
            url: 'ajax/get_fee_structure_grouped.php',
            method: 'POST',
            dataType: 'json',
            data: filters
        }).done(function(response) {
            const parsed = (typeof response === 'string')
                ? (function() { try { return JSON.parse(response); } catch (e) { return null; } })()
                : response;

            if (!parsed || parsed.success === false) {
                $('#feeStructureAccordion').hide();
                $('#noDataMessage').show();
                if (window.Swal) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Unable to load fee structures',
                        text: (parsed && parsed.message) ? parsed.message : 'Invalid response from server.'
                    });
                }
                return;
            }

            updateStats(parsed.stats || {});
            displayGroupedFees(Array.isArray(parsed.data) ? parsed.data : []);
        }).fail(function(xhr) {
            $('#feeStructureAccordion').hide();
            $('#noDataMessage').show();
            if (window.Swal) {
                const serverMessage = xhr && xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'Failed to load fee structures.';
                Swal.fire({ icon: 'error', title: 'Error', text: serverMessage });
            }
        }).always(function() {
            $('#loadingSpinner').hide();
        });
    }

    function displayGroupedFees(data) {
        const accordion = $('#feeStructureAccordion');
        accordion.empty();

        if (!data || data.length === 0) {
            $('#noDataMessage').show();
            return;
        }

        accordion.show();

        data.forEach((program, index) => {
            const entityType = program.entity_type === 'short_course' ? 'short_course' : 'program';
            const rawProgramCode = String(program.program_code ?? '');
            const safeProgramCode = esc(rawProgramCode);
            const safeProgramName = esc(program.program_name ?? rawProgramCode);
            const totalAmount = program.fees.reduce((sum, fee) => sum + parseFloat(fee.amount || 0), 0);
            const activeCount = program.fees.filter(f => String(f.status || '').toLowerCase() === 'active').length;
            const programIdBase = rawProgramCode.replace(/[^a-zA-Z0-9]/g, '-');
            const programId = `program-${programIdBase || index}`;
            const iconClass = entityType === 'short_course' ? 'fas fa-certificate text-warning' : 'fas fa-graduation-cap text-primary';
            const entityBadgeClass = entityType === 'short_course' ? 'bg-warning text-dark' : 'bg-primary';
            const entityBadgeLabel = entityType === 'short_course' ? 'Short Course' : 'Program';
            
            const accordionItem = $(`
                <div class="accordion-item mb-3 border rounded">
                    <h2 class="accordion-header" id="heading-${programId}">
                        <button class="accordion-button ${index === 0 ? '' : 'collapsed'}" type="button" 
                                data-bs-toggle="collapse" data-bs-target="#collapse-${programId}" 
                                aria-expanded="${index === 0 ? 'true' : 'false'}" aria-controls="collapse-${programId}">
                            <div class="d-flex w-100 justify-content-between align-items-center me-3">
                                <div>
                                    <i class="${iconClass} me-2"></i>
                                    <strong class="text-primary">${safeProgramCode}</strong>
                                    <span class="text-muted ms-2">${safeProgramName}</span>
                                </div>
                                <div class="d-flex gap-3 small">
                                    <span class="badge ${entityBadgeClass}">${entityBadgeLabel}</span>
                                    <span class="badge bg-info">${program.fees.length} fees</span>
                                    <span class="badge bg-success">${activeCount} active</span>
                                    <span class="text-muted">Total: <strong>ZMK ${totalAmount.toLocaleString('en-US', {minimumFractionDigits: 2})}</strong></span>
                                </div>
                            </div>
                        </button>
                    </h2>
                    <div id="collapse-${programId}" class="accordion-collapse collapse ${index === 0 ? 'show' : ''}" 
                         aria-labelledby="heading-${programId}">
                        <div class="accordion-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="fee-col-index">#</th>
                                            <th>Year</th>
                                            <th>Semester</th>
                                            <th>Description</th>
                                            <th class="text-end">Amount (ZMK)</th>
                                            <th>Status</th>
                                            <th class="text-center fee-col-actions">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="program-fees-body" data-program="${safeProgramCode}">
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            `);

            const tbody = accordionItem.find('.program-fees-body');
            program.fees.forEach((fee, feeIndex) => {
                const feeStatus = String(fee.status || '').toLowerCase();
                const statusBadge = feeStatus === 'active' 
                    ? '<span class="badge bg-success">Active</span>' 
                    : '<span class="badge bg-danger">Inactive</span>';
                
                const periodLabel = (fee.period_type || 'semester').toLowerCase() === 'term' ? 'Term' : 'Semester';
                const feeDescription = esc(fee.fee_description);
                const feeAmount = Number.parseFloat(fee.amount || 0);
                const feeId = Number.parseInt(fee.id, 10) || 0;
                const yearOfStudy = Number.parseInt(fee.year_of_study, 10) || 0;
                const semester = Number.parseInt(fee.semester, 10) || 0;
                const yearLabel = entityType === 'short_course' || !yearOfStudy ? '--' : `Year ${yearOfStudy}`;
                const semesterLabel = entityType === 'short_course' || !semester ? '--' : `${esc(periodLabel)} ${semester}`;
                
                tbody.append(`
                    <tr>
                        <td>${feeIndex + 1}</td>
                        <td>${yearLabel}</td>
                        <td>${semesterLabel}</td>
                        <td>${feeDescription}</td>
                        <td class="text-end"><strong>ZMK ${feeAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                        <td>${statusBadge}</td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm" role="group">
                                <a href="edit_fee_structure.php?id=${feeId}" class="btn btn-outline-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <button class="btn btn-outline-danger delete-fee" data-id="${feeId}" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `);
            });

            accordion.append(accordionItem);
        });
    }

    // Expand/Collapse all
    $('#expandAll').on('click', function() {
        $('.accordion-collapse').each(function() {
            if ($.fn.collapse) {
                $(this).collapse('show');
            } else if (window.bootstrap && window.bootstrap.Collapse) {
                window.bootstrap.Collapse.getOrCreateInstance(this, { toggle: false }).show();
            }
        });
    });

    $('#collapseAll').on('click', function() {
        $('.accordion-collapse').each(function() {
            if ($.fn.collapse) {
                $(this).collapse('hide');
            } else if (window.bootstrap && window.bootstrap.Collapse) {
                window.bootstrap.Collapse.getOrCreateInstance(this, { toggle: false }).hide();
            }
        });
    });

    // Filter handling
    function updateFilterBadges() {
        const filters = getCurrentFilters();
        const container = $('#activeFilters').empty();
        let count = 0;
        for (const [key, val] of Object.entries(filters)) {
            if (val) {
                count++;
                const displayName = esc(key.replace('filter', ''));
                let displayValue = val;
                
                // Get display text for program
                if (key === 'filterProgram') {
                    const option = $(`#filterProgram option[value="${val}"]`);
                    displayValue = option.text() || val;
                }
                
                container.append(`
                    <span class="badge bg-primary me-2 mb-2 p-2">
                        ${displayName}: ${esc(displayValue)}
                        <span class="remove-filter clickable-filter-pill" data-filter="${key}">
                            <i class="fas fa-times ms-1"></i>
                        </span>
                    </span>`);
            }
        }
        $('#activeFilterCount').text(count).toggleClass('d-none', count === 0);
    }

    $('#filterProgram, #filterYear, #filterStatus').on('change', function() {
        updateFilterBadges();
    });

    $('#entityTypeTabs').on('click', 'button', function() {
        $('#entityTypeTabs button').removeClass('active');
        $(this).addClass('active');
        loadFeeStructures();
    });

    $(document).on('click', '.remove-filter', function() {
        const id = $(this).data('filter');
        $(`#${id}`).val('');
        updateFilterBadges();
        loadFeeStructures();
    });

    $('#clearFilters').on('click', function() {
        $('#filterForm select').val('');
        updateFilterBadges();
        loadFeeStructures();
    });

    $('#applyFilter').on('click', function() {
        loadFeeStructures();
        $('#filterModal').modal('hide');
    });

    // Delete fee
    $(document).on('click', '.delete-fee', function() {
        if (!window.Swal) {
            return;
        }

        const id = $(this).data('id');
        Swal.fire({
            title: 'Delete this fee?',
            text: 'This will mark the fee structure as deleted.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete'
        }).then(result => {
            if (result.isConfirmed) {
                $.post('ajax/delete_fee_structure.php', { id }, function(res) {
                    if (res?.success) {
                        loadFeeStructures();
                        Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res?.message || 'Delete failed' });
                    }
                }).fail(function() {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to delete fee structure.' });
                });
            }
        });
    });

    // Initial load
    loadFeeStructures();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

