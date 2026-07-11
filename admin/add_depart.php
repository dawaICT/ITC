<?php
require "../db/connect.php";
require_once "../includes/schema_helpers.php";

ini_set('display_errors', 0);
error_reporting(E_ALL);

$alert = null;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["deptId"], $_POST["deptName"])) {
    
    $deptId = trim($_POST["deptId"]);
    $deptName = trim($_POST["deptName"]);

    if (!empty($deptId) && !empty($deptName)) {
        $deptIdCol = wuc_detect_column($db, 'departments', ['department_id', 'deptId', 'DeptID', 'id']);
        $deptNameCol = wuc_detect_column($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']);
        if (!$deptIdCol || !$deptNameCol) {
            $alert = [
                'type' => 'error',
                'title' => 'Schema Error',
                'text' => 'Department columns are not available in the current database schema.'
            ];
        } else {
        
        $check_stmt = $db->prepare("SELECT `{$deptIdCol}` FROM departments WHERE `{$deptIdCol}` = ? OR `{$deptNameCol}` = ? LIMIT 1");
        $check_stmt->bind_param("ss", $deptId, $deptName);
        $check_stmt->execute();
        $result = $check_stmt->get_result();

        if ($result->num_rows > 0) {
            // Department already exists
            $alert = [
                'type' => 'error', // SweetAlert2 icon
                'title' => 'Failed!',
                'text' => 'This department ID or Name already exists',
                'redirect' => 'departments.php'
            ];
        } else {
            $insert = $db->prepare("INSERT INTO departments (`{$deptIdCol}`, `{$deptNameCol}`) VALUES (?, ?)");
            $insert->bind_param("ss", $deptId, $deptName);

            if ($insert->execute()) {
                $alert = [
                    'type' => 'success',
                    'title' => 'Success!',
                    'text' => 'New department added successfully',
                    'redirect' => 'departments.php'
                ];
            } else {
                $alert = [
                    'type' => 'error',
                    'title' => 'Error',
                    'text' => 'Database error: Unable to add department'
                ];
            }
            $insert->close();
        }
        $check_stmt->close();
        }
    } else {
        $alert = [
            'type' => 'warning',
            'title' => 'Missing Fields',
            'text' => 'Please fill in all fields'
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Department</title>
    <link rel="stylesheet" type="text/css" href="w3/w3.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Force display for debugging, usually triggered by JS */
        #dept { display: block; } 
    </style>
</head> 
<body>
  <div id="dept" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-4">
      <header class="w3-container w3-blue"> 
        <!-- Redirect to departments.php on close to avoid staring at a blank page -->
        <span onclick="window.location.href='departments.php'" 
        class="w3-button w3-display-topright">&times;</span>
        <h3 class="w3-center">Add New Department</h3>
      </header>
      
      <div class="w3-container w3-padding">
        <form action="" method="post"> 
            <div class="w3-section">
              <label><b>Department ID</b></label>
              <input class="w3-input w3-border" type="text" name="deptId" placeholder="e.g. IT-01" required value="<?php echo isset($_POST['deptId']) ? htmlspecialchars($_POST['deptId']) : ''; ?>">
            </div>

            <div class="w3-section">
              <label><b>Department Name</b></label>
              <input class="w3-input w3-border" type="text" name="deptName" placeholder="e.g. Information Technology" required value="<?php echo isset($_POST['deptName']) ? htmlspecialchars($_POST['deptName']) : ''; ?>">
            </div>

            <button class="w3-button w3-block w3-green w3-section w3-padding" type="submit">Add Department</button>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    <?php if ($alert): ?>
        Swal.fire({
            icon: '<?php echo $alert['type']; ?>',
            title: '<?php echo $alert['title']; ?>',
            text: '<?php echo $alert['text']; ?>',
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'OK'
        }).then((result) => {
            <?php if (isset($alert['redirect'])): ?>
            window.location.href = '<?php echo $alert['redirect']; ?>';
            <?php endif; ?>
        });
    <?php endif; ?>
  </script>
</body>
</html>
