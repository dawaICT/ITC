<?php
$page_title = "Manage Lectures";
require_once "includes/admin.php";
require_once "includes/header.php";
require_once __DIR__ . '/../includes/id_helpers.php';
// Enable error reporting to see what's causing blank page
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Add error handling for database connection
if (!isset($db) || $db->connect_error) {
    die("Database connection failed: " . ($db->connect_error ?? "Database connection not established"));
}

// Fetch all lectures - removed phone column which doesn't exist
$query = "SELECT DISTINCT s.staff_id, s.title, s.Fname, s.Lname, s.sex, s.email,
          GROUP_CONCAT(DISTINCT c.course_code SEPARATOR ', ') as courses
          FROM staff s
          INNER JOIN staff_positions sp ON s.staff_id = sp.staff_id
          INNER JOIN positions p ON sp.PosID = p.PosID
          LEFT JOIN course_lecturer cl ON s.staff_id = cl.staff_id AND cl.status = 'active'
          LEFT JOIN courses c ON cl.course_code = c.course_code
          WHERE p.PosName = 'Lecturer'
          GROUP BY s.staff_id
          ORDER BY s.Fname ASC";
$results = $db->query($query);
if (!$results) {
    die("Query failed: " . $db->error);
}
$lecturers = array();
if($results && $results->num_rows > 0) {
    while($row = $results->fetch_object()) {
        $lecturers[] = $row;
    }
    $results->free();
}

// Handle form submissions
if(isset($_POST['action'])) {
    // admin.php only enforces login + academic-portal access, not the systems_admin
    // role. Creating/editing lecturer accounts is privileged, so gate the mutation
    // handlers behind an administrator (or registrar) even though the list view above
    // stays visible to academic staff.
    require_once __DIR__ . '/../includes/role_helpers.php';
    if (!((isset($isAdmin) && $isAdmin) || hasRole(ROLE_REGISTRAR))) {
        http_response_code(403);
        die('Access denied: managing lecturer accounts requires administrator privileges.');
    }
    $action = $_POST['action'];
    
    if($action == 'add') {
        require_once __DIR__ . '/../includes/helpers/staff_provisioning.php';

        $title = trim((string)($_POST['title'] ?? ''));
        $fname = trim((string)($_POST['fname'] ?? ''));
        $lname = trim((string)($_POST['lname'] ?? ''));
        $sex = trim((string)($_POST['sex'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));

        if ($title === '' || $fname === '' || $lname === '' || $sex === '' || $email === '') {
            die('All lecturer fields are required.');
        }

        $staff_id = generateNextStaffId($db);
        $roleCanonical = 'lecturer';
        $status = 'active';
        $tempHash = password_hash($staff_id, PASSWORD_DEFAULT);

        try {
            $db->begin_transaction();

            $insert = $db->prepare(
                'INSERT INTO staff (staff_id, title, Fname, Lname, sex, email, role, status, password)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$insert) {
                throw new RuntimeException('Failed to prepare staff insert.');
            }
            $insert->bind_param('sssssssss', $staff_id, $title, $fname, $lname, $sex, $email, $roleCanonical, $status, $tempHash);
            if (!$insert->execute()) {
                throw new RuntimeException('Failed to insert lecturer.');
            }
            $insert->close();

            $provision = wuc_provision_staff_account(
                $db,
                $staff_id,
                $roleCanonical,
                $staff_id,
                (string)($_SESSION['staff_id'] ?? 'admin')
            );
            if (!$provision['ok']) {
                throw new RuntimeException('Provisioning incomplete: ' . implode('; ', $provision['messages']));
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            error_log('manage_lectures.php add: ' . $e->getMessage());
            die('Could not create lecturer account.');
        }

        header('Location: manage_lectures.php?success=added&staff_id=' . urlencode($staff_id));
        exit;
    } 
    else if($action == 'edit' && isset($_POST['staff_id'])) {
        // Edit lecturer logic (phone column does not exist in the schema)
        $staff_id = trim((string)($_POST['staff_id'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $fname = trim((string)($_POST['fname'] ?? ''));
        $lname = trim((string)($_POST['lname'] ?? ''));
        $sex = trim((string)($_POST['sex'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if ($staff_id === '') {
            die('Missing staff_id.');
        }

        $update = $db->prepare(
            'UPDATE staff SET title = ?, Fname = ?, Lname = ?, sex = ?, email = ? WHERE staff_id = ?'
        );
        if (!$update) {
            die("Error updating staff: " . $db->error);
        }
        $update->bind_param('ssssss', $title, $fname, $lname, $sex, $email, $staff_id);
        if (!$update->execute()) {
            $updateErr = $update->error;
            $update->close();
            die("Error updating staff: " . $updateErr);
        }
        $update->close();

        // Redirect to refresh the page
        header("Location: manage_lectures.php?success=updated");
        exit;
    }
    else if($action == 'delete' && isset($_POST['staff_id'])) {
        require_once __DIR__ . '/../includes/helpers/staff_provisioning.php';
        require_once __DIR__ . '/../includes/exhibition_mode.php';

        $staff_id = trim((string)($_POST['staff_id'] ?? ''));
        if ($staff_id === '') {
            die('Missing staff_id.');
        }
        try {
            wuc_exhibition_assert_destructive_target($db, $staff_id);
        } catch (DomainException $e) {
            http_response_code(409);
            die($e->getMessage());
        }

        // Full teardown of every login/RBAC/portal/profile row, atomically.
        try {
            $db->begin_transaction();
            $teardown = wuc_deprovision_staff_account($db, $staff_id);
            if (!$teardown['ok']) {
                throw new RuntimeException('Deprovision failed: ' . implode('; ', $teardown['messages']));
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            error_log('manage_lectures.php delete: ' . $e->getMessage());
            die('Could not delete lecturer account.');
        }

        // Redirect to refresh the page
        header("Location: manage_lectures.php?success=deleted");
        exit;
    }
}
?>

<div class="container-fluid px-4">
    <div class="dashboard-header mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Manage Lectures</h1>
                <p class="text-muted">Add, edit, or remove lecturers from the system</p>
            </div>
            <div class="col-auto">
                <a href="add_lecturer.php" class="btn btn-primary btn-lg d-flex align-items-center gap-2 shadow-sm">
                    <i class="fas fa-plus-circle fa-lg"></i> Add New Lecturer
                </a>
            </div>
        </div>
    </div>

    <?php if(isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php 
            if($_GET['success'] == 'added') {
                echo "Lecturer added successfully!";
            } else if($_GET['success'] == 'updated') {
                echo "Lecturer updated successfully!";
            } else if($_GET['success'] == 'deleted') {
                echo "Lecturer deleted successfully!";
            }
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Lecturers Table -->
    <div class="data-table-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-chalkboard-teacher me-2"></i>Lecturers
                </h5>
                <div class="header-actions">
                    <div class="input-group input-group-sm" style="width: auto;">
                        <input type="text" class="form-control" id="quickSearch" 
                               placeholder="Search lecturers...">
                        <button class="btn btn-primary" type="button">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="lecturersTable" class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>No.</th>
                            <th>Staff ID</th>
                            <th>Full Name</th>
                            <th>Gender</th>
                            <th>Email</th>
                            <th>Courses</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $number = 1;
                        foreach($lecturers as $lecturer) {
                            ?>
                            <tr>
                                <td><?php echo $number++; ?>.</td>
                                <td><?php echo $lecturer->staff_id; ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-circle me-2 bg-primary text-white">
                                            <?php echo strtoupper(substr($lecturer->Fname, 0, 1)); ?>
                                        </div>
                                        <div>
                                            <?php echo "{$lecturer->title} {$lecturer->Fname} {$lecturer->Lname}"; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $lecturer->sex == 'M' ? 'info' : 'danger'; ?>">
                                        <?php echo $lecturer->sex == 'M' ? 'Male' : 'Female'; ?>
                                    </span>
                                </td>
                                <td><?php echo $lecturer->email; ?></td>
                                <td>
                                    <?php 
                                    if($lecturer->courses) {
                                        $courses = explode(', ', $lecturer->courses);
                                        foreach($courses as $course) {
                                            echo "<span class='badge bg-primary me-1 mb-1'>{$course}</span>";
                                        }
                                    } else {
                                        echo "<span class='badge bg-secondary'>No courses assigned</span>";
                                    }
                                    ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="editLecturer('<?php echo $lecturer->staff_id; ?>', 
                                                                   '<?php echo $lecturer->title; ?>', 
                                                                   '<?php echo $lecturer->Fname; ?>', 
                                                                   '<?php echo $lecturer->Lname; ?>', 
                                                                   '<?php echo $lecturer->sex; ?>', 
                                                                   '<?php echo $lecturer->email; ?>')"
                                                title="Edit Lecturer">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" 
                                                onclick="deleteLecturer('<?php echo $lecturer->staff_id; ?>', 
                                                                     '<?php echo $lecturer->title . ' ' . $lecturer->Fname . ' ' . $lecturer->Lname; ?>')"
                                                title="Delete Lecturer">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php 
                        }  
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Edit Lecturer Modal -->
<div class="modal fade" id="editLecturerModal" tabindex="-1" aria-labelledby="editLecturerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="editLecturerModalLabel">Edit Lecturer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="manage_lectures.php" method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="staff_id" id="edit_staff_id">
                    
                    <div class="row mb-3">
                        <div class="col-md-2">
                            <label for="edit_title" class="form-label">Title</label>
                            <select class="form-select" id="edit_title" name="title" required>
                                <option value="">Select</option>
                                <option value="Dr.">Dr.</option>
                                <option value="Prof.">Prof.</option>
                                <option value="Mr.">Mr.</option>
                                <option value="Mrs.">Mrs.</option>
                                <option value="Ms.">Ms.</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label for="edit_fname" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="edit_fname" name="fname" required>
                        </div>
                        <div class="col-md-5">
                            <label for="edit_lname" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="edit_lname" name="lname" required>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="edit_sex" class="form-label">Gender</label>
                            <select class="form-select" id="edit_sex" name="sex" required>
                                <option value="">Select</option>
                                <option value="M">Male</option>
                                <option value="F">Female</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="edit_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit_email" name="email" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Lecturer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Lecturer Modal -->
<div class="modal fade" id="deleteLecturerModal" tabindex="-1" aria-labelledby="deleteLecturerModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteLecturerModalLabel">Delete Lecturer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="manage_lectures.php" method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="staff_id" id="delete_staff_id">
                    <p>Are you sure you want to delete <strong id="delete_lecturer_name"></strong>?</p>
                    <p class="text-danger">This action cannot be undone and will remove all course assignments for this lecturer.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Lecturer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Enhanced UI Styles */
.dashboard-header {
    background: white;
    padding: 1.5rem;
    border-radius: 0.5rem;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1.5rem;
}

.dashboard-title {
    color: #344767;
    font-size: 1.75rem;
    font-weight: 600;
    margin: 0;
}

.card {
    border: none;
    border-radius: 0.5rem;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1.5rem;
}

.card-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    padding: 1rem 1.5rem;
}

.table th {
    font-weight: 600;
    color: #344767;
}

/* .avatar-circle base → assets/css/dashboard.css */
.avatar-circle { width: 2.5rem; height: 2.5rem; border-radius: 50%; font-size: 1rem; }

.badge {
    font-weight: 500;
    padding: 0.5em 0.75em;
    font-size: 0.75rem;
}

.btn-group .btn {
    padding: 0.25rem 0.5rem;
}

.search-box .input-group {
    width: 300px;
}

.search-box .form-control {
    border-right: none;
    padding: 0.5rem 1rem;
}

.search-box .btn {
    border-left: none;
    background: white;
    color: #4154f1;
}

.search-box .btn:hover {
    background: #4154f1;
    color: white;
}

/* Modal Enhancements */
.modal-header {
    border-bottom: none;
}

.modal-footer {
    border-top: none;
}

.modal-content {
    border: none;
    border-radius: 0.5rem;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .search-box .input-group {
        width: 100%;
    }
    
    .dashboard-header {
        flex-direction: column;
    }
    
    .header-actions {
        margin-top: 1rem;
    }
}
</style>

<script>
$(document).ready(function(){
    // Initialize DataTable
    $('#lecturersTable').DataTable({
        pageLength: 10,
        order: [[2, "asc"]],
        responsive: true,
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
        language: {
            search: "",
            searchPlaceholder: "Search lecturers...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ lecturers",
            paginate: {
                first: '<i class="fas fa-angle-double-left"></i>',
                last: '<i class="fas fa-angle-double-right"></i>',
                next: '<i class="fas fa-angle-right"></i>',
                previous: '<i class="fas fa-angle-left"></i>'
            }
        }
    });

    // Quick search functionality
    $('#quickSearch').on('keyup', function() {
        $('#lecturersTable').DataTable().search(this.value).draw();
    });
    
    // Initialize tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();
    
    // Add animation to table rows
    $('#lecturersTable tbody tr').addClass('fade-in');
    
    // Initialize Bootstrap modals
    const editLecturerModal = new bootstrap.Modal(document.getElementById('editLecturerModal'));
    const deleteLecturerModal = new bootstrap.Modal(document.getElementById('deleteLecturerModal'));
    
    // Add click event for the Edit Lecturer button
    document.querySelector('[data-bs-target="#editLecturerModal"]').addEventListener('click', function(e) {
        e.preventDefault();
        editLecturerModal.show();
    });
});

// Function to edit lecturer
function editLecturer(staffId, title, fname, lname, sex, email) {
    $('#edit_staff_id').val(staffId);
    $('#edit_title').val(title);
    $('#edit_fname').val(fname);
    $('#edit_lname').val(lname);
    $('#edit_sex').val(sex);
    $('#edit_email').val(email);
    
    const editLecturerModal = new bootstrap.Modal(document.getElementById('editLecturerModal'));
    editLecturerModal.show();
}

// Function to delete lecturer
function deleteLecturer(staffId, lecturerName) {
    $('#delete_staff_id').val(staffId);
    $('#delete_lecturer_name').text(lecturerName);
    
    const deleteLecturerModal = new bootstrap.Modal(document.getElementById('deleteLecturerModal'));
    deleteLecturerModal.show();
}

// Add animation class
document.addEventListener('DOMContentLoaded', function() {
    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
    `;
    document.head.appendChild(style);
});
</script>

<?php require_once "includes/footer.php"; ?> 
