<?php
header('Content-Type: text/html; charset=UTF-8');
include "includes/admin.php"; // Ensure $db connection is inside here
require "includes/header.php";
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Initialize stats
$stats = ['depts' => 0, 'progs' => 0, 'studs' => 0, 'hods' => 0];

try {
    // Check which tables exist
    $hasSSA = false;
    if ($tableCheck = @$db->query("SHOW TABLES LIKE 'staff_section_assignments'")) {
        $hasSSA = $tableCheck->num_rows > 0;
        $tableCheck->free();
    }
    $hasSections = false;
    if ($tableCheck = @$db->query("SHOW TABLES LIKE 'sections'")) {
        $hasSections = $tableCheck->num_rows > 0;
        $tableCheck->free();
    }

    // Check departments columns
    $deptCols = [];
    if ($meta = @$db->query("SHOW COLUMNS FROM departments")) {
        while ($c = $meta->fetch_assoc()) { $deptCols[strtolower($c['Field'])] = $c['Field']; }
        $meta->free();
    }
    $hasHodId = isset($deptCols['hod_id']);

    // Optimized counting - each in its own try so one failure doesn't break all
    $countQueries = [
        'depts' => "SELECT COUNT(*) FROM departments",
        'progs' => "SELECT COUNT(*) FROM programs",
        'studs' => "SELECT COUNT(*) FROM students",
    ];

    // HOD/HOS count depends on schema
    if ($hasSSA) {
        $countQueries['hods'] = "SELECT COUNT(DISTINCT staff_id) FROM staff_section_assignments WHERE role_key = 'head_of_department' AND status = 'active'";
    } elseif ($hasHodId) {
        $countQueries['hods'] = "SELECT COUNT(DISTINCT hod_id) FROM departments WHERE hod_id IS NOT NULL";
    }

    foreach ($countQueries as $key => $sql) {
        try {
            if ($res = @$db->query($sql)) {
                $stats[$key] = (int)($res->fetch_row()[0] ?? 0);
                $res->free();
            }
        } catch (Throwable $e) {
            error_log("Dashboard stat '{$key}' failed: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    error_log("Dashboard Stats Error: " . $e->getMessage());
}

$total_departments = $stats['depts'];
$total_programs = $stats['progs'];
$total_students = $stats['studs'];
$total_hods = $stats['hods'];

$hos_sections = [];
try {
    if ($res = $db->query("SHOW TABLES LIKE 'sections'")) {
        $hasSections = $res->num_rows > 0;
        $res->free();
        if ($hasSections) {
            $sql = "
                SELECT
                    s.section_id,
                    s.section_name,
                    s.section_type,
                    s.department_id,
                    ssa.staff_id,
                    CONCAT(st.Fname, ' ', st.Lname) AS hos_name
                FROM sections s
                LEFT JOIN staff_section_assignments ssa
                    ON ssa.section_id = s.section_id
                   AND ssa.role_key = 'head_of_department'
                   AND ssa.status = 'active'
                LEFT JOIN staff st ON st.staff_id = ssa.staff_id
                WHERE s.status = 'active'
                ORDER BY FIELD(s.section_id, 'TRANSPORT', 'ENGICT') DESC, s.section_name ASC
            ";
            if ($sectionRes = $db->query($sql)) {
                while ($row = $sectionRes->fetch_assoc()) {
                    $hos_sections[] = $row;
                }
                $sectionRes->free();
            }
        }
    }
} catch (Throwable $e) {
    error_log('HOS section summary failed: ' . $e->getMessage());
}
?>

<style>
    /* Page-specific — shared components in assets/css/dashboard.css */
    .quick-actions-card { border: none !important; background: transparent !important; box-shadow: none !important; }
    .student-modal { background: linear-gradient(135deg, #6f42c1 0%, #4e2a84 100%) !important; border: none !important; }
</style>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4 mt-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-building me-2 text-primary"></i>Department Management Hub</h5>
                <p class="page-subtitle mb-0">Total of <?= number_format($total_departments) ?> departments currently operating across <?= number_format($total_programs) ?> programs</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary shadow-sm" onclick="openModal()">
                    <i class="fas fa-plus me-2"></i>New Department
                </button>
            </div>
        </div>
    </div>

    <!-- Notification Area -->
    <div id="notification" class="notification" style="display:none"></div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3 text-white"><i class="fas fa-building"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($total_departments) ?></h3>
                        <p class="text-muted mb-0">Total Departments</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3 text-white"><i class="fas fa-user-tie"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($total_hods) ?></h3>
                        <p class="text-muted mb-0">Section Heads</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3 text-white"><i class="fas fa-graduation-cap"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($total_programs) ?></h3>
                        <p class="text-muted mb-0">Total Programs</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning me-3 text-white"><i class="fas fa-users"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($total_students) ?></h3>
                        <p class="text-muted mb-0">Total Students</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($hos_sections)): ?>
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="data-table-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-user-tie me-2"></i>Head of Section Assignments
                    </h5>
                    <button class="btn btn-sm btn-outline-success" onclick="openHodManagement()">
                        <i class="fas fa-edit me-1"></i>Manage HOS
                    </button>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php foreach ($hos_sections as $section): ?>
                            <?php
                                $sectionName = (string)($section['section_name'] ?? '');
                                $sectionType = (string)($section['section_type'] ?? '');
                                $hosName = trim((string)($section['hos_name'] ?? ''));
                                $hosStaffId = (string)($section['staff_id'] ?? '');
                                $isAssigned = $hosName !== '';
                            ?>
                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 h-100 bg-white">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($sectionName); ?></h6>
                                            <span class="badge bg-light text-secondary text-uppercase"><?php echo htmlspecialchars($sectionType); ?></span>
                                        </div>
                                        <span class="badge <?php echo $isAssigned ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                            <?php echo $isAssigned ? 'Assigned' : 'Unassigned'; ?>
                                        </span>
                                    </div>
                                    <div class="mt-3">
                                        <?php if ($isAssigned): ?>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($hosName); ?></div>
                                            <small class="text-muted">Staff ID: <?php echo htmlspecialchars($hosStaffId); ?></small>
                                        <?php else: ?>
                                            <div class="text-muted">No HOS assigned yet.</div>
                                            <small class="text-muted">Use Manage HOS to assign this section.</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="quick-actions-card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-bolt me-2"></i>Quick Actions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <button class="btn btn-outline-primary w-100 action-btn-card" onclick="openModal()">
                                <i class="fas fa-plus"></i>
                                <span>Add Department</span>
                                <small class="text-muted">Create new department</small>
                            </button>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-outline-success w-100 action-btn-card" onclick="openHodManagement()">
                                <i class="fas fa-users"></i>
                                <span>Manage HOS</span>
                                <small class="text-muted">Assign section heads</small>
                            </button>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-outline-info w-100 action-btn-card" onclick="viewPrograms()">
                                <i class="fas fa-graduation-cap"></i>
                                <span>View Programs</span>
                                <small class="text-muted">Browse programs</small>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Department List View Toggle -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="btn-group view-toggle" role="group" aria-label="View Toggle">
                <button type="button" class="btn btn-primary active" id="tableViewBtn" onclick="toggleView('table')">
                    <i class="fas fa-list me-1"></i>Table View
                </button>
                <button type="button" class="btn btn-primary" id="cardViewBtn" onclick="toggleView('card')">
                    <i class="fas fa-th-large me-1"></i>Card View
                </button>
            </div>
        </div>
    </div>

    <!-- Departments Table View -->
    <div id="tableView" class="row g-3">
        <div class="col-12">
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Department List
                        </h5>
                        <div class="header-actions">
                            <button class="btn btn-success btn-sm" onclick="exportToExcel()">
                                <i class="fas fa-file-excel me-2"></i>Export
                            </button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                                <i class="fas fa-print me-2"></i>Print
                            </button>
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-sm btn-secondary dropdown-toggle" type="button" id="filterDropdown" data-bs-toggle="dropdown">
                                    <i class="fas fa-filter me-1"></i>Filter
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="#" onclick="filterByStatus('all')">All Departments</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="filterByStatus('active')">Active Only</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="filterByStatus('inactive')">Inactive Only</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="departmentsTable" class="table table-hover align-middle mb-0" style="width:100%">
                            <thead class="table-light sticky-top">
                                <tr class="text-uppercase small fw-bold border-bottom border-2">
                                    <th class="ps-4">Department ID</th>
                                    <th>Department Name</th>
                                    <th>Head of Section</th>
                                    <th>Faculty</th>
                                    <th>Programs</th>
                                    <th>Students</th>
                                    <th>Faculty Count</th>
                                    <th>Status</th>
                                    <th class="text-center pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Department data will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
                </div>
            </div>
    </div>

    <!-- Departments Card View -->
    <div id="cardView" class="row g-3 d-none">
        <!-- Card view will be populated dynamically -->
    </div>
</div>

<!-- Add Department Modal -->
<div id="addDepartmentModal" class="modal fade" tabindex="-1" aria-labelledby="addDepartmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header student-modal">
                <h5 class="modal-title" id="addDepartmentModalLabel">
                    <i class="fas fa-plus-circle me-2"></i>Add New Department
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="departmentForm" method="POST" action="process_department.php" class="needs-validation" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" id="department_pk" name="department_pk" value="">

                    <div class="alert alert-info py-2 mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <small>Fields marked with <span class="text-danger">*</span> are required</small>
                    </div>

                    <!-- Department Code -->
                    <div class="mb-3">
                        <label for="departmentId" class="form-label fw-semibold">
                            Department Code <span class="text-danger">*</span>
                            <i class="fas fa-question-circle text-muted" title="2-20 uppercase letters/digits, starting with a letter (e.g., CS, ENG, ICT, ENG01)" data-bs-toggle="tooltip"></i>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-hashtag"></i></span>
                            <input type="text" class="form-control text-uppercase" id="departmentId" name="departmentId"
                                   pattern="[A-Z][A-Z0-9]{1,19}" maxlength="20"
                                   placeholder="e.g., CS, ENG, ICT, ENG01"
                                   autocomplete="off" required>
                            <div class="valid-feedback">Looks good!</div>
                            <div class="invalid-feedback">Code must be 2-20 letters/digits, starting with a letter (e.g., ENG, CS01)</div>
                        </div>
                    </div>

                    <!-- Department Name -->
                    <div class="mb-3">
                        <label for="departmentName" class="form-label fw-semibold">
                            Department Name <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-building"></i></span>
                            <input type="text" class="form-control" id="departmentName" name="departmentName" 
                                   placeholder="e.g., Computer Science" 
                                   minlength="2" maxlength="100"
                                   autocomplete="off" required>
                            <div class="valid-feedback">Looks good!</div>
                            <div class="invalid-feedback">Please enter department name (2-100 characters)</div>
                        </div>
                    </div>

                    <!-- Faculty -->
                    <div class="mb-3">
                        <label for="faculty" class="form-label fw-semibold">
                            Faculty <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-university"></i></span>
                            <select class="form-select" id="faculty" name="faculty" required>
                                <option value="">-- Select Faculty --</option>
                                <option value="Science">Faculty of Science</option>
                                <option value="Engineering">Faculty of Engineering</option>
                                <option value="Business">Faculty of Business</option>
                                <option value="Arts">Faculty of Arts & Humanities</option>
                                <option value="Education">Faculty of Education</option>
                                <option value="Medicine">Faculty of Medicine</option>
                                <option value="Law">Faculty of Law</option>
                                <option value="Agriculture">Faculty of Agriculture</option>
                            </select>
                            <div class="valid-feedback">Looks good!</div>
                            <div class="invalid-feedback">Please select a faculty</div>
                        </div>
                    </div>

                    <!-- Section -->
                    <div class="mb-3">
                        <label for="departmentSection" class="form-label fw-semibold">
                            Section <span class="text-danger">*</span>
                            <i class="fas fa-question-circle text-muted" title="The section this department belongs to. Its Head of Section is shown automatically and is managed from “Manage HOS”." data-bs-toggle="tooltip"></i>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-sitemap"></i></span>
                            <select class="form-select" id="departmentSection" name="section_id" required>
                                <option value="">-- Select Section --</option>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" id="refreshSectionBtn" title="Reload sections">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                            <div class="valid-feedback">Looks good!</div>
                            <div class="invalid-feedback">Please select a section</div>
                        </div>
                        <small class="text-muted d-block mt-1" id="sectionLoadStatus">The Head of Section is assigned from “Manage HOS”.</small>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="reset" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i>Reset
                    </button>
                    <button type="submit" class="btn btn-primary" id="saveDeptBtn">
                        <i class="fas fa-save me-1"></i>Save Department
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Add Programs Modal -->
<div id="quickAddProgramModal" class="modal fade" tabindex="-1" aria-labelledby="quickAddProgramModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-gradient-success text-white">
                <h5 class="modal-title" id="quickAddProgramModalLabel">
                    <i class="fas fa-graduation-cap me-2"></i>Add Programs to <span id="selectedDeptName"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="quickProgramForm" method="POST" class="needs-validation" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="add_program">
                    <input type="hidden" id="quick_department_id" name="department_id">
                    <input type="hidden" id="quick_department_code" name="department_code">

                    <div class="alert alert-info d-flex align-items-center mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <div>
                            <strong>Quick Add:</strong> Adding program to the newly created department.
                            You can add multiple programs one after another.
                        </div>
                    </div>

                    <!-- Program Code and Name -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="quick_program_code" class="form-label fw-semibold">
                                Program Code <span class="text-danger">*</span>
                                <i class="fas fa-question-circle text-muted" title="Use uppercase letters, numbers, and hyphens" data-bs-toggle="tooltip"></i>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-hashtag"></i></span>
                                <input type="text" class="form-control text-uppercase" id="quick_program_code" name="program_code" 
                                       pattern="[A-Z0-9\-]+" 
                                       placeholder="e.g., BSC-CS, DIP-IT" 
                                       required>
                                <div class="invalid-feedback">Enter a valid program code</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="quick_program_name" class="form-label fw-semibold">
                                Program Name <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-graduation-cap"></i></span>
                                <input type="text" class="form-control" id="quick_program_name" name="program_name" 
                                       placeholder="e.g., Bachelor of Computer Science" 
                                       required>
                                <div class="invalid-feedback">Enter program name</div>
                            </div>
                        </div>
                    </div>

                    <!-- Program Type and Study Mode -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="quick_program_type" class="form-label fw-semibold">
                                Program Type <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="quick_program_type" name="program_type" required>
                                <option value="">-- Select Type --</option>
                                <option value="degree">Degree</option>
                                <option value="diploma">Diploma</option>
                                <option value="certificate">Certificate</option>
                            </select>
                            <div class="invalid-feedback">Select program type</div>
                        </div>
                        <div class="col-md-6">
                            <label for="quick_period_mode" class="form-label fw-semibold">
                                Registration Period <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="quick_period_mode" name="period_mode" required>
                                <option value="semester">Semester (2 per year)</option>
                                <option value="term">Term (3 per year)</option>
                            </select>
                            <div class="invalid-feedback">Select registration period</div>
                            <small class="text-muted">Controls how this program registers (semester vs term intakes).</small>
                        </div>
                    </div>

                    <!-- Duration and Status -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="quick_program_duration" class="form-label fw-semibold">
                                Duration (years)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                <input type="number" class="form-control" id="quick_program_duration" name="program_duration" 
                                       min="0.25" max="10" step="0.25" placeholder="e.g., 2">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold d-block">Status</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="quick_is_active" name="is_active" value="1" checked>
                                <label class="form-check-label" for="quick_is_active">
                                    <i class="fas fa-check-circle text-success me-1"></i>Active Program
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="mb-3">
                        <label for="quick_program_description" class="form-label fw-semibold">Description (Optional)</label>
                        <textarea class="form-control" id="quick_program_description" name="program_description" 
                                  rows="2" placeholder="Brief description of the program"></textarea>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Close
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="addAnotherProgramBtn" disabled>
                        <i class="fas fa-plus me-1"></i>Add Another
                    </button>
                    <button type="submit" class="btn btn-success" id="saveQuickProgramBtn">
                        <i class="fas fa-save me-1"></i>Add Program
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage HOS Modal -->
<div id="manageHodModal" class="modal fade" tabindex="-1" aria-labelledby="manageHodModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header student-modal">
                <h5 class="modal-title" id="manageHodModalLabel">
                    <i class="fas fa-user-tie me-2"></i>Manage Heads of Section
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="manageHodForm" method="POST" action="assign_hod.php" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="card mb-3">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>Assignment Details
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label for="hod_department" class="form-label">Section <span class="text-danger">*</span></label>
                                    <select id="hod_department" name="section_id" class="form-select" autocomplete="off" required>
                                        <option value="">Select Section</option>
                                    </select>
                                    <div class="invalid-feedback">Please select a section.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="hod_staff" class="form-label">Staff (Assign as HOS) <span class="text-danger">*</span></label>
                                    <select id="hod_staff" name="staff_id" class="form-select" autocomplete="off" required>
                                        <option value="">Select Staff</option>
                                    </select>
                                    <div class="invalid-feedback">Please select a staff member.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal Footer -->
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check me-2"></i>Assign HOS
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    'use strict';
    
    // Pending section value to apply once the options exist (used by edit mode,
    // where the department's saved section must be selected after loading).
    window.pendingSectionValue = null;

    // Load the list of sections into the department form's Section picker.
    // Sections come from get_hos_sections.php (section_id + label incl. current HOS).
    // Concurrency-safe: overlapping calls (e.g. edit + the modal show handler)
    // will not double-fetch and clobber a selected value — later callers wait
    // for the in-flight load to settle.
    window.loadSections = function(forceReload, onReady) {
        const $sel = $('#departmentSection');
        const $status = $('#sectionLoadStatus');
        const $refreshBtn = $('#refreshSectionBtn');

        const runReady = function() {
            if (window.pendingSectionValue !== null && window.pendingSectionValue !== undefined) {
                $sel.val(window.pendingSectionValue);
                window.pendingSectionValue = null;
            }
            if (typeof onReady === 'function') onReady();
        };

        if (!forceReload && $sel.data('loaded')) {
            runReady();
            return;
        }
        if ($sel.data('loading')) {
            // A load is already in progress; run our ready logic when it settles.
            $sel.one('sections:loaded', runReady);
            return;
        }

        $sel.data('loading', true);
        $sel.prop('disabled', true).empty().append('<option value="">Loading sections...</option>');
        $status.html('<i class="fas fa-spinner fa-spin"></i> Loading sections...');
        $refreshBtn.prop('disabled', true).find('i').addClass('fa-spin');

        $.ajax({
            url: 'get_hos_sections.php',
            dataType: 'json',
            timeout: 15000,
            cache: false
        })
        .done(function(resp) {
            $sel.empty().append('<option value="">-- Select Section --</option>');
            const rows = (resp && Array.isArray(resp.data)) ? resp.data : [];
            if (rows.length > 0) {
                rows.forEach(function(d) {
                    const label = d.label || d.section_name || d.section_id;
                    $('<option>').val(d.section_id).text(label).appendTo($sel);
                });
                $sel.data('loaded', true).prop('disabled', false);
                $status.html('<i class="fas fa-check-circle text-success"></i> ' + rows.length + ' section(s) loaded. Head of Section is managed via “Manage HOS”.');
            } else {
                $sel.append('<option value="">No sections available</option>');
                $status.html('<i class="fas fa-exclamation-triangle text-warning"></i> No sections found');
            }
        })
        .fail(function(xhr, status, error) {
            $sel.empty().append('<option value="">Failed to load sections</option>');
            $status.html('<i class="fas fa-times-circle text-danger"></i> Failed to load. <a href="#" onclick="loadSections(true); return false;">Retry</a>');
            console.error('Section loading error:', status, error, xhr.responseText);
        })
        .always(function() {
            $sel.data('loading', false);
            $refreshBtn.prop('disabled', false).find('i').removeClass('fa-spin');
            runReady();
            $sel.trigger('sections:loaded');
        });
    };

    // Reload sections button
    $('#refreshSectionBtn').on('click', function() {
        loadSections(true);
    });

    // Load sections on Add Department modal open
    $('#addDepartmentModal').on('show.bs.modal', function() {
        loadSections();

        // Initialize tooltips
        $('[data-bs-toggle="tooltip"]').tooltip();
    });

    // Auto-format department code to uppercase
    $('#departmentId').on('input', function() {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    });

    // Real-time validation feedback (matches the relaxed code pattern:
    // 2-20 uppercase letters/digits, starting with a letter).
    $('#departmentId').on('blur', function() {
        const val = this.value;
        if (val && !/^[A-Z][A-Z0-9]{1,19}$/.test(val)) {
            $(this).addClass('is-invalid').removeClass('is-valid');
        } else if (val) {
            $(this).removeClass('is-invalid').addClass('is-valid');
        }
    });

    $('#departmentName').on('blur', function() {
        const val = this.value.trim();
        if (val.length >= 2) {
            $(this).removeClass('is-invalid').addClass('is-valid');
        } else if (val.length > 0) {
            $(this).addClass('is-invalid').removeClass('is-valid');
        }
    });

    // Submit Department form via AJAX
    $('#departmentForm').on('submit', function(e) {
        e.preventDefault();
        const form = this;
        
        // Check validity
        if (!form.checkValidity()) {
            e.stopPropagation();
            $(form).addClass('was-validated');
            
            // Focus on first invalid field
            const $firstInvalid = $(form).find(':invalid').first();
            if ($firstInvalid.length) {
                $firstInvalid.focus();
                showAlert('warning', 'Please fill in all required fields correctly');
            }
            return;
        }
        
        const $btn = $('#saveDeptBtn');
        const originalText = $btn.html();
        const formData = $(form).serialize();

        // Create vs edit is decided by the hidden primary-key field.
        const pk = $('#department_pk').val();
        const isEdit = pk !== '' && pk != null;
        const url = isEdit ? 'update_department.php' : 'process_department.php';

        // Disable form during submission
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Saving...');
        $(form).find('input, select, button').prop('disabled', true);

        $.ajax({
            url: url,
            method: 'POST',
            data: formData,
            dataType: 'json',
            timeout: 15000
        })
        .done(function(resp) {
            if (resp && resp.success) {
                // Store the department info
                const newDeptCode = resp.department_code || $('#departmentId').val();
                const newDeptName = resp.department_name || $('#departmentName').val();
                const deptId = resp.department_id;

                // Close the modal first
                $('#addDepartmentModal').modal('hide');

                // Reset form
                form.reset();
                $(form).removeClass('was-validated');
                $(form).find('.is-valid, .is-invalid').removeClass('is-valid is-invalid');

                // Reload table (and refresh card view if it is showing)
                if (window.departmentsTable) {
                    window.departmentsTable.ajax.reload(function() {
                        const cv = document.getElementById('cardView');
                        if (cv && !cv.classList.contains('d-none')) {
                            loadCardView();
                        }
                    }, false);
                }

                if (isEdit) {
                    showAlert('success', resp.message || 'Department updated successfully!');
                } else {
                    // Offer to add programs to the freshly created department
                    showSuccessWithAction(
                        resp.message || 'Department created successfully!',
                        'Would you like to add programs to this department?',
                        'Add Programs',
                        function() {
                            openQuickAddProgram(deptId, newDeptCode, newDeptName);
                        }
                    );
                }
            } else {
                showAlert('danger', (resp && resp.message) ? resp.message : 'Failed to save department');
            }
        })
        .fail(function(xhr, status, error) {
            let errorMsg = 'Request failed: ' + error;

            if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMsg = xhr.responseJSON.message;
            } else if (xhr.status === 0) {
                errorMsg = 'Network error. Please check your connection.';
            } else if (xhr.status === 403) {
                errorMsg = 'Access denied. Please refresh and login again.';
            } else if (xhr.status === 500) {
                errorMsg = 'Server error. Please try again or contact support.';
            }

            showAlert('danger', errorMsg);
            console.error('Department save error:', status, error, xhr.responseText);
        })
        .always(function() {
            // Always re-enable controls so the modal is usable on next open.
            $btn.prop('disabled', false).html(originalText);
            $(form).find('input, select, button').prop('disabled', false);
        });
    });

    // Reset form validation and revert to "create" mode on modal close
    $('#addDepartmentModal').on('hidden.bs.modal', function() {
        const $form = $('#departmentForm');
        $form[0].reset();
        $form.removeClass('was-validated');
        $form.find('.is-valid, .is-invalid').removeClass('is-valid is-invalid');
        setDeptModalMode('create');
    });

    // Auto-format program code to uppercase
    $('#quick_program_code').on('input', function() {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9\-]/g, '');
    });

    $('#quick_program_type').on('change', function() {
        const defaults = { degree: '4', diploma: '2', certificate: '1' };
        const type = ($(this).val() || '').toLowerCase();
        if (!$('#quick_program_duration').val() && defaults[type]) {
            $('#quick_program_duration').val(defaults[type]);
        }
    });

    // Quick program form submission
    $('#quickProgramForm').on('submit', function(e) {
        e.preventDefault();
        const form = this;
        
        if (!form.checkValidity()) {
            e.stopPropagation();
            $(form).addClass('was-validated');
            $(form).find(':invalid').first().focus();
            return;
        }
        
        const $btn = $('#saveQuickProgramBtn');
        const originalText = $btn.html();
        const formData = $(form).serialize();
        
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Adding...');
        $(form).find('input, select, textarea, button').prop('disabled', true);
        
        $.ajax({
            url: 'ajax/add_program.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            timeout: 15000
        })
        .done(function(resp) {
            if (resp && (resp.success || resp.status === 'success')) {
                const msg = resp.message || 'Program added successfully!';
                
                // Show success and enable "Add Another" button
                showAlert('success', msg);
                $('#addAnotherProgramBtn').prop('disabled', false);
                
                // Reset only the program fields, keep department
                $('#quick_program_code').val('');
                $('#quick_program_name').val('');
                $('#quick_program_type').val('');
                $('#quick_program_duration').val('');
                $('#quick_program_description').val('');
                $(form).removeClass('was-validated');
                
                // Focus back on program code for quick entry
                setTimeout(function() {
                    $('#quick_program_code').focus();
                }, 100);
            } else {
                showAlert('danger', (resp && resp.message) ? resp.message : 'Failed to add program');
            }
        })
        .fail(function(xhr, status, error) {
            let errorMsg = 'Request failed: ' + error;
            if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMsg = xhr.responseJSON.message;
            }
            showAlert('danger', errorMsg);
            console.error('Program add error:', status, error, xhr.responseText);
        })
        .always(function() {
            $btn.prop('disabled', false).html(originalText);
            $(form).find('input, select, textarea, button').prop('disabled', false);
        });
    });

    // Add another program button
    $('#addAnotherProgramBtn').on('click', function() {
        // Just trigger form submit
        $('#quickProgramForm').trigger('submit');
    });

    // Navigate to full programs page
    window.goToProgramsPage = function() {
        window.location.href = 'programs.php';
    };

    // Manage HOS modal: load sections and staff
    $('#manageHodModal').on('show.bs.modal', function(){
        const $dept = $('#hod_department');
        const $staff = $('#hod_staff');
        if (!$dept.data('loaded')) {
            $dept.empty().append('<option value="">Loading...</option>');
            $.getJSON('get_hos_sections.php', function(resp){
                $dept.empty().append('<option value="">Select Section</option>');
                if (resp && Array.isArray(resp.data)) {
                    resp.data.forEach(function(d){
                        const label = d.label || d.section_name;
                        $dept.append('<option value="' + d.section_id + '">' + label + '</option>');
                    });
                    $dept.data('loaded', true);
                }
            }).fail(function(){ $dept.empty().append('<option value="">Failed to load</option>'); });
        }
        if (!$staff.data('loaded')) {
            $staff.empty().append('<option value="">Loading...</option>');
            $.getJSON('ajax/staff_min.php', function(resp){
                $staff.empty().append('<option value="">Select Staff</option>');
                if (Array.isArray(resp)) {
                    resp.forEach(function(s){
                        const label = (s.title ? s.title + ' ' : '') + s.Fname + ' ' + s.Lname + ' (' + s.staff_id + ')';
                        $staff.append('<option value="' + s.staff_id + '">' + label + '</option>');
                    });
                    $staff.data('loaded', true);
                }
            }).fail(function(){ $staff.empty().append('<option value="">Failed to load</option>'); });
        }
    });

    // Submit Manage HOS form
    $('#manageHodForm').on('submit', function(e) {
        e.preventDefault();
        const form = this;
        
        if (!form.checkValidity()) {
            e.stopPropagation();
            $(form).addClass('was-validated');
            return;
        }
        
        const $btn = $(form).find('button[type="submit"]');
        const originalText = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Assigning...');
        
        $.ajax({
            url: $(form).attr('action'),
            method: 'POST',
            data: $(form).serialize(),
            dataType: 'json',
            timeout: 10000
        })
        .done(function(resp) {
            if (resp && resp.success) {
                showAlert('success', resp.message || 'HOS assigned successfully');
                $('#manageHodModal').modal('hide');
                if (window.departmentsTable) {
                    window.departmentsTable.ajax.reload(null, false);
                }
                form.reset();
                $(form).removeClass('was-validated');
            } else {
                showAlert('danger', (resp && resp.message) ? resp.message : 'Failed to assign HOS');
            }
        })
        .fail(function(xhr) {
            const errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Request failed: ' + xhr.status;
            showAlert('danger', errorMsg);
        })
        .always(function() {
            $btn.prop('disabled', false).html(originalText);
        });
    });

    // DataTable initialization
    window.departmentsTable = $('#departmentsTable').DataTable({
        pageLength: 10,
        responsive: true,
        processing: true,
        serverSide: false,
        dom: '<"row align-items-center mb-3"<"col-md-6"l><"col-md-6"f>>' +
             '<"table-responsive"t>' +
             '<"row align-items-center mt-3"<"col-md-5"i><"col-md-7"p>>',
        createdRow: function(row) {
            $(row).addClass('transition-effect');
        },
        ajax: {
            url: 'get_departments.php',
            dataSrc: function(json) {
                // Check if we received valid JSON
                if (!json || !json.data) {
                    console.error('Invalid response from server:', json);
                    showAlert('danger', 'Invalid response from server');
                    return [];
                }
                return json.data;
            },
            error: function(xhr, error, code) {
                console.error('DataTable ajax error:', error);
                console.error('Response:', xhr.responseText);
                if (xhr.responseText && xhr.responseText.includes('<!DOCTYPE') || xhr.responseText.includes('<html')) {
                    showAlert('danger', 'Authentication error - please refresh and login again');
                } else {
                    showAlert('danger', 'Failed to load departments: ' + error);
                }
            }
        },
        columns: [
            { data: 'department_id', width: '10%' },
            { data: 'department_name', width: '15%' },
            { 
                data: 'hod', 
                width: '15%',
                render: function(data) {
                    return data; // Already contains HTML from server
                }
            },
            { data: 'faculty', width: '12%' },
            { data: 'programs', width: '8%' },
            { data: 'students', width: '8%' },
            { data: 'faculty_count', width: '10%' },
            { 
                data: 'status',
                width: '10%',
                render: function(data) {
                    return data; // Already contains HTML badge from server
                }
            },
            { 
                data: null,
                orderable: false,
                searchable: false,
                width: '12%',
                render: function(data, type, row) {
                    return '<div class=\"btn-group btn-group-sm action-btns\" role=\"group\" aria-label=\"Department actions\">' +
                        '<button class=\"btn btn-outline-secondary\" title=\"View\" aria-label=\"View department\" onclick=\"viewDetails(\'' + row.id + '\')\">' +
                        '<i class=\"fas fa-eye\"></i></button>' +
                        '<button class=\"btn btn-outline-primary\" title=\"Edit\" aria-label=\"Edit department\" onclick=\"editDepartment(\'' + row.id + '\')\">' +
                        '<i class=\"fas fa-pen\"></i></button>' +
                        '<button class=\"btn btn-outline-danger\" title=\"Delete\" aria-label=\"Delete department\" onclick=\"deleteDepartment(\'' + row.id + '\')\">' +
                        '<i class=\"fas fa-trash-alt\"></i></button>' +
                        '</div>';
                }
            }
        ],
        columnDefs: [
            { orderable: false, targets: -1 },
            { targets: [4, 5, 6, 7], className: 'text-center' },
            { targets: 0, className: 'ps-4' },
            { targets: -1, className: 'text-center pe-4' }
        ],
        order: [[1, 'asc']],
        language: {
            search: '_INPUT_',
            searchPlaceholder: 'Search departments...',
            lengthMenu: 'Show _MENU_',
            paginate: {
                first: '<i class="fas fa-angle-double-left"></i>',
                last: '<i class="fas fa-angle-double-right"></i>',
                next: '<i class="fas fa-angle-right"></i>',
                previous: '<i class="fas fa-angle-left"></i>'
            },
            processing: '<div class=\"text-center\"><div class=\"spinner-border text-primary\" role=\"status\"></div><p class=\"mt-2\">Loading departments...</p></div>',
            zeroRecords: 'No departments found',
            emptyTable: 'No departments available',
            info: 'Showing _START_ to _END_ of _TOTAL_ departments',
            infoEmpty: 'Showing 0 to 0 of 0 departments',
            infoFiltered: '(filtered from _MAX_ total departments)'
        }
    });

    // Global helper functions
    window.toggleView = function(viewType) {
        const tableView = document.getElementById('tableView');
        const cardView = document.getElementById('cardView');
        const tableViewBtn = document.getElementById('tableViewBtn');
        const cardViewBtn = document.getElementById('cardViewBtn');
        
        if (viewType === 'table') {
            tableView.classList.remove('d-none');
            cardView.classList.add('d-none');
            tableViewBtn.classList.add('active');
            cardViewBtn.classList.remove('active');
        } else {
            tableView.classList.add('d-none');
            cardView.classList.remove('d-none');
            tableViewBtn.classList.remove('active');
            cardViewBtn.classList.add('active');
            loadCardView();
        }
    };

    window.exportToExcel = function() {
        const table = document.querySelector('#departmentsTable');
        const html = table.outerHTML;
        const url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
        const downloadLink = document.createElement('a');
        document.body.appendChild(downloadLink);
        downloadLink.href = url;
        downloadLink.download = 'departments_' + new Date().toISOString().slice(0, 10) + '.xls';
        downloadLink.click();
        document.body.removeChild(downloadLink);
        showAlert('success', 'Export completed successfully');
    };

    window.filterByStatus = function(status) {
        const dt = window.departmentsTable;
        if (!dt) return;

        // The status column holds a rendered badge like
        // "<span ...>Active</span>". Match the label between the tags with a
        // regex so "Active" does not also match "In<b>active</b>".
        let query = '';
        switch (status) {
            case 'active':
                query = '>Active<';
                break;
            case 'inactive':
                query = '>Inactive<';
                break;
            case 'review':
                query = '>Review<';
                break;
            default:
                query = '';
        }
        // regex = true, smart = false
        dt.column(7).search(query, true, false).draw();
    };

}); // End of $(document).ready

// Global action functions

// Toggle the shared department modal between "create" and "edit" appearance.
function setDeptModalMode(mode) {
    const isEdit = mode === 'edit';
    $('#addDepartmentModalLabel').html(isEdit
        ? '<i class="fas fa-edit me-2"></i>Edit Department'
        : '<i class="fas fa-plus-circle me-2"></i>Add New Department');
    $('#saveDeptBtn').html(isEdit
        ? '<i class="fas fa-save me-1"></i>Update Department'
        : '<i class="fas fa-save me-1"></i>Save Department');
    if (!isEdit) {
        $('#department_pk').val('');
    }
}

function openModal() {
    const form = document.getElementById('departmentForm');
    form.reset();
    $(form).removeClass('was-validated');
    $(form).find('.is-valid, .is-invalid').removeClass('is-valid is-invalid');
    setDeptModalMode('create');
    const modal = new bootstrap.Modal(document.getElementById('addDepartmentModal'));
    modal.show();
}

function openHodManagement() {
    const modal = new bootstrap.Modal(document.getElementById('manageHodModal'));
    modal.show();
}

function editDepartment(id) {
    $.getJSON('get_department.php', { id: id })
    .done(function(resp) {
        if (!resp || !resp.success || !resp.data) {
            showAlert('danger', (resp && resp.message) ? resp.message : 'Failed to load department details');
            return;
        }
        const d = resp.data;
        setDeptModalMode('edit');
        $('#department_pk').val(d.id);
        $('#departmentId').val(d.department_code || '');
        $('#departmentName').val(d.department_name || '');
        $('#faculty').val(d.faculty || '');

        // Queue the saved section; the modal's show handler loads the list and
        // applies this value once the options exist (concurrency-safe).
        window.pendingSectionValue = d.section_id || '';

        const modal = new bootstrap.Modal(document.getElementById('addDepartmentModal'));
        modal.show();
    })
    .fail(function(xhr) {
        const errorMsg = xhr.responseJSON && xhr.responseJSON.message
            ? xhr.responseJSON.message
            : 'Failed to load department (' + xhr.status + ')';
        showAlert('danger', errorMsg);
    });
}

function deleteDepartment(id) {
    if (!confirm('Are you sure you want to delete department ' + id + '? This action cannot be undone.')) {
        return;
    }
    
    $.ajax({
        url: 'delete_department.php',
        method: 'POST',
        data: {
            id: id,
            csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
        },
        dataType: 'json',
        timeout: 10000
    })
    .done(function(resp) {
        if (resp && resp.success) {
            showAlert('success', resp.message || 'Department deleted successfully');
            if (window.departmentsTable) {
                window.departmentsTable.ajax.reload(null, false);
            }
        } else {
            showAlert('danger', (resp && resp.message) ? resp.message : 'Failed to delete department');
        }
    })
    .fail(function(xhr) {
        const errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Request failed: ' + xhr.status;
        showAlert('danger', errorMsg);
    });
}

function viewDetails(id) {
    // TODO: Implement view details functionality
    window.location.href = 'department_details.php?id=' + encodeURIComponent(id);
}

function loadCardView() {
    const cardView = document.getElementById('cardView');
    const dt = window.departmentsTable;
    if (!cardView || !dt) return;

    // Render from the rows currently visible (respects search/filter).
    const rows = dt.rows({ search: 'applied' }).data().toArray();
    if (!rows.length) {
        cardView.innerHTML = '<div class="col-12"><div class="alert alert-info mb-0">' +
            '<i class="fas fa-info-circle me-2"></i>No departments to display.</div></div>';
        return;
    }

    let html = '';
    rows.forEach(function(r) {
        const idAttr = String(r.id).replace(/'/g, "\\'");
        html +=
            '<div class="col-md-6 col-xl-4">' +
              '<div class="stat-card h-100">' +
                '<div class="d-flex justify-content-between align-items-start mb-2">' +
                  '<h6 class="fw-bold mb-0">' + r.department_name + '</h6>' +
                  r.status +
                '</div>' +
                '<div class="text-muted small mb-2"><i class="fas fa-hashtag me-1"></i>' + r.department_id +
                  ' &middot; ' + r.faculty + '</div>' +
                '<div class="mb-2"><span class="text-muted small d-block mb-1">Head of Section</span>' + r.hod + '</div>' +
                '<div class="d-flex justify-content-between small text-muted border-top pt-2">' +
                  '<span><i class="fas fa-graduation-cap me-1"></i>' + r.programs + ' programs</span>' +
                  '<span><i class="fas fa-users me-1"></i>' + r.students + ' students</span>' +
                '</div>' +
                '<div class="mt-3 d-flex gap-2">' +
                  '<button class="btn btn-sm btn-info flex-fill" title="View" onclick="viewDetails(\'' + idAttr + '\')"><i class="fas fa-eye"></i></button>' +
                  '<button class="btn btn-sm btn-primary flex-fill" title="Edit" onclick="editDepartment(\'' + idAttr + '\')"><i class="fas fa-edit"></i></button>' +
                  '<button class="btn btn-sm btn-danger flex-fill" title="Delete" onclick="deleteDepartment(\'' + idAttr + '\')"><i class="fas fa-trash"></i></button>' +
                '</div>' +
              '</div>' +
            '</div>';
    });
    cardView.innerHTML = html;
}

function viewPrograms() {
    window.location.href = 'programs.php';
}

// Show alert notification
function showAlert(type, message) {
    const notification = $('#notification');
    const icon = {
        'success': 'fa-check-circle',
        'danger': 'fa-exclamation-circle',
        'warning': 'fa-exclamation-triangle',
        'info': 'fa-info-circle'
    }[type] || 'fa-info-circle';
    
    notification.removeClass().addClass('notification alert alert-' + type);
    notification.html('<i class="fas ' + icon + ' me-2"></i>' + message);
    notification.fadeIn();
    
    setTimeout(function() {
        notification.fadeOut();
    }, 4000);
}

// Show success with action button
function showSuccessWithAction(message, question, buttonText, callback) {
    const notification = $('#notification');
    
    // Change the string building to use single quotes for the inner attribute
    const html = `<div class="d-flex justify-content-between">
    <div><i class="fas fa-check-circle me-2"></i>${message}</div>
    </div>
    <div class="mt-2 border-top pt-2">
    <small class="d-block mb-2">${question}</small>
    <button class="btn btn-sm btn-light me-2" onclick="$('#notification').fadeOut()">
    <i class="fas fa-times me-1"></i>Not Now</button>
    <button class="btn btn-sm btn-success" id="actionBtn">
    <i class="fas fa-plus me-1"></i>${buttonText}</button>
    </div>`;
    
    notification.removeClass().addClass('notification alert alert-success');
    notification.html(html);
    notification.fadeIn();
    
    // Attach callback to action button
    $('#actionBtn').on('click', function() {
        notification.fadeOut();
        callback();
    });
    
    // Auto-hide after 10 seconds
    setTimeout(function() {
        notification.fadeOut();
    }, 10000);
}

// Open quick add program modal
function openQuickAddProgram(deptId, deptCode, deptName) {
    $('#quick_department_id').val(deptId);
    $('#quick_department_code').val(deptCode);
    $('#selectedDeptName').text(deptName);
    
    // Reset the form
    const $form = $('#quickProgramForm')[0];
    $form.reset();
    $($form).removeClass('was-validated');
    $('#addAnotherProgramBtn').prop('disabled', true);
    
    // Open modal
    const modal = new bootstrap.Modal(document.getElementById('quickAddProgramModal'));
    modal.show();
}
</script>

<?php require_once "includes/footer.php"; ?>
