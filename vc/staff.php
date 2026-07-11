<?php
require "includes/admin.php";
include 'add_staff.php';
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
    <a href="staff.php" class="w3-bar-item w3-button text-white w3-blue">
        <i class="fas fa-chalkboard-teacher"></i> Staff
    </a>
    <a href="departments.php" class="w3-bar-item w3-button text-white">
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
        <button class="w3-button w3-xlarge w3-hide-large" onclick="w3_open()">â˜°</button>
        <div class="row">
            <div class="col-sm-12">
                <div class="row">
                    <h3>Staff</h3>
                    <button class="w3-btn w3-round w3-green w3-left" onclick="document.getElementById('staffAccount').style.display='block'">
                        <i class="fas fa-user-plus"></i> Create staff account
                    </button>
                    <button class="w3-btn w3-round w3-green w3-right" onclick="document.getElementById('staff').style.display='block'">
                        <i class="fas fa-plus"></i> Add staff
                    </button>
                    <hr>
                    <div class="table-responsive">
                        <table id="myTable" class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>No.</th>
                                    <th>Staff ID</th>
                                    <th>Full name</th>
                                    <th>Gender</th>
                                    <th>Designation</th>
                                    <th>Department</th>
                                    <th class="w3-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $number = 1;
                                if($results = $db->query("SELECT * FROM staff")) {
                                    if($count = $results->num_rows) {
                                        while($row = $results->fetch_object()){
                                            $records[] = $row;
                                        }
                                        $results->free();
                                    }
                                }
                                foreach($records as $r) {
                                ?>
                                <tr>
                                    <td><?php echo $number++; ?>.</td>
                                    <td><?php echo ($r->staff_id); ?></td>
                                    <td><?php echo ($r->title); ?> <?php echo ($r->Fname); ?> <?php echo ($r->Lname); ?></td>
                                    <td><?php echo ($r->sex); ?></td>
                                    <td><?php echo ($r->designation); ?></td>
                                    <td><?php echo ($r->depart_name); ?></td>
                                    <td class="w3-center">
                                        <a class='btn w3-green' href="view_staff.php?view=<?php echo $r->staff_id?>">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a class='btn w3-blue' href="#?view=<?php echo $r->SID?>">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a class='btn w3-red' href="#?view=<?php echo $r->SID?>">
                                            <i class="fas fa-trash"></i>
                                        </a>
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
    </div>
</div>

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

/* Table Styles */
.table-responsive {
    margin-top: 20px;
}

.table {
    margin-bottom: 0;
}

.table th {
    font-weight: 600;
}

/* Button Styles */
.btn {
    font-size: 14px;
    margin: 0 2px;
}

.btn i {
    margin-right: 5px;
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

$(document).ready(function(){
    $('#myTable').dataTable();
});
</script>

<?php
include 'createAccount.php';
?>
