<?php
require_once "includes/admin.php";

if (isset($_GET['staff_id'])) {
    $id = $_GET['staff_id']; 
    // Use prepared statement for security
    $stmt = $db->prepare("SELECT * FROM staff WHERE staff_id = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($staff = $res->fetch_object()) {
        echo "<div class='text-center mb-4'>";
            if(!empty($staff->profile_image) && file_exists("../" . $staff->profile_image)) {
                echo "<img src='../" . htmlspecialchars($staff->profile_image) . "' class='rounded-circle mb-3' width='120' height='120' style='object-fit: cover; border: 3px solid #6f42c1;'>";
            } else {
                echo "<div class='rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3 text-primary' style='width: 120px; height: 120px; font-size: 3rem;'>
                    " . strtoupper(substr($staff->Fname, 0, 1) . substr($staff->Lname, 0, 1)) . "
                </div>";
            }
            echo "<h4>" . htmlspecialchars($staff->title . " " . $staff->Fname . " " . $staff->Lname) . "</h4>";
            echo "<span class='badge " . ($staff->status === 'Active' ? 'bg-success' : 'bg-danger') . "'>" . htmlspecialchars($staff->status) . "</span>";
        echo "</div>";
        
        echo "<div class='list-group list-group-flush'>";
            echo "<div class='list-group-item d-flex justify-content-between align-items-center px-0'>";
                echo "<span class='text-muted'>Staff ID</span>";
                echo "<span class='fw-bold'>" . htmlspecialchars($staff->staff_id) . "</span>";
            echo "</div>";
            
            echo "<div class='list-group-item d-flex justify-content-between align-items-center px-0'>";
                echo "<span class='text-muted'>Email</span>";
                echo "<span>" . htmlspecialchars($staff->email) . "</span>";
            echo "</div>";
            
            echo "<div class='list-group-item d-flex justify-content-between align-items-center px-0'>";
                echo "<span class='text-muted'>Mobile</span>";
                echo "<span>" . htmlspecialchars($staff->mobile) . "</span>";
            echo "</div>";
            
            echo "<div class='list-group-item d-flex justify-content-between align-items-center px-0'>";
                echo "<span class='text-muted'>Department ID</span>";
                echo "<span>" . htmlspecialchars($staff->deptId) . "</span>";
            echo "</div>";
            
            echo "<div class='list-group-item d-flex justify-content-between align-items-center px-0'>";
                echo "<span class='text-muted'>Qualification</span>";
                echo "<span>" . htmlspecialchars($staff->qualification) . "</span>";
            echo "</div>";
            
             echo "<div class='list-group-item d-flex justify-content-between align-items-center px-0'>";
                echo "<span class='text-muted'>Country</span>";
                echo "<span>" . htmlspecialchars($staff->country) . "</span>";
            echo "</div>";
        echo "</div>";
        
        // Fetch Roles
        $roles_stmt = $db->prepare("SELECT p.PosName FROM staff_positions sp JOIN positions p ON sp.PosID = p.PosID WHERE sp.staff_id = ?");
        $roles_stmt->bind_param("s", $id);
        $roles_stmt->execute();
        $roles_res = $roles_stmt->get_result();
        
        echo "<div class='mt-4'>";
            echo "<h6 class='text-muted mb-2'>Assigned Roles</h6>";
            echo "<div>";
            if ($roles_res->num_rows > 0) {
                while($role = $roles_res->fetch_object()) {
                    echo "<span class='badge bg-primary me-2 mb-1'>" . htmlspecialchars($role->PosName) . "</span>";
                }
            } else {
                echo "<span class='text-muted small'>No roles assigned</span>";
            }
            echo "</div>";
        echo "</div>";

    } else {
        echo "<div class='alert alert-warning'>Staff member not found.</div>";
    }
}
?>