<?php
include "includes/admin.php";
error_reporting(0);
?>

<!-- Sidebar -->
<div class="w3-sidebar w3-bar-block w3-collapse w3-card w3-animate-left bg-primary" style="width:200px;" id="mySidebar">
    <button class="w3-bar-item w3-button w3-large w3-hide-large" onclick="w3_close()">Close &times;</button>
    <div class="w3-container bg-primary">
        <h4 class="w3-bar-item text-white">ITC Portal</h4>
    </div>

    <a href="index.php" class="w3-bar-item w3-button text-white">
        <i class="fas fa-home"></i> Dashboard
    </a>
    <a href="students.php" class="w3-bar-item w3-button text-white">
        <i class="fas fa-user-graduate"></i> Students
    </a>
    <a href="staff.php" class="w3-bar-item w3-button text-white">
        <i class="fas fa-chalkboard-teacher"></i> Staff
    </a>
    <a href="departments.php" class="w3-bar-item w3-button text-white w3-blue">
        <i class="fas fa-building"></i> Departments
    </a>
    <a href="programs.php" class="w3-bar-item w3-button text-white">
        <i class="fas fa-graduation-cap"></i> Programs
    </a>
    <a href="courses.php" class="w3-bar-item w3-button text-white">
        <i class="fas fa-book"></i> Courses
    </a>
    <a href="semester.php" class="w3-bar-item w3-button text-white">
        <i class="fas fa-calendar-alt"></i> Semester
    </a>
    <a href="payments.php" class="w3-bar-item w3-button text-white">
        <i class="fas fa-money-bill"></i> Payments
    </a>
    <a href="settings.php" class="w3-bar-item w3-button text-white">
        <i class="fas fa-cog"></i> Settings
    </a>
    <a href="../logout.php?to=staff" class="w3-bar-item w3-button text-white">
        <i class="fas fa-sign-out-alt"></i> Logout
    </a>
</div>

<!-- Main content -->
<div class="w3-main" style="margin-left:200px">
    <div class="w3-container">
        <button class="w3-button w3-xlarge w3-hide-large" onclick="w3_open()">☰</button>
        <div class="row">
            <div class="col-sm-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4>Manage Departments</h4>
                        <button class="btn btn-success" data-toggle="modal" data-target="#addDepartmentModal">
                            <i class="fas fa-plus"></i> Add Department
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Department ID</th>
                                        <th>Department Name</th>
                                        <th>Faculty</th>
                                        <th>Head of Section</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $vcDeptSql = "SELECT d.department_id, d.department_name, d.faculty,
                                                         CONCAT(s.Fname, ' ', s.Lname) AS hod_name
                                                  FROM departments d
                                                  LEFT JOIN staff s ON d.hod_id = s.staff_id
                                                  ORDER BY d.department_name";
                                    $vcDeptResult = mysqli_query($db, $vcDeptSql);
                                    if ($vcDeptResult && mysqli_num_rows($vcDeptResult) > 0) {
                                        while ($vcDept = mysqli_fetch_assoc($vcDeptResult)) {
                                            $hod = trim($vcDept['hod_name'] ?? '');
                                            echo "<tr>";
                                            echo "<td>" . htmlspecialchars($vcDept['department_id']) . "</td>";
                                            echo "<td>" . htmlspecialchars($vcDept['department_name']) . "</td>";
                                            echo "<td>" . htmlspecialchars($vcDept['faculty'] ?? '') . "</td>";
                                            echo "<td>" . ($hod !== '' ? htmlspecialchars($hod) : "<span class='text-muted'>Not assigned</span>") . "</td>";
                                            echo "</tr>";
                                        }
                                    } else {
                                        echo "<tr><td colspan='4' class='text-center'>No departments found.</td></tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Department Modal -->
<div class="modal fade" id="addDepartmentModal" tabindex="-1" role="dialog" aria-labelledby="addDepartmentModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addDepartmentModalLabel">Add New Department</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="addDepartmentForm">
                    <div class="form-group">
                        <label for="departmentName">Department Name</label>
                        <input type="text" class="form-control" id="departmentName" required>
                    </div>
                    <div class="form-group">
                        <label for="hodName">Head of Section</label>
                        <select class="form-control" id="hodName" required>
                            <option value="">Select Head of Section</option>
                            <!-- Staff members will be loaded here -->
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save Department</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Additional Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
/* Sidebar Styles */
.bg-primary {
    background-color: #6200ea !important;
}

.text-white {
    color: white !important;
}

.w3-sidebar {
    height: 100%;
    position: fixed;
    overflow: auto;
}

.w3-sidebar .w3-bar-item {
    padding: 12px 16px;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: background-color 0.3s;
}

.w3-sidebar .w3-bar-item:hover {
    background-color: rgba(255, 255, 255, 0.1) !important;
    text-decoration: none;
}

.w3-sidebar .w3-bar-item i {
    width: 20px;
    text-align: center;
}

/* Card Styles */
.card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 15px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.card-header {
    padding: 1rem;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-body {
    padding: 1rem;
}

/* Table Styles */
.table {
    margin-bottom: 0;
}

.table th {
    border-top: none;
    font-weight: 600;
}

/* Button Styles */
.btn {
    font-size: 14px;
}

/* Modal Styles */
.modal-content {
    border-radius: 10px;
}

.modal-header {
    background: #f8f9fa;
    border-radius: 10px 10px 0 0;
}

.form-group {
    margin-bottom: 1rem;
}

.form-control {
    font-size: 14px;
}

/* Responsive Sidebar */
@media (max-width: 768px) {
    .w3-main {
        margin-left: 0;
    }

    .w3-sidebar {
        display: none;
    }

    .w3-sidebar.show {
        display: block;
        width: 100%;
        z-index: 999;
    }
}
</style>

<script>
function w3_open() {
    document.getElementById("mySidebar").style.display = "block";
}

function w3_close() {
    document.getElementById("mySidebar").style.display = "none";
}
</script>
