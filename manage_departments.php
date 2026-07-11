<?php
include "includes/lecturer.php";
require "includes/header.php";

error_reporting(0);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Departments - ITC Portal</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <?php include 'includes/lecturer_sidebar.php'; ?>

        <!-- Main Content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Manage Departments</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
                        <i class="fas fa-plus"></i> Add Department
                    </button>
                </div>
            </div>

            <!-- Departments Table -->
            <div class="table-responsive">
                <table id="departmentsTable" class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Department ID</th>
                            <th>Department Name</th>
                            <th>Faculty</th>
                            <th>Total Students</th>
                            <th>Total Faculty</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $query = "SELECT d.department_id, d.department_name, d.faculty, d.status,
                                         (SELECT COUNT(DISTINCT sp.Sid) FROM student_program sp
                                          JOIN programs p ON sp.program_code = p.program_code
                                          WHERE p.department_id = d.department_id) AS total_students,
                                         (SELECT COUNT(DISTINCT ssa.staff_id) FROM staff_section_assignments ssa
                                          JOIN sections sec ON ssa.section_id = sec.section_id
                                          WHERE sec.department_id = d.department_id AND ssa.status = 'active') AS total_faculty
                                  FROM departments d
                                  ORDER BY d.department_name";
                        $result = mysqli_query($db, $query);

                        while ($row = mysqli_fetch_assoc($result)) {
                            $deptId = htmlspecialchars($row['department_id']);
                            echo "<tr>";
                            echo "<td>" . $deptId . "</td>";
                            echo "<td>" . htmlspecialchars($row['department_name']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['faculty'] ?? '') . "</td>";
                            echo "<td>" . htmlspecialchars($row['total_students']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['total_faculty']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['status']) . "</td>";
                            echo "<td>
                                    <button class='btn btn-sm btn-info' onclick='viewDepartment(\"" . $deptId . "\")'>
                                        <i class='fas fa-eye'></i>
                                    </button>
                                    <button class='btn btn-sm btn-primary' onclick='editDepartment(\"" . $deptId . "\")'>
                                        <i class='fas fa-edit'></i>
                                    </button>
                                  </td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Add Department Modal -->
<div class="modal fade" id="addDepartmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Department</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addDepartmentForm" method="POST" action="process_department.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="deptId" class="form-label">Department ID</label>
                        <input type="text" class="form-control" id="deptId" name="deptId" required>
                    </div>
                    <div class="mb-3">
                        <label for="deptName" class="form-label">Department Name</label>
                        <input type="text" class="form-control" id="deptName" name="deptName" required>
                    </div>
                    <div class="mb-3">
                        <label for="faculty" class="form-label">Faculty</label>
                        <input type="text" class="form-control" id="faculty" name="faculty" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Add Department</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Department Modal -->
<div class="modal fade" id="editDepartmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Department</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editDepartmentForm" method="POST" action="process_department.php">
                <input type="hidden" name="edit" value="true">
                <input type="hidden" id="editDeptId" name="deptId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="editDeptName" class="form-label">Department Name</label>
                        <input type="text" class="form-control" id="editDeptName" name="deptName" required>
                    </div>
                    <div class="mb-3">
                        <label for="editFaculty" class="form-label">Faculty</label>
                        <input type="text" class="form-control" id="editFaculty" name="faculty" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Department Modal -->
<div class="modal fade" id="viewDepartmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Department Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="departmentDetails"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#departmentsTable').DataTable({
        "order": [[1, "asc"]],
        "pageLength": 10
    });
});

function viewDepartment(deptId) {
    $.ajax({
        url: 'get_department.php',
        type: 'POST',
        data: { deptId: deptId },
        dataType: 'json',
        success: function(data) {
            if (!data || !data.department_id) {
                $('#departmentDetails').html('<div class="alert alert-warning mb-0">Department not found.</div>');
            } else {
                const esc = (v) => $('<div>').text(v == null ? '' : v).html();
                $('#departmentDetails').html(
                    '<div class="table-responsive"><table class="table table-hover align-middle mb-0">' +
                    '<tr><th>Department ID</th><td>' + esc(data.department_id) + '</td></tr>' +
                    '<tr><th>Department Name</th><td>' + esc(data.department_name) + '</td></tr>' +
                    '<tr><th>Faculty</th><td>' + esc(data.faculty) + '</td></tr>' +
                    '<tr><th>Total Students</th><td>' + esc(data.total_students) + '</td></tr>' +
                    '<tr><th>Total Faculty</th><td>' + esc(data.total_faculty) + '</td></tr>' +
                    '<tr><th>Status</th><td>' + esc(data.status) + '</td></tr>' +
                    '</table></div>'
                );
            }
            $('#viewDepartmentModal').modal('show');
        }
    });
}

function editDepartment(deptId) {
    $.ajax({
        url: 'get_department.php',
        type: 'POST',
        data: { deptId: deptId },
        success: function(response) {
            const data = JSON.parse(response);
            $('#editDeptId').val(data.department_id);
            $('#editDeptName').val(data.department_name);
            $('#editFaculty').val(data.faculty);
            $('#editDepartmentModal').modal('show');
        }
    });
}
</script>

</body>
</html> 