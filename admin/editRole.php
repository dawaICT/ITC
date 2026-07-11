<?php
$page_title = "Edit System Role";
require_once "includes/admin.php";
require_once "includes/header.php";
if(isset($_GET['update'])) {
    $staff_id = $_GET['update'];

    // Get current role information
    $query = "SELECT sp.staff_id, s.Fname, s.Lname, s.title, p.PosID, p.PosName
              FROM staff_positions sp
              JOIN staff s ON sp.staff_id = s.staff_id
              JOIN positions p ON sp.PosID = p.PosID
              WHERE sp.staff_id = ?";

    $stmt = $db->prepare($query);
    $stmt->bind_param("s", $staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $current_roles = array();
    while($row = $result->fetch_object()) {
        $current_roles[] = $row;
    }

    // Get all available positions
    $pos_query = "SELECT PosID, PosName FROM positions ORDER BY PosName";
    $positions = $db->query($pos_query);
}

// Handle form submission
if(!empty($_POST)) {
    $staff_id = $_POST['staff_id'];
    $new_pos_ids = isset($_POST['PosID']) ? $_POST['PosID'] : array();

    $db->begin_transaction();

    try {
        // First, remove all existing roles for this staff
        $delete = $db->prepare("DELETE FROM staff_positions WHERE staff_id = ?");
        $delete->bind_param("s", $staff_id);
        $delete->execute();

        // Then add the new roles
        if (!empty($new_pos_ids)) {
            $insert = $db->prepare("INSERT INTO staff_positions (staff_id, PosID) VALUES (?, ?)");
            foreach($new_pos_ids as $pos_id) {
                $insert->bind_param("ss", $staff_id, $pos_id);
                if (!$insert->execute()) {
                    throw new Exception("Error updating roles");
                }
            }
        }

        $db->commit();
        echo "<script>alert('Roles updated successfully!');</script>";
        echo "<script>window.location.href='defineAccess.php';</script>";
    } catch (Exception $e) {
        $db->rollback();
        echo "<script>alert('Error updating roles!');</script>";
    }
}
?>

<div class="container-fluid">
    <div class="dashboard-header">
        <h1 class="welcome-message">Edit System Roles</h1>
        <div class="header-actions">
            <a href="defineAccess.php" class="btn btn-default">
                <i class="glyphicon glyphicon-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Update Roles for <?php echo $current_roles[0]->title . ' ' . $current_roles[0]->Fname . ' ' . $current_roles[0]->Lname; ?></h2>
        </div>
        <div class="card-body">
            <form action="editRole.php" method="post">
                <input type="hidden" name="staff_id" value="<?php echo $current_roles[0]->staff_id; ?>">

                <div class="form-group">
                    <label for="PosID">System Roles:</label>
                    <select class="form-control" name="PosID[]" id="PosID" multiple required>
                        <?php 
                        $current_pos_ids = array_map(function($role) { return $role->PosID; }, $current_roles);
                        while($pos = $positions->fetch_object()): 
                        ?>
                            <option value="<?php echo $pos->PosID; ?>" 
                                <?php echo in_array($pos->PosID, $current_pos_ids) ? 'selected' : ''; ?>>
                                <?php echo $pos->PosName; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <div class="form-text">Hold Ctrl/Cmd to select multiple roles</div>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Update Roles</button>
                    <a href="defineAccess.php" class="btn btn-default">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once "includes/footer.php"; ?>
