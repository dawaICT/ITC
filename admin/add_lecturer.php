<?php
$page_title = "Add New Lecturer";
require_once "includes/admin.php";
require_once "includes/header.php";
require_once __DIR__ . '/../includes/id_helpers.php';
require_once __DIR__ . '/../includes/role_helpers.php';

// admin.php only enforces login + academic-portal access, not the systems_admin
// role — so require an administrator (or registrar) before this page can mint a
// lecturer staff account. Otherwise any academic-portal user (e.g. a lecturer)
// could create staff accounts.
if (!((isset($isAdmin) && $isAdmin) || hasRole(ROLE_REGISTRAR))) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-4" role="alert"><i class="fas fa-ban me-2"></i>'
        . 'Access denied: creating lecturer accounts requires administrator privileges.</div>';
    require_once "includes/footer.php";
    exit;
}

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Check database connection
if (!isset($db) || $db->connect_error) {
    die("Database connection failed: " . ($db->connect_error ?? "Database connection not established"));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../includes/helpers/staff_provisioning.php';

    $title = trim((string)($_POST['title'] ?? ''));
    $fname = trim((string)($_POST['fname'] ?? ''));
    $lname = trim((string)($_POST['lname'] ?? ''));
    $sex = trim((string)($_POST['sex'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));

    if ($title === '' || $fname === '' || $lname === '' || $sex === '' || $email === '') {
        $error = 'All fields are required.';
    } else {
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
                throw new RuntimeException('Failed to insert lecturer profile.');
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
            header('Location: manage_lectures.php?success=added&staff_id=' . urlencode($staff_id));
            exit;
        } catch (Throwable $e) {
            $db->rollback();
            error_log('add_lecturer.php: ' . $e->getMessage());
            $error = 'Could not create lecturer account. Please try again or contact support.';
        }
    }
}
?>

<div class="container-fluid px-4">
    <div class="dashboard-header mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Add New Lecturer</h1>
                <p class="text-muted">Enter the details of the new lecturer</p>
            </div>
            <div class="col-auto">
                <a href="manage_lectures.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Lecturers
                </a>
            </div>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form action="add_lecturer.php" method="post" class="needs-validation" novalidate>
                <div class="row mb-3">
                    <div class="col-md-2">
                        <label for="title" class="form-label">Title</label>
                        <select class="form-select" id="title" name="title" required>
                            <option value="">Select</option>
                            <option value="Dr.">Dr.</option>
                            <option value="Prof.">Prof.</option>
                            <option value="Mr.">Mr.</option>
                            <option value="Mrs.">Mrs.</option>
                            <option value="Ms.">Ms.</option>
                        </select>
                        <div class="invalid-feedback">Please select a title</div>
                    </div>
                    <div class="col-md-5">
                        <label for="fname" class="form-label">First Name</label>
                        <input type="text" class="form-control" id="fname" name="fname" required>
                        <div class="invalid-feedback">Please enter first name</div>
                    </div>
                    <div class="col-md-5">
                        <label for="lname" class="form-label">Last Name</label>
                        <input type="text" class="form-control" id="lname" name="lname" required>
                        <div class="invalid-feedback">Please enter last name</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label for="sex" class="form-label">Gender</label>
                        <select class="form-select" id="sex" name="sex" required>
                            <option value="">Select</option>
                            <option value="M">Male</option>
                            <option value="F">Female</option>
                        </select>
                        <div class="invalid-feedback">Please select gender</div>
                    </div>
                    <div class="col-md-4">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                        <div class="invalid-feedback">Please enter a valid email address</div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Add Lecturer
                        </button>
                        <a href="manage_lectures.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Form validation
(function () {
    'use strict'
    var forms = document.querySelectorAll('.needs-validation')
    Array.prototype.slice.call(forms).forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault()
                event.stopPropagation()
            }
            form.classList.add('was-validated')
        }, false)
    })
})()
</script>

<?php require_once "includes/footer.php"; ?> 
