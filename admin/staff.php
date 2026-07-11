<?php
/**
 * Staff Management System
 * 
 * SECURITY NOTES:
 * - Ensure all form submissions check CSRF tokens
 * - All database queries use prepared statements
 * - This page requires HTTPS in production
 * - Database credentials are NOT exposed in HTML
 * 
 * DATABASE REQUIREMENTS:
 * - staff.staff_id (Primary Key)
 * - staff.deptId (Foreign Key → departments.deptId with ON DELETE SET NULL)
 * - staff.sex (ENUM: 'Male', 'Female', 'Other')
 * - staff.email (UNIQUE constraint)
 * - departments.department_name (Indexed for JOIN performance)
 * - access_right.assigned_access (Role management)
 * 
 * @version 2.0
 * @updated 2026-02-03
 */

$page_title = "Staff Management";
require "includes/admin.php";
require_once "../config/auth_check.php";
checkAdminAuth();
require_once "../includes/role_helpers.php";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require 'includes/header.php';

// Link admin dashboard stylesheets
?>

<style>
    /* Page-specific — shared components in assets/css/dashboard.css */
    .staff-stat-col { min-width: 0; }
    @media (max-width: 575.98px) {
        .staff-stat-col { width: 100%; flex: 0 0 100%; }
    }
</style>
<?php
?>


<?php
// Debug: Check database connection
if (!$db->ping()) {
    die("Database connection lost. Please check your database server.");
}

// Debug: Check if tables exist
$tables_to_check = ['staff', 'departments'];
foreach ($tables_to_check as $table) {
    $table_check = $db->query("SHOW TABLES LIKE '$table'");
    if ($table_check->num_rows == 0) {
        echo "<div class='alert alert-danger'>ERROR: Table '$table' does not exist!</div>";
    }
}

// Debug: Show table structure
$debug_table_structure = false; // Set to true to see table structures
if ($debug_table_structure) {
    echo "<div class='alert alert-info'><strong>Debug: Table Structures</strong><br>";
    $structure_query = $db->query("DESCRIBE staff");
    while ($col = $structure_query->fetch_assoc()) {
        echo $col['Field'] . " (" . $col['Type'] . ")<br>";
    }
    echo "</div>";
}

// Get staff statistics with error handling.
// Note: staff.status is lowercase 'active', staff.sex stores 'M'/'F' codes.
try {
    $total_staff_result = $db->query("SELECT COUNT(*) as total FROM staff WHERE status = 'active'");
    if (!$total_staff_result) {
        throw new Exception("Query failed: " . $db->error);
    }
    $total_staff = $total_staff_result->fetch_object()->total;
    $total_staff_result->free();

    $male_staff_result = $db->query("SELECT COUNT(*) as total FROM staff WHERE sex='M' AND status = 'active'");
    if (!$male_staff_result) {
        throw new Exception("Query failed: " . $db->error);
    }
    $male_staff = $male_staff_result->fetch_object()->total;
    $male_staff_result->free();

    $female_staff_result = $db->query("SELECT COUNT(*) as total FROM staff WHERE sex='F' AND status = 'active'");
    if (!$female_staff_result) {
        throw new Exception("Query failed: " . $db->error);
    }
    $female_staff = $female_staff_result->fetch_object()->total;
    $female_staff_result->free();

} catch (Exception $e) {
    error_log("Staff stats query error: " . $e->getMessage());
    echo "<div class='alert alert-danger'>Unable to load staff statistics. Please try again later.</div>";
    $total_staff = $male_staff = $female_staff = 0;
}

// Database configuration - using confirmed column names
$dept_name_column = 'department_name'; // Confirmed correct column name
$dept_pk_column = 'id'; // Primary key in departments table (column is `id`)

// Get staff records with error handling
$records = [];
$departments = [];

try {
    // Get departments for filter dropdown
    $dept_results = $db->query("SELECT {$dept_pk_column} as deptId, {$dept_name_column} as deptName FROM departments ORDER BY {$dept_name_column}");
    if (!$dept_results) {
        throw new Exception("Departments query failed: " . $db->error);
    }

    while($row = $dept_results->fetch_object()){
        $departments[] = $row;
    }
    $dept_results->free();

    // Get staff records. Department comes straight from staff.deptId →
    // departments.id. (Earlier revisions joined staff_section_assignments →
    // sections → departments, but neither of those tables exists in this DB.)
    $staff_query = "SELECT staff.*,
                       d.{$dept_name_column} as deptName,
                       COALESCE(access_right.assigned_access, 'Staff') as role
                   FROM staff
                   LEFT JOIN departments d ON d.{$dept_pk_column} = staff.deptId
                   LEFT JOIN access_right ON access_right.staff_id = staff.staff_id
                   WHERE staff.status = 'active'
                   GROUP BY staff.staff_id
                   ORDER BY staff.Fname, staff.Lname";

    $results = $db->query($staff_query);
    if (!$results) {
        throw new Exception("Staff query failed: " . $db->error);
    }

    if($results->num_rows > 0) {
        while($row = $results->fetch_object()){
            $records[] = $row;
        }
    }
    $results->free();

} catch (Exception $e) {
    error_log("Staff list query error: " . $e->getMessage());
    echo "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-2'></i>Unable to load staff records. Please try again later.</div>";
    $records = [];
}

// Debug information is shown in the statistics section

// Data Integrity Checks
$data_integrity_check = false; // Set to true to run data integrity checks
if ($data_integrity_check) {
    echo "<div class='alert alert-warning'>";
    echo "<strong>Data Integrity Check:</strong><br>";

    // Check for staff without departments
    $orphaned_staff = $db->query("SELECT COUNT(*) as count FROM staff WHERE deptId IS NOT NULL AND deptId NOT IN (SELECT id FROM departments)");
    if ($orphaned_staff) {
        $count = $orphaned_staff->fetch_object()->count;
        echo "Staff without valid departments: $count<br>";
        $orphaned_staff->free();
    }

    // Check for missing required fields
    $missing_emails = $db->query("SELECT COUNT(*) as count FROM staff WHERE email IS NULL OR email = ''");
    if ($missing_emails) {
        $count = $missing_emails->fetch_object()->count;
        echo "Staff with missing emails: $count<br>";
        $missing_emails->free();
    }

    // Check for duplicate staff IDs
    $duplicate_ids = $db->query("SELECT staff_id, COUNT(*) as count FROM staff GROUP BY staff_id HAVING count > 1");
    if ($duplicate_ids && $duplicate_ids->num_rows > 0) {
        echo "Duplicate Staff IDs found:<br>";
        while ($dup = $duplicate_ids->fetch_object()) {
            echo "- {$dup->staff_id} (appears {$dup->count} times)<br>";
        }
        $duplicate_ids->free();
    }

    echo "</div>";
}
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-user-tie me-2 text-primary"></i>Staff Management</h5>
                <p class="page-subtitle mb-0">Manage institutional personnel, departments, and access roles</p>
            </div>
            <div class="header-actions">
                <a href="index.php" class="btn btn-outline-primary shadow-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-xl-4 col-sm-4 col-12 staff-stat-col">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3 text-white"><i class="fas fa-users"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($total_staff) ?></h3>
                        <p class="text-muted mb-0">Active Staff</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-sm-4 col-12 staff-stat-col">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3 text-white"><i class="fas fa-mars"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($male_staff) ?></h3>
                        <p class="text-muted mb-0">Male Members</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-sm-4 col-12 staff-stat-col">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3 text-white"><i class="fas fa-venus"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($female_staff) ?></h3>
                        <p class="text-muted mb-0">Female Members</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
                            <button id="btnAddStaff" class="btn btn-outline-primary w-100 action-btn-card">
                                <i class="fas fa-plus"></i>
                                <span>Add Staff</span>
                            </button>
                        </div>
                        <div class="col-md-4">
                            <button id="btnCreateAccount" class="btn btn-outline-success w-100 action-btn-card">
                                <i class="fas fa-user-plus"></i>
                                <span>Create Account</span>
                            </button>
                        </div>
                        <div class="col-md-4">
                            <button id="btnResetPassword" class="btn btn-outline-warning w-100 action-btn-card">
                                <i class="fas fa-unlock"></i>
                                <span>Reset Password</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if(isset($_SESSION['successMssg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['successMssg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['successMssg']); ?>
    <?php endif; ?>
    <?php if(isset($_SESSION['errorMssg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['errorMssg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['errorMssg']); ?>
    <?php endif; ?>

    <!-- Librarian Assignment Section -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="card" style="border: none; background: #f8f9fa;">
                <div class="card-body py-2">
                    <form id="librarianForm" method="post" action="scripts/assign_librarian.php">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small mb-1">Staff ID</label>
                                <input type="text" name="staff_id" class="form-control form-control-sm" placeholder="Enter Staff ID" required>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-plus"></i> Assign
                                </button>
                            </div>
                            <div class="col-md-7">
                                <small class="text-muted">Grant Librarian role (LIB001) for library access</small>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Validation Messages -->
    <?php if(empty($records) && $total_staff > 0): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Data Issue Detected:</strong> Statistics show <?php echo $total_staff; ?> staff members,
            but no records were retrieved. This may indicate a problem with the JOIN query or data relationships.
        </div>
    <?php elseif(empty($departments)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Data Issue Detected:</strong> No departments found in the database.
            Staff records cannot be properly displayed without department information.
        </div>
    <?php endif; ?>

    <?php if(!empty($records)): ?>
        <!-- Staff Filters -->
        <div class="card mb-3" style="border: none; background: #f8f9fa;">
            <div class="card-body py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Department</label>
                        <select id="departmentFilter" class="form-select form-select-sm">
                            <option value="">All Departments</option>
                            <?php
                            foreach($departments as $dept) {
                                echo '<option value="'.htmlspecialchars($dept->deptName).'">'.htmlspecialchars($dept->deptName).'</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Role</label>
                        <select id="roleFilter" class="form-select form-select-sm">
                            <option value="">All Roles</option>
                            <?php foreach (getAvailableRoles() as $displayName => $roleValue): ?>
                            <option value="<?php echo htmlspecialchars($displayName); ?>"><?php echo htmlspecialchars($displayName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Gender</label>
                        <select id="genderFilter" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Search</label>
                        <input type="text" id="searchFilter" class="form-control form-control-sm" placeholder="Name or ID...">
                    </div>
                    <div class="col-md-3">
                        <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-outline-primary" onclick="applyFilters()">
                                <i class="fas fa-search"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="clearFilters()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="data-table-card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-users me-2"></i>Staff Profiles
                    </h5>
                    <div class="header-actions">
                        <button class="btn btn-success btn-sm" onclick="exportToExcel()">
                            <i class="fas fa-file-excel me-2"></i>Export
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                            <i class="fas fa-print me-2"></i>Print
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table id="staffTable" class="table table-hover align-middle mb-0" style="width:100%">
                        <thead class="table-light sticky-top">
                            <tr class="text-uppercase small fw-bold border-bottom border-2">
                                <th class="ps-4">Staff ID</th>
                                <th>Staff Member</th>
                                <th>Department</th>
                                <th>Role</th>
                                <th>Gender</th>
                                <th>Contact Info</th>
                                <th class="text-center pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        foreach($records as $r):
                        ?>
                            <?php
                            // Normalise sex to a display label. staff.sex stores 'M'/'F'.
                            $sexLabel = $r->sex === 'M' ? 'Male' : ($r->sex === 'F' ? 'Female' : 'Other');
                            $isMale = $r->sex === 'M';
                            ?>
                            <tr class="transition-effect">
                                <td class="ps-4">
                                    <span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($r->staff_id); ?></span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-circle me-3">
                                            <?php echo strtoupper(substr($r->Fname, 0, 1) . substr($r->Lname, 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars(trim(($r->title ?? '').' '.$r->Fname.' '.$r->Lname)); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($r->email ?? ''); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-success"><?php echo htmlspecialchars($r->deptName ?? ($r->deptId ?? 'Unassigned')); ?></span>
                                </td>
                                <td>
                                    <span class="badge <?php echo getRoleBadgeClass($r->role); ?>">
                                        <?php echo htmlspecialchars(getRoleDisplayName($r->role)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($isMale): ?>
                                        <span class="badge bg-blue-subtle text-primary border border-primary-subtle rounded-pill">
                                            <i class="fas fa-mars me-1"></i>Male
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-pink-subtle text-danger border border-danger-subtle rounded-pill">
                                            <i class="fas fa-venus me-1"></i><?php echo htmlspecialchars($sexLabel); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small">
                                        <div><i class="fas fa-envelope text-primary me-1"></i><?php echo htmlspecialchars($r->email ?? ''); ?></div>
                                        <div><i class="fas fa-mobile-alt text-success me-1"></i><?php echo htmlspecialchars($r->mobile ?? '—'); ?></div>
                                    </div>
                                </td>
                                <td class="text-center pe-4">
                                    <div class="btn-group btn-group-sm action-btns" role="group" aria-label="Staff actions">
                                        <a href="view_staff.php?view=<?php echo $r->staff_id?>"
                                           class="btn btn-outline-secondary"
                                           title="View Bio Data" aria-label="View Bio Data">
                                            <i class="fas fa-eye"></i><span class="d-none d-lg-inline ms-1">View</span>
                                        </a>
                                        <a href="editStaff.php?update=<?php echo $r->staff_id?>"
                                           class="btn btn-outline-primary"
                                           title="Edit Staff" aria-label="Edit Staff">
                                            <i class="fas fa-pen"></i><span class="d-none d-lg-inline ms-1">Edit</span>
                                        </a>
                                        <a href="deleteStaff.php?del=<?php echo $r->staff_id?>"
                                           class="btn btn-outline-danger"
                                           title="Delete Staff" aria-label="Delete Staff"
                                           onclick="return confirm('Are you sure you want to delete this staff from the system completely?')">
                                            <i class="fas fa-trash-alt"></i><span class="d-none d-lg-inline ms-1">Delete</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>No Staff Records Found</strong><br>
            <?php if ($total_staff > 0): ?>
                There are <?php echo $total_staff; ?> staff members in the database, but they could not be displayed.
                This may be due to missing department relationships or data corruption.
                <br><br>
                <a href="debug_database.php" target="_blank" class="btn btn-sm btn-outline-info">
                    <i class="fas fa-bug me-1"></i>Run Database Debug
                </a>
            <?php else: ?>
                The staff table appears to be empty. You can add staff members using the "Add Staff" button above.
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Add Staff Modal (Bootstrap) -->
<div class="modal fade" id="addStaffModal" tabindex="-1" aria-labelledby="addStaffModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-white">
        <h5 class="modal-title" id="addStaffModalLabel">Add New Staff</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="add_staff.php" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label for="staff_id" class="form-label">Staff ID</label>
              <input type="text" class="form-control" id="staff_id" value="Auto-generated (ITC001, ITC002...)" disabled>
              <div class="form-text">The system assigns the next available ITC staff number when you save.</div>
            </div>
            <div class="col-md-4">
              <label for="deptId" class="form-label">Department</label>
              <select class="form-select" id="deptId" name="deptId" required>
                <option value="" selected disabled>Select</option>
                <?php
                $dept_modal_results = $db->query("SELECT id as deptId, department_name as deptName FROM departments ORDER BY department_name");
                if ($dept_modal_results) {
                    while($row = $dept_modal_results->fetch_object()){
                        echo '<option value="'.htmlspecialchars($row->deptId).'">'.htmlspecialchars($row->deptName).'</option>';
                    }
                    $dept_modal_results->free();
                }
                ?>
              </select>
            </div>
            <div class="col-md-4">
              <label for="role" class="form-label">Role</label>
              <select class="form-select" id="role" name="role" required>
                <?php foreach (getAvailableRoles() as $displayName => $roleValue): ?>
                <option value="<?php echo htmlspecialchars($roleValue); ?>"><?php echo htmlspecialchars($displayName); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label for="title" class="form-label">Title</label>
              <select class="form-select" id="title" name="title" required>
                <option value="Mr.">Mr.</option>
                <option value="Mrs.">Mrs.</option>
                <option value="Ms.">Ms.</option>
                <option value="Mss.">Mss.</option>
                <option value="Dr.">Dr.</option>
                <option value="Prof.">Prof.</option>
                <option value="Sir.">Sir.</option>
                <option value="Eng.">Eng.</option>
              </select>
            </div>

            <div class="col-md-6">
              <label for="Fname" class="form-label">First Name</label>
              <input type="text" class="form-control" id="Fname" name="Fname" required autocomplete="off">
            </div>
            <div class="col-md-6">
              <label for="Lname" class="form-label">Last Name</label>
              <input type="text" class="form-control" id="Lname" name="Lname" required autocomplete="off">
            </div>

            <div class="col-md-4">
              <label for="sex" class="form-label">Gender</label>
              <select class="form-select" id="sex" name="sex" required>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
              </select>
            </div>
            <div class="col-md-4">
              <label for="nrc_pass" class="form-label">NRC/Passport #</label>
              <input type="text" class="form-control" id="nrc_pass" name="nrc_pass" required autocomplete="off">
            </div>
            <div class="col-md-4">
              <label for="mobile" class="form-label">Mobile</label>
              <input type="text" class="form-control" id="mobile" name="mobile" required autocomplete="off">
            </div>

            <div class="col-md-6">
              <label for="email" class="form-label">Email</label>
              <input type="email" class="form-control" id="email" name="email" autocomplete="off" required>
            </div>
            <div class="col-md-6">
              <label for="country" class="form-label">Country</label>
              <select class="form-select" id="country" name="country" required>
                <option value="" disabled selected>Select country</option>
                <?php
                // Reuse a minimal country list or keep empty to rely on server validation.
                $countries = [
                    'Zambia','Zimbabwe','South Africa','Botswana','Namibia','Kenya','Uganda','Tanzania','Malawi','Mozambique'
                ];
                foreach($countries as $c){
                    echo '<option value="'.htmlspecialchars($c).'">'.htmlspecialchars($c).'</option>';
                }
                ?>
              </select>
            </div>

            <div class="col-md-12">
              <label for="address" class="form-label">Home Address</label>
              <input type="text" class="form-control" id="address" name="address" required autocomplete="off">
            </div>

            <div class="col-md-12">
              <label for="qualification" class="form-label">Professional Qualification(s)</label>
              <input type="text" class="form-control" id="qualification" name="qualification" value="optional" autocomplete="off">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Staff</button>
        </div>
      </form>
    </div>
  </div>
  
</div>

<!-- Create Staff Account Modal (Bootstrap) -->
<div class="modal fade" id="createStaffAccountModal" tabindex="-1" aria-labelledby="createStaffAccountModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-white">
        <h5 class="modal-title" id="createStaffAccountModalLabel">Create Staff Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="createAccount.php" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <div class="modal-body">
          <div class="mb-3">
            <label for="account_staff_id" class="form-label">Staff ID</label>
            <input type="text" class="form-control" id="account_staff_id" name="staff_id" required autocomplete="off">
          </div>
          <div class="mb-3">
            <label for="account_pass" class="form-label">Password</label>
            <input type="password" class="form-control" id="account_pass" name="pass" required minlength="8" autocomplete="off">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">Create Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Reset Staff Password Modal (Bootstrap) -->
<div class="modal fade" id="resetStaffPasswordModal" tabindex="-1" aria-labelledby="resetStaffPasswordModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-white">
        <h5 class="modal-title" id="resetStaffPasswordModalLabel">Reset Staff Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="resetPassword.php" method="post" onsubmit="return validateResetForm()">
        <div class="modal-body">
          <div class="mb-3">
            <label for="reset_staff_id" class="form-label">Staff ID</label>
            <input type="text" class="form-control" id="reset_staff_id" name="staff_id" required autocomplete="off">
          </div>
          <div class="mb-3">
            <label for="reset_pass" class="form-label">New Password</label>
            <input type="password" class="form-control" id="reset_pass" name="pass" required minlength="8" autocomplete="off">
          </div>
          <div class="mb-3">
            <label for="reset_confirm_pass" class="form-label">Confirm Password</label>
            <input type="password" class="form-control" id="reset_confirm_pass" name="confirmPass" required minlength="8" autocomplete="off">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">Reset Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
$(document).ready(function() {
    'use strict';

    // Initialize DataTables
    // Initialize DataTables
    const table = $('#staffTable').DataTable({
        pageLength: 10,
        responsive: true,
        order: [[1, 'asc']], 
        columnDefs: [
            { orderable: false, targets: [6] }, // Actions column
            { className: "dt-nowrap", targets: [0, 4, 6] } // Only keep nowrap on ID, Gender, Actions
        ],
        dom: 'rtip', 
        language: {
            search: '_INPUT_',
            searchPlaceholder: 'Search staff...',
            zeroRecords: 'No staff members match these filters',
            emptyTable: 'No staff available',
            info: 'Showing _START_ to _END_ of _TOTAL_ profiles',
            infoEmpty: 'Showing 0 to 0 of 0 profiles',
            infoFiltered: '(filtered from _MAX_ total profiles)',
            paginate: {
                first: '<i class="fas fa-angle-double-left"></i>',
                last: '<i class="fas fa-angle-double-right"></i>',
                next: '<i class="fas fa-angle-right"></i>',
                previous: '<i class="fas fa-angle-left"></i>'
            }
        }
    });

    /**
     * Enhanced Filter Function
     * Uses Regex for exact matching on dropdowns to prevent partial overlaps
     */
    window.applyFilters = function() {
        const dept = $('#departmentFilter').val();
        const role = $('#roleFilter').val();
        const gender = $('#genderFilter').val();
        const search = $('#searchFilter').val();

        // Exact match for Department. \s* tolerates the whitespace/markup that
        // DataTables keeps around a cell's text so the ^...$ anchors still match.
        table.column(2).search(dept ? '^\\s*' + dept + '\\s*$' : '', true, false);

        // Role: substring match (the dropdown label can be shorter than the
        // rendered display name, e.g. "Systems Admin" vs "Systems Administrator").
        table.column(3).search(role ? role : '', true, false);

        // Exact match for Gender. The cell holds an <i> icon before the label,
        // so a bare ^Male$ never matched — allow surrounding whitespace.
        table.column(4).search(gender ? '^\\s*' + gender + '\\s*$' : '', true, false);
        
        // Global search for the text input
        table.search(search); 
        
        table.draw();
    };

    window.clearFilters = function() {
        $('#departmentFilter, #roleFilter, #genderFilter, #searchFilter').val('');
        table.search('').column(2).search('').column(3).search('').column(4).search('').draw();
    };

    // LIVE FILTERING: Trigger as user interacts
    $('#departmentFilter, #roleFilter, #genderFilter').on('change', applyFilters);
    $('#searchFilter').on('keyup', applyFilters);

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Modal Initializations (using simplified setup)
    const setupModal = (id, btnId) => {
        const el = document.getElementById(id);
        if (el) {
            const instance = new bootstrap.Modal(el);
            document.getElementById(btnId)?.addEventListener('click', (e) => {
                e.preventDefault();
                instance.show();
            });
        }
    };

    setupModal('addStaffModal', 'btnAddStaff');
    setupModal('createStaffAccountModal', 'btnCreateAccount');
    setupModal('resetStaffPasswordModal', 'btnResetPassword');
});

function goToNext() {
    // Navigate to next page or section
    // You can customize this to go to any page you want
    const nextPages = [
        'students_by_admin.php',
        'programs.php',
        'semester.php',
        'applicants.php'
    ];

    // Get current page index
    const currentPage = window.location.pathname.split('/').pop();
    let nextIndex = 0;

    // Find current page in the array
    for (let i = 0; i < nextPages.length; i++) {
        if (currentPage === nextPages[i]) {
            nextIndex = (i + 1) % nextPages.length;
            break;
        }
    }

    // Navigate to next page
    window.location.href = nextPages[nextIndex];
}

/**
 * Clean Export to Excel
 * Removes the 'Actions' column before generating the file
 */
function exportToExcel() {
    // Clone the table to avoid messing with the UI
    const tableClone = document.querySelector('#staffTable').cloneNode(true);
    
    // Remove the 'Actions' header and all action cells in rows
    tableClone.querySelectorAll('tr').forEach(row => {
        if (row.lastElementChild) row.removeChild(row.lastElementChild);
    });

    const html = tableClone.outerHTML;
    const url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
    const downloadLink = document.createElement('a');
    downloadLink.href = url;
    downloadLink.download = 'Staff_Report_' + new Date().toLocaleDateString().replace(/\//g, '-') + '.xls';
    
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
    
    showNotification('Export successful!', 'success');
}

/**
 * Enhanced Notification System
 * Shows animated toast-style notifications
 */
function showNotification(msg, type) {
    const note = document.createElement('div');
    note.className = `notification alert alert-${type}`;
    note.style.animation = 'slideInRight 0.3s ease-out';
    note.innerHTML = `<i class="fas fa-check-circle me-2"></i>${msg}`;
    document.body.appendChild(note);
    setTimeout(() => {
        note.style.animation = 'slideOutRight 0.3s ease-in';
        setTimeout(() => note.remove(), 300);
    }, 3000);
}

function validateResetForm() {
    const p = document.getElementById('reset_pass').value;
    const c = document.getElementById('reset_confirm_pass').value;
    if (p !== c) {
        alert('Passwords do not match');
        return false;
    }
    return true;
}
</script>

<?php 
require_once "includes/footer.php";
?>

